# Tests Doctrine Doctor

## 📁 Structure

```text
tests/
├── Analyzer/              # Unit tests for analyzers (53 files expected)
├── Collection/            # Collection tests (QueryData, Issues)
├── Collector/             # DataCollector tests
└── Integration/           # Integration tests (to create)
    ├── Database/
    ├── Fixtures/
    └── Analyzer/
```

## 🚀 Running Tests

### All tests

```bash
vendor/bin/phpunit
```

### Specific tests

```bash
# Single test
vendor/bin/phpunit tests/Analyzer/NPlusOneAnalyzerTest.php

# Folder tests
vendor/bin/phpunit tests/Analyzer/

# Specific test
vendor/bin/phpunit --filter testDetectsMissingConstructor
```

### With coverage

```bash
vendor/bin/phpunit --coverage-html coverage/
```

Then open `coverage/index.html` in your browser.

## 📖 Test Patterns

### 1. Simple Analyzer (Configuration)

Example: `StrictModeAnalyzerTest`

```php
/** @test */
public function it_detects_missing_configuration(): void
{
    // Arrange: Mock non-compliant configuration
    $this->connection
        ->method('fetchOne')
        ->willReturn('INVALID_VALUE');

    // Act
    $issues = $this->analyzer->analyze(QueryDataCollection::empty());

    // Assert
    $this->assertCount(1, $issues);
}
```

### 2. Analyzer with Queries

Example: `NPlusOneAnalyzerTest`

```php
/** @test */
public function it_detects_n_plus_one_queries(): void
{
    // Arrange: Create query pattern
    $queries = QueryDataCollection::fromArray([
        $this->createQuery('SELECT * FROM users'),
        $this->createQuery('SELECT * FROM posts WHERE user_id = 1'),
        $this->createQuery('SELECT * FROM posts WHERE user_id = 2'),
        // ... more similar queries
    ]);

    // Act
    $issues = $this->analyzer->analyze($queries);

    // Assert
    $this->assertCount(1, $issues);
    $this->assertEquals('N+1 Query', $issues->toArray()[0]->getType());
}
```

### 3. Analyzer with Metadata

Example: `CollectionInitializationAnalyzerTest`

```php
/** @test */
public function it_detects_uninitialized_collection(): void
{
    // Arrange: Create entity metadata with uninitialized collection
    $metadata = $this->createEntityMetadata([
        'items' => ['type' => ClassMetadata::ONE_TO_MANY]
    ]);

    // Create temp PHP file without initialization
    $tempFile = sys_get_temp_dir() . '/TestEntity.php';
    file_put_contents($tempFile, '<?php ...');

    // Act
    $issues = $this->analyzer->analyze(QueryDataCollection::empty());

    // Assert
    $this->assertCount(1, $issues);

    // Cleanup
    @unlink($tempFile);
}
```

## Checklist for a Good Test

For each analyzer, check:

- [ ] **Positive case**: No issue when everything is OK
- [ ] **Negative case**: Issue detected when problem present
- [ ] **Suggestion**: Suggestion provided when issue detected
- [ ] **Metadata**: `getName()` and `getDescription()` correct
- [ ] **Edge cases**: Null values, empty lists, exceptions
- [ ] **Severity**: Good severity (warning/critical)
- [ ] **Category**: Good category (performance/security/code_quality/configuration)

## Test Priorities

### 🔴 High Priority (Critical for users)

1. `NPlusOneAnalyzer` - Performance
2. `DQLInjectionAnalyzer` - Security
3. `SQLInjectionInRawQueriesAnalyzer` - Security
4. `MissingIndexAnalyzer` - Performance
5. `CollectionInitializationAnalyzer` - Code Quality

### 🟡 Medium Priority

1. `FloatForMoneyAnalyzer` - Data Integrity
2. `CascadeConfigurationAnalyzer` - Data Integrity
3. `BidirectionalConsistencyAnalyzer` - Data Integrity
4. `SetMaxResultsWithCollectionJoinAnalyzer` - Performance
5. `TransactionBoundaryAnalyzer` - Data Integrity

### 🟢 Low Priority (Configuration)

- `CharsetAnalyzer`
- `StrictModeAnalyzer`
- `InnoDBEngineAnalyzer`
- etc.

## 📖 Current Status

**Current coverage: ~7.5%** (4 tests / 53 analyzers)

Existing tests:

- `CollectionInitializationAnalyzerTest` (complete)
- `IssueCollectionTest` (complete)
- `QueryDataCollectionTest` (complete)
- `ProfilerOverheadTest` (complete)

### Goal: 80%+ coverage

## 🐛 Debugging

### See detailed assertions

```bash
vendor/bin/phpunit --testdox
```

### See only failures

```bash
vendor/bin/phpunit --stop-on-failure
```

### Verbose mode

```bash
vendor/bin/phpunit --verbose
```

## 📖 Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Testing Symfony Services](https://symfony.com/doc/current/testing.html)
- [TESTING_STRATEGY.md](../TESTING_STRATEGY.md) - Complete test plan

## 🤝 Contributing

To add tests:

1. Generate skeleton: `php bin/generate-test.php YourAnalyzer`
2. Implement test cases
3. Check coverage: `vendor/bin/phpunit --coverage-text`
4. Submit a PR

---

**Last updated:** $(date +%Y-%m-%d)
**Total Analyzers:** 53
**Tests Coverage:** 7.5% → Target: 80%
