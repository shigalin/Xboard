# Releasing the email identity update

This release includes upstream `d826a3b` and the local compatibility fixes.
It changes stored email identities, adds the internal `email_search` projection,
and retains the existing online-IP, node-traffic and device-limit customizations.
Deploy the backend and its migrations together. This is a maintenance-window
upgrade, not a rolling upgrade between old and new application workers.
Do not use the old admin panel's online updater for this release. The new online
updater also rejects this cutover while its migration is pending, before pulling
code or making external update requests; it cannot retroactively change code
already loaded by an older worker.

## Upgrade

1. Stop all old web, WebSocket, queue and scheduled application processes that
   share the database. For the default Compose layout, stop the `xboard` service;
   for the split layout, stop `web`, `horizon` and `ws-server`. Keep the database
   and Redis available. Maintenance mode alone does not stop existing workers.
2. Take and verify a complete database backup after writes have stopped. Retain
   the matching previous image/code revision and configuration for recovery.
3. With the new image/code, run **one** updater. For the default Compose service:

   ```sh
   docker compose run --rm --no-deps --entrypoint php xboard artisan xboard:update --no-interaction
   ```

   On a native installation, run `php artisan xboard:update --no-interaction`.
   In the split Compose template, `updater` is the only migration runner;
   `web`, `horizon` and `ws-server` disable automatic updates and wait for its
   successful completion. Redis must become healthy before `updater` runs.
   You can instead explicitly run `docker compose run --rm updater` once after
   stopping the old application services, then start the split application.
   The updater enters maintenance before migrating. An existing maintenance
   state is preserved; otherwise it resumes only after successful migrations.
   Direct `artisan migrate` on a populated database requires `artisan down`
   first. Do not run multiple updaters concurrently.
4. Confirm the updater exits successfully and the email migration is recorded
   by `php artisan migrate:status`. If maintenance was already active, explicitly
   run `php artisan up` only after the checks below succeed. Start all application
   processes with the same new image/code and verify their startup completes.
   When inherited maintenance is preserved, the updater prints an explicit
   warning and the recovery command; successful migration does not imply that
   the application has been brought back online.
5. Check an existing login, registration/verification, an international-domain
   email search (including a fragment inside a domain label), selected/filtered
   bans, online IPs, node traffic details and generated users' device limits.

The Docker entrypoint now stops on updater failure instead of launching services
against a partially migrated database. The admin customization script retains
its existing warning behavior for an unrecognized frontend bundle.
This failure behavior also applies to later online updates: after a migration
failure, subsequent admin requests receive maintenance responses. Diagnose the
updater output/container logs on the server, complete the migration, and only
then resume the affected application with `php artisan up`. Do not reopen the
admin panel against a potentially partially changed schema.

## Interrupted or rejected migrations

On failure, keep all old/new writers stopped. Maintenance remains enabled in
the affected container/installation. Its file is not a distributed lock and may
not survive replacing a container; it does not replace stopping every writer.

Canonical-email collisions and normalized addresses over 64 characters are
rejected before schema/data changes. Resolve the reported user IDs explicitly;
the updater does not merge or delete accounts. Do not bypass the guard.

After an interrupted MySQL DDL/data conversion, rerun the same new updater: the
schema and per-user projection backfill are retryable. Verify completion before
resuming traffic. A retry that inherits maintenance will not clear it for you.

## Rollback

Email normalization loses original Unicode/case representations, and the new
comparison rules allow addresses that can collide under the old rules. For a
populated database, this migration therefore rejects `down()` before changing
anything. Do not use schema rollback or simply deploy the old image.

To roll back, stop every application writer, restore the verified pre-upgrade
database backup and its matching previous application version/configuration,
then validate before resuming. Plan reconciliation for any writes accepted
after the backup. An empty database can still roll back the schema normally.

## Normalization and search contract

Identity normalization uses the Symfony Unicode/IDNA tables already pinned in
`composer.lock`, including when native PHP extensions exist. This preserves the
PHP 8.2 sigma mapping on PHP 8.3. Dependency upgrades must rerun the identity and
cross-runtime regressions; they must not silently change persisted identities.
No additional production dependency or mandatory native `intl` extension is
introduced.

`email` remains the canonical identity. `email_search` is a hidden, derived
Unicode-domain representation, maintained with email assignments and backfilled
by the migration. Email contains/not-contains filters search both representations
and treat `%`, `_` and `!` as literal input. Other filter types retain their
existing semantics. Any external importer that bypasses the User model must
populate both values using `User::normalizeEmail()` and `User::searchableEmail()`.

The actual admin user-edit endpoint now validates invitation changes under row
locks, as does `setInviteUser`. Omitting the invitation field preserves the
relationship, explicit null/blank clears it, and unknown users/self/cycles are
rejected without changing the relationship.
