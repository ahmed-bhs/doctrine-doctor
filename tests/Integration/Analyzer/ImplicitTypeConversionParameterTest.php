<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\ImplicitTypeConversionAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Middleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DQL infers a parameter's binding type from its PHP value: an int passed for
 * a text column is bound as an integer, and MySQL then casts every row of the
 * column. A repository `findBy()` binds with the mapped type and is safe. The
 * queries go through Symfony's debug middleware and DoctrineDataCollector, as
 * in the profiler, so the test covers how binding types reach the analyzer.
 */
final class ImplicitTypeConversionParameterTest extends TestCase
{
    private EntityManager $entityManager;

    private DebugDataHolder $debugDataHolder;

    protected function setUp(): void
    {
        $this->debugDataHolder = new DebugDataHolder();
        $connection            = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            new Configuration()->setMiddlewares([new Middleware($this->debugDataHolder, null)]),
        );

        $this->entityManager = new EntityManager($connection, PlatformAnalyzerTestHelper::createTestConfiguration());
        new SchemaTool($this->entityManager)->createSchema([$this->entityManager->getClassMetadata(User::class)]);
        $this->debugDataHolder->reset();
    }

    #[Test]
    public function it_detects_an_integer_parameter_bound_to_a_text_column_in_dql(): void
    {
        $this->entityManager
            ->createQuery('SELECT u FROM ' . User::class . ' u WHERE u.email = :email')
            ->setParameter('email', 42)
            ->getResult();

        $issues = $this->analyze()->toArray();

        self::assertCount(1, $issues);
        self::assertStringContainsString('email', $issues[0]->getTitle());
        self::assertStringContainsString('Integer Parameter', $issues[0]->getTitle());
    }

    #[Test]
    public function it_stays_silent_when_the_parameter_is_a_string(): void
    {
        $this->entityManager
            ->createQuery('SELECT u FROM ' . User::class . ' u WHERE u.email = :email')
            ->setParameter('email', '42')
            ->getResult();

        self::assertCount(0, $this->analyze());
    }

    #[Test]
    public function it_stays_silent_for_find_by_which_binds_the_mapped_type(): void
    {
        $this->entityManager->getRepository(User::class)->findBy(['email' => 42]);

        self::assertCount(0, $this->analyze());
    }

    #[Test]
    public function it_stays_silent_for_an_integer_parameter_on_an_integer_column(): void
    {
        $this->entityManager
            ->createQuery('SELECT u FROM ' . User::class . ' u WHERE u.id = :id AND u.email = :email')
            ->setParameter('id', 42)
            ->setParameter('email', 'ada@example.com')
            ->getResult();

        self::assertCount(0, $this->analyze());
    }

    private function analyze(): \AhmedBhs\DoctrineDoctor\Collection\IssueCollection
    {
        $registry = self::createStub(ManagerRegistry::class);
        $registry->method('getConnectionNames')->willReturn(['default' => 'doctrine.dbal.default_connection']);
        $registry->method('getManagerNames')->willReturn([]);
        $registry->method('getConnection')->willReturn($this->entityManager->getConnection());

        $collector = new DoctrineDataCollector($registry, $this->debugDataHolder);
        $collector->collect(new Request(), new Response());

        $queries = [];
        foreach ($collector->getQueries()['default'] ?? [] as $query) {
            $queries[] = QueryData::fromArray($query);
        }

        $analyzer = new ImplicitTypeConversionAnalyzer(
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            $this->entityManager,
        );

        return $analyzer->analyze(QueryDataCollection::fromArray($queries));
    }
}
