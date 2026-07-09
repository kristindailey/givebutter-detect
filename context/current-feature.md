<!-- Living document tracking the feature currently being worked on -->

# Current Feature
<!-- Title above as "# Current Feature: <name>", followed by a one- or two-sentence description of the feature/fix. -->

## Status
<!-- Not Started | In Progress | Complete -->

## Goals
<!-- What this feature needs to accomplish. List concrete, checkable goals. -->

## Notes
<!-- Implementation details, constraints, decisions, and references. -->

## History
<!-- Title of feature/fix and brief description of feature/fix -->

### Foundation & Scaffold
Stood up the full stack: stripped starter-kit auth to a seeded demo admin, Postgres 16 Docker Compose, `pg_trgm` migration, Givebutter brand theming, and a `/health` gate.

### Data Layer — Migrations & Models
Eleven migrations and nine models mirroring Givebutter's verified API schema, with `archived_at` soft-delete, blocking-key columns, and mass-assignment guards on the derived giving fields. Added `tags` after checking the ERD against the real OpenAPI spec.
