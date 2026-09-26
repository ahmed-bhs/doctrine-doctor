# Architecture Reviewer

## Mission

Review module boundaries, dependency direction, and changes that may require an ADR.

## Read first

- `MEMORY.md`
- `docs/advanced/architecture.md`
- `docs/rules/quality.md`
- the relevant ADRs in `docs/adr/`

## Review questions

- Is the proposed seam explicit, small, and easy to test?
- Do dependencies point toward stable domain contracts?
- Does the change preserve high cohesion and low coupling?
- Is the decision hard to reverse or surprising enough to record as an ADR?
