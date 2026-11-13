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
 * OpenAI chat completions API
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class AIChatPageComponentOpenAI extends AIChatPageComponentLLM
{
    private const ENDPOINT_CHAT = '/v1/chat/completions';

    private const ENDPOINT_MODELS = '/v1/models';

    private string $model;
    private string $api_key;

    public const MODEL_TYPES = [
        "gpt-4.5-preview" => "GPT-4.5 Preview",
        "gpt-4o" => "GPT-4o",
        "gpt-4o-mini" => "GPT-4o mini",
        "gpt-4-turbo" => "GPT-4 Turbo",
        "gpt-4.5-preview-2025-02-27" => "GPT-4.5 Preview 2025-02-27",
        "gpt-4-0125-preview" => "GPT-4 0125 Preview",
        "gpt-4-turbo-preview" => "GPT-4 Turbo Preview",
        "gpt-3.5-turbo-1106" => "GPT-3.5 Turbo 1106",
        "gpt-4" => "GPT-4",
        "gpt-3.5-turbo" => "GPT-3.5 Turbo"
    ];

    public static function getServiceId(): string
    {
        return 'openai';
    }

    public static function getServiceName(): string
    {
        return 'OpenAI GPT';
    }

    public static function getServiceDescription(): string
    {
        return 'OpenAI GPT Service';
    }

    public function getConfigurationFormInputs(): array
    {
        global $DIC;
        $ui_factory = $DIC->ui()->factory();
        $plugin = \ilAIChatPageComponentPlugin::getInstance();

        $inputs = [];

        $openai_enabled = \platform\AIChatPageComponentConfig::get('openai_service_enabled') ?? '0';
        $inputs['openai_service_enabled'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_service_enabled'),
            $plugin->txt('config_service_enabled_info')
        )->withValue($openai_enabled === '1');

        $inputs['openai_service_label'] = $ui_factory->input()->field()->text(
            $plugin->txt('config_service_label'),
            sprintf($plugin->txt('config_service_label_info'), self::getServiceName())
        )->withMaxLength(100)->withValue((string) (\platform\AIChatPageComponentConfig::get('openai_service_label') ?? ''));

        $api_url = \platform\AIChatPageComponentConfig::get('openai_api_url');
        $inputs['openai_api_url'] = $ui_factory->input()->field()->text(
            $plugin->txt('config_api_url'),
            $plugin->txt('config_api_url_info')
        )->withMaxLength(500)->withValue((string) ($api_url ?: 'https://api.openai.com'))->withRequired(true);

        $api_token = \platform\AIChatPageComponentConfig::get('openai_api_token');
        $inputs['openai_api_token'] = $ui_factory->input()->field()->password(
            $plugin->txt('config_api_token'),
            $plugin->txt('config_api_token_info')
        )->withValue((string) ($api_token ?: ''))->withRequired(true);

        $selected_model = \platform\AIChatPageComponentConfig::get('openai_selected_model');
        $cached_models = \platform\AIChatPageComponentConfig::get('openai_cached_models');

        if (is_array($cached_models) && !empty($cached_models)) {
            $model_options = $cached_models;

            $select_field = $ui_factory->input()->field()->select(
                $plugin->txt('config_selected_model'),
                $model_options,
                $plugin->txt('config_selected_model_info')
            )->withRequired(true);

            if ($selected_model && isset($model_options[$selected_model])) {
                $select_field = $select_field->withValue($selected_model);
            }

            $inputs['openai_selected_model'] = $select_field;
        } else {
            // Built-in list until the models have been loaded from the API
            $select_field = $ui_factory->input()->field()->select(
                $plugin->txt('config_selected_model'),
                self::MODEL_TYPES,
                $plugin->txt('config_selected_model_info')
            )->withRequired(true);

            $value_to_use = ($selected_model && isset(self::MODEL_TYPES[$selected_model])) ? $selected_model : 'gpt-4o';
            $select_field = $select_field->withValue($value_to_use);

            $inputs['openai_selected_model'] = $select_field;
        }

        $force_model = \platform\AIChatPageComponentConfig::get('openai_force_model') === '1';
        $inputs['openai_force_model'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_force_model'),
            $plugin->txt('config_force_model_info')
        )->withValue($force_model);

        // Only available once the models have been loaded from the API
        if (is_array($cached_models) && !empty($cached_models)) {
            $inputs['openai_available_models'] = self::buildAvailableModelsInput($cached_models);
        }

        // Text field, so that comma and dot are accepted as decimal separator
        $temperature = \platform\AIChatPageComponentConfig::get('openai_temperature');
        $temp_value = '0.7';
        if ($temperature !== null && $temperature !== '') {
            $temp_value = (string) $temperature;
        }

        $refinery = $DIC->refinery();
        $temp_constraint = $refinery->custom()->constraint(
            function ($value) {
                if (is_string($value)) {
                    $normalized = str_replace(',', '.', $value);
                    return is_numeric($normalized);
                }
                return is_numeric($value);
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

        $inputs['openai_temperature'] = $ui_factory->input()->field()->text(
            $plugin->txt('config_default_temperature'),
            $plugin->txt('config_temperature_info')
        )->withMaxLength(10)->withValue($temp_value)
         ->withAdditionalTransformation($temp_constraint)
         ->withAdditionalTransformation($temp_trafo);

        $force_temperature = \platform\AIChatPageComponentConfig::get('openai_force_temperature') === '1';
        $inputs['openai_force_temperature'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_force_temperature'),
            $plugin->txt('config_force_temperature_info')
        )->withValue($force_temperature);

        $streaming_enabled = \platform\AIChatPageComponentConfig::get('openai_streaming_enabled') ?? '1';
        $inputs['openai_streaming_enabled'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_streaming'),
            $plugin->txt('config_streaming_info')
        )->withValue($streaming_enabled === '1');

        $file_handling_enabled = \platform\AIChatPageComponentConfig::get('openai_file_handling_enabled') ?? '1';
        $inputs['openai_file_handling_enabled'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_file_handling'),
            $plugin->txt('config_file_handling_info')
        )->withValue($file_handling_enabled === '1');

        // Off by default: retrieved document passages are sent to OpenAI
        $rag_enabled = \platform\AIChatPageComponentConfig::get('openai_enable_rag') ?? '0';
        $inputs['openai_enable_rag'] = $ui_factory->input()->field()->checkbox(
            $plugin->txt('config_enable_rag'),
            $plugin->txt('config_enable_rag_external_info')
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

            if ($key === 'openai_temperature' && is_numeric($value)) {
                $value = (float) $value;
            }

            if ($key === 'openai_available_models') {
                $value = self::normalizeAvailableModels($value, $form_data['openai_selected_model'] ?? null);
            }

            \platform\AIChatPageComponentConfig::set($key, $value);
        }
    }

    public static function getDefaultConfiguration(): array
    {
        return [
            'openai_service_enabled' => '0',
            'openai_api_url' => 'https://api.openai.com',
            'openai_api_token' => '',
            'openai_selected_model' => 'gpt-4o',
            'openai_force_model' => '0',
            'openai_temperature' => 0.7,
            'openai_force_temperature' => '0',
            'openai_file_handling_enabled' => '1',
            'openai_streaming_enabled' => '1',
            'openai_enable_rag' => '0',
        ];
    }

    public function getCapabilities(): array
    {
        return [
            'streaming' => true,
            'rag' => true, // Provided by the separate RAG service
            'multimodal' => true,
            'file_types' => ['txt', 'csv', 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'],
            'rag_file_types' => AIChatPageComponentRAG::getFileTypes(),
            'max_tokens' => 128000,
        ];
    }

    public function __construct(string $model = null)
    {
        parent::__construct();

        if ($model === null) {
            $model = \platform\AIChatPageComponentConfig::get('openai_selected_model') ?: 'gpt-3.5-turbo';
        }

        $this->model = $model;
    }

    private function getEndpointUrl(string $endpoint): string
    {
        $base_url = \platform\AIChatPageComponentConfig::get('openai_api_url') ?: 'https://api.openai.com';

        $base_url = rtrim($base_url, '/');

        if (!str_starts_with($endpoint, '/')) {
            $endpoint = '/' . $endpoint;
        }

        return $base_url . $endpoint;
    }

    public function setModelOverride(?string $model): void
    {
        parent::setModelOverride($model);
        if ($this->model_override !== null) {
            $this->model = $this->model_override;
        }
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

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function supportsMultimodal(): bool
    {
        return true;
    }

    public function supportsBase64Images(): bool
    {
        return true;
    }

    protected function getModelParameters(): array
    {
        // Reasoning models (o-series, GPT-5 except the chat variant) only accept the default temperature
        if (preg_match('/^o\d/', $this->model)
            || (str_starts_with($this->model, 'gpt-5') && !str_contains($this->model, 'chat'))) {
            return [];
        }

        // Per-chat override (unless forced by admin), otherwise the configured default
        $temperature = $this->temperature_override
            ?? (\platform\AIChatPageComponentConfig::get('openai_temperature') ?: 0.7);

        return [
            'temperature' => (float) $temperature
        ];
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
        $messages_array = [];
        if (!empty($this->prompt)) {
            $messages_array[] = [
                'role' => 'system',
                'content' => $this->prompt
            ];
        }

        // Add optional context resources (page context, background text files, images, PDF pages)
        if (!empty($context_resources)) {
            $context_content = [
                ['type' => 'text', 'text' => "[BEGIN KNOWLEDGE BASE CONTEXT]"]
            ];

            foreach ($context_resources as $resource) {
                $title = $resource['title'] ?? '';
                switch ($resource['kind'] ?? '') {
                    case 'page_context':
                    case 'text_file':
                        $context_content[] = [
                            'type' => 'text',
                            'text' => "**{$title}**\n" . ($resource['content'] ?? '')
                        ];
                        break;

                    case 'image_file':
                    case 'pdf_page':
                        if (!empty($resource['url'])) {
                            $context_content[] = ['type' => 'text', 'text' => "**{$title}**"];
                            $context_content[] = [
                                'type' => 'image_url',
                                'image_url' => ['url' => $resource['url']]
                            ];
                        }
                        break;
                }
            }

            $context_content[] = [
                'type' => 'text',
                'text' => "[END KNOWLEDGE BASE CONTEXT]\nYou may refer to this context when answering future questions."
            ];

            if (count($context_content) > 2) {
                $messages_array[] = [
                    'role' => 'user',
                    'content' => $context_content
                ];
            }
        }

        $messages_array = array_merge($messages_array, $messages);

        $api_url = $this->getEndpointUrl(self::ENDPOINT_CHAT);

        $payload = [
            "messages" => $messages_array,
            "model" => $this->model,
            "stream" => $this->isStreaming()
        ];

        // Ask for token usage in the last streaming chunk
        if ($this->isStreaming()) {
            $payload['stream_options'] = ['include_usage' => true];
        }

        $model_params = $this->getModelParameters();
        $payload = array_merge($payload, $model_params);

        if ($this->logger) {
            $this->logger->debug("OpenAI Chat Request: Model=" . $this->model .
                               " | Messages=" . count($messages_array) .
                               " | Stream=" . ($this->isStreaming() ? 'yes' : 'no') .
                               " | Parameters=" . json_encode($model_params));
        }

        return $this->executeApiRequest($api_url, json_encode($payload));
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
            if ($this->logger) {
                $this->logger->error("OpenAI cURL execution failed", [
                    'curl_error' => $err_msg,
                    'curl_errno' => $err_no,
                    'url' => $api_url,
                    'total_time' => round($total_time, 3),
                    'connect_time' => round($connect_time, 3),
                    'has_api_key' => !empty($this->api_key)
                ]);
            }
            throw new AIChatPageComponentException("cURL Error: " . $err_msg, $err_no);
        }

        if ($httpcode != 200) {
            $error_body = $this->isStreaming() ? $response_content : $response;
            $response_preview = is_string($error_body) && !empty($error_body) ? substr($error_body, 0, 500) : '(no body)';

            if ($this->logger) {
                $this->logger->error("OpenAI API request failed: HTTP " . $httpcode . " | URL: " . $api_url . " | Response: " . $response_preview . " | Time: " . round($total_time, 2) . "s");

                $this->logger->error("OpenAI API Error Details", [
                    'http_code' => $httpcode,
                    'total_time' => round($total_time, 3),
                    'connect_time' => round($connect_time, 3),
                    'api_url' => $api_url,
                    'has_api_key' => !empty($this->api_key),
                    'streaming' => $this->isStreaming()
                ]);
            }

            $error_data = is_string($error_body) ? json_decode($error_body, true) : null;
            $error_message = $error_data['error']['message'] ?? "HTTP Error: " . $httpcode;

            if (in_array((int) $httpcode, self::SERVICE_BUSY_HTTP_CODES, true)) {
                throw new AIChatPageComponentException(self::SERVICE_BUSY, (int) $httpcode);
            }
            if ($httpcode === 401) {
                throw new AIChatPageComponentException("Invalid OpenAI API key: " . $error_message, 401);
            } else {
                throw new AIChatPageComponentException("OpenAI API Error: " . $error_message, $httpcode);
            }
        }

        if (!$this->isStreaming()) {
            $decoded_response = json_decode($response, true);
            if ($decoded_response === null && json_last_error() !== JSON_ERROR_NONE) {
                if ($this->logger) {
                    $this->logger->error("Invalid JSON response from OpenAI", [
                        'json_error' => json_last_error_msg(),
                        'response_preview' => substr($response, 0, 500)
                    ]);
                }
                throw new AIChatPageComponentException("Invalid JSON response from OpenAI API: " . json_last_error_msg());
            }
            if (!isset($decoded_response['choices'][0]['message']['content'])) {
                if ($this->logger) {
                    $this->logger->error("Unexpected OpenAI API response structure: " . substr((string) $response, 0, 200));
                }
                throw new AIChatPageComponentException("Unexpected API response structure from OpenAI");
            }

            $content = $decoded_response['choices'][0]['message']['content'];
            $usage = $decoded_response['usage'] ?? [];
            if (is_array($usage) && !empty($usage)) {
                $this->last_response_usage = $usage;
            }
            if ($this->logger) {
                $this->logger->debug("OpenAI Chat Response: HTTP " . $httpcode .
                                   " | Content Length=" . strlen($content) .
                                   " | Tokens: " . json_encode($usage));
            }

            return $content;
        }

        $messages = explode("\n", $response_content);
        $complete_message = '';

        foreach ($messages as $message) {
            if (trim($message) !== '' && strpos($message, 'data: ') === 0) {
                $json_data = substr($message, strlen('data: '));
                if ($json_data === '[DONE]') {
                    continue;
                }
                $json = json_decode($json_data, true);
                if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }
                if (is_array($json) && isset($json['choices'][0]['delta']['content'])) {
                    $complete_message .= $json['choices'][0]['delta']['content'];
                }
                if (is_array($json) && isset($json['usage']) && is_array($json['usage'])) {
                    $this->last_response_usage = $json['usage'];
                }
            }
        }

        if ($this->logger) {
            $this->logger->debug("OpenAI Chat Streaming Response: HTTP " . $httpcode .
                               " | Content Length=" . strlen($complete_message) .
                               " | Time: " . round($total_time, 2) . "s");
        }

        return $complete_message;
    }

    /**
     * @throws AIChatPageComponentException If no API token is configured
     */
    public static function fromConfig(): self
    {
        try {
            $model = \platform\AIChatPageComponentConfig::get('openai_selected_model') ?: 'gpt-3.5-turbo';
            $api_key = \platform\AIChatPageComponentConfig::get('openai_api_token') ?: '';
            $streaming = (\platform\AIChatPageComponentConfig::get('openai_streaming_enabled') ?? '1') === '1';

            if (empty($api_key)) {
                throw new AIChatPageComponentException("OpenAI API token not configured");
            }

            $openai = new self($model);
            $openai->setApiKey($api_key);
            $openai->setStreaming($streaming);

            return $openai;
        } catch (\Exception $e) {
            throw new AIChatPageComponentException("Failed to create OpenAI instance from plugin config: " . $e->getMessage());
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

            $api_token = \platform\AIChatPageComponentConfig::get('openai_api_token');

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
                if ($this->logger) {
                    $this->logger->error("OpenAI models API cURL execution failed", [
                        'curl_error' => $error,
                        'url' => $models_api_url
                    ]);
                }
                return [
                    'success' => false,
                    'message' => $plugin->txt('refresh_models_api_error') . ': cURL ' . $error,
                    'models' => null
                ];
            }

            if ($http_code === 200 && $response) {
                $models_response = json_decode($response, true);

                if (isset($models_response['data']) && is_array($models_response['data'])) {
                    $models = [];
                    foreach ($models_response['data'] as $model) {
                        $model_id = $model['id'] ?? null;
                        // Only GPT and o-series models; non-chat variants are unchecked for editors by default
                        if ($model_id && preg_match('/^(gpt-|chatgpt-|o\d)/', $model_id)) {
                            $model_name = ucwords(str_replace(['-', '_'], ' ', $model_id));
                            $models[$model_id] = $model_name;
                        }
                    }

                    if (!empty($models)) {
                        self::storeRefreshedModels($models);
                        \platform\AIChatPageComponentConfig::set('openai_models_cache_time', time());

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
                    if ($this->logger) {
                        $this->logger->error("OpenAI Models API 401 Error", [
                            'api_url' => $models_api_url,
                            'token_length' => strlen($api_token)
                        ]);
                    }
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
