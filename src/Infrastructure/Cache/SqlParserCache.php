<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Infrastructure\Cache;

use PhpMyAdmin\SqlParser\Parser;

/**
 * Shared cache of parsed SQL statements.
 *
 * Every runtime analyzer used to run `new Parser($sql)` on its own, so a request
 * with N queries and ~40 analyzers parsed each statement dozens of times. With
 * 149 queries this alone cost several seconds per request, and pushed requests
 * past max_execution_time on SAPIs without fastcgi_finish_request (Apache mod_php),
 * where the analysis runs before the response is sent.
 *
 * Parsed statements are treated as read-only by all callers, so one Parser
 * instance per distinct SQL string is shared. The cache is bounded to keep
 * memory stable in long-running processes.
 */
final class SqlParserCache
{
    private const int MAX_ENTRIES = 512;

    /**
     * @var array<string, Parser>
     */
    private static array $cache = [];

    public static function parse(string $sql): Parser
    {
        $key = hash('xxh128', $sql);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        if (\count(self::$cache) >= self::MAX_ENTRIES) {
            // FIFO eviction: drop the oldest entry
            unset(self::$cache[array_key_first(self::$cache)]);
        }

        return self::$cache[$key] = new Parser($sql);
    }

    public static function clear(): void
    {
        self::$cache = [];
    }
}
