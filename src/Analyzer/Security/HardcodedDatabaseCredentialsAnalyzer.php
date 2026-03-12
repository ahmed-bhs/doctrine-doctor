<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Security;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\SecurityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\DBAL\Connection;

class HardcodedDatabaseCredentialsAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    private const string ENV_PATTERN = '/^%env\(.*\)%$/';

    public function __construct(
        private readonly Connection $connection,
        private readonly SuggestionFactoryInterface $suggestionFactory,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            function () {
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
