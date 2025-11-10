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

class FlushInLoopSuggestion implements SuggestionInterface
{
    /**
     * @readonly
     */
    private int $flushCount;

    /**
     * @readonly
     */
    private float $operationsBetweenFlush;

    /**
     * @readonly
     */
    private string $example;

    public function __construct(array $data)
    {
        $this->flushCount             = $data['flush_count'] ?? 0;
        $this->operationsBetweenFlush = $data['operations_between_flush'] ?? 0;
        $this->example                = $this->generateExample();
    }

    public function getCode(): string
    {
        return $this->example;
    }

    public function getDescription(): string
    {
        return sprintf(
            'Detected %d flush() calls in a loop pattern (avg %.1f operations per flush). ' .
            'Move flush() outside the loop or use batch processing for better performance.',
            $this->flushCount,
            $this->operationsBetweenFlush,
        );
    }

    public function getMetadata(): SuggestionMetadata
    {
        return new SuggestionMetadata(
            type: SuggestionType::performance(),
            severity: Severity::critical(),
            title: sprintf('Remove flush() from loop (%d calls detected)', $this->flushCount),
            tags: ['performance', 'critical', 'flush', 'batch-processing', 'anti-pattern'],
        );
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'class'                    => static::class,
            'flush_count'              => $this->flushCount,
            'operations_between_flush' => $this->operationsBetweenFlush,
            'example'                  => $this->example,
        ];
    }

    private function generateExample(): string
    {
        return <<<'CODE'
            //  VERY INEFFICIENT: flush() in the loop
            assert(is_iterable($entities), '$entities must be iterable');

            foreach ($entities as $entity) {
                $entity->setStatus('processed');
                $entityManager->flush($entity);  // 1 transaction per iteration!
            }
            // Problems:
            // - N transactions (one per entity)
            // - Huge overhead: validation, events, synchronization
            // - Very slow on large collections
            // - Risk of deadlock with concurrent transactions

            //  SOLUTION 1: flush() after the loop (small number of entities)
            assert(is_iterable($entities), '$entities must be iterable');

            foreach ($entities as $entity) {
                $entity->setStatus('processed');
                // No flush here!
            }
            $entityManager->flush(); // 1 single transaction for everything!
            // Advantages:
            // - 1 single transaction
            // - Much faster
            // - Atomic: all or nothing

            //  SOLUTION 2: Batch processing (large number of entities)
            $batchSize = 50;
            $i = 0;

            assert(is_iterable($entities), '$entities must be iterable');


            foreach ($entities as $entity) {
                $entity->setStatus('processed');

                if ((++$i % $batchSize) === 0) {
                    $entityManager->flush();   // Flush every 50
                    $entityManager->clear();   // Frees up memory

                    // Optional: progress
                    echo "Processed $i entities\n";
                }
            }

            // Flush remaining entities
            if ($i % $batchSize !== 0) {
                $entityManager->flush();
                $entityManager->clear();
            }

            //  When to use each solution:
            //
            // flush() after the loop (Solution 1):
            //  < 100 entities
            //  Simple operations
            //  Need for atomic transaction
            //
            // Batch processing (Solution 2):
            //  > 100 entities
            //  Complex operations
            //  Memory limitation
            //  Bulk processing (imports, migrations)

            //  Performance:
            // Before: {$this->flushCount} transactions = {$this->flushCount} × overhead
            // After (solution 1): 1 transaction = 1 × overhead
            // After (solution 2): ~{$this->calculatedBatches()} transactions = huge gain!

            //  Warning:
            // - Do not use flush($entity) except in very specific cases
            // - empty flush() synchronizes ALL managed entities
            // - Always clear() after flush() in batch processing
            CODE;
    }
}
