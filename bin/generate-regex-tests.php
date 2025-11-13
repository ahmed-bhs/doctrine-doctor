#!/usr/bin/env php
<?php

/**
 * Script: Génération automatique de tests pour les patterns regex
 *
 * Génère des tests PHPUnit pour valider les remplacements regex → str_contains()
 *
 * Usage: php bin/generate-regex-tests.php [--output=path]
 */

declare(strict_types=1);

class RegexTestGenerator
{
    private const TEST_CASES = [
        'ORDER BY' => [
            'should_match' => [
                'SELECT * FROM users ORDER BY name',
                'select * from users order by name',
                'SELECT * FROM users WHERE id = 1 ORDER BY created_at DESC',
            ],
            'should_not_match' => [
                'SELECT * FROM users',
                'SELECT * FROM users GROUP BY status',
            ],
        ],
        'GROUP BY' => [
            'should_match' => [
                'SELECT COUNT(*) FROM users GROUP BY status',
                'select * from orders group by user_id',
            ],
            'should_not_match' => [
                'SELECT * FROM users',
                'SELECT * FROM users ORDER BY name',
            ],
        ],
        'LEFT JOIN' => [
            'should_match' => [
                'SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id',
                'select * from users left join addresses on users.id = addresses.user_id',
            ],
            'should_not_match' => [
                'SELECT * FROM users',
                'SELECT * FROM users INNER JOIN orders',
            ],
        ],
        'DISTINCT' => [
            'should_match' => [
                'SELECT DISTINCT email FROM users',
                'select distinct status from orders',
            ],
            'should_not_match' => [
                'SELECT * FROM users',
                'SELECT email FROM users',
            ],
        ],
    ];

    public function generateTestClass(array $patterns): string
    {
        $test = "<?php\n\n";
        $test .= "declare(strict_types=1);\n\n";
        $test .= "namespace AhmedBhs\\DoctrineDoctor\\Tests\\Unit\\Pattern;\n\n";
        $test .= "use PHPUnit\\Framework\\TestCase;\n\n";
        $test .= "/**\n";
        $test .= " * Auto-generated tests for regex → str_contains() migration\n";
        $test .= " * Generated: " . date('Y-m-d H:i:s') . "\n";
        $test .= " */\n";
        $test .= "class SimpleKeywordDetectionTest extends TestCase\n";
        $test .= "{\n";

        foreach (self::TEST_CASES as $keyword => $cases) {
            $test .= $this->generateTestMethodForKeyword($keyword, $cases);
        }

        $test .= "}\n";

        return $test;
    }

    private function generateTestMethodForKeyword(string $keyword, array $cases): string
    {
        $methodName = 'test' . str_replace(' ', '', ucwords(strtolower($keyword))) . 'Detection';
        $keywordUpper = strtoupper($keyword);

        $test = "\n";
        $test .= "    /**\n";
        $test .= "     * Test detection of '$keyword' keyword\n";
        $test .= "     */\n";
        $test .= "    public function $methodName(): void\n";
        $test .= "    {\n";

        // Test cases that should match
        $test .= "        // Should match\n";
        foreach ($cases['should_match'] as $sql) {
            $test .= "        \$this->assertTrue(\n";
            $test .= "            str_contains(strtoupper(" . var_export($sql, true) . "), '$keywordUpper'),\n";
            $test .= "            'Should detect $keyword in: $sql'\n";
            $test .= "        );\n\n";
        }

        // Test cases that should NOT match
        $test .= "        // Should NOT match\n";
        foreach ($cases['should_not_match'] as $sql) {
            $test .= "        \$this->assertFalse(\n";
            $test .= "            str_contains(strtoupper(" . var_export($sql, true) . "), '$keywordUpper'),\n";
            $test .= "            'Should NOT detect $keyword in: $sql'\n";
            $test .= "        );\n\n";
        }

        $test .= "    }\n";

        return $test;
    }

    public function generateComparisonTest(array $patterns): string
    {
        $test = "<?php\n\n";
        $test .= "declare(strict_types=1);\n\n";
        $test .= "namespace AhmedBhs\\DoctrineDoctor\\Tests\\Unit\\Pattern;\n\n";
        $test .= "use PHPUnit\\Framework\\TestCase;\n\n";
        $test .= "/**\n";
        $test .= " * Comparison test: Regex vs str_contains()\n";
        $test .= " * Validates that both methods produce identical results\n";
        $test .= " */\n";
        $test .= "class RegexVsStrContainsComparisonTest extends TestCase\n";
        $test .= "{\n";
        $test .= "    /**\n";
        $test .= "     * @dataProvider sqlQueryProvider\n";
        $test .= "     */\n";
        $test .= "    public function testRegexAndStrContainsSameResults(string \$sql, string \$keyword): void\n";
        $test .= "    {\n";
        $test .= "        // Old method (regex)\n";
        $test .= "        \$regexResult = (bool) preg_match('/' . \$keyword . '/i', \$sql);\n\n";
        $test .= "        // New method (str_contains)\n";
        $test .= "        \$strContainsResult = str_contains(strtoupper(\$sql), strtoupper(\$keyword));\n\n";
        $test .= "        \$this->assertSame(\n";
        $test .= "            \$regexResult,\n";
        $test .= "            \$strContainsResult,\n";
        $test .= "            \"Results differ for keyword '\$keyword' in SQL: \$sql\"\n";
        $test .= "        );\n";
        $test .= "    }\n\n";

        $test .= "    public static function sqlQueryProvider(): array\n";
        $test .= "    {\n";
        $test .= "        return [\n";

        foreach (self::TEST_CASES as $keyword => $cases) {
            foreach ($cases['should_match'] as $sql) {
                $test .= sprintf(
                    "            [%s, %s],\n",
                    var_export($sql, true),
                    var_export($keyword, true)
                );
            }
            foreach ($cases['should_not_match'] as $sql) {
                $test .= sprintf(
                    "            [%s, %s],\n",
                    var_export($sql, true),
                    var_export($keyword, true)
                );
            }
        }

        $test .= "        ];\n";
        $test .= "    }\n";
        $test .= "}\n";

        return $test;
    }

    public function generateBenchmarkTest(): string
    {
        $test = "<?php\n\n";
        $test .= "declare(strict_types=1);\n\n";
        $test .= "namespace AhmedBhs\\DoctrineDoctor\\Tests\\Unit\\Pattern;\n\n";
        $test .= "use PHPUnit\\Framework\\TestCase;\n\n";
        $test .= "/**\n";
        $test .= " * Performance benchmark: Regex vs str_contains()\n";
        $test .= " */\n";
        $test .= "class RegexPerformanceBenchmarkTest extends TestCase\n";
        $test .= "{\n";
        $test .= "    private const ITERATIONS = 10000;\n\n";

        $test .= "    public function testStrContainsIsFasterThanRegex(): void\n";
        $test .= "    {\n";
        $test .= "        \$sql = 'SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id ORDER BY u.created_at';\n";
        $test .= "        \$keyword = 'ORDER BY';\n\n";

        $test .= "        // Benchmark regex\n";
        $test .= "        \$startRegex = microtime(true);\n";
        $test .= "        for (\$i = 0; \$i < self::ITERATIONS; \$i++) {\n";
        $test .= "            preg_match('/ORDER BY/i', \$sql);\n";
        $test .= "        }\n";
        $test .= "        \$regexTime = microtime(true) - \$startRegex;\n\n";

        $test .= "        // Benchmark str_contains\n";
        $test .= "        \$startStrContains = microtime(true);\n";
        $test .= "        for (\$i = 0; \$i < self::ITERATIONS; \$i++) {\n";
        $test .= "            str_contains(strtoupper(\$sql), \$keyword);\n";
        $test .= "        }\n";
        $test .= "        \$strContainsTime = microtime(true) - \$startStrContains;\n\n";

        $test .= "        // str_contains should be faster (or at least not slower)\n";
        $test .= "        \$this->assertLessThanOrEqual(\n";
        $test .= "            \$regexTime,\n";
        $test .= "            \$strContainsTime,\n";
        $test .= "            sprintf(\n";
        $test .= "                'str_contains (%f) should be faster than regex (%f)',\n";
        $test .= "                \$strContainsTime,\n";
        $test .= "                \$regexTime\n";
        $test .= "            )\n";
        $test .= "        );\n\n";

        $test .= "        // Output for information\n";
        $test .= "        fwrite(STDOUT, sprintf(\n";
        $test .= "            \"\\nPerformance (\" . self::ITERATIONS . \" iterations):\\n\" .\n";
        $test .= "            \"- Regex:        %.6f seconds\\n\" .\n";
        $test .= "            \"- str_contains: %.6f seconds\\n\" .\n";
        $test .= "            \"- Speedup:      %.2fx\\n\",\n";
        $test .= "            \$regexTime,\n";
        $test .= "            \$strContainsTime,\n";
        $test .= "            \$regexTime / \$strContainsTime\n";
        $test .= "        ));\n";
        $test .= "    }\n";
        $test .= "}\n";

        return $test;
    }
}

// Main execution
$generator = new RegexTestGenerator();

$outputDir = __DIR__ . '/../tests/Unit/Pattern';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Generate test files
$files = [
    'SimpleKeywordDetectionTest.php' => $generator->generateTestClass([]),
    'RegexVsStrContainsComparisonTest.php' => $generator->generateComparisonTest([]),
    'RegexPerformanceBenchmarkTest.php' => $generator->generateBenchmarkTest(),
];

foreach ($files as $filename => $content) {
    $filepath = $outputDir . '/' . $filename;
    file_put_contents($filepath, $content);
    echo "✅ Generated: $filepath\n";
}

echo "\n✨ Test generation complete!\n";
echo "\nRun tests with:\n";
echo "  vendor/bin/phpunit tests/Unit/Pattern/\n";
