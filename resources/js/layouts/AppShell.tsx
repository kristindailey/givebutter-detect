import {
    BarChart3,
    ChevronDown,
    CircleHelp,
    Clock,
    DollarSign,
    GitBranch,
    Home,
    Mail,
    Megaphone,
    MessageCircle,
    Plus,
    Receipt,
    Search,
    Settings,
    ShieldCheck,
    SquareCheckBig,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

// The Givebutter chrome — black top bar + light sidebar — replicated from the
// real product screenshots (context/screenshots/). It is deliberately BOUNDED:
// the nav is static/decorative and only the active path to this feature
// (Contacts) is highlighted. No real nav destinations are wired. Shared by the
// Review Queue and (next) the Merge Review screen.

interface NavItem {
    label: string;
    icon: LucideIcon;
    active?: boolean;
}

const PRIMARY_NAV: NavItem[] = [
    { label: 'Home', icon: Home },
    { label: 'Campaigns', icon: Megaphone },
    { label: 'Transactions', icon: Receipt },
    { label: 'Contacts', icon: Users, active: true },
    { label: 'Engage', icon: Mail },
    { label: 'Payouts', icon: DollarSign },
    { label: 'Settings', icon: Settings },
];

const PLUS_NAV: NavItem[] = [
    { label: 'Workflows', icon: GitBranch },
    { label: 'Custom Reports', icon: BarChart3 },
    { label: 'Data Hygiene', icon: ShieldCheck },
    { label: 'Tasks', icon: SquareCheckBig },
];

const RECENT = ['Lettuce Help 2024', 'General Donations', 'Garden Gala'];

function SidebarLink({ item }: { item: NavItem }) {
    const Icon = item.icon;

    return (
        <span
            aria-current={item.active ? 'page' : undefined}
            className={cn(
                'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium select-none',
                item.active
                    ? 'bg-brand-blue/10 text-brand-blue'
                    : 'text-brand-black/70',
            )}
        >
            <Icon className="size-[18px] shrink-0" strokeWidth={2} />
            {item.label}
        </span>
    );
}

function TopBar() {
    return (
        <header className="sticky top-0 z-20 flex h-14 shrink-0 items-center justify-between gap-4 bg-brand-black px-4 text-brand-white">
            <div className="flex items-center gap-2">
                <span
                    aria-hidden
                    className="flex size-8 items-center justify-center rounded-lg bg-brand-yellow font-logo text-lg font-extrabold text-brand-black"
                >
                    G
                </span>
                <span className="font-logo text-xl font-extrabold text-brand-yellow">
                    Givebutter
                </span>
            </div>

            <div className="hidden w-full max-w-md items-center gap-2 rounded-lg bg-white/10 px-3 py-1.5 text-sm text-white/60 md:flex">
                <Search className="size-4" />
                <span className="flex-1">Search</span>
                <kbd className="rounded bg-white/10 px-1.5 py-0.5 text-xs">
                    ⌘ K
                </kbd>
            </div>

            <div className="flex items-center gap-2">
                <CircleHelp className="size-5 text-white/70" />
                <span className="hidden items-center gap-1.5 rounded-lg bg-white/10 px-3 py-1.5 text-sm sm:flex">
                    <MessageCircle className="size-4" /> Chat
                </span>
                <span className="hidden items-center gap-1.5 rounded-lg bg-white/10 px-3 py-1.5 text-sm sm:flex">
                    <SquareCheckBig className="size-4" /> Tasks
                </span>
                <span className="flex size-8 items-center justify-center rounded-full bg-brand-purple text-xs font-semibold text-brand-white">
                    CS
                </span>
            </div>
        </header>
    );
}

function Sidebar() {
    return (
        <aside className="hidden w-60 shrink-0 flex-col gap-1 border-r border-border bg-sidebar px-3 py-4 lg:flex">
            <div className="mb-2 flex items-center justify-between px-1">
                <button
                    type="button"
                    className="flex items-center gap-1 text-sm font-semibold text-brand-black"
                >
                    Change Collective
                    <ChevronDown className="size-4 text-muted-foreground" />
                </button>
                <span className="flex size-6 items-center justify-center rounded-md border border-border text-muted-foreground">
                    <Plus className="size-4" />
                </span>
            </div>

            <nav className="flex flex-col gap-0.5">
                {PRIMARY_NAV.map((item) => (
                    <SidebarLink key={item.label} item={item} />
                ))}
            </nav>

            <p className="mt-5 px-3 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                Givebutter Plus
            </p>
            <nav className="mt-1 flex flex-col gap-0.5">
                {PLUS_NAV.map((item) => (
                    <SidebarLink key={item.label} item={item} />
                ))}
            </nav>

            <p className="mt-5 px-3 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                Recent &amp; Pinned
            </p>
            <nav className="mt-1 flex flex-col gap-0.5">
                {RECENT.map((label) => (
                    <span
                        key={label}
                        className="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-brand-black/70 select-none"
                    >
                        <Clock className="size-[18px] shrink-0 text-muted-foreground" />
                        {label}
                    </span>
                ))}
            </nav>
        </aside>
    );
}

export default function AppShell({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <TopBar />
            <div className="flex flex-1">
                <Sidebar />
                <main className="min-w-0 flex-1">{children}</main>
            </div>
        </div>
    );
}
