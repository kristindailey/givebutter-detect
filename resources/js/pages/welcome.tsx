import { Head, Link, usePage } from '@inertiajs/react';
import { dashboard, login, register } from '@/routes';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Givebutter" />
            <nav>
                {auth.user ? (
                    <Link href={dashboard()}>Dashboard</Link>
                ) : (
                    <>
                        <Link href={login()}>Log in</Link>
                        <Link href={register()}>Register</Link>
                    </>
                )}
            </nav>
        </>
    );
}
