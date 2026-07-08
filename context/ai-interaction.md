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
4. **Test** - Verify it works in the browser. Run `composer test` for the full backend gate (Pint check + PHPStan + Pest) — or `php artisan test --compact` to run Pest alone (scoring + recompute logic only, not UI). Run `npm run build` (and `npm run types:check`) to catch frontend errors.
5. **Iterate** - Iterate and change things if needed
6. **Commit** - Only after build passes and everything works
7. **Merge** - Merge to main
8. **Delete Branch** - Delete branch after merge
9. **Review** - Review AI-generated code periodically and on demand.
10. Mark as completed in @context/current-feature.md and add to history

Do NOT commit without permission and until the build and tests pass. If build or tests fail, fix the issues first.

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