<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Security;

use AhmedBhs\DoctrineDoctor\Analyzer\Concern\MetadataAnalyzerTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\SecurityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Yaml\Yaml;

class HardcodedDatabaseCredentialsAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;

    private const string ENV_PATTERN = '/^%env\(.*\)%$/';

    public function __construct(
        private readonly Connection $connection,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly ?string $projectDir = null,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(
            function () {
                $configIssues = $this->analyzeDoctrineConfiguration();
                if ($this->hasDoctrineConfigurationFiles()) {
                    foreach ($configIssues as $issue) {
                        yield $issue;
                    }

                    return;
                }

                $params = $this->connection->getParams();

                if (isset($params['url']) && \is_string($params['url'])) {
                    if (!$this->isEnvVar($params['url'])) {
                        $parsed = parse_url($params['url']);
                        if (\is_array($parsed) && isset($parsed['user'])) {
                            yield $this->createHardcodedUrlIssue($params['url']);
                        }
                    }

                    return;
                }

                $hardcodedFields = [];

                if (isset($params['user']) && \is_string($params['user']) && '' !== $params['user'] && !$this->isEnvVar($params['user'])) {
                    $hardcodedFields[] = 'user';
                }

                if (isset($params['password']) && \is_string($params['password']) && '' !== $params['password'] && !$this->isEnvVar($params['password'])) {
                    $hardcodedFields[] = 'password';
                }

                if (isset($params['host']) && \is_string($params['host']) && '' !== $params['host'] && !$this->isEnvVar($params['host']) && !\in_array($params['host'], ['localhost', '127.0.0.1', '::1'], true)) {
                    $hardcodedFields[] = 'host';
                }

                if ([] !== $hardcodedFields) {
                    yield $this->createHardcodedParamsIssue($hardcodedFields);
                }
            },
        );
    }

    /**
     * @return list<SecurityIssue>
     */
    private function analyzeDoctrineConfiguration(): array
    {
        if (null === $this->projectDir || '' === $this->projectDir) {
            return [];
        }

        $issues = [];

        foreach ([
            $this->projectDir . '/config/packages/doctrine.yaml',
            $this->projectDir . '/config/packages/prod/doctrine.yaml',
        ] as $configPath) {
            if (!is_file($configPath)) {
                continue;
            }

            try {
                $config = Yaml::parseFile($configPath);
            } catch (\Throwable) {
                continue;
            }

            if (!\is_array($config)) {
                continue;
            }

            foreach ($this->extractDbalConfigurations($config) as $dbalConfig) {
                $issue = $this->createIssueFromDbalConfig($dbalConfig);
                if ($issue instanceof SecurityIssue) {
                    $issues[] = $issue;
                }
            }
        }

        return $issues;
    }

    private function hasDoctrineConfigurationFiles(): bool
    {
        if (null === $this->projectDir || '' === $this->projectDir) {
            return false;
        }

        foreach ([
            $this->projectDir . '/config/packages/doctrine.yaml',
            $this->projectDir . '/config/packages/prod/doctrine.yaml',
        ] as $configPath) {
            if (is_file($configPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractDbalConfigurations(array $config): array
    {
        $dbalConfigs = [];

        if (isset($config['doctrine']['dbal']) && \is_array($config['doctrine']['dbal'])) {
            $dbalConfigs[] = $config['doctrine']['dbal'];
        }

        if (isset($config['when@prod']['doctrine']['dbal']) && \is_array($config['when@prod']['doctrine']['dbal'])) {
            $dbalConfigs[] = $config['when@prod']['doctrine']['dbal'];
        }

        return $dbalConfigs;
    }

    /**
     * @param array<string, mixed> $dbalConfig
     */
    private function createIssueFromDbalConfig(array $dbalConfig): ?SecurityIssue
    {
        foreach ($this->flattenConnectionConfigs($dbalConfig) as $connectionConfig) {
            $urlIssue = $this->checkUrlCredentials($connectionConfig);
            if ($urlIssue instanceof SecurityIssue) {
                return $urlIssue;
            }

            $hardcodedFields = $this->detectHardcodedFields($connectionConfig);

            if ([] !== $hardcodedFields) {
                return $this->createHardcodedParamsIssue($hardcodedFields);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $connectionConfig
     */
    private function checkUrlCredentials(array $connectionConfig): ?SecurityIssue
    {
        if (!isset($connectionConfig['url']) || !\is_string($connectionConfig['url'])) {
            return null;
        }

        if ($this->isEnvVar($connectionConfig['url'])) {
            return null;
        }

        $parsed = parse_url($connectionConfig['url']);
        if (\is_array($parsed) && (isset($parsed['user']) || isset($parsed['pass']))) {
            return $this->createHardcodedUrlIssue($connectionConfig['url']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $connectionConfig
     *
     * @return list<string>
     */
    private function detectHardcodedFields(array $connectionConfig): array
    {
        $hardcodedFields = [];

        if ($this->isHardcodedStringField($connectionConfig, 'user')) {
            $hardcodedFields[] = 'user';
        }

        if ($this->isHardcodedStringField($connectionConfig, 'password')) {
            $hardcodedFields[] = 'password';
        }

        if ($this->isHardcodedStringField($connectionConfig, 'host') && !\in_array($connectionConfig['host'], ['localhost', '127.0.0.1', '::1'], true)) {
            $hardcodedFields[] = 'host';
        }

        return $hardcodedFields;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isHardcodedStringField(array $config, string $field): bool
    {
        return isset($config[$field]) && \is_string($config[$field]) && '' !== $config[$field] && !$this->isEnvVar($config[$field]);
    }

    /**
     * @param array<string, mixed> $dbalConfig
     *
     * @return list<array<string, mixed>>
     */
    private function flattenConnectionConfigs(array $dbalConfig): array
    {
        $connections = [];

        if (isset($dbalConfig['connections']) && \is_array($dbalConfig['connections'])) {
            foreach ($dbalConfig['connections'] as $connectionConfig) {
                if (\is_array($connectionConfig)) {
                    $connections[] = $connectionConfig;
                }
            }
        }

        $topLevelConnection = array_intersect_key($dbalConfig, array_flip(['url', 'user', 'password', 'host']));
        if ([] !== $topLevelConnection) {
            $connections[] = $topLevelConnection;
        }

        return $connections;
    }

    private function isEnvVar(string $value): bool
    {
        return 1 === preg_match(self::ENV_PATTERN, $value);
    }

    private function createHardcodedUrlIssue(string $url): SecurityIssue
    {
        $maskedUrl = preg_replace('/:([^@]+)@/', ':****@', $url);

        return new SecurityIssue([
            'title' => 'Hardcoded Database URL',
            'description' => sprintf(
                'The database URL is hardcoded in configuration instead of using an environment variable: "%s". '
                . 'Hardcoded credentials can be leaked through version control, error pages, or config dumps. '
                . 'Use %%env(DATABASE_URL)%% to reference environment variables.',
                $maskedUrl,
            ),
            'severity' => 'critical',
            'suggestion' => $this->createEnvVarSuggestion(),
            'queries' => [],
        ]);
    }

    private function createHardcodedParamsIssue(array $fields): SecurityIssue
    {
        return new SecurityIssue([
            'title' => sprintf('Hardcoded Database Credentials: %s', implode(', ', $fields)),
            'description' => sprintf(
                'The following database connection parameters are hardcoded instead of using environment variables: %s. '
                . 'Hardcoded credentials can be leaked through version control, error pages, or config dumps. '
                . 'Use %%env()%% syntax in Symfony configuration to reference environment variables.',
                implode(', ', $fields),
            ),
            'severity' => 'critical',
            'suggestion' => $this->createEnvVarSuggestion(),
            'queries' => [],
        ]);
    }

    private function createEnvVarSuggestion(): SuggestionInterface
    {
        $code = "# In .env (not committed to VCS):\n";
        $code .= "DATABASE_URL=\"mysql://app_user:secret@127.0.0.1:3306/my_database\"\n\n";
        $code .= "# In config/packages/doctrine.yaml:\n";
        $code .= "doctrine:\n";
        $code .= "    dbal:\n";
        $code .= "        url: '%env(resolve:DATABASE_URL)%'";

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/code_suggestion',
            context: [
                'description' => 'Move database credentials to environment variables',
                'code' => $code,
                'file_path' => 'config/packages/doctrine.yaml',
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::security(),
                severity: Severity::critical(),
                title: 'Use Environment Variables for Database Credentials',
                tags: ['security', 'credentials', 'owasp', 'configuration'],
            ),
        );
    }
}
