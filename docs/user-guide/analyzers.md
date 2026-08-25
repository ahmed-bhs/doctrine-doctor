---
layout: default
title: Analyzers Catalog
parent: User Guide
nav_order: 2
---

# Analyzer Reference Documentation

---

## 1. Overview

Doctrine Doctor implements **98 specialized analyzers** organized into four categories that detect Doctrine ORM anti-patterns and performance issues.

### 1.1 Severity Classification

| Severity | Impact | Examples |
|----------|---------|----------|
| **Critical** | Security/data-loss/severe runtime risk | SQL injection, dangerous cascade |
| **Warning** | Important performance/integrity/config issues | N+1 patterns, missing indexes |
| **Info** | Optimization and maintainability recommendations | Naming and design improvements |

---

## 2. Analyzer Taxonomy

### 2.1 Distribution by Category

![Analyzer Categories Distribution](../images/categories.png)

### 2.2 Detection Methods

- **Static Analysis**: Entity metadata, configuration analysis
- **Runtime Analysis**: Query pattern recognition, signature matching
- **Database Analysis**: EXPLAIN query execution plans
- **Code Analysis**: Call stack inspection, trace analysis

---

## 3. Performance Analyzers

### 3.1 Category Overview

Performance analyzers detect patterns that degrade application responsiveness, increase database load, or consume excessive system resources.

**Total**: 30 analyzers
**Average Impact**: 10-1000x performance improvement when resolved

### 3.2 Key Performance Analyzers

#### 3.2.1 NPlusOneAnalyzer

- **Severity**: Critical
- **Purpose**: Detects N+1 query problems (1 initial query + N lazy-loaded queries)
- **Detection**: Query signature matching and execution pattern analysis
- **Impact**: 90-99% query reduction when fixed

#### 3.2.2 MissingIndexAnalyzer

- **Severity**: Critical
- **Purpose**: Identifies queries doing full table scans
- **Detection**: Database EXPLAIN plan analysis
- **Impact**: 10-1000x performance improvement

#### 3.2.3 FlushInLoopAnalyzer

- **Severity**: Critical
- **Purpose**: Finds EntityManager::flush() calls inside loops
- **Detection**: Call stack trace analysis
- **Impact**: 10-100x improvement by batching operations

#### 3.2.4 SlowQueryAnalyzer

- **Severity**: Warning
- **Purpose**: Flags queries exceeding execution time threshold
- **Detection**: Direct execution time measurement

#### 3.2.5 HydrationAnalyzer

- **Severity**: Info
- **Purpose**: Detects inefficient result set hydration
- **Impact**: 50-80% memory reduction

#### 3.2.6 CartesianProductAnalyzer

- **Severity**: Critical
- **Purpose**: Detects cartesian product risks caused by joining multiple collections
- **Detection**: Correlates multi-collection JOIN patterns with grouped N+1 collection behavior
- **Impact**: Prevents row explosion, duplicate hydration, memory spikes, and severe slowdowns
- **Example**: Joining multiple to-many associations in one query creates `N x M` result multiplication

> Note: Two classes under `src/Analyzer/` are intentionally absent from this catalog. `MissingIndexAnalyzerConfig` is a configuration object, not an analyzer, and `FlushInLoopAnalyzerModern` is an unfinished variant excluded from the `doctrine_doctor.analyzer` tag in `config/services.yaml`, so it never runs.

---

### 3.3 Analyzer Summary Table

| Analyzer ID | Detection Method | Typical Impact | Configuration |
|-------------|------------------|----------------|---------------|
| NPlusOneAnalyzer | Query signature matching | 90-99% query reduction | `threshold: 5` |
| MissingIndexAnalyzer | EXPLAIN analysis | 10-1000x speedup | `slow_query_threshold: 50` |
| SlowQueryAnalyzer | Execution time | Direct | `threshold: 100` (ms) |
| HydrationAnalyzer | Result set size | 50-80% memory reduction | `row_threshold: 99` |
| FlushInLoopAnalyzer | Trace analysis | 10-100x | `flush_count_threshold: 5` |
| EagerLoadingAnalyzer | JOIN count | Query optimization | `join_threshold: 4` |
| LazyLoadingAnalyzer | Proxy initialization | Query reduction | `threshold: 10` |
| DTOHydrationAnalyzer | Hydration mode | Memory + performance | — |
| BulkOperationAnalyzer | Entity count | 100-1000x | `threshold: 20` |
| QueryCachingOpportunityAnalyzer | Cache statistics | 50-90% reduction | — |
| EntityManagerClearAnalyzer | Memory usage | Memory leak prevention | `batch_size_threshold: 20` |
| JoinOptimizationAnalyzer | JOIN complexity | Query simplification | `max_joins_recommended: 5`, `max_joins_critical: 8` |
| CartesianProductAnalyzer | Multi-collection JOIN analysis | Prevent row explosion | `n1_collection_threshold: 3` |
| SetMaxResultsWithCollectionJoinAnalyzer | LIMIT + JOIN | Incorrect results | — |
| OrderByWithoutLimitAnalyzer | ORDER BY + full scan | Resource usage | — |
| FindAllAnalyzer | Unfiltered queries | Memory exhaustion | `threshold: 99` |
| YearFunctionOptimizationAnalyzer | Function in WHERE | Index usage | — |
| IneffectiveLikeAnalyzer | Leading wildcard | Full table scan | — |
| NPlusOneSqlAnalyzer | SQL-level N+1 detection | Query reduction | — |
| StructuralMissingIndexAnalyzer | WHERE columns vs declared indexes | Index coverage | — |
| DeepOffsetPaginationAnalyzer | Large OFFSET values | Scan cost grows with offset | — |
| PaginationWithoutOrderByAnalyzer | LIMIT without ORDER BY | Non-deterministic pages | — |
| OrderByNullableLeadingColumnAnalyzer | Nullable leading sort key | Rows skipped per platform | — |
| FunctionOnPredicateColumnAnalyzer | Function wrapping a WHERE column | Index not usable | — |
| ImplicitTypeConversionAnalyzer | Type mismatch in predicates | Index not usable | — |
| NotInSubqueryAnalyzer | `NOT IN` with a subquery | NULL semantics and cost | — |
| MissingTransactionOnBatchAnalyzer | Unwrapped batch writes | Per-statement commits | — |
| EagerLoadingMappingAnalyzer | `fetch: 'EAGER'` in mapping | Unrequested joins | — |
| GedmoExtensionPerformanceAnalyzer | Gedmo Loggable / Translatable | Extra writes and joins | — |
| GetReferenceAnalyzer | Full load where a reference suffices | Avoidable queries | — |

**Internal Parser Utilities** (not directly user-facing):
| SqlAggregationAnalyzer | Aggregation function analysis | Query optimization | Internal |
| SqlConditionAnalyzer | WHERE/ON clause analysis | Index effectiveness | Internal |
| SqlPerformanceAnalyzer | SQL pattern analysis | Performance insights | Internal |

---

## 4. Security Analyzers

### 4.1 Category Overview

Security analyzers detect vulnerabilities aligned with **OWASP Top 10** and Doctrine-specific attack vectors.

**Total**: 6 analyzers
**OWASP Coverage**: A02:2021 (Cryptographic Failures), A03:2021 (Injection), A05:2021 (Security Misconfiguration)

### 4.2 Key Security Analyzers

#### 4.2.1 DQLInjectionAnalyzer

- **Severity**: Critical
- **OWASP**: A03:2021 Injection
- **Purpose**: Detects DQL injection vulnerabilities in string concatenation
- **Detection**: AST analysis of DQL string construction

#### 4.2.2 SQLInjectionInRawQueriesAnalyzer

- **Severity**: Critical
- **OWASP**: A03:2021 Injection
- **Purpose**: Finds SQL injection in native queries
- **Detection**: Native query pattern analysis

#### 4.2.3 SensitiveDataExposureAnalyzer

- **Severity**: Critical
- **OWASP**: A02:2021 Cryptographic Failures
- **Purpose**: Detects sensitive fields exposed in serialization
- **Detection**: Field pattern matching (password, token, secret, api_key)

#### 4.2.4 InsecureRandomAnalyzer

- **Severity**: Warning
- **Purpose**: Identifies insecure random number generation
- **Detection**: Usage of `rand()` in security contexts

#### 4.2.5 HardcodedDatabaseCredentialsAnalyzer

- **Severity**: Critical
- **Purpose**: Detects database credentials written directly into configuration instead of environment variables
- **Detection**: Hardcoded database URL in the connection configuration

#### 4.2.6 OverprivilegedDatabaseUserAnalyzer

- **Severity**: Warning
- **Purpose**: Flags a database user holding more privileges than the application needs
- **Detection**: Connection user inspection, including the empty-user case

---

## 5. Integrity Analyzers

### 5.1 Category Overview

Integrity analyzers detect code smells, anti-patterns, and violations of best practices that affect maintainability, readability, and adherence to Doctrine ORM conventions.

**Total**: 53 analyzers
**Focus**: Type safety, relationship consistency, lifecycle management, naming conventions

### 5.2 Key Analyzers

#### 5.2.1 CascadeAnalyzer (Unified)

**Description**: Single unified analyzer for all cascade-related issues following Single Responsibility Principle.

**Detects**:

1. `cascade="all"` usage (highest priority - most dangerous)
2. `cascade="remove"` on independent entities (potential data loss)
3. `cascade="persist"` on independent entities (wrong aggregate boundaries)

**Benefits**:

- O(n) performance instead of O(3n)
- No duplicate issues
- Clear priority ordering

**Example Violation**:

```php
/**
 * @ORM\ManyToOne(targetEntity="Tag")
 * @ORM\JoinColumn(cascade={"remove"})  // ❌ Tag is independent!
 */
private Tag $tag;
```

**Issue**: Deleting article would delete shared tag → data loss

---

#### 5.2.2 CascadeConfigurationAnalyzer

**Description**: Validates consistency between ORM cascade operations and database foreign key constraints.

**Violation Example**:

```php
/**
 * @ORM\OneToMany(targetEntity="Item", mappedBy="order", cascade={"remove"})
 */
private Collection $items;

// Database: ON DELETE SET NULL (mismatch!)
```

**Issue**: ORM expects cascade delete, database sets NULL → inconsistent state

---

#### 5.2.3 BidirectionalConsistencyAnalyzer

**Description**: Ensures symmetric mapping in bidirectional relationships.

**Violation**:

```php
class Order {
    /** @ORM\ManyToOne(targetEntity="Customer", inversedBy="orders") */
    private Customer $customer;
}

class Customer {
    /** @ORM\OneToMany(targetEntity="Order", mappedBy="wrongField") */
    //                                               ↑ Should be "customer"
    private Collection $orders;
}
```

---

### 5.3 Integrity Analyzer Summary

| Analyzer | Focus Area | Violation Type | Impact |
|----------|------------|----------------|--------|
| BidirectionalConsistencyAnalyzer | Relationship symmetry | Mapping error | ORM malfunction |
| CascadeConfigurationAnalyzer | Aggregate consistency | ORM/DB mismatch | Data corruption |
| CascadeAllAnalyzer | Explicit design | Over-automation | Unintended side effects |
| CascadePersistOnIndependentEntityAnalyzer | Aggregate boundaries | Wrong cascade scope | Data integrity |
| CascadeRemoveOnIndependentEntityAnalyzer | Entity independence | Improper deletion | Data loss |
| OrphanRemovalWithoutCascadeRemoveAnalyzer | Lifecycle management | Configuration inconsistency | Memory leak |
| MissingOrphanRemovalOnCompositionAnalyzer | Composition pattern | Missing cleanup | Orphaned records |
| OnDeleteCascadeMismatchAnalyzer | Layer consistency | ORM vs DB conflict | Undefined behavior |
| ForeignKeyMappingAnalyzer | Referential integrity | Primitive FK exposure | Architecture violation |
| TransactionBoundaryAnalyzer | ACID compliance | Transaction scope | Data inconsistency |
| EntityStateConsistencyAnalyzer | UnitOfWork pattern | State management | Sync issues |
| FinalEntityAnalyzer | Proxy compatibility | Non-final entities | Proxy failures |
| EmbeddableMutabilityAnalyzer | Value object | Mutable embeddables | Side effects |
| EmbeddableWithoutValueObjectAnalyzer | Value object pattern | Missing VO semantics | Design smell |
| MissingEmbeddableOpportunityAnalyzer | Cohesion | Scattered value objects | Maintainability |
| DecimalPrecisionAnalyzer | Type system | Precision loss | Financial errors |
| FloatForMoneyAnalyzer | Type system | Floating-point rounding | Calculation errors |
| FloatInMoneyEmbeddableAnalyzer | Value objects | Incorrect money handling | Financial bugs |
| PropertyTypeMismatchAnalyzer | Type safety | PHP↔DB type mismatch | Runtime errors |
| ColumnTypeAnalyzer | Column definitions | Wrong type usage | Data loss |
| CollectionInitializationAnalyzer | Object lifecycle | Uninitialized collections | Null pointer exceptions |
| CascadeAnalyzer | Cascade safety | Unified cascade diagnosis | Data loss or orphans |
| TimestampableTraitAnalyzer | Trait conventions | Mutable or nullable timestamps | Unreliable audit trail |
| BlameableTraitAnalyzer | Trait conventions | Mutable or public author fields | Unreliable audit trail |
| SoftDeleteableTraitAnalyzer | Trait conventions | Mutable deletion timestamp | Unreliable soft deletes |
| PrimaryKeyStrategyAnalyzer | ID generation | Inefficient strategy | Performance issues |
| QueryBuilderBestPracticesAnalyzer | Code quality | Bad QueryBuilder patterns | Maintainability |
| EntityManagerInEntityAnalyzer | Architecture | Dependency injection | Architecture violation |
| TypeHintMismatchAnalyzer | Type safety | Type inconsistency | Runtime errors |
| NamingConventionAnalyzer | Code standards | Naming violations | Readability issues |
| NullComparisonAnalyzer | SQL semantics | `= NULL` instead of `IS NULL` | Silently empty results |
| DivisionByZeroAnalyzer | Expression safety | Unguarded division | Runtime error |
| CompositeKeyComplexityAnalyzer | Identifier design | Composite primary key | Join and mapping complexity |
| JoinColumnNonPrimaryKeyAnalyzer | Referential integrity | Join column targets a non-primary key | Fragile association |
| JoinTypeConsistencyAnalyzer | Query semantics | Mixed JOIN types | Inconsistent result sets |
| OneToOneInverseSideAnalyzer | Association mapping | Inverse side of a OneToOne | Extra query per load |
| ManyToManyWithExtraColumnsAnalyzer | Relationship modelling | Join table carries extra columns | Should be an entity |
| MappedSuperclassAsTargetEntityAnalyzer | Association mapping | Association targets a mapped superclass | Unsupported by Doctrine |
| MappedSuperclassOneToManyAnalyzer | Association mapping | OneToMany on a mapped superclass | Unsupported by Doctrine |
| DuplicatePrivateFieldInHierarchyAnalyzer | Inheritance | Same private field redeclared in a subclass | Shadowed state |
| InheritanceTypeOnNonRootEntityAnalyzer | Inheritance | `InheritanceType` declared off the root | Ignored by Doctrine |
| ClassTableInheritanceDepthAnalyzer | Class Table Inheritance | Deep hierarchy | One JOIN per level |
| ClassTableInheritanceThinSubclassAnalyzer | Class Table Inheritance | Subclass adds almost no fields | JOIN cost for little data |
| SingleTableInheritanceSparseTableAnalyzer | Single Table Inheritance | Mostly-empty columns | Wasted storage |
| SingleTableInheritanceNullableColumnAnalyzer | Single Table Inheritance | Non-nullable subclass column | Inserts fail for siblings |
| PartialObjectAnalyzer | Hydration | Full entity loaded for a few fields | Unnecessary data transfer |
| FlushInEventListenerAnalyzer | Lifecycle | `flush()` inside a lifecycle callback | Nested unit of work |
| MissingVersionFieldForConcurrencyAnalyzer | Concurrency | No `#[ORM\Version]` field | Lost updates |
| DenormalizedAggregateWithoutLockingAnalyzer | Concurrency | Denormalized aggregate without locking | Drifting totals |
| UniqueEntityWithoutDatabaseIndexAnalyzer | Constraints | `#[UniqueEntity]` with no UNIQUE index | Duplicates under concurrency |
| DiscriminatorColumnAnalyzer (length) | Inheritance mapping | Column too short for map | Unloadable rows |
| DiscriminatorColumnAnalyzer (index) | Single Table Inheritance | Unindexed discriminator | Full table scans |
| NullablePrimaryKeyAnalyzer | Identifier mapping | Nullable primary key | Deprecated since ORM 3.6 |
| StringDefaultExpressionAnalyzer | Column defaults | Raw SQL string default | Deprecated since ORM 3.6 |

---

## 6. Configuration Analyzers

### 6.1 Category Overview

Configuration analyzers inspect the Doctrine and database settings the application runs with, rather than the entities or the queries themselves.

**Total**: 9 analyzers

### 6.2 Key Configuration Analyzers

#### 6.2.1 TimeZoneAnalyzer

- **Purpose**: Detects timezone handling issues in datetime fields
- **Recommendation**: Use DateTimeImmutable with UTC timezone

#### 6.2.2 CharsetAnalyzer

- **Purpose**: Detects charset issues (recommends UTF8MB4)
- **Recommendation**: Standardize on `utf8mb4` to avoid truncation and multi-byte character loss

#### 6.2.3 CollationAnalyzer

- **Purpose**: Validates collation settings for proper sorting and comparisons
- **Detection Notes**:
  - MySQL/MariaDB: detects `utf8mb4_general_ci` vs `utf8mb4_unicode_ci` mismatches
  - PostgreSQL: detects `"C"` collation issues, libc vs ICU differences, FK collation mismatches
- **Recommendation**: Use consistent, platform-appropriate collations across related tables/columns

#### 6.2.4 StrictModeAnalyzer

- **Purpose**: Ensures MySQL strict mode is enabled
- **Recommendation**: Enable strict mode to fail fast on invalid/truncated data instead of silent coercion

#### 6.2.5 InnoDBEngineAnalyzer

- **Purpose**: Validates InnoDB storage engine usage
- **Recommendation**: Prefer InnoDB for transactions, row-level locking, and foreign key support

#### 6.2.6 DoctrineCacheAnalyzer

- **Severity**: Critical / Warning
- **Purpose**: Detects suboptimal cache configuration — `ArrayCache` for metadata, query or result caching reparses and recompiles on every request
- **Note**: Reads the running configuration and only applies in the `prod` environment

#### 6.2.7 AutoGenerateProxyClassesAnalyzer

- **Severity**: Critical
- **Purpose**: Detects `auto_generate_proxy_classes` left enabled for production, which makes Doctrine stat the filesystem on every entity load
- **Detection**: Parses the production YAML (`config/packages/prod/doctrine.yaml`, `when@prod` blocks), so it warns from the dev profiler before deployment

#### 6.2.8 LazyGhostObjectsDisabledAnalyzer

- **Severity**: Info
- **Purpose**: Detects `enable_lazy_ghost_objects` left disabled (Symfony 6.2+), a more efficient proxy mechanism than the legacy generated proxies

#### 6.2.9 ConnectionPoolingAnalyzer

- **Purpose**: Reviews connection pool settings and reports when `max_connections` is unsuited to the workload

### 6.3 Configuration Summary

| Focus Area | Analyzers | Key Recommendations |
|------------|-----------|---------------------|
| Timezone | TimeZoneAnalyzer | Use UTC + DateTimeImmutable |
| Gedmo Traits | 3 analyzers | Proper trait configuration |
| Database Setup | 4 analyzers | UTF8MB4 charset + strict mode + InnoDB |

---

## 7. Configuration

### 7.1 Basic Configuration

```yaml
doctrine_doctor:
    enabled: true
    profiler:
        show_in_toolbar: true
        show_debug_info: false
```

### 7.2 Analyzer Configuration

```yaml
doctrine_doctor:
    analyzers:
        n_plus_one:
            enabled: true
            threshold: 5
        slow_query:
            enabled: true
            threshold: 100  # milliseconds
        missing_index:
            enabled: true
            slow_query_threshold: 50
```

### 7.3 Enabling / Disabling Individual Analyzers

```yaml
doctrine_doctor:
    analyzers:
        n_plus_one:
            enabled: true
        dql_injection:
            enabled: true
        strict_mode:
            enabled: true
```

## 8. Extensibility

### 8.1 Custom Analyzers

Create custom analyzers by implementing `AnalyzerInterface` (query-based) or `MetadataAnalyzerInterface` (metadata-based):

```php
// Query-based analyzer
use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;

final class CustomQueryAnalyzer implements AnalyzerInterface
{
    public function analyze(QueryDataCollection $queries): IssueCollection
    {
        // Detection logic based on captured SQL queries
    }
}

// Metadata-based analyzer
use AhmedBhs\DoctrineDoctor\Analyzer\Concern\MetadataAnalyzerTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;

final class CustomMetadataAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;

    public function analyzeMetadata(): IssueCollection
    {
        // Detection logic based on Doctrine metadata or database connection
    }
}
```

### 8.2 Registration

```yaml
services:
    App\Analyzer\CustomAnalyzer:
        tags:
            - { name: 'doctrine_doctor.analyzer' }
```

---

**[← Back to Main Documentation]({{ site.baseurl }}/)** | **[Configuration →](../user-guide/configuration)**
