<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Collector;

use AhmedBhs\DoctrineDoctor\Collector\AnalysisResultStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class AnalysisResultStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/dd-store-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    #[Test]
    public function it_round_trips_a_result(): void
    {
        $store = new AnalysisResultStore($this->directory);
        $result = ['issues' => [['type' => 'slow_query']], 'stats' => ['total_issues' => 1]];

        $store->save('0123456789abcdef', $result);

        self::assertSame($result, $store->load('0123456789abcdef'));
        self::assertSame($result, new AnalysisResultStore($this->directory)->load('0123456789abcdef'), 'Results are shared between processes');
    }

    #[Test]
    public function it_returns_null_for_an_unknown_key(): void
    {
        self::assertNull(new AnalysisResultStore($this->directory)->load('0123456789abcdef'));
    }

    #[Test]
    public function it_rejects_keys_that_could_escape_the_directory(): void
    {
        $store = new AnalysisResultStore($this->directory);

        $store->save('../../evil', ['x' => 1]);

        self::assertNull($store->load('../../evil'));
        self::assertFileDoesNotExist(\dirname($this->directory) . '/evil');
    }
}
