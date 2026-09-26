# Analyzer Rules

- Choose the narrowest contract: runtime SQL, static source or mapping, metadata, or opt-in database audit.
- Keep detection behind a small interface and inject factories, parsers, and adapters.
- Keep commands and collectors thin; put reusable orchestration in an application module.
- Return the project's issue collection instead of leaking framework or database objects.
- Add a positive case, a no-finding case, and a regression case for changed behavior.
