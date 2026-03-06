# Long-Term Durability Standard (20-30 Year Horizon)

This document defines the minimum engineering and operations standards required to keep this system maintainable, recoverable, and upgradeable through long service life.

## 1. Durability Targets

- Data durability: no silent data loss in primary transactional tables.
- Recovery durability: proven database restore drill at least once every 7 days.
- Change durability: every merge to main must pass automated tests in CI.
- Dependency durability: automated update proposals must be generated every week.
- Knowledge durability: architectural and operational decisions must be recorded in version control.

## 2. Non-Negotiable Controls

1. Backups
- Database backup must run daily.
- Backup files must be checksummed.
- Backup retention must be at least 30 days.

2. Restore drills
- Restore drill must run weekly against latest backup.
- Restore drill is considered successful only if schema verification passes.

3. Release safety
- CI must run test suite on every pull request and merge to `main`/`master`.
- Any failing durability test blocks release.

4. Dependency hygiene
- Backend (`composer`) and frontend (`npm`) dependency checks must run automatically every week.
- Security and version drift fixes should be merged on a regular cadence.

5. Documentation and ownership
- Every critical production incident gets a short postmortem in repository docs.
- Operational ownership (who runs backups, who verifies restore drill, who approves upgrades) must be explicitly assigned.

## 3. Operational Cadence

Daily:
- Verify scheduled backup completion.
- Verify application error logs are monitored.

Weekly:
- Verify restore drill success and timestamp freshness.
- Review pending dependency updates.

Monthly:
- Review failed/slow tests and close gaps.
- Confirm backup retention policy is still enforced.
- Patch and update OS, PHP runtime, and database minor versions.

Quarterly:
- Perform upgrade rehearsal in staging (framework, runtime, DB).
- Validate disaster recovery runbook end-to-end.

Yearly:
- Run architecture risk review and capacity planning.
- Rotate credentials and secrets according to security policy.

## 4. Upgrade Policy

- Prefer small, frequent upgrades over large jumps.
- Keep supported versions of:
  - PHP runtime
  - Laravel framework
  - MySQL engine
  - Node/npm toolchain (for frontend build)
- Never skip major framework upgrades without a rehearsal branch and migration notes.

## 5. Data Model and API Stability Rules

- Migrations are append-only in production history; never rewrite applied migrations.
- Destructive schema changes require:
  - backfill strategy
  - rollback strategy
  - data validation query
- Public API behavior changes require backward-compatibility notes and test updates.

## 6. Testing Durability Rules

- Durability-sensitive flows (finance ledger, payroll locks, expense reversal, transport billing) require regression tests.
- New bug fixes must include a test that fails before the fix and passes after.
- At least one CI job must run against every pull request.

## 7. Disaster Recovery Requirements

- Recovery Time Objective (RTO): define and review yearly.
- Recovery Point Objective (RPO): define and review yearly.
- Recovery checklist must include:
  - locate latest verified backup
  - validate checksum
  - restore to isolated database
  - run smoke queries for critical tables
  - validate application boot and basic API health

## 8. Observability Baseline

- Store and retain application logs with timestamps and severity levels.
- Capture backup and restore command outcomes in logs.
- Track durability KPIs:
  - backup success rate
  - restore drill success rate
  - mean time to detect failures
  - dependency update lead time

## 9. Governance Checklist

Use `scripts/durability-audit.ps1` for routine governance checks. Run:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\durability-audit.ps1
```

If any critical check fails, treat it as release-blocking until resolved.
