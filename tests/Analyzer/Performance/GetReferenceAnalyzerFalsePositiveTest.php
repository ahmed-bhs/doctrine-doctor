<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\GetReferenceAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GetReferenceAnalyzerFalsePositiveTest extends TestCase
{
    private GetReferenceAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new GetReferenceAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            2,
        );
    }

    #[Test]
    public function it_does_not_flag_fk_lookup_as_find_by_id_candidate(): void
    {
        $builder = QueryDataBuilder::create();

        $builder->addQuery(
            'SELECT t0_.id AS id_0, t0_.name AS name_1 FROM customers t0_ WHERE t0_.customer_id = ?',
            0.5,
        );
        $builder->addQuery(
            'SELECT t0_.id AS id_0, t0_.name AS name_1 FROM suppliers t0_ WHERE t0_.supplier_id = ?',
            0.5,
        );

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues, 'FK lookups (WHERE customer_id / supplier_id) are not primary-key find() candidates and must not be flagged as getReference() opportunities.');
    }

    #[Test]
    public function it_falsely_flags_lazy_loaded_queries_without_backtrace_as_explicit_find(): void
    {
        $builder = QueryDataBuilder::create();

        $builder->addQuery('SELECT t0_.id AS id_0, t0_.name AS name_1 FROM users t0_ WHERE t0_.id = ?', 0.3);
        $builder->addQuery('SELECT t0_.id AS id_0, t0_.name AS name_1 FROM users t0_ WHERE t0_.id = ?', 0.3);

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues));

        $issue = $issues->toArray()[0];
        self::assertStringContainsString('find()', $issue->getTitle(), 'Known false positive: without backtrace, lazy-loaded queries default to "explicit_find" context and suggest getReference(), which is incorrect for lazy loading');
    }

    #[Test]
    public function it_does_not_flag_doctrine_internal_version_check_query(): void
    {
        $builder = QueryDataBuilder::create();

        // #[ORM\Version] optimistic-lock re-read after a versioned UPDATE: a bare,
        // alias-less single-column SELECT. Not application code, not a find()
        // candidate, must never be suggested for getReference().
        $builder->addQuery('SELECT version FROM deposit_request WHERE id = ?', 0.3);
        $builder->addQuery('SELECT version FROM deposit_request WHERE id = ?', 0.3);

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues, 'Bare alias-less SELECTs are Doctrine/DBAL plumbing (e.g. optimistic-lock version re-reads), not find() candidates.');
    }

    #[Test]
    public function it_no_longer_flags_the_reported_process_next_scenario(): void
    {
        $builder = QueryDataBuilder::create();

        // Faithful reproduction of the reported false positive: one real entity
        // hydration (eco_organization, lazy-loaded) plus one Doctrine-internal
        // version-check re-read after the optimistic-locked UPDATE. Excluding the
        // version-check query drops the count to 1, below the threshold of 2 —
        // that is what actually makes the warning disappear here, not the lazy
        // vs find() classification (which only matters once >= 2 real entity
        // lookups clear the threshold on their own).
        $builder->addQuery(
            'SELECT t0_.id AS id_0, t0_.name AS name_1, t0_.webhook_notification_url AS webhook_notification_url_2 FROM eco_organization t0_ WHERE t0_.id = ?',
            0.92,
        );
        $builder->addQuery('SELECT version FROM deposit_request WHERE id = ?', 0.66);

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertCount(0, $issues, 'Excluding the version-check query drops the matched count to 1 (below threshold 2), so the reported "2 find() queries detected" warning must no longer fire.');
    }

    #[Test]
    public function it_classifies_php84_native_lazy_ghost_initialization_as_lazy_loading_not_find(): void
    {
        $builder = QueryDataBuilder::create();

        // PHP 8.4+ native lazy objects (Doctrine ORM 3.x default): no generated
        // proxy class, the field access runs ProxyFactory's initializer closure,
        // which calls EntityPersister::loadById().
        $nativeLazyGhostBacktrace = [
            ['class' => 'Doctrine\\ORM\\Persisters\\Entity\\BasicEntityPersister', 'function' => 'loadById'],
            ['class' => 'Doctrine\\ORM\\Proxy\\ProxyFactory', 'function' => 'createLazyInitializer'],
        ];

        $builder->addQueryWithBacktrace(
            'SELECT t0_.id AS id_0, t0_.name AS name_1 FROM eco_organization t0_ WHERE t0_.id = ?',
            $nativeLazyGhostBacktrace,
            0.3,
        );
        $builder->addQueryWithBacktrace(
            'SELECT t0_.id AS id_0, t0_.name AS name_1 FROM eco_organization t0_ WHERE t0_.id = ?',
            $nativeLazyGhostBacktrace,
            0.3,
        );

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues));

        $issue = $issues->toArray()[0];
        self::assertStringContainsString('Lazy Loading', $issue->getTitle(), 'A native lazy ghost initialization must be classified as lazy loading, not as an explicit find() that getReference() could replace.');
    }

    #[Test]
    public function it_still_classifies_real_find_as_explicit_find_even_though_it_also_calls_load_by_id(): void
    {
        $builder = QueryDataBuilder::create();

        // EntityManager::find() internally calls EntityPersister::loadById() too —
        // the explicit-find check must win over the lazy-loading indicators since
        // 'EntityManager::find' is present higher up the same call stack.
        $explicitFindBacktrace = [
            ['class' => 'Doctrine\\ORM\\Persisters\\Entity\\BasicEntityPersister', 'function' => 'loadById'],
            ['class' => 'Doctrine\\ORM\\EntityManager', 'function' => 'find'],
        ];

        $builder->addQueryWithBacktrace(
            'SELECT t0_.id AS id_0, t0_.name AS name_1 FROM eco_organization t0_ WHERE t0_.id = ?',
            $explicitFindBacktrace,
            0.3,
        );
        $builder->addQueryWithBacktrace(
            'SELECT t0_.id AS id_0, t0_.name AS name_1 FROM eco_organization t0_ WHERE t0_.id = ?',
            $explicitFindBacktrace,
            0.3,
        );

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        self::assertGreaterThanOrEqual(1, \count($issues));

        $issue = $issues->toArray()[0];
        self::assertStringContainsString('find()', $issue->getTitle(), 'A genuine EntityManager::find() call must still be classified as explicit_find, not misread as lazy loading just because it also calls loadById() internally.');
    }
}
