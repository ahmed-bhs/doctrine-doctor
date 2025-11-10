<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Suggestion;

use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

class GetReferenceSuggestion implements SuggestionInterface
{
    private readonly string $entity;

    private readonly string $table;

    private readonly int $queryCount;

    private readonly string $example;

    public function __construct(array $data)
    {
        $this->entity     = $data['entity'] ?? 'Entity';
        $this->table      = $data['table'] ?? 'table';
        $this->queryCount = $data['query_count'] ?? 0;
        $this->example    = $this->generateExample();
    }

    public function getCode(): string
    {
        return $this->example;
    }

    public function getDescription(): string
    {
        return sprintf(
            'Replace find() with getReference() for %s entity when only using it as a reference. ' .
            'This eliminates %d unnecessary SELECT queries.',
            $this->entity,
            $this->queryCount,
        );
    }

    public function getMetadata(): SuggestionMetadata
    {
        return new SuggestionMetadata(
            type: SuggestionType::performance(),
            severity: Severity::WARNING,
            title: sprintf('Use getReference() instead of find() for %s', $this->entity),
            tags: ['performance', 'proxy', 'getReference', 'optimization'],
        );
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'class'       => static::class,
            'entity'      => $this->entity,
            'table'       => $this->table,
            'query_count' => $this->queryCount,
            'example'     => $this->example,
        ];
    }

    private function generateExample(): string
    {
        $entity = $this->entity;

        return <<<CODE
            //  INEFFICIENT: Using find() loads the full entity
            \$user = \$entityManager->find({$entity}::class, \$userId);
            // Executes: SELECT * FROM {$this->table} WHERE id = ?

            \$order->setUser(\$user);  // Only need the reference!
            \$entityManager->persist(\$order);

            //  EFFICIENT: Using getReference() creates a proxy without query
            \$user = \$entityManager->getReference({$entity}::class, \$userId);
            // No database query executed!

            \$order->setUser(\$user);  // Same result, no query!
            \$entityManager->persist(\$order);

            //  When to use getReference():
            //  Setting relationships (ManyToOne, ManyToMany)
            //  You only need the entity ID
            //  You won't access entity properties immediately
            //  Entity is guaranteed to exist

            //   When NOT to use getReference():
            //  You need to read/modify entity properties
            //  Entity might not exist (getReference won't validate)
            //  You need to display entity data

            //  Performance Impact:
            // Before: {$this->queryCount} queries to load {$entity} entities
            // After:  0 queries - proxies created instantly
            // Savings: {$this->queryCount} × query time + network overhead
            CODE;
    }
}
