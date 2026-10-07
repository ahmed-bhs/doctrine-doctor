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
 * Results of deferred analyses (AnalysisTiming::OnView), one file per profile.
 *
 * Results are kept next to the profiles instead of inside them: a profile
 * loaded from the storage cannot be serialized again (Symfony's
 * FormDataCollector::__serialize() assumes live data), so stored profiles are
 * never rewritten. Files are plain serialized arrays readable by any process,
 * including AI Mate, which reads the profiler directory without the application.
 */
final readonly class AnalysisResultStore
{
    private const string KEY_PATTERN = '/^[a-f0-9]{16,64}$/';

    public function __construct(
        private string $directory,
    ) {
    }

    /**
     * @param array<string, mixed> $result
     */
    public function save(string $key, array $result): void
    {
        $file = $this->file($key);

        if (null === $file) {
            return;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o777, true) && !is_dir($this->directory)) {
            return;
        }

        // Write then rename, so a concurrent reader never sees a partial file
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (false !== @file_put_contents($tmp, serialize($result))) {
            @rename($tmp, $file);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(string $key): ?array
    {
        $file = $this->file($key);

        if (null === $file || !is_file($file)) {
            return null;
        }

        $contents = @file_get_contents($file);
        $result = false !== $contents ? @unserialize($contents) : false;

        return \is_array($result) ? $result : null;
    }

    public function has(string $key): bool
    {
        $file = $this->file($key);

        return null !== $file && is_file($file);
    }

    private function file(string $key): ?string
    {
        if (1 !== preg_match(self::KEY_PATTERN, $key)) {
            return null;
        }

        return $this->directory . '/' . $key . '.result';
    }
}
