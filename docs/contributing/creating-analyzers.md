---
layout: default
title: Creating Analyzers
parent: Contributing
nav_order: 3
---

# Creating Custom Analyzers

Complete guide to creating your own analyzers for Doctrine Doctor.

---

## Analyzer Basics

An analyzer:

- Implements `AnalyzerInterface`
- Receives query data from a request
- Returns a collection of issues
- Is stateless and independent
- Can be configured via YAML

---

## Step-by-Step Guide

### Step 1: Create Analyzer Class

```php
<?php

declare(strict_types=1);

namespace App\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Model\IssueCollection;
use AhmedBhs\DoctrineDoctor\Model\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Model\Severity;
use AhmedBhs\DoctrineDoctor\Template\Renderer\TemplateRendererInterface;

final class CustomAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly TemplateRendererInterface $templateRenderer,
        private readonly int $threshold = 100
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        $issues = new IssueCollection();

        // Your analysis logic here
        foreach ($queryDataCollection as $queryData) {
            // Check for patterns
            if ($this->detectsIssue($queryData)) {
                $issues->add($this->createIssue($queryData));
            }
        }

        return $issues;
    }

    private function detectsIssue($queryData): bool
    {
        // Detection logic
        return false;
    }

    private function createIssue($queryData)
    {
        // Issue creation
    }
}
```

### Step 2: Register as Service

```yaml
# config/services.yaml
services:
    App\Analyzer\CustomAnalyzer:
        arguments:
            $threshold: '%env(int:CUSTOM_ANALYZER_THRESHOLD)%'
        tags:
            - { name: 'doctrine_doctor.analyzer' }
```

### Step 3: Add Configuration

```php
<?php

// config/packages/doctrine_doctor.yaml
doctrine_doctor:
    analyzers:
        custom_analyzer:
            enabled: true
            threshold: 100
```

### Step 4: Create Template (Optional)

```php
<?php
// templates/doctrine_doctor/custom_issue.php

$code = <<<CODE
// Your suggestion code here
\$repository->createQueryBuilder('e')
    ->where('e.field = :value')
    ->setParameter('value', \$value);
CODE;

$description = "Detailed explanation of the issue and solution.";

return ['code' => $code, 'description' => $description];
```

### Step 5: Write Tests

```php
<?php

namespace App\Tests\Analyzer;

use App\Analyzer\CustomAnalyzer;
use PHPUnit\Framework\TestCase;

final class CustomAnalyzerTest extends TestCase
{
    public function testDetectsIssue(): void
    {
        // Arrange
        $analyzer = new CustomAnalyzer(
            $this->createMock(IssueFactoryInterface::class),
            $this->createMock(TemplateRendererInterface::class),
            threshold: 100
        );

        $queryDataCollection = $this->createQueryDataCollection();

        // Act
        $issues = $analyzer->analyze($queryDataCollection);

        // Assert
        $this->assertCount(1, $issues);
    }

    private function createQueryDataCollection()
    {
        // Helper to create test data
    }
}
```

---

## Real Example: Detecting Large OFFSET

Let's create an analyzer that detects inefficient OFFSET usage in pagination.

### Implementation

```php
<?php

declare(strict_types=1);

namespace App\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Model\Issue;
use AhmedBhs\DoctrineDoctor\Model\IssueCollection;
use AhmedBhs\DoctrineDoctor\Model\QueryData;
use AhmedBhs\DoctrineDoctor\Model\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Model\Severity;
use AhmedBhs\DoctrineDoctor\Template\Renderer\TemplateRendererInterface;

/**
 * Detects large OFFSET values in pagination queries.
 *
 * Large OFFSET values force the database to scan and skip many rows,
 * becoming exponentially slower as the offset increases.
 *
 * @author Your Name
 */
final class LargeOffsetAnalyzer implements AnalyzerInterface
{
    private const TYPE = 'large_offset';
    private const CATEGORY = 'performance';

    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly TemplateRendererInterface $templateRenderer,
        private readonly int $offsetThreshold = 10000
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        $issues = new IssueCollection();

        foreach ($queryDataCollection as $queryData) {
            $offset = $this->extractOffset($queryData);

            if ($offset >= $this->offsetThreshold) {
                $issues->add($this->createIssue($queryData, $offset));
            }
        }

        return $issues;
    }

    private function extractOffset(QueryData $queryData): int
    {
        $sql = $queryData->getSql();

        // Match OFFSET or LIMIT x,y syntax
        if (preg_match('/OFFSET\s+(\d+)/i', $sql, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/LIMIT\s+(\d+)\s*,\s*(\d+)/i', $sql, $matches)) {
            return (int) $matches[1]; // First number is offset in LIMIT x,y
        }

        return 0;
    }

    private function createIssue(QueryData $queryData, int $offset): Issue
    {
        $severity = $this->calculateSeverity($offset);

        $suggestion = $this->templateRenderer->render('large_offset', [
            'offset' => $offset,
            'threshold' => $this->offsetThreshold,
            'execution_time' => $queryData->getExecutionTimeMs(),
        ]);

        return $this->issueFactory->create(
            type: self::TYPE,
            title: sprintf('Large OFFSET detected: %d rows', $offset),
            description: sprintf(
                'Query uses OFFSET %d, which forces the database to scan and skip %d rows. '
                . 'This becomes exponentially slower as the offset increases.',
                $offset,
                $offset
            ),
            severity: $severity,
            category: self::CATEGORY,
            suggestion: $suggestion,
            queries: [$queryData->getSql()],
            backtrace: $queryData->getBacktrace(),
            data: [
                'offset' => $offset,
                'threshold' => $this->offsetThreshold,
                'execution_time_ms' => $queryData->getExecutionTimeMs(),
            ]
        );
    }

    private function calculateSeverity(int $offset): Severity
    {
        if ($offset >= 50000) {
            return Severity::CRITICAL;
        }

        if ($offset >= 20000) {
            return Severity::HIGH;
        }

        return Severity::MEDIUM;
    }
}
```

### Template

```php
<?php
// templates/doctrine_doctor/large_offset.php

/** @var array{offset: int, threshold: int, execution_time: float} $context */

$offset = $context['offset'];
$executionTime = $context['execution_time'];

ob_start();
?>
## Issue: Large OFFSET in Pagination

Your query uses `OFFSET <?= $offset ?>`, which forces the database to:
1. Scan <?= $offset ?> rows
2. Skip them all
3. Then return the actual results

**Execution time**: <?= number_format($executionTime, 2) ?>ms

## Solution: Cursor-Based Pagination

Instead of OFFSET, use the last ID from the previous page:

```php
// Instead of:
$query = $repository
    ->createQueryBuilder('e')
    ->orderBy('e.id', 'ASC')
    ->setFirstResult(<?= $offset ?>)  // Slow!
    ->setMaxResults(20);

// Use:
$query = $repository
    ->createQueryBuilder('e')
    ->where('e.id > :lastId')
    ->setParameter('lastId', $lastSeenId)
    ->orderBy('e.id', 'ASC')
    ->setMaxResults(20);
```

**Benefits**:
- Constant time complexity O(1)
- Works efficiently with millions of rows
- No performance degradation on deep pages

<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Use cursor-based pagination instead of OFFSET for better performance'
];
```

### Configuration

```yaml
# config/packages/doctrine_doctor.yaml
doctrine_doctor:
    analyzers:
        large_offset:
            enabled: true
            offset_threshold: 10000
```

### Tests

```php
<?php

namespace App\Tests\Analyzer\Performance;

use App\Analyzer\Performance\LargeOffsetAnalyzer;
use AhmedBhs\DoctrineDoctor\Model\QueryData;
use AhmedBhs\DoctrineDoctor\Model\QueryDataCollection;
use PHPUnit\Framework\TestCase;

final class LargeOffsetAnalyzerTest extends TestCase
{
    public function testDetectsLargeOffset(): void
    {
        $analyzer = $this->createAnalyzer(offsetThreshold: 10000);

        $queryData = new QueryData(
            sql: 'SELECT * FROM user LIMIT 20 OFFSET 15000',
            executionTimeMs: 234.5
        );

        $collection = new QueryDataCollection([$queryData]);
        $issues = $analyzer->analyze($collection);

        $this->assertCount(1, $issues);
        $this->assertSame('large_offset', $issues->first()->getType());
    }

    public function testIgnoresSmallOffset(): void
    {
        $analyzer = $this->createAnalyzer(offsetThreshold: 10000);

        $queryData = new QueryData(
            sql: 'SELECT * FROM user LIMIT 20 OFFSET 100',
            executionTimeMs: 5.2
        );

        $collection = new QueryDataCollection([$queryData]);
        $issues = $analyzer->analyze($collection);

        $this->assertCount(0, $issues);
    }

    public function testSeverityIncreaseWithOffset(): void
    {
        $analyzer = $this->createAnalyzer(offsetThreshold: 10000);

        $queryData = new QueryData(
            sql: 'SELECT * FROM user LIMIT 20 OFFSET 60000',
            executionTimeMs: 1234.5
        );

        $collection = new QueryDataCollection([$queryData]);
        $issues = $analyzer->analyze($collection);

        $this->assertSame('critical', $issues->first()->getSeverity()->value);
    }

    private function createAnalyzer(int $offsetThreshold): LargeOffsetAnalyzer
    {
        return new LargeOffsetAnalyzer(
            $this->createMock(IssueFactoryInterface::class),
            $this->createMock(TemplateRendererInterface::class),
            $offsetThreshold
        );
    }
}
```

---

## Best Practices

### 1. Single Responsibility

Each analyzer should detect ONE type of issue:

```php
// Good
class NPlusOneAnalyzer { }
class MissingIndexAnalyzer { }

// Bad
class PerformanceAnalyzer { } // Too broad
```

### 2. Configurable Thresholds

Allow users to adjust sensitivity:

```php
public function __construct(
    private readonly int $threshold = 100
) {
}
```

### 3. Clear Severity Levels

Use appropriate severity:

- **Critical**: Security issues, data loss
- **High**: Major performance problems
- **Medium**: Suboptimal patterns
- **Low**: Minor improvements

### 4. Actionable Suggestions

Provide concrete code examples:

```php
$suggestion = $this->templateRenderer->render('fix_template', [
    'before' => $problematicCode,
    'after' => $fixedCode,
]);
```

### 5. Include Context

Add metadata for debugging:

```php
return $this->issueFactory->create(
    // ...
    data: [
        'query_count' => $count,
        'execution_time_ms' => $time,
        'affected_entities' => $entities,
    ]
);
```

---

## Analyzer Categories

### Performance Analyzers

Focus on:

- Query efficiency
- Resource usage
- Database load

### Security Analyzers

Focus on:

- SQL injection
- Data exposure
- Insecure patterns

### Integrity Analyzers

Focus on:

- Data consistency
- Relationship correctness
- Mapping accuracy

### Configuration Analyzers

Focus on:

- Database settings
- Entity configuration
- Doctrine configuration

---

## Testing Strategies

### Unit Tests

Test analyzer logic in isolation:

```php
public function testDetectionLogic(): void
{
    $analyzer = new MyAnalyzer();
    $issues = $analyzer->analyze($testData);
    $this->assertCount(1, $issues);
}
```

### Integration Tests

Test with real Doctrine queries:

```php
public function testWithRealQueries(): void
{
    $em = $this->createEntityManager();
    // Execute real queries
    // Analyze with collector
    // Assert issues detected
}
```

### Edge Cases

Test boundary conditions:

```php
public function testThresholdBoundary(): void
{
    // Test at threshold - 1
    // Test at threshold
    // Test at threshold + 1
}
```

---

## Documentation

Document your analyzer:

```php
/**
 * Detects inefficient pagination with large OFFSET values.
 *
 * Large OFFSET forces the database to scan and skip many rows,
 * becoming exponentially slower as the offset increases.
 *
 * **Configuration**:
 * ```yaml
 * doctrine_doctor:
 *     analyzers:
 *         large_offset:
 *             offset_threshold: 10000
 * ```
 *
 * **Example Issue**:
 * ```sql
 * SELECT * FROM user LIMIT 20 OFFSET 50000
 * ```
 *
 * **Suggested Fix**:
 * Use cursor-based pagination instead of OFFSET.
 *
 * @author Your Name <email@example.com>
 */
```

---

## Contributing Your Analyzer

1. Create PR with analyzer code
2. Include tests
3. Add documentation
4. Update ANALYZERS.md
5. Add example use cases

---

**[← Development Setup](development)** | **[Configuration →]({{ site.baseurl }}/user-guide/configuration)**
