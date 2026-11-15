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

namespace ai;

use platform\AIChatPageComponentException;

/**
 * KI:connect.nrw (formerly RAMSES), OpenAI-compatible chat completions API
 *
 * The service ID remains 'ramses', because configuration keys and stored chats use it.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class AIChatPageComponentRAMSES extends AIChatPageComponentLLM
{
    private const ENDPOINT_CHAT = '/v1/chat/completions';

    private const ENDPOINT_MODELS = '/v1/models';

    private string $model;

    private string $api_key;

    public static function getServiceId(): string
    {
        return 'ramses';
    }

    public static function getServiceName(): string
    {
        return 'KI:connect.nrw';
    }

    public static function getServiceDescription(): string
    {
        return 'KI:connect.nrw AI Service (formerly RAMSES)';
    }

    public function getConfigurationFormInputs(): array
    {
        global $DIC;
        $ui_factory = $DIC->ui()->factory();
        $plugin = \ilAIChatPageComponentPlugin::getInstance();

        $inputs = [];

        $ramses_enabled = \platform\AIChatPageComponentConfig::get('ramses_service_enabled') ?? '0';
        $inputs['ramses_service_enabled'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_service_enabled'),
            $plugin->txt('config_service_enabled_info')
        )->withValue($ramses_enabled === '1');

        $inputs['ramses_service_label'] = $ui_factory->input()->field()->text(
            $plugin->txt('config_service_label'),
            sprintf($plugin->txt('config_service_label_info'), self::getServiceName())
        )->withMaxLength(100)->withValue((string) (\platform\AIChatPageComponentConfig::get('ramses_service_label') ?? ''));

        $api_url = \platform\AIChatPageComponentConfig::get('ramses_api_url');
        $inputs['ramses_api_url'] = $ui_factory->input()->field()->text(
            $plugin->txt('config_api_url'),
            $plugin->txt('config_api_url_info')
        )->withMaxLength(500)->withValue((string) ($api_url ?: 'https://chat.kiconnect.nrw/api/v1'))->withRequired(true);

        $api_token = \platform\AIChatPageComponentConfig::get('ramses_api_token');
        $inputs['ramses_api_token'] = $ui_factory->input()->field()->password(
            $plugin->txt('config_api_token'),
            $plugin->txt('config_api_token_info')
        )->withValue((string) ($api_token ?: ''))->withRequired(true);

        $selected_model = \platform\AIChatPageComponentConfig::get('ramses_selected_model');
        $cached_models = \platform\AIChatPageComponentConfig::get('cached_models');

        $force_model = \platform\AIChatPageComponentConfig::get('ramses_force_model') === '1';

        if (is_array($cached_models) && !empty($cached_models)) {
            $model_options = $cached_models;

            $model_select = $ui_factory->input()->field()->select(
                $plugin->txt('config_model_select'),
                $model_options,
                $plugin->txt('config_selected_model_info')
            )->withRequired(true);

            if ($selected_model && isset($model_options[$selected_model])) {
                $model_select = $model_select->withValue($selected_model);
            }

            $inputs['ramses_selected_model'] = $model_select;

            $inputs['ramses_force_model'] = $ui_factory->input()->field()->checkbox(
                $plugin->txt('config_force_model'),
                $plugin->txt('config_force_model_info')
            )->withValue($force_model);

            // Only available once the models have been loaded from the API
            $inputs['ramses_available_models'] = self::buildAvailableModelsInput($model_options);
        } else {
            // Models not loaded yet: show the stored value read-only
            $inputs['ramses_selected_model'] = $ui_factory->input()->field()->text(
                $plugin->txt('config_selected_model'),
                $plugin->txt('refresh_models_not_loaded')
            )->withValue((string) ($selected_model ?: ''))->withDisabled(true);

            $inputs['ramses_force_model'] = $ui_factory->input()->field()->checkbox(
                $plugin->txt('config_force_model'),
                $plugin->txt('config_force_model_info')
            )->withValue($force_model);
        }

        // Text field, so that comma and dot are accepted as decimal separator
        $temperature = \platform\AIChatPageComponentConfig::get('ramses_temperature');
        $temp_value = ($temperature !== null && $temperature !== '') ? (string) $temperature : '0.7';

        $refinery = $DIC->refinery();
        $temp_constraint = $refinery->custom()->constraint(
            function ($value) {
                $normalized = is_string($value) ? str_replace(',', '.', $value) : $value;
                return is_numeric($normalized);
            },
            'Must be a number (use comma or dot as decimal separator)'
        );
        $temp_trafo = $refinery->custom()->transformation(
            function ($value) {
                if (is_string($value)) {
                    $value = str_replace(',', '.', $value);
                }
                return is_numeric($value) ? (float) $value : 0.7;
            }
        );

        $inputs['ramses_temperature'] = $ui_factory->input()->field()->text(
            $plugin->txt('config_default_temperature'),
            $plugin->txt('config_temperature_info')
        )->withMaxLength(10)
         ->withValue($temp_value)
         ->withAdditionalTransformation($temp_constraint)
         ->withAdditionalTransformation($temp_trafo);

        $force_temperature = \platform\AIChatPageComponentConfig::get('ramses_force_temperature') === '1';
        $inputs['ramses_force_temperature'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_force_temperature'),
            $plugin->txt('config_force_temperature_info')
        )->withValue($force_temperature);

        $streaming_enabled = \platform\AIChatPageComponentConfig::get('ramses_streaming_enabled') ?? '1';
        $inputs['ramses_streaming_enabled'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_streaming'),
            $plugin->txt('config_streaming_info')
        )->withValue($streaming_enabled === '1');

        $file_handling_enabled = \platform\AIChatPageComponentConfig::get('ramses_file_handling_enabled') ?? '1';
        $inputs['ramses_file_handling_enabled'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_file_handling'),
            $plugin->txt('config_file_handling_info')
        )->withValue($file_handling_enabled === '1');

        $rag_enabled = \platform\AIChatPageComponentConfig::get('ramses_enable_rag') ?? '1';
        $inputs['ramses_enable_rag'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_enable_rag'),
            $plugin->txt('config_enable_rag_info')
        )->withValue($rag_enabled === '1');

        return $inputs;
    }

    public function saveConfiguration(array $form_data): void
    {
        foreach ($form_data as $key => $value) {
            if ($value instanceof \ILIAS\Data\Password) {
                $value = $value->toString();
            }

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            if ($key === 'ramses_temperature' && is_numeric($value)) {
                $value = (float) $value;
            }

            if ($key === 'ramses_available_models') {
                $value = self::normalizeAvailableModels($value, $form_data['ramses_selected_model'] ?? null);
            }

            \platform\AIChatPageComponentConfig::set($key, $value);
        }
    }

    public static function getDefaultConfiguration(): array
    {
        return [
            'ramses_service_enabled' => '0',
            'ramses_api_url' => 'https://chat.kiconnect.nrw/api/v1',
            'ramses_api_token' => '',
            'ramses_selected_model' => '',
            'ramses_force_model' => '0',
            'ramses_temperature' => 0.7,
            'ramses_force_temperature' => '0',
            'ramses_streaming_enabled' => '1',
            'ramses_file_handling_enabled' => '1',
            'ramses_enable_rag' => '1',
            'cached_models' => []
        ];
    }

    public function getCapabilities(): array
    {
        return [
            'streaming' => true,
            'rag' => true,
            'multimodal' => true,
            'file_types' => ['txt', 'csv', 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'],
            'rag_file_types' => ['txt', 'csv', 'pdf'],
            'max_tokens' => null,
        ];
    }

    /**
     * Cache key kept from earlier versions, so that the stored model list remains valid
     */
    protected static function getModelCacheKey(): string
    {
        return 'cached_models';
    }

    public function setModelOverride(?string $model): void
    {
        parent::setModelOverride($model);
        if ($this->model_override !== null) {
            $this->model = $this->model_override;
        }
    }

    protected function getModelParameters(): array
    {
        if ($this->temperature_override !== null) {
            $temperature = $this->temperature_override;
        } else {
            $temperature = (float) (\platform\AIChatPageComponentConfig::get('ramses_temperature') ?: 0.7);
        }

        return [
            'temperature' => $temperature
        ];
    }

    /**
     * @param string|null $model Defaults to the configured model
     */
    public function __construct(string $model = null)
    {
        parent::__construct();

        if ($model === null) {
            $model = \platform\AIChatPageComponentConfig::get('ramses_selected_model') ?: 'swiss-ai-apertus-70b-instruct-2509';
        }

        $this->model = $model;
        $this->api_key = \platform\AIChatPageComponentConfig::get('ramses_api_token') ?: '';
    }

    private function getEndpointUrl(string $endpoint): string
    {
        $base_url = \platform\AIChatPageComponentConfig::get('ramses_api_url') ?: 'https://chat.kiconnect.nrw/api/v1';

        $base_url = rtrim($base_url, '/');

        if (!str_starts_with($endpoint, '/')) {
            $endpoint = '/' . $endpoint;
        }

        // Accept base URLs ending with /v1, e.g. https://chat.kiconnect.nrw/api/v1
        if (str_ends_with($base_url, '/v1') && str_starts_with($endpoint, '/v1/')) {
            $endpoint = substr($endpoint, 3);
        }

        return $base_url . $endpoint;
    }

    public function getApiKey(): string
    {
        return $this->api_key;
    }

    public function setApiKey(string $api_key): void
    {
        $this->api_key = $api_key;
    }

    public function setStreaming(bool $streaming): void
    {
        $this->streaming = $streaming;
    }

    public function isStreaming(): bool
    {
        return $this->streaming;
    }

    public function supportsMultimodal(): bool
    {
        return true;
    }

    public function supportsBase64Images(): bool
    {
        return true;
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    /**
     * In RAG mode, RAG file types go to the RAG service, all other types are sent as images or text
     */
    public function getAllowedFileTypes(bool $rag_enabled): array
    {
        $multimodal = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'pdf', 'txt', 'csv'];
        if ($rag_enabled) {
            return array_values(array_unique(array_merge($this->getRagFileTypes(), $multimodal)));
        }
        return $multimodal;
    }

    public function sendMessagesArray(array $messages, ?array $context_resources = null): string
    {
        global $DIC;

        $api_url = $this->getEndpointUrl(self::ENDPOINT_CHAT);

        $messages_array = [];

        if (!empty($this->prompt)) {
            $messages_array[] = [
                'role' => 'system',
                'content' => $this->prompt
            ];
        }

        if (!empty($context_resources)) {
            $context_content = [];

            $context_content[] = [
                'type' => 'text',
                'text' => '[BEGIN KNOWLEDGE BASE CONTEXT]\n'
            ];

            foreach ($context_resources as $resource) {
                $resource_desc = "**{$resource['title']}** ({$resource['kind']})";

                $metadata = [];
                if (isset($resource['mime_type'])) {
                    $metadata[] = "Type: {$resource['mime_type']}";
                }
                if (isset($resource['page_number'])) {
                    $metadata[] = "Page: {$resource['page_number']}";
                }
                if (isset($resource['source_file'])) {
                    $metadata[] = "Source: {$resource['source_file']}";
                }
                if (!empty($metadata)) {
                    $resource_desc .= " [" . implode(", ", $metadata) . "]";
                }

                $context_content[] = [
                    'type' => 'text',
                    'text' => $resource_desc
                ];

                switch ($resource['kind']) {
                    case 'page_context':
                    case 'text_file':
                        $context_content[] = [
                            'type' => 'text',
                            'text' => "Content:\n" . $resource['content']
                        ];
                        break;

                    case 'image_file':
                    case 'pdf_page':
                        $context_content[] = [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $resource['url'],
                                'detail' => 'high'
                            ]
                        ];
                        break;
                }

                $context_content[] = [
                    'type' => 'text',
                    'text' => "---"
                ];
            }

            $context_content[] = [
                'type' => 'text',
                'text' => '[END KNOWLEDGE BASE CONTEXT]\nYou may refer to this context when answering future questions.'
            ];

            // Sent as user message, because the API does not accept images in assistant messages
            $messages_array[] = [
                'role' => 'user',
                'content' => $context_content
            ];
        }

        $messages_array = array_merge($messages_array, $messages);

        $payload_array = [
            "messages" => $messages_array,
            "model" => $this->model,
            "stream" => $this->isStreaming()
        ];

        // Ask for token usage in the last streaming chunk
        if ($this->isStreaming()) {
            $payload_array['stream_options'] = ['include_usage' => true];
        }

        $model_params = $this->getModelParameters();
        $payload_array = array_merge($payload_array, $model_params);

        $payload = json_encode($payload_array);

        if ($payload === false) {
            throw new AIChatPageComponentException("Failed to encode API payload: " . json_last_error_msg());
        }

        $this->logger->debug("RAMSES Chat Request (Multimodal): Model=" . $this->model .
                           " | Messages=" . count($messages_array) .
                           " | Has Context=" . (!empty($context_resources) ? 'yes' : 'no') .
                           " | Stream=" . ($this->isStreaming() ? 'yes' : 'no') .
                           " | Parameters=" . json_encode($model_params));

        return $this->executeApiRequest($api_url, $payload);
    }

    /**
     * Send the request; in streaming mode each text fragment is forwarded as Server-Sent Event
     *
     * @return string Complete answer text
     * @throws AIChatPageComponentException
     */
    private function executeApiRequest(string $api_url, string $payload): string
    {
        $curl_session = curl_init();

        $plugin = \ilAIChatPageComponentPlugin::getInstance();
        $plugin_path = $plugin->getDirectory();
        $absolute_plugin_path = realpath($plugin_path);
        $ca_cert_path = $absolute_plugin_path . '/certs/RAMSES.pem';

        if (file_exists($ca_cert_path)) {
            curl_setopt($curl_session, CURLOPT_CAINFO, $ca_cert_path);
        }
        // Otherwise the system CA store is used; certificate verification is never disabled

        curl_setopt($curl_session, CURLOPT_URL, $api_url);
        curl_setopt($curl_session, CURLOPT_POST, true);
        curl_setopt($curl_session, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($curl_session, CURLOPT_RETURNTRANSFER, !$this->isStreaming());
        curl_setopt($curl_session, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->getApiKey()
        ]);

        self::applyProxySettings($curl_session);

        $response_content = '';

        $stream_state = $this->configureChatRequest($curl_session, $response_content);

        $response = curl_exec($curl_session);
        $httpcode = curl_getinfo($curl_session, CURLINFO_HTTP_CODE);
        $total_time = curl_getinfo($curl_session, CURLINFO_TOTAL_TIME);
        $connect_time = curl_getinfo($curl_session, CURLINFO_CONNECT_TIME);
        $err_no = curl_errno($curl_session);
        $err_msg = curl_error($curl_session);
        curl_close($curl_session);

        $err_no = $this->resolveChatRequestError($err_no, $stream_state, $api_url);
        if ($err_no === 0 && $response === false) {
            $response = true; // Streaming transfer ended at the end marker
        }

        if ($response === false || $err_no) {
            $this->logger->error("RAMSES API cURL execution failed", [
                'curl_error' => $err_msg,
                'curl_errno' => $err_no,
                'url' => $api_url,
                'total_time' => round($total_time, 3),
                'connect_time' => round($connect_time, 3)
            ]);
            throw new AIChatPageComponentException("cURL Error: " . $err_msg, $err_no);
        }

        if ($httpcode != 200) {
            $error_body = $this->isStreaming() ? $response_content : $response;
            $response_preview = is_string($error_body) && !empty($error_body) ? substr($error_body, 0, 500) : '(no body)';

            $this->logger->error("RAMSES API request failed: HTTP " . $httpcode . " | URL: " . $api_url . " | Response: " . $response_preview . " | Time: " . round($total_time, 2) . "s");

            $this->logger->error("RAMSES API Error Details", [
                'http_code' => $httpcode,
                'total_time' => round($total_time, 3),
                'connect_time' => round($connect_time, 3),
                'api_url' => $api_url,
                'has_api_key' => !empty($this->api_key),
                'streaming' => $this->isStreaming()
            ]);

            $error_data = is_string($response) ? json_decode($response, true) : null;
            $error_message = $error_data['error']['message'] ?? "HTTP Error: " . $httpcode;

            if (in_array((int) $httpcode, self::SERVICE_BUSY_HTTP_CODES, true)) {
                throw new AIChatPageComponentException(self::SERVICE_BUSY, (int) $httpcode);
            }
            if ($httpcode === 401) {
                throw new AIChatPageComponentException("Invalid API key: " . $error_message, 401);
            } else {
                throw new AIChatPageComponentException($error_message, $httpcode);
            }
        }

        if (!$this->isStreaming()) {
            $decoded_response = json_decode($response, true);
            if ($decoded_response === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new AIChatPageComponentException("Invalid JSON response from RAMSES API: " . json_last_error_msg());
            }
            if (!isset($decoded_response['choices'][0]['message']['content'])) {
                $this->logger->error("Unexpected API response structure: " . substr((string) $response, 0, 200));
                throw new AIChatPageComponentException("Unexpected API response structure from KI:connect.nrw");
            }

            $content = $decoded_response['choices'][0]['message']['content'];

            if (isset($decoded_response['metadata']) && is_array($decoded_response['metadata'])) {
                $this->last_response_metadata = $decoded_response['metadata'];
                $this->logger->debug("RAG sources found", [
                    'count' => count($decoded_response['metadata'])
                ]);
            }

            if (isset($decoded_response['usage']) && is_array($decoded_response['usage'])) {
                $this->last_response_usage = $decoded_response['usage'];
            }

            $usage = $decoded_response['usage'] ?? [];
            $this->logger->debug("RAMSES Chat Response: HTTP " . $httpcode .
                               " | Content Length=" . strlen($content) .
                               " | Tokens: " . json_encode($usage) .
                               " | Sources: " . (isset($decoded_response['metadata']) ? count($decoded_response['metadata']) : 0));

            return $content;
        }

        // Collect text, sources and token usage from all chunks
        $lines = explode("\n", $response_content);
        $complete_message = '';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, 'data: ') !== 0) {
                continue;
            }
            $json_data = substr($line, strlen('data: '));
            if ($json_data === '[DONE]') {
                continue;
            }
            $json = json_decode($json_data, true);
            if (!is_array($json)) {
                continue;
            }

            if (isset($json['choices'][0]['delta']['content'])) {
                $complete_message .= $json['choices'][0]['delta']['content'];
            }

            // Sources may appear in any chunk, usually the last one
            if (isset($json['metadata']) && is_array($json['metadata'])) {
                $this->last_response_metadata = $json['metadata'];
            }

            if (isset($json['usage']) && is_array($json['usage'])) {
                $this->last_response_usage = $json['usage'];
            }
        }

        $source_count = $this->last_response_metadata ? count($this->last_response_metadata) : 0;

        $this->logger->debug("RAMSES Chat Response (Streaming): HTTP " . $httpcode .
                           " | Content Length=" . strlen($complete_message) .
                           " | Chunks Processed=" . count($lines) .
                           " | Sources=" . $source_count);

        return $complete_message;
    }

    /**
     * @throws AIChatPageComponentException If no API token is configured
     */
    public static function fromConfig(): self
    {
        try {
            $model = \platform\AIChatPageComponentConfig::get('ramses_selected_model') ?: 'swiss-ai-apertus-70b-instruct-2509';
            $api_key = \platform\AIChatPageComponentConfig::get('ramses_api_token') ?: '';
            $streaming = (\platform\AIChatPageComponentConfig::get('ramses_streaming_enabled') ?? '1') === '1';

            if (empty($api_key)) {
                throw new AIChatPageComponentException("RAMSES API token not configured");
            }

            $ramses = new self($model);
            $ramses->setApiKey($api_key);
            $ramses->setStreaming($streaming);

            return $ramses;
        } catch (\Exception $e) {
            throw new AIChatPageComponentException("Failed to create RAMSES instance from plugin config: " . $e->getMessage());
        }
    }

    /**
     * Load the model list from the API and update the selection for editors
     *
     * @return array{success: bool, message: string, models: array|null}
     */
    public function refreshModels(): array
    {
        $plugin = \ilAIChatPageComponentPlugin::getInstance();

        try {
            $models_api_url = $this->getEndpointUrl(self::ENDPOINT_MODELS);

            $api_token = \platform\AIChatPageComponentConfig::get('ramses_api_token');

            if (is_object($api_token) && method_exists($api_token, 'toString')) {
                $api_token = $api_token->toString();
            }

            if (empty($api_token)) {
                return [
                    'success' => false,
                    'message' => $plugin->txt('refresh_models_no_token'),
                    'models' => null
                ];
            }

            $ch = curl_init();

            $plugin = \ilAIChatPageComponentPlugin::getInstance();
            $plugin_path = $plugin->getDirectory();
            $absolute_plugin_path = realpath($plugin_path);
            $ca_cert_path = $absolute_plugin_path . '/certs/RAMSES.pem';

            if (file_exists($ca_cert_path)) {
                curl_setopt($ch, CURLOPT_CAINFO, $ca_cert_path);
            }
            // Otherwise the system CA store is used; certificate verification is never disabled

            curl_setopt($ch, CURLOPT_URL, $models_api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $api_token,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            self::applyProxySettings($ch);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $this->logger->error("RAMSES models API cURL execution failed", [
                    'curl_error' => $error,
                    'url' => $models_api_url
                ]);
                return [
                    'success' => false,
                    'message' => $plugin->txt('refresh_models_api_error') . ': cURL ' . $error,
                    'models' => null
                ];
            }

            if ($http_code === 200 && $response) {
                $models_response = json_decode($response, true);

                // Accept an OpenAI-style list object as well as a plain array
                $models_data = [];
                if (is_array($models_response)) {
                    if (isset($models_response['object']) && $models_response['object'] === 'list' && isset($models_response['data'])) {
                        $models_data = $models_response['data'];
                    } else {
                        $models_data = $models_response;
                    }
                }

                if (is_array($models_data) && !empty($models_data)) {
                    $models = [];
                    foreach ($models_data as $model) {
                        // Model ID from 'id' or 'name', whichever the API provides
                        $model_id = $model['id'] ?? $model['name'] ?? null;
                        $model_name = $model['display_name'] ?? $model['name'] ?? $model['id'] ?? null;

                        if ($model_id && $model_name) {
                            $models[$model_id] = $model_name;
                        }
                    }

                    if (!empty($models)) {
                        self::storeRefreshedModels($models);
                        \platform\AIChatPageComponentConfig::set('models_cache_time', time());

                        return [
                            'success' => true,
                            'message' => $plugin->txt('refresh_models_success') . ' (' . count($models) . ' ' . $plugin->txt('refresh_models_count') . ')',
                            'models' => $models
                        ];
                    } else {
                        return [
                            'success' => false,
                            'message' => $plugin->txt('refresh_models_no_models'),
                            'models' => null
                        ];
                    }
                } else {
                    return [
                        'success' => false,
                        'message' => $plugin->txt('refresh_models_invalid_response'),
                        'models' => null
                    ];
                }
            } else {
                $error_msg = $plugin->txt('refresh_models_api_error') . ' (HTTP ' . $http_code . ')';
                if ($error) {
                    $error_msg .= ': ' . $error;
                }

                if ($http_code === 401) {
                    $error_msg .= ' - ' . $plugin->txt('refresh_models_no_token');
                    $this->logger->error("RAMSES Models API 401 Error", [
                        'api_url' => $models_api_url,
                        'token_length' => strlen($api_token)
                    ]);
                }

                return [
                    'success' => false,
                    'message' => $error_msg,
                    'models' => null
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $plugin->txt('refresh_models_exception') . ': ' . $e->getMessage(),
                'models' => null
            ];
        }
    }
}
