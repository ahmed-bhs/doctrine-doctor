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

class OverprivilegedDatabaseUserAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    private const array PRIVILEGED_USERS = ['root', 'postgres', 'admin', 'sa'];

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

                $user = null;
                $password = null;

                if (isset($params['url']) && \is_string($params['url'])) {
                    $parsed = parse_url($params['url']);
                    if (\is_array($parsed)) {
                        $user = isset($parsed['user']) ? urldecode($parsed['user']) : null;
                        $password = isset($parsed['pass']) ? urldecode($parsed['pass']) : null;
                    }
                }

                $user ??= $params['user'] ?? null;
                $password ??= $params['password'] ?? null;

                if (!\is_string($user) || '' === $user) {
                    yield $this->createEmptyUserIssue();

                    return;
                }

                if (\in_array(strtolower($user), self::PRIVILEGED_USERS, true)) {
                    yield $this->createPrivilegedUserIssue($user);
                }

                if (null === $password || (\is_string($password) && '' === $password)) {
                    yield $this->createEmptyPasswordIssue($user);
                }
            },
        );
    }

    private function createPrivilegedUserIssue(string $user): SecurityIssue
    {
        return new SecurityIssue([
            'title' => sprintf('Overprivileged Database User: %s', $user),
            'description' => sprintf(
                'The database connection uses the privileged user "%s". '
                . 'This violates the principle of least privilege (OWASP). '
                . 'A compromised application could be used to drop tables, create users, or access other databases. '
                . 'Create a dedicated database user with only SELECT, INSERT, UPDATE, DELETE privileges on the application database.',
                $user,
            ),
            'severity' => 'warning',
            'suggestion' => $this->createLeastPrivilegeSuggestion($user),
            'queries' => [],
        ]);
    }

    private function createEmptyUserIssue(): SecurityIssue
    {
        return new SecurityIssue([
            'title' => 'Empty Database User',
            'description' => 'The database connection has no user configured. '
                . 'This may indicate a misconfiguration or the use of a default anonymous connection. '
                . 'Always use a named, dedicated user for database connections.',
            'severity' => 'warning',
            'suggestion' => $this->createLeastPrivilegeSuggestion('(empty)'),
            'queries' => [],
        ]);
    }

    private function createEmptyPasswordIssue(string $user): SecurityIssue
    {
        return new SecurityIssue([
            'title' => sprintf('Empty Database Password for User: %s', $user),
            'description' => sprintf(
                'The database user "%s" has an empty password. '
                . 'This is a critical security risk: anyone with network access to the database server can connect. '
                . 'Set a strong password for all database users.',
                $user,
            ),
            'severity' => 'critical',
            'suggestion' => $this->createLeastPrivilegeSuggestion($user),
            'queries' => [],
        ]);
    }

    private function createLeastPrivilegeSuggestion(string $currentUser): SuggestionInterface
    {
        $code = "-- Create a dedicated database user with minimal privileges:\n";
        $code .= "CREATE USER 'app_user'@'%' IDENTIFIED BY 'strong_random_password';\n";
        $code .= "GRANT SELECT, INSERT, UPDATE, DELETE ON my_database.* TO 'app_user'@'%';\n";
        $code .= "FLUSH PRIVILEGES;\n\n";
        $code .= "# In .env:\n";
        $code .= "DATABASE_URL=\"mysql://app_user:strong_random_password@127.0.0.1:3306/my_database\"";

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/code_suggestion',
            context: [
                'description' => sprintf('Replace "%s" with a dedicated, least-privilege database user', $currentUser),
                'code' => $code,
                'file_path' => '.env',
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::security(),
                severity: Severity::warning(),
                title: 'Use Least-Privilege Database User',
                tags: ['security', 'database', 'owasp', 'least-privilege'],
            ),
        );
    }
}
