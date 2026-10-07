<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Infrastructure\Cache;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\CachedSqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Infrastructure\Cache\SqlNormalizationCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The static SQL caches live as long as the PHP process: in worker runtimes
 * (FrankenPHP, RoadRunner, Swoole) they must not grow without limit.
 */
final class BoundedSqlCachesTest extends TestCase
{
    protected function setUp(): void
    {
        SqlNormalizationCache::clear();
        CachedSqlStructureExtractor::clearCache();
    }

    protected function tearDown(): void
    {
        SqlNormalizationCache::clear();
        CachedSqlStructureExtractor::clearCache();
    }

    #[Test]
    public function the_normalization_cache_is_bounded(): void
    {
        for ($i = 0; $i < SqlNormalizationCache::MAX_ENTRIES_PER_CACHE + 50; ++$i) {
            SqlNormalizationCache::normalize('SELECT id FROM t' . $i);
        }

        self::assertSame(SqlNormalizationCache::MAX_ENTRIES_PER_CACHE, SqlNormalizationCache::getStats()['entries']);
    }

    #[Test]
    public function the_structure_extractor_cache_is_bounded(): void
    {
        $extractor = new CachedSqlStructureExtractor();

        for ($i = 0; $i < SqlNormalizationCache::MAX_ENTRIES_PER_CACHE + 50; ++$i) {
            $extractor->isSelectQuery('SELECT id FROM t' . $i);
        }

        self::assertSame(SqlNormalizationCache::MAX_ENTRIES_PER_CACHE, CachedSqlStructureExtractor::getStats()['entries']);
    }

    #[Test]
    public function the_most_recent_entries_are_kept(): void
    {
        for ($i = 0; $i < SqlNormalizationCache::MAX_ENTRIES_PER_CACHE + 50; ++$i) {
            SqlNormalizationCache::normalize('SELECT id FROM t' . $i);
        }

        $hits = SqlNormalizationCache::getStats()['hits'];
        SqlNormalizationCache::normalize('SELECT id FROM t' . (SqlNormalizationCache::MAX_ENTRIES_PER_CACHE + 49));

        self::assertSame($hits + 1, SqlNormalizationCache::getStats()['hits']);
    }

    #[Test]
    public function null_results_are_cached_too(): void
    {
        // Most queries match no N+1 pattern: a null result must not be recomputed every time
        $sql = 'SELECT COUNT(*) FROM orders';
        $extractor = new CachedSqlStructureExtractor();

        $extractor->detectNPlusOnePattern($sql);
        $hits = CachedSqlStructureExtractor::getStats()['hits'];
        $extractor->detectNPlusOnePattern($sql);

        self::assertNull($extractor->detectNPlusOnePattern($sql));
        self::assertSame($hits + 2, CachedSqlStructureExtractor::getStats()['hits']);

        SqlNormalizationCache::detectNPlusOnePattern($sql);
        $hits = SqlNormalizationCache::getStats()['hits'];
        SqlNormalizationCache::detectNPlusOnePattern($sql);

        self::assertSame($hits + 1, SqlNormalizationCache::getStats()['hits']);
    }
}
