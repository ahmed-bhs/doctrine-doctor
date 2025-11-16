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

class IndexSuggestion implements SuggestionInterface
{
    /**
     * @readonly
     */
    private string $table;

    /**
     * @readonly
     */
    private array $columns;

    /**
     * @readonly
     */
    private string $migrationCode;

    public function __construct(array $data)
    {
        $this->table         = $data['table'] ?? '';
        $this->columns       = $data['columns'] ?? [];
        $this->migrationCode = $data['migrationCode'] ?? $this->generateMigrationCode($this->table, $this->columns);
    }

    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @return array<mixed>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getCode(): string
    {
        return $this->migrationCode;
    }

    public function getDescription(): string
    {
        return sprintf(
            'Consider adding an index on table "%s" for columns "%s" to improve query performance.',
            $this->table,
            implode(', ', $this->columns),
        );
    }

    public function getMetadata(): SuggestionMetadata
    {

        return new SuggestionMetadata(
            type: SuggestionType::performance(),
            severity: Severity::CRITICAL,
            title: 'Add index on %s.%s',
            tags: ['performance', 'optimization'],
        );

    }

    public function toArray(): array
    {
        return [
            'class'         => static::class,
            'table'         => $this->table,
            'columns'       => $this->columns,
            'migrationCode' => $this->migrationCode,
        ];
    }

    private function generateMigrationCode(string $table, array $columns): string
    {
        if ('' === $table || '0' === $table || [] === $columns) {
            return '// Unable to generate migration code: table or columns missing.';
        }

        $indexName = 'IDX_' . strtoupper($table) . '_' . implode('_', array_map(function ($column) {
            return strtoupper($column);
        }, $columns));
        $cols      = implode(', ', $columns);

        return sprintf('CREATE INDEX %s ON %s (%s);', $indexName, $table, $cols);
    }
}
