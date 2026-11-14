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

use ai\AIChatPageComponentLLM;
use ai\AIChatPageComponentLLMRegistry;
use ai\AIChatPageComponentRAG;
use ai\AIChatPageComponentRAGStatus;
use ILIAS\Cron\Schedule\CronJobScheduleType;
use ILIAS\Plugin\pcaic\Model\ChatConfig;
use ILIAS\Plugin\pcaic\Model\ChatSession;

/**
 * Cron job that advances the processing of files in the RAG service
 *
 * Without this job the state of a file is only queried and a failed upload only
 * repeated when somebody uses the chat. The job queries the state of all files
 * that are still processing, uploads failed files again when their retry is due
 * and deletes files the RAG could not delete yet.
 */
class ilAIChatPageComponentRAGCronJob extends ilCronJob
{
    public const JOB_ID = 'pcaic_rag_ingestion';

    /** @var int No further chat is started after this number of seconds, a run should not overlap the next one */
    private const TIME_BUDGET = 120;

    private ilAIChatPageComponentPlugin $plugin;

    private int $started_at = 0;

    public function __construct(ilAIChatPageComponentPlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function getId(): string
    {
        return self::JOB_ID;
    }

    public function getTitle(): string
    {
        return $this->plugin->txt('cron_rag_title');
    }

    public function getDescription(): string
    {
        return $this->plugin->txt('cron_rag_description');
    }

    public function hasAutoActivation(): bool
    {
        return true;
    }

    public function hasFlexibleSchedule(): bool
    {
        return true;
    }

    public function getDefaultScheduleType(): CronJobScheduleType
    {
        return CronJobScheduleType::SCHEDULE_TYPE_IN_MINUTES;
    }

    public function getDefaultScheduleValue(): ?int
    {
        return 1;
    }

    public function run(): ilCronJobResult
    {
        global $DIC;
        $db = $DIC->database();
        $logger = $DIC->logger()->pcaic();
        $result = new ilCronJobResult();

        if (!AIChatPageComponentRAG::isAvailable()) {
            $result->setStatus(ilCronJobResult::STATUS_NO_ACTION);
            return $result;
        }

        $this->started_at = time();
        $checked = 0;
        $uploaded = 0;
        $errors = 0;

        // State of the files that are still processing; refreshChat() limits the frequency per chat
        $chat_ids = $this->fetchColumn(
            "SELECT DISTINCT chat_id FROM pcaic_attachments WHERE rag_status = "
            . $db->quote(AIChatPageComponentRAGStatus::PROCESSING, 'text') . " AND rag_remote_file_id IS NOT NULL",
            'chat_id'
        );
        foreach ($chat_ids as $chat_id) {
            if ($this->isOutOfTime()) {
                break;
            }
            try {
                if (AIChatPageComponentRAGStatus::refreshChat($chat_id)) {
                    $checked++;
                }
            } catch (\Exception $e) {
                $errors++;
                $logger->warning("RAG status check of chat $chat_id failed: " . $e->getMessage());
            }
        }

        // Failed background files whose new upload is due
        $chat_ids = $this->fetchColumn(
            "SELECT DISTINCT chat_id FROM pcaic_attachments WHERE background_file = 1 AND "
            . AIChatPageComponentRAGStatus::retryDueCondition(),
            'chat_id'
        );
        foreach ($chat_ids as $chat_id) {
            if ($this->isOutOfTime()) {
                break;
            }
            try {
                $chat_config = new ChatConfig($chat_id);
                $llm = $this->getRagServiceForChat($chat_config);
                if ($llm !== null) {
                    $stats = $llm->syncBackgroundFilesToRAG($chat_config);
                    $uploaded += $stats['uploaded'];
                    $errors += $stats['errors'];
                }
            } catch (\Exception $e) {
                $errors++;
                $logger->warning("RAG upload of background files of chat $chat_id failed: " . $e->getMessage());
            }
        }

        // Failed chat uploads whose new upload is due
        $session_ids = $this->fetchColumn(
            "SELECT DISTINCT m.session_id FROM pcaic_attachments a"
            . " INNER JOIN pcaic_messages m ON m.message_id = a.message_id"
            . " WHERE a.background_file = 0 AND " . AIChatPageComponentRAGStatus::retryDueCondition(),
            'session_id'
        );
        foreach ($session_ids as $session_id) {
            if ($this->isOutOfTime()) {
                break;
            }
            try {
                $session = new ChatSession($session_id);
                if (!$session->exists()) {
                    continue;
                }
                $chat_config = new ChatConfig($session->getChatId());
                $llm = $this->getRagServiceForChat($chat_config);
                if ($llm !== null) {
                    // Same range of messages as before sending a message
                    $stats = $llm->syncChatAttachmentsToRAG($session, min($chat_config->getMaxMemory(), 20));
                    $uploaded += $stats['uploaded'];
                    $errors += $stats['errors'];
                }
            } catch (\Exception $e) {
                $errors++;
                $logger->warning("RAG upload of chat attachments of session $session_id failed: " . $e->getMessage());
            }
        }

        try {
            AIChatPageComponentRAGStatus::processDeletionQueue();
        } catch (\Exception $e) {
            $errors++;
            $logger->warning("Pending RAG deletions failed: " . $e->getMessage());
        }

        $result->setStatus($checked + $uploaded + $errors > 0 ? ilCronJobResult::STATUS_OK : ilCronJobResult::STATUS_NO_ACTION);
        $result->setMessage("Chats checked: $checked, files uploaded again: $uploaded, errors: $errors");
        return $result;
    }

    /**
     * AI service of the chat if the chat uses the RAG, otherwise null
     */
    private function getRagServiceForChat(ChatConfig $chat_config): ?AIChatPageComponentLLM
    {
        if (!$chat_config->exists()) {
            return null;
        }

        // As in api.php: the default service is used if the administrator enforces it
        $service = $chat_config->getAiService();
        if ((\platform\AIChatPageComponentConfig::get('force_default_ai_service') ?: '0') === '1') {
            $service = \platform\AIChatPageComponentConfig::get('selected_ai_service') ?: 'ramses';
        }
        if (!AIChatPageComponentLLMRegistry::isServiceEnabled((string) $service)) {
            return null;
        }

        $llm = AIChatPageComponentLLMRegistry::createServiceInstance((string) $service);
        return $llm !== null && $llm->isRagEnabledForChat($chat_config) ? $llm : null;
    }

    /**
     * @return string[] Values of the column, read completely before further queries are sent
     */
    private function fetchColumn(string $query, string $column): array
    {
        global $DIC;
        $db = $DIC->database();

        $values = [];
        $result = $db->query($query);
        while ($row = $db->fetchAssoc($result)) {
            $values[] = (string) $row[$column];
        }
        return $values;
    }

    private function isOutOfTime(): bool
    {
        return time() - $this->started_at > self::TIME_BUDGET;
    }
}
