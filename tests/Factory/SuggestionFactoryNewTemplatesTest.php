<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Factory;

use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for new suggestion factory methods based on article insights:
 * - Denormalization (counter fields)
 * - GROUP BY aggregation
 * - Updated Extra Lazy with all methods
 */
final class SuggestionFactoryNewTemplatesTest extends TestCase
{
    private SuggestionFactory $factory;

    protected function setUp(): void
    {
        $renderer = new PhpTemplateRenderer();
        $this->factory = new SuggestionFactory($renderer);
    }

    #[Test]
    public function it_creates_denormalization_suggestion(): void
    {
        // Act
        $suggestion = $this->factory->createDenormalization(
            entity: 'Article',
            relation: 'comments',
            queryCount: 15,
        );

        // Assert
        self::assertNotNull($suggestion);
        self::assertStringContainsString('Denormalization', $suggestion->getCode());
        self::assertStringContainsString('commentsCount', $suggestion->getCode());
        self::assertStringContainsString('counter', strtolower($suggestion->getCode()));
        self::assertStringContainsString('Article', $suggestion->getCode());
    }

    #[Test]
    public function it_creates_denormalization_suggestion_with_custom_counter_field(): void
    {
        // Act
        $suggestion = $this->factory->createDenormalization(
            entity: 'Article',
            relation: 'comments',
            queryCount: 10,
            counterField: 'totalComments',
        );

        // Assert
        self::assertNotNull($suggestion);
        self::assertStringContainsString('totalComments', $suggestion->getCode());
    }

    #[Test]
    public function it_creates_group_by_aggregation_suggestion(): void
    {
        // Act
        $suggestion = $this->factory->createGroupByAggregation(
            entity: 'Article',
            relation: 'comments',
            queryCount: 20,
        );

        // Assert
        self::assertNotNull($suggestion);
        self::assertStringContainsString('GROUP BY', $suggestion->getCode());
        self::assertStringContainsString('COUNT', $suggestion->getCode());
        self::assertStringContainsString('Article', $suggestion->getCode());
        self::assertStringContainsString('comments', $suggestion->getCode());
    }

    #[Test]
    public function it_includes_trade_offs_in_denormalization(): void
    {
        // Act
        $suggestion = $this->factory->createDenormalization(
            entity: 'Article',
            relation: 'comments',
            queryCount: 10,
        );

        // Assert
        $code = $suggestion->getCode();
        self::assertStringContainsString('Trade-offs', $code);
        self::assertStringContainsString('Pros:', $code);
        self::assertStringContainsString('Cons:', $code);
        self::assertStringContainsString('Zero queries', $code);
        self::assertStringContainsString('Data redundancy', $code);
    }

    #[Test]
    public function it_includes_trade_offs_in_group_by(): void
    {
        // Act
        $suggestion = $this->factory->createGroupByAggregation(
            entity: 'Article',
            relation: 'comments',
            queryCount: 10,
        );

        // Assert
        $code = $suggestion->getCode();
        self::assertStringContainsString('Trade-offs', $code);
        self::assertStringContainsString('Single query', $code);
        self::assertStringContainsString('No data duplication', $code);
    }

    #[Test]
    public function it_includes_lifecycle_callbacks_option_in_denormalization(): void
    {
        // Act
        $suggestion = $this->factory->createDenormalization(
            entity: 'Article',
            relation: 'comments',
            queryCount: 10,
        );

        // Assert
        self::assertStringContainsString('Lifecycle Callbacks', $suggestion->getCode());
        self::assertStringContainsString('PrePersist', $suggestion->getCode());
        self::assertStringContainsString('PreUpdate', $suggestion->getCode());
    }

    #[Test]
    public function it_includes_database_trigger_option_in_denormalization(): void
    {
        // Act
        $suggestion = $this->factory->createDenormalization(
            entity: 'Article',
            relation: 'comments',
            queryCount: 10,
        );

        // Assert
        self::assertStringContainsString('Database Trigger', $suggestion->getCode());
        self::assertStringContainsString('CREATE TRIGGER', $suggestion->getCode());
    }

    #[Test]
    public function it_includes_repository_method_in_group_by(): void
    {
        // Act
        $suggestion = $this->factory->createGroupByAggregation(
            entity: 'Article',
            relation: 'comments',
            queryCount: 10,
        );

        // Assert
        self::assertStringContainsString('Query Builder', $suggestion->getCode());
        self::assertStringContainsString('Repository', $suggestion->getCode());
        self::assertStringContainsString('createQueryBuilder', $suggestion->getCode());
    }

    #[Test]
    public function it_includes_multiple_aggregations_example_in_group_by(): void
    {
        // Act
        $suggestion = $this->factory->createGroupByAggregation(
            entity: 'Article',
            relation: 'comments',
            queryCount: 10,
        );

        // Assert
        self::assertStringContainsString('Multiple Aggregations', $suggestion->getCode());
        self::assertStringContainsString('SUM', $suggestion->getCode());
        self::assertStringContainsString('MAX', $suggestion->getCode());
    }

    #[Test]
    public function it_calculates_performance_improvement_for_denormalization(): void
    {
        // Act
        $suggestion = $this->factory->createDenormalization(
            entity: 'Article',
            relation: 'comments',
            queryCount: 20,
        );

        // Assert
        $code = $suggestion->getCode();
        self::assertStringContainsString('Expected Performance Improvement', $code);
        self::assertStringContainsString('20', $code); // query count
        self::assertStringContainsString('0 queries', $code); // after optimization
    }

    #[Test]
    public function it_calculates_performance_improvement_for_group_by(): void
    {
        // Act
        $suggestion = $this->factory->createGroupByAggregation(
            entity: 'Article',
            relation: 'comments',
            queryCount: 25,
        );

        // Assert
        $code = $suggestion->getCode();
        self::assertStringContainsString('Expected Performance Improvement', $code);
        self::assertStringContainsString('25', $code); // query count
        self::assertStringContainsString('1 query', $code); // after optimization
    }
}
