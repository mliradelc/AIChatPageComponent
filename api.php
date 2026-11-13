<?php

/**
 * This file is part of the AIChatPageComponent plugin for ILIAS.
 *
 * Copyright (c) University of Cologne, CompetenceCenter E-Learning
 *
 * The plugin is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 */

/**
 * Endpoint of the chat frontend
 *
 * The browser only sends the chat ID, the message and attachment IDs; all chat
 * settings are read on the server. Access is checked for every request.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
$ilias_root = rtrim(dirname(__DIR__, 7), '/');
chdir($ilias_root);
require_once($ilias_root . '/Services/Init/classes/class.ilInitialisation.php');
ilContext::init(ilContext::CONTEXT_WEB);
ilInitialisation::initILIAS();

global $DIC;
$logger = $DIC->logger()->root();

require_once(__DIR__ . '/classes/platform/class.AIChatPageComponentConfig.php');
require_once(__DIR__ . '/classes/platform/class.AIChatPageComponentException.php');
require_once(__DIR__ . '/classes/ai/class.AIChatPageComponentLLM.php');
require_once(__DIR__ . '/classes/ai/class.AIChatPageComponentLLMRegistry.php');
require_once(__DIR__ . '/src/Model/ChatConfig.php');
require_once(__DIR__ . '/src/Model/ChatSession.php');
require_once(__DIR__ . '/src/Model/ChatMessage.php');
require_once(__DIR__ . '/src/Model/Attachment.php');

use ILIAS\Plugin\pcaic\Model\ChatConfig;
use ILIAS\Plugin\pcaic\Model\ChatSession;
use ILIAS\Plugin\pcaic\Model\ChatMessage;
use ILIAS\Plugin\pcaic\Model\Attachment;

$request = $DIC->http()->request();
$method = strtoupper($request->getMethod());

// Restrict CORS to the ILIAS installation's own origin
$allowed_origin = defined('ILIAS_HTTP_PATH') ? rtrim(ILIAS_HTTP_PATH, '/') : '';
if ($allowed_origin !== '' && $request->getHeaderLine('Origin') === $allowed_origin) {
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$data = [];

if ($method === 'GET') {
    $query_params = $request->getQueryParams();
    $data = is_array($query_params) ? $query_params : $query_params->toArray();
} elseif ($method === 'POST') {
    $content_type = $request->getHeaderLine('Content-Type');
    if (strpos($content_type, 'multipart/form-data') !== false) {
        $post_data = $request->getParsedBody();
        $data = is_array($post_data) ? $post_data : $post_data->toArray();
    } else {
        $input = (string) $request->getBody();
        $json_data = json_decode($input, true);
        if ($json_data !== null) {
            $data = $json_data;
        } else {
            $post_data = $request->getParsedBody();
            $data = is_array($post_data) ? $post_data : $post_data->toArray();
        }
    }
}

$action = $data['action'] ?? '';
$chat_id = $data['chat_id'] ?? '';
$user_id = (int) ($DIC->user()->getId() ?? 0);
$is_anonymous = $DIC->user()->isAnonymous();
$allow_anonymous = (\platform\AIChatPageComponentConfig::get('allow_anonymous_access') === '1');

// Block all access for anonymous users when globally disabled
if ($is_anonymous && !$allow_anonymous) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['error' => 'anonymous_access_blocked']);
    exit;
}

try {

    switch ($action) {

        case 'send_message':
            header('Content-Type: application/json');

            $message = $data['message'] ?? '';
            $attachment_ids = $data['attachment_ids'] ?? [];

            // attachment_ids may be sent as JSON string or array
            if (is_string($attachment_ids)) {
                $attachment_ids = json_decode($attachment_ids, true) ?: [];
            }
            if (!is_array($attachment_ids)) {
                $attachment_ids = [];
            }

            if (empty($chat_id) || empty($message)) {
                echo json_encode(['error' => 'Missing required parameters']);
                exit;
            }

            $chat_config = new ChatConfig($chat_id);
            if (!$chat_config->exists()) {
                echo json_encode(['error' => 'Chat not found']);
                exit;
            }

            if (!checkChatAccess($chat_config)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit;
            }

            // Daily message limit (logged-in users only)
            if (!$is_anonymous) {
                $limit_error = checkDailyMessageLimit($chat_id, $user_id);
                if ($limit_error !== null) {
                    http_response_code(429);
                    echo json_encode(['error' => $limit_error]);
                    exit;
                }

                $attachment_check = validateAttachmentIds($attachment_ids, $chat_id, $user_id);
                if ($attachment_check['error'] !== null) {
                    http_response_code(400);
                    echo json_encode(['error' => $attachment_check['error']]);
                    exit;
                }
                $attachment_ids = $attachment_check['ids'];
            }

            // The default service is used if the administrator enforces it
            $llm = createLLMInstance(getEffectiveAiService($chat_config));
            $llm->setStreaming(false);

            // Suppress inline citations if sources are hidden
            if (!$chat_config->isShowSources()) {
                $llm->setPrompt(
                    ($llm->getPrompt() ?? '') .
                    "\n\n[SYSTEM INSTRUCTION: Answer directly and completely. Sources are managed separately.]"
                );
            } elseif (!isRagEnabledForChat($chat_config, $llm)) {
                $llm->setPrompt(
                    ($llm->getPrompt() ?? '') .
                    "\n\n[SYSTEM INSTRUCTION: Cite sources inline as [1], [2], [3]. A sources panel is shown separately.]"
                );
            }
            // In RAG mode the RAG prompt defines the citation format ([cit-N], 0-based),
            // which is converted to [N+1]; an additional [1] instruction would shift the numbering

            if ($is_anonymous) {
                // Anonymous users: nothing is stored, the history comes from the frontend
                $conversation_history = $data['conversation_history'] ?? [];
                if (is_string($conversation_history)) {
                    $conversation_history = json_decode($conversation_history, true) ?: [];
                }
                $conversation_history = sanitizeConversationHistory($conversation_history);
                $response = $llm->handleStatelessMessage($chat_id, $conversation_history, $message);
            } else {
                $response = $llm->handleSendMessage($chat_id, $user_id, $message, $attachment_ids);
            }

            if (!$chat_config->isShowSources()) {
                $response = stripSourcesFromResponse($response);
            }

            $json_response = [
                'success' => true,
                'message' => $response
            ];

            if ($chat_config->isShowSources()) {
                $metadata = $llm->getLastResponseMetadata();
                if ($metadata !== null && !empty($metadata)) {
                    $json_response['sources'] = buildClientSources($chat_config, $chat_id, $metadata);
                }
            }

            $usage = $llm->getLastResponseUsage();
            if ($usage !== null) {
                $json_response['usage'] = $usage;
            }

            if ($llm->isRagIncomplete()) {
                $json_response['rag_incomplete'] = true;
            }

            echo json_encode($json_response);
            break;

        case 'send_message_stream':
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            // Events must reach the browser immediately: no PHP output buffer, no proxy buffering
            header('X-Accel-Buffering: no');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $message = $data['message'] ?? '';
            $attachment_ids = $data['attachment_ids'] ?? [];

            // attachment_ids may be sent as JSON string or array
            if (is_string($attachment_ids)) {
                $attachment_ids = json_decode($attachment_ids, true) ?: [];
            }
            if (!is_array($attachment_ids)) {
                $attachment_ids = [];
            }

            if (empty($chat_id) || empty($message)) {
                echo "data: " . json_encode(['error' => 'Missing required parameters']) . "\n\n";
                exit;
            }

            $chat_config = new ChatConfig($chat_id);
            if (!$chat_config->exists()) {
                echo "data: " . json_encode(['error' => 'Chat not found']) . "\n\n";
                exit;
            }

            if (!checkChatAccess($chat_config)) {
                http_response_code(403);
                echo "data: " . json_encode(['error' => 'Access denied']) . "\n\n";
                exit;
            }

            // Daily message limit (logged-in users only); EventSource always receives HTTP 200,
            // so errors are sent as events
            if (!$is_anonymous) {
                $limit_error = checkDailyMessageLimit($chat_id, $user_id);
                if ($limit_error !== null) {
                    echo "data: " . json_encode(['type' => 'error', 'error' => $limit_error]) . "\n\n";
                    flush();
                    exit;
                }

                $attachment_check = validateAttachmentIds($attachment_ids, $chat_id, $user_id);
                if ($attachment_check['error'] !== null) {
                    echo "data: " . json_encode(['type' => 'error', 'error' => $attachment_check['error']]) . "\n\n";
                    flush();
                    exit;
                }
                $attachment_ids = $attachment_check['ids'];
            }

            $ai_service = getEffectiveAiService($chat_config);

            // Streaming must be enabled globally, for the service and for the chat
            $streaming_enabled = isStreamingEnabledForChat($chat_config, $ai_service);

            // Send the start event before the service is created, so that an exception
            // (e.g. no_service_available) is reported as event: EventSource only fires
            // onmessage for HTTP 200, the body of an error response is not accessible
            echo "data: " . json_encode(['type' => 'start']) . "\n\n";
            flush();

            $llm = createLLMInstance($ai_service);
            $llm->setStreaming($streaming_enabled);

            // RAG sources are sent before the answer, so that citations can be shown while streaming
            if ($chat_config->isShowSources()) {
                $llm->setSourcesListener(function (array $metadata) use ($chat_config, $chat_id): void {
                    if ($metadata === []) {
                        return;
                    }
                    echo "data: " . json_encode([
                        'type' => 'sources',
                        'sources' => buildClientSources($chat_config, $chat_id, $metadata),
                    ]) . "\n\n";
                    flush();
                });
            }

            // Suppress inline citations if sources are hidden
            if (!$chat_config->isShowSources()) {
                $llm->setPrompt(
                    ($llm->getPrompt() ?? '') .
                    "\n\n[SYSTEM INSTRUCTION: Answer directly and completely. Sources are managed separately.]"
                );
            } elseif (!isRagEnabledForChat($chat_config, $llm)) {
                $llm->setPrompt(
                    ($llm->getPrompt() ?? '') .
                    "\n\n[SYSTEM INSTRUCTION: Cite sources inline as [1], [2], [3]. A sources panel is shown separately.]"
                );
            }
            // In RAG mode the RAG prompt defines the citation format ([cit-N], 0-based),
            // which is converted to [N+1]; an additional [1] instruction would shift the numbering

            if ($is_anonymous) {
                // Anonymous users: nothing is stored, the history comes from the frontend
                $conversation_history = $data['conversation_history'] ?? [];
                if (is_string($conversation_history)) {
                    $conversation_history = json_decode($conversation_history, true) ?: [];
                }
                $conversation_history = sanitizeConversationHistory($conversation_history);
                $response = $llm->handleStatelessMessage($chat_id, $conversation_history, $message);
            } else {
                $response = $llm->handleSendMessage($chat_id, $user_id, $message, $attachment_ids);
            }

            if (!$chat_config->isShowSources()) {
                $response = stripSourcesFromResponse($response);
            }

            $complete_data = ['type' => 'complete', 'message' => $response];

            if ($chat_config->isShowSources()) {
                $metadata = $llm->getLastResponseMetadata();
                if ($metadata !== null && !empty($metadata)) {
                    $complete_data['sources'] = buildClientSources($chat_config, $chat_id, $metadata);
                }
            }

            $usage = $llm->getLastResponseUsage();
            if ($usage !== null) {
                $complete_data['usage'] = $usage;
            }

            if ($llm->isRagIncomplete()) {
                $complete_data['rag_incomplete'] = true;
            }

            echo "data: " . json_encode($complete_data) . "\n\n";
            flush();
            break;

        case 'upload_file':
            header('Content-Type: application/json');

            // Anonymous users can chat, but never upload files
            if ($is_anonymous) {
                http_response_code(403);
                echo json_encode(['error' => 'File uploads are not available for anonymous users']);
                exit;
            }

            $uploaded_files = $request->getUploadedFiles();
            $uploaded_file = $uploaded_files['file'] ?? null;
            if (!$uploaded_file instanceof \Psr\Http\Message\UploadedFileInterface) {
                echo json_encode(['error' => 'No file uploaded']);
                exit;
            }

            $chat_config = new ChatConfig($chat_id);
            if (!$chat_config->exists()) {
                echo json_encode(['error' => 'Chat not found']);
                exit;
            }

            if (!checkChatAccess($chat_config)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit;
            }

            $ai_service = getEffectiveAiService($chat_config);
            if (!isFileHandlingEnabledForService($ai_service)) {
                echo json_encode(['error' => 'File handling is disabled for this AI service']);
                exit;
            }

            $max_file_size_mb = \ILIAS\Plugin\pcaic\Validation\FileUploadValidator::getMaxFileSizeMb();

            // Rejected by PHP (upload_max_filesize) before the plugin limit could be checked
            if (in_array($uploaded_file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $logger->warning("Chat upload rejected by the PHP upload limit; the plugin allows {$max_file_size_mb} MB");
                echo json_encode(['error' => 'File too large. The server does not accept files of this size.']);
                exit;
            }

            if ($uploaded_file->getError() !== UPLOAD_ERR_OK) {
                echo json_encode(['error' => 'File upload failed']);
                exit;
            }

            $file_restrictions = \platform\AIChatPageComponentConfig::get('file_upload_restrictions');
            $chat_uploads_allowed = !is_array($file_restrictions) || ($file_restrictions['allow_chat_uploads'] ?? true);
            if (!$chat_uploads_allowed || !$chat_config->isEnableChatUploads()) {
                http_response_code(403);
                echo json_encode(['error' => 'File uploads are disabled for this chat']);
                exit;
            }

            // Enforced here, the browser check can be bypassed
            if ((int) $uploaded_file->getSize() > \ILIAS\Plugin\pcaic\Validation\FileUploadValidator::getMaxFileSizeBytes()) {
                echo json_encode(['error' => "File too large. Maximum size is {$max_file_size_mb} MB."]);
                exit;
            }

            $llm = createLLMInstance(getEffectiveAiService($chat_config));
            $rag_enabled = isRagEnabledForChat($chat_config, $llm);

            $file_extension = strtolower(pathinfo((string) $uploaded_file->getClientFilename(), PATHINFO_EXTENSION));
            if (!$llm->isFileTypeAllowed($file_extension, $rag_enabled)) {
                $mode = $rag_enabled ? 'RAG' : 'Multimodal';
                $allowed = $llm->getAllowedFileTypesDescription($rag_enabled);
                echo json_encode([
                    'error' => "File type .{$file_extension} not allowed in {$mode} mode. Allowed: {$allowed}"
                ]);
                exit;
            }

            try {
                $resource_storage = $DIC->resourceStorage();
                $stakeholder = new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder();

                $upload_service = $DIC->upload();
                $upload_service->process();

                if (!$upload_service->hasUploads()) {
                    throw new \Exception('No valid uploads found');
                }

                $upload_results = $upload_service->getResults();
                $upload_result = $upload_results[array_keys($upload_results)[0]];

                if (!$upload_result->isOK()) {
                    throw new \Exception('Upload validation failed');
                }

                $resource_id = $resource_storage->manage()->upload($upload_result, $stakeholder);

                $attachment = new Attachment();
                $attachment->setChatId($chat_id);
                $attachment->setUserId($user_id);
                $attachment->setResourceId($resource_id->serialize());
                $attachment->setMessageId(null); // Bound to the message when it is sent
                $attachment->setTimestamp(date('Y-m-d H:i:s'));
                $attachment->save();

                $enable_rag = $rag_enabled;

                $revision = $resource_storage->manage()->getCurrentRevision($resource_id);
                $suffix = strtolower($revision->getInformation()->getSuffix());

                if ($enable_rag) {
                    $rag_types = $llm->getRagFileTypes();
                    $is_rag_type = in_array($suffix, $rag_types, true);

                    if ($is_rag_type) {
                        // RAG file type: stored in the RAG, not sent as image
                        $stream = $resource_storage->consume()->stream($resource_id);
                        $content = $stream->getStream()->getContents();

                        $original_filename = $revision->getTitle();
                        $safe_filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $original_filename);
                        $temp_file = sys_get_temp_dir() . '/' . uniqid() . '_' . $safe_filename;
                        file_put_contents($temp_file, $content);

                        $logger->debug("Prepared temp file for RAG upload: $temp_file | original=$original_filename | size=" . strlen($content) . " | suffix=$suffix");

                        $session = ChatSession::getOrCreateForUserAndChat($user_id, $chat_id);
                        $session_id = $session->getSessionId();

                        $rag_response = $llm->uploadFileToRAG($temp_file, (string) $session_id, $original_filename);

                        $attachment->setRAGCollectionId($rag_response['collection_id']);
                        $attachment->setRAGRemoteFileId($rag_response['remote_file_id']);
                        $attachment->setRAGUploadedAt(date('Y-m-d H:i:s'));
                        $attachment->save();
                        \ai\AIChatPageComponentRAGStatus::markUploaded((int) $attachment->getId());

                        @unlink($temp_file);
                    } else {
                        // Other types (e.g. images) are sent to the AI service directly
                        $logger->debug("File type '$suffix' is not a RAG type — storing for multimodal embedding");
                    }
                }

                echo json_encode([
                    'success' => true,
                    'attachment' => $attachment->toArray()
                ]);

            } catch (\Exception $e) {
                $logger->error("File upload failed", [
                    'error' => $e->getMessage()
                ]);
                echo json_encode(['error' => 'File upload failed. Please try again.']);
            }
            break;

        case 'load_chat':
            header('Content-Type: application/json');

            // Anonymous users have no stored history
            if ($is_anonymous) {
                echo json_encode(['success' => true, 'messages' => [], 'session' => null, 'config' => []]);
                exit;
            }

            if (empty($chat_id)) {
                echo json_encode(['error' => 'Missing chat_id']);
                exit;
            }

            $chat_config = new ChatConfig($chat_id);
            if (!$chat_config->exists()) {
                echo json_encode(['error' => 'Chat configuration not found']);
                exit;
            }

            if (!checkChatAccess($chat_config)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit;
            }

            $session = ChatSession::getOrCreateForUserAndChat($user_id, $chat_id);

            $messages = $session->getRecentMessages(50);

            $formatted_messages = [];
            foreach ($messages as $msg) {
                $attachments = $msg->getAttachments();
                $formatted_attachments = [];

                foreach ($attachments as $att) {
                    $formatted_attachments[] = $att->toArray();
                }

                $msg_text = $msg->getMessage();
                $show_sources = $chat_config->isShowSources();

                if ($msg->getRole() === 'assistant' && !$show_sources) {
                    $msg_text = stripSourcesFromResponse($msg_text);
                }

                $formatted_msg = [
                    'role' => $msg->getRole(),
                    'message' => $msg_text,
                    'timestamp' => $msg->getTimestamp(),
                    'attachments' => $formatted_attachments
                ];

                if ($msg->getRole() === 'assistant' && $show_sources && $msg->hasSources()) {
                    $bg_urls = $chat_config->isAllowSourceDownloads()
                        ? getBackgroundFileDownloadUrls($chat_id)
                        : [];
                    $formatted_msg['sources'] = array_map(function ($source) use ($bg_urls, $chat_id) {
                        $filename = $source['filename'] ?? 'Unknown';
                        $attachment_id = $bg_urls[$filename] ?? null;
                        return array_merge($source, [
                            'download_url' => $attachment_id ? buildSecureDownloadUrl($chat_id, $attachment_id) : null,
                        ]);
                    }, $msg->getFormattedSources());
                }

                $usage = $msg->getUsage();
                if ($usage !== null) {
                    $formatted_msg['usage'] = $usage;
                }

                $formatted_messages[] = $formatted_msg;
            }

            echo json_encode([
                'success' => true,
                'config' => $chat_config->toArray(),
                'session' => $session->toArray(),
                'messages' => $formatted_messages
            ]);

            // Cleanup of inactive sessions with a probability of about 5 %, only for users
            // with write permission, so that it never runs for learners
            if (mt_rand(1, 20) === 1 && checkChatAccess($chat_config, 'write')) {
                $cleanup_days = (int) (\platform\AIChatPageComponentConfig::get('session_cleanup_days') ?? 90);
                if ($cleanup_days > 0) {
                    try {
                        $plugin = ilAIChatPageComponentPlugin::getInstance();
                        $plugin->cleanupInactiveSessions($cleanup_days);
                    } catch (\Exception $e) {
                        $logger->warning("Lazy session cleanup failed", ['error' => $e->getMessage()]);
                    }
                }
            }
            break;

        case 'clear_chat':
            header('Content-Type: application/json');

            // Anonymous users have no stored history
            if ($is_anonymous) {
                echo json_encode(['success' => true]);
                exit;
            }

            if (empty($chat_id)) {
                echo json_encode(['error' => 'Missing chat_id']);
                exit;
            }

            $chat_config = new ChatConfig($chat_id);
            if (!$chat_config->exists()) {
                echo json_encode(['error' => 'Chat not found']);
                exit;
            }

            if (!checkChatAccess($chat_config)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit;
            }

            $db = $DIC->database();

            $query = "SELECT session_id FROM pcaic_sessions " .
                     "WHERE user_id = " . $db->quote($user_id, 'integer') . " " .
                     "AND chat_id = " . $db->quote($chat_id, 'text');

            $result = $db->query($query);
            if ($row = $db->fetchAssoc($result)) {
                $session_id = $row['session_id'];

                // Attachments are deleted individually, so that files are also removed from the RAG
                $attachments_query = "SELECT a.id FROM pcaic_attachments a " .
                                    "INNER JOIN pcaic_messages m ON a.message_id = m.message_id " .
                                    "WHERE m.session_id = " . $db->quote($session_id, 'text');
                $attachments_result = $db->query($attachments_query);

                while ($attachment_row = $db->fetchAssoc($attachments_result)) {
                    try {
                        $attachment = new Attachment((int) $attachment_row['id']);
                        $attachment->delete();
                    } catch (\Exception $e) {
                        $logger->warning("Failed to delete attachment during clear_chat", [
                            'attachment_id' => $attachment_row['id'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                $db->manipulate("DELETE FROM pcaic_messages WHERE session_id = " . $db->quote($session_id, 'text'));

                $db->manipulate("DELETE FROM pcaic_sessions WHERE session_id = " . $db->quote($session_id, 'text'));
            }

            echo json_encode(['success' => true]);
            break;

        case 'get_upload_config':
            header('Content-Type: application/json');

            $max_file_size = \ILIAS\Plugin\pcaic\Validation\FileUploadValidator::getMaxFileSizeMb();
            $allowed_types = \platform\AIChatPageComponentConfig::get('allowed_file_types') ?: 'txt,md,csv,pdf,jpg,jpeg,png,gif,webp';

            echo json_encode([
                'success' => true,
                'max_file_size_mb' => (int) $max_file_size,
                'allowed_types' => explode(',', $allowed_types)
            ]);
            break;

        case 'get_global_config':
            header('Content-Type: application/json');

            // Anonymous users can never upload
            if ($DIC->user()->isAnonymous()) {
                $chat_config = new ChatConfig($data['chat_id'] ?? '');
                $ai_service = getEffectiveAiService($chat_config);
                $llm = createLLMInstance($ai_service);
                $rag_enabled = isRagEnabledForChat($chat_config, $llm);
                $allowed_extensions = $llm->getAllowedFileTypes($rag_enabled);
                $streaming_enabled = isStreamingEnabledForChat($chat_config, $ai_service);

                echo json_encode([
                    'success' => true,
                    'upload_enabled' => false,
                    'allowed_extensions' => $allowed_extensions,
                    'allowed_mime_types' => [],
                    'allowed_accept_values' => [],
                    'rag_mode' => $rag_enabled,
                    'streaming_enabled' => $streaming_enabled,
                    'file_handling_enabled' => false,
                    'max_file_size_mb' => 0,
                    'max_attachments_per_message' => 0,
                    'max_char_limit' => (int) (\platform\AIChatPageComponentConfig::get('characters_limit') ?: 2000),
                    'max_memory_limit' => (int) (\platform\AIChatPageComponentConfig::get('max_memory_messages') ?: 10)
                ]);
                exit;
            }

            $upload_enabled = true;
            $enable_uploads_setting = \platform\AIChatPageComponentConfig::get('enable_file_uploads');
            if ($enable_uploads_setting !== null) {
                $upload_enabled = ($enable_uploads_setting === '1' || $enable_uploads_setting === 'true');
            }

            $chat_id = $data['chat_id'] ?? null;

            if (!$chat_id) {
                http_response_code(400);
                echo json_encode(['error' => 'chat_id is required for get_global_config']);
                exit;
            }

            $chat_config = new ChatConfig($chat_id);
            if (!$chat_config->exists()) {
                http_response_code(404);
                echo json_encode(['error' => 'Chat not found']);
                exit;
            }

            if (!checkChatAccess($chat_config)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit;
            }

            $ai_service = getEffectiveAiService($chat_config);

            $llm = createLLMInstance($ai_service);
            $rag_enabled = isRagEnabledForChat($chat_config, $llm);

            $allowed_extensions = $llm->getAllowedFileTypes($rag_enabled);

            // MIME types for the accept attribute of the file input
            $allowed_mime_types = \ILIAS\Plugin\pcaic\Validation\FileUploadValidator::extensionsToMimeTypes($allowed_extensions);

            // MIME types and extensions, because browsers do not recognise every MIME type
            $allowed_accept_values = \ILIAS\Plugin\pcaic\Validation\FileUploadValidator::extensionsToAcceptValues($allowed_extensions);

            $streaming_enabled = isStreamingEnabledForChat($chat_config, $ai_service);

            $file_handling_enabled = isFileHandlingEnabledForService($ai_service);

            echo json_encode([
                'success' => true,
                'upload_enabled' => $upload_enabled && $file_handling_enabled,
                'allowed_extensions' => $allowed_extensions,
                'allowed_mime_types' => $allowed_mime_types,
                'allowed_accept_values' => $allowed_accept_values,
                'rag_mode' => $rag_enabled,
                'streaming_enabled' => $streaming_enabled,
                'file_handling_enabled' => $file_handling_enabled,
                'max_file_size_mb' => \ILIAS\Plugin\pcaic\Validation\FileUploadValidator::getMaxFileSizeMb(),
                'max_attachments_per_message' => (int) (\platform\AIChatPageComponentConfig::get('max_attachments_per_message') ?: 5),
                'max_char_limit' => (int) (\platform\AIChatPageComponentConfig::get('characters_limit') ?: 2000),
                'max_memory_limit' => (int) (\platform\AIChatPageComponentConfig::get('max_memory_messages') ?: 10)
            ]);
            break;

        case 'download_background_file':
            if ($is_anonymous) {
                http_response_code(403);
                exit;
            }

            $attachment_id = (int) ($data['attachment_id'] ?? 0);
            if (!$attachment_id || !$chat_id) {
                http_response_code(400);
                exit;
            }

            $dl_chat_config = new ChatConfig($chat_id);
            if (!checkChatAccess($dl_chat_config, 'read')) {
                http_response_code(403);
                exit;
            }

            if (!$dl_chat_config->isAllowSourceDownloads()) {
                http_response_code(403);
                exit;
            }

            // Only background files of this chat can be downloaded
            try {
                $attachment = new \ILIAS\Plugin\pcaic\Model\Attachment($attachment_id);
                if (!$attachment->isBackgroundFile() || $attachment->getChatId() !== $chat_id) {
                    http_response_code(403);
                    exit;
                }

                $resource_id = $attachment->getResourceIdentification();
                if (!$resource_id) {
                    http_response_code(404);
                    exit;
                }

                // Delivered as download via the Resource Storage
                $download_consumer = $DIC->resourceStorage()->consume()->download($resource_id);
                $download_consumer->run();
            } catch (\Exception $e) {
                $logger->warning("Background file download failed", [
                    'attachment_id' => $attachment_id,
                    'error' => $e->getMessage()
                ]);
                http_response_code(500);
            }
            exit;

        default:
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode([
                'error' => 'Unknown action: ' . $action
            ]);
            break;
    }

} catch (\Throwable $e) {
    // Also PHP errors, so that a stream always ends with an error event
    $is_no_service = ($e->getMessage() === 'no_service_available');
    $is_rag_unavailable = ($e->getMessage() === \ai\AIChatPageComponentRAG::UNAVAILABLE);
    $is_service_busy = ($e->getMessage() === \ai\AIChatPageComponentLLM::SERVICE_BUSY);

    if ($is_rag_unavailable || $is_service_busy) {
        $logger->warning("API Error: " . $e->getMessage() . " (HTTP " . $e->getCode() . ")", ['action' => $action, 'chat_id' => $chat_id]);
    } else {
        $logger->error("API Error: " . $e->getMessage() . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")", [
            'action' => $action,
            'chat_id' => $chat_id,
            'error' => $e->getMessage(),
            'trace' => $is_no_service ? '' : $e->getTraceAsString()
        ]);
    }

    if ($is_no_service) {
        $client_message = 'no_service_available';
    } elseif ($is_rag_unavailable) {
        $client_message = \ilAIChatPageComponentPlugin::getInstance()->txt('rag_unavailable');
    } elseif ($is_service_busy) {
        $client_message = \ilAIChatPageComponentPlugin::getInstance()->txt('ai_service_busy');
    } else {
        $client_message = 'An internal error occurred. Please try again.';
    }

    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(($is_no_service || $is_rag_unavailable || $is_service_busy) ? 503 : 500);
        echo json_encode(['error' => $client_message]);
    } else {
        echo "data: " . json_encode(['type' => 'error', 'error' => $client_message]) . "\n\n";
        flush();
    }
}

/**
 * Daily message limit of logged-in users per chat (server date)
 *
 * @return string|null Error message, or null if the limit has not been reached
 */
function checkDailyMessageLimit(string $chat_id, int $user_id): ?string
{
    $max = (int) (\platform\AIChatPageComponentConfig::get('max_messages_per_day') ?? 50);
    if ($max <= 0) {
        return null; // 0 = unlimited
    }

    global $DIC;
    $db = $DIC->database();

    $today = date('Y-m-d');

    $result = $db->query(
        "SELECT COUNT(*) AS cnt " .
        "FROM pcaic_messages m " .
        "INNER JOIN pcaic_sessions s ON m.session_id = s.session_id " .
        "WHERE s.user_id = " . $db->quote($user_id, 'integer') . " " .
        "AND s.chat_id = " . $db->quote($chat_id, 'text') . " " .
        "AND m.role = 'user' " .
        "AND DATE(m.timestamp) = " . $db->quote($today, 'text')
    );

    $row = $db->fetchAssoc($result);
    $count = (int) ($row['cnt'] ?? 0);

    if ($count >= $max) {
        $plugin = ilAIChatPageComponentPlugin::getInstance();
        return sprintf($plugin->txt('error_rate_limit_exceeded'), $max);
    }

    return null;
}

/**
 * Attachments the user may bind to a new message
 *
 * Only unsent chat uploads of the user in this chat are accepted; other IDs are
 * ignored. Number and total size of the attachments are checked against the
 * plugin configuration.
 *
 * @return array{ids: int[], error: string|null}
 */
function validateAttachmentIds(array $attachment_ids, string $chat_id, int $user_id): array
{
    global $DIC;
    $plugin = ilAIChatPageComponentPlugin::getInstance();

    $ids = [];
    $total_size = 0;
    foreach (array_unique(array_map('intval', array_filter($attachment_ids, 'is_numeric'))) as $attachment_id) {
        $attachment = Attachment::loadById($attachment_id);
        if (
            $attachment === null
            || $attachment->getChatId() !== $chat_id
            || $attachment->getUserId() !== $user_id
            || $attachment->getMessageId() !== null
            || $attachment->isBackgroundFile()
        ) {
            $DIC->logger()->pcaic()->warning("Attachment $attachment_id rejected for chat $chat_id and user $user_id");
            continue;
        }
        $ids[] = $attachment_id;
        $total_size += $attachment->getSize();
    }

    $max_attachments = (int) (\platform\AIChatPageComponentConfig::get('max_attachments_per_message') ?: 5);
    if (count($ids) > $max_attachments) {
        return ['ids' => [], 'error' => sprintf($plugin->txt('error_max_attachments'), $max_attachments)];
    }

    $max_total_mb = (int) (\platform\AIChatPageComponentConfig::get('max_total_upload_size_mb') ?: 25);
    if ($total_size > $max_total_mb * 1024 * 1024) {
        return ['ids' => [], 'error' => sprintf($plugin->txt('error_total_upload_too_large'), $max_total_mb)];
    }

    return ['ids' => $ids, 'error' => null];
}

/**
 * Read permission on the object containing the chat
 *
 * parent_id is an obj_id; access is granted if any of its references is readable.
 */
function checkChatAccess(ChatConfig $chat_config, string $permission = 'read'): bool
{
    global $DIC;

    $parent_id = $chat_config->getParentId();
    if ($parent_id <= 0) {
        return false;
    }

    $refs = ilObject::_getAllReferences($parent_id);

    if (empty($refs)) {
        // Some page types store the ref_id
        $refs = [$parent_id];
    }

    foreach ($refs as $ref_id) {
        if ($DIC->access()->checkAccess($permission, '', (int) $ref_id)) {
            return true;
        }
    }

    return false;
}

/**
 * Limit and clean the history sent by the frontend for anonymous users
 *
 * Only user and assistant messages are kept, each shortened to the character limit.
 */
function sanitizeConversationHistory(array $history): array
{
    $char_limit = (int) (\platform\AIChatPageComponentConfig::get('characters_limit') ?: 2000);
    $max_entries = 40; // At most 20 exchanges, independent of the memory setting

    $sanitized = [];
    foreach (array_slice($history, -$max_entries) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $role = $entry['role'] ?? '';
        $message = $entry['message'] ?? '';
        if (!in_array($role, ['user', 'assistant'], true)) {
            continue;
        }
        if (!is_string($message)) {
            continue;
        }
        $sanitized[] = [
            'role' => $role,
            'message' => mb_substr($message, 0, $char_limit)
        ];
    }
    return $sanitized;
}

/**
 * AI service of the chat, or the default service if the administrator enforces it
 */
function getEffectiveAiService(ChatConfig $chat_config): string
{
    $force_default = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';

    if ($force_default === '1') {
        return \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
    }

    return $chat_config->getAiService();
}

/**
 * Create an enabled service; falls back to the first enabled service
 *
 * @throws \Exception no_service_available if no service is enabled
 */
function createLLMInstance(string $service): \ai\AIChatPageComponentLLM
{
    // Disabled services must not be used
    $enabled_services = \ai\AIChatPageComponentLLMRegistry::getEnabledServices();

    if (empty($enabled_services)) {
        throw new \Exception("no_service_available");
    }

    if (isset($enabled_services[$service])) {
        $instance = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($service);
        if ($instance !== null) {
            return $instance;
        }
    }

    $first_service = array_key_first($enabled_services);
    $instance = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($first_service);

    if ($instance === null) {
        throw new \Exception("no_service_available");
    }

    return $instance;
}

/**
 * Streaming must be enabled globally, for the service and for the chat
 */
function isStreamingEnabledForChat(ChatConfig $chat_config, string $ai_service): bool
{
    $global_streaming = \platform\AIChatPageComponentConfig::get('enable_streaming') ?: '1';
    if ($global_streaming !== '1') {
        return false;
    }

    $llm_streaming_key = $ai_service . '_streaming_enabled';
    $llm_streaming = \platform\AIChatPageComponentConfig::get($llm_streaming_key);

    // Enabled unless disabled for the service
    $llm_streaming = $llm_streaming ?? '1';

    if ($llm_streaming !== '1') {
        return false;
    }

    if (!$chat_config->isEnableStreaming()) {
        return false;
    }

    return true;
}

/**
 * File handling must be enabled globally and for the service
 */
function isFileHandlingEnabledForService(string $ai_service): bool
{
    $global_file_handling = \platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1';
    if ($global_file_handling !== '1') {
        return false;
    }

    $service_file_handling_key = $ai_service . '_file_handling_enabled';
    $service_file_handling = \platform\AIChatPageComponentConfig::get($service_file_handling_key);

    $default_file_handling = '1';
    $service_file_handling = $service_file_handling ?? $default_file_handling;

    if ($service_file_handling !== '1') {
        return false;
    }

    return true;
}

/**
 * RAG is used if the RAG service is available, allowed for the service and enabled in the chat
 */
function isRagEnabledForChat(ChatConfig $chat_config, \ai\AIChatPageComponentLLM $llm): bool
{
    if (!$llm->supportsRAG()) {
        return false;
    }

    $ai_service = getEffectiveAiService($chat_config);
    $rag_config_key = $ai_service . '_enable_rag';
    $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
    $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

    if (!$rag_globally_enabled) {
        return false;
    }

    return $chat_config->isEnableRag();
}

/**
 * Remove source references and source sections from an answer (used if sources are hidden)
 *
 * Removes inline markers (superscripts, [1], ^1), trailing source sections in
 * several languages and trailing lists of file citations.
 */
function stripSourcesFromResponse(string $text): string
{
    // Trailing source section, with or without heading marker
    $section_pattern = '/\s*(?:---\s*)?#{1,4}\s*\*{0,2}(?:'
        . 'Quellen|Literatur(?:verzeichnis)?'                   // DE
        . '|Sources?|R[eé]f[eé]rences?|Bibliography'           // EN / FR
        . '|Fuentes?|Referencias?|Bibliograf[ií]a'             // ES
        . '|Fonti|Riferimenti|Bibliograf[ií]a'                 // IT
        . '|Fontes?|Refer[eê]ncias?'                           // PT
        . ')\*{0,2}\s*:?.*$/us';
    $text = preg_replace($section_pattern, '', $text);

    // Source heading without heading marker, followed by citation lines
    $text = preg_replace(
        '/\n{1,2}(?:Quellen|Literatur(?:verzeichnis)?'
        . '|Sources?|R[eé]f[eé]rences?|Bibliography'
        . '|Fuentes?|Referencias?|Bibliograf[ií]a'
        . '|Fonti|Riferimenti|Fontes?|Refer[eê]ncias?'
        . ')\s*:?\s*\n[\s\S]*$/u',
        '',
        $text
    );

    // Horizontal rule followed by file citation lines
    $text = preg_replace(
        '/\n+---\s*\n(?:\s*[^\n]*\.(?:pdf|txt|csv|xlsx?|docx?|pptx?)[^\n]*\n?)+\s*$/ui',
        '',
        $text
    );

    // Trailing file citation lines without rule, e.g. "file.pdf, pages 1, 3"
    $text = preg_replace(
        '/(?:\n\s*[^\n]+\.(?:pdf|txt|csv|xlsx?|docx?|pptx?)\s*,\s*pages?[^\n]*)+\s*$/ui',
        '',
        $text
    );

    // Numbered reference list at the end (at least two lines)
    $text = preg_replace('/(?:\n\d+\.\s+[^\n]+){2,}\s*$/u', '', $text);

    $text = preg_replace('/[\x{00B9}\x{00B2}\x{00B3}\x{2070}-\x{2079}]+/u', '', $text);

    $text = preg_replace('/\[\^[\d,\s]+\]|\^[\d,\s]+/', '', $text);

    $text = preg_replace('/\[\d+\]/', '', $text);

    $text = preg_replace('/\[\s*\]/', '', $text);

    // Markdown links: keep the text, drop the URL
    $text = preg_replace('/\[([^\]]+)\]\(https?:\/\/[^)]+\)/', '$1', $text);

    $text = preg_replace('/https?:\/\/\S+/', '', $text);

    return rtrim($text);
}

/**
 * Sources of an answer as sent to the browser
 *
 * @param array $metadata Sources from the AI service (see AIChatPageComponentLLM::getLastResponseMetadata())
 * @return array<int, array{filename: string, pages: array, url: ?string, excerpt: ?string, download_url: ?string}>
 */
function buildClientSources(ChatConfig $chat_config, string $chat_id, array $metadata): array
{
    $bg_urls = $chat_config->isAllowSourceDownloads()
        ? getBackgroundFileDownloadUrls($chat_id)
        : [];

    return array_map(static function ($source) use ($bg_urls, $chat_id): array {
        $filename = $source['filename'] ?? 'Unknown';
        $attachment_id = $bg_urls[$filename] ?? null;
        return [
            'filename' => $filename,
            'pages' => $source['page_numbers'] ?? [],
            'url' => $source['url'] ?? null,
            'excerpt' => isset($source['text']) ? mb_substr($source['text'], 0, 200) . '...' : null,
            'download_url' => $attachment_id ? buildSecureDownloadUrl($chat_id, $attachment_id) : null,
        ];
    }, array_values($metadata));
}

/**
 * Background files of the chat by file name, used for download links of sources
 *
 * @return array<string, int> file name => attachment ID
 */
function getBackgroundFileDownloadUrls(string $chat_id): array
{
    $map = [];
    try {
        $attachments = \ILIAS\Plugin\pcaic\Model\Attachment::getByChatId($chat_id);
        foreach ($attachments as $attachment) {
            if (!$attachment->isBackgroundFile()) {
                continue;
            }
            $title = $attachment->getTitle();
            $id = $attachment->getId();
            if ($title && $id) {
                $map[$title] = $id;
                // The RAG may replace spaces with underscores in file names
                $map[str_replace(' ', '_', $title)] = $id;
            }
        }
    } catch (\Exception $e) {
        // Sources are shown without download links
    }
    return $map;
}

/**
 * Download URL of a background file; the download runs through api.php, so that
 * session and read permission are checked
 */
function buildSecureDownloadUrl(string $chat_id, int $attachment_id): string
{
    global $DIC;
    $base = rtrim(preg_replace('~(/Customizing)(?=/|$).*~i', '', ILIAS_HTTP_PATH), '/');
    $plugin_path = '/Customizing/global/plugins/Services/COPage/PageComponent/AIChatPageComponent/api.php';
    return $base . $plugin_path
        . '?action=download_background_file'
        . '&chat_id=' . urlencode($chat_id)
        . '&attachment_id=' . (int) $attachment_id;
}
