<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Template\Renderer;

use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(SuggestionFactory::class)]
final class SuggestionSeverityAlertTest extends TestCase
{
    private const SEVERITY_DRIVEN_TEMPLATES = [
        'Performance/flush_in_loop',
        'Performance/join_left_on_not_null',
        'Performance/query_caching_frequent',
        'Performance/get_reference',
        'Performance/left_join_with_not_null',
        'Integrity/blameable_public_setter',
        'Integrity/blameable_target_entity',
        'Integrity/bidirectional_orphan_nullable',
        'Integrity/embeddable_mutability',
        'Integrity/primary_key_auto_increment',
        'Integrity/primary_key_uuid_v7',
        'Integrity/primary_key_mixed',
    ];

    private SuggestionFactory $factory;

    protected function setUp(): void
    {
        $directory     = dirname(__DIR__, 4) . '/src/Template/Suggestions';
        $this->factory = new SuggestionFactory(new PhpTemplateRenderer($directory, new NullLogger()));
    }

    #[Test]
    #[DataProvider('severityProvider')]
    public function it_renders_the_alert_matching_the_reported_severity(Severity $severity, string $expectedClass): void
    {
        foreach (self::SEVERITY_DRIVEN_TEMPLATES as $templateName) {
            $suggestion = $this->factory->createFromTemplate(
                templateName: $templateName,
                context: [],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::performance(),
                    severity: $severity,
                    title: 'Title',
                ),
            );

            self::assertStringContainsString(
                $expectedClass,
                $suggestion->getCode(),
                sprintf('Template %s does not follow severity %s', $templateName, $severity->getValue()),
            );
        }
    }

    #[Test]
    public function it_keeps_a_severity_already_present_in_the_context(): void
    {
        $suggestion = $this->factory->createFromTemplate(
            templateName: 'Integrity/primary_key_mixed',
            context: ['severity' => 'critical'],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::info(),
                title: 'Title',
            ),
        );

        self::assertStringContainsString('alert-danger', $suggestion->getCode());
    }

    /**
     * @return iterable<string, array{Severity, string}>
     */
    public static function severityProvider(): iterable
    {
        yield 'critical' => [Severity::critical(), 'alert-danger'];
        yield 'warning'  => [Severity::warning(), 'alert-warning'];
        yield 'info'     => [Severity::info(), 'alert-info'];
    }
}
