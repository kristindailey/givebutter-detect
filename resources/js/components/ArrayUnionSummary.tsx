import type { ReactNode } from 'react';
import type { ProjectionArrays } from '@/lib/merge';

// Read-only "kept both" summary. Array fields (emails, phones, addresses, tags,
// external IDs) auto-union with dedupe in the projection — there's no decision to
// make, so this just shows what the merged record will carry.

interface ArrayUnionSummaryProps {
    arrays: ProjectionArrays;
}

function formatAddress(address: {
    address_1: string | null;
    address_2: string | null;
    city: string | null;
    state: string | null;
    zipcode: string | null;
}): string {
    return [
        address.address_1,
        address.address_2,
        address.city,
        address.state,
        address.zipcode,
    ]
        .filter((part): part is string => Boolean(part))
        .join(', ');
}

function Section({
    label,
    count,
    children,
}: {
    label: string;
    count: number;
    children: ReactNode;
}) {
    return (
        <div>
            <div className="mb-1.5 flex items-baseline gap-2">
                <span className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {label}
                </span>
                <span className="text-xs text-muted-foreground">
                    union · {count} kept
                </span>
            </div>
            {children}
        </div>
    );
}

function Row({ value, meta }: { value: string; meta?: string | null }) {
    return (
        <li className="flex items-baseline justify-between gap-3 rounded-md bg-muted/40 px-3 py-1.5 text-sm">
            <span className="min-w-0 truncate text-brand-black">{value}</span>
            {meta && (
                <span className="shrink-0 text-xs text-muted-foreground">
                    {meta}
                </span>
            )}
        </li>
    );
}

export function ArrayUnionSummary({ arrays }: ArrayUnionSummaryProps) {
    const hasAny =
        arrays.emails.length > 0 ||
        arrays.phones.length > 0 ||
        arrays.addresses.length > 0 ||
        arrays.tags.length > 0 ||
        arrays.external_ids.length > 0;

    if (!hasAny) {
        return (
            <p className="text-sm text-muted-foreground">
                No array fields on either contact.
            </p>
        );
    }

    return (
        <div className="space-y-4">
            {arrays.emails.length > 0 && (
                <Section label="Emails" count={arrays.emails.length}>
                    <ul className="space-y-1">
                        {arrays.emails.map((email, index) => (
                            <Row
                                key={`email-${index}`}
                                value={email.value ?? '—'}
                                meta={email.type}
                            />
                        ))}
                    </ul>
                </Section>
            )}

            {arrays.phones.length > 0 && (
                <Section label="Phones" count={arrays.phones.length}>
                    <ul className="space-y-1">
                        {arrays.phones.map((phone, index) => (
                            <Row
                                key={`phone-${index}`}
                                value={phone.value ?? '—'}
                                meta={phone.type}
                            />
                        ))}
                    </ul>
                </Section>
            )}

            {arrays.addresses.length > 0 && (
                <Section label="Addresses" count={arrays.addresses.length}>
                    <ul className="space-y-1">
                        {arrays.addresses.map((address, index) => (
                            <Row
                                key={`address-${index}`}
                                value={formatAddress(address) || '—'}
                                meta={address.type}
                            />
                        ))}
                    </ul>
                </Section>
            )}

            {arrays.tags.length > 0 && (
                <Section label="Tags" count={arrays.tags.length}>
                    <div className="flex flex-wrap gap-1.5">
                        {arrays.tags.map((tag, index) => (
                            <span
                                key={`tag-${index}`}
                                className="inline-flex items-center rounded-full border border-border bg-muted px-2.5 py-0.5 text-xs font-medium text-brand-black/70"
                            >
                                {tag.name}
                            </span>
                        ))}
                    </div>
                </Section>
            )}

            {arrays.external_ids.length > 0 && (
                <Section
                    label="External IDs"
                    count={arrays.external_ids.length}
                >
                    <ul className="space-y-1">
                        {arrays.external_ids.map((externalId, index) => (
                            <Row
                                key={`external-${index}`}
                                value={externalId.external_id ?? '—'}
                                meta={externalId.label}
                            />
                        ))}
                    </ul>
                </Section>
            )}
        </div>
    );
}
