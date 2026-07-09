# AI Interaction Guidelines

## Communication

- Be concise and direct
- Explain non-obvious decisions briefly
- Ask before large refactors or architectural changes
- Don't add features not in the project spec
- Never delete files without clarification

## Workflow

This is the common workflow that we will use for every single feature/fix:

1. **Document** - Document the feature in @context/current-feature.md.
2. **Branch** - Create new branch for feature, fix, etc
3. **Implement** - Implement the feature/fix that I create in @context/current-feature.md
4. **Test** - Verify it works in the browser. While iterating, `php artisan test --compact` runs Pest alone and `composer test` runs the backend gate (Pint + PHPStan + Pest). Neither is the full gate.
5. **Iterate** - Iterate and change things if needed
6. **Gate** - Run `composer ci:check` — the Vite build, ESLint, Prettier, TypeScript, Pint, PHPStan, and Pest. See [The gate](#the-gate) below.
7. **Commit** - Only after `composer ci:check` passes end-to-end
8. **Merge** - Merge to main
9. **Delete Branch** - Delete branch after merge
10. **Review** - Review AI-generated code periodically and on demand.
11. Mark as completed in @context/current-feature.md and add to history

Do NOT commit without permission and until `composer ci:check` passes. If it fails, fix the issues first.

## The gate

`composer ci:check` is the **only** command that matches CI. GitHub Actions runs precisely this, so a green local run means a green build. Nothing runs it automatically — **no pre-push hook exists.** If you don't run it, CI is the first thing that checks the work.

`composer test` and `php artisan test` are for the inner loop. Neither touches the frontend, so neither catches a broken Vite build, an ESLint error, a Prettier violation, or a TypeScript error. Do not treat either as a pre-commit gate.

Two ordering facts worth knowing:

- The Vite build leads the gate because Wayfinder generates `@/actions` and `@/routes` during it. Those files are gitignored, so on a fresh checkout `types:check` cannot resolve them until the build has run.
- CI copies `.env.example` to `.env` and runs the suite on **PHP 8.4 and 8.5**. Your local `.env` and single PHP version are never exercised there, so those two can drift without a local failure.

## Branching

We will create a new branch for every feature/fix. Name branch **feature/[feature]** or **fix[fix]**, etc. Ask to delete the branch once merged.

## Commits

- Ask before committing (don't auto-commit)
- Use conventional commit messages (feat:, fix:, chore:, etc.)
- Keep commits focused (one feature/fix per commit)
- Never put "Generated With Claude" in the commit messages

## When Stuck

- If something isn't working after 2-3 attempts, stop and explain the issue
- Don't keep trying random fixes
- Ask for clarification if requirements are unclear

## Code Changes

- Make minimal changes to accomplish the task
- Don't refactor unrelated code unless asked
- Don't add "nice to have" features
- Preserve existing patterns in the codebase

## Code Review

Review AI-generated code periodically and on demand. Use the `/code-review` slash command to review the current diff for correctness and cleanup, and `/security-review` to run a security review of the pending changes on the current branch. Focus especially on:

- Security (auth is stubbed to a seeded admin — watch input validation and the merge commit path, which mutates records in a DB transaction)
- Performance (N+1 queries, and keeping candidate generation sub-quadratic via the pg_trgm GIN indexes)
- Logic errors (edge cases in scoring, the household modifier, and derived-field recompute)
- Patterns (matches existing codebase?)