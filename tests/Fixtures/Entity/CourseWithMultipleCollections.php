<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Course entity with multiple uninitialized collections - FOR TESTING.
 * This entity has a constructor but doesn't initialize any collections.
 */
#[ORM\Entity]
#[ORM\Table(name: 'courses_multiple_collections')]
class CourseWithMultipleCollections
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    /**
     * BAD: Collection not initialized in constructor!
     */
    #[ORM\OneToMany(targetEntity: Comment::class, mappedBy: 'course')]
    private Collection $students;

    /**
     * BAD: Another collection not initialized!
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'course_teachers')]
    private Collection $teachers;

    /**
     * BAD: Third collection not initialized!
     */
    #[ORM\OneToMany(targetEntity: Comment::class, mappedBy: 'courseTag')]
    private Collection $tags;

    public function __construct()
    {
        // BAD: Constructor exists but doesn't initialize collections!
        // $this->students = new ArrayCollection();
        // $this->teachers = new ArrayCollection();
        // $this->tags = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getStudents(): Collection
    {
        return $this->students;
    }

    public function getTeachers(): Collection
    {
        return $this->teachers;
    }

    public function getTags(): Collection
    {
        return $this->tags;
    }
}
