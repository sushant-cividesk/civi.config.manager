# Configuration Manager 0.1.0-alpha67.5-core

Alpha67.5 focuses on Settings responsiveness and operator clarity.

- The initial Settings request no longer performs the full metadata-rich provider inventory walk.
- Provider ownership and safety evidence loads asynchronously through an admin-only, read-only JSON endpoint.
- Configuration types are searchable and grouped as Core, Contributed, Custom, Backup / monitor-only, or Unavailable.
- Rejected provider registrations remain visible under Unavailable.
- Existing scope modes, dependency warnings, lazy item pickers, and provider admission/write-safety rules are unchanged.
- Targeted DEV browser QA tolerates local developer certificate authorities only in targeted mode; disposable CI keeps normal HTTPS validation.

Persistent provider-discovery caching remains intentionally deferred until profiling proves it is necessary.
