<?php

declare(strict_types=1);

namespace Mcp\Capability\Attribute;

if (!class_exists(McpTool::class)) {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class McpTool
    {
        public function __construct(
            public string $name,
            public string $description,
        ) {
        }
    }
}

namespace Symfony\AI\Mate\Bridge\Symfony\Profiler\Service;

use Symfony\Component\HttpKernel\DataCollector\DataCollectorInterface;

if (!interface_exists(CollectorFormatterInterface::class)) {
    /**
     * @template TCollector of DataCollectorInterface
     */
    interface CollectorFormatterInterface
    {
        public function getName(): string;

        /**
         * @param TCollector $collector
         *
         * @return array<string, mixed>
         */
        public function format(DataCollectorInterface $collector): array;

        /**
         * @param TCollector $collector
         *
         * @return array<string, mixed>
         */
        public function getSummary(DataCollectorInterface $collector): array;
    }
}

if (!class_exists(ProfilerDataProvider::class)) {
    final class ProfilerDataProvider
    {
        public function getLatestProfile(): ?ProfilerDataProviderProfile
        {
            return null;
        }

        public function findProfile(string $token): ?ProfilerDataProviderResult
        {
            return null;
        }
    }

    final class ProfilerDataProviderProfile
    {
        public function getToken(): string
        {
            return '';
        }
    }

    final class ProfilerDataProviderResult
    {
        public function getProfile(): ProfilerSnapshot
        {
            return new ProfilerSnapshot();
        }
    }

    final class ProfilerSnapshot
    {
        /**
         * @return list<DataCollectorInterface>
         */
        public function getCollectors(): array
        {
            return [];
        }
    }
}
