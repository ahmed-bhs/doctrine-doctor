<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector;

/**
 * When runtime analysis runs for a profiled request.
 */
enum AnalysisTiming: string
{
    /**
     * Resolve at runtime: Request on the CLI (functional tests read the profile
     * right after the request), AfterResponse when fastcgi_finish_request()
     * exists (php-fpm), OnView otherwise (Apache mod_php, FrankenPHP worker mode,
     * RoadRunner, Swoole), where the analysis would block the response.
     */
    case Auto = 'auto';

    /**
     * Analyze in collect(), before the response is sent. Blocks the request.
     */
    case Request = 'request';

    /**
     * Analyze in lateCollect(), after fastcgi_finish_request() flushed the response.
     * Only non-blocking on php-fpm.
     */
    case AfterResponse = 'after_response';

    /**
     * Only store the queries during the request; analyze them when the profile is
     * opened in the web debug toolbar or the profiler, then persist the result.
     * Never blocks the profiled request, whatever the SAPI.
     */
    case OnView = 'on_view';

    public function resolve(): self
    {
        if (self::Auto !== $this) {
            return $this;
        }

        return self::fromEnvironment(\PHP_SAPI, \function_exists('fastcgi_finish_request'));
    }

    /**
     * @internal exposed for tests
     */
    public static function fromEnvironment(string $sapi, bool $hasFastcgiFinishRequest): self
    {
        return match (true) {
            'cli' === $sapi          => self::Request,
            $hasFastcgiFinishRequest => self::AfterResponse,
            default                  => self::OnView,
        };
    }
}
