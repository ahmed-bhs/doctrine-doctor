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

class EagerLoadingSuggestion implements SuggestionInterface
{
    private readonly string $entity;

    private readonly string $relation;

    private readonly int $queryCount;

    private readonly string $example;

    public function __construct(array $data)
    {
        $this->entity     = $data['entity'] ?? 'Entity';
        $this->relation   = $data['relation'] ?? 'relation';
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
            'Use eager loading for %s.%s to eliminate %d lazy loading queries. ' .
            'Add JOIN with addSelect() in your query builder.',
            $this->entity,
            $this->relation,
            $this->queryCount,
        );
    }

    public function getMetadata(): SuggestionMetadata
    {
        return new SuggestionMetadata(
            type: SuggestionType::performance(),
            severity: Severity::CRITICAL,
            title: sprintf('Eager load %s.%s to avoid N+1 queries', $this->entity, $this->relation),
            tags: ['performance', 'n+1', 'eager-loading', 'joins'],
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
            'relation'    => $this->relation,
            'query_count' => $this->queryCount,
            'example'     => $this->example,
        ];
    }

    private function generateExample(): string
    {
        $entity    = $this->entity;
        $relation  = $this->relation;
        $entityVar = strtolower($entity[0]);

        return <<<CODE
            //  PROBLEM: Lazy loading in the loop
            \${$entity}s = \$repository->findAll();

            foreach (\${$entity}s as \${$entityVar}) {
                echo \${$entityVar}->get{$relation}()->getName();
                //  {$this->queryCount} additional queries executed!
            }
            // Total: 1 + {$this->queryCount} = {$this->calculateTotal()} queries

            //  SOLUTION 1: Eager loading with Query Builder
            \${$entity}s = \$repository->createQueryBuilder('{$entityVar}')
                ->leftJoin('{$entityVar}.{$relation}', 'r')
                ->addSelect('r')  // ← Loads the relation!
                ->getQuery()
                ->getResult();

            foreach (\${$entity}s as \${$entityVar}) {
                echo \${$entityVar}->get{$relation}()->getName();
                //  No additional query!
            }
            // Total: 1 single query with JOIN

            //  SOLUTION 2: Method in the Repository
            // {$entity}Repository.php
            public function findAllWith{$relation}()
            {
                return \$this->createQueryBuilder('{$entityVar}')
                    ->leftJoin('{$entityVar}.{$relation}', 'r')
                    ->addSelect('r')
                    ->getQuery()
                    ->getResult();
            }

            // In the controller
            \${$entity}s = \$repository->findAllWith{$relation}();

            //  JOIN Types:
            // - leftJoin(): Includes entities even if relation = null
            // - innerJoin(): Excludes entities with relation = null
            // - Always add addSelect() after the JOIN!

            //  Performance:
            // Before: {$this->calculateTotal()} queries
            // After: 1 query
            // Gain: {$this->calculatePercentage()}% faster!

            //  ATTENTION with collections:
            // If the relation is OneToMany, use EXTRA_LAZY if possible:
            #[ORM\OneToMany(fetch: 'EXTRA_LAZY')]
            private Collection \${$relation};
            CODE;
    }

    private function calculateTotal(): int
    {
        return 1 + $this->queryCount;
    }

    private function calculatePercentage(): int
    {
        return (int) round(($this->queryCount / $this->calculateTotal()) * 100);
    }
}
