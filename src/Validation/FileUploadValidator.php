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

namespace ILIAS\Plugin\pcaic\Validation;

/**
 * Validates uploads against the file handling settings of the plugin configuration
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class FileUploadValidator
{
    /** @var int Maximum size of an uploaded file in MB if none is configured */
    public const DEFAULT_MAX_FILE_SIZE_MB = 5;

    /**
     * Maximum size of a file uploaded in the chat, in MB (plugin configuration)
     *
     * The only place that reads the setting; checks, messages and the frontend use this value.
     */
    public static function getMaxFileSizeMb(): int
    {
        $configured = (int) \platform\AIChatPageComponentConfig::get('max_file_size_mb');
        return $configured > 0 ? $configured : self::DEFAULT_MAX_FILE_SIZE_MB;
    }

    public static function getMaxFileSizeBytes(): int
    {
        return self::getMaxFileSizeMb() * 1024 * 1024;
    }

    /**
     * Largest upload PHP accepts, in bytes: the smaller of upload_max_filesize and post_max_size
     *
     * @return int|null Null if PHP sets no limit
     */
    public static function getPhpUploadLimitBytes(): ?int
    {
        $limits = array_filter([
            self::iniSizeToBytes((string) ini_get('upload_max_filesize')),
            self::iniSizeToBytes((string) ini_get('post_max_size')),
        ], static fn(int $bytes): bool => $bytes > 0);

        return $limits === [] ? null : min($limits);
    }

    /**
     * Whether the configured maximum file size cannot be reached because PHP rejects such uploads
     */
    public static function exceedsPhpUploadLimit(int $max_file_size_mb, ?int $php_limit_bytes): bool
    {
        return $php_limit_bytes !== null && $max_file_size_mb * 1024 * 1024 > $php_limit_bytes;
    }

    /**
     * Bytes of a php.ini size value such as "8M", "512K", "1G" or "1048576"; 0 or less means unlimited
     */
    public static function iniSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $number = (int) $value;
        switch (strtolower($value[strlen($value) - 1])) {
            case 'g':
                return $number * 1024 * 1024 * 1024;
            case 'm':
                return $number * 1024 * 1024;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
    }

    private static function getDefaultAllowedExtensions(): array
    {
        $default_types = \platform\AIChatPageComponentConfig::get('default_allowed_file_types');
        return is_array($default_types) ? $default_types : ['txt', 'pdf', 'csv', 'png', 'jpg', 'jpeg', 'webp', 'gif'];
    }

    /**
     * @var array<string, string> Extension => MIME type
     */
    private const EXTENSION_TO_MIME = [
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'csv' => 'text/csv',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp'
    ];

    /**
     * @param array $upload_info Upload data with name, size and tmp_name
     * @param string $upload_type background|chat
     * @return array{success: bool, error: string|null}
     */
    public static function validateUpload(array $upload_info, string $upload_type, ?string $chat_id = null): array
    {
        $file_restrictions = \platform\AIChatPageComponentConfig::get('file_upload_restrictions') ?? [];

        if (!($file_restrictions['enabled'] ?? false)) {
            return ['success' => false, 'error' => 'File handling is disabled by administrator. Files cannot be processed by AI.'];
        }

        if ($upload_type === 'background' && !($file_restrictions['allow_background_files'] ?? true)) {
            return ['success' => false, 'error' => 'Background file uploads are disabled by administrator.'];
        }

        if ($upload_type === 'chat' && !($file_restrictions['allow_chat_uploads'] ?? true)) {
            return ['success' => false, 'error' => 'Chat file uploads are disabled by administrator.'];
        }

        $size_validation = self::validateFileSize($upload_info);
        if (!$size_validation['success']) {
            return $size_validation;
        }

        $type_validation = self::validateFileType($upload_info, $file_restrictions);
        if (!$type_validation['success']) {
            return $type_validation;
        }

        return ['success' => true, 'error' => null];
    }

    private static function validateFileSize(array $upload_info): array
    {
        $max_file_size_mb = self::getMaxFileSizeMb();

        if ($upload_info['size'] > self::getMaxFileSizeBytes()) {
            return ['success' => false, 'error' => "File too large. Maximum size is {$max_file_size_mb}MB."];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Check extension against the whitelist and the file content against the expected MIME type
     */
    private static function validateFileType(array $upload_info, array $file_restrictions): array
    {
        $allowed_extensions = $file_restrictions['allowed_file_types'] ?? self::getDefaultAllowedExtensions();

        if (empty($allowed_extensions)) {
            $allowed_extensions = self::getDefaultAllowedExtensions();
        }

        $allowed_extensions = array_map('strtolower', $allowed_extensions);

        $filename = $upload_info['name'] ?? '';
        $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($file_extension, $allowed_extensions)) {
            $allowed_list = implode(', ', $allowed_extensions);
            return ['success' => false, 'error' => "File type '{$file_extension}' not allowed. Allowed types: {$allowed_list}"];
        }

        $expected_mime = self::EXTENSION_TO_MIME[$file_extension] ?? null;
        if ($expected_mime) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $actual_mime = finfo_file($finfo, $upload_info['tmp_name']);
            finfo_close($finfo);

            if ($actual_mime !== $expected_mime) {
                return ['success' => false, 'error' => "File content does not match extension '{$file_extension}'."];
            }
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * @param string $upload_type background|chat
     * @return string[] Allowed extensions; empty if uploads of this type are disabled
     */
    public static function getAllowedExtensions(string $upload_type): array
    {
        $file_restrictions = \platform\AIChatPageComponentConfig::get('file_upload_restrictions') ?? [];

        if (!($file_restrictions['enabled'] ?? false)) {
            return [];
        }

        if ($upload_type === 'background' && !($file_restrictions['allow_background_files'] ?? true)) {
            return [];
        }

        if ($upload_type === 'chat' && !($file_restrictions['allow_chat_uploads'] ?? true)) {
            return [];
        }

        $allowed_extensions = $file_restrictions['allowed_file_types'] ?? self::getDefaultAllowedExtensions();

        if (empty($allowed_extensions)) {
            return self::getDefaultAllowedExtensions();
        }

        return $allowed_extensions;
    }

    /**
     * @param string $upload_type background|chat
     */
    public static function isUploadEnabled(string $upload_type): bool
    {
        $file_restrictions = \platform\AIChatPageComponentConfig::get('file_upload_restrictions') ?? [];

        if (!($file_restrictions['enabled'] ?? false)) {
            return false;
        }

        if ($upload_type === 'background') {
            return $file_restrictions['allow_background_files'] ?? true;
        }

        if ($upload_type === 'chat') {
            return $file_restrictions['allow_chat_uploads'] ?? true;
        }

        return false;
    }

    /**
     * @param string[] $extensions
     * @return string[] Unique MIME types
     */
    public static function extensionsToMimeTypes(array $extensions): array
    {
        $mime_types = [];
        foreach ($extensions as $ext) {
            $ext = strtolower($ext);
            if (isset(self::EXTENSION_TO_MIME[$ext])) {
                $mime_types[] = self::EXTENSION_TO_MIME[$ext];
            }
        }
        return array_values(array_unique($mime_types));
    }

    /**
     * Values for the HTML accept attribute
     *
     * Contains MIME types and extensions, because browsers do not recognise every
     * MIME type (e.g. text/markdown).
     *
     * @param string[] $extensions
     * @return string[]
     */
    public static function extensionsToAcceptValues(array $extensions): array
    {
        $accept_values = [];

        foreach ($extensions as $ext) {
            $ext = strtolower($ext);

            if (isset(self::EXTENSION_TO_MIME[$ext])) {
                $accept_values[] = self::EXTENSION_TO_MIME[$ext];
            }

            $accept_values[] = '.' . $ext;
        }

        return array_values(array_unique($accept_values));
    }

    /**
     * @return array<string, string> Extension => MIME type
     */
    public static function getExtensionToMimeMapping(): array
    {
        return self::EXTENSION_TO_MIME;
    }
}
