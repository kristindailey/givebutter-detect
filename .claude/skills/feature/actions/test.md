# Test Action

1. Read current-feature.md to understand what was implemented
2. Identify Services, Artisan commands, and models added/modified for this feature
3. Check if tests already exist for these (`tests/Feature`, `tests/Unit`)
4. For trust-critical logic without tests, write Pest tests:
    - Create with `php artisan make:test --pest {name}` (add `--unit` for unit tests)
    - Focus on the matcher and the money-math — scoring, the household modifier, derived-field recompute. Not UI, not endpoint integration
    - Use model factories and states; seed the hero cases (Jennifer/Jen, parent/child) for scoring assertions
    - Test happy path and error cases
    - Do not write tests just to write them. Use your best judgement
5. Run the affected tests: `php artisan test --compact --filter={name}`
6. Run the full backend gate: `composer test` (config clear + Pint + PHPStan + Pest)
7. If the feature touched `resources/`, also run `npm run types:check` and `npm run build`
8. Report which trust-critical paths the new tests cover, and which remain untested