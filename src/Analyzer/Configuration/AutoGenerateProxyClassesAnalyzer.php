<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Configuration;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Analyzes Doctrine proxy class auto-generation configuration in PRODUCTION.
 *
 * This analyzer runs in development mode (via Symfony profiler) but specifically
 * checks the PRODUCTION configuration files (config/packages/prod/doctrine.yaml
 * or when@prod blocks) to detect if auto_generate_proxy_classes is enabled.
 *
 * When enabled in production, Doctrine performs filesystem checks on every request:
 * - Filesystem stat() calls on every entity load
 * - 10-30% slower entity initialization
 * - Increased I/O load
 * - Should ALWAYS be disabled in production
 *
 * This is a critical production configuration issue that many developers miss.
 */
class AutoGenerateProxyClassesAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
        /**
         * @readonly
         */
        private string $projectDir,
        /**
         * @readonly
         */
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param QueryDataCollection $queryDataCollection - Not used, config analyzers run independently
     */
    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                try {
                    // Read production configuration from YAML files
                    // Doctrine Doctor runs in dev mode, but checks prod configuration
                    $prodAutoGenerate = $this->readProductionAutoGenerateConfig();

                    // Always log at warning level to ensure visibility during debugging
                    $this->logger?->warning('AutoGenerateProxyClassesAnalyzer: read prod config', [
                        'prodAutoGenerate' => $prodAutoGenerate,
                        'projectDir' => $this->projectDir,
                    ]);

                    // If we couldn't read the config file, skip analysis
                    // We only check production config, not runtime dev config
                    if (null === $prodAutoGenerate) {
                        $this->logger?->warning('AutoGenerateProxyClassesAnalyzer: production config not found, skipping analysis');
                        return;
                    }

                    // In Doctrine ORM:
                    // - 0 or false = Never auto-generate (RECOMMENDED)
                    // - 1 or true = Always auto-generate (NOT RECOMMENDED - performance impact)
                    // - 2 = Auto-generate on file change (NOT RECOMMENDED - file system checks)
                    //
                    // Constants (Doctrine\Common\Proxy\AbstractProxyFactory):
                    // - AUTOGENERATE_NEVER = 0
                    // - AUTOGENERATE_ALWAYS = 1
                    // - AUTOGENERATE_FILE_NOT_EXISTS = 2
                    // - AUTOGENERATE_EVAL = 3 (deprecated)

                    if ($this->isAutoGenerateEnabled($prodAutoGenerate)) {
                        yield $this->createAutoGenerateIssue($prodAutoGenerate);
                    }
                } catch (\Throwable $throwable) {
                    $this->logger?->error('AutoGenerateProxyClassesAnalyzer failed', [
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
     * Reads the auto_generate_proxy_classes configuration from production YAML files.
     * Checks multiple possible locations:
     * - config/packages/prod/doctrine.yaml (dedicated prod file)
     * - config/packages/doctrine.yaml (when@prod override OR global fallback)
     *
     * If when@prod doesn't override the setting, returns the global value
     * (since that's what Symfony will use in production).
     *
     * @return int|null The auto_generate value from prod config, or null if not found
     */
    private function readProductionAutoGenerateConfig(): ?int
    {
        $configPaths = [
            $this->projectDir . '/config/packages/prod/doctrine.yaml',
            $this->projectDir . '/config/packages/doctrine.yaml',
        ];

        $this->logger?->warning('AutoGenerateProxyClassesAnalyzer: checking config paths', [
            'paths' => $configPaths,
        ]);

        foreach ($configPaths as $configPath) {
            if (!file_exists($configPath)) {
                $this->logger?->warning('AutoGenerateProxyClassesAnalyzer: config file not found', [
                    'path' => $configPath,
                ]);
                continue;
            }

            $this->logger?->warning('AutoGenerateProxyClassesAnalyzer: parsing config file', [
                'path' => $configPath,
            ]);

            try {
                $config = Yaml::parseFile($configPath);

                if (!is_array($config)) {
                    $this->logger?->warning('AutoGenerateProxyClassesAnalyzer: config is not array', [
                        'path' => $configPath,
                        'type' => get_debug_type($config),
                    ]);
                    continue;
                }

                // Priority 1: Check for when@prod syntax first (explicit production override)
                if (isset($config['when@prod'])
                    && is_array($config['when@prod'])
                    && isset($config['when@prod']['doctrine'])
                    && is_array($config['when@prod']['doctrine'])
                    && isset($config['when@prod']['doctrine']['orm'])
                    && is_array($config['when@prod']['doctrine']['orm'])
                    && isset($config['when@prod']['doctrine']['orm']['auto_generate_proxy_classes'])) {
                    $value = $this->normalizeAutoGenerateValue(
                        $config['when@prod']['doctrine']['orm']['auto_generate_proxy_classes'],
                    );
                    $this->logger?->warning('AutoGenerateProxyClassesAnalyzer: found when@prod config', [
                        'path'  => $configPath,
                        'value' => $value,
                        'raw_value' => $config['when@prod']['doctrine']['orm']['auto_generate_proxy_classes'],
                    ]);

                    return $value;
                }

                // Priority 2: For prod/doctrine.yaml, check direct config
                if (str_contains($configPath, '/prod/')
                    && isset($config['doctrine'])
                    && is_array($config['doctrine'])
                    && isset($config['doctrine']['orm'])
                    && is_array($config['doctrine']['orm'])
                    && isset($config['doctrine']['orm']['auto_generate_proxy_classes'])) {
                    $value = $this->normalizeAutoGenerateValue(
                        $config['doctrine']['orm']['auto_generate_proxy_classes'],
                    );
                    $this->logger?->warning('AutoGenerateProxyClassesAnalyzer: found prod/doctrine.yaml config', [
                        'path'  => $configPath,
                        'value' => $value,
                        'raw_value' => $config['doctrine']['orm']['auto_generate_proxy_classes'],
                    ]);

                    return $value;
                }

                // Priority 3: Fallback to global config (what Symfony uses when when@prod doesn't override)
                // This is important! If when@prod exists but doesn't override auto_generate_proxy_classes,
                // Symfony will use the global value in production
                if (isset($config['doctrine'])
                    && is_array($config['doctrine'])
                    && isset($config['doctrine']['orm'])
                    && is_array($config['doctrine']['orm'])
                    && isset($config['doctrine']['orm']['auto_generate_proxy_classes'])) {
                    $value = $this->normalizeAutoGenerateValue(
                        $config['doctrine']['orm']['auto_generate_proxy_classes'],
                    );
                    $this->logger?->warning('AutoGenerateProxyClassesAnalyzer: found global config (will be used in prod)', [
                        'path'  => $configPath,
                        'value' => $value,
                        'raw_value' => $config['doctrine']['orm']['auto_generate_proxy_classes'],
                        'note' => 'No when@prod override found, global config applies to production',
                    ]);

                    return $value;
                }

                $this->logger?->warning('AutoGenerateProxyClassesAnalyzer: auto_generate_proxy_classes not found in config', [
                    'path'        => $configPath,
                    'has_when_prod' => isset($config['when@prod']),
                    'has_doctrine' => isset($config['doctrine']),
                ]);
            } catch (\Throwable $throwable) {
                $this->logger?->warning('Failed to parse config file', [
                    'file'      => $configPath,
                    'exception' => $throwable::class,
                    'message'   => $throwable->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Normalizes the auto_generate_proxy_classes value to an integer.
     * Handles: true/false, 0/1/2/3, string values.
     */
    private function normalizeAutoGenerateValue(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value)) {
            return $value;
        }

        // Handle string values like "0", "1", "true", "false"
        if (is_string($value)) {
            if (in_array(strtolower($value), ['true', 'yes', 'on'], true)) {
                return 1;
            }
            if (in_array(strtolower($value), ['false', 'no', 'off'], true)) {
                return 0;
            }

            return (int) $value;
        }

        return 0;
    }

    private function isAutoGenerateEnabled(int $autoGenerate): bool
    {
        // Check if auto-generation is enabled
        // 0 = disabled (good)
        // 1, 2, 3 = enabled (bad)
        return 0 !== $autoGenerate;
    }

    private function createAutoGenerateIssue(int $autoGenerate): DatabaseConfigIssue
    {
        $mode = $this->getAutoGenerateModeName($autoGenerate);

        return new DatabaseConfigIssue([
            'title'       => 'Proxy Auto-Generation Enabled in Production',
            'description' => sprintf(
                'Your PRODUCTION Doctrine configuration has auto_generate_proxy_classes enabled (mode: %s). ' .
                'This causes Doctrine to check filesystem on EVERY request to see if proxies need regeneration. ' .
                'Performance impact in production:' . "
" .
                '- Filesystem stat() calls on every entity load' . "
" .
                '- 10-30%% slower entity initialization' . "
" .
                '- Unnecessary I/O operations' . "
" .
                '- Wasted CPU cycles' . "

" .
                'Proxy classes should be pre-generated during deployment, not at runtime.',
                $mode,
            ),
            'severity'   => 'critical',
            'suggestion' => $this->suggestionFactory->createConfiguration(
                setting: 'auto_generate_proxy_classes (PRODUCTION)',
                currentValue: $mode,
                recommendedValue: 'false (AUTOGENERATE_NEVER)',
                description: 'Disable proxy auto-generation in production for better performance',
                fixCommand: $this->getFixCommand(),
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function getAutoGenerateModeName(int $autoGenerate): string
    {
        return match ($autoGenerate) {
            0       => 'AUTOGENERATE_NEVER (0)',
            1       => 'AUTOGENERATE_ALWAYS (1)',
            2       => 'AUTOGENERATE_FILE_NOT_EXISTS (2)',
            3       => 'AUTOGENERATE_EVAL (3) - deprecated',
            default => sprintf('Unknown mode (%d)', $autoGenerate),
        };
    }

    private function getFixCommand(): string
    {
        return <<<'YAML'
            # ===== PRODUCTION CONFIGURATION =====
            # config/packages/prod/doctrine.yaml
            doctrine:
                orm:
                    # CRITICAL: Disable auto-generation in production
                    auto_generate_proxy_classes: false

            # ===== DEVELOPMENT CONFIGURATION =====
            # config/packages/dev/doctrine.yaml
            doctrine:
                orm:
                    #  OK: Enable auto-generation in development for convenience
                    auto_generate_proxy_classes: true

            # ===== DEPLOYMENT WORKFLOW =====
            # Add these commands to your deployment script:

            # 1. Clear all caches
            php bin/console cache:clear --env=prod --no-warmup

            # 2. Generate proxy classes
            php bin/console doctrine:cache:clear-metadata --env=prod
            php bin/console doctrine:cache:clear-query --env=prod

            # 3. Warm up cache (this generates proxies)
            php bin/console cache:warmup --env=prod

            # 4. Ensure proxy directory has correct permissions
            chmod -R 755 var/cache/prod/doctrine/orm/Proxies

            # 5. Deploy and restart web server
            # systemctl reload php8.2-fpm
            # or
            # systemctl restart nginx

            # ===== DOCKER DEPLOYMENT =====
            # In your Dockerfile:
            RUN php bin/console cache:clear --env=prod \
                && php bin/console cache:warmup --env=prod \
                && chmod -R 755 var/cache

            # ===== VERIFICATION =====
            # After deployment, verify proxies exist:
            ls -la var/cache/prod/doctrine/orm/Proxies/

            # You should see files like:
            # - __CG__AppEntityUser.php
            # - __CG__AppEntityProduct.php
            # etc.

            # ===== WHY THIS MATTERS =====
            # With auto_generate = true:
            #   - Every entity load: stat() system call on proxy file
            #   - Checking modification time: filemtime()
            #   - Comparing with source: more I/O
            #   - Result: 10-30% slower entity loading
            #
            # With auto_generate = false:
            #   - Proxy files loaded directly
            #   - No filesystem checks
            #   - Maximum performance
            #   - Result: Optimal speed

            # ===== TROUBLESHOOTING =====
            # If you get "Proxy class not found" errors after deployment:
            # 1. Check var/cache/prod/doctrine/orm/Proxies/ exists
            # 2. Run: php bin/console cache:warmup --env=prod
            # 3. Check file permissions (should be readable by web server)
            # 4. Verify doctrine.yaml has correct config per environment
            YAML;
    }
}
