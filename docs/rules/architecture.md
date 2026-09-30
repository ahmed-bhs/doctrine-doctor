# Architecture Rules

- Keep the domain independent from Symfony and Doctrine infrastructure.
- Point dependencies toward stable contracts and keep adapters at the seams.
- Prefer deep modules: hide policy behind a small interface and keep callers thin.
- Apply SOLID where it clarifies a real responsibility, variation, or dependency; do not create abstractions for decoration.
- Use DDD terms only for concepts that exist in the domain. Keep business rules close to those concepts.
- Prefer composition, immutable value objects, explicit dependencies, and high cohesion.
- Use an ADR for a hard-to-reverse decision with a real trade-off; keep routine code choices in the implementation.
