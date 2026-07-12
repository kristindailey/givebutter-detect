<?php

/**
 * Demo-deployment controls.
 *
 * The deployed demo has one database and many visitors, and none of them have a
 * terminal. The first person to merge Jennifer/Jen archives her for everyone who
 * comes after, so the demo has to be able to restore itself.
 */
return [

    /**
     * Enables the scheduled reset and the in-app "Reset demo data" button, both of
     * which run `seed:demo --detect --force`.
     *
     * Gated on an explicit flag rather than `app()->environment('local')`: a deployed
     * demo *is* production, so an environment check would either disable the reset
     * exactly where it is needed or force the environment to lie about itself.
     */
    'reset_enabled' => (bool) env('DEMO_RESET_ENABLED', true),

    /**
     * How often the scheduled reset runs (`routes/console.php` builds the cron
     * expression from this). Also the number quoted in the README, so a visitor
     * knows how long a merge of theirs will stick around.
     */
    'reset_interval_minutes' => 10,
];
