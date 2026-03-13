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
// Filter to SELECT queries only
$selects = $queryDataCollection->filter(
    fn (QueryData $q): bool => $q->isSelect(),
);

// Group by normalized SQL pattern
$groups = $selects->groupByPattern(
    fn (string $sql): string => $this->normalizeQuery($sql),
);

// Check thresholds
foreach ($groups as $pattern => $group) {
    if ($group->count() >= $this->threshold) {
        yield $this->createIssue($group);
    }
}
```

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

## Step 7 — Write Tests

Create a test in `tests/Analyzer/{Category}/` or `tests/Unit/Analyzer/`:

```php
final class YourAnalyzerTest extends DatabaseTestCase
{
    private YourAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema([Entity::class]);

        $this->analyzer = new YourAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    public function test_it_detects_the_problem(): void
    {
        $queries = QueryDataBuilder::create()
            ->addSelect('SELECT * FROM users WHERE id = ?', executionMs: 1.5)
            ->addSelect('SELECT * FROM users WHERE id = ?', executionMs: 2.0)
            ->build();

        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('expected keyword', $issuesArray[0]->getTitle());
    }

    public function test_it_does_not_flag_when_below_threshold(): void
    {
        $queries = QueryDataBuilder::create()
            ->addSelect('SELECT * FROM users WHERE id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues->toArray());
    }
}
```

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
