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

use ai\AIChatPageComponentRAGStatus;
use PHPUnit\Framework\TestCase;

/**
 * Tests the retry delays and the mapping of RAG processing states
 */
class RAGStatusTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function retryDelayProvider(): array
    {
        return [
            'no failure' => [0, 300],
            'first failure' => [1, 300],
            'second failure' => [2, 600],
            'fifth failure' => [5, 4800],
            'seventh failure' => [7, 19200],
            'capped' => [8, 21600],
            'large count stays capped' => [1000, 21600],
        ];
    }

    /**
     * @dataProvider retryDelayProvider
     */
    public function testRetryDelay(int $failed_count, int $expected): void
    {
        $this->assertSame($expected, AIChatPageComponentRAGStatus::retryDelay($failed_count));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function remoteStatusProvider(): array
    {
        return [
            'completed' => ['COMPLETED', AIChatPageComponentRAGStatus::COMPLETED],
            'lower case' => ['completed', AIChatPageComponentRAGStatus::COMPLETED],
            'failed' => ['FAILED', AIChatPageComponentRAGStatus::FAILED],
            'pending' => ['PENDING', AIChatPageComponentRAGStatus::PROCESSING],
            'processing' => ['PROCESSING', AIChatPageComponentRAGStatus::PROCESSING],
            'unknown' => ['SOMETHING_NEW', AIChatPageComponentRAGStatus::PROCESSING],
        ];
    }

    /**
     * @dataProvider remoteStatusProvider
     */
    public function testMapRemoteStatus(string $remote, string $expected): void
    {
        $this->assertSame($expected, AIChatPageComponentRAGStatus::mapRemoteStatus($remote));
    }

    /**
     * @return array<string, array{int|null, bool|null, bool|null, string}>
     */
    public static function completedStateProvider(): array
    {
        return [
            'chunks reported' => [12, null, null, AIChatPageComponentRAGStatus::COMPLETED],
            'no chunks reported' => [0, null, null, AIChatPageComponentRAGStatus::EMPTY],
            'chunk count wins over text check' => [3, false, false, AIChatPageComponentRAGStatus::COMPLETED],
            'no count, file with text' => [null, true, null, AIChatPageComponentRAGStatus::COMPLETED],
            'file with text is never probed empty' => [null, true, false, AIChatPageComponentRAGStatus::COMPLETED],
            'nothing known' => [null, null, null, AIChatPageComponentRAGStatus::COMPLETED],
            'scan, retrieval finds it' => [null, false, true, AIChatPageComponentRAGStatus::SCANNED],
            'scan, retrieval undecided' => [null, false, null, AIChatPageComponentRAGStatus::SCANNED],
            'scan, retrieval finds nothing' => [null, false, false, AIChatPageComponentRAGStatus::EMPTY],
        ];
    }

    /**
     * @dataProvider completedStateProvider
     */
    public function testCompletedState(?int $chunks, ?bool $has_text, ?bool $probe, string $expected): void
    {
        $this->assertSame($expected, AIChatPageComponentRAGStatus::completedState($chunks, $has_text, $probe));
    }

    public function testPdfWithFontHasText(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Page /Resources << /Font << /F1 2 0 R >> >> >>\nendobj\n%%EOF";
        $this->assertTrue(AIChatPageComponentRAGStatus::hasText($pdf, 'pdf'));
    }

    public function testImageOnlyPdfHasNoText(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Page /Resources << /XObject << /Im0 2 0 R >> >> >>\nendobj\n"
            . "2 0 obj\n<< /Type /XObject /Subtype /Image /Filter /DCTDecode /Length 4 >>\nstream\n\xff\xd8\xff\xd9\nendstream\nendobj\n%%EOF";
        $this->assertFalse(AIChatPageComponentRAGStatus::hasText($pdf, 'PDF'));
    }

    public function testPdfFontInObjectStreamIsFound(): void
    {
        $objects = gzcompress("3 0 << /Type /Page /Resources << /Font << /F1 4 0 R >> >> >>");
        $pdf = "%PDF-1.5\n1 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length " . strlen($objects) . " >>\n"
            . "stream\r\n" . $objects . "\nendstream\nendobj\n%%EOF";
        $this->assertTrue(AIChatPageComponentRAGStatus::hasText($pdf, 'pdf'));

        $images = gzcompress("3 0 << /Type /Page /Resources << /XObject << /Im0 4 0 R >> >> >>");
        $pdf = "%PDF-1.5\n1 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length " . strlen($images) . " >>\n"
            . "stream\n" . $images . "\nendstream\nendobj\n%%EOF";
        $this->assertFalse(AIChatPageComponentRAGStatus::hasText($pdf, 'pdf'));
    }

    public function testUnreadablePdfIsUndecided(): void
    {
        $encrypted = "%PDF-1.6\ntrailer\n<< /Encrypt 5 0 R >>\n%%EOF";
        $this->assertNull(AIChatPageComponentRAGStatus::hasText($encrypted, 'pdf'));

        $broken = "%PDF-1.5\n1 0 obj\n<< /Type /ObjStm /Filter /FlateDecode >>\nstream\nnot compressed\nendstream\nendobj\n%%EOF";
        $this->assertNull(AIChatPageComponentRAGStatus::hasText($broken, 'pdf'));

        $this->assertNull(AIChatPageComponentRAGStatus::hasText('no pdf header', 'pdf'));
        $this->assertNull(AIChatPageComponentRAGStatus::hasText('PK...', 'docx'));
    }

    public function testTextFiles(): void
    {
        $this->assertTrue(AIChatPageComponentRAGStatus::hasText("a;b\n1;2\n", 'csv'));
        $this->assertFalse(AIChatPageComponentRAGStatus::hasText(" \n\t", 'txt'));
    }
}
