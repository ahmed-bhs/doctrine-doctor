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

class PaginationSuggestion implements SuggestionInterface
{
    /**
     * @readonly
     */
    private string $code;

    /**
     * @readonly
     */
    private string $description;

    public function __construct(array $data)
    {
        $this->description = $data['description'] ?? 'Use pagination to limit results';
        $this->code        = $this->generateCode();
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getMetadata(): SuggestionMetadata
    {

        return new SuggestionMetadata(
            type: SuggestionType::performance(),
            severity: Severity::critical(),
            title: 'Use Doctrine Paginator for collection joins',
            tags: ['performance', 'optimization'],
        );

    }

    public function toArray(): array
    {
        return [
            'class'       => static::class,
            'code'        => $this->code,
            'description' => $this->description,
        ];
    }

    private function generateCode(): string
    {
        return <<<'CODE'
            // Option 1: Use pagination in your repository
            /**

             * @return array<mixed>

             */

            public function findPaginated(int $page = 1, int $limit = 20): array
            {
                return $this->createQueryBuilder('e')
                    ->setFirstResult(($page - 1) * $limit)
                    ->setMaxResults($limit)
                    ->getQuery()
                    ->getResult();
            }

            // Option 2: Add WHERE conditions to filter data
            /**

             * @return array<mixed>

             */

            public function findByStatus(string $status): array
            {
                return $this->createQueryBuilder('e')
                    ->where('e.status = :status')
                    ->setParameter('status', $status)
                    ->setMaxResults(100) // Safety limit
                    ->getQuery()
                    ->getResult();
            }

            // Option 3: Use Doctrine Paginator for large datasets
            use Doctrine\ORM\Tools\Pagination\Paginator;

            public function getPaginator(int $page = 1, int $limit = 20): Paginator
            {
                $query = $this->createQueryBuilder('e')
                    ->setFirstResult(($page - 1) * $limit)
                    ->setMaxResults($limit)
                    ->getQuery();

                return new Paginator($query);
            }

            // Usage in controller:
            $paginator = $repository->getPaginator($page, 20);
            $totalItems = count($paginator);
            $items = iterator_to_array($paginator);
            CODE;
    }
}
