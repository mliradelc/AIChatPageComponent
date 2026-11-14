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
 * Registry of the available AI services
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class AIChatPageComponentLLMRegistry
{
    /**
     * All registered AI services
     *
     * A new service is added here; configuration tab, service selection and routing
     * are derived from this list.
     *
     * @return array<string, class-string<AIChatPageComponentLLM>> service ID => class name
     */
    public static function getAvailableServices(): array
    {
        return [
            'ramses' => AIChatPageComponentRAMSES::class,
            'openai' => AIChatPageComponentOpenAI::class,
        ];
    }

    /**
     * Services enabled in the plugin configuration
     *
     * @return array<string, class-string<AIChatPageComponentLLM>> service ID => class name
     */
    public static function getEnabledServices(): array
    {
        $available = self::getAvailableServices();
        $enabled = [];

        foreach ($available as $service_id => $service_class) {
            $config_key = $service_id . '_service_enabled';
            $is_enabled = \platform\AIChatPageComponentConfig::get($config_key);

            if ($is_enabled === '1') {
                $enabled[$service_id] = $service_class;
            }
        }

        return $enabled;
    }

    /**
     * @return class-string<AIChatPageComponentLLM>|null
     */
    public static function getServiceClass(string $service_id): ?string
    {
        $services = self::getAvailableServices();
        return $services[$service_id] ?? null;
    }

    public static function serviceExists(string $service_id): bool
    {
        return isset(self::getAvailableServices()[$service_id]);
    }

    public static function isServiceEnabled(string $service_id): bool
    {
        if (!self::serviceExists($service_id)) {
            return false;
        }

        $config_key = $service_id . '_service_enabled';
        $is_enabled = \platform\AIChatPageComponentConfig::get($config_key);

        return $is_enabled === '1';
    }

    /**
     * Create a configured service instance (via fromConfig() if available)
     */
    public static function createServiceInstance(string $service_id): ?AIChatPageComponentLLM
    {
        $service_class = self::getServiceClass($service_id);

        if ($service_class === null) {
            return null;
        }

        if (method_exists($service_class, 'fromConfig')) {
            return $service_class::fromConfig();
        }

        return new $service_class();
    }

    /**
     * Create a service instance without requiring a complete configuration
     *
     * Used by the configuration forms, where the service may not be configured yet.
     */
    public static function createBareServiceInstance(string $service_id): ?AIChatPageComponentLLM
    {
        $service_class = self::getServiceClass($service_id);

        if ($service_class === null) {
            return null;
        }

        return new $service_class();
    }

    public static function getServiceCapabilities(string $service_id): array
    {
        $instance = self::createServiceInstance($service_id);

        if ($instance === null) {
            return [];
        }

        return $instance->getCapabilities();
    }

    /**
     * Services as select options
     *
     * @return array<string, string> service ID => service name
     */
    public static function getServiceOptions(bool $only_enabled = false): array
    {
        $services = $only_enabled ? self::getEnabledServices() : self::getAvailableServices();
        $options = [];

        foreach ($services as $service_id => $service_class) {
            $options[$service_id] = $service_class::getDisplayName();
        }

        return $options;
    }
}
