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

namespace ILIAS\Plugin\pcaic\Tests;

use ILIAS\Plugin\pcaic\Validation\FileUploadValidator;
use PHPUnit\Framework\TestCase;
use platform\AIChatPageComponentConfig;

/**
 * Tests the maximum size of uploaded files and its comparison with the PHP limits
 */
class FileUploadLimitTest extends TestCase
{
    protected function tearDown(): void
    {
        AIChatPageComponentConfig::reset();
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function configuredSizeProvider(): array
    {
        return [
            'not configured' => [null, 5],
            'empty' => ['', 5],
            'zero' => [0, 5],
            'negative' => [-3, 5],
            'integer' => [20, 20],
            'stored as text' => ['50', 50],
        ];
    }

    /**
     * @dataProvider configuredSizeProvider
     * @param mixed $configured
     */
    public function testMaxFileSize($configured, int $expected_mb): void
    {
        AIChatPageComponentConfig::reset(['max_file_size_mb' => $configured]);

        $this->assertSame($expected_mb, FileUploadValidator::getMaxFileSizeMb());
        $this->assertSame($expected_mb * 1024 * 1024, FileUploadValidator::getMaxFileSizeBytes());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function iniSizeProvider(): array
    {
        return [
            'megabytes' => ['8M', 8388608],
            'lower case' => ['2m', 2097152],
            'kilobytes' => ['512K', 524288],
            'gigabytes' => ['1G', 1073741824],
            'bytes' => ['1048576', 1048576],
            'unlimited' => ['0', 0],
            'unlimited (-1)' => ['-1', -1],
            'empty' => ['', 0],
        ];
    }

    /**
     * @dataProvider iniSizeProvider
     */
    public function testIniSizeToBytes(string $value, int $expected): void
    {
        $this->assertSame($expected, FileUploadValidator::iniSizeToBytes($value));
    }

    public function testConfiguredSizeIsComparedWithPhpLimit(): void
    {
        $two_mb = 2 * 1024 * 1024;

        $this->assertTrue(FileUploadValidator::exceedsPhpUploadLimit(5, $two_mb));
        $this->assertFalse(FileUploadValidator::exceedsPhpUploadLimit(2, $two_mb));
        $this->assertFalse(FileUploadValidator::exceedsPhpUploadLimit(1, $two_mb));
        $this->assertFalse(FileUploadValidator::exceedsPhpUploadLimit(500, null));
    }

    public function testPhpUploadLimitIsTheSmallerIniValue(): void
    {
        $expected = array_filter([
            FileUploadValidator::iniSizeToBytes((string) ini_get('upload_max_filesize')),
            FileUploadValidator::iniSizeToBytes((string) ini_get('post_max_size')),
        ], static fn(int $bytes): bool => $bytes > 0);

        $this->assertSame($expected === [] ? null : min($expected), FileUploadValidator::getPhpUploadLimitBytes());
    }
}
