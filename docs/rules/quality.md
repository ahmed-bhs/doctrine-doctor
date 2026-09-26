# Quality Rules

- Start with the smallest relevant check and run the full PR checks before review.
- Treat PHPStan, Deptrac, ECS, and tests as design feedback, not gates to work around.
- Do not weaken a check to hide a finding; fix the design or record a reviewed exception.
- Keep database-dependent checks explicit and opt-in.
