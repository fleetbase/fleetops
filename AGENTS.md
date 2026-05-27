# AI Agent Instructions

## Repository Scope

FleetOps module for dispatching, order management, routing, live operations, fleet resources, and logistics workflows.

## Allowed Work

- Bug fixes for dispatch, orders, routing, fleet resources, and logistics workflows.
- Small frontend or backend enhancements that preserve existing FleetOps conventions.
- Tests, documentation, API resource updates, serializers, and validation updates tied to FleetOps behavior.

## Restricted Work

- Large rewrites of order, dispatch, routing, or live operations flows without explicit approval.
- Changes to pricing, billing, external integrations, or production operational behavior without review.
- API behavior changes without checking whether `fleetbase/postman` and `fleetbase/fleetbase.io` need updates.

## Global Agent Rules

- Do not merge pull requests.
- Do not publish packages.
- Do not rotate, expose, modify, or invent secrets.
- Do not change production credentials or deployment configuration unless explicitly requested.
- Do not perform large rewrites unless explicitly requested.
- Prefer small, reviewable changes.
- Preserve existing architecture and conventions.
- Follow the repository's existing naming, formatting, and test patterns.
- Add or update tests where practical.
- If tests are not added, explain why in the PR.
- Run available lint/test/build commands before finalizing the PR.
- Include command output or a clear summary of validation performed.
- If the issue is ambiguous, comment with questions instead of guessing.

## Local Setup

- `pnpm install`
- `composer install`

## Test Commands

- Frontend lint: `pnpm lint`
- Frontend tests: `pnpm test` or `pnpm test:ember`
- Frontend build: `pnpm build`
- Backend lint: `composer test:lint`
- Backend static analysis: `composer test:types`
- Backend tests: `composer test:unit` or `composer test`

## Pull Request Rules

- Work on a dedicated branch for each task.
- Keep changes small and reviewable.
- Stage only files related to the task.
- Do not include unrelated local changes in commits.
- Use clear PR titles and descriptions that explain what changed and why.
- Link the related issue when one exists.
- Include validation commands and a concise summary of results.
- Explain any skipped tests, missing coverage, or known follow-up work.
- Call out documentation and API reference impact using the PR template.
- Do not merge the PR after opening it.

## Documentation Rules

Some changes require documentation updates in `fleetbase/fleetbase.io`.

Some API-related changes additionally require updates to API specification files in `fleetbase/postman`.

Update or create documentation when a change affects:

- user-facing behavior
- configuration
- installation steps
- environment variables
- API behavior
- request/response schemas
- authentication behavior
- extension behavior
- permissions or IAM behavior
- billing, invoicing, wallets, or gateway behavior
- developer workflows
- CLI commands
- mobile app workflows
- onboarding flows
- breaking changes
- deprecated behavior

If API behavior changes, the agent must determine whether corresponding specification updates are needed in `fleetbase/postman`.

Examples include:

- new endpoints
- modified endpoints
- payload changes
- validation changes
- authentication changes
- response schema changes
- enum changes
- new query parameters
- deprecated API behavior

If API specification files are updated in `fleetbase/postman`, the agent should also determine whether a documentation PR or regeneration step is required in `fleetbase/fleetbase.io`.

If documentation should be updated but cannot be updated in the same PR, the agent must:

1. mention this clearly in the PR description;
2. add a `needs-docs` label if available;
3. reference the expected documentation area in `fleetbase/fleetbase.io`;
4. reference any required specification updates in `fleetbase/postman`.

## Escalation Rules

Stop and request human clarification when:

- the requested behavior is ambiguous or conflicts with existing architecture;
- the change would alter public APIs, authentication, permissions, billing, financial records, or production configuration;
- secrets, credentials, signing assets, deployment settings, or release automation are involved;
- the safest implementation requires a large rewrite or broad cross-repository coordination;
- tests fail for reasons that are not clearly related to the change;
- documentation or API specification ownership is unclear.

## Recommended Labels

Use these labels when they are available:

- `type:bug`
- `type:feature`
- `type:docs`
- `type:refactor`
- `type:chore`
- `agent:ready`
- `agent:blocked`
- `agent:in-progress`
- `needs-docs`
- `needs-api-spec`
- `needs-human-review`
- `needs-product-decision`
- `priority:p0`
- `priority:p1`
- `priority:p2`
- `priority:p3`
+- `module:fleetops`
