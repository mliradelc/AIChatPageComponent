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

/**
 * Plugin configuration: general settings, one tab per AI service, RAG service and statistics
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 *
 * @ilCtrl_IsCalledBy  ilAIChatPageComponentConfigGUI: ilObjComponentSettingsGUI
 */
class ilAIChatPageComponentConfigGUI extends ilPluginConfigGUI
{
    private ilAIChatPageComponentPlugin $plugin;
    private ilCtrlInterface $ctrl;
    private \ILIAS\DI\Container $dic;

    public function __construct()
    {
        global $DIC;

        $this->dic = $DIC;
        $this->ctrl = $DIC->ctrl();
        $this->plugin = ilAIChatPageComponentPlugin::getInstance();
    }

    /**
     * Service commands are derived from the registered services:
     * show{Service}Config, save{Service}Configuration, refresh{Service}Models
     */
    public function performCommand(string $cmd): void
    {
        $tpl = $this->dic->ui()->mainTemplate();
        $tpl->setTitle($this->plugin->txt('plugin_title'));
        $tpl->setDescription($this->plugin->txt('plugin_description'));
        $tpl->setTitleIcon(
            $this->plugin->getDirectory() . '/templates/images/ai_chat.svg',
            $this->plugin->txt('plugin_title')
        );

        if ($cmd === 'configure' || $cmd === 'showConfigurationForm') {
            $this->showConfigurationForm('general');
            return;
        }

        if ($cmd === 'saveConfiguration') {
            $this->saveConfiguration();
            return;
        }

        if ($cmd === 'showRagConfig') {
            $this->showConfigurationForm('rag');
            return;
        }

        if ($cmd === 'saveRagConfiguration') {
            $this->saveRagConfiguration();
            return;
        }

        $services = \ai\AIChatPageComponentLLMRegistry::getAvailableServices();

        foreach ($services as $service_id => $service_class) {
            $service_id_cap = ucfirst($service_id);

            if ($cmd === "show{$service_id_cap}Config") {
                $this->showConfigurationForm($service_id);
                return;
            }

            if ($cmd === "save{$service_id_cap}Configuration") {
                $this->saveServiceConfiguration($service_id);
                return;
            }

            if ($cmd === "refresh{$service_id_cap}Models") {
                $this->refreshServiceModels($service_id);
                return;
            }
        }

        if ($cmd === 'showStatistics') {
            $this->showStatistics();
            return;
        }

        if ($cmd === 'gotoPage') {
            $this->gotoPage();
            return;
        }

        if ($cmd === 'setChatOnline') {
            $this->setOnlineStatus(true);
            return;
        }

        if ($cmd === 'setChatOffline') {
            $this->setOnlineStatus(false);
            return;
        }

        if ($cmd === 'clearChatHistory') {
            $this->clearChatHistory();
            return;
        }

        if ($cmd === 'deleteChat') {
            $this->deleteChat();
            return;
        }

        if ($cmd === 'runSessionCleanup') {
            $this->runSessionCleanup();
            return;
        }

        $this->showConfigurationForm('general');
    }

    private function showConfigurationForm(string $active_tab = 'general'): void
    {
        try {
            $this->addConfigTabs($active_tab);

            if ($active_tab === 'general') {
                $form_html = $this->buildGeneralConfigurationForm();
            } elseif ($active_tab === 'rag') {
                $form_html = $this->buildRagConfigurationForm();
            } else {
                $form_html = $this->buildServiceConfigurationForm($active_tab);
            }

            $this->dic->ui()->mainTemplate()->setContent($form_html);
        } catch (\Exception $e) {
            $this->dic->logger()->pcaic()->error('Failed to show configuration form: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', [
                'error' => $e->getMessage(),
                'active_tab' => $active_tab
            ]);
            $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                'failure',
                $this->plugin->txt('config_load_error') . ': ' . $e->getMessage()
            );
        }
    }

    private function buildGeneralConfigurationForm(): string
    {
        $ui_factory = $this->dic->ui()->factory();
        $ui_renderer = $this->dic->ui()->renderer();

        $inputs = [];

        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildDefaultValuesInputs(),
            $this->plugin->txt('config_defaults_title'),
            $this->plugin->txt('config_defaults_info')
        );

        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildProcessingLimitsInputs(),
            $this->plugin->txt('config_processing_title'),
            $this->plugin->txt('config_processing_info')
        );

        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildAiServiceSelectionInputs(),
            $this->plugin->txt('config_services_title'),
            $this->plugin->txt('config_services_info')
        );

        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildUploadConstraintsInputs(),
            $this->plugin->txt('config_upload_title'),
            $this->plugin->txt('config_upload_info')
        );

        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildFileUploadRestrictionsInputs(),
            $this->plugin->txt('config_file_restrictions_title'),
            $this->plugin->txt('config_file_restrictions_info')
        );

        $inputs[] = $ui_factory->input()->field()->section(
            $this->buildAnonymousAccessInputs(),
            $this->plugin->txt('config_anonymous_access_title'),
            $this->plugin->txt('config_anonymous_access_info')
        );

        $form = $ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveConfiguration'),
            $inputs
        );

        return $ui_renderer->render($form);
    }

    /**
     * Defaults for new chats
     */
    private function buildDefaultValuesInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $inputs = [];

        $default_prompt = \platform\AIChatPageComponentConfig::get('default_prompt');
        $inputs['default_prompt'] = $ui_factory->input()->field()->textarea(
            $this->plugin->txt('config_default_prompt'),
            $this->plugin->txt('config_default_prompt_info')
        )->withMaxLimit(4000)->withValue($default_prompt ?: 'You are a helpful AI assistant. Please provide accurate and helpful responses.');

        $disclaimer = \platform\AIChatPageComponentConfig::get('default_disclaimer');
        $inputs['default_disclaimer'] = $ui_factory->input()->field()->textarea(
            $this->plugin->txt('config_default_disclaimer'),
            $this->plugin->txt('config_default_disclaimer_info')
        )->withMaxLimit(4000)->withValue($disclaimer ?: '');

        return $inputs;
    }

    /**
     * Processing limits, streaming, message limit and session cleanup
     */
    private function buildProcessingLimitsInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $inputs = [];

        $char_limit = \platform\AIChatPageComponentConfig::get('characters_limit');
        $inputs['default_char_limit'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_default_char_limit'),
            $this->plugin->txt('config_default_char_limit_info')
        )->withValue($char_limit ? (int) $char_limit : 2000);

        $max_memory = \platform\AIChatPageComponentConfig::get('max_memory_messages');
        $inputs['default_max_memory'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_default_max_memory'),
            $this->plugin->txt('config_default_max_memory_info')
        )->withValue($max_memory ? (int) $max_memory : 10);

        $pdf_pages_processed = \platform\AIChatPageComponentConfig::get('pdf_pages_processed');
        $inputs['pdf_pages_processed'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_pdf_pages_processed'),
            $this->plugin->txt('config_pdf_pages_processed_info')
        )->withValue($pdf_pages_processed ? (int) $pdf_pages_processed : 20);

        $max_image_data = \platform\AIChatPageComponentConfig::get('max_image_data_mb');
        $inputs['max_image_data_mb'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_max_image_data'),
            $this->plugin->txt('config_max_image_data_info')
        )->withValue($max_image_data ? (int) $max_image_data : 15);

        $streaming_enabled = \platform\AIChatPageComponentConfig::get('enable_streaming');
        $inputs['enable_streaming'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_enable_streaming'),
            $this->plugin->txt('config_enable_streaming_info')
        )->withValue(($streaming_enabled ?? '1') === '1');

        // 0 = unlimited
        $max_msg_day = \platform\AIChatPageComponentConfig::get('max_messages_per_day');
        $inputs['max_messages_per_day'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_max_messages_per_day'),
            $this->plugin->txt('config_max_messages_per_day_info')
        )->withValue($max_msg_day !== null ? (int) $max_msg_day : 50);

        // 0 = disabled
        $cleanup_days = \platform\AIChatPageComponentConfig::get('session_cleanup_days');
        $inputs['session_cleanup_days'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_session_cleanup_days'),
            $this->plugin->txt('config_session_cleanup_days_info')
        )->withValue($cleanup_days !== null ? (int) $cleanup_days : 90);

        return $inputs;
    }

    private function buildAiServiceSelectionInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $inputs = [];

        $service_options = \ai\AIChatPageComponentLLMRegistry::getServiceOptions(true);

        if (empty($service_options)) {
            $service_options['none'] = 'No AI services enabled - Please enable at least one service';
        }

        $selected_service = \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';

        // The stored service may have been disabled in the meantime
        if (!isset($service_options[$selected_service])) {
            $selected_service = array_key_first($service_options);
        }

        $force_default_service = \platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0';

        $inputs['selected_ai_service'] = $ui_factory->input()->field()->select(
            $this->plugin->txt('config_selected_ai_service'),
            $service_options,
            $this->plugin->txt('config_selected_ai_service_info')
        )->withValue($selected_service);

        $inputs['force_default_ai_service'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_force_default_ai_service'),
            $this->plugin->txt('config_force_default_ai_service_info')
        )->withValue($force_default_service === '1');

        return $inputs;
    }

    private function buildUploadConstraintsInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $inputs = [];

        $max_file_size = \ILIAS\Plugin\pcaic\Validation\FileUploadValidator::getMaxFileSizeMb();
        $max_file_size_info = $this->plugin->txt('config_max_file_size_info');

        // PHP rejects larger uploads before the plugin sees them
        $php_limit = \ILIAS\Plugin\pcaic\Validation\FileUploadValidator::getPhpUploadLimitBytes();
        if ($php_limit !== null) {
            $php_limit_mb = round($php_limit / 1024 / 1024, 1);
            $max_file_size_info .= ' ' . sprintf($this->plugin->txt('config_max_file_size_php_limit'), $php_limit_mb);
            if (\ILIAS\Plugin\pcaic\Validation\FileUploadValidator::exceedsPhpUploadLimit($max_file_size, $php_limit)) {
                $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                    'info',
                    sprintf($this->plugin->txt('config_max_file_size_php_warning'), $max_file_size, $php_limit_mb)
                );
            }
        }

        $inputs['max_file_size_mb'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_max_file_size'),
            $max_file_size_info
        )->withValue($max_file_size);

        $max_attachments = \platform\AIChatPageComponentConfig::get('max_attachments_per_message');
        $inputs['max_attachments_per_message'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_max_attachments'),
            $this->plugin->txt('config_max_attachments_info')
        )->withValue($max_attachments ? (int) $max_attachments : 5);

        $max_upload_size = \platform\AIChatPageComponentConfig::get('max_total_upload_size_mb');
        $inputs['max_total_upload_size_mb'] = $ui_factory->input()->field()->numeric(
            $this->plugin->txt('config_max_upload_size'),
            $this->plugin->txt('config_max_upload_size_info')
        )->withValue($max_upload_size ? (int) $max_upload_size : 25);

        return $inputs;
    }

    private function buildAnonymousAccessInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $inputs = [];

        $allow_anonymous = \platform\AIChatPageComponentConfig::get('allow_anonymous_access');
        $inputs['allow_anonymous_access'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_allow_anonymous_access'),
            $this->plugin->txt('config_allow_anonymous_access_info')
        )->withValue(($allow_anonymous ?? '0') === '1');

        return $inputs;
    }

    /**
     * Global file handling switch with allowed file types and separate switches for
     * background files and chat uploads; file handling per service is set in the service tabs
     */
    private function buildFileUploadRestrictionsInputs(): array
    {
        $ui_factory = $this->dic->ui()->factory();
        $refinery = $this->dic->refinery();

        $enable_file_handling = \platform\AIChatPageComponentConfig::get('enable_file_handling') ?? '1';
        $file_restrictions = \platform\AIChatPageComponentConfig::get('file_upload_restrictions') ?? [];

        $default_file_types = \platform\AIChatPageComponentConfig::get('default_allowed_file_types');
        $default_types_string = is_array($default_file_types)
            ? implode(',', $default_file_types)
            : 'txt,md,pdf,csv,png,jpg,jpeg,webp,gif';

        $current_types = $file_restrictions['allowed_file_types'] ?? $default_file_types;
        $current_types_string = is_array($current_types) ? implode(',', $current_types) : $default_types_string;

        $sub_inputs = [];

        $sub_inputs['allowed_file_types'] = $ui_factory->input()->field()->text(
            $this->plugin->txt('config_allowed_file_types'),
            $this->plugin->txt('config_allowed_file_types_info')
        )->withMaxLength(500)->withValue($current_types_string);

        $sub_inputs['allow_background_files'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_allow_background_files'),
            $this->plugin->txt('config_allow_background_files_info')
        )->withValue(true);

        $sub_inputs['allow_chat_uploads'] = $ui_factory->input()->field()->checkbox(
            $this->plugin->txt('config_allow_chat_uploads'),
            $this->plugin->txt('config_allow_chat_uploads_info')
        )->withValue(true);

        $restrictions_trafo = $refinery->custom()->transformation(
            static function (?array $vs): array {
                if ($vs === null) {
                    return ['enabled' => false];
                }

                $restrictions = ['enabled' => true];

                if (isset($vs['allowed_file_types']) && !empty($vs['allowed_file_types'])) {
                    $allowed_types = array_map('trim', explode(',', $vs['allowed_file_types']));
                    $restrictions['allowed_file_types'] = array_filter($allowed_types);
                }

                if (isset($vs['allow_background_files'])) {
                    $restrictions['allow_background_files'] = $vs['allow_background_files'];
                }

                if (isset($vs['allow_chat_uploads'])) {
                    $restrictions['allow_chat_uploads'] = $vs['allow_chat_uploads'];
                }

                return $restrictions;
            }
        );

        $file_restrictions_group = $ui_factory->input()->field()->optionalGroup(
            $sub_inputs,
            $this->plugin->txt('config_enable_file_handling'),
            $this->plugin->txt('config_enable_file_handling_info')
        );

        if ($enable_file_handling === '1') {
            $current_values = [
                'allowed_file_types' => $current_types_string,
                'allow_background_files' => $file_restrictions['allow_background_files'] ?? true,
                'allow_chat_uploads' => $file_restrictions['allow_chat_uploads'] ?? true
            ];
            $file_restrictions_group = $file_restrictions_group->withValue($current_values);
        } else {
            $file_restrictions_group = $file_restrictions_group->withValue(null);
        }

        $file_restrictions_group = $file_restrictions_group->withAdditionalTransformation($restrictions_trafo);

        return ['file_restrictions' => $file_restrictions_group];
    }

    public function saveConfiguration(): void
    {
        $ui_factory = $this->dic->ui()->factory();
        $request = $this->dic->http()->request();

        try {
            // Same structure as buildGeneralConfigurationForm(), so that the request can be mapped
            $inputs = [];

            $inputs[] = $ui_factory->input()->field()->section(
                $this->buildDefaultValuesInputs(),
                $this->plugin->txt('config_defaults_title'),
                $this->plugin->txt('config_defaults_info')
            );

            $inputs[] = $ui_factory->input()->field()->section(
                $this->buildProcessingLimitsInputs(),
                $this->plugin->txt('config_processing_title'),
                $this->plugin->txt('config_processing_info')
            );

            $inputs[] = $ui_factory->input()->field()->section(
                $this->buildAiServiceSelectionInputs(),
                $this->plugin->txt('config_services_title'),
                $this->plugin->txt('config_services_info')
            );

            $inputs[] = $ui_factory->input()->field()->section(
                $this->buildUploadConstraintsInputs(),
                $this->plugin->txt('config_upload_title'),
                $this->plugin->txt('config_upload_info')
            );

            $inputs[] = $ui_factory->input()->field()->section(
                $this->buildFileUploadRestrictionsInputs(),
                $this->plugin->txt('config_file_restrictions_title'),
                $this->plugin->txt('config_file_restrictions_info')
            );

            $inputs[] = $ui_factory->input()->field()->section(
                $this->buildAnonymousAccessInputs(),
                $this->plugin->txt('config_anonymous_access_title'),
                $this->plugin->txt('config_anonymous_access_info')
            );

            $form = $ui_factory->input()->container()->form()->standard(
                $this->ctrl->getFormAction($this, 'saveConfiguration'),
                $inputs
            );

            $form = $form->withRequest($request);
            $data = $form->getData();

            if ($data !== null) {
                // Section order as in buildGeneralConfigurationForm()
                $defaults_data = $data[0] ?? [];
                $processing_data = $data[1] ?? [];
                $services_data = $data[2] ?? [];
                $constraints_data = $data[3] ?? [];
                $restrictions_data = $data[4] ?? [];
                $anonymous_data = $data[5] ?? [];

                if (isset($defaults_data['default_prompt'])) {
                    \platform\AIChatPageComponentConfig::set('default_prompt', $defaults_data['default_prompt']);
                }
                if (isset($defaults_data['default_disclaimer'])) {
                    \platform\AIChatPageComponentConfig::set('default_disclaimer', $defaults_data['default_disclaimer']);
                }

                if (isset($processing_data['default_char_limit'])) {
                    \platform\AIChatPageComponentConfig::set('characters_limit', (int) $processing_data['default_char_limit']);
                }
                if (isset($processing_data['default_max_memory'])) {
                    \platform\AIChatPageComponentConfig::set('max_memory_messages', (int) $processing_data['default_max_memory']);
                }
                if (isset($processing_data['pdf_pages_processed'])) {
                    \platform\AIChatPageComponentConfig::set('pdf_pages_processed', (int) $processing_data['pdf_pages_processed']);
                }
                if (isset($processing_data['max_image_data_mb'])) {
                    \platform\AIChatPageComponentConfig::set('max_image_data_mb', (int) $processing_data['max_image_data_mb']);
                }
                if (isset($processing_data['enable_streaming'])) {
                    \platform\AIChatPageComponentConfig::set('enable_streaming', $processing_data['enable_streaming'] ? '1' : '0');
                }
                if (isset($processing_data['max_messages_per_day'])) {
                    \platform\AIChatPageComponentConfig::set('max_messages_per_day', (int) $processing_data['max_messages_per_day']);
                }
                if (isset($processing_data['session_cleanup_days'])) {
                    \platform\AIChatPageComponentConfig::set('session_cleanup_days', (int) $processing_data['session_cleanup_days']);
                }

                \platform\AIChatPageComponentConfig::set('selected_ai_service', $services_data['selected_ai_service'] ?? 'ramses');
                \platform\AIChatPageComponentConfig::set('force_default_ai_service', ($services_data['force_default_ai_service'] ?? false) ? '1' : '0');

                if (isset($constraints_data['max_file_size_mb'])) {
                    \platform\AIChatPageComponentConfig::set('max_file_size_mb', (int) $constraints_data['max_file_size_mb']);
                }
                if (isset($constraints_data['max_attachments_per_message'])) {
                    \platform\AIChatPageComponentConfig::set('max_attachments_per_message', (int) $constraints_data['max_attachments_per_message']);
                }
                if (isset($constraints_data['max_total_upload_size_mb'])) {
                    \platform\AIChatPageComponentConfig::set('max_total_upload_size_mb', (int) $constraints_data['max_total_upload_size_mb']);
                }

                // The transformation of the optional group returns ['enabled' => bool, ...]
                $file_restrictions_value = $restrictions_data['file_restrictions'] ?? ['enabled' => false];

                $is_enabled = is_array($file_restrictions_value) && ($file_restrictions_value['enabled'] ?? false);

                if ($is_enabled) {
                    \platform\AIChatPageComponentConfig::set('enable_file_handling', '1');
                    \platform\AIChatPageComponentConfig::set('file_upload_restrictions', $file_restrictions_value);
                } else {
                    \platform\AIChatPageComponentConfig::set('enable_file_handling', '0');
                    \platform\AIChatPageComponentConfig::set('file_upload_restrictions', ['enabled' => false]);
                }

                \platform\AIChatPageComponentConfig::set(
                    'allow_anonymous_access',
                    ($anonymous_data['allow_anonymous_access'] ?? false) ? '1' : '0'
                );

                $this->dic->ui()->mainTemplate()->setOnScreenMessage('success', $this->plugin->txt('config_saved_success'));

            } else {
                $this->dic->ui()->mainTemplate()->setOnScreenMessage('failure', $this->plugin->txt('config_form_invalid'));
            }

        } catch (\Exception $e) {
            $this->dic->ui()->mainTemplate()->setOnScreenMessage('failure', $this->plugin->txt('config_save_error') . ': ' . $e->getMessage());
        }

        $this->showConfigurationForm('general');
    }

    /**
     * Add configuration tabs: General, one per AI service, RAG, Statistics
     */
    private function addConfigTabs(string $active_tab): void
    {
        $tabs = $this->dic->tabs();
        $tabs->addTab(
            'general',
            $this->plugin->txt('tab_general_config'),
            $this->ctrl->getLinkTarget($this, 'showConfigurationForm')
        );

        $services = \ai\AIChatPageComponentLLMRegistry::getAvailableServices();
        foreach ($services as $service_id => $service_class) {
            $service_id_cap = ucfirst($service_id);
            $tabs->addTab(
                $service_id,
                $service_class::getDisplayName(),
                $this->ctrl->getLinkTargetByClass(get_class($this), "show{$service_id_cap}Config")
            );
        }

        $tabs->addTab(
            'rag',
            $this->plugin->txt('tab_rag_config'),
            $this->ctrl->getLinkTarget($this, 'showRagConfig')
        );

        $tabs->addTab(
            'statistics',
            $this->plugin->txt('tab_statistics'),
            $this->ctrl->getLinkTarget($this, 'showStatistics')
        );

        $tabs->setTabActive($active_tab);
    }

    private function buildRagConfigForm(): \ILIAS\UI\Component\Input\Container\Form\Standard
    {
        $ui_factory = $this->dic->ui()->factory();

        $section = $ui_factory->input()->field()->section(
            \ai\AIChatPageComponentRAG::getConfigurationFormInputs(),
            $this->plugin->txt('config_rag_title'),
            $this->plugin->txt('config_rag_info')
        );

        return $ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveRagConfiguration'),
            [$section]
        );
    }

    private function buildRagConfigurationForm(): string
    {
        return $this->dic->ui()->renderer()->render($this->buildRagConfigForm());
    }

    /**
     * Save the RAG configuration
     *
     * If URL or client key change, the stored RAG references are reset, because the
     * collections belong to the previous RAG or tenant. Files are uploaded again on next use.
     */
    private function saveRagConfiguration(): void
    {
        try {
            $form = $this->buildRagConfigForm()->withRequest($this->dic->http()->request());
            $form_data = $form->getData();

            if ($form_data !== null && is_array($form_data) && count($form_data) > 0) {
                $old_url = \ai\AIChatPageComponentRAG::getApiUrl();
                $old_key = \ai\AIChatPageComponentRAG::getClientKey();

                \ai\AIChatPageComponentRAG::saveConfiguration(reset($form_data));

                $target_changed = $old_url !== \ai\AIChatPageComponentRAG::getApiUrl()
                    || $old_key !== \ai\AIChatPageComponentRAG::getClientKey();

                if ($target_changed) {
                    $reset_count = $this->resetRagReferences();
                    $this->dic->logger()->pcaic()->info("RAG target changed, reset RAG references", [
                        'attachments' => $reset_count
                    ]);
                }

                $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                    'success',
                    $this->plugin->txt('config_saved_success')
                    . ($target_changed ? ' ' . $this->plugin->txt('config_rag_references_reset') : '')
                );
            } else {
                $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                    'failure',
                    $this->plugin->txt('config_form_invalid')
                );
            }
        } catch (\Exception $e) {
            $this->dic->logger()->pcaic()->error("Failed to save RAG configuration", [
                'error' => $e->getMessage()
            ]);
            $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                'failure',
                $this->plugin->txt('config_save_error') . ': ' . $e->getMessage()
            );
        }

        $this->showConfigurationForm('rag');
    }

    /**
     * @return int Number of attachments whose RAG references were reset
     */
    private function resetRagReferences(): int
    {
        $db = $this->dic->database();

        $affected = $db->manipulate(
            "UPDATE pcaic_attachments SET rag_collection_id = NULL, rag_remote_file_id = NULL, rag_uploaded_at = NULL " .
            "WHERE rag_collection_id IS NOT NULL OR rag_remote_file_id IS NOT NULL"
        );

        if ($db->tableColumnExists('pcaic_chats', 'rag_collection_id')) {
            $db->manipulate("UPDATE pcaic_chats SET rag_collection_id = NULL WHERE rag_collection_id IS NOT NULL");
        }

        return (int) $affected;
    }

    private function buildServiceConfigurationForm(string $service_id): string
    {
        $ui_factory = $this->dic->ui()->factory();
        $renderer = $this->dic->ui()->renderer();

        $service_class = \ai\AIChatPageComponentLLMRegistry::getServiceClass($service_id);
        if ($service_class === null) {
            return $renderer->render(
                $ui_factory->messageBox()->failure("Service '$service_id' not found in registry")
            );
        }

        $service = \ai\AIChatPageComponentLLMRegistry::createBareServiceInstance($service_id);
        if ($service === null) {
            return $renderer->render(
                $ui_factory->messageBox()->failure("Failed to create service instance for '$service_id'")
            );
        }

        $service_inputs = $service->getConfigurationFormInputs();

        $section = $ui_factory->input()->field()->section(
            $service_inputs,
            $service_class::getDisplayName(),
            $this->getServiceSectionDescription($service_class)
        );

        $service_id_cap = ucfirst($service_id);
        $form_action = $this->ctrl->getFormAction($this, "save{$service_id_cap}Configuration");
        $form = $ui_factory->input()->container()->form()->standard($form_action, [$section]);

        $form_html = $renderer->render($form);

        $button_html = $this->buildRefreshModelsButton($service_id);

        return $form_html . $button_html;
    }

    /**
     * Built-in description of a service; omitted if the administrator renamed the
     * service, because it names the provider the service was made for
     *
     * @param class-string<\ai\AIChatPageComponentLLM> $service_class
     */
    private function getServiceSectionDescription(string $service_class): ?string
    {
        return $service_class::getDisplayName() === $service_class::getServiceName()
            ? $service_class::getServiceDescription()
            : null;
    }

    /**
     * Button to reload the model list, with the time of the last update
     */
    private function buildRefreshModelsButton(string $service_id): string
    {
        if (!in_array($service_id, ['ramses', 'openai'])) {
            return '';
        }

        $ui_factory = $this->dic->ui()->factory();
        $renderer = $this->dic->ui()->renderer();

        $cache_time_key = ($service_id === 'ramses') ? 'models_cache_time' : 'openai_models_cache_time';
        $cached_models_key = ($service_id === 'ramses') ? 'cached_models' : 'openai_cached_models';

        $models_cache_time = \platform\AIChatPageComponentConfig::get($cache_time_key);
        $cached_models = \platform\AIChatPageComponentConfig::get($cached_models_key);

        $button_text = $this->plugin->txt('config_refresh_models_button');
        $info_text = '';

        if ($models_cache_time) {
            $timestamp = is_string($models_cache_time) ? (int) $models_cache_time : $models_cache_time;
            $last_update = date('d.m.Y H:i', $timestamp);
            $model_count = is_array($cached_models) ? count($cached_models) : 0;
            $info_text = '<p><small>Zuletzt aktualisiert: ' . $last_update . ' (' . $model_count . ' ' . $this->plugin->txt('refresh_models_count') . ')</small></p>';
        } else {
            $info_text = '<p><small>' . $this->plugin->txt('refresh_models_not_loaded') . '</small></p>';
        }

        $service_id_cap = ucfirst($service_id);
        $refresh_button = $ui_factory->button()->standard(
            $button_text,
            $this->ctrl->getLinkTarget($this, "refresh{$service_id_cap}Models")
        );

        $button_html = $renderer->render($refresh_button);

        return '<div style="margin-top: 20px;">' . $info_text . $button_html . '</div>';
    }

    private function saveServiceConfiguration(string $service_id): void
    {
        $ui_factory = $this->dic->ui()->factory();
        $request = $this->dic->http()->request();

        try {
            $service_class = \ai\AIChatPageComponentLLMRegistry::getServiceClass($service_id);
            if ($service_class === null) {
                throw new \Exception("Service '$service_id' not found in registry");
            }

            $service = \ai\AIChatPageComponentLLMRegistry::createBareServiceInstance($service_id);
            if ($service === null) {
                throw new \Exception("Failed to create service instance for '$service_id'");
            }

            $service_inputs = $service->getConfigurationFormInputs();

            $section = $ui_factory->input()->field()->section(
                $service_inputs,
                $service_class::getDisplayName(),
                $this->getServiceSectionDescription($service_class)
            );

            $service_id_cap = ucfirst($service_id);
            $form_action = $this->ctrl->getFormAction($this, "save{$service_id_cap}Configuration");
            $form = $ui_factory->input()->container()->form()->standard($form_action, [$section])
                ->withRequest($request);

            $form_data = $form->getData();

            if ($form_data !== null && is_array($form_data) && count($form_data) > 0) {
                $section_data = reset($form_data);

                $service->saveConfiguration($section_data);

                $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                    'success',
                    $this->plugin->txt('config_saved_success')
                );
            } else {
                $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                    'failure',
                    $this->plugin->txt('config_form_invalid')
                );
            }

        } catch (\Exception $e) {
            $this->dic->logger()->pcaic()->error("Failed to save {$service_id} configuration", [
                'error' => $e->getMessage()
            ]);
            $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                'failure',
                $this->plugin->txt('config_save_error') . ': ' . $e->getMessage()
            );
        }

        $this->showConfigurationForm($service_id);
    }

    private function refreshServiceModels(string $service_id): void
    {
        try {
            $service = \ai\AIChatPageComponentLLMRegistry::createBareServiceInstance($service_id);

            if ($service === null) {
                $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                    'failure',
                    "Unknown service: {$service_id}"
                );
                $this->showConfigurationForm($service_id);
                return;
            }

            $result = $service->refreshModels();

            if ($result['success']) {
                $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                    'success',
                    $result['message']
                );
            } else {
                $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                    'failure',
                    $result['message']
                );
            }
        } catch (\Exception $e) {
            $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                'failure',
                $this->plugin->txt('refresh_models_exception') . ': ' . $e->getMessage()
            );
        }

        $this->showConfigurationForm($service_id);
    }

    private function showStatistics(): void
    {
        $this->addConfigTabs('statistics');

        require_once __DIR__ . '/Statistics/class.AIChatStatisticsDataRetrieval.php';
        require_once __DIR__ . '/Statistics/class.AIChatStatisticsTableGUI.php';

        $filter_service = $this->dic->uiService()->filter();
        $ui_factory = $this->dic->ui()->factory();
        $filter_fields = [
            'title' => $ui_factory->input()->field()->text($this->plugin->txt('stat_title')),
            'obj_id' => $ui_factory->input()->field()->numeric($this->plugin->txt('stat_obj_id')),
            'ref_id' => $ui_factory->input()->field()->numeric($this->plugin->txt('stat_ref_id')),
        ];
        $filter = $filter_service->standard(
            'pcaic_stat_filter',
            $this->ctrl->getLinkTarget($this, 'showStatistics'),
            $filter_fields,
            array_fill(0, count($filter_fields), true),
            true,
            false
        );
        $filter_data = $filter_service->getData($filter) ?? [];

        $table = new AIChatStatisticsTableGUI($this);

        $cleanup_days = (int) (\platform\AIChatPageComponentConfig::get('session_cleanup_days') ?? 90);
        $cleanup_button_html = '';
        if ($cleanup_days > 0) {
            $cleanup_btn = $ui_factory->button()->standard(
                $this->plugin->txt('stat_run_cleanup'),
                $this->ctrl->getLinkTarget($this, 'runSessionCleanup')
            );
            $cleanup_button_html = '<div style="margin-bottom:16px">'
                . $this->dic->ui()->renderer()->render($cleanup_btn)
                . '</div>';
        }

        $this->dic->ui()->mainTemplate()->setContent(
            $cleanup_button_html
            . $this->dic->ui()->renderer()->render($filter)
            . $table->getHTML($filter_data ?: null)
        );
    }

    /**
     * Redirect to the page containing the chat
     */
    private function gotoPage(): void
    {
        $query = $this->dic->http()->wrapper()->query();
        $refinery = $this->dic->refinery();

        $chat_ids = $query->has('pcaic_stat_chat_id')
            ? $query->retrieve('pcaic_stat_chat_id', $refinery->kindlyTo()->listOf($refinery->kindlyTo()->string()))
            : [];
        $chat_id = $chat_ids[0] ?? '';

        if ($chat_id === '') {
            $this->showStatistics();
            return;
        }

        $db = $this->dic->database();
        $result = $db->query(
            "SELECT parent_id, parent_type FROM pcaic_chats WHERE chat_id = " . $db->quote($chat_id, 'text')
        );
        $row = $db->fetchAssoc($result);

        if (!$row || (int) $row['parent_id'] === 0) {
            $this->showStatistics();
            return;
        }

        $parent_id = (int) $row['parent_id'];
        $parent_type = (string) $row['parent_type'];

        // parent_id is an obj_id; for container pages parent_type is "cont", so the
        // repository type (crs, grp, ...) is looked up for the link
        $repo_type = ilObject::_lookupType($parent_id);
        if (empty($repo_type)) {
            $repo_type = $parent_type;
        }

        $refs = ilObject::_getAllReferences($parent_id);
        if (empty($refs)) {
            $refs = [$parent_id]; // Some page types store the ref_id
        }

        $ref_id = (int) reset($refs);
        $link = ilLink::_getStaticLink($ref_id, $repo_type);

        // Plain redirect: ilUtil::redirect() stays in the administration context and
        // its permission check blocks the navigation
        header('Location: ' . $link, true, 302);
        exit;
    }

    /**
     * chat_id from the parameter written by the table URL builder
     */
    private function getChatIdFromRequest(): string
    {
        $query = $this->dic->http()->wrapper()->query();
        $refinery = $this->dic->refinery();

        $ids = $query->has('pcaic_stat_chat_id')
            ? $query->retrieve('pcaic_stat_chat_id', $refinery->kindlyTo()->listOf($refinery->kindlyTo()->string()))
            : [];

        return $ids[0] ?? '';
    }

    private function setOnlineStatus(bool $online): void
    {
        $chat_id = $this->getChatIdFromRequest();

        if ($chat_id === '') {
            $this->showStatistics();
            return;
        }

        $chat_config = new \ILIAS\Plugin\pcaic\Model\ChatConfig($chat_id);

        if (!$chat_config->exists()) {
            $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                'failure',
                $this->plugin->txt('stat_chat_not_found')
            );
            $this->showStatistics();
            return;
        }

        $chat_config->setIsOnline($online);
        $chat_config->save();

        $this->dic->ui()->mainTemplate()->setOnScreenMessage(
            'success',
            $this->plugin->txt($online ? 'stat_toggled_online' : 'stat_toggled_offline')
        );

        $this->showStatistics();
    }

    /**
     * Delete all sessions and messages of a chat; the configuration is kept
     */
    private function clearChatHistory(): void
    {
        $chat_id = $this->getChatIdFromRequest();

        if ($chat_id === '') {
            $this->showStatistics();
            return;
        }

        try {
            $this->plugin->clearChatHistory($chat_id);
            $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                'success',
                $this->plugin->txt('stat_history_cleared')
            );
        } catch (\Exception $e) {
            $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                'failure',
                $e->getMessage()
            );
        }

        $this->showStatistics();
    }

    private function runSessionCleanup(): void
    {
        $cleanup_days = (int) (\platform\AIChatPageComponentConfig::get('session_cleanup_days') ?? 90);

        if ($cleanup_days <= 0) {
            $this->showStatistics();
            return;
        }

        try {
            $stats = $this->plugin->cleanupInactiveSessions($cleanup_days);

            if ($stats['sessions'] > 0) {
                $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                    'success',
                    sprintf($this->plugin->txt('stat_cleanup_done'), $stats['sessions'], $stats['messages'])
                );
            } else {
                $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                    'info',
                    $this->plugin->txt('stat_cleanup_nothing')
                );
            }
        } catch (\Exception $e) {
            $this->dic->ui()->mainTemplate()->setOnScreenMessage('failure', $e->getMessage());
        }

        $this->showStatistics();
    }

    private function deleteChat(): void
    {
        $chat_id = $this->getChatIdFromRequest();

        if ($chat_id === '') {
            $this->showStatistics();
            return;
        }

        try {
            $this->plugin->deleteCompleteChat($chat_id);
            $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                'success',
                $this->plugin->txt('stat_chat_deleted')
            );
        } catch (\Exception $e) {
            $this->dic->ui()->mainTemplate()->setOnScreenMessage(
                'failure',
                $e->getMessage()
            );
        }

        $this->showStatistics();
    }
}
