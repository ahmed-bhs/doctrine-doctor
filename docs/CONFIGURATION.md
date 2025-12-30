# Configuration Reference

## Table of Contents

- [1. Overview](#1-overview)
- [2. Configuration Structure](#2-configuration-structure)
- [3. Global Settings](#3-global-settings)
- [4. Analysis Configuration](#4-analysis-configuration)
  - [4.1 Excluding Vendor Entities](#41-excluding-vendor-entities)
- [5. Profiler Configuration](#5-profiler-configuration)
  - [5.1 Profiler Visibility](#51-profiler-visibility)
  - [5.2 Debug Information](#52-debug-information)
  - [5.3 Enabling Query Backtraces](#53-enabling-query-backtraces)
- [6. Analyzer Configuration](#6-analyzer-configuration)
- [7. Performance Thresholds](#7-performance-thresholds)
- [8. Group Management](#8-group-management)
- [9. Environment-Specific Configuration](#9-environment-specific-configuration)
- [10. Advanced Configuration](#10-advanced-configuration)

---

## 1. Overview

Doctrine Doctor uses Symfony's configuration system. All configuration is placed in YAML files under `config/packages/`.

### 1.1 Configuration Locations

```text
config/
├── packages/
│   ├── dev/
│   │   └── doctrine_doctor.yaml         # Development-specific
│   └── doctrine_doctor.yaml              # All environments
```

### 1.2 Configuration Precedence

```text
Environment-specific (config/packages/dev/)
    ↓ overrides
Global (config/packages/)
    ↓ overrides
Bundle defaults (DependencyInjection/Configuration.php)
```

---

## 2. Configuration Structure

### 2.1 Complete Configuration Tree

```yaml
doctrine_doctor:
    # Master switch
    enabled: true|false

    # Profiler integration settings
    profiler:
        show_in_toolbar: true|false
        show_debug_info: true|false

    # Individual analyzer settings
    analyzers:
        analyzer_name:
            enabled: true|false
            # Analyzer-specific parameters
            threshold: <value>
            # ... additional parameters
```

### 2.2 Default Values

When no configuration file exists, Doctrine Doctor uses these defaults:

```yaml
doctrine_doctor:
    enabled: true

    profiler:
        show_in_toolbar: true
        show_debug_info: false

    analyzers:
        # Performance
        n_plus_one:
            enabled: true
            threshold: 5

        slow_query:
            enabled: true
            threshold: 100  # milliseconds

        missing_index:
            enabled: true
            slow_query_threshold: 50
            min_rows_scanned: 1000
            explain_queries: true

        # ... (see section 5 for complete list)
```

---

## 3. Global Settings

### 3.1 Master Enable/Disable

```yaml
doctrine_doctor:
    enabled: true
```

**Type**: `boolean`
**Default**: `true`
**Description**: Global switch for the entire bundle. When `false`, all analyzers are disabled and the profiler panel is hidden.

**Use Cases**:

- Temporarily disable during debugging sessions
- Environment-specific deactivation (e.g., in staging)

**Example**:

```yaml
# config/packages/prod/doctrine_doctor.yaml
doctrine_doctor:
    enabled: false  # Disable in production
```

---

## 4. Analysis Configuration

### 4.1 Excluding Vendor Entities

```yaml
doctrine_doctor:
    analysis:
        exclude_third_party_entities: true
```

**Type**: `boolean`
**Default**: `true`
**Description**: Automatically excludes entities from the `vendor/` directory during analysis. This filters out third-party entities from Symfony, Doctrine, FOSUserBundle, and other vendor bundles to provide cleaner, more relevant reports focused on your application code.

**How it works**:

- Uses **path-based detection** (`/vendor/` in file path) - 100% reliable
- Filtering is **completely transparent** - no changes needed in your code
- Results are **cached per request** for optimal performance (~0.1ms overhead)

**Example**: With this enabled (recommended), entities like `Symfony\Component\Security\Core\User\User` or `FOS\UserBundle\Model\User` will be excluded from analysis, while your `App\Entity\User` will be analyzed normally.

**When to disable**: Only disable if you need to analyze vendor entity mappings or debug third-party bundle configurations.

---

## 5. Profiler Configuration

### 5.1 Profiler Visibility

```yaml
doctrine_doctor:
    profiler:
        show_in_toolbar: true
```

**Type**: `boolean`
**Default**: `true`
**Description**: Controls whether the "Doctrine Doctor" panel appears in the Symfony Profiler toolbar.

### 5.2 Debug Information

```yaml
doctrine_doctor:
    profiler:
        show_debug_info: false
```

**Type**: `boolean`
**Default**: `false`
**Description**: Displays internal debugging information (analyzer execution times, memory usage, service instances). **For development of Doctrine Doctor itself only.**

### 5.3 Enabling Query Backtraces

To see code location backtraces in Doctrine Doctor issues, enable Doctrine DBAL's backtrace collection:

```yaml
# config/packages/dev/doctrine.yaml
doctrine:
    dbal:
        profiling_collect_backtrace: true
```

**Type**: `boolean`
**Default**: `false`
**Description**: Enables collection of stack traces for SQL queries, allowing Doctrine Doctor to show exactly where in your code each issue originates.

**Note**: This is a Doctrine DBAL setting, not a Doctrine Doctor configuration. Add it to your `doctrine.yaml` file. Recommended for development environments only (minimal performance overhead ~2-5%).

---

## 6. Analyzer Configuration

### 6.1 Performance Analyzers

#### N+1 Query Analyzer

```yaml
doctrine_doctor:
    analyzers:
        n_plus_one:
            enabled: true
            threshold: 5
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enabled` | boolean | `true` | Enable/disable analyzer |
| `threshold` | integer | `5` | Minimum duplicate query occurrences to trigger detection |

**Tuning Guide**:

- **Strict**: `threshold: 2` - Catch even minor N+1 issues
- **Balanced**: `threshold: 5` - Default, good signal-to-noise ratio
- **Permissive**: `threshold: 10` - Only major N+1 problems

---

#### Slow Query Analyzer

```yaml
doctrine_doctor:
    analyzers:
        slow_query:
            enabled: true
            threshold: 100
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enabled` | boolean | `true` | Enable/disable analyzer |
| `threshold` | integer | `100` | Execution time threshold in milliseconds |

**Tuning by Environment**:

```yaml
# Local development (fast SSD)
threshold: 20

# Shared development server
threshold: 50

# Staging (production-like hardware)
threshold: 100
```

---

#### Missing Index Analyzer

```yaml
doctrine_doctor:
    analyzers:
        missing_index:
            enabled: true
            slow_query_threshold: 100
            min_rows_scanned: 1000
            explain_queries: true
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enabled` | boolean | `true` | Enable/disable analyzer |
| `slow_query_threshold` | integer | `100` | Minimum execution time (ms) to trigger EXPLAIN analysis |
| `min_rows_scanned` | integer | `1000` | Minimum rows scanned to recommend index |
| `explain_queries` | boolean | `true` | Execute EXPLAIN queries (requires database permissions) |

**Database Permission Requirements**:

```sql
-- MySQL: Ensure user can execute EXPLAIN
GRANT SELECT ON database.* TO 'app_user'@'localhost';

-- PostgreSQL: EXPLAIN requires SELECT permission
-- No additional permissions needed
```

**Performance Considerations**:

- `explain_queries: false` - Disable if database user lacks permissions or for performance
- Higher `min_rows_scanned` reduces false positives on small tables

---

#### Hydration Analyzer

```yaml
doctrine_doctor:
    analyzers:
        hydration:
            enabled: true
            row_threshold: 99
            critical_threshold: 999
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enabled` | boolean | `true` | Enable/disable analyzer |
| `row_threshold` | integer | `99` | Rows to trigger "High" severity |
| `critical_threshold` | integer | `999` | Rows to trigger "Critical" severity |

---

#### Flush in Loop Analyzer

```yaml
doctrine_doctor:
    analyzers:
        flush_in_loop:
            enabled: true
            flush_count_threshold: 5
            time_window_ms: 1000
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enabled` | boolean | `true` | Enable/disable analyzer |
| `flush_count_threshold` | integer | `5` | Minimum flush calls to detect loop pattern |
| `time_window_ms` | integer | `1000` | Time window (ms) to group flush calls |

---

#### Eager Loading Analyzer

```yaml
doctrine_doctor:
    analyzers:
        eager_loading:
            enabled: true
            join_threshold: 5
            critical_join_threshold: 10
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enabled` | boolean | `true` | Enable/disable analyzer |
| `join_threshold` | integer | `5` | Number of JOINs to trigger "Medium" severity |
| `critical_join_threshold` | integer | `10` | Number of JOINs to trigger "Critical" severity |

---

#### Lazy Loading Analyzer

```yaml
doctrine_doctor:
    analyzers:
        lazy_loading:
            enabled: true
            threshold: 10
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enabled` | boolean | `true` | Enable/disable analyzer |
| `threshold` | integer | `10` | Minimum lazy load events to trigger detection |

---

#### Bulk Operation Analyzer

```yaml
doctrine_doctor:
    analyzers:
        bulk_operation:
            enabled: true
            threshold: 100
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enabled` | boolean | `true` | Enable/disable analyzer |
| `threshold` | integer | `100` | Entity count to recommend DQL bulk operations |

**Recommendation**:

```php
// Below threshold: ORM is acceptable
foreach ($entities as $entity) {
    $em->remove($entity);
}
$em->flush();

// Above threshold: Use DQL
$qb->delete(Entity::class, 'e')
   ->where('e.status = :status')
   ->setParameter('status', 'inactive')
   ->getQuery()
   ->execute();
```

---

#### Entity Manager Clear Analyzer

```yaml
doctrine_doctor:
    analyzers:
        entity_manager_clear:
            enabled: true
            batch_size_threshold: 100
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enabled` | boolean | `true` | Enable/disable analyzer |
| `batch_size_threshold` | integer | `100` | Entity count to recommend `clear()` calls |

---

#### Join Optimization Analyzer

```yaml
doctrine_doctor:
    analyzers:
        join_optimization:
            enabled: true
            max_joins_recommended: 5
            max_joins_critical: 10
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enabled` | boolean | `true` | Enable/disable analyzer |
| `max_joins_recommended` | integer | `5` | Recommended maximum JOINs |
| `max_joins_critical` | integer | `10` | Critical threshold |

---

#### Partial Object Analyzer

```yaml
doctrine_doctor:
    analyzers:
        partial_object:
            enabled: true
            threshold: 5
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enabled` | boolean | `true` | Enable/disable analyzer |
| `threshold` | integer | `5` | Minimum fields loaded to suggest partial objects |

---

#### Find All Analyzer

```yaml
doctrine_doctor:
    analyzers:
        find_all:
            enabled: true
            threshold: 99
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enabled` | boolean | `true` | Enable/disable analyzer |
| `threshold` | integer | `99` | Row count to flag `findAll()` usage |

---

#### Get Reference Analyzer

```yaml
doctrine_doctor:
    analyzers:
        get_reference:
            enabled: true
            threshold: 10
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enabled` | boolean | `true` | Enable/disable analyzer |
| `threshold` | integer | `10` | Minimum `find()` calls to recommend `getReference()` |

---

### 5.2 Security Analyzers

All security analyzers use `enabled` parameter only (no thresholds):

```yaml
doctrine_doctor:
    analyzers:
        dql_injection:
            enabled: true
        sql_injection:
            enabled: true
        sensitive_data_exposure:
            enabled: true
        insecure_random:
            enabled: true
```

**Security Best Practice**: Keep all security analyzers enabled at all times.

---

### 5.3 Architectural Analyzers

Architectural analyzers typically have no configurable parameters beyond `enabled`:

```yaml
doctrine_doctor:
    analyzers:
        cascade_configuration:
            enabled: true
        cascade_all:
            enabled: true
        bidirectional_consistency:
            enabled: true
        orphan_removal_without_cascade_remove:
            enabled: true
        # ... (see full list in ANALYZERS.md)
```

---

### 5.4 Data Integrity Analyzers

```yaml
doctrine_doctor:
    analyzers:
        decimal_precision:
            enabled: true
        float_for_money:
            enabled: true
        timezone:
            enabled: true
        charset:
            enabled: true
        collation:
            enabled: true
        strict_mode:
            enabled: true
```

---

### 5.5 Best Practices Analyzers

```yaml
doctrine_doctor:
    analyzers:
        naming_convention:
            enabled: true
        collection_initialization:
            enabled: true
        primary_key_strategy:
            enabled: true
        innodb_engine:
            enabled: true
```

---

## 7. Performance Thresholds

### 7.1 Recommended Thresholds by Environment

#### Local Development

```yaml
doctrine_doctor:
    analyzers:
        slow_query:
            threshold: 10  # Fast local database
        n_plus_one:
            threshold: 2   # Strict detection
        missing_index:
            min_rows_scanned: 500  # Detect early
```

#### Shared Development Server

```yaml
doctrine_doctor:
    analyzers:
        slow_query:
            threshold: 50
        n_plus_one:
            threshold: 3
        missing_index:
            min_rows_scanned: 1000
```

#### Staging (Production-like)

```yaml
doctrine_doctor:
    analyzers:
        slow_query:
            threshold: 100
        n_plus_one:
            threshold: 5  # Reduce noise
        missing_index:
            min_rows_scanned: 5000  # Production-scale data
```

---

## 8. Group Management

> **⚠️ Note**: Group-based configuration (`groups:` node) is not yet implemented.
>
> To enable/disable specific analyzers, use individual analyzer configuration:
>
> ```yaml
> doctrine_doctor:
>     analyzers:
>         n_plus_one:
>             enabled: false  # Disable specific analyzer
>         slow_query:
>             enabled: true   # Keep enabled
> ```
>
> This feature is planned for a future release to allow bulk enable/disable by category.

---

## 9. Environment-Specific Configuration

### 9.1 Development Configuration

```yaml
# config/packages/dev/doctrine_doctor.yaml
doctrine_doctor:
    enabled: true

    profiler:
        show_in_toolbar: true
        show_debug_info: true  # Show analyzer performance metrics

    analyzers:
        n_plus_one:
            threshold: 2  # Strict

        slow_query:
            threshold: 20  # Fast local DB

        missing_index:
            explain_queries: true
```

### 8.2 Test Configuration

```yaml
# config/packages/test/doctrine_doctor.yaml
doctrine_doctor:
    enabled: false  # Disable to avoid test overhead
```

### 8.3 Production Configuration

```yaml
# config/packages/prod/doctrine_doctor.yaml
doctrine_doctor:
    enabled: false  # MUST be disabled in production
```

**Critical**: Doctrine Doctor should NEVER run in production. The bundle is excluded from production via:

- `composer require --dev`
- Explicit `enabled: false` in prod configuration

---

## 10. Advanced Configuration

### 10.1 Custom Analyzer Registration

```yaml
# config/services.yaml
services:
    App\Analyzer\CustomBusinessRuleAnalyzer:
        arguments:
            $threshold: 50
            $templateRenderer: '@AhmedBhs\DoctrineDoctor\Template\Renderer\TemplateRendererInterface'
        tags:
            - { name: 'doctrine_doctor.analyzer' }
```

### 9.2 Custom Template Renderer

```yaml
services:
    App\Infrastructure\MarkdownTemplateRenderer:
        arguments:
            $templateDirectory: '%kernel.project_dir%/templates/doctrine_doctor'

    # Override default renderer
    AhmedBhs\DoctrineDoctor\Template\Renderer\TemplateRendererInterface:
        alias: App\Infrastructure\MarkdownTemplateRenderer
```

### 9.3 Service Decoration

```yaml
services:
    App\Decorator\EnhancedIssueFactory:
        decorates: AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface
        arguments:
            $inner: '@.inner'
            $logger: '@logger'
```

### 9.4 Conditional Configuration via Environment Variables

```yaml
# config/packages/doctrine_doctor.yaml
doctrine_doctor:
    enabled: '%env(bool:DOCTRINE_DOCTOR_ENABLED)%'

    analyzers:
        slow_query:
            threshold: '%env(int:SLOW_QUERY_THRESHOLD)%'
```

```.env
# .env.local
DOCTRINE_DOCTOR_ENABLED=true
SLOW_QUERY_THRESHOLD=30
```

---

## Configuration Validation

### Validate Configuration Syntax

```bash
# Check YAML syntax
php bin/console lint:yaml config/packages/doctrine_doctor.yaml

# Validate container configuration
php bin/console debug:config doctrine_doctor

# Dump complete merged configuration
php bin/console debug:config doctrine_doctor --resolve-env
```

### Common Configuration Errors

#### 1. Invalid Threshold Type

```yaml
# 📢 Wrong
doctrine_doctor:
    analyzers:
        slow_query:
            threshold: "50"  # String instead of integer

# Correct
doctrine_doctor:
    analyzers:
        slow_query:
            threshold: 50
```

#### 2. Unknown Analyzer Name

```yaml
# 📢 Wrong (typo)
doctrine_doctor:
    analyzers:
        n_pluss_one:  # Typo
            enabled: true

# Correct
doctrine_doctor:
    analyzers:
        n_plus_one:
            enabled: true
```

#### 3. Missing Required Parameters

```yaml
# 📢 Incomplete
doctrine_doctor:
    analyzers:
        missing_index:
            enabled: true
            # Missing: slow_query_threshold, min_rows_scanned

# Complete
doctrine_doctor:
    analyzers:
        missing_index:
            enabled: true
            slow_query_threshold: 100
            min_rows_scanned: 1000
            explain_queries: true
```

---

## Configuration Examples

### Minimal Configuration (Use Defaults)

```yaml
# config/packages/dev/doctrine_doctor.yaml
doctrine_doctor:
    enabled: true
```

### Performance-Focused Configuration

```yaml
doctrine_doctor:
    groups:
        performance: true
        security: false
        integrity: false
        configuration: false

    analyzers:
        n_plus_one:
            threshold: 2

        slow_query:
            threshold: 25

        missing_index:
            slow_query_threshold: 50
            min_rows_scanned: 500
```

### Security-Focused Configuration

```yaml
doctrine_doctor:
    # Enable only security analyzers individually
    analyzers:
        dql_injection:
            enabled: true
        sql_injection:
            enabled: true
        sensitive_data_exposure:
            enabled: true
        # Disable performance analyzers
        n_plus_one:
            enabled: false
        slow_query:
            enabled: false
```

### Full Customization

```yaml
doctrine_doctor:
    enabled: true

    profiler:
        show_in_toolbar: true
        show_debug_info: false

    analyzers:
        n_plus_one:
            enabled: true
            threshold: 5

        slow_query:
            enabled: true
            threshold: 100

        missing_index:
            enabled: true
            slow_query_threshold: 50
            min_rows_scanned: 1000
            explain_queries: true

        hydration:
            enabled: true
            row_threshold: 99
            critical_threshold: 999

        flush_in_loop:
            enabled: true
            flush_count_threshold: 5
            time_window_ms: 1000

        eager_loading:
            enabled: true
            join_threshold: 5
            critical_join_threshold: 10

        lazy_loading:
            enabled: true
            threshold: 10

        bulk_operation:
            enabled: true
            threshold: 100

        entity_manager_clear:
            enabled: true
            batch_size_threshold: 100

        join_optimization:
            enabled: true
            max_joins_recommended: 5
            max_joins_critical: 10

        partial_object:
            enabled: true
            threshold: 5

        find_all:
            enabled: true
            threshold: 99

        get_reference:
            enabled: true
            threshold: 10

        # Security (no thresholds)
        dql_injection:
            enabled: true
        sql_injection:
            enabled: true
        sensitive_data_exposure:
            enabled: true
        insecure_random:
            enabled: true

        # Code Quality (no thresholds)
        cascade_configuration:
            enabled: true
        cascade_all:
            enabled: true
        bidirectional_consistency:
            enabled: true

        # Configuration (no thresholds)
        decimal_precision:
            enabled: true
        float_for_money:
            enabled: true
        timezone:
            enabled: true
        charset:
            enabled: true

        # Best Practices (no thresholds)
        naming_convention:
            enabled: true
        collection_initialization:
            enabled: true
        primary_key_strategy:
            enabled: true
```

---

## References

- [Symfony Configuration Reference](https://symfony.com/doc/current/configuration.html)
- [Symfony Best Practices - Configuration](https://symfony.com/doc/current/best_practices.html#configuration)
- [Environment Variables](https://symfony.com/doc/current/configuration.html#configuration-based-on-environment-variables)

---

**[← Back to Main Documentation](../README.md)** | **[Analyzer Reference →](ANALYZERS.md)** | **[Architecture →](ARCHITECTURE.md)**
