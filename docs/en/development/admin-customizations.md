# Usage overview, online IPs and node traffic

The upstream `.gitmodules` points to `cedar2025/xboard-admin-dist`, which only
contains compiled admin assets. This checkout pins build `a260c84d` (dist commit
`ef5f43d`). The readable usage and online-IP components live in
`resources/js/admin/` and reuse that build's React, charts, dialogs and query
client. No frontend dependencies are added.

After initializing the submodule, generate the customized admin entry:

```sh
git submodule update --init public/assets/admin
php scripts/customize-admin.php
```

The script verifies the upstream bundle's SHA-256, writes a new entry with a
content hash in its filename, and updates the submodule's manifest and HTML.
Entries it generated earlier are removed once the new one is in place.
The Laravel admin view already loads that manifest. The original upstream
bundle is preserved. The script can be rerun after editing the components.
It intentionally fails for an unknown upstream build, which needs its bindings
and replacement locations reviewed first.

The install and update scripts run this step after `xboard:install` and
`xboard:update`, and only warn when it fails, so an unrecognized bundle can no
longer leave migrations unapplied; the admin then serves the unmodified upstream
entry. The Docker build runs it whenever the script is present, and a failure
there fails the build. It uses the existing PHP runtime. The generated
files are local changes inside the admin submodule; the readable components
and build script belong to the backend repository, so publishing the dist
submodule is unnecessary. Deployment and publication remain separate operations.

`GET /api/v2/{secure_path}/stat/getUsage` accepts `start_date` and `end_date`
(`Y-m-d`, inclusive). Each day counts distinct users with positive upload plus
download in the existing user statistics, across all rate rows, and sums user
traffic including node rates. This installation uses positive node rates. The
statistics reuse the existing table without new columns and without changing
traffic recording or accounting. The only schema change is
`idx_stat_user_usage`, a covering index on `(record_type, record_at, user_id,
u, d)`: `idx_stat_user_record_user` serves the range scan but leaves
`record_type`, `u` and `d` to row lookups, so both aggregates would otherwise
read every row in the range. Adding it to a large table takes a while, and it
costs one more index on each stat upsert.
Days without records return zero. The summary counts distinct users across the
whole selected period, rather than summing daily counts.

The API and date picker restrict dates to the window retained by `reset:log`
(about two months, through today). The response includes `summary.available_from`.
Older dates are rejected rather than filled with misleading zeroes. The existing
log retention and cleanup schedule are unchanged.
The lower bound also respects the previous month's final cleanup cutoff, so
PHP month overflow at a month boundary cannot reopen dates already purged.
`available_from` is already a calendar date in the application timezone, so the
day picker uses it as-is. Selected calendar dates are formatted directly for
the API, without an `LS`/`ES` offset round trip across browser DST boundaries.
Preset ranges and picker bounds derive the application's UTC+8 calendar day
from UTC fields, avoiding a DST shift near midnight. Preset day counts and
the shared time-conversion helpers used by other views are unchanged.

Neither view polls on a timer. The upstream card this replaces refetched every
30 seconds, which suited one row per day of order totals; these daily aggregates
read users x rates x days twice per request, so the card now refetches on mount,
on a range change, or on focus once its data is five minutes stale. The
online-IP dialog queries only when it is opened.

`GET /api/v2/{secure_path}/user/getOnlineIPs?id=...` reads the user's existing
online-device hash and returns current IPs with `last_seen_at` and `is_online`.
Both endpoints use the existing admin middleware. The IP view uses the same
300-second freshness rule as device counting, deduplicates IPs across nodes,
and shows the latest report time first. It does not impose a 100-IP limit.
An IP removed by a new report or a node disconnect is no longer displayed
unless another node still reports it as online.

Viewing IPs performs one read of the existing device hash. It does not write
history, refresh the TTL, remove stale fields or change device state. Device
reporting, counting, sequence fences, alive lists and node synchronization
remain as they were before the IP-view feature. Previously written history
keys are no longer read or refreshed and expire under their existing TTL.

## User traffic by node

Clicking a user's used-traffic cell opens `GET
/api/v2/{secure_path}/user/getNodeTraffic?id=...` (admin only). It shows current
cycle traffic by node, ordered by billed usage. The table shows only the node
name and raw upload/download bytes. Summary totals reflect the rate when each
report was processed, including time-dependent and zero rates.
Renamed nodes use their current name; deleted
nodes retain their last reported name and ID.

Migration `2026_11_03_000001_create_user_server_traffic_table` adds one aggregate
row per user/node in `v2_user_server_traffic`. There is no per-report history or
date-range query: these rows describe the same cycle as the user's counters,
including annual and non-resetting plans. The existing two-month daily-stat
cleanup does not remove them. Deleting a user cascades to these rows.

`TrafficFetchJob` writes the counters and detail in one transaction, locking the
user row. Each transaction starts with a write to that row, so SQLite waits for
its writer lock instead of failing a read-to-write upgrade when several workers
report at once. On SQLite 3.35+ the counters are read through `UPDATE ...
RETURNING`, which holds the database-wide writer lock for fewer statements;
elsewhere a no-op write (`u = u`) takes the row lock, which MySQL does without
logging an extra binlog row image. Resets and admin user saves begin their
transactions with the same no-op write. The detail row is incremented in one
statement and created only on a user's first report from a node in the cycle.
The billed delta is read back from the updated counters to preserve
the database's existing fractional-rate rounding. This adds database work per
reported user. Resets take the same lock and clear detail in the transaction;
manual edits to counters clear attribution only if a counter actually changes.
The query takes a shared user lock so totals and detail describe one snapshot.
Node software and its reporting protocol are unchanged.

Pre-existing usage cannot be reconstructed. The dialog displays the difference
between current usage and recorded node usage as unattributed, including manual
counter adjustments. Direct database/plugin counter edits that bypass these
paths cannot preserve attribution; an excess of detail over the counters is
explicitly flagged. Opening the dialog fetches fresh data without polling and
hides old cached totals during the fetch.

Deploy the migration before restarting queue workers on the new code, then run
the admin customization script (the existing update/build scripts do this).
Old workers do not record node detail, so their traffic remains unattributed.

## Snell nodes and Hysteria2 gecko obfs

The same script adds the `snell` node type to the admin (type list, colour,
type filter and settings form) and a `gecko` entry to the Hysteria2 obfs
select. The form and its labels live in `resources/js/admin/` next to the
other components.

Snell nodes use server `version` 5 or 6. Version 5 supports `obfs_mode`
(`none`/`http`); version 6 offers `default` or `unshaped`. The admin no longer
offers `unsafe-raw`, which cannot authenticate independent PSKs.

`psk_mode: user` gives every user their UUID as the Snell PSK. No shared
Snell PSK or client `userkey` is needed. This works with plain Snell and with
ShadowTLS enabled. New admin forms default to this mode; older saved nodes
without `psk_mode` keep `shared` mode until explicitly changed.

For independent PSKs, enabling `shadow_tls.enabled` additionally requires
`shadow_tls.password`, a node-wide shared password (12-255 characters).
The subscription puts the UUID in `psk` and this shared password in
`obfs-opts.password`, with ShadowTLS version 3. The outer layer only gates
access; the inner Snell PSK determines the user for traffic and limits.
`shadow_tls.server` is a DNS hostname for a reachable TLS 1.3 handshake site;
`shadow_tls.server_port` defaults to 443. ShadowTLS replaces v5 HTTP obfs.

The legacy `shared` mode retains the node PSK (12-255 bytes): plain Snell
also needs a per-user `userkey`; legacy ShadowTLS uses the UUID as its
outer password. This keeps existing nodes working during migration.

Clash Meta subscriptions include independent-PSK nodes both with and without
ShadowTLS. The request UA must report a recognized mihomo core version:
1.19.28+ for a v5 node (encoded as client v4), or 1.19.34+ for v6. GUI-only
version strings cannot establish core capabilities and are filtered.
Sing-box subscriptions support plain Snell independent PSKs from core 1.14;
ShadowTLS-wrapped Snell nodes remain omitted from that subscription format.

Upgrade the node and its patched sing-box/sing-snell dependencies before
selecting independent PSKs in the panel. Existing clients must refresh their
subscriptions when changing modes or the shared ShadowTLS password. Rotating
a user's UUID rotates their Snell PSK and revokes their old connections.
Independent mode does not fall back to the old shared Snell PSK.

Hysteria2 nodes with gecko obfs enabled are likewise sent only to sing-box
1.14.0 or later and left out of every other subscription format. Gecko uses
sing-box's default packet sizes on both sides. Both features need an
xboard-node build whose sing-box includes them.
