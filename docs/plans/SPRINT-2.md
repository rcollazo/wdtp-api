# WDTP UI — Sprint 2

## Goals
- {{goal 1}}
- {{goal 2}}
- {{goal 3}}

## Scope
- In-scope: {{bullets}}
- Out-of-scope: {{bullets}}

## Task Dashboard (mirrors `planner`)
- Total: {{count_total}} | Todo: {{count_todo}} | In-Progress: {{count_inprog}} | Blocked: {{count_blocked}} | Done: {{count_done}}
- Notes: {{free text for issues/observations}}

## Tasks (authoritative: planner)
| ID | Title | Assignee | Est | Depends | Status | Labels |
|---:|-------|----------|:---:|---------|--------|--------|
| P-{{123}} | {{Implement Login Form}} | wdtp-ui-dev | M | — | Todo | ui,auth |
| P-{{124}} | {{Login E2E tests (web/mobile)}} | wdtp-ui-testing | M | P-123 | Todo | testing,auth |
| P-{{125}} | {{Auth docs & troubleshooting}} | wdtp-ui-docs-maintainer | S | P-123 | Todo | docs,auth |

### Definition of Done (global)
- Code merged with CI green
- Tests added/updated and passing
- **Docs updated by `wdtp-ui-docs-maintainer`**
- Feature flags/instrumentation added where applicable

## Risks & Mitigations
- {{risk}} → {{mitigation}}
- {{risk}} → {{mitigation}}

## Branch & PR Plan
- Branch naming: `feat/<area>-<short-title>-P<id>` / `fix/...` / `chore/...`
- PR title: `[P<id>] <concise title>`
- Mapping:
  - P-{{123}} → `feat/auth-login-form-P123` → `[P123] Auth: Login form & API wiring`
  - P-{{124}} → `test/auth-login-e2e-P124` → `[P124] Tests: Auth login E2E`
  - P-{{125}} → `docs/auth-login-guide-P125` → `[P125] Docs: Auth login guide`

## Planner Sync Log
- {{timestamp}} — Initialized Sprint 2 with {{N}} tasks
- {{timestamp}} — Synced statuses: {{summary}}
