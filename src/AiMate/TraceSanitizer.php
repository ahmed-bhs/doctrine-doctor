<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\AiMate;

final readonly class TraceSanitizer
{
    private const int MAX_FRAMES = 5;

    public function __construct(
        private string $projectDir,
        /** @var list<string> */
        private array $excludedPathFragments = ['/vendor/', '/var/cache/', '/templates/'],
    ) {
    }

    /**
     * @param array<int, array<string, mixed>>|null $trace
     * @return list<array{file: string, line: int, class?: string, function?: string}>
     */
    public function sanitize(?array $trace): array
    {
        if ($trace === null || $trace === []) {
            return [];
        }

        $sanitized = $this->filterFrames($trace, excludeInternal: true);

        if ($sanitized === []) {
            $sanitized = $this->filterFrames($trace, excludeInternal: false);
        }

        return array_slice($sanitized, 0, self::MAX_FRAMES);
    }

    /**
     * @param array<int, array<string, mixed>> $trace
     * @return list<array{file: string, line: int, class?: string, function?: string}>
     */
    private function filterFrames(array $trace, bool $excludeInternal): array
    {
        $result = [];

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;

            if (!is_string($file) || $file === '') {
                continue;
            }

            if ($excludeInternal && $this->isInternalPath($file)) {
                continue;
            }

            $sanitized = [
                'file' => $this->relativizePath($file),
                'line' => is_int($frame['line'] ?? null) ? $frame['line'] : 0,
            ];

            if (is_string($frame['class'] ?? null) && $frame['class'] !== '') {
                $sanitized['class'] = $frame['class'];
            }

            if (is_string($frame['function'] ?? null) && $frame['function'] !== '') {
                $sanitized['function'] = $frame['function'];
            }

            $result[] = $sanitized;
        }

        return $result;
    }

    private function isInternalPath(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);

        foreach ($this->excludedPathFragments as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function relativizePath(string $file): string
    {
        $normalizedProjectDir = rtrim(str_replace('\\', '/', $this->projectDir), '/');
        $normalizedFile = str_replace('\\', '/', $file);

        if ($normalizedProjectDir !== '' && str_starts_with($normalizedFile, $normalizedProjectDir . '/')) {
            return substr($normalizedFile, strlen($normalizedProjectDir) + 1);
        }

        return $normalizedFile;
    }
}

