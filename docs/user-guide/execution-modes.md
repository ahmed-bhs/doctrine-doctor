---
layout: default
title: Profiler and CI Checks
parent: User Guide
nav_order: 3
---

# Profiler and CI Checks

Use the profiler to see what happened on a page. Use the CI command to catch code and mapping problems before a change is merged.

## Which one should I use?

| Use it for… | Run it here |
|------------|-------------|
| Queries from a page you just loaded, such as N+1 or slow SQL | Doctrine Doctor panel in the Symfony Web Profiler |
| Code, mapping, and configuration checks on each pull request | `php bin/console doctrine:doctor:analyze` |
| Checks against a live database | Add `--with-database` to the CI command |

## Add it to CI

```bash
php bin/console doctrine:doctor:analyze
```

Warnings and critical findings fail the command by default. Set `--fail-on=critical` to fail only on critical findings, `--fail-on=info` to fail on any finding, or `--fail-on=never` to report findings without failing. Analyzer errors still return a failure.

Most checks do not need a live database. If your CI job has one available, include the optional database audits:

```bash
php bin/console doctrine:doctor:analyze --with-database
```

The profiler runs 40 request-based checks. CI runs 52 code, mapping, and configuration checks by default, plus 8 database audits when requested. SQL injection checks cover both sides: unsafe SQL that ran and unsafe query construction in source code.

For the exact list of checks in each group, see the [analyzer execution inventory](../advanced/analyzer-execution-inventory).

---

**[← Analyzers Catalog](analyzers)** | **[Configuration →](configuration)**
