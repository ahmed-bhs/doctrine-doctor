<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Command\Report;

use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;

/**
 * Resolves the file and line a finding refers to, from the data analyzers attach to issues
 * (entity class, field, method, explicit file and line) or from the first application frame
 * of its backtrace. Paths are made relative to the project directory so that reports, IDs and
 * baselines are identical on a developer machine and in CI.
 */
final readonly class FindingLocator
{
    private const array CLASS_KEYS = ['entity_fqcn', 'entity', 'entity_class', 'class', 'entityClass', 'class_name', 'className', 'embeddable_class', 'root_fqcn', 'root_class', 'root'];

    private const array FIELD_KEYS = ['field', 'field_name', 'fieldName', 'property', 'property_name', 'association'];

    private string $projectDir;

    public function __construct(?string $projectDir = null)
    {
        $this->projectDir = rtrim(str_replace('\\', '/', $projectDir ?? (string) getcwd()), '/') . '/';
    }

    /**
     * Three early-exit sources tried in order; pdepend reports an NPath that does not reflect this method.
     *
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    public function locate(IssueInterface $issue): FindingLocation
    {
        $data = $issue->getData();
        [$class, $field] = $this->subject($issue, $data);
        $method = $this->firstString($data, ['method']);

        $location = $this->fromClass($class, $field, $method);
        $location ??= $this->fromExplicitFile($data, $method);
        $location ??= $this->fromBacktrace((array) $issue->getBacktrace());

        return $location ?? new FindingLocation(null, null, $this->anchor($class, $field, $method));
    }

    /**
     * "Class::$field" or "Class::method()" the finding is about, without reading any file; empty when unknown.
     */
    public function subjectOf(IssueInterface $issue): string
    {
        $data = $issue->getData();
        [$class, $field] = $this->subject($issue, $data);

        return $this->anchor($class, $field, $this->firstString($data, ['method']));
    }

    /**
     * The class and field a finding is about, from the issue data or, as a last resort, from a title
     * such as "Float Used for Money: StockAlert::$targetPrice".
     *
     * @param array<string, mixed> $data
     * @return array{0: ?string, 1: ?string}
     */
    private function subject(IssueInterface $issue, array $data): array
    {
        $class = $this->resolveClass($this->firstString($data, self::CLASS_KEYS));
        $field = $this->firstString($data, self::FIELD_KEYS);

        if (null !== $class) {
            return [$class, $field];
        }

        $subject = strstr($issue->getTitle(), ':');
        if (false === $subject || 1 !== preg_match('/\b([A-Z]\w*)(?:::\$(\w+))?\b/', $subject, $matches)) {
            return [null, $field];
        }

        return [$this->resolveClass($matches[1]), $field ?? ($matches[2] ?? null)];
    }

    private function fromClass(?string $class, ?string $field, ?string $method): ?FindingLocation
    {
        if (null === $class || !class_exists($class)) {
            return null;
        }

        $reflection = new \ReflectionClass($class);
        $file = $reflection->getFileName();
        if (false === $file) {
            return new FindingLocation(null, null, $this->anchor($class, $field, $method));
        }

        return new FindingLocation($this->relative($file), $this->lineIn($reflection, $field, $method), $this->anchor($class, $field, $method));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fromExplicitFile(array $data, ?string $method): ?FindingLocation
    {
        if (!isset($data['file']) || !\is_string($data['file']) || '' === $data['file']) {
            return null;
        }

        $file = $this->relative($data['file']);
        $line = isset($data['line']) && is_numeric($data['line']) ? (int) $data['line'] : null;

        return new FindingLocation($file, $line, $file . (null !== $method ? '::' . $method . '()' : ''));
    }

    /**
     * @param array<mixed> $backtrace
     */
    private function fromBacktrace(array $backtrace): ?FindingLocation
    {
        foreach ($backtrace as $frame) {
            if (!\is_array($frame) || !isset($frame['file']) || !\is_string($frame['file']) || str_contains($frame['file'], '/vendor/')) {
                continue;
            }

            $file = $this->relative($frame['file']);
            $line = isset($frame['line']) && is_numeric($frame['line']) ? (int) $frame['line'] : null;

            return new FindingLocation($file, $line, $file . ':' . ($line ?? 0));
        }

        return null;
    }

    private function anchor(?string $class, ?string $field, ?string $method): string
    {
        if (null === $class) {
            return '';
        }

        return $class . (null !== $field ? '::$' . ltrim($field, '$') : '') . (null !== $method ? '::' . $method . '()' : '');
    }

    /**
     * Accepts a fully qualified class name or a short one ("StockAlert"): short names are matched against the
     * classes already loaded by the analysis, preferring classes of the project over those of vendor/.
     */
    private function resolveClass(?string $class): ?string
    {
        if (null === $class) {
            return null;
        }
        $class = ltrim($class, '\\');
        if (class_exists($class, false) || str_contains($class, '\\')) {
            return $class;
        }

        $candidates = [];
        foreach (get_declared_classes() as $declared) {
            if (str_ends_with($declared, '\\' . $class)) {
                $file = (string) new \ReflectionClass($declared)->getFileName();
                $candidates[$declared] = (str_contains($file, '/vendor/') ? 1 : 0) . str_pad((string) \strlen($declared), 4, '0', STR_PAD_LEFT);
            }
        }
        asort($candidates);

        return array_key_first($candidates);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $keys
     */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && \is_string($data[$key]) && '' !== $data[$key]) {
                return $data[$key];
            }
        }

        return null;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     */
    private function lineIn(\ReflectionClass $reflection, ?string $field, ?string $method): ?int
    {
        if (null !== $method && $reflection->hasMethod($method)) {
            $line = $reflection->getMethod($method)->getStartLine();

            return false !== $line ? $line : null;
        }

        if (null !== $field) {
            $name = ltrim($field, '$');
            $declaring = $reflection->hasProperty($name) ? $reflection->getProperty($name)->getDeclaringClass() : $reflection;
            $file = $declaring->getFileName();

            if (false !== $file && $declaring->getName() === $reflection->getName()) {
                foreach (file($file) ?: [] as $index => $source) {
                    if (1 === preg_match('/\$' . preg_quote($name, '/') . '\b/', $source) && 1 === preg_match('/\b(private|protected|public|readonly|var)\b/', $source)) {
                        return $index + 1;
                    }
                }
            }
        }

        $line = $reflection->getStartLine();

        return false !== $line ? $line : null;
    }

    private function relative(string $file): string
    {
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $this->projectDir) ? substr($file, \strlen($this->projectDir)) : $file;
    }
}
