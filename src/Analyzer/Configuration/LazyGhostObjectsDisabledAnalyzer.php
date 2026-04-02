<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Configuration;

use AhmedBhs\DoctrineDoctor\Analyzer\Concern\MetadataAnalyzerTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Yaml\Yaml;

/**
 * Detects when enable_lazy_ghost_objects is not enabled in Doctrine ORM configuration (Symfony 6.2+).
 *
 * Since Symfony 6.2, Doctrine supports Lazy Ghost Objects as a more efficient proxy mechanism.
 * Ghost objects are faster and avoid classic proxy pitfalls (final classes, constructor side effects).
 *
 * When this option is disabled (or absent), Doctrine falls back to classic proxies which are:
 * - Less efficient in memory and CPU usage
 * - Have limitations with final classes
 * - Can trigger unintended side effects in constructors
 *
 * This analyzer only runs on Symfony 6.2+.
 */
class LazyGhostObjectsDisabledAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;

    private const NATIVE_LAZY_OBJECTS_KEY = 'enable_native_lazy_objects';

    /**
     * @param (\Closure(): ?string)|null $doctrineBundleVersionResolver
     */
    public function __construct(
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly string $projectDir,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?\Closure $doctrineBundleVersionResolver = null,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                try {
                    if (version_compare(Kernel::VERSION, '6.2.0', '<')) {
                        $this->logger?->info('LazyGhostObjectsDisabledAnalyzer: Symfony < 6.2, skipping analysis');
                        return;
                    }

                    if ($this->areNativeLazyObjectsAlwaysEnabled()) {
                        $this->logger?->info('LazyGhostObjectsDisabledAnalyzer: native lazy objects are always enabled on this DoctrineBundle version');
                        return;
                    }

                    $enabled = $this->readNativeLazyObjectsConfig();

                    if (null === $enabled) {
                        $this->logger?->info('LazyGhostObjectsDisabledAnalyzer: no explicit native lazy objects config found');
                        return;
                    }

                    if (true === $enabled) {
                        $this->logger?->info('LazyGhostObjectsDisabledAnalyzer: native lazy objects already enabled');
                        return;
                    }

                    yield $this->createIssue();
                } catch (\Throwable $throwable) {
                    $this->logger?->error('LazyGhostObjectsDisabledAnalyzer failed', [
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'file' => $throwable->getFile(),
                        'line' => $throwable->getLine(),
                    ]);
                }
            },
        );
    }

    /**
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    private function readNativeLazyObjectsConfig(): ?bool
    {
        $configPaths = [
            $this->projectDir . '/config/packages/prod/doctrine.yaml',
            $this->projectDir . '/config/packages/doctrine.yaml',
        ];

        foreach ($configPaths as $configPath) {
            if (!file_exists($configPath)) {
                $this->logger?->debug('LazyGhostObjectsDisabledAnalyzer: config file not found', [
                    'path' => $configPath,
                ]);
                continue;
            }

            $result = $this->parseConfigFile($configPath);

            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }

    private function parseConfigFile(string $configPath): ?bool
    {
        try {
            $config = Yaml::parseFile($configPath);

            if (!is_array($config)) {
                $this->logger?->debug('LazyGhostObjectsDisabledAnalyzer: config is not array', [
                    'path' => $configPath,
                    'type' => get_debug_type($config),
                ]);

                return null;
            }

            return $this->extractLazyGhostValue($config, $configPath);
        } catch (\Throwable $throwable) {
            $this->logger?->warning('LazyGhostObjectsDisabledAnalyzer: failed to parse config file', [
                'file' => $configPath,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    private function extractLazyGhostValue(array $config, string $configPath): ?bool
    {
        $lookupPaths = [
            ['when@prod', 'doctrine', 'orm', self::NATIVE_LAZY_OBJECTS_KEY],
        ];

        if (str_contains($configPath, '/prod/')) {
            $lookupPaths[] = ['doctrine', 'orm', self::NATIVE_LAZY_OBJECTS_KEY];
        }

        $lookupPaths[] = ['doctrine', 'orm', self::NATIVE_LAZY_OBJECTS_KEY];

        foreach ($lookupPaths as $keys) {
            $value = $this->getNestedValue($config, $keys);

            if (null !== $value) {
                $normalized = $this->normalizeBoolValue($value);
                $this->logger?->debug('LazyGhostObjectsDisabledAnalyzer: found config', [
                    'path' => $configPath,
                    'keys' => implode('.', $keys),
                    'value' => $normalized ? 'true' : 'false',
                ]);

                return $normalized;
            }
        }

        $this->logger?->debug('LazyGhostObjectsDisabledAnalyzer: enable_native_lazy_objects not found in config', [
            'path' => $configPath,
        ]);

        return null;
    }

    private function getNestedValue(array $config, array $keys): mixed
    {
        $current = $config;

        foreach ($keys as $key) {
            if (!is_array($current) || !isset($current[$key])) {
                return null;
            }

            $current = $current[$key];
        }

        return $current;
    }

    private function normalizeBoolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return 0 !== $value;
        }

        if (is_string($value)) {
            if (in_array(strtolower($value), ['true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array(strtolower($value), ['false', 'no', 'off'], true)) {
                return false;
            }

            return (bool) (int) $value;
        }

        return false;
    }

    private function createIssue(): DatabaseConfigIssue
    {
        return new DatabaseConfigIssue([
            'title' => 'Native lazy objects not enabled',
            'description' => 'Doctrine ORM native lazy objects are explicitly disabled in your configuration. '
                . 'Enable them to use the modern proxy mechanism with better performance and compatibility.',
            'severity' => Severity::info(),
            'suggestion' => $this->suggestionFactory->createFromTemplate(
                templateName: 'Configuration/lazy_ghost_objects_disabled',
                context: [],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::configuration(),
                    severity: Severity::info(),
                    title: 'Enable native lazy objects for better performance',
                    tags: ['configuration', 'performance'],
                ),
            ),
            'backtrace' => null,
            'queries' => [],
        ]);
    }

    private function areNativeLazyObjectsAlwaysEnabled(): bool
    {
        $version = $this->resolveDoctrineBundleVersion();

        return null !== $version && version_compare($version, '3.1.0', '>=');
    }

    private function resolveDoctrineBundleVersion(): ?string
    {
        if (null === $this->doctrineBundleVersionResolver) {
            return null;
        }

        return ($this->doctrineBundleVersionResolver)();
    }
}
