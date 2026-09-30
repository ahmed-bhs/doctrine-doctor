# ADR 0004: Reset state between persistent requests

- Status: Accepted
- Date: 2026-09-26

## Context

Doctrine Doctor can run in persistent PHP environments such as FrankenPHP
worker mode, RoadRunner, and Swoole. Services and static caches can outlive a
single request in those environments. Keeping a Doctrine object, query data, or
request-specific issue in shared state can leak results between requests or
retain a stale EntityManager.

## Decision

Treat request analysis as request-scoped state. Do not store Doctrine objects or
request data in static state. Register the data collector with Symfony's
`kernel.reset` mechanism and clear the remaining `ServiceHolder` state through
`WorkerModeResetSubscriber` at request termination. Static parser caches may
store reusable string-based results only, and must expose a reset path when
their lifetime can cross requests.

Runtime analysis is performed during collection where persistent-runtime safety
is explicit; the profiler reads serialized collector data afterwards.

## Consequences

Persistent workers do not reuse stale Doctrine references or previous request
findings. Cache reuse remains possible for immutable string results, but cache
ownership and reset behavior must be reviewed when a new static cache is added.
The collector lifecycle is more deliberate and requires regression coverage for
back-to-back requests.

## Rejected alternatives

- Keep all services and caches for the process lifetime: rejected because
  request-specific state and Doctrine references can become stale.
- Clear every cache after every request: rejected because immutable parser
  results can be safely reused when their ownership is explicit.
