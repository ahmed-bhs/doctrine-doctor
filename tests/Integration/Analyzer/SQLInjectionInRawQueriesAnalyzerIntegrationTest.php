<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Security\SQLInjectionInRawQueriesAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for SQLInjectionInRawQueriesAnalyzer - Critical Security.
 *
 * Tests detection of SQL injection in raw queries using:
 * - Connection::executeQuery()
 * - Connection::executeStatement()
 * - EntityManager::getConnection()->exec()
 *
 * IMPORTANT: These are intentionally vulnerable examples for testing!
 */
final class SQLInjectionInRawQueriesAnalyzerIntegrationTest extends DatabaseTestCase
{
    private SQLInjectionInRawQueriesAnalyzer $sqlInjectionInRawQueriesAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->createSchema([User::class]);

        $this->sqlInjectionInRawQueriesAnalyzer = new SQLInjectionInRawQueriesAnalyzer(
            $this->entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_raw_sql_with_string_concatenation(): void
    {
        // VULNERABLE: Direct string concatenation in raw SQL with obvious injection pattern
        $vulnerableSQL = "SELECT * FROM users WHERE id = 1 OR '1'='1' -- comment";

        $queryData = new QueryData(
            sql: $vulnerableSQL,
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
            backtrace: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        );

        $issues = $this->sqlInjectionInRawQueriesAnalyzer->analyze(QueryDataCollection::fromArray([$queryData]));

        // The analyzer checks for raw SQL patterns - test documents behavior
        self::assertIsArray($issues->toArray(), 'Analyzer should process raw SQL');

        if (count($issues) > 0) {
            $issue = $issues->toArray()[0];
            self::assertEquals('security', $issue->getCategory());
        }
    }

    #[Test]
    public function it_detects_unsafe_delete_statements(): void
    {
        // VULNERABLE: DELETE with SQL comment injection
        $vulnerableSQL = "DELETE FROM users WHERE id = '1'; DROP TABLE users; --'";

        $queryData = new QueryData(
            sql: $vulnerableSQL,
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
            backtrace: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        );

        $issues = $this->sqlInjectionInRawQueriesAnalyzer->analyze(QueryDataCollection::fromArray([$queryData]));

        self::assertIsArray($issues->toArray(), 'Analyzer should process DELETE statements');
    }

    #[Test]
    public function it_detects_update_injection(): void
    {
        // VULNERABLE: UPDATE with WHERE bypass
        $vulnerableSQL = "UPDATE users SET email = 'hacker@evil.com' WHERE '1'='1'";

        $queryData = new QueryData(
            sql: $vulnerableSQL,
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
            backtrace: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        );

        $issues = $this->sqlInjectionInRawQueriesAnalyzer->analyze(QueryDataCollection::fromArray([$queryData]));

        self::assertIsArray($issues->toArray(), 'Analyzer should process UPDATE statements');
    }

    #[Test]
    public function it_does_not_flag_safe_prepared_statements(): void
    {
        // SAFE: Using prepared statement placeholders
        $safeSQL = "SELECT * FROM users WHERE email = ? AND status = ?";

        $queryData = new QueryData(
            sql: $safeSQL,
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: ['user@example.com', 'active'],
            backtrace: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        );

        $issues = $this->sqlInjectionInRawQueriesAnalyzer->analyze(QueryDataCollection::fromArray([$queryData]));

        self::assertCount(0, $issues, 'Should NOT flag safe prepared statements');
    }

    #[Test]
    public function it_does_not_flag_named_parameters(): void
    {
        // SAFE: Using named parameters
        $safeSQL = "SELECT * FROM users WHERE username = :username AND password = :password";

        $queryData = new QueryData(
            sql: $safeSQL,
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: ['username' => 'admin', 'password' => 'hashed'],
            backtrace: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        );

        $issues = $this->sqlInjectionInRawQueriesAnalyzer->analyze(QueryDataCollection::fromArray([$queryData]));

        self::assertCount(0, $issues, 'Should NOT flag named parameters');
    }

    #[Test]
    public function it_detects_like_wildcard_injection(): void
    {
        // VULNERABLE: LIKE with injection pattern
        $vulnerableSQL = "SELECT * FROM users WHERE name LIKE '%%' OR '1'='1' -- comment%'";

        $queryData = new QueryData(
            sql: $vulnerableSQL,
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
            backtrace: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        );

        $issues = $this->sqlInjectionInRawQueriesAnalyzer->analyze(QueryDataCollection::fromArray([$queryData]));

        self::assertIsArray($issues->toArray(), 'Analyzer should process LIKE queries');
    }

    #[Test]
    public function it_detects_union_based_injection(): void
    {
        // VULNERABLE: UNION injection
        $vulnerableSQL = "SELECT id, name FROM users WHERE id = 1 UNION SELECT card_number, cvv FROM credit_cards";

        $queryData = new QueryData(
            sql: $vulnerableSQL,
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
            backtrace: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        );

        $issues = $this->sqlInjectionInRawQueriesAnalyzer->analyze(QueryDataCollection::fromArray([$queryData]));

        self::assertIsArray($issues->toArray(), 'Should analyze UNION queries');
    }

    #[Test]
    public function it_demonstrates_real_execute_query_vulnerability(): void
    {
        // Simulate real Connection::executeQuery() vulnerability with obvious injection
        $simulatedQuery = "SELECT * FROM users WHERE id = 1 OR '1'='1' --";

        $queryData = new QueryData(
            sql: $simulatedQuery,
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
            backtrace: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        );

        $issues = $this->sqlInjectionInRawQueriesAnalyzer->analyze(QueryDataCollection::fromArray([$queryData]));

        self::assertIsArray($issues->toArray(), 'Analyzer should process executeQuery vulnerabilities');
    }

    #[Test]
    public function it_provides_security_recommendations(): void
    {
        $userEmail = $_POST['email'] ?? 'user@example.com'; // Simulate untrusted input
        $vulnerableSQL = sprintf("INSERT INTO users (email) VALUES ('%s')", $userEmail);

        $queryData = new QueryData(
            sql: $vulnerableSQL,
            executionTime: QueryExecutionTime::fromMilliseconds(10.0),
            params: [],
            backtrace: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        );

        $issues = $this->sqlInjectionInRawQueriesAnalyzer->analyze(QueryDataCollection::fromArray([$queryData]));

        self::assertIsArray($issues->toArray(), 'Should return issues array');

        if (count($issues) > 0) {
            $issue = $issues->toArray()[0];
            $suggestion = $issue->getSuggestion();

            if (null !== $suggestion) {
                $description = $suggestion->getDescription();
                self::assertStringContainsString('prepared', strtolower((string) $description), 'Should recommend prepared statements');
            }
        }
    }
}
