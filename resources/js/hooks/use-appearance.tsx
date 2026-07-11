export type ResolvedAppearance = 'light' | 'dark';
export type Appearance = ResolvedAppearance | 'system';

export type UseAppearanceReturn = {
    readonly appearance: Appearance;
    readonly resolvedAppearance: ResolvedAppearance;
    readonly updateAppearance: (mode: Appearance) => void;
};

// This prototype is light-themed to Givebutter; dark mode is deliberately out of
// scope (see project-overview UI/UX). The appearance system is locked to light so
// a dark OS preference can't half-apply the shadcn `.dark` tokens over the brand
// chrome. The hook keeps its original shape so callers stay unchanged.

const forceLight = (): void => {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.classList.remove('dark');
    document.documentElement.style.colorScheme = 'light';
};

export function initializeTheme(): void {
    forceLight();
}

export function useAppearance(): UseAppearanceReturn {
    return {
        appearance: 'light',
        resolvedAppearance: 'light',
        updateAppearance: () => forceLight(),
    } as const;
}
