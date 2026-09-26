---
layout: default
title: Agent Workflow
parent: Contributing
nav_order: 4
---

# Agent Workflow

This is the short path for an agent changing Doctrine Doctor.

## 1. Establish context

Read [CONTEXT.md](../../CONTEXT.md), then inspect the relevant code and tests.
If the change affects layers or analyzer execution, read the [architecture
guide](../advanced/architecture) and the relevant ADR.

## 2. Choose the seam

Ask what evidence the change needs:

| Evidence | Contract or module |
|----------|--------------------|
| SQL and request context | `AnalyzerInterface`, profiler path |
| Source code or mappings | `StaticAnalyzerInterface`, CI path |
| Live schema or database settings | `DatabaseAuditAnalyzerInterface`, opt-in CI path |

Keep commands and collectors thin. Put reusable orchestration behind a module
with an explicit interface, and inject adapters instead of constructing them in
the module.

## 3. Make the change testable

Add a focused test for the changed behavior, then a regression test for the
failure mode. Prefer fixtures and test doubles at the seam. Avoid tests that
depend on a database or framework boot when a smaller test can prove the same
contract.

## 4. Run checks

Start with the focused test. Before opening a PR, follow
[Quality Checks](quality-checks) and run the analyzer command when analyzer
execution is involved.

## 5. Explain the result

Update user documentation when behavior or commands change. Add an ADR only
when the decision is hard to reverse, surprising without context, and based on
a real trade-off. The PR description should state the user-visible behavior,
the design seam, and the checks that passed.
