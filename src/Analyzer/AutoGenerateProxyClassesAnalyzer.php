<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Analyzes Doctrine proxy class auto-generation configuration.
 * Detects when auto_generate_proxy_classes is enabled in production,
 * which causes Doctrine to check filesystem on every request.
 * Performance impact:
 * - Filesystem stat() calls on every entity load
 * - 10-30% slower entity initialization
 * - Increased I/O load
 * - Should ALWAYS be disabled in production
 * This is a critical production configuration issue that many developers miss.
 */
class AutoGenerateProxyClassesAnalyzer implements AnalyzerInterface
{
    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
        /**
         * @readonly
         */
        private string $environment = 'prod',
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
                    $config       = $this->entityManager->getConfiguration();
                    $autoGenerate = $config->getAutoGenerateProxyClasses();

                    // Check if we're in production-like environment
                    $isProduction = in_array($this->environment, ['prod', 'production', 'staging'], true);

                    // In Doctrine ORM:
                    // - 0 or false = Never auto-generate (GOOD for production)
                    // - 1 or true = Always auto-generate (BAD for production)
                    // - 2 = Auto-generate on file change (BAD for production)
                    //
                    // Constants (Doctrine\Common\Proxy\AbstractProxyFactory):
                    // - AUTOGENERATE_NEVER = 0
                    // - AUTOGENERATE_ALWAYS = 1
                    // - AUTOGENERATE_FILE_NOT_EXISTS = 2
                    // - AUTOGENERATE_EVAL = 3 (deprecated)

                    if ($isProduction && $this->isAutoGenerateEnabled($autoGenerate)) {
                        yield $this->createAutoGenerateIssue($autoGenerate);
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
                'Doctrine is configured to auto-generate proxy classes in production (mode: %s). ' .
                'This causes Doctrine to check filesystem on EVERY request to see if proxies need regeneration. ' .
                'Performance impact:' . "
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
                setting: 'auto_generate_proxy_classes',
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
