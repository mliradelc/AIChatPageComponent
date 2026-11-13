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

/**
 * Processing state of chat files in the RAG service
 *
 * The RAG processes uploaded files asynchronously. The state is stored per
 * attachment and queried from the RAG by chat requests; the interval between two
 * queries of a chat grows with the time since the last upload. Whether a chat has unprocessed files is decided from the database
 * only. Files whose upload or processing failed are uploaded again with an
 * increasing delay, unless the RAG rejected them. Files the RAG could not delete
 * yet are deleted later.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class AIChatPageComponentRAGStatus
{
    /** Local states in pcaic_attachments.rag_status */
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    /** @var int Longest interval between two status checks of a chat, in seconds */
    public const CHECK_INTERVAL = 60;

    /** @var int Delay before the first new upload after a failure, in seconds */
    public const RETRY_BASE_DELAY = 30;

    /** @var int Maximum delay between new uploads, in seconds */
    public const RETRY_MAX_DELAY = 300;

    /** @var int A file the RAG fails to process is given up after this number of attempts in a row */
    public const MAX_PROCESSING_ATTEMPTS = 5;

    /** @var int Delay before the first new attempt to delete a file, in seconds */
    public const DELETION_RETRY_BASE_DELAY = 300;

    /** @var int Maximum delay between attempts to delete a file, in seconds */
    public const DELETION_RETRY_MAX_DELAY = 21600;

    /** @var int Pending deletions are given up after this number of attempts */
    public const MAX_DELETION_ATTEMPTS = 100;

    /** @var int Pending deletions processed per run */
    private const DELETIONS_PER_RUN = 10;

    private const DELETION_CHECK_KEY = 'rag_deletions_checked_at';

    /**
     * Delay before the next attempt after $failed_count failures in a row
     */
    public static function retryDelay(
        int $failed_count,
        int $base_delay = self::RETRY_BASE_DELAY,
        int $max_delay = self::RETRY_MAX_DELAY
    ): int {
        $exponent = max(0, min($failed_count - 1, 20));
        return (int) min($base_delay * (2 ** $exponent), $max_delay);
    }

    /**
     * Delay before the next upload, or null if the file is given up
     *
     * @param int $max_attempts Number of failures in a row after which no new upload follows; 0 = unlimited
     */
    public static function nextRetryDelay(int $failed_count, int $max_attempts = 0): ?int
    {
        if ($max_attempts > 0 && $failed_count >= $max_attempts) {
            return null;
        }
        return self::retryDelay($failed_count);
    }

    /**
     * Whether the RAG rejected an upload for a reason a new upload of the same file does not change
     *
     * Network errors (no HTTP status), server errors and rate limits pass; so do
     * authentication errors, which are caused by the configuration and not by the file.
     */
    public static function isPermanentUploadError(int $http_code): bool
    {
        return $http_code >= 400 && $http_code < 500
            && !in_array($http_code, [401, 403, 408, 425, 429], true);
    }

    /**
     * Interval between two status checks of a chat, in seconds
     *
     * Most files are processed quickly, so the state is queried often right after
     * an upload and less often the longer the processing takes.
     *
     * @param int|null $seconds_since_upload Age of the latest upload that is still processing, null if there is none
     */
    public static function checkInterval(?int $seconds_since_upload): int
    {
        if ($seconds_since_upload === null) {
            return self::CHECK_INTERVAL;
        }
        if ($seconds_since_upload < 60) {
            return 5;
        }
        if ($seconds_since_upload < 600) {
            return 30;
        }
        return self::CHECK_INTERVAL;
    }

    /**
     * Local state for a state reported by the RAG
     */
    public static function mapRemoteStatus(string $remote_status): string
    {
        switch (strtoupper($remote_status)) {
            case AIChatPageComponentRAG::STATUS_COMPLETED:
                return self::COMPLETED;
            case AIChatPageComponentRAG::STATUS_FAILED:
                return self::FAILED;
            default:
                return self::PROCESSING;
        }
    }

    public static function markUploaded(int $attachment_id): void
    {
        self::db()->manipulate(
            "UPDATE pcaic_attachments SET rag_status = " . self::db()->quote(self::PROCESSING, 'text')
            . ", rag_status_error = NULL, rag_retry_at = NULL WHERE id = " . self::db()->quote($attachment_id, 'integer')
        );
    }

    public static function markSkipped(int $attachment_id): void
    {
        self::db()->manipulate(
            "UPDATE pcaic_attachments SET rag_status = " . self::db()->quote(self::SKIPPED, 'text')
            . " WHERE id = " . self::db()->quote($attachment_id, 'integer')
        );
    }

    /**
     * Upload or processing failed: remove the RAG references and schedule a new upload
     *
     * @param int $max_attempts Number of failures in a row after which the file is given up; 0 = unlimited,
     *                          1 = no new upload (the RAG rejected the file)
     */
    public static function markFailed(int $attachment_id, string $error, int $max_attempts = 0): void
    {
        $db = self::db();
        $result = $db->query("SELECT rag_failed_count FROM pcaic_attachments WHERE id = " . $db->quote($attachment_id, 'integer'));
        $row = $db->fetchAssoc($result);
        $failed_count = (int) ($row['rag_failed_count'] ?? 0) + 1;
        $delay = self::nextRetryDelay($failed_count, $max_attempts);
        // Without a time of the next attempt the file is not uploaded again
        $retry_at = $delay === null ? null : date('Y-m-d H:i:s', time() + $delay);

        $db->manipulate(
            "UPDATE pcaic_attachments SET"
            . " rag_collection_id = NULL, rag_remote_file_id = NULL, rag_uploaded_at = NULL,"
            . " rag_status = " . $db->quote(self::FAILED, 'text') . ","
            . " rag_status_error = " . $db->quote(mb_substr($error, 0, 1000), 'text') . ","
            . " rag_failed_count = " . $db->quote($failed_count, 'integer') . ","
            . " rag_retry_at = " . $db->quote($retry_at, 'timestamp')
            . " WHERE id = " . $db->quote($attachment_id, 'integer')
        );
    }

    /**
     * SQL condition for attachments that may be uploaded to the RAG now
     */
    public static function uploadDueCondition(): string
    {
        return "(rag_status IS NULL OR " . self::retryDueCondition() . ")";
    }

    /**
     * SQL condition for failed attachments whose new upload is due
     */
    public static function retryDueCondition(): string
    {
        $db = self::db();
        return "(rag_status = " . $db->quote(self::FAILED, 'text')
            . " AND rag_retry_at IS NOT NULL AND rag_retry_at <= " . $db->quote(date('Y-m-d H:i:s'), 'timestamp') . ")";
    }

    /**
     * Whether background files of the chat are not (yet) usable in the RAG
     */
    public static function hasUnprocessedBackgroundFiles(string $chat_id): bool
    {
        $db = self::db();
        $result = $db->query(
            "SELECT COUNT(*) cnt FROM pcaic_attachments WHERE chat_id = " . $db->quote($chat_id, 'text')
            . " AND background_file = 1 AND (rag_status IS NULL OR rag_status IN ("
            . $db->quote(self::PROCESSING, 'text') . ", " . $db->quote(self::FAILED, 'text') . "))"
        );
        $row = $db->fetchAssoc($result);
        return (int) ($row['cnt'] ?? 0) > 0;
    }

    /**
     * Query the RAG for files of the chat that are still processing, not more often
     * than checkInterval() allows
     *
     * @return bool False if the check was skipped because of the interval
     */
    public static function refreshChat(string $chat_id): bool
    {
        $db = self::db();
        $latest = $db->fetchAssoc($db->query(
            "SELECT MAX(rag_uploaded_at) latest FROM pcaic_attachments WHERE chat_id = " . $db->quote($chat_id, 'text')
            . " AND rag_status = " . $db->quote(self::PROCESSING, 'text') . " AND rag_remote_file_id IS NOT NULL"
        ));
        $uploaded_at = empty($latest['latest']) ? false : strtotime((string) $latest['latest']);

        if (!self::claim(
            "UPDATE pcaic_chats SET rag_status_checked_at = %s WHERE chat_id = " . $db->quote($chat_id, 'text')
            . " AND (rag_status_checked_at IS NULL OR rag_status_checked_at <= %s)",
            self::checkInterval($uploaded_at === false ? null : max(0, time() - $uploaded_at))
        )) {
            return false;
        }

        $rag = new AIChatPageComponentRAG();
        $result = $db->query(
            "SELECT a.id, a.rag_remote_file_id, a.background_file, a.chat_id, m.session_id"
            . " FROM pcaic_attachments a LEFT JOIN pcaic_messages m ON m.message_id = a.message_id"
            . " WHERE a.chat_id = " . $db->quote($chat_id, 'text')
            . " AND a.rag_status = " . $db->quote(self::PROCESSING, 'text')
            . " AND a.rag_remote_file_id IS NOT NULL"
        );

        while ($row = $db->fetchAssoc($result)) {
            // Entity as used for the upload: chat for background files, session for chat uploads
            $entity_id = (int) $row['background_file'] === 1 ? (string) $row['chat_id'] : (string) $row['session_id'];
            $status = $rag->getFileStatus((string) $row['rag_remote_file_id'], $entity_id);
            if ($status === null) {
                continue;
            }

            switch (self::mapRemoteStatus($status['status'])) {
                case self::COMPLETED:
                    $db->manipulate(
                        "UPDATE pcaic_attachments SET rag_status = " . $db->quote(self::COMPLETED, 'text')
                        . ", rag_status_error = NULL, rag_failed_count = 0, rag_retry_at = NULL"
                        . " WHERE id = " . $db->quote((int) $row['id'], 'integer')
                    );
                    break;
                case self::FAILED:
                    self::logger()->warning("RAG processing of attachment " . $row['id'] . " failed: " . ($status['error'] ?? ''));
                    self::markFailed(
                        (int) $row['id'],
                        (string) ($status['error'] ?? 'Processing failed'),
                        self::MAX_PROCESSING_ATTEMPTS
                    );
                    break;
            }
        }

        self::processDeletionQueue();
        return true;
    }

    /**
     * Remember a file the RAG did not delete yet
     */
    public static function queueDeletion(string $remote_file_id, string $entity_id): void
    {
        $db = self::db();
        $result = $db->query("SELECT id FROM pcaic_rag_deletions WHERE remote_file_id = " . $db->quote($remote_file_id, 'text'));
        if ($db->fetchAssoc($result) !== null) {
            return;
        }

        $db->insert('pcaic_rag_deletions', [
            'id' => ['integer', $db->nextId('pcaic_rag_deletions')],
            'remote_file_id' => ['text', $remote_file_id],
            'entity_id' => ['text', $entity_id],
            'attempts' => ['integer', 0],
            'next_try' => ['timestamp', date('Y-m-d H:i:s', time() + self::CHECK_INTERVAL)],
            'created_at' => ['timestamp', date('Y-m-d H:i:s')],
        ]);
        self::logger()->info("RAG deletion of $remote_file_id postponed until processing has finished");
    }

    /**
     * Retry pending deletions, at most once per minute for the whole installation
     */
    public static function processDeletionQueue(): void
    {
        $db = self::db();
        $result = $db->query("SELECT config_key FROM pcaic_config WHERE config_key = " . $db->quote(self::DELETION_CHECK_KEY, 'text'));
        if ($db->fetchAssoc($result) === null) {
            \platform\AIChatPageComponentConfig::set(self::DELETION_CHECK_KEY, '1970-01-01 00:00:00');
        }

        if (!self::claim(
            "UPDATE pcaic_config SET config_value = %s WHERE config_key = " . $db->quote(self::DELETION_CHECK_KEY, 'text')
            . " AND config_value <= %s"
        )) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $db->setLimit(self::DELETIONS_PER_RUN, 0);
        $result = $db->query(
            "SELECT id, remote_file_id, entity_id, attempts FROM pcaic_rag_deletions"
            . " WHERE next_try IS NULL OR next_try <= " . $db->quote($now, 'timestamp') . " ORDER BY next_try"
        );

        $rag = new AIChatPageComponentRAG();
        while ($row = $db->fetchAssoc($result)) {
            $attempts = (int) $row['attempts'] + 1;
            $deleted = false;
            try {
                $deleted = $rag->deleteFile((string) $row['remote_file_id'], (string) $row['entity_id']);
            } catch (\Exception $e) {
                self::logger()->warning("Pending RAG deletion failed: " . $e->getMessage());
            }

            if ($deleted || $attempts >= self::MAX_DELETION_ATTEMPTS) {
                if (!$deleted) {
                    self::logger()->error("RAG deletion of " . $row['remote_file_id'] . " given up after $attempts attempts");
                }
                $db->manipulate("DELETE FROM pcaic_rag_deletions WHERE id = " . $db->quote((int) $row['id'], 'integer'));
                continue;
            }

            $db->manipulate(
                "UPDATE pcaic_rag_deletions SET attempts = " . $db->quote($attempts, 'integer')
                . ", next_try = " . $db->quote(date('Y-m-d H:i:s', time() + self::retryDelay(
                    $attempts,
                    self::DELETION_RETRY_BASE_DELAY,
                    self::DELETION_RETRY_MAX_DELAY
                )), 'timestamp')
                . " WHERE id = " . $db->quote((int) $row['id'], 'integer')
            );
        }
    }

    /**
     * Execute a conditional UPDATE that sets a timestamp; only one of several
     * concurrent requests changes the row and gets the claim
     *
     * @param string $query UPDATE with two %s placeholders: new timestamp, latest allowed old timestamp
     * @param int $interval Minimum age of the old timestamp, in seconds
     */
    private static function claim(string $query, int $interval = self::CHECK_INTERVAL): bool
    {
        $db = self::db();
        $now = date('Y-m-d H:i:s');
        $threshold = date('Y-m-d H:i:s', time() - $interval);
        return $db->manipulate(sprintf($query, $db->quote($now, 'text'), $db->quote($threshold, 'text'))) > 0;
    }

    private static function db(): \ilDBInterface
    {
        global $DIC;
        return $DIC->database();
    }

    private static function logger(): \ilLogger
    {
        global $DIC;
        return $DIC->logger()->pcaic();
    }
}
