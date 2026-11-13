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
 * Test bootstrap
 *
 * The tested classes only need the plugin configuration and ILIAS base classes.
 * They are replaced by the stubs, so the tests run without an ILIAS installation
 * or database. The plugin autoloader is not used, because it would load the
 * database-backed configuration class.
 */

require_once __DIR__ . '/stubs.stub';

$plugin_dir = dirname(__DIR__);
require_once $plugin_dir . '/classes/platform/class.AIChatPageComponentException.php';
require_once $plugin_dir . '/classes/ai/class.AIChatPageComponentLLM.php';
require_once $plugin_dir . '/classes/ai/class.AIChatPageComponentRAMSES.php';
require_once $plugin_dir . '/classes/ai/class.AIChatPageComponentOpenAI.php';
require_once $plugin_dir . '/classes/ai/class.AIChatPageComponentLLMRegistry.php';
require_once $plugin_dir . '/src/Model/Attachment.php';
