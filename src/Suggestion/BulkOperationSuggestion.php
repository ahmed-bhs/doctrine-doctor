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

class BulkOperationSuggestion implements SuggestionInterface
{
    private readonly string $operationType;

    private readonly string $table;

    private readonly int $queryCount;

    private readonly string $example;

    public function __construct(array $data)
    {
        $this->operationType = $data['operation_type'] ?? 'UPDATE';
        $this->table         = $data['table'] ?? 'table';
        $this->queryCount    = $data['query_count'] ?? 0;
        $this->example       = $this->generateExample();
    }

    public function getCode(): string
    {
        return $this->example;
    }

    public function getDescription(): string
    {
        return sprintf(
            'Replace %d individual %s operations with a single DQL bulk %s query. ' .
            'This will reduce database round-trips from %d to 1.',
            $this->queryCount,
            $this->operationType,
            $this->operationType,
            $this->queryCount,
        );
    }

    public function getMetadata(): SuggestionMetadata
    {
        return new SuggestionMetadata(
            type: SuggestionType::PERFORMANCE,
            severity: Severity::CRITICAL,
            title: 'Bulk Operation Optimization',
            tags: ['performance', 'bulk', 'dql'],
        );
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'class'          => static::class,
            'operation_type' => $this->operationType,
            'table'          => $this->table,
            'query_count'    => $this->queryCount,
            'example'        => $this->example,
        ];
    }

    private function generateExample(): string
    {
        $entityName = $this->tableToEntityName($this->table);

        if ('UPDATE' === $this->operationType) {
            return $this->generateUpdateExample($entityName);
        }

        return $this->generateDeleteExample($entityName);
    }

    private function generateUpdateExample(string $entity): string
    {
        return <<<CODE
            // INEFFICIENT: UPDATE in loop
            \$entities = \$repository->findBy(['status' => 'pending']);

            foreach (\$entities as \$entity) {
                \$entity->setStatus('processed');
                \$entity->setProcessedAt(new DateTime());
            }
            \$entityManager->flush();
            // Result: {$this->queryCount} individual UPDATE queries!

            //  SOLUTION: Bulk UPDATE with DQL
            \$query = \$entityManager->createQuery(
                'UPDATE {$entity} e
                 SET e.status = :newStatus, e.processedAt = :processedAt
                 WHERE e.status = :oldStatus'
            );

            \$query->setParameter('newStatus', 'processed');
            \$query->setParameter('processedAt', new DateTime());
            \$query->setParameter('oldStatus', 'pending');

            \$numUpdated = \$query->execute();
            // Result: 1 single UPDATE query for all rows!

            //  Advanced use cases:

            // 1. UPDATE with calculation
            \$query = \$entityManager->createQuery(
                'UPDATE Product p
                 SET p.price = p.price * :multiplier
                 WHERE p.category = :category'
            );
            \$query->setParameter('multiplier', 1.1);  // +10%
            \$query->setParameter('category', 'electronics');
            \$numUpdated = \$query->execute();

            // 2. UPDATE with subquery (if complex need)
            \$query = \$entityManager->createQuery(
                'UPDATE User u
                 SET u.lastOrderDate = (
                     SELECT MAX(o.createdAt)
                     FROM Order o
                     WHERE o.user = u
                 )'
            );
            \$numUpdated = \$query->execute();

            // 3. Multiple conditional UPDATE
            \$query = \$entityManager->createQuery(
                'UPDATE {$entity} e
                 SET e.status = :status
                 WHERE e.createdAt < :cutoffDate
                 AND e.status IN (:statuses)'
            );
            \$query->setParameter('status', 'archived');
            \$query->setParameter('cutoffDate', new DateTime('-1 year'));
            \$query->setParameter('statuses', ['pending', 'draft']);
            \$numUpdated = \$query->execute();

            //  IMPORTANT:
            // - Doctrine events (prePersist, postUpdate, etc.) are NOT triggered
            // - Entities in memory are NOT synchronized
            // - clear() the EntityManager after to avoid inconsistencies:
            \$entityManager->clear();

            //  Performance:
            // Before: {$this->queryCount} queries × ~1ms = ~{$this->queryCount}ms
            // After: 1 query = ~1ms
            // Gain: {$this->calculateGain()}x faster!

            //  When to use:
            //  Mass update (> 10 entities)
            //  Simple operations (set values, increment)
            //  No need for Doctrine events
            //  Complex business logic per entity
            //  Need for events (listeners/subscribers)
            CODE;
    }

    private function generateDeleteExample(string $entity): string
    {
        return <<<CODE
            // INEFFICIENT: DELETE in loop
            \$oldEntities = \$repository->findBy(['status' => 'expired']);

            foreach (\$oldEntities as \$entity) {
                \$entityManager->remove(\$entity);
            }
            \$entityManager->flush();
            // Result: {$this->queryCount} individual DELETE queries!

            //  SOLUTION: Bulk DELETE with DQL
            \$query = \$entityManager->createQuery(
                'DELETE FROM {$entity} e WHERE e.status = :status'
            );
            \$query->setParameter('status', 'expired');
            \$numDeleted = \$query->execute();
            // Result: 1 single DELETE query!

            //  Advanced use cases:

            // 1. DELETE with date
            \$query = \$entityManager->createQuery(
                'DELETE FROM {$entity} e
                 WHERE e.createdAt < :cutoffDate'
            );
            \$query->setParameter('cutoffDate', new DateTime('-1 year'));
            \$numDeleted = \$query->execute();

            // 2. DELETE with multiple conditions
            \$query = \$entityManager->createQuery(
                'DELETE FROM Log l
                 WHERE l.level = :level
                 AND l.createdAt < :date
                 AND l.processed = :processed'
            );
            \$query->setParameter('level', 'debug');
            \$query->setParameter('date', new DateTime('-30 days'));
            \$query->setParameter('processed', true);
            \$numDeleted = \$query->execute();

            // 3. DELETE with subquery
            \$query = \$entityManager->createQuery(
                'DELETE FROM Notification n
                 WHERE n.user IN (
                     SELECT u.id FROM User u WHERE u.active = false
                 )'
            );
            \$numDeleted = \$query->execute();

            //  CASCADE WARNING:
            // - Bulk DELETE does NOT trigger Doctrine cascades
            // - Handle relations manually or use database CASCADE:

            // Option 1: Manual DELETE of relations first
            \$em->createQuery('DELETE FROM OrderLine ol WHERE ol.order IN (:orders)')
                ->setParameter('orders', \$orderIds)
                ->execute();
            \$em->createQuery('DELETE FROM Order o WHERE o.id IN (:orders)')
                ->setParameter('orders', \$orderIds)
                ->execute();

            // Option 2: ON DELETE CASCADE in database
            // In migration:
            \$this->addSql('ALTER TABLE order_line
                ADD CONSTRAINT FK_order
                FOREIGN KEY (order_id) REFERENCES `order`(id)
                ON DELETE CASCADE');

            //  IMPORTANT:
            // - Doctrine events (preRemove, postRemove) are NOT triggered
            // - Entities in memory are NOT synchronized
            // - Call clear() after:
            \$entityManager->clear();

            //  Performance:
            // Before: {$this->queryCount} DELETE queries
            // After: 1 DELETE query
            // Gain: {$this->calculateGain()}x faster!

            //  When to use:
            //  Mass deletion (> 10 entities)
            //  Data cleanup (logs, old data)
            //  No complex Doctrine cascade
            //  Need for deletion events
            //  Complex relations with cascade
            CODE;
    }

    private function tableToEntityName(string $table): string
    {
        $table = preg_replace('/^(tbl_|tb_)/', '', $table);
        $parts = explode('_', (string) $table);

        return implode('', array_map(ucfirst(...), $parts));
    }

    private function calculateGain(): int
    {
        return max(1, $this->queryCount);
    }
}
