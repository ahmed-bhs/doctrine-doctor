<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ColumnTypeTest;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entity with multiple types of column issues - comprehensive test case.
 */
#[ORM\Entity]
class EntityWithMixedIssues
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * CRITICAL: object type.
     */
    #[ORM\Column(type: 'object')]
    private object $objectField;

    /**
     * WARNING: array type.
     */
    #[ORM\Column(type: 'array')]
    private array $arrayField;

    /**
     * INFO: simple_array with limited length.
     */
    #[ORM\Column(type: 'simple_array', length: 255)]
    private array $simpleArrayField;

    /**
     * INFO: enum opportunity (status).
     */
    #[ORM\Column(type: 'string', length: 20)]
    private string $status;

    /**
     * OK: json type (correct approach).
     */
    #[ORM\Column(type: 'json')]
    private array $jsonField;

    /**
     * OK: regular string.
     */
    #[ORM\Column(type: 'string', length: 100)]
    private string $title;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getObjectField(): object
    {
        return $this->objectField;
    }

    public function setObjectField(object $objectField): self
    {
        $this->objectField = $objectField;

        return $this;
    }

    public function getArrayField(): array
    {
        return $this->arrayField;
    }

    public function setArrayField(array $arrayField): self
    {
        $this->arrayField = $arrayField;

        return $this;
    }

    public function getSimpleArrayField(): array
    {
        return $this->simpleArrayField;
    }

    public function setSimpleArrayField(array $simpleArrayField): self
    {
        $this->simpleArrayField = $simpleArrayField;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getJsonField(): array
    {
        return $this->jsonField;
    }

    public function setJsonField(array $jsonField): self
    {
        $this->jsonField = $jsonField;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }
}
