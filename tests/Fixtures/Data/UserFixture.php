<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data;

use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Loads realistic User data for testing.
 */
class UserFixture implements FixtureInterface
{
    /** @var array<User> */
    private array $users = [];

    public function load(EntityManagerInterface $em): void
    {
        $names = [
            'John Doe', 'Jane Smith', 'Bob Johnson', 'Alice Williams',
            'Charlie Brown', 'Diana Prince', 'Eve Adams', 'Frank Miller',
            'Grace Hopper', 'Henry Ford', 'Irene Curie', 'Jack London',
        ];

        foreach ($names as $index => $name) {
            $user = new User();
            $user->setName($name);
            $user->setEmail(strtolower(str_replace(' ', '.', $name)) . '@example.com');

            $em->persist($user);
            $this->users[] = $user;
        }
    }

    public function getLoadedEntities(): array
    {
        return $this->users;
    }

    /**
     * Get a specific user by index.
     */
    public function getUser(int $index): User
    {
        return $this->users[$index] ?? throw new \OutOfBoundsException("User at index {$index} not found");
    }

    /**
     * Get all users.
     *
     * @return array<User>
     */
    public function getUsers(): array
    {
        return $this->users;
    }
}
