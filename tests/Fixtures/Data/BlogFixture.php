<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data;

use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Comment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Loads realistic Blog data (posts + comments) for testing N+1 queries.
 */
class BlogFixture implements FixtureInterface
{
    /** @var array<BlogPost> */
    private array $posts = [];

    /** @var array<Comment> */
    private array $comments = [];

    /**
     * @param array<User> $users
     */
    public function __construct(
        /**
         * @readonly
         */
        private array $users,
    ) {
    }

    public function load(EntityManagerInterface $em): void
    {
        if (empty($this->users)) {
            throw new \RuntimeException('BlogFixture requires users to be loaded first');
        }

        // Create 10 blog posts
        $titles = [
            'Introduction to Doctrine ORM',
            'Understanding N+1 Query Problems',
            'Optimizing Database Performance',
            'Best Practices for Entity Design',
            'Lazy Loading vs Eager Loading',
            'Working with Doctrine Collections',
            'Advanced DQL Techniques',
            'Database Indexing Strategies',
            'Transaction Management in Doctrine',
            'Security Best Practices',
        ];

        foreach ($titles as $index => $title) {
            $post = new BlogPost();
            $post->setTitle($title);
            $post->setContent($this->generateContent($title));
            $post->setAuthor($this->users[$index % count($this->users)]);

            $em->persist($post);
            $this->posts[] = $post;

            // Add 3-7 comments per post to create N+1 scenario
            $commentCount = rand(3, 7);
            for ($i = 0; $i < $commentCount; $i++) {
                $comment = new Comment();
                $comment->setContent($this->generateCommentContent($i));
                $comment->setPost($post);
                $comment->setAuthor($this->users[array_rand($this->users)]);

                $em->persist($comment);
                $this->comments[] = $comment;
            }
        }
    }

    public function getLoadedEntities(): array
    {
        return array_merge($this->posts, $this->comments);
    }

    /**
     * @return array<BlogPost>
     */
    public function getPosts(): array
    {
        return $this->posts;
    }

    /**
     * @return array<Comment>
     */
    public function getComments(): array
    {
        return $this->comments;
    }

    private function generateContent(string $title): string
    {
        return "This is a detailed article about: {$title}. " .
            "Lorem ipsum dolor sit amet, consectetur adipiscing elit. " .
            "Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.";
    }

    private function generateCommentContent(int $index): string
    {
        $comments = [
            'Great article! Very informative.',
            'Thanks for sharing this.',
            'I learned a lot from this post.',
            'Could you elaborate more on this topic?',
            'This is exactly what I was looking for.',
            'Excellent explanation!',
            'Very helpful, thank you!',
        ];

        return $comments[$index % count($comments)];
    }
}
