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
 * attachment and queried from the RAG at most once per minute and chat, triggered
 * by chat requests; whether a chat has unprocessed files is decided from the
 * database only. Files whose processing failed are uploaded again with an
 * increasing delay. Files the RAG could not delete yet are deleted later.
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
    /** Processed by the RAG, but the retrieval finds no content (e.g. a scan the text recognition could not read) */
    public const EMPTY = 'empty';
    /** Processed and usable, but the file is a scan: the content depends on the text recognition of the RAG */
    public const SCANNED = 'scanned';

    /** @var int Minimum interval between two status checks of a chat, in seconds */
    public const CHECK_INTERVAL = 60;

    /** @var int Delay before the first new upload after a failure, in seconds */
    public const RETRY_BASE_DELAY = 300;

    /** @var int Maximum delay between new uploads, in seconds */
    public const RETRY_MAX_DELAY = 21600;

    /** @var int Pending deletions are given up after this number of attempts */
    public const MAX_DELETION_ATTEMPTS = 100;

    /** @var int Pending deletions processed per run */
    private const DELETIONS_PER_RUN = 10;

    private const DELETION_CHECK_KEY = 'rag_deletions_checked_at';

    /**
     * Delay before the next upload after $failed_count failures in a row
     */
    public static function retryDelay(int $failed_count): int
    {
        $exponent = max(0, min($failed_count - 1, 20));
        return (int) min(self::RETRY_BASE_DELAY * (2 ** $exponent), self::RETRY_MAX_DELAY);
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

    /**
     * Final state of a file the RAG reports as completed
     *
     * The chunk count of the RAG decides if it reports one. Otherwise a file with
     * text is completed. A file without text (a scan) is usable if the text
     * recognition of the RAG read it, which only the retrieval shows: it is empty if
     * the probe found nothing, and marked as a scan otherwise.
     *
     * @param int|null $chunks Chunk count reported by the RAG, null if unknown
     * @param bool|null $has_text Whether the file contains text, null if unknown
     * @param bool|null $probe Result of AIChatPageComponentRAG::probeFile(), null if undecided or not asked
     */
    public static function completedState(?int $chunks, ?bool $has_text, ?bool $probe = null): string
    {
        if ($chunks !== null) {
            return $chunks > 0 ? self::COMPLETED : self::EMPTY;
        }
        if ($has_text !== false) {
            return self::COMPLETED;
        }

        return $probe === false ? self::EMPTY : self::SCANNED;
    }

    /**
     * Whether a file contains text the RAG can index
     *
     * PDFs: text needs a font, so a PDF without any font resource consists of images
     * only (e.g. a scan without OCR). Fonts may be stored in compressed object streams.
     *
     * @return bool|null Null if it cannot be decided (other file types, encrypted or unreadable PDFs)
     */
    public static function hasText(string $content, string $suffix): ?bool
    {
        $suffix = strtolower($suffix);
        if (in_array($suffix, ['txt', 'csv', 'md'], true)) {
            return trim($content) !== '';
        }
        if ($suffix !== 'pdf' || strncmp($content, '%PDF-', 5) !== 0) {
            return null;
        }
        if (strpos($content, '/Font') !== false) {
            return true;
        }
        if (strpos($content, '/Encrypt') !== false) {
            return null;
        }

        // Not found in plain objects: search the compressed object streams
        $undecided = false;
        $offset = 0;
        while (($position = strpos($content, '/ObjStm', $offset)) !== false) {
            $offset = $position + 7;
            $start = strpos($content, 'stream', $offset);
            $end = $start === false ? false : strpos($content, 'endstream', $start);
            if ($start === false || $end === false) {
                break;
            }
            $offset = $end;

            $compressed = ltrim(substr($content, $start + 6, $end - $start - 6), "\r\n");
            $data = @gzuncompress($compressed, 16 * 1024 * 1024);
            if ($data === false) {
                $undecided = true;
            } elseif (strpos($data, '/Font') !== false) {
                return true;
            }
        }

        return $undecided ? null : false;
    }

    /**
     * Whether the stored file of an attachment contains text, see hasText()
     */
    private static function attachmentHasText(int $attachment_id): ?bool
    {
        try {
            global $DIC;
            $irss = $DIC->resourceStorage();
            $attachment = \ILIAS\Plugin\pcaic\Model\Attachment::loadById($attachment_id);
            $identification = $attachment !== null ? $attachment->getResourceIdentification() : null;
            if ($identification === null) {
                return null;
            }
            $suffix = (string) $irss->manage()->getCurrentRevision($identification)->getInformation()->getSuffix();
            if (!in_array(strtolower($suffix), ['pdf', 'txt', 'csv', 'md'], true)) {
                return null;
            }
            return self::hasText((string) $irss->consume()->stream($identification)->getStream()->getContents(), $suffix);
        } catch (\Throwable $e) {
            self::logger()->debug("Text check of attachment $attachment_id failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Title of the file of an attachment, used as search text of the probe
     */
    private static function attachmentTitle(int $attachment_id): string
    {
        try {
            $title = (new \ILIAS\Plugin\pcaic\Model\Attachment($attachment_id))->getTitle();
        } catch (\Throwable $e) {
            $title = '';
        }
        return trim(preg_replace('/\.[A-Za-z0-9]+$/', '', (string) $title)) ?: 'document';
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
     */
    public static function markFailed(int $attachment_id, string $error): void
    {
        $db = self::db();
        $result = $db->query("SELECT rag_failed_count FROM pcaic_attachments WHERE id = " . $db->quote($attachment_id, 'integer'));
        $row = $db->fetchAssoc($result);
        $failed_count = (int) ($row['rag_failed_count'] ?? 0) + 1;

        $db->manipulate(
            "UPDATE pcaic_attachments SET"
            . " rag_collection_id = NULL, rag_remote_file_id = NULL, rag_uploaded_at = NULL,"
            . " rag_status = " . $db->quote(self::FAILED, 'text') . ","
            . " rag_status_error = " . $db->quote(mb_substr($error, 0, 1000), 'text') . ","
            . " rag_failed_count = " . $db->quote($failed_count, 'integer') . ","
            . " rag_retry_at = " . $db->quote(date('Y-m-d H:i:s', time() + self::retryDelay($failed_count)), 'timestamp')
            . " WHERE id = " . $db->quote($attachment_id, 'integer')
        );
    }

    /**
     * SQL condition for attachments that may be uploaded to the RAG now
     */
    public static function uploadDueCondition(): string
    {
        $db = self::db();
        return "(rag_status IS NULL OR (rag_status = " . $db->quote(self::FAILED, 'text')
            . " AND (rag_retry_at IS NULL OR rag_retry_at <= " . $db->quote(date('Y-m-d H:i:s'), 'timestamp') . ")))";
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
     * Query the RAG for files of the chat that are still processing, at most once per minute
     *
     * @return bool False if the check was skipped because of the interval
     */
    public static function refreshChat(string $chat_id): bool
    {
        if (!self::claim(
            "UPDATE pcaic_chats SET rag_status_checked_at = %s WHERE chat_id = " . self::db()->quote($chat_id, 'text')
            . " AND (rag_status_checked_at IS NULL OR rag_status_checked_at <= %s)"
        )) {
            return false;
        }

        $db = self::db();
        $rag = new AIChatPageComponentRAG();
        $result = $db->query(
            "SELECT a.id, a.rag_remote_file_id, a.rag_collection_id, a.background_file, a.chat_id, m.session_id"
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
                    $chunks = $status['chunks'] ?? null;
                    $has_text = $chunks === null ? self::attachmentHasText((int) $row['id']) : null;
                    // A scan is only searchable if the text recognition of the RAG read it: ask the retrieval once
                    $probe = $chunks === null && $has_text === false
                        ? $rag->probeFile(
                            (string) $row['rag_remote_file_id'],
                            (string) ($row['rag_collection_id'] ?? ''),
                            self::attachmentTitle((int) $row['id'])
                        )
                        : null;
                    $state = self::completedState($chunks, $has_text, $probe);
                    if ($state !== self::COMPLETED) {
                        self::logger()->warning("RAG processed attachment " . $row['id'] . " as " . $state
                            . ($state === self::EMPTY ? ": no searchable content" : ": scan, content depends on text recognition"));
                    }
                    $db->manipulate(
                        "UPDATE pcaic_attachments SET rag_status = " . $db->quote($state, 'text')
                        . ", rag_status_error = NULL, rag_failed_count = 0, rag_retry_at = NULL"
                        . " WHERE id = " . $db->quote((int) $row['id'], 'integer')
                    );
                    break;
                case self::FAILED:
                    self::logger()->warning("RAG processing of attachment " . $row['id'] . " failed: " . ($status['error'] ?? ''));
                    self::markFailed((int) $row['id'], (string) ($status['error'] ?? 'Processing failed'));
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
                . ", next_try = " . $db->quote(date('Y-m-d H:i:s', time() + self::retryDelay($attempts)), 'timestamp')
                . " WHERE id = " . $db->quote((int) $row['id'], 'integer')
            );
        }
    }

    /**
     * Execute a conditional UPDATE that sets a timestamp; only one of several
     * concurrent requests changes the row and gets the claim
     *
     * @param string $query UPDATE with two %s placeholders: new timestamp, latest allowed old timestamp
     */
    private static function claim(string $query): bool
    {
        $db = self::db();
        $now = date('Y-m-d H:i:s');
        $threshold = date('Y-m-d H:i:s', time() - self::CHECK_INTERVAL);
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
