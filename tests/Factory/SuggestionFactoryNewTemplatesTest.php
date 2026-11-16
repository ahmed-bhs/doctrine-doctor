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
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        $suggestion = $this->factory->createFromTemplate(
            templateName: 'Performance/denormalization',
            context: [
                'entity' => 'Article',
                'relation' => 'comments',
                'query_count' => 15,
                'counter_field' => 'commentsCount',
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::info(),
                title: 'Denormalization Opportunity: 15 count() queries for Article.comments',
                tags: ['performance', 'doctrine', 'denormalization', 'counter', 'aggregation'],
            ),
        );

        self::assertNotNull($suggestion);
        self::assertStringContainsString('counter column', $suggestion->getCode());
        self::assertStringContainsString('commentsCount', $suggestion->getCode());
        self::assertStringContainsString('counter', strtolower($suggestion->getCode()));
        self::assertStringContainsString('Article', $suggestion->getCode());
    }

    #[Test]
    public function it_creates_denormalization_suggestion_with_custom_counter_field(): void
    {
        $suggestion = $this->factory->createFromTemplate(
            templateName: 'Performance/denormalization',
            context: [
                'entity' => 'Article',
                'relation' => 'comments',
                'query_count' => 10,
                'counter_field' => 'totalComments',
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::info(),
                title: 'Denormalization Opportunity',
                tags: ['performance', 'doctrine', 'denormalization'],
            ),
        );

        self::assertNotNull($suggestion);
        self::assertStringContainsString('totalComments', $suggestion->getCode());
    }

    #[Test]
    public function it_creates_group_by_aggregation_suggestion(): void
    {
        $suggestion = $this->factory->createFromTemplate(
            templateName: 'Performance/group_by_aggregation',
            context: [
                'entity' => 'Article',
                'relation' => 'comments',
                'query_count' => 20,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: 'GROUP BY Opportunity: 20 queries for Article.comments aggregation',
                tags: ['performance', 'doctrine', 'group-by', 'aggregation', 'n+1'],
            ),
        );

        self::assertNotNull($suggestion);
        self::assertStringContainsString('GROUP BY', $suggestion->getCode());
        self::assertStringContainsString('COUNT', $suggestion->getCode());
        self::assertStringContainsString('Article', $suggestion->getCode());
        self::assertStringContainsString('comments', $suggestion->getCode());
    }

    #[Test]
    public function it_keeps_the_denormalized_counter_in_sync(): void
    {
        $suggestion = $this->factory->createFromTemplate(
            templateName: 'Performance/denormalization',
            context: [
                'entity' => 'Article',
                'relation' => 'comments',
                'query_count' => 10,
                'counter_field' => 'commentsCount',
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::info(),
                title: 'Denormalization Opportunity',
                tags: ['performance', 'doctrine', 'denormalization'],
            ),
        );

        $code = $suggestion->getCode();
        self::assertStringContainsString('++$this-&gt;commentsCount', $code);
        self::assertStringContainsString('--$this-&gt;commentsCount', $code);
        self::assertStringContainsString('SELECT COUNT(*)', $code);
    }

    #[Test]
    public function it_aggregates_in_a_single_group_by_query(): void
    {
        $suggestion = $this->factory->createFromTemplate(
            templateName: 'Performance/group_by_aggregation',
            context: [
                'entity' => 'Article',
                'relation' => 'comments',
                'query_count' => 10,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::info(),
                title: 'GROUP BY Opportunity',
                tags: ['performance', 'doctrine', 'group-by'],
            ),
        );

        $code = $suggestion->getCode();
        self::assertStringContainsString("groupBy('e.id')", $code);
        self::assertStringContainsString('COUNT(r.id) AS commentsCount', $code);
    }

    #[Test]
    public function it_maps_the_counter_column_in_denormalization(): void
    {
        $suggestion = $this->factory->createFromTemplate(
            templateName: 'Performance/denormalization',
            context: [
                'entity' => 'Article',
                'relation' => 'comments',
                'query_count' => 10,
                'counter_field' => 'commentsCount',
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::info(),
                title: 'Denormalization Opportunity',
                tags: ['performance', 'doctrine', 'denormalization'],
            ),
        );

        self::assertStringContainsString('#[ORM\\Column]', $suggestion->getCode());
        self::assertStringContainsString('private int $commentsCount = 0;', $suggestion->getCode());
    }

    #[Test]
    public function it_derives_the_item_methods_in_denormalization(): void
    {
        $suggestion = $this->factory->createFromTemplate(
            templateName: 'Performance/denormalization',
            context: [
                'entity' => 'Article',
                'relation' => 'comments',
                'query_count' => 10,
                'counter_field' => 'commentsCount',
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::info(),
                title: 'Denormalization Opportunity',
                tags: ['performance', 'doctrine', 'denormalization'],
            ),
        );

        self::assertStringContainsString('addComment(Comment $item)', $suggestion->getCode());
        self::assertStringContainsString('removeComment(Comment $item)', $suggestion->getCode());
    }

    #[Test]
    public function it_includes_repository_method_in_group_by(): void
    {
        $suggestion = $this->factory->createFromTemplate(
            templateName: 'Performance/group_by_aggregation',
            context: [
                'entity' => 'Article',
                'relation' => 'comments',
                'query_count' => 10,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::info(),
                title: 'GROUP BY Opportunity',
                tags: ['performance', 'doctrine', 'group-by'],
            ),
        );

        self::assertStringContainsString('createQueryBuilder', $suggestion->getCode());
        self::assertStringContainsString("leftJoin('e.comments', 'r')", $suggestion->getCode());
    }

    #[Test]
    public function it_offers_to_keep_the_entities_in_group_by(): void
    {
        $suggestion = $this->factory->createFromTemplate(
            templateName: 'Performance/group_by_aggregation',
            context: [
                'entity' => 'Article',
                'relation' => 'comments',
                'query_count' => 10,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::info(),
                title: 'GROUP BY Opportunity',
                tags: ['performance', 'doctrine', 'group-by'],
            ),
        );

        self::assertStringContainsString('select <code>e, COUNT(r.id) AS commentsCount</code>', $suggestion->getCode());
    }

    #[Test]
    public function it_calculates_performance_improvement_for_denormalization(): void
    {
        $suggestion = $this->factory->createFromTemplate(
            templateName: 'Performance/denormalization',
            context: [
                'entity' => 'Article',
                'relation' => 'comments',
                'query_count' => 20,
                'counter_field' => 'commentsCount',
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: 'Denormalization Opportunity: 20 count() queries for Article.comments',
                tags: ['performance', 'doctrine', 'denormalization'],
            ),
        );

        $code = $suggestion->getCode();
        self::assertStringContainsString('20 COUNT queries', $code);
    }

    #[Test]
    public function it_calculates_performance_improvement_for_group_by(): void
    {
        $suggestion = $this->factory->createFromTemplate(
            templateName: 'Performance/group_by_aggregation',
            context: [
                'entity' => 'Article',
                'relation' => 'comments',
                'query_count' => 25,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: 'GROUP BY Opportunity: 25 queries for Article.comments aggregation',
                tags: ['performance', 'doctrine', 'group-by'],
            ),
        );

        $code = $suggestion->getCode();
        self::assertStringContainsString('25 queries', $code);
        self::assertStringContainsString('One GROUP BY query', $code);
    }
}
