<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Factory;

use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Issue\AbstractIssue;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use InvalidArgumentException;

/**
 * Concrete Factory for creating Issue instances.
 * Implements Factory Pattern for flexible object creation.
 * SOLID Principles respected:
 */
class IssueFactory implements IssueFactoryInterface
{
    /** @var array<string, class-string<AbstractIssue>>|null */
    private static ?array $typeMap = null;

    public function create(IssueData $issueData): IssueInterface
    {
        return $this->createFromArray($issueData->toArray());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createFromArray(array $data): IssueInterface
    {
        $rawType = $data['type'] ?? 'unknown';
        $type = $rawType instanceof IssueType ? $rawType->value : (is_string($rawType) ? $rawType : 'unknown');

        // Find the concrete class for this issue type
        $typeMap = $this->getTypeMap();
        $issueClass = $typeMap[$type] ?? null;

        if (null === $issueClass) {
            throw new InvalidArgumentException(sprintf('Unknown issue type "%s". Available types: %s', $type, implode(', ', array_keys($typeMap))));
        }

        return new $issueClass($data);
    }

    /**
     * Check if a type is supported.
     */
    public function supports(string $type): bool
    {
        return isset($this->getTypeMap()[$type]);
    }

    /**
     * Get all supported issue types.
     * @return string[]
     */
    public function getSupportedTypes(): array
    {
        return array_keys($this->getTypeMap());
    }

    /**
     * Build and cache the map of type => issue class from issue class declarations.
     *
     * @return array<string, class-string<AbstractIssue>>
     */
    private function getTypeMap(): array
    {
        if (null !== self::$typeMap) {
            return self::$typeMap;
        }

        $map = [];
        foreach ($this->discoverIssueClasses() as $issueClass) {
            foreach ($issueClass::supportedTypes() as $type) {
                if (isset($map[$type]) && $map[$type] !== $issueClass) {
                    throw new InvalidArgumentException(sprintf(
                        'Duplicate issue type mapping for "%s": %s and %s',
                        $type,
                        $map[$type],
                        $issueClass,
                    ));
                }

                $map[$type] = $issueClass;
            }
        }

        self::$typeMap = $map;

        return self::$typeMap;
    }

    /**
     * @return list<class-string<AbstractIssue>>
     */
    private function discoverIssueClasses(): array
    {
        $issueClasses = [];
        $issueFiles = glob(__DIR__ . '/../Issue/*Issue.php') ?: [];

        foreach ($issueFiles as $issueFile) {
            $className = pathinfo($issueFile, PATHINFO_FILENAME);
            $fqcn = 'AhmedBhs\\DoctrineDoctor\\Issue\\' . $className;

            if (!class_exists($fqcn)) {
                continue;
            }

            if (!is_subclass_of($fqcn, AbstractIssue::class)) {
                continue;
            }

            $issueClasses[] = $fqcn;
        }

        return $issueClasses;
    }
}
