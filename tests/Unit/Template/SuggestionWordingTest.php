<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Template;

use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SuggestionWordingTest extends TestCase
{
    #[Test]
    public function a_sum_inflated_by_a_join_is_fixed_with_exists_not_distinct(): void
    {
        $code = $this->render('Performance/aggregation_with_inner_join', ['aggregation' => 'SUM']);

        self::assertStringContainsString('EXISTS (SELECT', $code);
        self::assertStringNotContainsString("select('COUNT(DISTINCT", $code);
    }

    #[Test]
    public function a_count_inflated_by_a_join_is_fixed_with_distinct(): void
    {
        self::assertStringContainsString('COUNT(DISTINCT o.id)', $this->render('Performance/aggregation_with_inner_join', ['aggregation' => 'COUNT']));
    }

    #[Test]
    public function the_null_fix_names_the_dql_property_not_the_sql_column(): void
    {
        $code = $this->render('Integrity/null_comparison', ['field' => 's0_.shipped_at', 'incorrect' => 's0_.shipped_at = NULL', 'correct' => 's0_.shipped_at IS NULL']);

        self::assertStringContainsString("isNull('e.shippedAt')", $code);
    }

    #[Test]
    public function the_foreign_key_fix_imports_the_target_and_keeps_the_column(): void
    {
        $code = $this->render('Integrity/foreign_key_primitive', ['entity_class' => 'Supplier', 'field_name' => 'defaultProductId', 'target_entity' => 'App\\Entity\\Product\\Product']);

        self::assertStringContainsString('use App\\Entity\\Product\\Product;', $code);
        self::assertStringContainsString('targetEntity: Product::class', $code);
        self::assertStringContainsString("name: 'default_product_id'", $code);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): string
    {
        return PlatformAnalyzerTestHelper::createSuggestionFactory()->createFromTemplate(
            $template,
            $context,
            new SuggestionMetadata(type: SuggestionType::performance(), severity: Severity::warning(), title: 'test'),
        )->getCode();
    }
}
