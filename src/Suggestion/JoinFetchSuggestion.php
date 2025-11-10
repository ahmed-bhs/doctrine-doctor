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

class JoinFetchSuggestion implements SuggestionInterface
{
    /**
     * @readonly
     */
    private string $entity;

    /**
     * @readonly
     */
    private string $relation;

    /**
     * @readonly
     */
    private string $example;

    public function __construct(array $data)
    {
        $this->entity   = $data['entity'] ?? '';
        $this->relation = $data['relation'] ?? '';
        $this->example  = $data['example'] ?? $this->generateExample($this->entity, $this->relation);
    }

    public function getEntity(): string
    {
        return $this->entity;
    }

    public function getRelation(): string
    {
        return $this->relation;
    }

    public function getCode(): string
    {
        return $this->example;
    }

    public function getDescription(): string
    {
        return sprintf(
            'Consider using `->leftJoin(\'e.%s\', \'%s\')->addSelect(\'%s\')` to eager load the "%s" relation on the "%s" entity.',
            $this->relation,
            $this->relation,
            $this->relation,
            $this->relation,
            $this->entity,
        );
    }

    public function getMetadata(): SuggestionMetadata
    {
        return new SuggestionMetadata(
            type: SuggestionType::performance(),
            severity: Severity::CRITICAL,
            title: sprintf('Use JOIN FETCH for %s.%s to avoid lazy loading', $this->entity, $this->relation),
            tags: ['performance', 'n+1', 'join-fetch', 'eager-loading'],
        );
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'class'    => static::class,
            'entity'   => $this->entity,
            'relation' => $this->relation,
            'example'  => $this->example,
        ];
    }

    private function generateExample(string $entity, string $relation): string
    {
        if ('' === $entity || '0' === $entity || ('' === $relation || '0' === $relation)) {
            return '// Unable to generate example: entity or relation missing.';
        }

        // Generate comprehensive example with QueryBuilder
        $shortEntity = $this->getShortClassName($entity);

        return <<<CODE
            // In your repository ({$shortEntity}Repository.php)
            public function findAllWithRelation()
            {
                return \$this->createQueryBuilder('e')
                    ->leftJoin('e.{$relation}', 'rel')
                    ->addSelect('rel')
                    ->getQuery()
                    ->getResult();
            }
            CODE;
    }

    private function getShortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
