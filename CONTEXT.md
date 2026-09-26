# Doctrine Doctor Context

This file defines the terms used by the project. It describes the domain and
the relationships between concepts; implementation details belong in the
architecture guide and ADRs.

## Analyzer

An analyzer is a check that turns Doctrine evidence into one or more issues.
The evidence may come from a request, application source, Doctrine mappings,
or a live database.

## Runtime analyzer

A runtime analyzer needs evidence captured while a request is running, such as
executed SQL, parameters, timing, row counts, or a backtrace. Its findings are
shown in the Web Profiler.

## Static analyzer

A static analyzer can run without SQL captured from the current request. It
checks application source, Doctrine mappings, or configuration and is suitable
for local checks and CI.

## Database audit

A database audit is a static check whose evidence comes from the configured
live database. It is opt-in because it needs database access and can describe a
specific environment rather than the source code alone.

## Issue

An issue is a detected problem. It has a severity, category, explanation, and
the evidence needed to understand it.

## Suggestion

A suggestion is an actionable improvement attached to an issue. It explains a
possible next step without pretending that one fix fits every application.

## Profiler analysis

Profiler analysis explains what happened during one request. It is diagnostic
feedback for development, not a replacement for the CI checks.

## CI analysis

CI analysis checks code and mappings before a change is merged. Its exit status
is part of the delivery workflow and can be controlled with `--fail-on`.

## Analyzer execution mode

An execution mode is the place where an analyzer runs: runtime profiler, static
CI analysis, or opt-in database audit. The analyzer contract determines the
mode; the service tag only registers the analyzer with the bundle.
