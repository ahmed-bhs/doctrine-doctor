---
layout: default
title: First Steps
parent: Getting Started
nav_order: 3
---

# First Steps

Learn how to use Doctrine Doctor effectively in your Symfony project.

---

## Understanding the Profiler Panel

The Doctrine Doctor panel appears in the Symfony Web Profiler toolbar after each request.

### Panel Overview

The panel displays:

- **Issue count** by severity (Critical, High, Medium, Low)
- **Category breakdown** (Performance, Security, Code Quality, Configuration)
- **Total queries** analyzed
- **Analysis time** (overhead)

---

## Reading Issue Reports

Each issue contains:

### 1. Title and Severity

```text
🔴 Critical: N+1 Query Detected
```

### 2. Description

Detailed explanation of the problem and its impact.

### 3. Affected Queries

The specific SQL queries involved:

```sql
SELECT * FROM user WHERE id = ?
-- Executed 100 times
```

### 4. Backtrace (if enabled)

Code location where the query originated:

```text
src/Controller/UserController.php:42
templates/user/index.html.twig:15
```

### 5. Suggestion

Actionable solution with code example:

```php
$users = $repository
    ->createQueryBuilder('u')
    ->leftJoin('u.profile', 'p')
    ->addSelect('p')
    ->getQuery()
    ->getResult();
```

---

## Common Workflow

### 1. Develop as Normal

Write your Symfony code without worrying about Doctrine Doctor.

### 2. Check the Profiler

After loading a page, click the Doctrine Doctor panel in the profiler.

### 3. Review Issues

Focus on Critical and High severity issues first.

### 4. Apply Suggestions

Use the provided code examples to fix issues.

### 5. Verify Fix

Refresh the page and confirm the issue is resolved.

---

## Configuring Analyzers

Customize analyzer behavior in `config/packages/dev/doctrine_doctor.yaml`:

### Example: Stricter N+1 Detection

```yaml
doctrine_doctor:
    analyzers:
        n_plus_one:
            threshold: 2  # Detect even minor N+1 issues
```

### Example: Adjust Slow Query Threshold

```yaml
doctrine_doctor:
    analyzers:
        slow_query:
            threshold: 50  # Flag queries over 50ms
```

[See full configuration reference →]({{ site.baseurl }}/user-guide/configuration)

---

## Best Practices

### During Development

1. **Keep Doctrine Doctor enabled** in the dev environment
2. **Enable backtraces** for precise issue location
3. **Review issues regularly** before committing code
4. **Fix Critical/High issues** immediately

### Before Deployment

1. **Run tests** to ensure fixes don't break functionality
2. **Review staging environment** with production-like data
3. **Ensure Doctrine Doctor is disabled** in production

### Team Collaboration

1. **Share configuration** via version control
2. **Document common patterns** your team encounters
3. **Set team standards** for acceptable thresholds
4. **Review issues in code reviews**

---

## Understanding Severity Levels

### 🔴 Critical

- Security vulnerabilities (SQL injection)
- Data loss risk (cascade misconfiguration)
- Major performance issues (100+ N+1 queries)

**Action**: Fix immediately before committing

### 🟠 High

- Significant performance degradation (missing indexes)
- Architectural violations
- Major inefficiencies

**Action**: Fix before merging to main branch

### 🟡 Medium

- Sub-optimal patterns
- Minor performance issues
- Code quality improvements

**Action**: Fix when refactoring or improving the area

### 🔵 Low

- Naming conventions
- Minor suggestions
- Cosmetic issues

**Action**: Fix when convenient or as part of larger changes

---

## Next Steps

- [Explore common examples]({{ site.baseurl }}/examples/n-plus-one)
- [Learn about all analyzers]({{ site.baseurl }}/user-guide/analyzers)
- [Understand the architecture]({{ site.baseurl }}/advanced/architecture)
- [Customize configuration]({{ site.baseurl }}/user-guide/configuration)
