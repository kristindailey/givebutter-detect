# Data Layer — Migrations & Models

## Overview

Create the normalized tables and Eloquent models that mirror Givebutter's real API schema (verified against `docs.givebutter.com`). This is pure data plumbing — no detection, no merge logic. The ERD in `project-overview.md` is the source of truth; this spec turns it into migrations + models.

## Requirements

- Migrations for every entity in the ERD: `contacts`, `emails`, `phones`, `addresses`, `external_ids`, `households`, `household_contacts`, `transactions`, `duplicate_candidates`, plus `tags` + `contact_tags`.
- Eloquent models with relationships for: `Contact`, `Household`, `Transaction`, `DuplicateCandidate` (plus thin models / hasMany targets for emails/phones/addresses/external_ids, and `Tag` via belongsToMany).
- **Derived fields stored on `contacts`** (recomputed, not free-text): `total_contributions` (numeric), `contact_since` (date), `last_donation_amount` (string).
- **Blocking-key columns on `contacts`**: `name_key` (text, nullable) and `address_key` (text, nullable), populated by the Normalizer during seeding and `detect:run`. `address_key` is derived from the contact's **primary** address (keeps keys on one table rather than indexing the child `addresses`).
- **Soft-delete via `archived_at`** (custom column, not Laravel's `deleted_at`) + a global scope that hides archived contacts by default, mirroring Givebutter's reversible `DELETE` + `restore` semantics.
- Minimal `User` model retained for the seeded demo admin.
- **No GIN trigram indexes here** — those live in the Detection Phase 1 spec, next to the query that justifies them. Base-table btree indexes are fine to include: `emails.normalized_value` and `phones.normalized_value` (the columns the exact-match blocks self-join on) each get one.
- Mass assignment uses the `#[Fillable([...])]` attribute (Laravel 13 style, matching the existing `User` model) — not `$fillable` and not an open `$guarded`.

## Tables (per ERD)

Follow the ERD in `project-overview.md` exactly. Field-mirroring notes to honor:

- `emails` / `phones` are `{type, value}` only (plus `id`, `contact_id`, and a `normalized_value` column the Normalizer writes: lowercased+trimmed for email, last-10-digits for phone).
- `addresses`: `address_1`, `address_2`, `city`, `state`, `zipcode`, `country`, `type`, `is_primary`.
- `households` use `head_contact_id` to mark the head; **no per-member role label** — the modifier keys off co-membership + head designation only.
- `transactions`: `captured_at` (settlement time, drives `contact_since`), `refunded_at`, `status` (`succeeded|...`) drive the recompute filter. String PK. Note: the real OpenAPI spec types `status` as a bare string with no documented enum — `succeeded` is our assumed value, worth a comment in the migration.
- `tags`: `id`, `name` (unique, max 64 chars per the create-contact request schema) + `contact_tags` pivot. Never matched on and never diffed in the UI — they exist only so the merge can auto-union them.
- `duplicate_candidates`: `contact_a_id`, `contact_b_id` (canonical `a_id < b_id`), `score` (numeric 0–100), `signal_breakdown` (jsonb), `detected_at`, plus **`resolution`** (`pending|merged|dismissed`, default `pending`) and **`resolved_at`** (timestamp, nullable) for queue state. Prototype-only table. The Review Queue reads `WHERE resolution='pending'`; merge sets `merged`, dismiss sets `dismissed` (a labeled negative). Stored as a plain string column with the three values as `RESOLUTION_*` constants on `DuplicateCandidate` — a backed PHP enum would mean a new `app/Enums` base folder for one three-case type.
- `contacts` mirror the API shape: `prefix`, `first_name`, `preferred_name`, `middle_name`, `last_name`, `suffix`, `dob`, `company`, `title`, `primary_email`, `primary_phone`, plus the derived + blocking-key + `archived_at` columns above.

## Files to Create

1. `database/migrations/` — one migration per table (contacts first after the pg_trgm enable migration)
2. `app/Models/Contact.php` — relationships (emails, phones, addresses, externalIds, households, tags, transactions), `archived` global scope, `withArchived()` escape hatch, casts
3. `app/Models/Scopes/ArchivedScope.php` — `whereNull('archived_at')`
4. `app/Models/Household.php` — members (belongsToMany), head (belongsTo Contact)
5. `app/Models/Transaction.php` — belongsTo Contact, plus a `STATUS_SUCCEEDED` constant and a `countsTowardGiving()` helper so the refund-exclusion rule lives in one place for the recompute spec to call
6. `app/Models/DuplicateCandidate.php` — contactA / contactB, `signal_breakdown` cast to array, `RESOLUTION_*` constants, a `pending()` scope (pending rows, score descending — the Review Queue's query), and `markMerged()` / `markDismissed()` writers
7. Thin models or inline relations for `Email`, `Phone`, `Address`, `ExternalId`
8. `app/Models/Tag.php` — belongsToMany Contact

## Key Gotchas

- Canonical pair ordering (`contact_a_id < contact_b_id`) is enforced by the writer (detect:run), but add a unique index on `(contact_a_id, contact_b_id)` to guard against dupes.
- The `archived` global scope must be **removable in two places**, not one:
  1. `Contact::withArchived()` — the merge needs to load the loser even though it's being archived; commit sets `archived_at` after.
  2. `DuplicateCandidate::contactA()` / `contactB()` must lift the scope on the relation itself. Once a pair resolves to `merged` the loser carries `archived_at`, and without this the resolved row silently returns `null` for one side.
- `archived_at` is **also excluded from fillable** — it's the one field a crafted merge payload could use to retire the *survivor*. Archive and restore only via `Contact::archive()` / `Contact::restore()`.
- Likewise `resolution` / `resolved_at`: a resolution is the outcome of an action, not a field a payload gets to name. The merge and dismiss controllers call `markMerged()` / `markDismissed()`.
- `Household::head()` resolves through the archived scope, so it returns `null` once the head contact is archived. **If a merge loser is the household head, the merge must re-point `head_contact_id` to the survivor** or the household loses its head silently. Out of scope here; the merge spec owns it.
- **The three derived fields are deliberately excluded from `Contact`'s fillable list**, so no request payload can set them — the merge commit path mass-assigns from user picker input. Consequence for downstream specs: the seeder and the recompute must write them via `forceFill()` / direct column update, not `create()` / `update()`.
- `total_contributions` numeric, `last_donation_amount` string (mirrors the API's string money fields), `contact_since` date.
- `php artisan make:migration` stamps migrations created in the same second with identical timestamps, which sorts `contact_tags` before `tags` and `household_contacts` before `households` — both fail on the foreign key. Rename the files into dependency order after generating them.

## Testing

No schema or relationship tests — those are exercised by the seed-demo spec, which fails loudly when a migration or relationship is wrong. Model factories are deferred there too: nothing here consumes them yet.

`tests/Feature/DataLayerGuardsTest.php` covers only what the seeder *can't* — the guards that fail **silently** when broken, since a regression in any of them leaves migrations green and the app looking fine:

- `archived_at`, the three derived giving fields, and `resolution` are not mass-assignable
- the archived global scope hides losers; `withArchived()` reveals them; `archive()` / `restore()` round-trip
- a `DuplicateCandidate` still resolves both contacts once one side is archived
- `pending()` returns pending rows only, highest score first

## References

- `givebutter/project-overview.md` → Data Architecture (ERD + field-mirroring notes)
- Depends on: `foundation-spec.md` (pg_trgm enabled first)
- Consumed by: `seed-demo-spec.md`, `detection-phase-1-spec.md`