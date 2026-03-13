# Creating a New Analyzer

This guide walks you through every step needed to add a new analyzer to Doctrine Doctor.

## Table of Contents

- [Architecture Overview](#architecture-overview)
- [Step 1 — Create the Analyzer Class](#step-1--create-the-analyzer-class)
- [Step 2 — Register the IssueType](#step-2--register-the-issuetype)
- [Step 3 — Write the Detection Logic](#step-3--write-the-detection-logic)
- [Step 4 — Craft the Issue Title, Description and Severity](#step-4--craft-the-issue-title-description-and-severity)
- [Step 5 — Create the Suggestion Template](#step-5--create-the-suggestion-template)
- [Step 6 — Register the Service](#step-6--register-the-service)
- [Putting It All Together](#putting-it-all-together)
- [Step 7 — Write Tests](#step-7--write-tests)
- [Using the AST Parser Instead of Regex](#using-the-ast-parser-instead-of-regex)
- [Creating a Custom Visitor](#creating-a-custom-visitor)
- [Decoupling Detection with the Strategy Pattern](#decoupling-detection-with-the-strategy-pattern)
- [Existing Services and Traits You Should Reuse](#existing-services-and-traits-you-should-reuse)
- [Checklist Before Submitting](#checklist-before-submitting)

---

## Architecture Overview

```
Analyzer
  │  implements AnalyzerInterface
  │  receives QueryDataCollection (captured SQL queries)
  │  returns IssueCollection (generator-based, memory efficient)
  │
  ├── Detection logic
  │     query-based  → filter/group QueryDataCollection
  │     code-based   → use PhpCodeParser + Visitors on entity source
  │     metadata-based → iterate Doctrine ClassMetadata
  │
  ├── Issue creation
  │     IssueData DTO → IssueFactory → concrete Issue
  │
  └── Suggestion
        SuggestionFactory → renders a PHP template
        SuggestionMetadata → type, severity, title, tags
```

**Categories:** Analyzers live in one of four namespaces:

| Namespace | Purpose |
|-----------|---------|
| `Analyzer\Performance` | Query patterns, N+1, hydration, caching |
| `Analyzer\Security` | SQL injection, insecure random, data exposure |
| `Analyzer\Integrity` | Entity mapping errors, uninitialized collections |
| `Analyzer\Configuration` | Database config (charset, timezone, strict mode) |

---

## Step 1 — Create the Analyzer Class

Create a new file in the appropriate namespace:

```
src/Analyzer/{Category}/YourAnalyzer.php
```

Implement `AnalyzerInterface`. This is the only required contract:

```php
<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Concern\ShortClassNameTrait;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

class YourAnalyzer implements AnalyzerInterface
{
    use ShortClassNameTrait;

    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactoryInterface $suggestionFactory,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            function () use ($queryDataCollection) {
                // detection + yield issues here
            },
        );
    }
}
```

**Why `IssueCollection::fromGenerator()`?**
Issues are yielded lazily. The profiler only materializes what it needs, keeping memory usage constant even with hundreds of analyzers.

---

## Step 2 — Register the IssueType

Add a new case to `src/ValueObject/IssueType.php`:

```php
case YOUR_ISSUE_TYPE = 'your_issue_type';
```

This enum value is used in `IssueData` and by the `IssueFactory` to resolve the concrete issue class.

---

## Step 3 — Write the Detection Logic

### Query-based detection (Performance analyzers)

Use `QueryDataCollection` methods to filter and group queries:

```php
// 1. Filter to SELECT queries only
$selects = $queryDataCollection->filter(
    fn (QueryData $q): bool => $q->isSelect(),
);

// 2. Group by normalized SQL pattern
$groups = $selects->groupByPattern(
    fn (string $sql): string => $this->normalizeQuery($sql),
);

// 3. Check thresholds
foreach ($groups as $pattern => $group) {
    if ($group->count() >= $this->threshold) {
        yield $this->createIssue($group);
    }
}
```

**Why these three steps?**

This pipeline filters noise at each stage to surface real problems:

1. **Filter SELECT queries** — Only reads can produce N+1 patterns. INSERT, UPDATE, DELETE are write operations that serve a different purpose. Keeping them in the dataset would pollute the grouping step with irrelevant noise.

2. **Group by normalized SQL** — Normalization replaces concrete values with placeholders so that `SELECT * FROM users WHERE id = 1` and `SELECT * FROM users WHERE id = 42` become the same pattern. Grouping by that pattern reveals repetitive queries: if the same pattern appears 50 times, something is loading entities one by one in a loop instead of using a single `JOIN` or `WHERE IN`.

3. **Compare against the threshold** — Not every repeated query is a problem. Two identical SELECTs can be perfectly normal (e.g., a guard query at the start and end of a request). The threshold (default: 5) is the boundary between "normal usage" and "likely N+1". It is configurable per project: a high-traffic API might lower it to 3 to catch issues early, while a batch command might raise it to 20 to avoid false positives on intentional loops.

`QueryData` exposes everything captured at runtime:

| Property | Type | Description |
|----------|------|-------------|
| `$sql` | `string` | Raw SQL text |
| `$executionTime` | `QueryExecutionTime` | Timing (call `->inMilliseconds()`, `->format()`) |
| `$params` | `array` | Bound parameters |
| `$backtrace` | `?array` | PHP call stack at query time |
| `$rowCount` | `?int` | Rows returned/affected |

### Metadata-based detection (Integrity analyzers)

Iterate Doctrine metadata via `EntityManagerInterface`:

```php
public function __construct(
    private readonly EntityManagerInterface $entityManager,
    // ...
) {
}

public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
{
    return IssueCollection::fromGenerator(
        function () {
            $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

            foreach ($allMetadata as $classMetadata) {
                yield from $this->analyzeEntity($classMetadata);
            }
        },
    );
}
```

Use `MappingHelper` to read association mappings in a version-agnostic way (supports both Doctrine ORM 2.x arrays and 3.x/4.x objects):

```php
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;

$targetEntity = MappingHelper::getString($mapping, 'targetEntity');
$cascade      = MappingHelper::getArray($mapping, 'cascade');
$orphanRemoval = MappingHelper::getBool($mapping, 'orphanRemoval');
$joinColumns  = MappingHelper::getArray($mapping, 'joinColumns');
```

---

## Step 4 — Craft the Issue Title, Description and Severity

An issue has three user-facing text fields. Each serves a distinct purpose in the profiler panel:

### Title

One short line shown in the issue list. Include the entity or field name so the user can locate the problem at a glance.

```php
$title = sprintf('Uninitialized collection in %s::$%s', $shortClassName, $fieldName);
```

Rules:
- Under 80 characters.
- Start with the problem, not the solution.
- Include the concrete entity/field/query pattern.

### Description

Shown when the user expands the issue. Explain **what** is wrong and **why** it matters. Use `DescriptionHighlighter` to wrap code fragments in `<code>` tags automatically:

```php
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;

$description = DescriptionHighlighter::highlight(
    'Entity {entity} executes {count} identical {keyword} queries on {table}. '
    . 'This is a typical N+1 pattern that degrades response time linearly.',
    [
        'entity'  => $entityClass,    // auto-detected as class name (has backslash)
        'count'   => (string) $count, // auto-detected as code
        'keyword' => 'SELECT',        // auto-detected as SQL keyword (uppercase)
        'table'   => 'users',         // auto-detected as DB object
    ],
);
```

`DescriptionHighlighter` auto-detects the type of each value:

| Pattern | Rendered as | Example |
|---------|-------------|---------|
| Contains `\` | Class name | `App\Entity\User` |
| Ends with `()` | Method call | `flush()` |
| Quoted (`"` or `'`) | String value | `"remove"` |
| All uppercase | SQL keyword | `SELECT` |
| Contains `.` (no spaces) | DB object | `mysql.time_zone` |
| Default | Generic code | `$orders` |

You can also call the specific helpers directly: `DescriptionHighlighter::keyword()`, `::method()`, `::value()`, `::class()`, `::dbObject()`, `::code()`.

### Severity

Three levels, used for sorting and filtering in the profiler:

| Level | When to use | Priority |
|-------|-------------|----------|
| `Severity::critical()` | Will crash or corrupt data at runtime | 3 |
| `Severity::warning()` | Performance degradation or bad practice | 2 |
| `Severity::info()` | Optimization opportunity, no immediate harm | 1 |

### Putting it together with IssueData

```php
$issueData = new IssueData(
    type: IssueType::YOUR_TYPE->value,
    title: $title,
    description: $description,
    severity: Severity::warning(),
    suggestion: $suggestion,           // created in step 5
    queries: $matchingQueries,         // array of QueryData — shown in profiler with timing
    backtrace: $firstQuery->backtrace, // optional — pinpoints where the query was triggered
);

yield $this->issueFactory->create($issueData);
```

**Queries and response time:** When you attach `QueryData` objects to an issue, the profiler displays each query's SQL and execution time. `IssueData` automatically deduplicates queries by normalized SQL pattern, and `getTotalExecutionTime()` sums all durations.

**Backtrace:** When provided, the profiler shows the file and line where the problematic code was called, helping the user jump straight to the source.

---

## Step 5 — Create the Suggestion Template

Create a PHP template in `src/Template/Suggestions/{Category}/`:

```
src/Template/Suggestions/Performance/your_template.php
```

### Template structure

```php
<?php

declare(strict_types=1);

$fieldName = (string) ($context['field_name'] ?? 'items');
$entityClass = (string) ($context['entity_class'] ?? 'Entity');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>
<div class="suggestion-header"><h4>Your suggestion title</h4></div>
<div class="suggestion-content">

<div class="alert alert-danger">
    Key metric or problem statement
</div>

<h4>Before</h4>
<div class="query-item"><pre><code class="language-php">
// problematic code
</code></pre></div>

<h4>After</h4>
<div class="query-item"><pre><code class="language-php">
// fixed code
</code></pre></div>

<p><a href="https://..." target="_blank" rel="noopener noreferrer" class="doc-link">
    Doctrine documentation link
</a></p>

</div>
<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => sprintf('Short description for %s', $e($entityClass)),
];
```

### Available CSS classes

| Class | Renders as |
|-------|-----------|
| `suggestion-header` | Top bar with title |
| `suggestion-content` | Main content area |
| `alert alert-danger` | Red alert box |
| `alert alert-warning` | Yellow alert box |
| `alert alert-info` | Blue info box |
| `query-item` | Code block container |
| `doc-link` | External documentation link |
| `language-php`, `language-sql` | Prism.js syntax highlighting |

### Calling the template from the analyzer

```php
$suggestion = $this->suggestionFactory->createFromTemplate(
    templateName: 'Performance/your_template',  // relative to src/Template/Suggestions/
    context: [
        'field_name'   => $fieldName,
        'entity_class' => $shortClassName,
        'query_count'  => $count,
    ],
    suggestionMetadata: new SuggestionMetadata(
        type: SuggestionType::performance(),
        severity: Severity::warning(),
        title: 'Descriptive title shown in the profiler',
        tags: ['performance', 'doctrine', 'n+1'],
    ),
);
```

`SuggestionMetadata` fields:

| Field | Purpose |
|-------|---------|
| `type` | Category (`performance()`, `security()`, `integrity()`, `configuration()`, `bestPractice()`, `refactoring()`) |
| `severity` | Matches the issue severity — determines badge color in profiler |
| `title` | Shown in the suggestion header |
| `tags` | Used for filtering and grouping in the profiler panel |

---

## Step 6 — Register the Service

Add the analyzer to `config/services.yaml`:

```yaml
AhmedBhs\DoctrineDoctor\Analyzer\Performance\YourAnalyzer:
    arguments:
        $entityManager: '@doctrine_doctor.entity_manager'
        $threshold: '%doctrine_doctor.analyzers.your_analyzer.threshold%'
    tags:
        - { name: doctrine_doctor.analyzer }
```

The tag `doctrine_doctor.analyzer` is required. The `DataCollector` discovers all analyzers via `!tagged_iterator`.

If your analyzer only needs `IssueFactoryInterface` and `SuggestionFactoryInterface`, autowiring handles it — you only need the tag:

```yaml
AhmedBhs\DoctrineDoctor\Analyzer\Performance\YourAnalyzer:
    tags:
        - { name: doctrine_doctor.analyzer }
```

---

## Putting It All Together

Below is a complete, working analyzer that detects repeated identical INSERT queries (a sign that batch inserts should be used instead of single-row inserts in a loop). Every step from the guide is applied.

### The analyzer class

```php
<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Concern\ShortClassNameTrait;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

// Step 1 — Implement AnalyzerInterface
class RepeatedInsertAnalyzer implements AnalyzerInterface
{
    // Reuse ShortClassNameTrait for entity names in titles/descriptions
    use ShortClassNameTrait;

    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,         // creates Issue objects
        private readonly SuggestionFactoryInterface $suggestionFactory, // renders suggestion templates
        private readonly int $threshold = 10,                          // configurable threshold
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        // Use generator for lazy evaluation — memory efficient
        return IssueCollection::fromGenerator(
            function () use ($queryDataCollection) {
                // Step 3a — Filter: keep only INSERT queries
                $inserts = $queryDataCollection->filter(
                    fn (QueryData $query): bool => $query->isInsert(),
                );

                // Step 3b — Group by normalized SQL pattern
                // "INSERT INTO users (name) VALUES ('Alice')" and
                // "INSERT INTO users (name) VALUES ('Bob')"
                // become the same pattern
                $groups = $inserts->groupByPattern(
                    fn (string $sql): string => preg_replace(
                        '/VALUES\s*\(.*?\)/i',
                        'VALUES (?)',
                        $sql,
                    ) ?? $sql,
                );

                // Step 3c — Check threshold
                foreach ($groups as $pattern => $group) {
                    if ($group->count() < $this->threshold) {
                        continue;
                    }

                    $queriesArray = $group->toArray();
                    $firstQuery = $queriesArray[0];
                    $table = $this->extractTableName($firstQuery->sql);

                    // Step 4 — Build the issue with title, description, severity
                    $title = sprintf('Repeated INSERT on %s (%d times)', $table, $group->count());

                    $description = DescriptionHighlighter::highlight(
                        '{count} identical {keyword} queries executed on {table}. '
                        . 'This typically means entities are persisted one by one in a loop. '
                        . 'Use batch inserts with periodic flush() and clear() to reduce '
                        . 'database round-trips.',
                        [
                            'count'   => (string) $group->count(),
                            'keyword' => 'INSERT',
                            'table'   => $table,
                        ],
                    );

                    // Step 5 — Create the suggestion from a template
                    $suggestion = $this->suggestionFactory->createFromTemplate(
                        templateName: 'Performance/repeated_insert',
                        context: [
                            'table'       => $table,
                            'insert_count' => $group->count(),
                            'threshold'   => $this->threshold,
                        ],
                        suggestionMetadata: new SuggestionMetadata(
                            type: SuggestionType::performance(),
                            severity: Severity::warning(),
                            title: sprintf('Use batch inserts for %s', $table),
                            tags: ['performance', 'doctrine', 'batch', 'insert'],
                        ),
                    );

                    // Assemble the IssueData DTO
                    $issueData = new IssueData(
                        type: IssueType::BULK_OPERATION->value,
                        title: $title,
                        description: $description,
                        severity: Severity::warning(),
                        suggestion: $suggestion,
                        queries: $queriesArray,     // displayed in profiler with execution times
                        backtrace: $firstQuery->backtrace, // pinpoints trigger location
                    );

                    yield $this->issueFactory->create($issueData);
                }
            },
        );
    }

    private function extractTableName(string $sql): string
    {
        if (1 === preg_match('/INSERT\s+INTO\s+[`"]?(\w+)/i', $sql, $matches)) {
            return $matches[1];
        }

        return 'unknown';
    }
}
```

### The suggestion template

`src/Template/Suggestions/Performance/repeated_insert.php`:

```php
<?php

declare(strict_types=1);

$table = (string) ($context['table'] ?? 'entities');
$insertCount = (int) ($context['insert_count'] ?? 0);
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>
<div class="suggestion-header"><h4>Use batch inserts</h4></div>
<div class="suggestion-content">

<div class="alert alert-warning">
    <strong><?php echo $insertCount; ?></strong> individual INSERT queries on
    <code><?php echo $e($table); ?></code>
</div>

<p>Inserting entities one by one forces a database round-trip for each row.
Batch processing reduces this to one round-trip per batch.</p>

<h4>Before</h4>
<div class="query-item"><pre><code class="language-php">foreach ($items as $item) {
    $entityManager->persist($item);
    $entityManager->flush(); // INSERT on every iteration
}</code></pre></div>

<h4>After</h4>
<div class="query-item"><pre><code class="language-php">$batchSize = 50;

foreach ($items as $i => $item) {
    $entityManager->persist($item);

    if (0 === ($i + 1) % $batchSize) {
        $entityManager->flush();
        $entityManager->clear();
    }
}

$entityManager->flush(); // remaining items</code></pre></div>

<p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/batch-processing.html"
      target="_blank" rel="noopener noreferrer" class="doc-link">
    Doctrine ORM Batch Processing
</a></p>

</div>
<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => sprintf('Use batch inserts for %s table', $e($table)),
];
```

### The service registration

`config/services.yaml`:

```yaml
AhmedBhs\DoctrineDoctor\Analyzer\Performance\RepeatedInsertAnalyzer:
    arguments:
        $threshold: '%doctrine_doctor.analyzers.repeated_insert.threshold%'
    tags:
        - { name: doctrine_doctor.analyzer }
```

### The IssueType enum entry

`src/ValueObject/IssueType.php` — uses the existing `BULK_OPERATION` case here, but if your analyzer covers a new concept, add a new case:

```php
case REPEATED_INSERT = 'repeated_insert';
```

### The tests

```php
final class RepeatedInsertAnalyzerTest extends TestCase
{
    private RepeatedInsertAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new RepeatedInsertAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            threshold: 5,
        );
    }

    public function test_it_detects_repeated_inserts_above_threshold(): void
    {
        // Arrange — 6 identical INSERT queries (above threshold of 5)
        $builder = QueryDataBuilder::create();
        for ($i = 0; $i < 6; ++$i) {
            $builder->addInsert(
                "INSERT INTO products (name) VALUES ('Product $i')",
                executionMs: 1.2,
            );
        }

        // Act
        $issues = $this->analyzer->analyze($builder->build());
        $issuesArray = $issues->toArray();

        // Assert
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('products', $issuesArray[0]->getTitle());
        self::assertStringContainsString('6 times', $issuesArray[0]->getTitle());
        self::assertSame('warning', $issuesArray[0]->getSeverity()->value);
        self::assertNotNull($issuesArray[0]->getSuggestion());
    }

    public function test_it_ignores_inserts_below_threshold(): void
    {
        // Arrange — 3 INSERTs (below threshold of 5)
        $builder = QueryDataBuilder::create();
        for ($i = 0; $i < 3; ++$i) {
            $builder->addInsert("INSERT INTO products (name) VALUES ('Product $i')");
        }

        // Act
        $issues = $this->analyzer->analyze($builder->build());

        // Assert — no issue raised
        self::assertCount(0, $issues->toArray());
    }

    public function test_it_ignores_select_queries(): void
    {
        // Arrange — many SELECTs, no INSERTs
        $builder = QueryDataBuilder::create();
        for ($i = 0; $i < 20; ++$i) {
            $builder->addSelect('SELECT * FROM products WHERE id = ?');
        }

        // Act
        $issues = $this->analyzer->analyze($builder->build());

        // Assert — not this analyzer's concern
        self::assertCount(0, $issues->toArray());
    }

    public function test_it_groups_different_tables_separately(): void
    {
        // Arrange — 6 inserts on products, 6 on categories
        $builder = QueryDataBuilder::create();
        for ($i = 0; $i < 6; ++$i) {
            $builder->addInsert("INSERT INTO products (name) VALUES ('P$i')");
            $builder->addInsert("INSERT INTO categories (name) VALUES ('C$i')");
        }

        // Act
        $issues = $this->analyzer->analyze($builder->build());

        // Assert — two separate issues, one per table
        self::assertCount(2, $issues->toArray());
    }

    public function test_it_attaches_queries_and_backtrace_to_issue(): void
    {
        $builder = QueryDataBuilder::create();
        for ($i = 0; $i < 6; ++$i) {
            $builder->addInsert(
                "INSERT INTO orders (total) VALUES ($i)",
                executionMs: 2.0,
            );
        }

        $issues = $this->analyzer->analyze($builder->build());
        $issue = $issues->toArray()[0];

        // Queries are attached for display in the profiler
        self::assertNotEmpty($issue->getQueries());

        // Suggestion contains rendered HTML
        self::assertNotNull($issue->getSuggestion());
        self::assertStringContainsString('batch', strtolower($issue->getSuggestion()->getCode()));
    }
}
```

---

## Step 7 — Write Tests

Create a test in `tests/Analyzer/{Category}/` or `tests/Unit/Analyzer/`.

Use `QueryDataBuilder` to build test query collections and `PlatformAnalyzerTestHelper` to create factory instances for tests.

---

## Using the AST Parser Instead of Regex

When your analyzer inspects PHP source code (entity methods, constructors, etc.), **always use `PhpCodeParser` over regex**.

### Why

| | Regex | AST (PhpCodeParser) |
|---|---|---|
| Comments | False positives | Ignored automatically |
| Strings | False positives | Ignored automatically |
| Formatting | Brittle | Handles any style |
| Nested patterns | Extremely hard | Natural tree traversal |
| Testability | Fragile | Clean unit tests |

### How to use PhpCodeParser

Inject it in your analyzer:

```php
public function __construct(
    private readonly PhpCodeParser $phpCodeParser,
) {
}
```

Or use the built-in fallback:

```php
public function __construct(
    ?PhpCodeParser $phpCodeParser = null,
) {
    $this->phpCodeParser = $phpCodeParser ?? new PhpCodeParser();
}
```

Available detection methods (no need to write a visitor for these):

```php
// Check if a field is initialized in a method
$this->phpCodeParser->hasCollectionInitialization($reflectionMethod, 'orders');

// Check if a method calls a specific pattern
$this->phpCodeParser->hasMethodCall($reflectionMethod, 'initializeTranslations*');

// Detect insecure random usage
$this->phpCodeParser->detectInsecureRandom($reflectionMethod, ['rand', 'mt_rand']);

// Detect json_encode($this) or serialize($this)
$this->phpCodeParser->detectSensitiveExposure($reflectionMethod);

// Detect exposed sensitive fields
$this->phpCodeParser->detectExposedSensitiveFields($reflectionMethod, ['password', 'token']);

// Detect SQL injection patterns
$this->phpCodeParser->detectSqlInjectionPatterns($reflectionMethod);
```

`PhpCodeParser` caches ASTs (up to 1000 entries) and analysis results (auto-invalidated on file change). No need to manage caching yourself.

---

## Creating a Custom Visitor

When `PhpCodeParser` does not have a built-in method for your detection, create a visitor.

### 1. Create the visitor class

```
src/Analyzer/Parser/Visitor/YourPatternVisitor.php
```

```php
<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser\Visitor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class YourPatternVisitor extends NodeVisitorAbstract
{
    private bool $detected = false;

    public function enterNode(Node $node): ?Node
    {
        // Check for your pattern in the AST node
        if ($this->matchesPattern($node)) {
            $this->detected = true;
        }

        return null;
    }

    public function isDetected(): bool
    {
        return $this->detected;
    }

    private function matchesPattern(Node $node): bool
    {
        // Your detection logic using PhpParser node types:
        // Node\Expr\Assign, Node\Expr\MethodCall, Node\Expr\FuncCall,
        // Node\Expr\New_, Node\Param, Node\Stmt\Class_, etc.
        return false;
    }
}
```

### 2. Use it via PhpCodeParser

Add a method to `PhpCodeParser` or use it directly:

```php
$code = $this->phpCodeParser->extractMethodCode($reflectionMethod);
$ast  = $this->phpCodeParser->parse($code);

$visitor   = new YourPatternVisitor();
$traverser = new NodeTraverser();
$traverser->addVisitor($visitor);
$traverser->traverse($ast);

if ($visitor->isDetected()) {
    // create issue
}
```

---

## Decoupling Detection with the Strategy Pattern

When a visitor needs to detect **multiple patterns** that may grow over time, extract each pattern into its own class behind an interface. This follows the Open/Closed Principle — adding a new pattern requires a new class, not modifying existing code.

### Example: CollectionInitializationVisitor

The visitor detects three patterns for collection initialization:

1. `$this->field = new ArrayCollection()` — explicit assignment
2. `$this->field = []` — empty array
3. `private Collection $field = new ArrayCollection()` — constructor promotion

Each pattern is a class implementing `InitializationPatternInterface`:

```php
interface InitializationPatternInterface
{
    public function matches(Node $node, string $fieldName): bool;
}
```

The visitor iterates them:

```php
final class CollectionInitializationVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly string $fieldName,
        ?array $patterns = null,
    ) {
        $this->patterns = $patterns ?? self::defaultPatterns();
    }

    public function enterNode(Node $node): ?Node
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($node, $this->fieldName)) {
                $this->hasInitialization = true;
                return null;
            }
        }
        return null;
    }
}
```

Shared logic lives in reusable traits:

- `CollectionClassTrait` — `isCollectionClass()`, `getClassName()` for matching Doctrine collection types
- `ThisPropertyAccessTrait` — `isThisPropertyAccess()` for matching `$this->fieldName` patterns

To add a fourth pattern (e.g., factory method initialization), create a new class implementing `InitializationPatternInterface` and add it to `defaultPatterns()`. No existing code changes.

---

## Existing Services and Traits You Should Reuse

Before writing utility code, check if it already exists:

### Traits

| Trait | Location | Purpose |
|-------|----------|---------|
| `ShortClassNameTrait` | `Analyzer\Concern` | `$this->shortClassName('App\Entity\User')` returns `'User'` — use in titles and descriptions |
| `CollectionClassTrait` | `Parser\Visitor\InitializationPattern` | Check if a class name is a Doctrine collection type |
| `ThisPropertyAccessTrait` | `Parser\Visitor\InitializationPattern` | Check if an AST node is a `$this->property` access |

### Services

| Service | Purpose | When to use |
|---------|---------|-------------|
| `IssueFactoryInterface` | Creates concrete `Issue` objects from `IssueData` | Every analyzer needs this |
| `SuggestionFactoryInterface` | Renders suggestion templates and creates `SuggestionInterface` | Every analyzer with a fix suggestion |
| `PhpCodeParser` | Parses PHP source into AST, caches results | When inspecting entity source code |
| `SqlStructureExtractor` | Parses SQL queries, detects patterns (joins, subqueries, lazy loading) | When analyzing captured SQL |
| `SqlNormalizationCache` | Normalizes SQL for grouping (removes literals, params) | When deduplicating or grouping queries |

### Helpers

| Helper | Purpose |
|--------|---------|
| `MappingHelper` | Version-agnostic access to Doctrine association mappings (ORM 2.x arrays and 3.x+ objects) |
| `DescriptionHighlighter` | Wraps code fragments in `<code>` tags with auto-type-detection |

### Value Objects

| Value Object | Purpose |
|--------------|---------|
| `Severity` | `critical()`, `warning()`, `info()` — comparison with `isHigherThan()`, `getPriority()` |
| `SuggestionType` | `performance()`, `security()`, `integrity()`, `configuration()`, `bestPractice()`, `refactoring()` |
| `SuggestionMetadata` | Groups type + severity + title + tags for the suggestion |
| `QueryExecutionTime` | Wraps timing — `inMilliseconds()`, `format()` (renders `"1.23s"` or `"45ms"`) |
| `IssueType` | Enum of all known issue types |

### Test Helpers

| Helper | Purpose |
|--------|---------|
| `QueryDataBuilder` | Fluent builder: `QueryDataBuilder::create()->addSelect($sql)->build()` |
| `PlatformAnalyzerTestHelper` | `::createIssueFactory()`, `::createSuggestionFactory()`, `::createTestEntityManager()` |
| `DatabaseTestCase` | Base class with SQLite EntityManager and `createSchema()` |

---

## Checklist Before Submitting

- [ ] Analyzer implements `AnalyzerInterface`
- [ ] `IssueType` enum has a new case for your issue
- [ ] Service registered in `config/services.yaml` with `doctrine_doctor.analyzer` tag
- [ ] Suggestion template created in `src/Template/Suggestions/{Category}/`
- [ ] Title is concise and includes the entity/field name
- [ ] Description uses `DescriptionHighlighter` for code fragments
- [ ] Severity matches the actual impact (`critical` = crashes, `warning` = perf, `info` = nice-to-have)
- [ ] AST parser used instead of regex for PHP source analysis
- [ ] Tests cover detection and no-false-positive scenarios
- [ ] `php vendor/bin/phpunit` passes
- [ ] `php vendor/bin/phpstan analyse` passes
- [ ] `php vendor/bin/phpmd analyze src/ --format text --ruleset phpmd.xml` passes
