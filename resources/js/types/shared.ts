import type { Auth } from './auth';

/**
 * Demo-deployment controls, mirrored from `config/demo.php`. The deployed demo
 * shares one database across every visitor, so it can restore itself.
 */
export type Demo = {
    resetEnabled: boolean;
};

/** Props shared with every Inertia page by `HandleInertiaRequests::share()`. */
export type SharedData = {
    name: string;
    auth: Auth;
    demo: Demo;
};
