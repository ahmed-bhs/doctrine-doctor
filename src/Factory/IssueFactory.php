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
    private readonly IssueTypeRegistryInterface $issueTypeRegistry;

    public function __construct(?IssueTypeRegistryInterface $issueTypeRegistry = null)
    {
        $this->issueTypeRegistry = $issueTypeRegistry ?? new FilesystemIssueTypeRegistry();
    }

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
        $typeMap = $this->issueTypeRegistry->getTypeMap();
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
        return $this->issueTypeRegistry->supports($type);
    }

    /**
     * Get all supported issue types.
     * @return string[]
     */
    public function getSupportedTypes(): array
    {
        return $this->issueTypeRegistry->getSupportedTypes();
    }
}
