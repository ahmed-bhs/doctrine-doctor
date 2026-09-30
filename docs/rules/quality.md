# Quality Rules

- Start with the smallest relevant check and run the full PR checks before review.
- Treat PHPStan, Deptrac, ECS, and tests as design feedback, not gates to work around.
- Do not weaken a check to hide a finding; fix the design or record a reviewed exception.
- Keep database-dependent checks explicit and opt-in.
- Apply the [testing rules](testing.md): behavior at public seams, a balanced
  test pyramid, and no mocks for code owned by this project.
- Treat third-party skills as dependencies: review their instructions and scripts,
  keep their source and content hash in `skills-lock.json`, and update them deliberately.
