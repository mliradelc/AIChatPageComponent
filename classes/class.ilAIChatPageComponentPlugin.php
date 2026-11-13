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

declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

/**
 * PageComponent plugin for AI chats on ILIAS pages
 *
 * Provides a cron job (ilCronJobProvider) that advances the processing of files in
 * the RAG service.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class ilAIChatPageComponentPlugin extends ilPageComponentPlugin implements ilCronJobProvider
{
    public const PLUGIN_ID = "pcaic";

    /** @var string Session key for cut/paste markers, see onDelete() and onClone() */
    private const SESSION_CUT_PASTE_OPERATIONS = 'pcaic_cut_paste_operations';

    public const PLUGIN_NAME = "AIChatPageComponent";

    public const CTYPE = "Services";

    public const CNAME = "COPage";

    public const SLOT_ID = "pgcp";

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (!isset(self::$instance)) {
            global $DIC;

            $component_repository = $DIC["component.repository"];

            $info = $component_repository->getPluginByName(self::PLUGIN_NAME);

            $component_factory = $DIC["component.factory"];

            $plugin_obj = $component_factory->getPlugin($info->getId());

            self::$instance = $plugin_obj;
        }

        return self::$instance;
    }

    /**
     * @return ilCronJob[]
     */
    public function getCronJobInstances(): array
    {
        return [new ilAIChatPageComponentRAGCronJob($this)];
    }

    /**
     * @throws OutOfBoundsException if the plugin has no such cron job
     */
    public function getCronJobInstance(string $jobId): ilCronJob
    {
        if ($jobId === ilAIChatPageComponentRAGCronJob::JOB_ID) {
            return new ilAIChatPageComponentRAGCronJob($this);
        }
        throw new OutOfBoundsException("Unknown cron job: $jobId");
    }

    public function getPluginName(): string
    {
        return self::PLUGIN_NAME;
    }

    public static function getPluginId(): string
    {
        return self::PLUGIN_ID;
    }

    /**
     * Plugin directory relative to the ILIAS root
     */
    public function getPluginBaseDir(): string
    {
        return rtrim($this->getDirectory(), '/');
    }

    /**
     * Absolute URL of the plugin directory
     */
    public function getPluginBaseUrl(): string
    {
        $base = rtrim(ILIAS_HTTP_PATH, '/');
        $rel = $this->getPluginBaseDir();

        return $base . '/' . $rel;
    }

    /**
     * Whether the chat element may be added to a page of this type by the current user
     *
     * PageComponent plugins cannot have own RBAC permissions, so write permission on
     * the object containing the page is required.
     */
    public function isValidParentType(string $a_parent_type): bool
    {
        global $DIC;
        $logger = $DIC->logger()->pcaic();

        $supported_types = $this->getParentTypes();
        $logger->debug('parent_type: ' . $a_parent_type);
        if (!in_array($a_parent_type, $supported_types)) {
            $logger->debug("PageComponent access denied: Unsupported parent type", ['parent_type' => $a_parent_type]);
            return false;
        }

        $parent_ref_id = $this->getCurrentParentRefId();
        if (!$parent_ref_id) {
            $logger->warning("PageComponent access denied: No parent context found");
            return false;
        }

        $access = $DIC->access();

        $has_write_access = $access->checkAccess('write', '', $parent_ref_id);

        if (!$has_write_access) {
            $logger->info("PageComponent access denied: User lacks content editing permission", [
                'ref_id' => $parent_ref_id,
                'parent_type' => $a_parent_type,
                'required_permission' => 'write'
            ]);
            return false;
        }

        $logger->debug("PageComponent access granted via content editing permissions", [
            'ref_id' => $parent_ref_id,
            'parent_type' => $a_parent_type
        ]);
        return true;
    }

    private function getCurrentParentRefId(): ?int
    {
        global $DIC;
        $logger = $DIC->logger()->pcaic();

        $query = $DIC->http()->wrapper()->query();
        if ($query->has('ref_id')) {
            $ref_id = $query->retrieve(
                'ref_id',
                $DIC->refinery()->byTrying([
                    $DIC->refinery()->kindlyTo()->int(),
                    $DIC->refinery()->always(0)
                ])
            );
            if ($ref_id > 0) {
                return $ref_id;
            }
        }

        // Fall back to the page object
        if (isset($this->page_obj)) {
            try {
                $parent_id = $this->page_obj->getParentId();
                $parent_type = $this->page_obj->getParentType();

                $ref_id = $this->getRefIdFromObjectId($parent_id, $parent_type);
                return $ref_id;
            } catch (\Exception $e) {
                $logger->debug("Failed to resolve ref_id from page object", ['error' => $e->getMessage()]);
            }
        }

        $logger->warning("Cannot determine parent context for permission check");
        return null;
    }

    private function getRefIdFromObjectId(int $obj_id, string $obj_type): ?int
    {
        try {
            $ref_ids = ilObject::_getAllReferences($obj_id);

            if (!empty($ref_ids)) {
                return (int) array_shift($ref_ids);
            }
        } catch (\Exception $e) {
            global $DIC;
            $logger = $DIC->logger()->pcaic();
            $logger->warning("Failed to resolve references for object", [
                'obj_id' => $obj_id,
                'obj_type' => $obj_type,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Called when a page element is copied or cut and pasted
     *
     * A real copy gets a new chat with its own configuration and background files;
     * cut and paste keeps the chat.
     *
     * @param array $a_properties Properties of the page element, modified for the copy
     */
    public function onClone(array &$a_properties, string $a_plugin_version): void
    {
        global $DIC;
        $logger = $DIC->logger()->pcaic();

        if ($additional_data_id = ($a_properties['additional_data_id'] ?? null)) {
            $data = $this->getData($additional_data_id);
            if ($data) {
                $id = $this->saveData($data);
                $a_properties['additional_data_id'] = $id;
            }
        }

        if (isset($a_properties['chat_id'])) {
            $old_chat_id = $a_properties['chat_id'];

            try {
                require_once(__DIR__ . '/../src/bootstrap.php');

                $is_cut_paste = $this->isCutPasteOperation($old_chat_id);
                $old_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($old_chat_id);

                if ($old_config->exists()) {
                    if ($is_cut_paste) {
                        $logger->debug("PageComponent moved - preserving chat data");
                    } else {
                        $new_chat_id = uniqid('chat_', true);

                        $cloned_background_files = $this->cloneBackgroundFiles($old_config->getBackgroundFiles());

                        $new_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($new_chat_id);
                        $new_config->setPageId($old_config->getPageId());
                        $new_config->setParentId($old_config->getParentId());
                        $new_config->setParentType($old_config->getParentType());
                        $new_config->setTitle($old_config->getTitle());
                        $new_config->setSystemPrompt($old_config->getSystemPrompt());
                        $new_config->setAiService($old_config->getAiService());
                        $new_config->setMaxMemory($old_config->getMaxMemory());
                        $new_config->setCharLimit($old_config->getCharLimit());
                        $new_config->setBackgroundFiles($cloned_background_files);
                        $new_config->setPersistent($old_config->isPersistent());
                        $new_config->setIncludePageContext($old_config->isIncludePageContext());
                        $new_config->setEnableChatUploads($old_config->isEnableChatUploads());
                        $new_config->setEnableStreaming($old_config->isEnableStreaming());
                        $new_config->setEnableRag($old_config->isEnableRag());
                        $new_config->setIsOnline($old_config->isOnline());
                        $new_config->setDisclaimer($old_config->getDisclaimer());
                        $new_config->save();

                        $a_properties['chat_id'] = $new_chat_id;
                        $logger->info("PageComponent copied - created new chat configuration", [
                            'original_chat' => $old_chat_id,
                            'new_chat' => $new_chat_id,
                            'background_files_cloned' => count($cloned_background_files)
                        ]);
                    }
                } else {
                    $new_chat_id = uniqid('chat_', true);
                    $a_properties['chat_id'] = $new_chat_id;
                    $logger->warning("PageComponent cloning: Original chat configuration not found, created new one", [
                        'original_chat' => $old_chat_id,
                        'new_chat' => $new_chat_id
                    ]);
                }
            } catch (\Exception $e) {
                $logger->error("PageComponent cloning failed", [
                    'chat_id' => $old_chat_id,
                    'error' => $e->getMessage()
                ]);
                // Keep the chat ID: the copy uses the original chat instead of pointing to none
            }
        }
    }

    /**
     * Called after a repository object (course, group, ...) has been copied
     *
     * Like onClone(): the copied object gets its own chats.
     *
     * @param array $mapping ref_id mapping from the source to the copied objects
     */
    public function afterRepositoryCopy(
        array &$a_properties,
        array $mapping,
        int $source_ref_id,
        string $a_plugin_version
    ): void {
        global $DIC;
        $logger = $DIC->logger()->pcaic();

        if (empty($a_properties['chat_id'])) {
            return;
        }

        $old_chat_id = $a_properties['chat_id'];

        try {
            require_once(__DIR__ . '/../src/bootstrap.php');

            $old_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($old_chat_id);

            if (!$old_config->exists()) {
                $logger->warning("afterRepositoryCopy: source chat config not found", [
                    'chat_id' => $old_chat_id
                ]);
                return;
            }

            $new_chat_id = uniqid('chat_', true);
            $cloned_background_files = $this->cloneBackgroundFiles($old_config->getBackgroundFiles());

            $new_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($new_chat_id);
            // The page context is updated on first rendering (updateChatConfigPageContext())
            $new_config->setPageId($old_config->getPageId());
            $new_config->setParentId($old_config->getParentId());
            $new_config->setParentType($old_config->getParentType());
            $new_config->setTitle($old_config->getTitle());
            $new_config->setSystemPrompt($old_config->getSystemPrompt());
            $new_config->setAiService($old_config->getAiService());
            $new_config->setMaxMemory($old_config->getMaxMemory());
            $new_config->setCharLimit($old_config->getCharLimit());
            $new_config->setBackgroundFiles($cloned_background_files);
            $new_config->setPersistent($old_config->isPersistent());
            $new_config->setIncludePageContext($old_config->isIncludePageContext());
            $new_config->setEnableChatUploads($old_config->isEnableChatUploads());
            $new_config->setEnableStreaming($old_config->isEnableStreaming());
            $new_config->setEnableRag($old_config->isEnableRag());
            $new_config->setIsOnline($old_config->isOnline());
            $new_config->setDisclaimer($old_config->getDisclaimer());
            $new_config->save();

            $a_properties['chat_id'] = $new_chat_id;

            $logger->info("afterRepositoryCopy: created new chat config for copied object", [
                'source_chat' => $old_chat_id,
                'new_chat' => $new_chat_id,
                'source_ref' => $source_ref_id,
            ]);

        } catch (\Exception $e) {
            $logger->error("afterRepositoryCopy: cloning failed – keeping original chat_id", [
                'chat_id' => $old_chat_id,
                'error' => $e->getMessage()
            ]);
            // Keep the chat ID: the copy uses the original chat instead of pointing to none
        }
    }

    /**
     * Called before a page element is deleted
     *
     * On cut (move operation) the chat is kept for the following paste.
     */
    public function onDelete(array $a_properties, string $a_plugin_version, bool $move_operation = false): void
    {
        if ($move_operation) {
            if ($chat_id = ($a_properties['chat_id'] ?? null)) {
                $this->markCutPasteOperation($chat_id);
            }
            return;
        }

        if ($additional_data_id = ($a_properties['additional_data_id'] ?? null)) {
            $this->deleteData($additional_data_id);
        }

        if ($chat_id = ($a_properties['chat_id'] ?? null)) {
            $this->deleteCompleteChat($chat_id);
        }
    }

    public function getData(int $id): ?string
    {
        global $DIC;
        $db = $DIC->database();

        $query = "SELECT data FROM pcaic_data WHERE id = " . $db->quote($id, 'integer');
        $result = $db->query($query);
        if ($row = $db->fetchAssoc($result)) {
            return $row['data'];
        }
        return null;
    }

    public function saveData(string $data): int
    {
        global $DIC;
        $db = $DIC->database();

        $id = $db->nextId('pcaic_data');
        $db->insert(
            'pcaic_data',
            array(
                'id' => array('integer', $id),
                'data' => array('text', $data)
            )
        );
        return $id;
    }

    public function deleteData(int $id): void
    {
        global $DIC;
        $db = $DIC->database();

        $query = "DELETE FROM pcaic_data WHERE id = " . $db->quote($id, 'integer');
        $db->manipulate($query);
    }

    /**
     * Delete a chat completely: attachments (incl. RAG and Resource Storage), messages,
     * sessions and configuration
     *
     * @throws ilException
     */
    public function deleteCompleteChat(string $chat_id): void
    {
        global $DIC;
        $logger = $DIC->logger()->pcaic();
        $db = $DIC->database();

        $logger->info('Delete: Starting chat deletion', ['chat_id' => $chat_id]);

        $attachment_count = 0;
        $rag_count = 0;

        try {
            $session_ids = [];
            $result = $db->query(
                "SELECT session_id FROM pcaic_sessions WHERE chat_id = " . $db->quote($chat_id, 'text')
            );
            while ($row = $db->fetchAssoc($result)) {
                $session_ids[] = $row['session_id'];
            }

            $message_ids = [];
            if (!empty($session_ids)) {
                $quoted_session_ids = implode(',', array_map(fn($id) => $db->quote($id, 'text'), $session_ids));
                $result = $db->query("SELECT message_id FROM pcaic_messages WHERE session_id IN ($quoted_session_ids)");
                while ($row = $db->fetchAssoc($result)) {
                    $message_ids[] = (int) $row['message_id'];
                }
            }

            if (!empty($message_ids)) {
                $quoted_message_ids = implode(',', array_map(fn($id) => $db->quote($id, 'integer'), $message_ids));
                $result = $db->query("SELECT id FROM pcaic_attachments WHERE message_id IN ($quoted_message_ids)");
                while ($row = $db->fetchAssoc($result)) {
                    try {
                        $attachment = new \ILIAS\Plugin\pcaic\Model\Attachment((int) $row['id']);
                        if ($attachment->getRAGRemoteFileId()) {
                            $rag_count++;
                        }
                        $attachment->delete();
                        $attachment_count++;
                    } catch (\Exception $e) {
                        $logger->warning('Delete: Attachment cleanup failed', [
                            'attachment_id' => $row['id'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            // Background files and uploads not yet sent
            $result = $db->query(
                "SELECT id FROM pcaic_attachments WHERE chat_id = " . $db->quote($chat_id, 'text')
            );
            while ($row = $db->fetchAssoc($result)) {
                try {
                    $attachment = new \ILIAS\Plugin\pcaic\Model\Attachment((int) $row['id']);
                    if ($attachment->getRAGRemoteFileId()) {
                        $rag_count++;
                    }
                    $attachment->delete();
                    $attachment_count++;
                } catch (\Exception $e) {
                    $logger->warning('Delete: Chat attachment cleanup failed', [
                        'attachment_id' => $row['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            if (!empty($session_ids)) {
                $quoted_session_ids = implode(',', array_map(fn($id) => $db->quote($id, 'text'), $session_ids));
                $db->manipulate("DELETE FROM pcaic_messages WHERE session_id IN ($quoted_session_ids)");
            }

            $db->manipulate("DELETE FROM pcaic_sessions WHERE chat_id = " . $db->quote($chat_id, 'text'));

            $db->manipulate("DELETE FROM pcaic_chats WHERE chat_id = " . $db->quote($chat_id, 'text'));

            $logger->info('Delete: Chat deleted successfully', [
                'chat_id' => $chat_id,
                'attachments' => $attachment_count,
                'rag_files' => $rag_count,
                'sessions' => count($session_ids),
                'messages' => count($message_ids)
            ]);

        } catch (\Exception $e) {
            $logger->error('Delete: Chat deletion failed', [
                'chat_id' => $chat_id,
                'error' => $e->getMessage()
            ]);
            throw new ilException('Failed to delete chat: ' . $e->getMessage());
        }
    }

    /**
     * Delete sessions without activity for more than $days days, including messages
     * and uploaded files; background files are kept
     *
     * @return array{sessions: int, messages: int, attachments: int}
     */
    public function cleanupInactiveSessions(int $days): array
    {
        global $DIC;
        $logger = $DIC->logger()->pcaic();
        $db = $DIC->database();

        $stats = ['sessions' => 0, 'messages' => 0, 'attachments' => 0];

        try {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $result = $db->query(
                "SELECT session_id FROM pcaic_sessions " .
                "WHERE last_activity < " . $db->quote($cutoff, 'timestamp')
            );

            $session_ids = [];
            while ($row = $db->fetchAssoc($result)) {
                $session_ids[] = $row['session_id'];
            }

            if (empty($session_ids)) {
                return $stats;
            }

            $quoted = implode(',', array_map(fn($id) => $db->quote($id, 'text'), $session_ids));

            $att_result = $db->query(
                "SELECT a.id FROM pcaic_attachments a " .
                "INNER JOIN pcaic_messages m ON a.message_id = m.message_id " .
                "WHERE m.session_id IN ($quoted)"
            );
            while ($row = $db->fetchAssoc($att_result)) {
                try {
                    $att = new \ILIAS\Plugin\pcaic\Model\Attachment((int) $row['id']);
                    $att->delete();
                    $stats['attachments']++;
                } catch (\Exception $e) {
                    $logger->warning('cleanupInactiveSessions: attachment delete failed', ['id' => $row['id']]);
                }
            }

            $count_result = $db->query(
                "SELECT COUNT(*) AS cnt FROM pcaic_messages WHERE session_id IN ($quoted)"
            );
            $count_row = $db->fetchAssoc($count_result);
            $stats['messages'] = (int) ($count_row['cnt'] ?? 0);

            $db->manipulate("DELETE FROM pcaic_messages WHERE session_id IN ($quoted)");
            $db->manipulate("DELETE FROM pcaic_sessions WHERE session_id IN ($quoted)");

            $stats['sessions'] = count($session_ids);

            $logger->info('cleanupInactiveSessions: done', $stats + ['threshold_days' => $days]);

        } catch (\Exception $e) {
            $logger->error('cleanupInactiveSessions failed', ['error' => $e->getMessage()]);
            throw new ilException('Session cleanup failed: ' . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Delete all sessions, messages and uploaded files of a chat; configuration and
     * background files are kept
     */
    public function clearChatHistory(string $chat_id): void
    {
        global $DIC;
        $logger = $DIC->logger()->pcaic();
        $db = $DIC->database();

        try {
            $session_ids = [];
            $result = $db->query(
                "SELECT session_id FROM pcaic_sessions WHERE chat_id = " . $db->quote($chat_id, 'text')
            );
            while ($row = $db->fetchAssoc($result)) {
                $session_ids[] = $row['session_id'];
            }

            if (!empty($session_ids)) {
                $quoted_session_ids = implode(',', array_map(fn($id) => $db->quote($id, 'text'), $session_ids));

                $result = $db->query(
                    "SELECT a.id FROM pcaic_attachments a
                     INNER JOIN pcaic_messages m ON a.message_id = m.message_id
                     WHERE m.session_id IN ($quoted_session_ids)"
                );
                while ($row = $db->fetchAssoc($result)) {
                    try {
                        $attachment = new \ILIAS\Plugin\pcaic\Model\Attachment((int) $row['id']);
                        $attachment->delete();
                    } catch (\Exception $e) {
                        $logger->warning('clearChatHistory: attachment delete failed', ['id' => $row['id']]);
                    }
                }

                $db->manipulate("DELETE FROM pcaic_messages WHERE session_id IN ($quoted_session_ids)");
            }

            $db->manipulate("DELETE FROM pcaic_sessions WHERE chat_id = " . $db->quote($chat_id, 'text'));

            $logger->info('clearChatHistory: done', ['chat_id' => $chat_id, 'sessions' => count($session_ids)]);

        } catch (\Exception $e) {
            $logger->error('clearChatHistory failed', ['chat_id' => $chat_id, 'error' => $e->getMessage()]);
            throw new ilException('Failed to clear chat history: ' . $e->getMessage());
        }
    }

    /**
     * Delete all chat data and files, then drop the plugin tables
     */
    protected function beforeUninstall(): bool
    {
        global $DIC;
        $db = $DIC->database();
        $logger = $DIC->logger()->pcaic();

        try {
            $this->cleanupAllPluginData();
            $logger->info("All plugin data and files cleaned up successfully");

            $tables = [
                'pcaic_attachments', 'pcaic_messages', 'pcaic_sessions', 'pcaic_chats',
                'pcaic_config', 'pcaic_data', 'pcaic_rag_deletions'
            ];
            $dropped_tables = [];

            foreach ($tables as $table) {
                if ($db->tableExists($table)) {
                    $db->dropTable($table);
                    $dropped_tables[] = $table;
                }
                if ($db->sequenceExists($table)) {
                    $db->dropSequence($table);
                }
            }

            $logger->info("Plugin uninstalled successfully", [
                'tables_dropped' => $dropped_tables
            ]);
            return true;

        } catch (Exception $e) {
            $logger->error("Plugin uninstallation failed", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Remember a cut operation (onDelete() with move operation) for the following onClone()
     */
    private function markCutPasteOperation(string $chat_id): void
    {
        $operations = $this->getCutPasteOperations();
        $operations[$chat_id] = time();

        // Markers older than 60 seconds are outdated
        $current_time = time();
        foreach ($operations as $stored_chat_id => $timestamp) {
            if ($current_time - $timestamp > 60) {
                unset($operations[$stored_chat_id]);
            }
        }

        ilSession::set(self::SESSION_CUT_PASTE_OPERATIONS, $operations);
    }

    private function getCutPasteOperations(): array
    {
        $operations = ilSession::get(self::SESSION_CUT_PASTE_OPERATIONS);
        return is_array($operations) ? $operations : [];
    }

    /**
     * Whether onClone() belongs to a cut and paste of this chat
     */
    private function isCutPasteOperation(string $chat_id): bool
    {
        $operations = $this->getCutPasteOperations();
        if (!isset($operations[$chat_id])) {
            return false;
        }

        // A marker younger than 30 seconds belongs to the current paste; it is used only once
        $is_cut_paste = (time() - (int) $operations[$chat_id]) <= 30;
        unset($operations[$chat_id]);
        ilSession::set(self::SESSION_CUT_PASTE_OPERATIONS, $operations);

        return $is_cut_paste;
    }

    /**
     * Copy the background files for a real copy of the chat
     */
    private function cloneBackgroundFiles(array $file_ids): array
    {
        if (empty($file_ids)) {
            return [];
        }

        global $DIC;
        $logger = $DIC->logger()->pcaic();
        $irss = $DIC->resourceStorage();
        $cloned_files = [];

        foreach ($file_ids as $file_id) {
            try {
                $original_identification = $irss->manage()->find($file_id);
                if (!$original_identification) {
                    $logger->warning("Background file not found during cloning", ['file_id' => $file_id]);
                    continue;
                }

                $stakeholder = new \ILIAS\Plugin\pcaic\Storage\ResourceStakeholder();
                $new_identification = $irss->manage()->clone($original_identification, $stakeholder);
                $cloned_files[] = $new_identification->serialize();

            } catch (\Exception $e) {
                $logger->warning("Background file cloning failed", [
                    'file_id' => $file_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $cloned_files;
    }

    /**
     * Page types the chat can be inserted into
     *
     * blp: blog postings, lm: learning modules, sahs: SCORM editor, qpl: question pools,
     * wpg: wiki pages, auth: login pages, cont: container pages, copa: content pages,
     * impr: imprint
     *
     * @return string[]
     */
    public function getParentTypes(): array
    {
        $par_types = ["blp", "lm", "sahs", "qpl", "wpg", "auth", "cont", "copa", "impr" ];
        return $par_types;
    }

    /**
     * @return string[]
     */
    public function getParentObjectTypes(): array
    {
        $par_types = ["root", "cat", "crs", "grp", "fold"];
        return $par_types;
    }

    /**
     * Delete all chats including sessions, messages and files (used on uninstall)
     */
    private function cleanupAllPluginData(): void
    {
        global $DIC;
        $db = $DIC->database();
        $logger = $DIC->logger()->pcaic();

        try {
            $total_chats_deleted = 0;

            if ($db->tableExists('pcaic_chats')) {
                $result = $db->query("SELECT chat_id FROM pcaic_chats");

                while ($row = $db->fetchAssoc($result)) {
                    try {
                        $this->deleteCompleteChat($row['chat_id']);
                        $total_chats_deleted++;
                    } catch (Exception $e) {
                        $logger->warning("Failed to cleanup chat during uninstall", [
                            'chat_id' => $row['chat_id'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            $logger->info("Plugin data cleanup completed", [
                'chats_deleted' => $total_chats_deleted
            ]);

        } catch (Exception $e) {
            $logger->error("Plugin data cleanup failed", ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
