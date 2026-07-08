# Data Layer — Migrations & Models

## Overview

Create the normalized tables and Eloquent models that mirror Givebutter's real API schema (verified against `docs.givebutter.com`). This is pure data plumbing — no detection, no merge logic. The ERD in `project-overview.md` is the source of truth; this spec turns it into migrations + models.

## Requirements

- Migrations for every entity in the ERD: `contacts`, `emails`, `phones`, `addresses`, `external_ids`, `households`, `household_contacts`, `transactions`, `duplicate_candidates`.
- Eloquent models with relationships for: `Contact`, `Household`, `Transaction`, `DuplicateCandidate` (plus thin models / hasMany targets for emails/phones/addresses/external_ids).
- **Derived fields stored on `contacts`** (recomputed, not free-text): `total_contributions` (numeric), `contact_since` (date), `last_donation_amount` (string).
- **Blocking-key columns on `contacts`**: `name_key` (text, nullable) and `address_key` (text, nullable), populated by the Normalizer during seeding and `detect:run`. `address_key` is derived from the contact's **primary** address (keeps keys on one table rather than indexing the child `addresses`).
- **Soft-delete via `archived_at`** (custom column, not Laravel's `deleted_at`) + a global scope that hides archived contacts by default, mirroring Givebutter's reversible `DELETE` + `restore` semantics.
- Minimal `User` model retained for the seeded demo admin.
- **No GIN trigram indexes here** — those live in the Detection Phase 1 spec, next to the query that justifies them. Base-table btree indexes (email/phone normalized values for exact blocks) are fine to include.

## Tables (per ERD)

Follow the ERD in `project-overview.md` exactly. Field-mirroring notes to honor:

- `emails` / `phones` are `{type, value}` only (plus `id`, `contact_id`).
- `addresses`: `address_1`, `address_2`, `city`, `state`, `zipcode`, `country`, `type`, `is_primary`.
- `households` use `head_contact_id` to mark the head; **no per-member role label** — the modifier keys off co-membership + head designation only.
- `transactions`: `captured_at` (settlement time, drives `contact_since`), `refunded_at`, `status` (`succeeded|...`) drive the recompute filter. String PK.
- `duplicate_candidates`: `contact_a_id`, `contact_b_id` (canonical `a_id < b_id`), `score` (numeric 0–100), `signal_breakdown` (jsonb), `detected_at`, plus **`resolution`** (enum `pending|merged|dismissed`, default `pending`) and **`resolved_at`** (timestamp, nullable) for queue state. Prototype-only table. The Review Queue reads `WHERE resolution='pending'`; merge sets `merged`, dismiss sets `dismissed` (a labeled negative).
- `contacts` mirror the API shape: `prefix`, `first_name`, `preferred_name`, `middle_name`, `last_name`, `suffix`, `dob`, `company`, `title`, `primary_email`, `primary_phone`, plus the derived + blocking-key + `archived_at` columns above.

## Files to Create

1. `database/migrations/` — one migration per table (contacts first after the pg_trgm enable migration)
2. `app/Models/Contact.php` — relationships (emails, phones, addresses, externalIds, households, transactions), `archived` global scope, casts
3. `app/Models/Household.php` — members (belongsToMany), head (belongsTo Contact)
4. `app/Models/Transaction.php` — belongsTo Contact
5. `app/Models/DuplicateCandidate.php` — contactA / contactB, `signal_breakdown` cast to array
6. Thin models or inline relations for `Email`, `Phone`, `Address`, `ExternalId`

## Key Gotchas

- Canonical pair ordering (`contact_a_id < contact_b_id`) is enforced by the writer (detect:run), but add a unique index on `(contact_a_id, contact_b_id)` to guard against dupes.
- The `archived` global scope must be **removable** for merge/restore flows (the merge needs to load the loser even though it's being archived; commit sets `archived_at` after).
- `total_contributions` numeric, `last_donation_amount` string (mirrors the API's string money fields), `contact_since` date.

## Testing

No standalone tests for the data layer — it's exercised by the seed-demo spec (which fails loudly if a migration/relationship is wrong) and by the scoring/recompute tests in later specs.

## References

- `givebutter/project-overview.md` → Data Architecture (ERD + field-mirroring notes)
- Depends on: `foundation-spec.md` (pg_trgm enabled first)
- Consumed by: `seed-demo-spec.md`, `detection-phase-1-spec.md`