---
layout: default
title: Architecture
parent: Advanced
nav_order: 1
---

# System Architecture

---

## 1. Overview

Doctrine Doctor follows a **layered architecture** pattern with clear separation of concerns:

The durable choices behind the execution split and parser boundary are recorded
in [ADR 0002](../adr/0002-separate-analysis-execution-modes) and
[ADR 0003](../adr/0003-use-the-smallest-correct-parser).

```text
┌──────────────────────────────────────────────┐
│           Presentation Layer                 │
│   (Symfony Web Profiler Integration)        │
└────────────────────┬─────────────────────────┘
                     │
┌────────────────────▼─────────────────────────┐
│          Application Layer                   │
│  (Data Collectors, Issue Reconstructors)     │
└────────────────────┬─────────────────────────┘
                     │
┌────────────────────▼─────────────────────────┐
│           Domain Layer                       │
│   (Analyzers, Issues, Suggestions)           │
└────────────────────┬─────────────────────────┘
                     │
┌────────────────────▼─────────────────────────┐
│       Infrastructure Layer                   │
│  (Doctrine ORM, Database, Templates)         │
└──────────────────────────────────────────────┘
```

### 1.1 Layer Responsibilities

#### Presentation Layer

- Symfony Profiler panel rendering
- Twig template integration
- Visual representation of analysis results

#### Application Layer

- Query data collection
- Analysis coordination
- Issue aggregation and deduplication
- Service orchestration

#### Domain Layer

- Core analysis algorithms
- Business logic for issue detection
- Suggestion generation
- Domain models (Issue, Suggestion, QueryData)

#### Infrastructure Layer

- Doctrine ORM integration
- Database platform abstraction
- PHP template rendering system
- External service integrations

---

## 2. Architectural Patterns

### 2.1 Strategy Pattern (Analyzers)

Each analyzer declares whether it needs request SQL (`AnalyzerInterface`) or runs independently in CI (`StaticAnalyzerInterface`). Mapping checks use `MetadataAnalyzerInterface`; live schema and configuration audits use `DatabaseAuditAnalyzerInterface`. Symfony DI routes each service to the profiler or the `doctrine:doctor:analyze` command by its interface:

- **Open/Closed Principle compliance** - Add new analyzers without modifying existing code
- **Easy addition of new analyzers** - Simply implement the interface and tag the service
- **Independent testing** - Each analyzer can be tested in isolation
- **Dynamic analyzer selection** - Enable/disable analyzers via configuration

### 2.2 Data Collector Pipeline

Doctrine Doctor uses Symfony's `DataCollector` + `LateDataCollectorInterface`. The profiler runs request-dependent analyzers in `collect()` for worker-mode safety; in PHP-FPM it defers the work to `lateCollect()` after the response.

1. **collect()** - Capture query data; persistent runtimes run runtime analysis here.
2. **lateCollect()** - PHP-FPM runs runtime analysis and collects database display info here, after the response.
3. **CI command** - `doctrine:doctor:analyze` runs source/mapping checks; live database audits are added with `--with-database`.
4. **serialize()/unserialize()** - Profiler storage lifecycle

Rationale: avoid stale Doctrine objects in persistent runtimes (FrankenPHP/RoadRunner/Swoole).

### 2.3 Issue Creation Pattern

Each analyzer creates `Issue` objects with:

- **Severity** (`critical`, `warning`, `info`)
- **Category** (Performance, Security, Integrity, Configuration)
- **Suggestion** (with code examples and descriptions)
- **Location** (backtrace, query context)
- **Metadata** (queries, execution time, affected entities)

### 2.4 Dependency Inversion Principle

High-level modules depend on abstractions, not concrete implementations:

- **Analyzers** depend on `TemplateRendererInterface`, not specific renderers
- **DoctrineDoctorDataCollector** depends on `AnalyzerInterface[]`, not concrete analyzers
- **Template rendering** uses `TemplateRendererInterface` (implemented by `PhpTemplateRenderer` or `TwigTemplateRenderer`)

This allows easy substitution of implementations without changing high-level code.

---

## 3. Component Diagram

```mermaid
graph TB
    subgraph "Symfony Application"
        A[HTTP Request] --> B[Controller]
        B --> C[Doctrine ORM]
    end

    subgraph "Doctrine Bundle"
        C --> D[DoctrineDataCollector]
    end

    subgraph "Doctrine Doctor Bundle"
        D --> E[DoctrineDoctorDataCollector]
        E --> F[ServiceHolder]

        F --> G[analyzeQueriesLazy]
        G --> H1[Performance Analyzers]
        G --> H2[Security Analyzers]
        G --> H3[Integrity Analyzers]
        G --> H4[Configuration Analyzers]

        H1 --> I[Issues]
        H2 --> I
        H3 --> I
        H4 --> I

        I --> J[IssueDeduplicator]
        J --> K[Profiler Panel]
    end

    style A fill:#e1f5ff
    style E fill:#fff4e1
    style G fill:#ffe1e1
    style K fill:#e1ffe1
```

---

## 4. Analysis Lifecycle Diagram

```mermaid
sequenceDiagram
    participant Request
    participant Symfony
    participant DoctrineDC
    participant DoctorDC
    participant Analyzers
    participant Profiler

    Request->>Symfony: HTTP Request
    activate Symfony

    Symfony->>DoctrineDC: Execute queries
    DoctrineDC->>DoctrineDC: Log queries

    Symfony->>DoctorDC: collect()
    activate DoctorDC
    DoctorDC->>DoctrineDC: Get query data
    DoctrineDC-->>DoctorDC: Query metadata
    DoctorDC->>DoctorDC: Store in $data
    deactivate DoctorDC

    DoctorDC->>DoctorDC: Run analysis in collect()

    loop For each analyzer
        DoctorDC->>Analyzers: analyze(QueryDataCollection)
        Analyzers->>Analyzers: Detect patterns
        Analyzers-->>DoctorDC: Return IssueCollection
    end

    DoctorDC->>DoctorDC: Deduplicate issues
    DoctorDC->>DoctorDC: Calculate statistics
    deactivate DoctorDC

    Symfony-->>Request: Response sent
    deactivate Symfony

    Profiler->>DoctorDC: getData()
    DoctorDC-->>Profiler: Analysis results
    Profiler->>Profiler: Render UI
```

---

## 5. Class Structure

### 5.1 Core Interfaces

```php
/**
 * Analyzer Interface - Strategy Pattern (query-based analyzers)
 */
interface AnalyzerInterface
{
    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection;
}

/**
 * Metadata Analyzer Interface (metadata-based analyzers)
 * Extends AnalyzerInterface for backward compatibility.
 * Uses MetadataAnalyzerTrait to bridge analyze() -> analyzeMetadata().
 */
interface StaticAnalyzerInterface extends AnalyzerInterface
{
}

interface MetadataAnalyzerInterface extends StaticAnalyzerInterface
{
    public function analyzeMetadata(): IssueCollection;
}

interface DatabaseAuditAnalyzerInterface extends MetadataAnalyzerInterface
{
}

/**
 * Template Renderer Interface - Dependency Inversion
 */
interface TemplateRendererInterface
{
    /**
     * Render a template with given context.
     *
     * @param string $templateName Name/identifier of the template
     * @param array<string, mixed> $context Variables to pass to the template
     * @throws \RuntimeException If template not found or rendering fails
     * @return array{code: string, description: string} Rendered code and description
     */
    public function render(string $templateName, array $context): array;

    /**
     * Check if a template exists.
     */
    public function exists(string $templateName): bool;
}

/**
 * Issue Interface - Domain Model
 */
interface IssueInterface
{
    public function getType(): string;
    public function getTitle(): string;
    public function getDescription(): string;
    public function getSeverity(): Severity;
    public function getCategory(): string;
    public function getSuggestion(): ?SuggestionInterface;
    public function getBacktrace(): ?array;
    public function getQueries(): array;
    public function getData(): array;
    public function toArray(): array;
}
```

### 5.2 Class Diagram

```text
┌─────────────────────────────────┐
│   DoctrineDoctorDataCollector   │
│   extends DataCollector          │
│ implements LateDataCollectorInterface │
├─────────────────────────────────┤
│ - analyzers: AnalyzerInterface[]│
│ - helpers: DataCollectorHelpers  │
│ - doctrineCollector              │
├─────────────────────────────────┤
│ + collect(Request, Response)     │
│ + lateCollect()                  │
│ + getData(): array               │
│ + getName(): string              │
└────────────┬────────────────────┘
             │ uses
             ▼
┌─────────────────────────────────┐
│      DataCollectorHelpers       │
├─────────────────────────────────┤
│ - databaseInfoCollector          │
│ - issueReconstructor             │
│ - queryStatsCalculator           │
│ - dataCollectorLogger            │
│ - issueDeduplicator              │
└────────────┬────────────────────┘
             │ coordinates
             ▼
┌─────────────────────────────────┐
│      AnalyzerInterface          │◄──────────┐
├─────────────────────────────────┤           │
│ + analyze(QueryDataCollection)  │           │
│   : IssueCollection              │           │
└─────────────────────────────────┘           │
             △                                 │
             │ implements                      │
     ┌───────┴────────┬───────────────┐       │
     │                │               │       │
┌────────┐    ┌──────────────┐  ┌────────┐   │
│N+1     │    │SlowQuery     │  │Security│   │
│Analyzer│    │Analyzer      │  │Analyzer│   │
└────────┘    └──────────────┘  └────────┘   │
                                              │
                                        uses  │
┌─────────────────────────────────┐           │
│     TemplateRendererInterface   │───────────┘
├─────────────────────────────────┤
│ + render(name, ctx): array      │
│ + exists(name): bool            │
└─────────────────────────────────┘
             △
             │ implements
     ┌───────┴────────┐
     │                │
┌────────────┐  ┌──────────────┐
│PhpTemplate │  │TwigTemplate  │
│Renderer    │  │Renderer      │
└────────────┘  └──────────────┘
```

---

## 6. Design Decisions

### 6.1 Data Collection Timing

**Decision**: Keep `LateDataCollectorInterface` contract but execute analysis in `collect()`.

**Rationale**:

- Worker mode compatibility (no stale EntityManager references post-response)
- Deterministic lifecycle in persistent runtimes
- Profiler data remains available via serialized collector state

**Trade-offs**:

- Analysis cost happens during collection phase
- Requires careful control of overhead in development
- Still cannot modify response based on analysis (acceptable for dev tool)

### 6.2 PHP Template System

**Decision**: Use native PHP templates instead of solely Twig.

**Rationale**:

- **Flexibility**: Full PHP capabilities for complex code generation
- **No Twig Dependency**: Core functionality works without Twig
- **Performance**: No template compilation overhead
- **Familiarity**: Standard PHP syntax for contributors

**Implementation**:

````php
// Template: left_join_with_not_null.php
<?php ob_start(); ?>
## Issue: LEFT JOIN with IS NOT NULL

Your query uses LEFT JOIN but filters with IS NOT NULL:
```sql
<?= $context->original_query ?>
```

This is contradictory. Use INNER JOIN instead.
<?php
$code = ob_get_clean();
return ['code' => $code, 'description' => 'Suggestion'];
````

**Note**: Templates use `SafeContext` for automatic XSS protection. See [Template Security Guide](template-security) for details.

### 6.3 Analyzer Independence

**Decision**: Each analyzer is stateless and independent.

**Rationale**:

- Parallel execution potential (future optimization)
- Independent testing and development
- Configurable enable/disable per analyzer
- No inter-analyzer dependencies

**Implementation**:

- Constructor injection of dependencies only
- No shared mutable state
- Returns `IssueCollection` for type safety and predictability

### 6.4 Severity Classification

**Decision**: Three-level severity system (`critical`, `warning`, `info`).

**Rationale**:

- Simple and consistent UI semantics
- Easy sorting/filtering
- Reduced ambiguity between adjacent levels

**Criteria**:

- **Critical**: Security vulnerability, data loss risk, severe runtime risk
- **Warning**: Significant performance/integrity/configuration concern
- **Info**: Improvement opportunity, non-blocking recommendation

---

## 7. Extension Points

### 7.1 Custom Analyzer Registration

```yaml
# config/services.yaml
services:
    App\Analyzer\CustomBusinessRuleAnalyzer:
        arguments:
            $threshold: 100
        tags:
            - { name: 'doctrine_doctor.analyzer' }
```

### 7.2 Custom Template Renderer

```php
namespace App\Infrastructure;

use AhmedBhs\DoctrineDoctor\Template\Renderer\TemplateRendererInterface;

class CustomTemplateRenderer implements TemplateRendererInterface
{
    public function render(string $templateName, array $context): array
    {
        // Custom rendering logic (e.g., Markdown, reStructuredText)
    }
}
```

```yaml
services:
    App\Infrastructure\CustomTemplateRenderer: ~

    AhmedBhs\DoctrineDoctor\Template\Renderer\TemplateRendererInterface:
        alias: App\Infrastructure\CustomTemplateRenderer
```

### 7.3 Custom Issue Handling

Extend `DataCollectorHelpers` for custom issue processing:

```php
namespace App\Service;

use AhmedBhs\DoctrineDoctor\Collector\DataCollectorHelpers;

class CustomDataCollectorHelpers extends DataCollectorHelpers
{
    public function processIssues(array $issues): array
    {
        // Custom filtering, prioritization, or enrichment
        return array_filter($issues, fn($issue) =>
            $this->matchesBusinessRules($issue)
        );
    }
}
```

---

## References

- [Symfony DataCollector Documentation](https://symfony.com/doc/current/profiler/data_collector.html)
- [Doctrine ORM Architecture](https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/architecture.html)
- [Clean Architecture Principles](https://blog.cleancoder.com/uncle-bob/2012/08/13/the-clean-architecture.html)
- [Design Patterns: Elements of Reusable Object-Oriented Software](https://en.wikipedia.org/wiki/Design_Patterns)

---

**[← Back to Main Documentation]({{ site.baseurl }}/)** | **[Configuration Reference →](../user-guide/configuration)**
