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

use ILIAS\Plugin\pcaic\Validation\FileUploadValidator;

/**
 * Page editor integration of the chat: create and edit forms, editor preview and
 * rendering on the page
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 *
 * @ilCtrl_isCalledBy ilAIChatPageComponentPluginGUI: ilPCPluggedGUI
 * @ilCtrl_isCalledBy ilAIChatPageComponentPluginGUI: ilRepositoryGUI
 */
class ilAIChatPageComponentPluginGUI extends ilPageComponentPluginGUI
{
    protected ilLanguage $lng;

    protected ilCtrl $ctrl;

    protected ilGlobalTemplateInterface $tpl;

    protected $request;

    protected $logger;

    public function __construct()
    {
        global $DIC;

        parent::__construct();

        $this->lng = $DIC->language();
        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC['tpl'];
        $this->request = $DIC->http()->request();

        $this->logger = $DIC->logger()->pcaic();
    }

    public function setPCGUI(ilPageContentGUI $a_val): void
    {
        parent::setPCGUI($a_val);
    }

    /**
     * Upload requests are forwarded to the upload handler, all other commands must
     * be in the list of allowed commands
     */
    public function executeCommand(): void
    {
        $next_class = $this->ctrl->getNextClass();

        switch ($next_class) {
            case strtolower(ilAIChatPageComponentFileUploadHandlerGUI::class):
                require_once(__DIR__ . '/class.ilAIChatPageComponentFileUploadHandlerGUI.php');
                $gui = new ilAIChatPageComponentFileUploadHandlerGUI();
                $this->ctrl->forwardCommand($gui);
                break;
            default:
                $cmd = $this->ctrl->getCmd();
                $allowed = ["create", "save", "edit", "update", "cancel",
                            "showBasicTab", "saveBasic",
                            "showAdvancedTab", "saveAdvanced",
                            "showStatisticsTab", "clearHistory"];
                if (in_array($cmd, $allowed)) {
                    $this->$cmd();
                } else {
                    $this->tpl->setOnScreenMessage("failure", $this->lng->txt("msg_invalid_cmd"), true);
                    $this->returnToParent();
                }
                break;
        }
    }

    /**
     * Form for inserting a new chat
     */
    public function insert(): void
    {
        global $DIC;
        $form = $this->initForm(true);
        $renderer = $DIC->ui()->renderer();
        $this->tpl->setContent($renderer->render($form));
    }

    /**
     * Save a new chat
     */
    public function create(): void
    {
        global $DIC;
        $form = $this->initForm(true);
        $request = $DIC->http()->request();

        if ($request->getMethod() == "POST") {
            $form = $form->withRequest($request);
            $data = $form->getData();

            if ($form->getError() != null) {
                $renderer = $DIC->ui()->renderer();
                $this->tpl->setContent($renderer->render($form));
                return;
            }

            if ($data !== null) {
                $flat_data = array_merge(...array_map(fn($s) => is_array($s) ? $s : [], $data));
                if ($this->saveForm($flat_data, true)) {
                    $this->tpl->setOnScreenMessage("success", $this->lng->txt("saved_successfully"), true);
                    $this->returnToParent();
                    return;
                } else {
                }
            } else {
            }
        }

        $renderer = $DIC->ui()->renderer();
        $this->tpl->setContent($renderer->render($form));
    }

    public function edit(): void
    {
        $this->showBasicTab();
    }

    public function update(): void
    {
        $this->saveBasic();
    }

    /**
     * Form for creating and editing a chat
     */
    protected function initForm(bool $a_create = false, string $tab = 'all')
    {
        global $DIC;
        $ui_factory = $DIC->ui()->factory();

        // File types per service are checked again on upload
        $file_handling_enabled = (\platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1') === '1';
        $background_files_enabled = FileUploadValidator::isUploadEnabled('background');

        // Allowed types depend on RAG, see getBackgroundFileExtensions()
        $allowed_extensions = $this->getAllowedBackgroundFileExtensions($a_create);

        if (!$file_handling_enabled) {
            $file_upload = $ui_factory->input()->field()->text(
                $this->plugin->txt('background_files_upload_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('setting_disabled_by_admin'))->withDisabled(true)->withDedicatedName('background_files');
        } elseif (!$background_files_enabled) {
            // Placeholder if background files are disabled globally
            $file_upload = $ui_factory->input()->field()->text(
                $this->plugin->txt('background_files_upload_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('background_files_disabled'))->withDisabled(true)->withDedicatedName('background_files');
        } else {
            $extensions_display = implode(', ', array_map('strtoupper', $allowed_extensions));
            $info_text = 'Upload background files for AI context. Allowed types: ' . $extensions_display;

            require_once(__DIR__ . '/class.ilAIChatPageComponentFileUploadHandlerGUI.php');
            $upload_handler = new ilAIChatPageComponentFileUploadHandlerGUI();

            // MIME types and extensions, because browsers do not recognise every MIME type (e.g. .md)
            $allowed_accept_values = FileUploadValidator::extensionsToAcceptValues($allowed_extensions);

            $file_upload = $ui_factory->input()->field()->file(
                $upload_handler,
                $this->plugin->txt('background_files_upload_label')
            )->withByline($info_text)
             ->withDedicatedName('background_files')
             ->withMaxFiles(10)
             ->withAcceptedMimeTypes($allowed_accept_values);

            if (!$a_create && isset($prop['background_files'])) {
                $existing_file_ids = is_string($prop['background_files']) ?
                    json_decode($prop['background_files'], true) :
                    $prop['background_files'];

                if (is_array($existing_file_ids) && !empty($existing_file_ids)) {
                    $file_upload = $file_upload->withValue($existing_file_ids);
                }
            }
        }

        $defaults = $this->getAIChatDefaults();

        $prop = [];
        $chat_config = null;

        if (!$a_create) {
            $old_properties = $this->getProperties();
            $chat_id = $old_properties['chat_id'] ?? '';

            if (!empty($chat_id)) {
                try {
                    $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
                    if ($chat_config->exists()) {
                        $prop = [
                            'chat_title' => $chat_config->getTitle(),
                            'system_prompt' => $chat_config->getSystemPrompt(),
                            'ai_service' => $chat_config->getAiService(),
                            'max_memory' => $chat_config->getMaxMemory(),
                            'char_limit' => $chat_config->getCharLimit(),
                            'persistent' => $chat_config->isPersistent(),
                            'include_page_context' => $chat_config->isIncludePageContext(),
                            'enable_chat_uploads' => $chat_config->isEnableChatUploads(),
                            'enable_streaming' => $chat_config->isEnableStreaming(),
                            'enable_rag' => $chat_config->isEnableRag(),
                            'show_sources' => $chat_config->isShowSources(),
                            'allow_source_downloads' => $chat_config->isAllowSourceDownloads(),
                            'is_online' => $chat_config->isOnline(),
                            'disclaimer' => $chat_config->getDisclaimer(),
                            'background_files' => json_encode($chat_config->getBackgroundFiles())
                        ];
                    } else {
                        // Chat without stored configuration: use the element properties
                        $prop = $old_properties;
                    }
                } catch (\Exception $e) {
                    $this->logger->warning("Error loading ChatConfig", ['error' => $e->getMessage()]);
                    $prop = $old_properties;
                }
            } else {
                $prop = $old_properties;
            }

        }

        // Only if file upload is available (not the disabled placeholder)
        if (!$a_create && $file_handling_enabled && $background_files_enabled && isset($prop['background_files'])) {
            $existing_file_ids = is_string($prop['background_files']) ?
                json_decode($prop['background_files'], true) :
                $prop['background_files'];

            if (is_array($existing_file_ids) && !empty($existing_file_ids)) {
                $file_upload = $file_upload->withValue($existing_file_ids);
            }
        }

        $chat_title = $ui_factory->input()->field()->text(
            $this->plugin->txt('chat_title_label'),
            $this->plugin->txt('chat_title_info')
        )->withDedicatedName('chat_title')->withRequired(true)->withMaxLength(255)->withValue($prop['chat_title'] ?? $defaults['title']);

        $is_online = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('chat_online_label'),
            $this->plugin->txt('chat_online_info')
        )->withDedicatedName('is_online')->withValue($this->toBool($prop['is_online'] ?? true));

        $system_prompt = $ui_factory->input()->field()->textarea(
            $this->plugin->txt('system_prompt_label'),
            $this->plugin->txt('system_prompt_info')
        )->withDedicatedName('system_prompt')->withMaxLimit(12000)->withValue($prop['system_prompt'] ?? $defaults['prompt']);

        $default_ai_service = \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
        $force_default_service = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';

        $service_options = \ai\AIChatPageComponentLLMRegistry::getServiceOptions(true);

        $stored_service = $prop['ai_service'] ?? null;
        if ($force_default_service === '1') {
            $ai_service_value = $default_ai_service;
        } elseif ($stored_service && isset($service_options[$stored_service])) {
            $ai_service_value = $stored_service;
        } elseif (!empty($service_options)) {
            // The stored service has been disabled: use the first available service
            $ai_service_value = array_key_first($service_options);

            if ($stored_service && !isset($service_options[$stored_service])) {
                global $DIC;
                $DIC->ui()->mainTemplate()->setOnScreenMessage(
                    'info',
                    sprintf(
                        'The previously selected AI service "%s" is no longer available. Please select a new service.',
                        $stored_service
                    )
                );
            }
        } else {
            $ai_service_value = null;
        }

        $ai_service = $ui_factory->input()->field()->select(
            $this->plugin->txt('ai_service_label'),
            $service_options,
            $this->plugin->txt('ai_service_info')
        )->withDedicatedName('ai_service');

        if ($ai_service_value && isset($service_options[$ai_service_value])) {
            $ai_service = $ai_service->withValue($ai_service_value);
        }

        if ($force_default_service === '1') {
            $ai_service = $ai_service->withDisabled(true);
        }

        $max_memory = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('max_memory_label'),
            $this->plugin->txt('max_memory_info')
        )->withDedicatedName('max_memory')->withValue((int) ($prop['max_memory'] ?? $defaults['max_memory_messages']));

        $char_limit = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('char_limit_label'),
            $this->plugin->txt('char_limit_info')
        )->withDedicatedName('char_limit')->withValue((int) ($prop['char_limit'] ?? $defaults['characters_limit']));

        $persistent = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('persistent_chat_label'),
            $this->plugin->txt('persistent_chat_info')
        )->withDedicatedName('persistent')->withValue($this->toBool($prop['persistent'] ?? false));

        $include_context = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('include_page_context_label'),
            $this->plugin->txt('include_page_context_info')
        )->withDedicatedName('include_page_context')->withValue($this->toBool($prop['include_page_context'] ?? true));

        // File handling must be known before the file-related fields are built
        $effective_ai_service = $ai_service_value ?? 'ramses';
        $global_file_handling = (\platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1') === '1';
        $service_file_handling_key = $effective_ai_service . '_file_handling_enabled';
        $service_file_handling = (\platform\AIChatPageComponentConfig::get($service_file_handling_key) ?? '1') === '1';
        $file_handling_enabled_for_service = $global_file_handling && $service_file_handling;

        $chat_uploads_globally_enabled = FileUploadValidator::isUploadEnabled('chat');
        if (!$file_handling_enabled_for_service) {
            $enable_chat_uploads = $ui_factory->input()->field()->text(
                $this->plugin->txt('enable_chat_uploads_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('setting_disabled_by_admin'))->withDisabled(true)->withDedicatedName('enable_chat_uploads_disabled');
        } elseif ($chat_uploads_globally_enabled) {
            $enable_chat_uploads = $ui_factory->input()->field()->checkbox(
                $this->plugin->txt('enable_chat_uploads_label'),
                $this->plugin->txt('enable_chat_uploads_info')
            )->withDedicatedName('enable_chat_uploads')->withValue($this->toBool($prop['enable_chat_uploads'] ?? false));
        } else {
            $enable_chat_uploads = $ui_factory->input()->field()->text(
                $this->plugin->txt('enable_chat_uploads_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('chat_uploads_disabled'))->withDisabled(true)->withDedicatedName('enable_chat_uploads_disabled');
        }

        $streaming_globally_enabled = (\platform\AIChatPageComponentConfig::get('enable_streaming') ?? '1') === '1';

        if ($streaming_globally_enabled) {
            $enable_streaming = $ui_factory->input()->field()->checkbox(
                $this->plugin->txt('enable_streaming_label'),
                $this->plugin->txt('enable_streaming_info')
            )->withDedicatedName('enable_streaming')->withValue($this->toBool($prop['enable_streaming'] ?? true));
        }

        // RAG requires file handling, the RAG service and RAG allowed for the AI service
        require_once(__DIR__ . '/ai/class.AIChatPageComponentLLM.php');
        require_once(__DIR__ . '/ai/class.AIChatPageComponentLLMRegistry.php');

        $llm = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($effective_ai_service);
        if ($llm === null) {
            $available_services = \ai\AIChatPageComponentLLMRegistry::getAvailableServices();
            $first_service = !empty($available_services) ? array_key_first($available_services) : null;
            $llm = $first_service ? \ai\AIChatPageComponentLLMRegistry::createServiceInstance($first_service) : null;
        }

        $service_supports_rag = $llm->supportsRAG();

        $rag_config_key = $effective_ai_service . '_enable_rag';
        $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
        $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

        if (!$file_handling_enabled_for_service) {
            $enable_rag = $ui_factory->input()->field()->text(
                $this->plugin->txt('enable_rag_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('setting_disabled_by_admin'))->withDisabled(true)->withDedicatedName('enable_rag_disabled');
        } elseif (!$service_supports_rag) {
            $enable_rag = $ui_factory->input()->field()->text(
                $this->plugin->txt('enable_rag_label'),
                $this->plugin->txt('rag_not_supported_info')
            )->withValue($this->plugin->txt('rag_not_supported'))->withDisabled(true)->withDedicatedName('enable_rag_disabled');
        } elseif (!$rag_globally_enabled) {
            $enable_rag = $ui_factory->input()->field()->text(
                $this->plugin->txt('enable_rag_label'),
                $this->plugin->txt('setting_disabled_by_admin_info')
            )->withValue($this->plugin->txt('setting_disabled_by_admin'))->withDisabled(true)->withDedicatedName('enable_rag_disabled');
        } else {
            // Source settings are nested in the RAG option
            $rag_enabled_now = $this->toBool($prop['enable_rag'] ?? false);
            $rag_sub_inputs = [
                'show_sources' => $ui_factory->input()->field()->checkbox(
                    $this->plugin->txt('show_sources_label'),
                    $this->plugin->txt('show_sources_info')
                )->withValue($this->toBool($prop['show_sources'] ?? false)),
                'allow_source_downloads' => $ui_factory->input()->field()->checkbox(
                    $this->plugin->txt('allow_source_downloads_label'),
                    $this->plugin->txt('allow_source_downloads_info')
                )->withValue($this->toBool($prop['allow_source_downloads'] ?? false)),
            ];
            $enable_rag = $ui_factory->input()->field()->optionalGroup(
                $rag_sub_inputs,
                $this->plugin->txt('enable_rag_label'),
                $this->plugin->txt('enable_rag_info')
            );
            $enable_rag = $enable_rag->withValue($rag_enabled_now ? [
                'show_sources' => $this->toBool($prop['show_sources'] ?? false),
                'allow_source_downloads' => $this->toBool($prop['allow_source_downloads'] ?? false),
            ] : null);
        }

        $disclaimer = $ui_factory->input()->field()->textarea(
            $this->plugin->txt('legal_disclaimer_label'),
            $this->plugin->txt('legal_disclaimer_info')
        )->withDedicatedName('disclaimer')->withMaxLimit(4000)->withValue($prop['disclaimer'] ?? $defaults['disclaimer']);

        if ($a_create) {
            $form_action = $this->ctrl->getFormAction($this, 'create');
        } elseif ($tab === 'basic') {
            $form_action = $this->ctrl->getFormAction($this, 'saveBasic');
        } else {
            $form_action = $this->ctrl->getFormAction($this, 'update');
        }

        // General settings
        $sections = [];
        $sections[] = $ui_factory->input()->field()->section(
            ['chat_title' => $chat_title, 'is_online' => $is_online, 'system_prompt' => $system_prompt],
            $this->plugin->txt('section_general_label'),
            $this->plugin->txt('section_general_info')
        );

        // AI service (create mode only)
        if ($tab !== 'basic') {
            $ai_config_fields = [];
            if ($force_default_service !== '1') {
                $ai_config_fields['ai_service'] = $ai_service;
            }
            $ai_config_fields['max_memory'] = $max_memory;
            $ai_config_fields['char_limit'] = $char_limit;
            $sections[] = $ui_factory->input()->field()->section(
                $ai_config_fields,
                $this->plugin->txt('section_ai_config_label'),
                $this->plugin->txt('section_ai_config_info')
            );
        }

        // Chat behaviour
        $behavior_fields = [
            'persistent' => $persistent,
            'include_page_context' => $include_context,
        ];
        if ($file_handling_enabled_for_service) {
            $behavior_fields['enable_chat_uploads'] = $enable_chat_uploads;
        }
        if ($streaming_globally_enabled) {
            $behavior_fields['enable_streaming'] = $enable_streaming;
        }
        if ($file_handling_enabled_for_service && $service_supports_rag && $rag_globally_enabled) {
            $behavior_fields['enable_rag'] = $enable_rag;
        }
        $sections[] = $ui_factory->input()->field()->section(
            $behavior_fields,
            $this->plugin->txt('section_behavior_label'),
            $this->plugin->txt('section_behavior_info')
        );

        // Background files
        if ($file_handling_enabled_for_service && $background_files_enabled) {
            $sections[] = $ui_factory->input()->field()->section(
                ['background_files' => $file_upload],
                $this->plugin->txt('section_background_files_label'),
                $this->plugin->txt('section_background_files_info')
            );
        }

        // Legal (create mode only)
        if ($tab !== 'basic') {
            $sections[] = $ui_factory->input()->field()->section(
                ['disclaimer' => $disclaimer],
                $this->plugin->txt('section_legal_label'),
                $this->plugin->txt('section_legal_info')
            );
        }

        $form = $ui_factory->input()->container()->form()->standard($form_action, $sections);

        return $form;
    }

    protected function saveForm(array $form_data, bool $a_create): bool
    {
        $chat_id = '';
        if ($a_create) {
            $chat_id = uniqid('chat_', true);
        } else {
            $properties = $this->getProperties();
            $chat_id = $properties['chat_id'] ?? uniqid('chat_', true);
        }

        // Only if file handling and background files are enabled
        $file_handling_enabled = (\platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1') === '1';
        $background_files_enabled = FileUploadValidator::isUploadEnabled('background');

        $file_ids = [];
        if ($file_handling_enabled && $background_files_enabled) {
            $background_files = $form_data['background_files'] ?? [];

            if (is_array($background_files)) {
                foreach ($background_files as $file_data) {
                    if (is_string($file_data)) {
                        // The upload handler returns the resource ID directly, as array or as JSON string
                        $file_ids[] = $file_data;
                    } elseif (is_array($file_data) && isset($file_data['resource_id'])) {
                        $file_ids[] = $file_data['resource_id'];
                    }
                }
            } elseif (is_string($background_files)) {
                $decoded = json_decode($background_files, true);
                if (is_array($decoded)) {
                    $file_ids = $decoded;
                }
            }
        }

        $page_info = $this->getPageInfo();

        try {
            $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);

            $chat_config->setChatId($chat_id);
            $chat_config->setPageId((int) ($page_info['page_id'] ?? 0));
            $chat_config->setParentId((int) ($page_info['parent_id'] ?? 0));
            $chat_config->setParentType($page_info['parent_type'] ?? '');
            $chat_config->setTitle($form_data['chat_title'] ?? '');
            $chat_config->setSystemPrompt($form_data['system_prompt'] ?? '');

            $force_default_service = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';
            if ($force_default_service === '1') {
                $ai_service = \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
            } else {
                $ai_service = $form_data['ai_service'] ?? \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
            }
            $chat_config->setAiService($ai_service);

            $chat_config->setMaxMemory((int) ($form_data['max_memory'] ?? 10));
            $chat_config->setCharLimit((int) ($form_data['char_limit'] ?? 2000));

            $this->saveBackgroundFilesToAttachments($chat_id, $file_ids, $chat_config, $a_create);

            $chat_config->setIsOnline((bool) ($form_data['is_online'] ?? true));
            $chat_config->setPersistent((bool) ($form_data['persistent'] ?? true));
            $chat_config->setIncludePageContext((bool) ($form_data['include_page_context'] ?? true));
            // Chat uploads and streaming can only be enabled if they are enabled globally
            $chat_uploads_globally_enabled = FileUploadValidator::isUploadEnabled('chat');
            if ($file_handling_enabled && $chat_uploads_globally_enabled) {
                $chat_config->setEnableChatUploads((bool) ($form_data['enable_chat_uploads'] ?? false));
            } else {
                $chat_config->setEnableChatUploads(false);
            }
            $streaming_globally_enabled = (\platform\AIChatPageComponentConfig::get('enable_streaming') ?? '1') === '1';
            if ($streaming_globally_enabled) {
                $chat_config->setEnableStreaming((bool) ($form_data['enable_streaming'] ?? true));
            } else {
                $chat_config->setEnableStreaming(false);
            }

            require_once(__DIR__ . '/ai/class.AIChatPageComponentLLM.php');
            require_once(__DIR__ . '/ai/class.AIChatPageComponentLLMRegistry.php');

            $ai_service = $chat_config->getAiService();
            $llm = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($ai_service);
            if ($llm === null) {
                $available_services = \ai\AIChatPageComponentLLMRegistry::getAvailableServices();
                $first_service = !empty($available_services) ? array_key_first($available_services) : null;
                $llm = $first_service ? \ai\AIChatPageComponentLLMRegistry::createServiceInstance($first_service) : null;
            }

            $service_supports_rag = $llm->supportsRAG();

            $rag_config_key = $ai_service . '_enable_rag';
            $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
            $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

            $rag_was_enabled = $chat_config->isEnableRag();
            $rag_now_enabled = false;

            if ($service_supports_rag && $rag_globally_enabled) {
                $rag_data = $form_data['enable_rag'] ?? null;
                $rag_now_enabled = is_array($rag_data);
                $chat_config->setEnableRag($rag_now_enabled);
                if ($rag_now_enabled) {
                    $chat_config->setShowSources((bool) ($rag_data['show_sources'] ?? false));
                    $chat_config->setAllowSourceDownloads((bool) ($rag_data['allow_source_downloads'] ?? false));
                }
            }
            // Otherwise keep the stored value: RAG may be temporarily unavailable (e.g. RAG
            // service not configured yet) and is checked again for every message

            $chat_config->setDisclaimer($form_data['disclaimer'] ?? '');

            $result = $chat_config->save();

            // When RAG is switched on, existing background files are uploaded to the RAG
            if ($result && !$rag_was_enabled && $rag_now_enabled) {
                $this->logger->info("RAG was activated, syncing background files", ['chat_id' => $chat_id]);
                try {
                    $sync_stats = $llm->syncBackgroundFilesToRAG($chat_config);
                    $this->logger->info("Background files RAG sync completed", $sync_stats);

                    if ($sync_stats['uploaded'] > 0) {
                        $this->tpl->setOnScreenMessage('info', sprintf(
                            $this->plugin->txt('rag_sync_success'),
                            $sync_stats['uploaded']
                        ), true);
                    }
                } catch (\Exception $e) {
                    $this->logger->error("Background files RAG sync failed", ['error' => $e->getMessage()]);
                    $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('rag_sync_error'), true);
                }
            }

            if ($result) {
                // The page element references the chat via these properties
                $properties = $this->getProperties();
                $properties['chat_id'] = $chat_id;
                $properties['chat_title'] = $form_data['chat_title'] ?? '';

                if ($a_create) {
                    $success = $this->createElement($properties);
                } else {
                    $success = $this->updateElement($properties);
                }

                return $success;
            } else {
                $this->logger->warning("Failed to save ChatConfig");
                return false;
            }

        } catch (\Exception $e) {
            $this->logger->warning("Exception saving ChatConfig", ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function cancel()
    {
        $this->returnToParent();
    }

    private function getChatIdForEdit(): string
    {
        $properties = $this->getProperties();
        return $properties['chat_id'] ?? '';
    }

    private function addEditTabs(string $active): void
    {
        global $DIC;
        $tabs = $DIC->tabs();
        $tabs->addTab(
            'basic',
            $this->plugin->txt('tab_settings'),
            $this->ctrl->getLinkTarget($this, 'showBasicTab')
        );
        $tabs->addTab(
            'advanced',
            $this->plugin->txt('tab_advanced_settings'),
            $this->ctrl->getLinkTarget($this, 'showAdvancedTab')
        );
        $tabs->addTab(
            'statistics',
            $this->plugin->txt('tab_statistics'),
            $this->ctrl->getLinkTarget($this, 'showStatisticsTab')
        );
        $tabs->setTabActive($active);
    }

    public function showBasicTab(): void
    {
        global $DIC;
        $this->addEditTabs('basic');
        $form = $this->initForm(false, 'basic');
        $this->tpl->setContent($DIC->ui()->renderer()->render($form));
    }

    public function saveBasic(): void
    {
        global $DIC;
        $form = $this->initForm(false, 'basic');
        if ($DIC->http()->request()->getMethod() === 'POST') {
            $form = $form->withRequest($DIC->http()->request());
            $data = $form->getData();
            if ($form->getError() !== null) {
                $this->addEditTabs('basic');
                $this->tpl->setContent($DIC->ui()->renderer()->render($form));
                return;
            }
            if ($data !== null && $this->saveBasicFormData(array_merge(...array_map(fn($s) => is_array($s) ? $s : [], $data)))) {
                $this->tpl->setOnScreenMessage('success', $this->lng->txt('msg_obj_modified'), true);
                $this->ctrl->redirect($this, 'showBasicTab');
                return;
            }
        }
        $this->addEditTabs('basic');
        $this->tpl->setContent($DIC->ui()->renderer()->render($form));
    }

    private function saveBasicFormData(array $form_data): bool
    {
        $chat_id = $this->getChatIdForEdit();
        if (empty($chat_id)) {
            return false;
        }

        $file_handling_enabled = (\platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1') === '1';
        $background_files_enabled = FileUploadValidator::isUploadEnabled('background');

        $file_ids = [];
        if ($file_handling_enabled && $background_files_enabled) {
            $background_files = $form_data['background_files'] ?? [];
            if (is_array($background_files)) {
                foreach ($background_files as $file_data) {
                    if (is_string($file_data)) {
                        $file_ids[] = $file_data;
                    } elseif (is_array($file_data) && isset($file_data['resource_id'])) {
                        $file_ids[] = $file_data['resource_id'];
                    }
                }
            }
        }

        $page_info = $this->getPageInfo();

        try {
            $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
            $chat_config->setChatId($chat_id);
            $chat_config->setPageId((int) ($page_info['page_id'] ?? 0));
            $chat_config->setParentId((int) ($page_info['parent_id'] ?? 0));
            $chat_config->setParentType($page_info['parent_type'] ?? '');
            $chat_config->setTitle($form_data['chat_title'] ?? '');
            $chat_config->setSystemPrompt($form_data['system_prompt'] ?? '');

            $this->saveBackgroundFilesToAttachments($chat_id, $file_ids, $chat_config, false);

            $chat_config->setIsOnline((bool) ($form_data['is_online'] ?? true));
            $chat_config->setPersistent((bool) ($form_data['persistent'] ?? true));
            $chat_config->setIncludePageContext((bool) ($form_data['include_page_context'] ?? true));

            $chat_uploads_globally_enabled = FileUploadValidator::isUploadEnabled('chat');
            if ($file_handling_enabled && $chat_uploads_globally_enabled) {
                $chat_config->setEnableChatUploads((bool) ($form_data['enable_chat_uploads'] ?? false));
            } else {
                $chat_config->setEnableChatUploads(false);
            }

            $streaming_globally_enabled = (\platform\AIChatPageComponentConfig::get('enable_streaming') ?? '1') === '1';
            if ($streaming_globally_enabled) {
                $chat_config->setEnableStreaming((bool) ($form_data['enable_streaming'] ?? true));
            } else {
                $chat_config->setEnableStreaming(false);
            }

            require_once(__DIR__ . '/ai/class.AIChatPageComponentLLM.php');
            require_once(__DIR__ . '/ai/class.AIChatPageComponentLLMRegistry.php');

            $ai_service = $chat_config->getAiService() ?: (\platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses');

            $llm = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($ai_service);
            if ($llm === null) {
                $available_services = \ai\AIChatPageComponentLLMRegistry::getAvailableServices();
                $first_service = !empty($available_services) ? array_key_first($available_services) : null;
                $llm = $first_service ? \ai\AIChatPageComponentLLMRegistry::createServiceInstance($first_service) : null;
            }

            $service_supports_rag = $llm ? $llm->supportsRAG() : false;
            $rag_config_key = $ai_service . '_enable_rag';
            $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
            $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

            $rag_was_enabled = $chat_config->isEnableRag();
            $rag_now_enabled = false;

            if ($service_supports_rag && $rag_globally_enabled) {
                $rag_data = $form_data['enable_rag'] ?? null;
                $rag_now_enabled = is_array($rag_data);
                $chat_config->setEnableRag($rag_now_enabled);
                if ($rag_now_enabled) {
                    $chat_config->setShowSources((bool) ($rag_data['show_sources'] ?? false));
                    $chat_config->setAllowSourceDownloads((bool) ($rag_data['allow_source_downloads'] ?? false));
                }
            } else {
                $chat_config->setEnableRag(false);
            }

            $result = $chat_config->save();

            if ($result && !$rag_was_enabled && $rag_now_enabled && $llm) {
                $this->logger->info("RAG was activated, syncing background files", ['chat_id' => $chat_id]);
                try {
                    $sync_stats = $llm->syncBackgroundFilesToRAG($chat_config);
                    if ($sync_stats['uploaded'] > 0) {
                        $this->tpl->setOnScreenMessage('info', sprintf($this->plugin->txt('rag_sync_success'), $sync_stats['uploaded']), true);
                    }
                } catch (\Exception $e) {
                    $this->logger->error("Background files RAG sync failed", ['error' => $e->getMessage()]);
                    $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('rag_sync_error'), true);
                }
            }

            if ($result) {
                $properties = $this->getProperties();
                $properties['chat_id'] = $chat_id;
                $properties['chat_title'] = $form_data['chat_title'] ?? '';
                return (bool) $this->updateElement($properties);
            }

            return false;

        } catch (\Throwable $e) {
            $this->logger->warning("Exception in saveBasicFormData", ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function showAdvancedTab(): void
    {
        global $DIC;
        $this->addEditTabs('advanced');
        $form = $this->buildAdvancedForm();
        $this->tpl->setContent($DIC->ui()->renderer()->render($form));
    }

    public function saveAdvanced(): void
    {
        global $DIC;
        $form = $this->buildAdvancedForm();
        if ($DIC->http()->request()->getMethod() === 'POST') {
            $form = $form->withRequest($DIC->http()->request());
            $data = $form->getData();
            if ($form->getError() !== null) {
                $this->addEditTabs('advanced');
                $this->tpl->setContent($DIC->ui()->renderer()->render($form));
                return;
            }
            if ($data !== null && $this->saveAdvancedFormData($data)) {
                $this->tpl->setOnScreenMessage('success', $this->lng->txt('msg_obj_modified'), true);
                $this->ctrl->redirect($this, 'showAdvancedTab');
                return;
            }
        }
        $this->addEditTabs('advanced');
        $this->tpl->setContent($DIC->ui()->renderer()->render($form));
    }

    private function buildAdvancedForm()
    {
        global $DIC;
        $ui_factory = $DIC->ui()->factory();
        $defaults = $this->getAIChatDefaults();

        $prop = [];
        $chat_id = $this->getChatIdForEdit();
        if (!empty($chat_id)) {
            try {
                $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
                if ($chat_config->exists()) {
                    $prop = [
                        'max_memory' => $chat_config->getMaxMemory(),
                        'char_limit' => $chat_config->getCharLimit(),
                        'disclaimer' => $chat_config->getDisclaimer(),
                        'temperature' => $chat_config->getTemperature(),
                        'ai_service' => $chat_config->getAiService(),
                        'model' => $chat_config->getModel(),
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->warning("Error loading ChatConfig for advanced form", ['error' => $e->getMessage()]);
            }
        }

        // AI service
        $default_ai_service = \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
        $force_default_svc = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';
        $service_options = \ai\AIChatPageComponentLLMRegistry::getServiceOptions(true);

        $current_ai_service = $prop['ai_service'] ?? $default_ai_service;
        if (!isset($service_options[$current_ai_service])) {
            $current_ai_service = array_key_first($service_options) ?: $default_ai_service;
        }

        if ($force_default_svc !== '1') {
            $ai_service_field = $ui_factory->input()->field()->select(
                $this->plugin->txt('ai_service_label'),
                $service_options,
                $this->plugin->txt('ai_service_info')
            )->withDedicatedName('ai_service')->withValue($current_ai_service);
        } else {
            $ai_service_field = null;
        }

        // Model
        $force_model_key = $current_ai_service . '_force_model';
        $force_model = \platform\AIChatPageComponentConfig::get($force_model_key) === '1';
        $current_model = $prop['model'] ?? null;

        if ($force_model) {
            $model_field = null;
        } else {
            $global_default_model = \platform\AIChatPageComponentConfig::get($current_ai_service . '_selected_model') ?: '';
            // Only models the administrator offers to editors
            $service_class = \ai\AIChatPageComponentLLMRegistry::getServiceClass($current_ai_service);
            $cached_models = $service_class ? $service_class::getAvailableModels() : [];
            $model_value = $current_model ?? $global_default_model;
            $model_info = $this->plugin->txt('model_override_info')
                . ($global_default_model ? ' (' . $this->plugin->txt('default_label') . ': ' . ($cached_models[$global_default_model] ?? $global_default_model) . ')' : '');
            if (is_array($cached_models) && !empty($cached_models)) {
                $model_field = $ui_factory->input()->field()->select(
                    $this->plugin->txt('model_override_label'),
                    $cached_models,
                    $model_info
                )->withDedicatedName('model_override');
                if ($model_value && isset($cached_models[$model_value])) {
                    $model_field = $model_field->withValue($model_value);
                }
            } else {
                $model_field = $ui_factory->input()->field()->text(
                    $this->plugin->txt('model_override_label'),
                    $model_info
                )->withDedicatedName('model_override')->withValue((string) $model_value);
            }
        }

        // Memory and character limit
        $max_memory = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('max_memory_label'),
            $this->plugin->txt('max_memory_info')
        )->withDedicatedName('max_memory')
         ->withValue((int) ($prop['max_memory'] ?? $defaults['max_memory_messages']));

        $char_limit = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('char_limit_label'),
            $this->plugin->txt('char_limit_info')
        )->withDedicatedName('char_limit')
         ->withValue((int) ($prop['char_limit'] ?? $defaults['characters_limit']));

        // Temperature
        $force_temp_key = $current_ai_service . '_force_temperature';
        $force_temperature = \platform\AIChatPageComponentConfig::get($force_temp_key) === '1';
        $temp_override_value = $prop['temperature'] ?? null;
        $temp_display = $temp_override_value !== null
            ? rtrim(rtrim(number_format((float) $temp_override_value, 2, '.', ''), '0'), '.')
            : '';

        if ($force_temperature) {
            $temperature_override = null;
        } else {
            $global_default_temp = \platform\AIChatPageComponentConfig::get($current_ai_service . '_temperature') ?: '0.7';
            $temp_value = $temp_override_value !== null ? $temp_display : (string) $global_default_temp;
            $temp_info = $this->plugin->txt('temperature_override_info')
                . ' (' . $this->plugin->txt('default_label') . ': ' . $global_default_temp . ')';
            $temperature_override = $ui_factory->input()->field()->text(
                $this->plugin->txt('temperature_override_label'),
                $temp_info
            )->withDedicatedName('temperature_override')->withMaxLength(10)->withValue($temp_value);
        }

        // Disclaimer
        $disclaimer = $ui_factory->input()->field()->textarea(
            $this->plugin->txt('legal_disclaimer_label'),
            $this->plugin->txt('legal_disclaimer_info')
        )->withDedicatedName('disclaimer')
         ->withMaxLimit(4000)
         ->withValue($prop['disclaimer'] ?? $defaults['disclaimer']);

        $form_fields_adv = [];
        if ($ai_service_field !== null) {
            $form_fields_adv['ai_service'] = $ai_service_field;
        }
        if ($model_field !== null) {
            $form_fields_adv['model_override'] = $model_field;
        }
        $form_fields_adv['max_memory'] = $max_memory;
        $form_fields_adv['char_limit'] = $char_limit;
        if ($temperature_override !== null) {
            $form_fields_adv['temperature_override'] = $temperature_override;
        }
        $form_fields_adv['disclaimer'] = $disclaimer;

        return $ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveAdvanced'),
            $form_fields_adv
        );
    }

    private function saveAdvancedFormData(array $form_data): bool
    {
        $chat_id = $this->getChatIdForEdit();
        if (empty($chat_id)) {
            return false;
        }

        try {
            $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
            if (!$chat_config->exists()) {
                return false;
            }
            $force_default_svc = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';
            if ($force_default_svc === '1') {
                $chat_config->setAiService(\platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses');
            } elseif (!empty($form_data['ai_service'])) {
                $chat_config->setAiService($form_data['ai_service']);
            }

            $ai_service_id = $chat_config->getAiService();
            $force_model = \platform\AIChatPageComponentConfig::get($ai_service_id . '_force_model') === '1';
            if ($force_model) {
                $chat_config->setModel(null);
            } else {
                $model_value = $form_data['model_override'] ?? null;
                $model_value = is_string($model_value) && $model_value !== '' ? $model_value : null;
                // NULL keeps the chat linked to the global default
                $global_default_model = \platform\AIChatPageComponentConfig::get($ai_service_id . '_selected_model') ?: '';
                if ($model_value === $global_default_model) {
                    $model_value = null;
                }
                $chat_config->setModel($model_value);
            }

            $chat_config->setMaxMemory((int) ($form_data['max_memory'] ?? 10));
            $chat_config->setCharLimit((int) ($form_data['char_limit'] ?? 2000));

            $force_temperature = \platform\AIChatPageComponentConfig::get($ai_service_id . '_force_temperature') === '1';
            if ($force_temperature) {
                $chat_config->setTemperature(null);
            } else {
                $raw = str_replace(',', '.', (string) ($form_data['temperature_override'] ?? ''));
                $temp_value = is_numeric($raw) ? max(0.0, min(2.0, (float) $raw)) : null;
                // NULL keeps the chat linked to the global default
                $global_default_temp = (float) (\platform\AIChatPageComponentConfig::get($ai_service_id . '_temperature') ?: 0.7);
                if ($temp_value !== null && abs($temp_value - $global_default_temp) < 0.001) {
                    $temp_value = null;
                }
                $chat_config->setTemperature($temp_value);
            }

            $chat_config->setDisclaimer($form_data['disclaimer'] ?? '');
            return (bool) $chat_config->save();
        } catch (\Exception $e) {
            $this->logger->warning("Exception in saveAdvancedFormData", ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function showStatisticsTab(): void
    {
        global $DIC;
        $this->addEditTabs('statistics');

        $chat_id = $this->getChatIdForEdit();
        $ui_factory = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();

        if (empty($chat_id)) {
            $this->tpl->setContent($renderer->render(
                $ui_factory->messageBox()->info($this->plugin->txt('stat_no_sessions'))
            ));
            return;
        }

        $db = $DIC->database();

        $res = $db->queryF(
            "SELECT COUNT(*) AS session_count, MAX(last_activity) AS last_activity
               FROM pcaic_sessions WHERE chat_id = %s",
            ['text'],
            [$chat_id]
        );
        $row = $db->fetchAssoc($res) ?? [];
        $session_count = (int) ($row['session_count'] ?? 0);
        $last_activity = $row['last_activity'] ?? null;

        $res = $db->queryF(
            "SELECT COUNT(*) AS message_count
               FROM pcaic_messages m
               JOIN pcaic_sessions s ON m.session_id = s.session_id
              WHERE s.chat_id = %s",
            ['text'],
            [$chat_id]
        );
        $msg_row = $db->fetchAssoc($res) ?? [];
        $message_count = (int) ($msg_row['message_count'] ?? 0);

        try {
            $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
            $bg_count = $chat_config->exists() ? count($chat_config->getBackgroundFiles()) : 0;
        } catch (\Exception $e) {
            $bg_count = 0;
        }

        $last_activity_str = $last_activity
            ? $this->formatStoredDateTime($last_activity)
            : '–';

        $listing = $ui_factory->listing()->descriptive([
            $this->plugin->txt('stat_sessions') => (string) $session_count,
            $this->plugin->txt('stat_messages') => (string) $message_count,
            $this->plugin->txt('stat_last_activity') => $last_activity_str,
            $this->plugin->txt('stat_background_files') => (string) $bg_count,
        ]);
        $file_status_panel = $this->getBackgroundFileStatusPanel($chat_id);

        $panel = $ui_factory->panel()->standard(
            $this->plugin->txt('tab_statistics'),
            $listing
        );

        $html = '';
        if ($session_count > 0) {
            $clear_btn = $ui_factory->button()->standard(
                $this->plugin->txt('stat_clear_history'),
                $this->ctrl->getLinkTarget($this, 'clearHistory')
            );
            $html .= '<div style="margin-bottom:1rem">' . $renderer->render($clear_btn) . '</div>';
        }
        $html .= $renderer->render($panel);
        if ($file_status_panel !== null) {
            $html .= $renderer->render($file_status_panel);
        }

        $this->tpl->setContent($html);
    }

    /**
     * Panel with the background files of the chat and their processing state in the RAG
     *
     * The state is queried from the RAG service before (at most once per minute and
     * chat, see AIChatPageComponentRAGStatus::refreshChat()).
     */
    private function getBackgroundFileStatusPanel(string $chat_id): ?\ILIAS\UI\Component\Panel\Standard
    {
        global $DIC;
        $db = $DIC->database();
        $ui_factory = $DIC->ui()->factory();

        try {
            $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
            if (!$chat_config->exists()) {
                return null;
            }
            $llm = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($chat_config->getAiService());
            $rag_enabled = $llm !== null && $llm->isRagEnabledForChat($chat_config);
            if ($rag_enabled) {
                \ai\AIChatPageComponentRAGStatus::refreshChat($chat_id);
            }
        } catch (\Exception $e) {
            $this->logger->warning("Processing state of background files not available", ['error' => $e->getMessage()]);
            return null;
        }

        $result = $db->query(
            "SELECT id, rag_status, rag_status_error, rag_retry_at FROM pcaic_attachments"
            . " WHERE chat_id = " . $db->quote($chat_id, 'text') . " AND background_file = 1 ORDER BY timestamp ASC"
        );

        // Files by processing state; problems first
        $groups = array_fill_keys([
            'rag_file_status_failed',
            'rag_file_status_processing',
            'rag_file_status_pending',
            'rag_file_status_completed',
            'rag_file_status_direct',
        ], []);
        while ($row = $db->fetchAssoc($result)) {
            $title = (new \ILIAS\Plugin\pcaic\Model\Attachment((int) $row['id']))->getTitle();
            $status = $this->getRagFileStatusKey($rag_enabled, $row['rag_status'] ?? null);
            if ($status === 'rag_file_status_failed') {
                $title .= $this->getRagFailureDetails($row);
            }
            $groups[$status][] = $title;
        }

        $items = [];
        foreach ($groups as $status => $titles) {
            if ($titles !== []) {
                $label = $this->plugin->txt($status) . ' (' . count($titles) . ')';
                $items[$label] = $ui_factory->listing()->unordered($titles);
            }
        }

        if ($items === []) {
            return null;
        }

        if ($rag_enabled) {
            $checked = $db->fetchAssoc($db->query(
                "SELECT rag_status_checked_at FROM pcaic_chats WHERE chat_id = " . $db->quote($chat_id, 'text')
            ));
            if (!empty($checked['rag_status_checked_at'])) {
                $items[$this->plugin->txt('stat_rag_checked_at')] =
                    $this->formatStoredDateTime($checked['rag_status_checked_at']);
            }
        }

        return $ui_factory->panel()->standard(
            $this->plugin->txt('stat_background_files'),
            $ui_factory->listing()->descriptive($items)
        );
    }

    /**
     * Date and time stored by the plugin (UTC) in the time zone and format of the user
     */
    private function formatStoredDateTime(string $value): string
    {
        return \ilDatePresentation::formatDate(new \ilDateTime($value, IL_CAL_DATETIME, 'UTC'));
    }

    /**
     * Language key of the processing state of a background file
     */
    private function getRagFileStatusKey(bool $rag_enabled, ?string $rag_status): string
    {
        if (!$rag_enabled) {
            return 'rag_file_status_direct';
        }

        switch ($rag_status) {
            case \ai\AIChatPageComponentRAGStatus::COMPLETED:
                return 'rag_file_status_completed';
            case \ai\AIChatPageComponentRAGStatus::PROCESSING:
                return 'rag_file_status_processing';
            case \ai\AIChatPageComponentRAGStatus::FAILED:
                return 'rag_file_status_failed';
            case \ai\AIChatPageComponentRAGStatus::SKIPPED:
                return 'rag_file_status_direct';
            default:
                return 'rag_file_status_pending';
        }
    }

    /**
     * Time of the next attempt and error message of a failed background file
     *
     * @param array $row rag_status_error and rag_retry_at of the attachment
     */
    private function getRagFailureDetails(array $row): string
    {
        $details = [];
        if (!empty($row['rag_retry_at'])) {
            // The new upload is triggered by the next chat request
            $details[] = $row['rag_retry_at'] <= gmdate('Y-m-d H:i:s')
                ? $this->plugin->txt('rag_file_retry_due')
                : sprintf($this->plugin->txt('rag_file_retry_at'), $this->formatStoredDateTime($row['rag_retry_at']));
        }
        if (!empty($row['rag_status_error'])) {
            $details[] = mb_substr((string) $row['rag_status_error'], 0, 160);
        }
        return $details === [] ? '' : ' – ' . implode('; ', $details);
    }

    public function clearHistory(): void
    {
        $chat_id = $this->getChatIdForEdit();
        if (!empty($chat_id)) {
            try {
                $this->plugin->clearChatHistory($chat_id);
                $this->tpl->setOnScreenMessage('success', $this->plugin->txt('stat_history_cleared'), true);
            } catch (\Exception $e) {
                $this->tpl->setOnScreenMessage('failure', $e->getMessage(), true);
            }
        }
        $this->ctrl->redirect($this, 'showStatisticsTab');
    }

    /**
     * @param string $a_mode edit, presentation, print, preview or offline
     */
    public function getElementHTML(string $a_mode, array $a_properties, string $a_plugin_version): string
    {
        // Editors see a preview in the page editor
        if ($a_mode === 'edit') {
            return $this->renderEditPlaceholder($a_properties);
        }

        if (!$this->currentUserCanReadParent()) {
            return '';
        }

        // Chat deleted in the statistics tab: render nothing
        $chat_id = $a_properties['chat_id'] ?? '';
        if (!empty($chat_id)) {
            try {
                $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
                if (!$chat_config->exists()) {
                    return '';
                }
            } catch (\Exception $e) {
                return '';
            }
        }

        $is_online = $this->isChatOnline($chat_id);

        if (!$is_online) {
            // Offline chats are shown to editors with a banner and hidden from learners
            if ($this->currentUserCanWriteParent()) {
                $a_properties['show_offline_banner'] = true;
                return $this->renderChatInterface($a_properties);
            }
            return '';
        }

        return $this->renderChatInterface($a_properties);
    }

    /**
     * Chats without stored configuration count as online
     */
    private function isChatOnline(string $chat_id): bool
    {
        if (empty($chat_id)) {
            return true;
        }
        try {
            $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
            return !$chat_config->exists() || $chat_config->isOnline();
        } catch (\Exception $e) {
            return true;
        }
    }

    private function currentUserCanWriteParent(): bool
    {
        global $DIC;

        $parent_id = (int) ($this->plugin->getParentId() ?? 0);
        if ($parent_id <= 0) {
            return false;
        }

        $refs = ilObject::_getAllReferences($parent_id);
        if (empty($refs)) {
            $refs = [$parent_id];
        }

        foreach ($refs as $ref_id) {
            if ($DIC->access()->checkAccess('write', '', (int) $ref_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read permission on the object containing the page; anonymous users need
     * anonymous access to be enabled in the plugin configuration
     */
    private function currentUserCanReadParent(): bool
    {
        global $DIC;

        if ($DIC->user()->isAnonymous()) {
            $allow_anonymous = (\platform\AIChatPageComponentConfig::get('allow_anonymous_access') === '1');
            if (!$allow_anonymous) {
                return false;
            }
        }

        $parent_id = (int) ($this->plugin->getParentId() ?? 0);
        if ($parent_id <= 0) {
            return true; // Element not yet placed on a page of an object
        }

        $refs = ilObject::_getAllReferences($parent_id);
        if (empty($refs)) {
            $refs = [$parent_id]; // Some page types store the ref_id
        }

        foreach ($refs as $ref_id) {
            if ($DIC->access()->checkAccess('read', '', (int) $ref_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Preview of the chat in the page editor with demo messages
     */
    private function renderEditPlaceholder(array $properties): string
    {
        $tpl = new ilTemplate(
            "tpl.ai_chat_placeholder.html",
            true,
            true,
            $this->plugin->getDirectory()
        );

        $chat_id = $properties['chat_id'] ?? '';
        $config_properties = $properties;

        $is_orphaned = false;
        if (!empty($chat_id)) {
            try {
                $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
                if ($chat_config->exists()) {
                    $config_properties = [
                        'chat_title' => $chat_config->getTitle(),
                        'system_prompt' => $chat_config->getSystemPrompt(),
                        'enable_chat_uploads' => $chat_config->isEnableChatUploads(),
                        'disclaimer' => $chat_config->getDisclaimer()
                    ];
                } else {
                    $is_orphaned = true;
                }
            } catch (\Exception $e) {
                $is_orphaned = true;
            }
        }

        $chat_title = htmlspecialchars($config_properties['chat_title'] ?? $this->plugin->txt('default_chat_title'));
        $tpl->setVariable("CHAT_TITLE", $chat_title);
        $tpl->setVariable("CHAT_ARIA_LABEL", sprintf($this->plugin->txt('chat_aria_label'), $chat_title));

        $tpl->setVariable("EDIT_MODE_LABEL", $this->plugin->txt('edit_mode_label'));
        $tpl->setVariable("CLICK_TO_EDIT_HINT", $this->plugin->txt('click_to_edit_hint'));

        // The chat was deleted in the statistics tab; editors should remove the element
        if ($is_orphaned) {
            $tpl->setCurrentBlock("placeholder_orphaned_badge");
            $tpl->setVariable("ORPHANED_BADGE_LABEL", $this->plugin->txt('chat_orphaned_title'));
            $tpl->parseCurrentBlock();
        }

        $chat_id_for_status = $properties['chat_id'] ?? '';
        if (!$is_orphaned && !$this->isChatOnline($chat_id_for_status)) {
            $tpl->setCurrentBlock("placeholder_offline_badge");
            $tpl->setVariable("OFFLINE_BADGE_LABEL", $this->plugin->txt('chat_offline_badge'));
            $tpl->parseCurrentBlock();
        }

        $system_prompt = $config_properties['system_prompt'] ?? '';
        $demo_user_message = $this->plugin->txt('demo_user_message');
        $demo_assistant_message = $this->getDemoAssistantMessage($system_prompt);

        $tpl->setVariable("DEMO_USER_MESSAGE", htmlspecialchars($demo_user_message));
        $tpl->setVariable("DEMO_ASSISTANT_MESSAGE", htmlspecialchars($demo_assistant_message));

        $tpl->setVariable("INPUT_PLACEHOLDER", $this->plugin->txt('input_aria_label'));
        $tpl->setVariable("CLEAR_CHAT_LABEL", $this->plugin->txt('clear_chat_label'));
        $tpl->setVariable("THEME_TOGGLE_TITLE", htmlspecialchars($this->plugin->txt('theme_toggle_title')));
        $tpl->setVariable("THEME_TOGGLE_LABEL", htmlspecialchars($this->plugin->txt('theme_toggle_label')));
        $tpl->setVariable("ATTACH_FILE_TITLE", $this->plugin->txt('attach_file_title'));

        $tpl->setVariable("COPY_MESSAGE_TITLE", htmlspecialchars($this->plugin->txt('copy_message_title')));
        $tpl->setVariable("REGENERATE_RESPONSE_TITLE", htmlspecialchars($this->plugin->txt('regenerate_response_title')));

        if (!empty($config_properties['disclaimer'])) {
            $tpl->setCurrentBlock("disclaimer");
            $tpl->setVariable("DISCLAIMER", htmlspecialchars($config_properties['disclaimer']));
            $tpl->parseCurrentBlock();
        }

        $enable_chat_uploads = ($config_properties['enable_chat_uploads'] ?? false);
        $is_chat_uploads_enabled = ($enable_chat_uploads === true || $enable_chat_uploads === '1' || $enable_chat_uploads === 1);

        if ($is_chat_uploads_enabled) {
            $tpl->setCurrentBlock("chat_attach_button_preview");
            $tpl->parseCurrentBlock();
        }

        $this->addChatAssets();

        return $tpl->get();
    }

    /**
     * Labels used when rendering answers (code blocks, tables, highlight boxes)
     */
    private function setRenderingLabels(ilTemplate $tpl): void
    {
        $tpl->setVariable("TABLE_COPY", htmlspecialchars($this->plugin->txt('table_copy')));
        $tpl->setVariable("TABLE_EXPORT_CSV", htmlspecialchars($this->plugin->txt('table_export_csv')));
        $tpl->setVariable("CODE_COPY", htmlspecialchars($this->plugin->txt('code_copy')));
        $tpl->setVariable("ALERT_NOTE", htmlspecialchars($this->plugin->txt('alert_note')));
        $tpl->setVariable("ALERT_TIP", htmlspecialchars($this->plugin->txt('alert_tip')));
        $tpl->setVariable("ALERT_IMPORTANT", htmlspecialchars($this->plugin->txt('alert_important')));
        $tpl->setVariable("ALERT_WARNING", htmlspecialchars($this->plugin->txt('alert_warning')));
        $tpl->setVariable("ALERT_CAUTION", htmlspecialchars($this->plugin->txt('alert_caution')));
    }

    /**
     * Demo answer for the preview, chosen by keywords of the system prompt
     */
    private function getDemoAssistantMessage(string $system_prompt): string
    {
        $default = $this->plugin->txt('demo_assistant_message');

        if (empty($system_prompt)) {
            return $default;
        }

        $system_lower = strtolower($system_prompt);

        if (strpos($system_lower, 'tutor') !== false || strpos($system_lower, 'lehrer') !== false || strpos($system_lower, 'teacher') !== false) {
            return $this->plugin->txt('demo_assistant_tutor') ?: $default;
        }

        if (strpos($system_lower, 'code') !== false || strpos($system_lower, 'programming') !== false || strpos($system_lower, 'programmier') !== false) {
            return $this->plugin->txt('demo_assistant_coding') ?: $default;
        }

        if (strpos($system_lower, 'creative') !== false || strpos($system_lower, 'kreativ') !== false || strpos($system_lower, 'story') !== false) {
            return $this->plugin->txt('demo_assistant_creative') ?: $default;
        }

        return $default;
    }

    private function renderChatInterface(array $properties): string
    {
        $tpl = new ilTemplate(
            "tpl.ai_chat.html",
            true,
            true,
            $this->plugin->getDirectory()
        );

        $chat_id = $properties['chat_id'] ?? uniqid('chat_', true);
        $config_properties = $properties;

        try {
            $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
            if ($chat_config->exists()) {
                // The page may have been moved since the chat was created
                $this->updateChatConfigPageContext($chat_config);

                $config_properties = [
                    'chat_id' => $chat_id,
                    'chat_title' => $chat_config->getTitle(),
                    'system_prompt' => $chat_config->getSystemPrompt(),
                    'ai_service' => $chat_config->getAiService(),
                    'max_memory' => $chat_config->getMaxMemory(),
                    'char_limit' => $chat_config->getCharLimit(),
                    'persistent' => $chat_config->isPersistent(),
                    'include_page_context' => $chat_config->isIncludePageContext(),
                    'enable_chat_uploads' => $chat_config->isEnableChatUploads(),
                    'enable_streaming' => $chat_config->isEnableStreaming(),
                    'disclaimer' => $chat_config->getDisclaimer(),
                    'background_files' => json_encode($chat_config->getBackgroundFiles()),
                    // Flags set by the caller (e.g. offline) are kept
                    'show_offline_banner' => $properties['show_offline_banner'] ?? false,
                ];
            } else {
            }
        } catch (\Exception $e) {
            $this->logger->warning("Error loading ChatConfig for rendering", ['error' => $e->getMessage()]);
        }

        $service_unavailable = empty(\ai\AIChatPageComponentLLMRegistry::getEnabledServices());

        $container_id = 'ai-chat-' . md5($chat_id);
        $messages_id = $container_id . '-messages';

        $tpl->setVariable("CONTAINER_ID", $container_id);
        $tpl->setVariable("MESSAGES_ID", $messages_id);
        $tpl->setVariable("CHAT_ID", htmlspecialchars($chat_id));

        $tpl->setVariable("CHAT_TITLE", htmlspecialchars($config_properties['chat_title'] ?? $this->plugin->txt('default_chat_title')));

        $tpl->setVariable("WELCOME_MESSAGE", $this->plugin->txt('welcome_message'));
        $tpl->setVariable("INPUT_PLACEHOLDER", $this->plugin->txt('input_aria_label'));
        $tpl->setVariable("SEND_BUTTON_TEXT", $this->plugin->txt('send_button_text'));
        $tpl->setVariable("LOADING_TEXT", $this->plugin->txt('loading_text'));
        $tpl->setVariable("CHAR_LIMIT", (int) ($config_properties['char_limit'] ?? 2000));

        $tpl->setVariable("ATTACHMENTS_LABEL", $this->plugin->txt('attachments_label'));
        $tpl->setVariable("CLEAR_ATTACHMENTS_TITLE", $this->plugin->txt('clear_attachments_title'));
        $tpl->setVariable("ATTACH_FILE_TITLE", $this->plugin->txt('attach_file_title'));
        $tpl->setVariable("ATTACH_FILE_LABEL", $this->plugin->txt('attach_file_label'));
        $tpl->setVariable("INPUT_ARIA_LABEL", $this->plugin->txt('input_aria_label'));
        $tpl->setVariable("SEND_ARIA_LABEL", $this->plugin->txt('send_aria_label'));
        $tpl->setVariable("FILE_INPUT_ARIA_LABEL", $this->plugin->txt('file_input_aria_label'));

        $chat_title = htmlspecialchars($config_properties['chat_title'] ?? $this->plugin->txt('default_chat_title'));
        $tpl->setVariable("SKIP_TO_INPUT", $this->plugin->txt('skip_to_input'));
        $tpl->setVariable("CHAT_ARIA_LABEL", sprintf($this->plugin->txt('chat_aria_label'), $chat_title));
        $tpl->setVariable("MESSAGES_ARIA_LABEL", $this->plugin->txt('messages_aria_label'));
        $tpl->setVariable("ATTACHMENTS_ARIA_LABEL", $this->plugin->txt('attachments_aria_label'));
        $tpl->setVariable("ACTIONS_ARIA_LABEL", $this->plugin->txt('actions_aria_label'));
        $tpl->setVariable("NEW_MESSAGE_ARIA", $this->plugin->txt('new_message_aria'));

        $tpl->setVariable("CLEAR_CHAT_TEXT", $this->plugin->txt('clear_chat_text'));
        $tpl->setVariable("CLEAR_CHAT_TITLE", $this->plugin->txt('clear_chat_title'));
        $tpl->setVariable("CLEAR_CHAT_LABEL", $this->plugin->txt('clear_chat_label'));
        $tpl->setVariable("THEME_TOGGLE_TITLE", htmlspecialchars($this->plugin->txt('theme_toggle_title')));
        $tpl->setVariable("THEME_TOGGLE_LABEL", htmlspecialchars($this->plugin->txt('theme_toggle_label')));
        $tpl->setVariable("CLEAR_CHAT_CONFIRM", htmlspecialchars($this->plugin->txt('clear_chat_confirm')));

        $tpl->setVariable("COPY_MESSAGE_TITLE", htmlspecialchars($this->plugin->txt('copy_message_title')));
        $tpl->setVariable("REGENERATE_RESPONSE_TITLE", htmlspecialchars($this->plugin->txt('regenerate_response_title')));

        $tpl->setVariable("MESSAGE_COPIED", htmlspecialchars($this->plugin->txt('message_copied')));
        $tpl->setVariable("MESSAGE_COPY_FAILED", htmlspecialchars($this->plugin->txt('message_copy_failed')));

        $tpl->setVariable("REMOVE_ATTACHMENT", htmlspecialchars($this->plugin->txt('remove_attachment')));
        $tpl->setVariable("THINKING_HEADER", htmlspecialchars($this->plugin->txt('thinking_header')));

        $tpl->setVariable("SOURCES_LABEL", htmlspecialchars($this->plugin->txt('sources_label')));
        $tpl->setVariable("RAG_INCOMPLETE_NOTICE", htmlspecialchars($this->plugin->txt('rag_incomplete_notice')));
        $tpl->setVariable("PAGE_LABEL", htmlspecialchars($this->plugin->txt('page_label')));
        $tpl->setVariable("PAGES_LABEL", htmlspecialchars($this->plugin->txt('pages_label')));
        $this->setRenderingLabels($tpl);
        $tpl->setVariable("CITATION_MORE_SOURCE", htmlspecialchars($this->plugin->txt('citation_more_source')));
        $tpl->setVariable("CITATION_MORE_SOURCES", htmlspecialchars($this->plugin->txt('citation_more_sources')));
        $tpl->setVariable("SOURCE_PREVIOUS", htmlspecialchars($this->plugin->txt('source_previous')));
        $tpl->setVariable("SOURCE_NEXT", htmlspecialchars($this->plugin->txt('source_next')));
        $tpl->setVariable("SCROLL_TO_BOTTOM", htmlspecialchars($this->plugin->txt('scroll_to_bottom')));

        $tpl->setVariable("MAX_FILE_SIZE_MB", FileUploadValidator::getMaxFileSizeMb());

        $max_attachments_config = \platform\AIChatPageComponentConfig::get('max_attachments_per_message');
        $max_attachments = $max_attachments_config ? (int) $max_attachments_config : 5;
        $tpl->setVariable("MAX_ATTACHMENTS_PER_MESSAGE", $max_attachments);

        // Upload error messages with the configured limits
        $error_max_attachments_template = $this->plugin->txt('error_max_attachments');
        $error_file_too_large_template = $this->plugin->txt('error_file_too_large');
        $error_file_type_not_allowed_template = $this->plugin->txt('error_file_type_not_allowed');
        $error_file_upload_failed_template = $this->plugin->txt('error_file_upload_failed');

        // txt() returns the key if the entry is missing
        if ($error_max_attachments_template === 'error_max_attachments' || empty($error_max_attachments_template) || strpos($error_max_attachments_template, '{maxAttachments}') !== false) {
            $error_max_attachments_template = 'Maximum %d attachments per message allowed';
        }
        if ($error_file_too_large_template === 'error_file_too_large' || empty($error_file_too_large_template)) {
            $error_file_too_large_template = 'File too large. Maximum size is %dMB';
        }
        if ($error_file_type_not_allowed_template === 'error_file_type_not_allowed' || empty($error_file_type_not_allowed_template)) {
            $error_file_type_not_allowed_template = 'File type not allowed: %s';
        }
        if ($error_file_upload_failed_template === 'error_file_upload_failed' || empty($error_file_upload_failed_template)) {
            $error_file_upload_failed_template = 'File upload failed: %s';
        }

        $error_max_attachments = sprintf($error_max_attachments_template, $max_attachments);
        $error_file_too_large = sprintf($error_file_too_large_template, FileUploadValidator::getMaxFileSizeMb());

        // These messages are completed by JavaScript with runtime values
        $error_file_type_not_allowed = $error_file_type_not_allowed_template;
        $error_file_upload_failed = $error_file_upload_failed_template;

        $tpl->setVariable("ERROR_MAX_ATTACHMENTS", $error_max_attachments);
        $tpl->setVariable("ERROR_FILE_TOO_LARGE", $error_file_too_large);

        $max_total_upload_mb = (int) (\platform\AIChatPageComponentConfig::get('max_total_upload_size_mb') ?: 25);
        $tpl->setVariable("MAX_TOTAL_UPLOAD_SIZE_MB", $max_total_upload_mb);
        $tpl->setVariable(
            "ERROR_TOTAL_UPLOAD_TOO_LARGE",
            htmlspecialchars(sprintf($this->plugin->txt('error_total_upload_too_large'), $max_total_upload_mb))
        );
        $tpl->setVariable("ERROR_FILE_TYPE_NOT_ALLOWED", $error_file_type_not_allowed);
        $tpl->setVariable("ERROR_FILE_UPLOAD_FAILED", $error_file_upload_failed);

        global $DIC;
        if ($max_size_config !== null) {
            $DIC->logger()->pcaic()->debug("Template: Using central config for file size", [
                'source' => 'central_config',
                'value' => $max_size_config,
                'effective_mb' => $max_size_mb
            ]);
        } else {
            $DIC->logger()->pcaic()->debug("Template: Using fallback file size limit", [
                'source' => 'fallback',
                'effective_mb' => $max_size_mb
            ]);
        }

        $tpl->setVariable("GENERATION_STOPPED", htmlspecialchars($this->plugin->txt('generation_stopped')));
        $tpl->setVariable("REGENERATE_FAILED", htmlspecialchars($this->plugin->txt('regenerate_failed')));
        $tpl->setVariable("WELCOME_MESSAGE", htmlspecialchars($this->plugin->txt('welcome_message')));
        $tpl->setVariable("STOP_GENERATION", htmlspecialchars($this->plugin->txt('stop_generation')));

        $tpl->setVariable("API_URL", htmlspecialchars($this->getAIChatApiUrl()));
        $tpl->setVariable("SYSTEM_PROMPT", htmlspecialchars($config_properties['system_prompt'] ?? 'You are a helpful AI assistant.'));
        $tpl->setVariable("MAX_MEMORY", (int) ($config_properties['max_memory'] ?? 10));
        // JavaScript expects '1' or '0'
        $persistent_value = ($config_properties['persistent'] ?? false);
        $is_persistent = ($persistent_value === true || $persistent_value === '1' || $persistent_value === 1);
        $tpl->setVariable("PERSISTENT", $is_persistent ? 'true' : 'false');
        $tpl->setVariable("AI_SERVICE", htmlspecialchars($config_properties['ai_service'] ?? 'default'));

        $enable_chat_uploads = ($config_properties['enable_chat_uploads'] ?? false);
        $is_chat_uploads_enabled = ($enable_chat_uploads === true || $enable_chat_uploads === '1' || $enable_chat_uploads === 1);
        $chat_uploads_globally_enabled = FileUploadValidator::isUploadEnabled('chat');

        // Anonymous users can never upload
        $is_anonymous = $DIC->user()->isAnonymous();

        // Raw data panel for administrators (system role ID 2)
        $is_ilias_admin = $DIC->rbac()->review()->isAssigned($DIC->user()->getId(), SYSTEM_ROLE_ID);
        $tpl->setVariable("IS_ADMIN", $is_ilias_admin ? 'true' : 'false');

        $effective_chat_uploads_enabled = $is_chat_uploads_enabled && $chat_uploads_globally_enabled && !$is_anonymous;
        $tpl->setVariable("ENABLE_CHAT_UPLOADS", $effective_chat_uploads_enabled ? 'true' : 'false');
        $tpl->setVariable("IS_ANONYMOUS", $is_anonymous ? 'true' : 'false');
        $tpl->setVariable("SERVICE_UNAVAILABLE", $service_unavailable ? 'true' : 'false');
        $tpl->setVariable("NO_SERVICE_AVAILABLE", htmlspecialchars($this->plugin->txt('no_service_available')));

        $enable_streaming = ($config_properties['enable_streaming'] ?? true);
        $is_streaming_enabled = ($enable_streaming === true || $enable_streaming === '1' || $enable_streaming === 1);
        $streaming_globally_enabled = (\platform\AIChatPageComponentConfig::get('enable_streaming') ?? '1') === '1';

        // Streaming must be enabled in the chat and globally
        $effective_streaming_enabled = $is_streaming_enabled && $streaming_globally_enabled;
        $tpl->setVariable("ENABLE_STREAMING", $effective_streaming_enabled ? 'true' : 'false');

        $page_info = $this->getPageInfo();
        $tpl->setVariable("PAGE_ID", (int) ($page_info['page_id'] ?? 0));
        $tpl->setVariable("PARENT_ID", (int) ($page_info['parent_id'] ?? 0));
        $tpl->setVariable("PARENT_TYPE", htmlspecialchars($page_info['parent_type'] ?? ''));
        $tpl->setVariable("INCLUDE_PAGE_CONTEXT", ($config_properties['include_page_context'] ?? true) ? 'true' : 'false');

        $background_files = $config_properties['background_files'] ?? '[]';
        if (is_array($background_files)) {
            $background_files = json_encode($background_files);
        }
        $tpl->setVariable("BACKGROUND_FILES", htmlspecialchars($background_files));

        if (!empty($config_properties['show_offline_banner'])) {
            $tpl->setCurrentBlock("chat_offline_badge");
            $tpl->setVariable("OFFLINE_BADGE_TEXT", $this->plugin->txt('chat_offline_badge'));
            $tpl->parseCurrentBlock();
        }

        if (!empty($config_properties['disclaimer'])) {
            $tpl->setCurrentBlock("disclaimer");
            $tpl->setVariable("DISCLAIMER", htmlspecialchars($config_properties['disclaimer']));
            $tpl->parseCurrentBlock();
        }

        if ($effective_chat_uploads_enabled) {
            $tpl->setCurrentBlock("chat_attachments_area");
            $tpl->parseCurrentBlock();

            $tpl->setCurrentBlock("chat_attach_button");
            $tpl->parseCurrentBlock();

            $tpl->setCurrentBlock("chat_file_input");
            $tpl->parseCurrentBlock();
        }

        $tpl->setVariable("SESSION_MANAGEMENT_HTML", "");

        $this->addChatAssets();

        return $tpl->get();
    }

    /**
     * Store the background files of the chat as attachments (message_id NULL)
     *
     * Removed files are deleted on edit; new files are uploaded to the RAG if RAG is used.
     */
    private function saveBackgroundFilesToAttachments(string $chat_id, array $new_file_ids, \ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config, bool $is_create): void
    {
        global $DIC;
        $db = $DIC->database();

        $user_id = $DIC->user()->getId();

        $llm = $this->getLLMInstanceForChat($chat_config);
        $ai_service = $chat_config->getAiService();

        // RAG service available and allowed for the AI service
        $llm_rag_enabled = \ai\AIChatPageComponentRAG::isEnabledForService($ai_service);

        $enable_rag = $llm->supportsRAG() && $llm_rag_enabled;

        $this->logger->debug("RAG configuration check", [
            'llm_supports_rag' => $llm->supportsRAG(),
            'llm_rag_enabled' => $llm_rag_enabled,
            'enable_rag' => $enable_rag,
            'ai_service' => $ai_service
        ]);

        $existing_query = "SELECT id, resource_id FROM pcaic_attachments " .
                         "WHERE chat_id = " . $db->quote($chat_id, 'text') . " " .
                         "AND message_id IS NULL";
        $existing_result = $db->query($existing_query);
        $existing_files = [];
        while ($row = $db->fetchAssoc($existing_result)) {
            $existing_files[$row['resource_id']] = (int) $row['id'];
        }

        if (!$is_create) {
            $files_to_delete = array_diff(array_keys($existing_files), $new_file_ids);
            foreach ($files_to_delete as $resource_id) {
                $attachment_id = $existing_files[$resource_id];
                try {
                    $attachment = new \ILIAS\Plugin\pcaic\Model\Attachment($attachment_id);

                    if ($enable_rag && $attachment->isInRAG()) {
                        $this->deleteFileFromRAG($attachment, $chat_id, $chat_config);
                    }

                    $attachment->delete();

                    $this->logger->info("Deleted background file attachment", [
                        'chat_id' => $chat_id,
                        'resource_id' => $resource_id,
                        'attachment_id' => $attachment_id
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error("Failed to delete background file attachment", [
                        'resource_id' => $resource_id,
                        'chat_id' => $chat_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        foreach ($new_file_ids as $resource_id) {
            if (isset($existing_files[$resource_id])) {
                continue;
            }

            try {
                $attachment = new \ILIAS\Plugin\pcaic\Model\Attachment();
                $attachment->setMessageId(null);
                $attachment->setBackgroundFile(true);
                $attachment->setChatId($chat_id);
                $attachment->setUserId($user_id);
                $attachment->setResourceId($resource_id);
                $attachment->setTimestamp(date('Y-m-d H:i:s'));

                $this->logger->debug("Processing new background file", [
                    'resource_id' => $resource_id,
                    'chat_id' => $chat_id,
                    'enable_rag' => $enable_rag
                ]);

                if ($enable_rag) {
                    $this->logger->debug("Calling uploadFileToRAG", ['resource_id' => $resource_id]);
                    $this->uploadFileToRAG($attachment, $chat_id, $chat_config);
                    $this->logger->debug("uploadFileToRAG completed", [
                        'resource_id' => $resource_id,
                        'has_rag_collection_id' => $attachment->getRAGCollectionId() !== null,
                        'has_rag_remote_file_id' => $attachment->getRAGRemoteFileId() !== null
                    ]);
                }

                $attachment->save();
                if ($attachment->isInRAG()) {
                    \ai\AIChatPageComponentRAGStatus::markUploaded((int) $attachment->getId());
                }

                $this->logger->info("Saved background file attachment", [
                    'chat_id' => $chat_id,
                    'resource_id' => $resource_id,
                    'rag_collection_id' => $attachment->getRAGCollectionId(),
                    'rag_remote_file_id' => $attachment->getRAGRemoteFileId()
                ]);

            } catch (\Exception $e) {
                $this->logger->error("Failed to save background file attachment", [
                    'resource_id' => $resource_id,
                    'chat_id' => $chat_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function deleteFileFromRAG(\ILIAS\Plugin\pcaic\Model\Attachment $attachment, string $chat_id, \ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config): void
    {
        try {
            $this->logger->debug("Starting RAG deletion", [
                'resource_id' => $attachment->getResourceId(),
                'chat_id' => $chat_id,
                'rag_remote_file_id' => $attachment->getRAGRemoteFileId()
            ]);

            if (!$attachment->getRAGRemoteFileId()) {
                $this->logger->warning("No RAG remote file ID to delete", ['resource_id' => $attachment->getResourceId()]);
                return;
            }

            $llm = $this->getLLMInstanceForChat($chat_config);

            $llm->deleteFileFromRAG($attachment->getRAGRemoteFileId(), $chat_id);

            $this->logger->info("File deleted from RAG successfully", [
                'resource_id' => $attachment->getResourceId(),
                'rag_remote_file_id' => $attachment->getRAGRemoteFileId(),
                'chat_id' => $chat_id,
                'ai_service' => $chat_config->getAiService()
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Failed to delete file from RAG", [
                'resource_id' => $attachment->getResourceId(),
                'chat_id' => $chat_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // The attachment is deleted even if the RAG deletion fails
        }
    }

    /**
     * Upload the file of an attachment to the RAG and store the RAG references
     */
    private function uploadFileToRAG(\ILIAS\Plugin\pcaic\Model\Attachment $attachment, string $chat_id, \ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config): void
    {
        global $DIC;

        try {
            $this->logger->debug("Starting RAG upload", [
                'resource_id' => $attachment->getResourceId(),
                'chat_id' => $chat_id
            ]);

            $irss = $DIC->resourceStorage();
            $identification = $irss->manage()->find($attachment->getResourceId());
            if (!$identification) {
                $this->logger->warning("File not found in IRSS", ['resource_id' => $attachment->getResourceId()]);
                return;
            }

            $this->logger->debug("File found in IRSS");

            $stream = $irss->consume()->stream($identification);
            $content = $stream->getStream()->getContents();

            $revision = $irss->manage()->getCurrentRevision($identification);
            $original_filename = $revision->getTitle();
            $suffix = $revision->getInformation()->getSuffix();

            $this->logger->debug("File downloaded from IRSS", [
                'filename' => $original_filename,
                'suffix' => $suffix,
                'size' => strlen($content)
            ]);

            // Temporary file with the original name, the RAG validates the extension
            $safe_filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $original_filename);
            $temp_file = sys_get_temp_dir() . '/' . $safe_filename;
            file_put_contents($temp_file, $content);

            $this->logger->debug("Temp file created", [
                'temp_path' => $temp_file,
                'exists' => file_exists($temp_file)
            ]);

            $llm = $this->getLLMInstanceForChat($chat_config);
            $this->logger->debug("LLM instance created, calling uploadFileToRAG", [
                'ai_service' => $chat_config->getAiService()
            ]);

            $rag_result = $llm->uploadFileToRAG($temp_file, $chat_id, $original_filename);

            $this->logger->debug("RAG upload returned", ['result' => $rag_result]);

            $attachment->setRagCollectionId($rag_result['collection_id']);
            $attachment->setRagRemoteFileId($rag_result['remote_file_id']);
            $attachment->setRagUploadedAt(date('Y-m-d H:i:s'));

            if (!$chat_config->getRAGCollectionId()) {
                $chat_config->setRAGCollectionId($rag_result['collection_id']);
            }

            @unlink($temp_file);

            $this->logger->info("File uploaded to RAG successfully", [
                'resource_id' => $attachment->getResourceId(),
                'collection_id' => $rag_result['collection_id'],
                'remote_file_id' => $rag_result['remote_file_id'],
                'ai_service' => $chat_config->getAiService()
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Failed to upload file to RAG", [
                'resource_id' => $attachment->getResourceId(),
                'chat_id' => $chat_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->logger->debug("RAG upload configuration", [
                'ai_service' => $chat_config->getAiService(),
                'rag_api_url' => \ai\AIChatPageComponentRAG::getApiUrl(),
                'application_id' => \platform\AIChatPageComponentConfig::get('rag_application_id'),
                'instance_id' => \platform\AIChatPageComponentConfig::get('rag_instance_id'),
                'client_key_set' => \ai\AIChatPageComponentRAG::getClientKey() !== ''
            ]);

            // The attachment is stored without RAG
        }
    }

    /**
     * Service of the chat; falls back to the first available service
     */
    private function getLLMInstanceForChat(\ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config)
    {
        $ai_service = $chat_config->getAiService();

        $instance = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($ai_service);

        if ($instance === null) {
            $available_services = \ai\AIChatPageComponentLLMRegistry::getAvailableServices();
            if (!empty($available_services)) {
                $first_service = array_key_first($available_services);
                $instance = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($first_service);
            }

            if ($instance === null) {
                throw new \Exception("No AI services available in registry");
            }
        }

        return $instance;
    }

    /**
     * Update page_id, parent_id and parent_type of the chat if the page has moved
     */
    private function updateChatConfigPageContext(\ILIAS\Plugin\pcaic\Model\ChatConfig $chat_config): void
    {

        try {
            $current_page_info = $this->getPageInfo();

            $current_page_id = $chat_config->getPageId();
            $current_parent_id = $chat_config->getParentId();
            $current_parent_type = $chat_config->getParentType();

            $new_page_id = $current_page_info['page_id'];
            $new_parent_id = $current_page_info['parent_id'];
            $new_parent_type = $current_page_info['parent_type'];

            if ($current_page_id !== $new_page_id ||
                $current_parent_id !== $new_parent_id ||
                $current_parent_type !== $new_parent_type) {

                $chat_config->setPageId($new_page_id);
                $chat_config->setParentId($new_parent_id);
                $chat_config->setParentType($new_parent_type);
                $chat_config->save();

            }

        } catch (\Exception $e) {
            $this->logger->warning("Error updating page context during render", ['error' => $e->getMessage()]);
        }
    }

    private function addChatAssets(): void
    {
        global $DIC;
        $tpl = $DIC['tpl'];

        $tpl->addCss($this->plugin->getDirectory() . "/css/ai_chat.css");

        // marked.js renders Markdown in the browser
        $tpl->addJavaScript($this->plugin->getDirectory() . "/js/vendor/marked.min.js");
        // DOMPurify cleans the rendered answers
        $tpl->addJavaScript($this->plugin->getDirectory() . "/js/vendor/purify.min.js");
        $tpl->addJavaScript($this->plugin->getDirectory() . "/js/ai_chat.js");
    }

    /**
     * URL of api.php
     */
    private function getAIChatApiUrl(): string
    {
        try {
            $plugin_base_url = $this->plugin->getPluginBaseUrl();
            $api_url = $plugin_base_url . '/api.php';

            return $api_url;
        } catch (Exception $e) {
            $this->logger->warning("Failed to generate API URL", ['error' => $e->getMessage()]);
            return "";
        }
    }

    /**
     * Defaults for new chats from the plugin configuration
     */
    private function getAIChatDefaults(): array
    {
        $defaults = [
            'title' => 'AI Chat',
            'prompt' => 'You are a helpful AI assistant. Please provide accurate and helpful responses.',
            'characters_limit' => 2000,
            'max_memory_messages' => 10,
            'disclaimer' => ''
        ];

        try {
            require_once($this->plugin->getPluginBaseDir() . '/classes/platform/class.AIChatPageComponentConfig.php');

            $prompt = \platform\AIChatPageComponentConfig::get('default_prompt');
            if (!empty($prompt)) {
                $defaults['prompt'] = $prompt;
            }

            $char_limit = \platform\AIChatPageComponentConfig::get('characters_limit');
            if (!empty($char_limit)) {
                $defaults['characters_limit'] = (int) $char_limit;
            }

            $max_memory = \platform\AIChatPageComponentConfig::get('max_memory_messages');
            if (!empty($max_memory)) {
                $defaults['max_memory_messages'] = (int) $max_memory;
            }

            $disclaimer = \platform\AIChatPageComponentConfig::get('default_disclaimer');
            if (!empty($disclaimer)) {
                $defaults['disclaimer'] = $disclaimer;
            }
        } catch (Exception $e) {
            $this->logger->warning("Failed to load defaults from AIChat config", ['error' => $e->getMessage()]);
        }

        return $defaults;
    }

    /**
     * @return array{page_id: int, parent_id: int, parent_type: string}
     */
    private function getPageInfo(): array
    {
        $page_id = (int) ($this->getPlugin()->getPageId() ?? 0);
        $parent_id = (int) ($this->getPlugin()->getParentId() ?? 0);
        $parent_type = ($this->getPlugin()->getParentType() ?? '');

        return [
            'page_id' => $page_id,
            'parent_id' => $parent_id,
            'parent_type' => $parent_type,
        ];
    }

    /**
     * Allowed file types for background files
     *
     * In create mode the RAG file types are used if RAG is available, because RAG will
     * probably be enabled; in edit mode the RAG setting of the chat is used.
     */
    private function getAllowedBackgroundFileExtensions(bool $a_create): array
    {
        require_once(__DIR__ . '/ai/class.AIChatPageComponentLLM.php');
        require_once(__DIR__ . '/ai/class.AIChatPageComponentLLMRegistry.php');

        $ai_service = null;
        $rag_enabled_for_chat = false;

        if (!$a_create) {
            $old_properties = $this->getProperties();
            $chat_id = $old_properties['chat_id'] ?? '';

            if (!empty($chat_id)) {
                try {
                    $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);
                    if ($chat_config->exists()) {
                        $ai_service = $chat_config->getAiService();
                        $rag_enabled_for_chat = $chat_config->isEnableRag();
                    }
                } catch (\Exception $e) {
                    $this->logger->warning("Error loading ChatConfig for file extensions", ['error' => $e->getMessage()]);
                }
            }
        }

        if (empty($ai_service)) {
            $force_default_service = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';
            if ($force_default_service === '1') {
                $ai_service = \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
            } else {
                $service_options = \ai\AIChatPageComponentLLMRegistry::getServiceOptions(true);
                $ai_service = !empty($service_options) ? array_key_first($service_options) : 'ramses';
            }
        }

        $llm = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($ai_service);
        if ($llm === null) {
            return FileUploadValidator::getAllowedExtensions('background');
        }

        $rag_config_key = $ai_service . '_enable_rag';
        $rag_globally_enabled = \platform\AIChatPageComponentConfig::get($rag_config_key);
        $rag_globally_enabled = ($rag_globally_enabled == '1' || $rag_globally_enabled === 1);

        $effective_rag_enabled = false;
        if ($rag_globally_enabled && $llm->supportsRAG()) {
            if ($a_create) {
                $effective_rag_enabled = true;
            } else {
                $effective_rag_enabled = $rag_enabled_for_chat;
            }
        }

        $allowed_extensions = $llm->getAllowedFileTypes($effective_rag_enabled);

        $this->logger->debug("Background file extensions determined", [
            'ai_service' => $ai_service,
            'rag_globally_enabled' => $rag_globally_enabled,
            'rag_enabled_for_chat' => $rag_enabled_for_chat,
            'effective_rag_enabled' => $effective_rag_enabled,
            'allowed_extensions' => $allowed_extensions,
            'mode' => $a_create ? 'create' : 'edit'
        ]);

        return $allowed_extensions;
    }

    /**
     * Convert stored property values ('1', 'true', 'on', ...) to bool
     */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
