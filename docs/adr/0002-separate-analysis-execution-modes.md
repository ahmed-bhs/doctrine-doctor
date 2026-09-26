# ADR 0002: Separate analysis execution modes

- Status: Accepted
- Date: 2026-09-26

## Context

Doctrine Doctor analyzes different kinds of evidence. Request SQL and request
context exist only while an application request is running. Source and mapping
checks can run in CI without a request. Database audits need a configured live
database and can be slow or environment-dependent. Running all analyzers in the
profiler would make the request path expensive and would make CI results depend
on captured traffic.

## Decision

Keep three explicit execution contracts:

- `AnalyzerInterface` for request-dependent runtime SQL analysis in the profiler.
- `StaticAnalyzerInterface` for source, mapping, and other deterministic CI
  analysis.
- `DatabaseAuditAnalyzerInterface` for live database checks, enabled only with
  `--with-database`.

Registration remains shared through `doctrine_doctor.analyzer`, while the
execution path is selected by the contract. Commands, collectors, and
presenters remain thin; analyzers return the shared issue model.

## Consequences

Runtime profiling stays bounded by request evidence. CI can run deterministic
checks without a database, and teams explicitly opt into environment-dependent
audits. An analyzer must declare its evidence and cannot silently move work to a
different path. Some shared interfaces and registration logic are required, but
the separation makes performance and failure behavior visible.

## Rejected alternatives

- Run every analyzer in the profiler: rejected because static and database work
  would slow requests and couple results to runtime traffic.
- Run every analyzer in CI: rejected because request-only SQL evidence is not
  available and live database access should not be implicit.
- Create separate registration systems: rejected because one tag with explicit
  contracts keeps discovery consistent without duplicating configuration.
