<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\AiMate;

use AhmedBhs\DoctrineDoctor\AiMate\DoctrineDoctorMcpSanitizer;
use AhmedBhs\DoctrineDoctor\AiMate\TraceSanitizer;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DoctrineDoctorMcpSanitizerTest extends TestCase
{
    private DoctrineDoctorMcpSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new DoctrineDoctorMcpSanitizer(
            new TraceSanitizer('/app'),
        );
    }

    #[Test]
    public function it_sanitizes_hint_trace_and_queries(): void
    {
        $issue = new PerformanceIssue([
            'type' => 'n_plus_one',
            'title' => 'N+1 query detected',
            'description' => 'Repeated queries detected in request.',
            'severity' => Severity::warning(),
            'suggestion' => $this->createSuggestion(
                '<div><strong>Use JOIN FETCH</strong> to reduce duplicate queries.</div>',
                'Apply eager loading on repeated relation access',
            ),
            'backtrace' => [
                ['file' => '/app/vendor/symfony/http-kernel/Kernel.php', 'line' => 10, 'function' => 'handle'],
                ['file' => '/app/src/Controller/ProductController.php', 'line' => 42, 'class' => 'App\\Controller\\ProductController', 'function' => '__invoke'],
            ],
            'queries' => [
                ['sql' => "SELECT * FROM product WHERE slug = 'camera'", 'executionMS' => 0.012, 'params' => ['camera']],
            ],
        ]);

        $payload = $this->sanitizer->sanitizeIssue($issue, includeQueries: true);

        self::assertSame('n_plus_one', $payload['type']);
        self::assertSame('warning', $payload['severity']);
        self::assertSame('performance', $payload['category']);
        self::assertSame('Apply eager loading on repeated relation access', $payload['hint']['summary']);
        self::assertCount(1, $payload['trace']);
        self::assertSame('src/Controller/ProductController.php', $payload['trace'][0]['file']);
        self::assertCount(1, $payload['queries']);
        self::assertSame('SELECT * FROM PRODUCT WHERE SLUG = ?', $payload['queries'][0]['sql']);
        self::assertArrayNotHasKey('params', $payload['queries'][0]);
    }

    #[Test]
    public function it_filters_issues_by_category_and_severity(): void
    {
        $issues = [
            new PerformanceIssue([
                'type' => 'slow_query',
                'title' => 'Slow query',
                'description' => 'A slow query was detected.',
                'severity' => Severity::critical(),
            ]),
            new PerformanceIssue([
                'type' => 'query_cache',
                'title' => 'Cache opportunity',
                'description' => 'A cache opportunity was detected.',
                'severity' => Severity::info(),
            ]),
        ];

        $payload = $this->sanitizer->sanitizeIssues(
            $issues,
            category: 'performance',
            severity: 'critical',
            limit: 10,
        );

        self::assertCount(1, $payload);
        self::assertSame('Slow query', $payload[0]['title']);
    }

    private function createSuggestion(string $code, string $description): SuggestionInterface
    {
        return new class($code, $description) implements SuggestionInterface {
            public function __construct(
                private readonly string $code,
                private readonly string $description,
            ) {
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
                    severity: Severity::warning(),
                    title: 'Eager loading hint',
                );
            }

            public function toArray(): array
            {
                return [
                    'code' => $this->code,
                    'description' => $this->description,
                ];
            }
        };
    }
}
