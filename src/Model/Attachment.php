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

namespace ILIAS\Plugin\pcaic\Model;

use ILIAS\ResourceStorage\Services as ResourceStorage;
use ILIAS\Plugin\pcaic\Storage\ResourceStakeholder;
use Exception;

/**
 * File attached to a chat message or used as background file of a chat
 *
 * Files are stored in the ILIAS Resource Storage. Images and PDF pages are
 * converted via flavours into images suitable for AI services.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class Attachment
{
    protected ?int $id = null;

    /** @var int|null NULL for background files and uploads not yet sent */
    protected ?int $message_id = null;

    protected ?string $chat_id = null;

    protected ?int $user_id = null;

    /** @var string|null Resource Storage identification */
    protected ?string $resource_id = null;

    /** @var string|null Collection in the RAG service */
    protected ?string $rag_collection_id = null;

    /** @var string|null File ID in the RAG service */
    protected ?string $rag_remote_file_id = null;

    protected ?string $rag_uploaded_at = null;

    /** @var string|null Processing state in the RAG service, written by AIChatPageComponentRAGStatus */
    protected ?string $rag_status = null;

    protected ?string $timestamp = null;

    protected bool $background_file = false;

    protected \ilDBInterface $db;

    protected ResourceStorage $resource_storage;

    protected \ilLogger $logger;

    /**
     * @param int|null $id Loads this attachment if given
     */
    public function __construct(?int $id = null)
    {
        global $DIC;

        $this->db = $DIC->database();
        $this->logger = $DIC->logger()->pcaic();
        $this->resource_storage = $DIC->resourceStorage();

        if ($id) {
            $this->id = $id;
            $this->load();
        }
    }

    public function load(): void
    {
        if (!$this->id) {
            return;
        }

        $query = "SELECT * FROM pcaic_attachments WHERE id = " . $this->db->quote($this->id, 'integer');
        $result = $this->db->query($query);

        if ($row = $this->db->fetchAssoc($result)) {
            $this->message_id = $row['message_id'] ? (int) $row['message_id'] : null;
            $this->chat_id = $row['chat_id'];
            $this->user_id = $row['user_id'] ? (int) $row['user_id'] : null;
            $this->resource_id = $row['resource_id'];
            $this->rag_collection_id = $row['rag_collection_id'] ?? null;
            $this->rag_remote_file_id = $row['rag_remote_file_id'] ?? null;
            $this->rag_uploaded_at = $row['rag_uploaded_at'] ?? null;
            $this->rag_status = $row['rag_status'] ?? null;
            $this->timestamp = $row['timestamp'];
            $this->background_file = (bool) ($row['background_file'] ?? 0);
        }
    }

    public static function loadById(int $id): ?self
    {
        $attachment = new self();
        $attachment->id = $id;
        $attachment->load();

        return $attachment->resource_id ? $attachment : null;
    }

    /**
     * Insert or update the attachment
     */
    public function save(): void
    {
        if ($this->id) {
            $query = "UPDATE pcaic_attachments SET " .
                "message_id = " . $this->db->quote($this->message_id, 'integer') . ", " .
                "chat_id = " . $this->db->quote($this->chat_id, 'text') . ", " .
                "user_id = " . $this->db->quote($this->user_id, 'integer') . ", " .
                "resource_id = " . $this->db->quote($this->resource_id, 'text') . ", " .
                "rag_collection_id = " . $this->db->quote($this->rag_collection_id, 'text') . ", " .
                "rag_remote_file_id = " . $this->db->quote($this->rag_remote_file_id, 'text') . ", " .
                "rag_uploaded_at = " . $this->db->quote($this->rag_uploaded_at, 'timestamp') . ", " .
                "background_file = " . $this->db->quote($this->background_file ? 1 : 0, 'integer') . ", " .
                "timestamp = " . $this->db->quote($this->timestamp, 'timestamp') . " " .
                "WHERE id = " . $this->db->quote($this->id, 'integer');
        } else {
            $this->id = $this->db->nextId('pcaic_attachments');
            $query = "INSERT INTO pcaic_attachments (id, message_id, chat_id, user_id, resource_id, rag_collection_id, rag_remote_file_id, rag_uploaded_at, background_file, timestamp) " .
                "VALUES (" .
                $this->db->quote($this->id, 'integer') . ", " .
                $this->db->quote($this->message_id, 'integer') . ", " .
                $this->db->quote($this->chat_id, 'text') . ", " .
                $this->db->quote($this->user_id, 'integer') . ", " .
                $this->db->quote($this->resource_id, 'text') . ", " .
                $this->db->quote($this->rag_collection_id, 'text') . ", " .
                $this->db->quote($this->rag_remote_file_id, 'text') . ", " .
                $this->db->quote($this->rag_uploaded_at, 'timestamp') . ", " .
                $this->db->quote($this->background_file ? 1 : 0, 'integer') . ", " .
                $this->db->quote($this->timestamp, 'timestamp') . ")";
        }

        $this->db->manipulate($query);
    }

    /**
     * Delete the attachment from the RAG service, the Resource Storage and the database
     */
    public function delete(): void
    {
        if (!$this->id) {
            return;
        }

        if ($this->rag_remote_file_id && $this->rag_collection_id) {
            try {
                global $DIC;
                $entity_id = $this->background_file ? $this->chat_id : $this->getSessionIdFromMessage();

                if ($entity_id) {
                    require_once(__DIR__ . '/../../classes/ai/class.AIChatPageComponentLLM.php');
                    require_once(__DIR__ . '/../../classes/ai/class.AIChatPageComponentRAMSES.php');
                    require_once(__DIR__ . '/ChatConfig.php');

                    $chat_config = new ChatConfig($this->chat_id);
                    $llm = $this->createLLMInstance($chat_config->getAiService());
                    $llm->deleteFileFromRAG($this->rag_remote_file_id, $entity_id);

                    $this->logger->info("Deleted file from RAG", [
                        'attachment_id' => $this->id,
                        'rag_remote_file_id' => $this->rag_remote_file_id,
                        'entity_id' => $entity_id
                    ]);
                }
            } catch (Exception $e) {
                $this->logger->warning("Failed to remove file from RAG during attachment deletion", [
                    'attachment_id' => $this->id,
                    'rag_remote_file_id' => $this->rag_remote_file_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        if ($this->resource_id) {
            try {
                $resource_id = $this->resource_storage->manage()->find($this->resource_id);
                if ($resource_id) {
                    $stakeholder = new ResourceStakeholder();
                    $this->resource_storage->manage()->remove($resource_id, $stakeholder);
                }
            } catch (Exception $e) {
                $this->logger->warning("Failed to remove resource during attachment deletion", [
                    'resource_id' => $this->resource_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $query = "DELETE FROM pcaic_attachments WHERE id = " . $this->db->quote($this->id, 'integer');
        $this->db->manipulate($query);
    }

    /**
     * Session of the message this attachment belongs to
     */
    private function getSessionIdFromMessage(): ?string
    {
        if (!$this->message_id) {
            return null;
        }

        $query = "SELECT session_id FROM pcaic_messages WHERE message_id = " . $this->db->quote($this->message_id, 'integer');
        $result = $this->db->query($query);
        if ($row = $this->db->fetchAssoc($result)) {
            return $row['session_id'];
        }
        return null;
    }

    private function createLLMInstance(string $service)
    {
        $instance = \ai\AIChatPageComponentLLMRegistry::createServiceInstance($service);

        if ($instance === null) {
            // Fall back to the first available service
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
     * @return Attachment[]
     */
    public static function getByMessageId(int $message_id): array
    {
        global $DIC;
        $db = $DIC->database();

        $attachments = [];
        $query = "SELECT id FROM pcaic_attachments WHERE message_id = " . $db->quote($message_id, 'integer') . " ORDER BY timestamp ASC";
        $result = $db->query($query);

        while ($row = $db->fetchAssoc($result)) {
            $attachments[] = new self((int) $row['id']);
        }

        return $attachments;
    }

    /**
     * @return Attachment[]
     */
    public static function getByChatId(string $chat_id): array
    {
        global $DIC;
        $db = $DIC->database();

        $attachments = [];
        $query = "SELECT id FROM pcaic_attachments WHERE chat_id = " . $db->quote($chat_id, 'text') . " ORDER BY timestamp ASC";
        $result = $db->query($query);

        while ($row = $db->fetchAssoc($result)) {
            $attachments[] = new self((int) $row['id']);
        }

        return $attachments;
    }

    /**
     * Store an uploaded file and create an attachment for a message
     *
     * @throws Exception
     */
    public static function createFromUpload(\ILIAS\FileUpload\DTO\UploadResult $upload_result, int $message_id, string $chat_id, int $user_id): self
    {
        global $DIC;

        if (!$upload_result->isOK()) {
            $logger = $DIC->logger()->pcaic();
            $logger->debug("Upload failed: " . $upload_result->getStatus()->getMessage());
            throw new Exception("Upload failed");
        }

        $resource_storage = $DIC->resourceStorage();

        $stakeholder = new ResourceStakeholder();
        $resource_id = $resource_storage->manage()->upload($upload_result, $stakeholder);

        $attachment = new self();
        $attachment->setMessageId($message_id);
        $attachment->setChatId($chat_id);
        $attachment->setUserId($user_id);
        $attachment->setResourceId($resource_id->serialize());
        $attachment->setTimestamp(date('Y-m-d H:i:s'));
        $attachment->save();

        return $attachment;
    }

    public function getResourceIdentification(): ?\ILIAS\ResourceStorage\Identification\ResourceIdentification
    {
        if (!$this->resource_id) {
            return null;
        }

        try {
            return $this->resource_storage->manage()->find($this->resource_id);
        } catch (Exception $e) {
            $this->logger->warning("Failed to find resource", [
                'resource_id' => $this->resource_id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getCurrentRevision(): ?\ILIAS\ResourceStorage\Revision\Revision
    {
        $resource_id = $this->getResourceIdentification();
        if (!$resource_id) {
            return null;
        }

        try {
            return $this->resource_storage->manage()->getCurrentRevision($resource_id);
        } catch (Exception $e) {
            $this->logger->warning("Failed to get current revision", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getDownloadUrl(): ?string
    {
        $resource_id = $this->getResourceIdentification();
        if (!$resource_id) {
            return null;
        }

        try {
            $src_consumer = $this->resource_storage->consume()->src($resource_id);
            $download_url = $src_consumer->getSrc();

            if ($download_url) {
                // URLs generated from the plugin directory point to the wrong base path; rebuild them from ILIAS_HTTP_PATH
                if (strpos($download_url, '/Customizing/global/plugins/') !== false) {
                    $pattern = '/.*\/(src\/FileDelivery\/deliver\.php\/.+)$/';
                    if (preg_match($pattern, $download_url, $matches)) {
                        $ilias_base = rtrim(preg_replace('~(/Customizing)(?=/|$).*~i', '', ILIAS_HTTP_PATH), '/');
                        $corrected_url = $ilias_base . '/' . $matches[1];

                        $this->logger->debug("Corrected IRSS URL", [
                            'original_url' => $download_url,
                            'corrected_url' => $corrected_url
                        ]);
                        return $corrected_url;
                    }
                }

                $this->logger->debug("Using original IRSS URL", ['url' => $download_url]);
                return $download_url;
            } else {
                $this->logger->warning("IRSS getSrc returned empty URL");
            }

        } catch (Exception $e) {
            $this->logger->warning("IRSS getSrc failed", ['error' => $e->getMessage()]);
        }

        return null;
    }

    public function getPreviewUrl(): ?string
    {
        if (!$this->resource_id) {
            return null;
        }

        // No thumbnails are generated for PDFs
        if ($this->getMimeType() === 'application/pdf') {
            $this->logger->debug("Skipping preview URL for PDF");
            return null;
        }

        $resource_id = $this->getResourceIdentification();
        if ($resource_id) {
            $flavour_url = $this->getThumbnailFlavourUrl($resource_id, $this->resource_storage);
            if ($flavour_url) {
                $this->logger->debug("Using ILIAS Flavour URL", ['url' => $flavour_url]);
                return $flavour_url;
            }
        }

        $ilias_base = rtrim(preg_replace('~(/Customizing)(?=/|$).*~i', '', ILIAS_HTTP_PATH), '/');
        $delivery_url = $ilias_base . '/src/FileDelivery/deliver.php/' . $this->resource_id;

        $this->logger->debug("Using simple preview URL fallback", ['url' => $delivery_url]);
        return $delivery_url;
    }

    /**
     * Signed URL of the thumbnail flavour for images
     */
    private function getThumbnailFlavourUrl($identification, $resource_storage): ?string
    {
        try {
            $mime_type = $this->getMimeType();

            if (strpos($mime_type, 'image/') === 0) {
                $thumbnail_definition = $this->createChatThumbnailFlavourDefinition();

                $resource_storage->flavours()->ensure($identification, $thumbnail_definition);

                $thumbnail_flavour = $resource_storage->flavours()->get($identification, $thumbnail_definition);

                if ($thumbnail_flavour) {
                    $flavour_urls_obj = $resource_storage->consume()->flavourUrls($thumbnail_flavour);
                    $flavour_urls = $flavour_urls_obj->getURLsAsArray(true);

                    if (!empty($flavour_urls)) {
                        $thumbnail_url = $flavour_urls[0];

                        // URLs containing the plugin path are generated with a wrong base path
                        if (strpos($thumbnail_url, '/Customizing/global/plugins/') !== false) {
                            $this->logger->debug("Flavour URL contains plugin path, skipping", ['url' => $thumbnail_url]);
                        } else {
                            $this->logger->debug("Generated thumbnail flavour URL", ['url' => $thumbnail_url]);
                            return $thumbnail_url;
                        }
                    }
                }

            }

        } catch (\Exception $e) {
            $this->logger->warning("Failed to create thumbnail flavour", ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function createChatThumbnailFlavourDefinition(): \ILIAS\ResourceStorage\Flavour\Definition\FlavourDefinition
    {
        return new \ILIAS\ResourceStorage\Flavour\Definition\CropToSquare(
            true,  // persist
            150,   // max size in pixels
            75     // JPEG quality
        );
    }

    /**
     * Image scaled for AI services, read directly from the cached flavour
     */
    private function getOptimizedImageFlavourData($identification): ?string
    {
        try {
            $mime_type = $this->getMimeType();

            if (strpos($mime_type, 'image/') === 0) {
                $ai_optimized_definition = $this->createAiOptimizedImageFlavourDefinition();

                $this->resource_storage->flavours()->ensure($identification, $ai_optimized_definition);

                $ai_optimized_flavour = $this->resource_storage->flavours()->get($identification, $ai_optimized_definition);

                if ($ai_optimized_flavour) {
                    $flavour_resource_id = $ai_optimized_flavour->getResourceId();

                    $stream_consumer = $this->resource_storage->consume()->stream($flavour_resource_id);
                    $optimized_data = $stream_consumer->getStream()->getContents();

                    if ($optimized_data !== false && !empty($optimized_data)) {
                        $this->logger->debug("Retrieved ILIAS Flavour data", ['size_bytes' => strlen($optimized_data)]);
                        return $optimized_data;
                    } else {
                        $this->logger->warning("ILIAS Flavour stream returned empty data");
                    }
                } else {
                    $this->logger->warning("Failed to get AI-optimized flavour object");
                }
            }

        } catch (\Exception $e) {
            $this->logger->warning("Failed to get AI-optimized flavour data", ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function createAiOptimizedImageFlavourDefinition(): \ILIAS\ResourceStorage\Flavour\Definition\FlavourDefinition
    {
        // Scales the image into a square of max_size pixels, keeping the aspect ratio
        return new \ILIAS\ResourceStorage\Flavour\Definition\FitToSquare(
            true,  // persist
            1024,  // max size in pixels, same as ImageOptimizer::MAX_DIMENSION
            85     // JPEG quality, same as ImageOptimizer::JPEG_QUALITY
        );
    }

    public function isImage(): bool
    {
        $revision = $this->getCurrentRevision();
        if (!$revision) {
            return false;
        }

        $info = $revision->getInformation();
        $mime_type = $info->getMimeType();
        return strpos($mime_type, 'image/') === 0;
    }

    public function getTitle(): string
    {
        $revision = $this->getCurrentRevision();
        if (!$revision) {
            return 'Unknown';
        }

        return $revision->getInformation()->getTitle();
    }

    public function getSize(): int
    {
        $revision = $this->getCurrentRevision();
        if (!$revision) {
            return 0;
        }

        return $revision->getInformation()->getSize();
    }

    public function getMimeType(): string
    {
        $revision = $this->getCurrentRevision();
        if (!$revision) {
            return 'application/octet-stream';
        }

        return $revision->getInformation()->getMimeType();
    }

    public function getContentAsBase64(): ?string
    {
        $resource_id = $this->getResourceIdentification();
        if (!$resource_id) {
            return null;
        }

        try {
            $stream = $this->resource_storage->consume()->stream($resource_id);
            $content = $stream->getStream()->getContents();
            if ($content !== false) {
                return base64_encode($content);
            }
        } catch (Exception $e) {
            $this->logger->warning("Failed to read attachment content", ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Data URL(s) for AI services
     *
     * @return string|array|null Image: one data URL; PDF: one data URL per page; other types: null
     */
    public function getDataUrl()
    {
        if (!$this->isImage() && !$this->isPdf()) {
            return null;
        }

        if ($this->isPdf()) {
            return $this->getPdfPagesAsDataUrls();
        }

        $base64_content = $this->getOptimizedContentAsBase64();
        if (!$base64_content) {
            return null;
        }

        return $base64_content;
    }

    /**
     * Single data URL; for PDFs the first page
     */
    public function getOptimizedContentAsBase64(): ?string
    {
        if ($this->isImage()) {
            return $this->getOptimizedImageAsBase64();
        } elseif ($this->getMimeType() === 'application/pdf') {
            $pdf_pages = $this->getOptimizedPdfAsBase64();
            if (is_array($pdf_pages) && !empty($pdf_pages)) {
                return $pdf_pages[0];
            } elseif (is_string($pdf_pages)) {
                return $pdf_pages; // Text fallback if the PDF could not be converted
            }
            return null;
        }

        return null;
    }

    /**
     * Scaled image as data URL; uses the cached flavour and falls back to ImageOptimizer
     */
    private function getOptimizedImageAsBase64(): ?string
    {
        $resource_id = $this->getResourceIdentification();
        if (!$resource_id) {
            return null;
        }

        try {
            $optimized_flavour_data = $this->getOptimizedImageFlavourData($resource_id);
            if ($optimized_flavour_data) {
                $base64_content = base64_encode($optimized_flavour_data);

                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $optimized_mime = $finfo->buffer($optimized_flavour_data) ?: 'image/jpeg';

                $this->logger->debug("Using ILIAS Flavour optimized image", [
                    'size_bytes' => strlen($optimized_flavour_data),
                    'base64_chars' => strlen($base64_content)
                ]);

                return 'data:' . $optimized_mime . ';base64,' . $base64_content;
            }

            $this->logger->debug("ILIAS Flavour failed, using ImageOptimizer fallback");

            $stream = $this->resource_storage->consume()->stream($resource_id);
            $original_data = $stream->getStream()->getContents();
            if ($original_data === false) {
                return null;
            }

            require_once(__DIR__ . '/../Service/ImageOptimizer.php');
            $optimized = \ILIAS\Plugin\pcaic\Service\ImageOptimizer::optimize(
                $original_data,
                $this->getMimeType()
            );

            $base64_content = base64_encode($optimized['data']);

            $this->logger->debug("Using ImageOptimizer fallback", [
                'original_bytes' => strlen($original_data),
                'optimized_bytes' => strlen($optimized['data']),
                'base64_chars' => strlen($base64_content)
            ]);

            return 'data:' . $optimized['mime_type'] . ';base64,' . $base64_content;

        } catch (Exception $e) {
            $this->logger->warning("Failed to optimize image", ['error' => $e->getMessage()]);
            $base64_content = $this->getContentAsBase64();
            if ($base64_content) {
                return 'data:' . $this->getMimeType() . ';base64,' . $base64_content;
            }
        }

        return null;
    }

    /**
     * PDF pages as data URLs, converted via the cached PagesToExtract flavour
     *
     * @return array|string|null Data URLs per page, or a text fallback if the conversion failed
     */
    private function getOptimizedPdfAsBase64()
    {
        $resource_id = $this->getResourceIdentification();
        if (!$resource_id) {
            $this->logger->warning("No resource ID for PDF optimization");
            return null;
        }

        try {
            $pdf_flavour_definition = $this->createAiPdfFlavourDefinition();

            $this->logger->debug("Ensuring PDF flavour exists", ['resource_id' => $this->resource_id]);
            $this->resource_storage->flavours()->ensure($resource_id, $pdf_flavour_definition);

            $pdf_flavour = $this->resource_storage->flavours()->get($resource_id, $pdf_flavour_definition);

            if (!$pdf_flavour) {
                $this->logger->warning("Failed to get PDF flavour");
                return null;
            }

            $stream_resolvers = $pdf_flavour->getStreamResolvers();

            if (empty($stream_resolvers)) {
                $this->logger->debug("No PDF stream resolvers, using text fallback");
                $title = $this->getTitle();
                $fallback_text = "PDF Document: {$title}";
                $fallback_data_url = 'data:text/plain;base64,' . base64_encode($fallback_text);
                $this->logger->debug("Using PDF text fallback", ['text' => $fallback_text]);
                return $fallback_data_url;
            }

            $pdf_pages_data = $this->processPdfFlavourStreams($stream_resolvers);

            if (!$pdf_pages_data) {
                $this->logger->warning("No PDF page data retrieved from cached flavours");
                return null;
            }

            $this->logger->debug("PDF converted to page images", ['page_count' => count($pdf_pages_data)]);
            return $pdf_pages_data;

        } catch (\Exception $e) {
            $this->logger->warning("Failed to convert PDF to image for AI", ['error' => $e->getMessage()]);

            // The file name gives the AI service at least some context
            $title = $this->getTitle();
            $fallback_text = "PDF Document: {$title}";
            $fallback_data_url = 'data:text/plain;base64,' . base64_encode($fallback_text);
            $this->logger->debug("PDF conversion failed, using text fallback", ['text' => $fallback_text]);
            return $fallback_data_url;
        }
    }

    private function processPdfFlavourStreams(array $stream_resolvers): ?array
    {
        $pages_data = [];
        // Configured page limit; the flavour definition extracts at most 50 pages
        $max_pages = min(50, max(1, (int) (\platform\AIChatPageComponentConfig::get('pdf_pages_processed') ?: 20)));
        $pages_processed = 0;

        foreach ($stream_resolvers as $i => $resolver) {
            if ($pages_processed >= $max_pages) {
                $this->logger->debug("PDF limiting to maximum pages", ['max_pages' => $max_pages]);
                break;
            }

            try {
                $stream = $resolver->getStream();

                // Detect the image type from the first bytes
                $head = $stream->read(16);
                $mime = (strncmp($head, "\x89PNG", 4) === 0) ? 'image/png'
                    : ((strncmp($head, "\xFF\xD8\xFF", 3) === 0) ? 'image/jpeg' : 'image/png');

                $page_content = $head;
                while (!$stream->eof()) {
                    $page_content .= $stream->read(8192);
                }
                $stream->close();

                if ($page_content) {
                    require_once(__DIR__ . '/../Service/ImageOptimizer.php');
                    $optimized = \ILIAS\Plugin\pcaic\Service\ImageOptimizer::optimize(
                        $page_content,
                        $mime
                    );

                    $base64_content = base64_encode($optimized['data']);
                    $data_url = 'data:' . $optimized['mime_type'] . ';base64,' . $base64_content;

                    $pages_data[] = $data_url;
                    $pages_processed++;

                    $this->logger->debug("PDF page processed via stream resolver", ['page' => $i + 1]);
                } else {
                    $this->logger->warning("Empty page content from stream resolver", ['resolver_index' => $i]);
                }

            } catch (\Exception $e) {
                $this->logger->warning("Failed to process PDF page via stream", [
                    'page' => $i + 1,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return !empty($pages_data) ? $pages_data : null;
    }

    private function createAiPdfFlavourDefinition(): \ILIAS\ResourceStorage\Flavour\Definition\FlavourDefinition
    {
        return new \ILIAS\ResourceStorage\Flavour\Definition\PagesToExtract(
            true,    // persist
            1024,    // max size in pixels
            50,      // max pages
            false,   // fill; false keeps the aspect ratio
            85       // JPEG quality
        );
    }

    /**
     * PDF pages as data URLs; a text fallback is returned as single-element array
     */
    public function getPdfPagesAsDataUrls(): ?array
    {
        if (!$this->isPdf()) {
            return null;
        }

        $pdf_data_urls = $this->getOptimizedPdfAsBase64();

        if ($pdf_data_urls && is_array($pdf_data_urls)) {
            $this->logger->debug("PDF processed using cached flavours", [
                'filename' => $this->getTitle(),
                'page_count' => count($pdf_data_urls)
            ]);
            return $pdf_data_urls;
        } elseif ($pdf_data_urls && is_string($pdf_data_urls)) {
            $this->logger->debug("PDF using fallback text representation", ['filename' => $this->getTitle()]);
            return [$pdf_data_urls];
        }

        return null;
    }

    public function isPdf(): bool
    {
        return $this->getMimeType() === 'application/pdf';
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getMessageId(): ?int
    {
        return $this->message_id;
    }
    public function setMessageId(?int $message_id): void
    {
        $this->message_id = $message_id;
    }

    public function getChatId(): ?string
    {
        return $this->chat_id;
    }
    public function setChatId(?string $chat_id): void
    {
        $this->chat_id = $chat_id;
    }

    public function getUserId(): ?int
    {
        return $this->user_id;
    }
    public function setUserId(?int $user_id): void
    {
        $this->user_id = $user_id;
    }

    public function getResourceId(): ?string
    {
        return $this->resource_id;
    }
    public function setResourceId(?string $resource_id): void
    {
        $this->resource_id = $resource_id;
    }

    public function getTimestamp(): ?string
    {
        return $this->timestamp;
    }
    public function setTimestamp(?string $timestamp): void
    {
        $this->timestamp = $timestamp;
    }

    public function getRAGCollectionId(): ?string
    {
        return $this->rag_collection_id;
    }
    public function setRAGCollectionId(?string $rag_collection_id): void
    {
        $this->rag_collection_id = $rag_collection_id;
    }

    public function getRAGRemoteFileId(): ?string
    {
        return $this->rag_remote_file_id;
    }
    public function setRAGRemoteFileId(?string $rag_remote_file_id): void
    {
        $this->rag_remote_file_id = $rag_remote_file_id;
    }

    public function getRAGUploadedAt(): ?string
    {
        return $this->rag_uploaded_at;
    }
    public function setRAGUploadedAt(?string $rag_uploaded_at): void
    {
        $this->rag_uploaded_at = $rag_uploaded_at;
    }

    public function getRAGStatus(): ?string
    {
        return $this->rag_status;
    }

    public function isBackgroundFile(): bool
    {
        return $this->background_file;
    }
    public function setBackgroundFile(bool $background_file): void
    {
        $this->background_file = $background_file;
    }

    /**
     * Plain text files, whose content is sent to the AI service as text
     */
    public function isTextFile(): bool
    {
        $extension = strtolower(pathinfo($this->getTitle(), PATHINFO_EXTENSION));
        return in_array($extension, ['txt', 'csv', 'md'], true);
    }

    /**
     * Content of a text file as UTF-8
     */
    public function getTextContent(): ?string
    {
        $resource_id = $this->getResourceIdentification();
        if (!$resource_id) {
            return null;
        }

        try {
            $content = $this->resource_storage->consume()->stream($resource_id)->getStream()->getContents();
            return self::toUtf8($content);
        } catch (Exception $e) {
            $this->logger->warning("Failed to read text attachment: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Convert text to UTF-8; text files are often Windows-1252 encoded, and invalid
     * UTF-8 would make the JSON request to the AI service fail
     */
    public static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    public function isInRAG(): bool
    {
        return $this->rag_collection_id !== null;
    }

    public function isInIRSS(): bool
    {
        return $this->resource_id !== null;
    }

    /**
     * Attachment data for API responses
     *
     * src is the best URL for display (null for PDFs, the frontend shows an icon);
     * data_url is a Base64 fallback for images.
     */
    public function toArray(): array
    {
        $download_url = $this->getDownloadUrl();
        $preview_url = $this->getPreviewUrl();
        $file_type = $this->getFileType();

        $data_url = null;
        if ($file_type === 'image') {
            $data_url = $this->getDataUrl();
        }

        $src_url = null;
        if ($file_type === 'image') {
            $src_url = $preview_url ?: ($data_url ?: $download_url);
        } elseif ($file_type === 'pdf') {
            $src_url = null;
        } else {
            $src_url = $download_url;
        }

        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'filename' => $this->getTitle(),
            'size' => $this->getSize(),
            'mime_type' => $this->getMimeType(),
            'file_type' => $file_type,
            'is_image' => $this->isImage(),
            'download_url' => $download_url,
            'preview_url' => $preview_url,
            'thumbnail_url' => $preview_url,
            'src' => $src_url,
            'data_url' => $data_url,
            'rag_status' => $this->rag_status
        ];
    }

    /**
     * @return string image|pdf|document|other
     */
    private function getFileType(): string
    {
        $mime_type = $this->getMimeType();

        if (strpos($mime_type, 'image/') === 0) {
            return 'image';
        } elseif ($mime_type === 'application/pdf') {
            return 'pdf';
        } elseif (strpos($mime_type, 'text/') === 0) {
            return 'text';
        }

        return 'other';
    }
}
