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

Warnings and critical findings fail the command by default. Use `--fail-on` to choose the minimum severity that fails the build:

| Option | The command fails when it finds… |
|--------|----------------------------------|
| `--fail-on=critical` | A critical finding |
| `--fail-on=warning` | A warning or critical finding (the default) |
| `--fail-on=info` | Any finding |
| `--fail-on=never` | An analyzer error; findings are reported without failing |

### GitHub Actions

Add a step like this after installing your Symfony application's dependencies:

```yaml
- name: Run Doctrine Doctor
  run: php bin/console doctrine:doctor:analyze --fail-on=warning
```

The command does not need a live database for the default code and mapping checks. If the job starts the database used by your test environment, include the optional audits:

```yaml
- name: Run Doctrine Doctor with database audits
  run: php bin/console doctrine:doctor:analyze --with-database --fail-on=warning
```

For local development, run the same command before opening a pull request. In a pull request job, keep `--fail-on=warning` as the default and use `--fail-on=critical` if your team wants to introduce the check without failing on existing warnings.

The profiler runs 40 request-based checks. CI runs 52 code, mapping, and configuration checks by default, plus 8 database audits when requested. SQL injection checks cover both sides: unsafe SQL that ran and unsafe query construction in source code.

For the exact list of checks in each group, see the [analyzer execution inventory](../advanced/analyzer-execution-inventory).

---

**[← Analyzers Catalog](analyzers)** | **[Configuration →](configuration)**
