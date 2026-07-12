import { merge, mergePreview } from '@/routes/contacts';

// The projection shape returned by both merge endpoints. It mirrors
// MergeService::project() exactly — a dry-run GET and the committing POST share
// the same code, so what the before/after panel renders is what commits.

export interface ScalarField {
    survivor: string | null;
    loser: string | null;
    chosen: string | null;
    conflict: boolean;
}

export interface EmailItem {
    type: string | null;
    value: string | null;
}

export interface PhoneItem {
    type: string | null;
    value: string | null;
}

export interface AddressItem {
    address_1: string | null;
    address_2: string | null;
    city: string | null;
    state: string | null;
    zipcode: string | null;
    country: string | null;
    type: string | null;
    is_primary: boolean;
}

export interface TagItem {
    name: string;
}

export interface ExternalIdItem {
    label: string | null;
    external_id: string | null;
}

export interface ProjectionArrays {
    emails: EmailItem[];
    phones: PhoneItem[];
    addresses: AddressItem[];
    tags: TagItem[];
    external_ids: ExternalIdItem[];
}

export interface DerivedField {
    before: string | null;
    after: string | null;
}

export interface ProjectionDerived {
    total_contributions: DerivedField;
    contact_since: DerivedField;
    last_donation_amount: DerivedField;
}

export interface Projection {
    survivor_id: number;
    loser_id: number;
    scalars: Record<string, ScalarField>;
    arrays: ProjectionArrays;
    derived: ProjectionDerived;
}

export type PickSource = 'survivor' | 'loser';
export type Picks = Record<string, PickSource>;

// Human labels for the scalar identity fields the picker can surface. Keys match
// MergeService::SCALAR_FIELDS; anything unmapped falls back to the raw field name.
export const SCALAR_LABELS: Record<string, string> = {
    prefix: 'Prefix',
    first_name: 'First name',
    preferred_name: 'Preferred name',
    middle_name: 'Middle name',
    last_name: 'Last name',
    suffix: 'Suffix',
    dob: 'Date of birth',
    company: 'Company',
    title: 'Title',
    primary_email: 'Primary email',
    primary_phone: 'Primary phone',
    external_id: 'External ID',
};

/** Laravel's encrypted CSRF token, read from the XSRF-TOKEN cookie for the POST. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** Dry-run preview — powers the diff, union summary, and before/after panel. */
export async function fetchPreview(
    survivorId: number,
    loserId: number,
    signal?: AbortSignal,
): Promise<Projection> {
    const response = await fetch(
        mergePreview.url({ query: { survivor: survivorId, loser: loserId } }),
        { headers: { Accept: 'application/json' }, signal },
    );

    if (!response.ok) {
        throw new Error('Failed to load the merge preview.');
    }

    return response.json() as Promise<Projection>;
}

/**
 * A guard rejection the server explained: an undetected pair (404), an already-
 * resolved pair (409), or a contact that can no longer be merged (422 — typically
 * a stale tab whose contact was merged away elsewhere). The message is the
 * server's, so the reviewer is told which guard fired instead of a bare failure.
 */
export class MergeRejectedError extends Error {}

/**
 * The statuses whose `message` is copy we wrote for a reviewer to read. Anything
 * else — a 500, a 419, a `firstOrFail` that lost a race — also carries a
 * `message`, but one written for a developer ("No query results for model
 * [App\Models\Contact] 1002."), and Laravel returns it even with debug off. Read
 * the body only for the guards, so an unplanned failure can't toast internals.
 */
const GUARD_STATUSES = [404, 409, 422];

/** Commit — high-trust, so the caller awaits this before toasting (no optimistic UI). */
export async function commitMerge(
    survivorId: number,
    loserId: number,
    picks: Picks,
): Promise<Projection> {
    const response = await fetch(merge.url(), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({
            survivor_id: survivorId,
            loser_id: loserId,
            picks,
        }),
    });

    if (!response.ok) {
        const data = GUARD_STATUSES.includes(response.status)
            ? ((await response.json().catch(() => null)) as {
                  message?: string;
              } | null)
            : null;

        throw new MergeRejectedError(
            data?.message ?? 'The merge could not be completed.',
        );
    }

    return response.json() as Promise<Projection>;
}

/** Format a decimal-string amount as USD; null renders as an em dash. */
export function formatMoney(value: string | null): string {
    if (value === null || value === '') {
        return '—';
    }

    return Number(value).toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
    });
}

/** Format a `Y-m-d` date string; null renders as an em dash. */
export function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(`${value}T00:00:00`).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}
