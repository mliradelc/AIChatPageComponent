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

use ai\AIChatPageComponentLLMRegistry;
use ai\AIChatPageComponentOpenAI;
use ai\AIChatPageComponentRAMSES;
use PHPUnit\Framework\TestCase;
use platform\AIChatPageComponentConfig;

/**
 * Tests the names of the AI services shown in tabs and selections
 */
class ServiceDisplayNameTest extends TestCase
{
    protected function tearDown(): void
    {
        AIChatPageComponentConfig::reset();
    }

    public function testBuiltInNamesAreUsedByDefault(): void
    {
        AIChatPageComponentConfig::reset();

        $this->assertSame('KI:connect.nrw', AIChatPageComponentRAMSES::getDisplayName());
        $this->assertSame('OpenAI GPT', AIChatPageComponentOpenAI::getDisplayName());
    }

    public function testConfiguredNameReplacesBuiltInName(): void
    {
        AIChatPageComponentConfig::reset([
            'ramses_service_label' => ' RAMSES (kiez) ',
            'openai_service_label' => 'KI:connect.nrw',
        ]);

        $this->assertSame('RAMSES (kiez)', AIChatPageComponentRAMSES::getDisplayName());
        $this->assertSame('KI:connect.nrw', AIChatPageComponentOpenAI::getDisplayName());
        // The built-in name and the service ID stay as they are
        $this->assertSame('KI:connect.nrw', AIChatPageComponentRAMSES::getServiceName());
        $this->assertSame('ramses', AIChatPageComponentRAMSES::getServiceId());
    }

    public function testBlankNameFallsBackToBuiltInName(): void
    {
        AIChatPageComponentConfig::reset(['ramses_service_label' => '   ', 'openai_service_label' => '']);

        $this->assertSame('KI:connect.nrw', AIChatPageComponentRAMSES::getDisplayName());
        $this->assertSame('OpenAI GPT', AIChatPageComponentOpenAI::getDisplayName());
    }

    public function testServiceSelectionUsesConfiguredNames(): void
    {
        AIChatPageComponentConfig::reset([
            'ramses_service_enabled' => '1',
            'openai_service_enabled' => '0',
            'ramses_service_label' => 'RAMSES (kiez)',
        ]);

        $this->assertSame(
            ['ramses' => 'RAMSES (kiez)', 'openai' => 'OpenAI GPT'],
            AIChatPageComponentLLMRegistry::getServiceOptions()
        );
        $this->assertSame(['ramses' => 'RAMSES (kiez)'], AIChatPageComponentLLMRegistry::getServiceOptions(true));
    }
}
