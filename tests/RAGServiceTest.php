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

use ai\AIChatPageComponentRAG;
use PHPUnit\Framework\TestCase;
use platform\AIChatPageComponentConfig;

/**
 * Tests the parts of the RAG service that do not require HTTP requests
 */
class RAGServiceTest extends TestCase
{
    protected function setUp(): void
    {
        AIChatPageComponentConfig::reset();
    }

    /**
     * @dataProvider citationProvider
     */
    public function testConvertCitationMarkers(string $input, string $expected): void
    {
        $this->assertSame($expected, AIChatPageComponentRAG::convertCitationMarkers($input));
    }

    public static function citationProvider(): array
    {
        return [
            'single' => ['The deadline is two weeks[cit-0].', 'The deadline is two weeks[1].'],
            'separate brackets' => ['Paris[cit-1][cit-0]', 'Paris[2][1]'],
            'comma separated' => ['A [cit-0, cit-2] B', 'A [1][3] B'],
            'case insensitive' => ['[CIT-9]', '[10]'],
            'other brackets untouched' => ['Keep [1] and [x]', 'Keep [1] and [x]'],
        ];
    }

    public function testChunksToSourcesKeepsReferenceOrder(): void
    {
        $sources = AIChatPageComponentRAG::chunksToSources([
            ['reference_index' => 1, 'filename' => 'b.txt', 'text' => 'B', 'metadata' => ['type' => 'plain']],
            ['reference_index' => 0, 'filename' => 'a.txt', 'text' => 'A', 'metadata' => ['type' => 'plain']],
        ]);

        $this->assertSame(['a.txt', 'b.txt'], array_column($sources, 'filename'));
    }

    public function testChunksToSourcesReadsMetadata(): void
    {
        $sources = AIChatPageComponentRAG::chunksToSources([
            [
                'reference_index' => 0,
                'filename' => 'script.pdf',
                'file_id' => 'f1',
                'collection_id' => 'c1',
                'text' => 'Text',
                'metadata' => ['type' => 'paginated', 'page_numbers' => [3, 4], 'page_count' => 9],
            ],
            [
                'reference_index' => 1,
                'filename' => 'https://example.org/page',
                'text' => 'Web',
                'metadata' => ['type' => 'website', 'url' => 'https://example.org/page', 'hierarchy' => []],
            ],
        ]);

        $this->assertSame([3, 4], $sources[0]['page_numbers']);
        $this->assertSame('f1', $sources[0]['file_id']);
        $this->assertNull($sources[0]['url']);
        $this->assertSame('https://example.org/page', $sources[1]['url']);
        $this->assertSame([], $sources[1]['page_numbers']);
    }

    public function testChunksToSourcesDecodesHtmlEncodedFilenames(): void
    {
        $sources = AIChatPageComponentRAG::chunksToSources([
            ['reference_index' => 0, 'filename' => 'Getr&#228;nkekarte &amp; Preise.pdf', 'text' => '', 'metadata' => []],
        ]);

        $this->assertSame('Getränkekarte & Preise.pdf', $sources[0]['filename']);
    }

    public function testNumericIdsAreStableIntegers(): void
    {
        AIChatPageComponentConfig::set('rag_application_id', 'ILIAS');
        AIChatPageComponentConfig::set('rag_instance_id', 'ilias9');

        $first = AIChatPageComponentRAG::getNumericIds('chat_123');
        $second = AIChatPageComponentRAG::getNumericIds('chat_123');

        $this->assertSame($first, $second);
        $this->assertContainsOnly('int', $first);
        $this->assertNotSame($first[2], AIChatPageComponentRAG::getNumericIds('chat_456')[2]);
    }

    public function testNumericIdsDependOnConfiguration(): void
    {
        AIChatPageComponentConfig::set('rag_instance_id', 'ilias9');
        $default = AIChatPageComponentRAG::getNumericIds('chat_123');

        AIChatPageComponentConfig::set('rag_instance_id', 'other');
        $changed = AIChatPageComponentRAG::getNumericIds('chat_123');

        $this->assertSame($default[0], $changed[0]);
        $this->assertNotSame($default[1], $changed[1]);
    }

    public function testFileTypesDefaultAndConfigured(): void
    {
        $this->assertSame(['txt', 'csv', 'pdf'], AIChatPageComponentRAG::getFileTypes());

        AIChatPageComponentConfig::set('rag_allowed_file_types', ' PDF, txt ,,');
        $this->assertSame(['pdf', 'txt'], AIChatPageComponentRAG::getFileTypes());

        AIChatPageComponentConfig::set('rag_allowed_file_types', ['pdf']);
        $this->assertSame(['pdf'], AIChatPageComponentRAG::getFileTypes());
    }

    public function testUnauthorizedCollectionsAreDetected(): void
    {
        // Response of the WebGateway for a collection of another tenant
        $response = '{"error":"{\\"detail\\":\\"Invalid required parameter: An error occurred in pipeline step '
            . "'VectorSearchRetrievalStep': Invalid parameter error in step 'VectorSearchRetrievalStep': Unauthorized "
            . "access to collection IDs ['app_1_instance_2_entity_3'] for tenant 00000000-0000-0000-0000-000000000000"
            . '\\"}"}';

        $this->assertSame(
            ['app_1_instance_2_entity_3'],
            AIChatPageComponentRAG::parseUnauthorizedCollections($response, ['app_1_instance_2_entity_3', 'app_1_instance_2_entity_4'])
        );
    }

    public function testUnreadableRejectionAffectsAllRequestedCollections(): void
    {
        $this->assertSame(
            ['a', 'b'],
            AIChatPageComponentRAG::parseUnauthorizedCollections('{"error":"Unauthorized access to collection"}', ['a', 'b'])
        );
    }

    public function testOtherErrorsAreNoRejection(): void
    {
        $this->assertNull(AIChatPageComponentRAG::parseUnauthorizedCollections('{"detail":"last message must be user"}', ['a']));
    }

    public function testAvailabilityRequiresEnabledUrlAndKey(): void
    {
        $this->assertFalse(AIChatPageComponentRAG::isAvailable());

        AIChatPageComponentConfig::set('rag_service_enabled', '1');
        AIChatPageComponentConfig::set('rag_api_url', 'https://rag.example.org/');
        $this->assertFalse(AIChatPageComponentRAG::isAvailable());

        AIChatPageComponentConfig::set('rag_client_key', 'key');
        $this->assertTrue(AIChatPageComponentRAG::isAvailable());
        $this->assertSame('https://rag.example.org', AIChatPageComponentRAG::getApiUrl());

        AIChatPageComponentConfig::set('rag_service_enabled', '0');
        $this->assertFalse(AIChatPageComponentRAG::isAvailable());
    }

    public function testChunkCountIsReadFromStatusResponse(): void
    {
        $this->assertSame(42, AIChatPageComponentRAG::extractChunkCount(['status' => 'COMPLETED', 'chunk_count' => 42]));
        $this->assertSame(0, AIChatPageComponentRAG::extractChunkCount(['status' => 'COMPLETED', 'num_chunks' => '0']));
        $this->assertSame(2, AIChatPageComponentRAG::extractChunkCount(['chunks' => [['text' => 'a'], ['text' => 'b']]]));
        $this->assertSame(0, AIChatPageComponentRAG::extractChunkCount(['chunks' => []]));
    }

    public function testChunkCountIsUnknownIfNotReported(): void
    {
        $this->assertNull(AIChatPageComponentRAG::extractChunkCount(['status' => 'COMPLETED', 'error_message' => null]));
        $this->assertNull(AIChatPageComponentRAG::extractChunkCount(['chunk_count' => null]));
        $this->assertNull(AIChatPageComponentRAG::extractChunkCount(['chunk_count' => 'many']));
    }

    public function testProbeFindsChunkOfFile(): void
    {
        $chunks = [['file_id' => 'other', 'text' => 'a'], ['file_id' => 'f1', 'text' => 'b']];
        $this->assertTrue(AIChatPageComponentRAG::evaluateProbe($chunks, 'f1', 20));
        // Found even if the result is full
        $this->assertTrue(AIChatPageComponentRAG::evaluateProbe($chunks, 'f1', 2));
    }

    public function testProbeWithoutChunkOfFileIsEmptyOnlyIfResultIsNotFull(): void
    {
        $this->assertFalse(AIChatPageComponentRAG::evaluateProbe([], 'f1', 20));
        $this->assertFalse(AIChatPageComponentRAG::evaluateProbe([['file_id' => 'other']], 'f1', 20));
        // Other files may have displaced the file from a full result
        $this->assertNull(AIChatPageComponentRAG::evaluateProbe([['file_id' => 'a'], ['file_id' => 'b']], 'f1', 2));
    }

    public function testProbeIsUndecidedIfChunksHaveNoFileId(): void
    {
        $this->assertNull(AIChatPageComponentRAG::evaluateProbe([['text' => 'a']], 'f1', 20));
    }
}
