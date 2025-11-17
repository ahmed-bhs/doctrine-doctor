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

class BatchOperationSuggestion implements SuggestionInterface
{
    /**
     * @readonly
     */
    private string $table;

    /**
     * @readonly
     */
    private int $operationCount;

    /**
     * @readonly
     */
    private string $example;

    public function __construct(array $data)
    {
        $this->table          = $data['table'] ?? '';
        $this->operationCount = $data['operation_count'] ?? 0;
        $this->example        = $this->generateExample();
    }

    public function getCode(): string
    {
        return $this->example;
    }

    public function getDescription(): string
    {
        return sprintf(
            'To prevent memory leaks during batch operations on "%s" table, call EntityManager::clear() periodically. ' .
            'This releases managed entities from memory while maintaining database consistency.',
            $this->table,
        );
    }

    public function getMetadata(): SuggestionMetadata
    {
        return new SuggestionMetadata(
            type: SuggestionType::PERFORMANCE,
            severity: Severity::WARNING,
            title: 'Batch Operation Memory Management',
            tags: ['performance', 'memory', 'batch'],
        );
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'class'           => static::class,
            'table'           => $this->table,
            'operation_count' => $this->operationCount,
            'example'         => $this->example,
        ];
    }

    private function generateExample(): string
    {
        return <<<'CODE'
            //  BEFORE: Memory leak risk in batch operations
            assert(is_iterable($largeDataset), '$largeDataset must be iterable');

            foreach ($largeDataset as $data) {
                $entity = new Entity($data);
                $entityManager->persist($entity);

                if (($i++ % 100) === 0) {
                    $entityManager->flush();
                    // Missing: $entityManager->clear()
                    // EntityManager keeps ALL entities in memory!
                }
            }
            $entityManager->flush(); // Remaining entities

            //  AFTER: Proper memory management
            $batchSize = 100;
            $i = 0;

            assert(is_iterable($largeDataset), '$largeDataset must be iterable');


            foreach ($largeDataset as $data) {
                $entity = new Entity($data);
                $entityManager->persist($entity);

                if ((++$i % $batchSize) === 0) {
                    $entityManager->flush();
                    $entityManager->clear(); // Free memory!

                    // Optional: Report progress
                    // echo "Processed $i entities\n";
                }
            }

            // Don't forget remaining entities
            if ($i % $batchSize !== 0) {
                $entityManager->flush();
                $entityManager->clear();
            }

            //  Important Notes:
            // - clear() detaches all entities from EntityManager
            // - Don't keep references to entities after clear()
            // - Adjust batch size based on entity complexity
            // - For updates, you'll need to re-fetch entities after clear()
            CODE;
    }
}
