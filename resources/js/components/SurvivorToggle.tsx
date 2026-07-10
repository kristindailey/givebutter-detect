import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

// Header override for the system-proposed survivor. Flipping it re-fetches the
// preview swapped — derived values depend on who survives. Donor tenure is *not*
// a survivor concern (contact_since recomputes over the union either way), so
// this is a safe, reversible choice.

export interface ToggleContact {
    id: number;
    name: string;
    email: string | null;
}

interface SurvivorToggleProps {
    contacts: ToggleContact[];
    survivorId: number;
    onChange: (survivorId: number) => void;
    disabled?: boolean;
}

export function SurvivorToggle({
    contacts,
    survivorId,
    onChange,
    disabled,
}: SurvivorToggleProps) {
    return (
        <Select
            value={String(survivorId)}
            onValueChange={(value) => onChange(Number(value))}
            disabled={disabled}
        >
            <SelectTrigger className="min-w-56 bg-card">
                <span className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    Survivor
                </span>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {contacts.map((contact) => (
                    <SelectItem key={contact.id} value={String(contact.id)}>
                        {contact.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
