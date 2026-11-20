# Admin customizations

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

The used-traffic cell also shows how many nodes the user has used, from
`used_node_count` in the `user/fetch` response. It counts the user's rows in
`v2_user_server_traffic`, the same nodes the dialog lists, including deleted
nodes. Traffic from before attribution was enabled is not counted. The count
is a separate query, outside the `admin.user.fetch.query` plugin hook, so
usage fields a plugin defines are returned as it selected them. The page and
the count are read in one transaction at REPEATABLE READ (set for that
transaction on MySQL, MariaDB and PostgreSQL, whose default may be READ
COMMITTED; a SQLite transaction already reads one snapshot), so a concurrent
reset or report cannot pair the page's usage, which the filters and sort
selected, with a count from another cycle. This holds because the `user/fetch`
route opens no transaction of its own: inside a caller's transaction the level
can no longer be set and the reads use that transaction's level, so under READ
COMMITTED they can again disagree.

## Today's node traffic

`server/manage/getNodes` returns `today_traffic` for each node: raw upload plus
download from today's daily row in `v2_stat_server`, without the node rate and
summed across protocol types. The node list shows it in a sortable column after
the cycle traffic. It is updated by the same `stat` queue job as the node's
total counters, so it lags reports by the queue delay.

The same response describes each node's groups as full sync serves them. The
database matches each value from `Server::syncGroupIds()` against group rows
with the same `whereIn` full sync runs (`Server::groupValueMatches()`), so values
such as `"01"` or `true` show the group full sync gives them, and `null` shows
none. Each distinct value is compared once per request, and the matched
group's name is read with it. Plain IDs (integers, or strings that are exactly
an integer's decimal form, within the 32-bit range) compare the same on every
database, so one query matches them for all nodes; every other value is
compared on its own, as full sync compares it. These reads, the nodes, their
parents and today's traffic share one REPEATABLE READ transaction, so a node
never lists one group's old name next to another's new one. Each distinct
value is cached under a key that keeps its type (`"1"`, `1` and `true` differ,
as do `INF` and `-INF` from JSON numbers too large for a float), so two values
never share a match. The ranking and device-ban pushes key values the same way. `groups` lists the matched groups, group `0` included. `group_ids` is a unique list of strings
for the admin form and group filter: matched groups by ID, plus values that
match no group row as they are, because users can still be in a group without
a row (a plan's `group_id` need not have one). The list describes the stored value
rather than reproduce it (`["01"]` is listed as group `"1"`, and the request's
`TrimStrings` middleware would turn a listed `" 3"` into `"3"`), so sending it
back would rewrite the stored value. The customized node dialog therefore sends
`group_ids` only when the selection the admin sees differs from the one it
opened with. The selector shows, and after an edit keeps, only values that are
group options, so the comparison is between the shown selections as sets:
removing and re-adding a group, or reordering, sends nothing and keeps values
the selector cannot show (such as `" 3"` with no group row). An actual change
sends the new selection, which drops those values, as the upstream selector
always did. `server/manage/save`
writes whatever it receives, as before. Saving other
fields keeps the stored value, and an explicit change is always written, even
when the list it was compared with has gone stale. If the admin customization
step fails and the upstream bundle is served, the dialog sends every field
again and saving rewrites legacy values. A value the
database cannot compare with an integer (PostgreSQL rejects `"abc"`) fails only
its own comparison; full sync fails for such a node on that database, so
`groups` is empty for it while `group_ids` still lists every value.

Node user sync (`ServerService::getAvailableUsers`) and device-ban pushes use
the same helper. Full sync skipped any stored value PHP's `empty()` treats as
empty (`null`, `[]`, `""`, `0`, `false` and the string `"0"`) without a query or
the `server.users.get` hook, and the helper still returns no group for them.
Whenever the query builder's `whereIn` accepted the stored
array (each top-level element binds exactly one value), the helper returns
those bound values, so every row that synced before syncs the same users. A
nested empty list such as `["1",[[]]]` passes `whereIn`'s check but binds fewer
values than it has elements: SQLite ran that query with the remaining bindings
shifted and the last condition (`banned = ?`) unbound, serving nobody, and it
still serves nobody there (the hook still ran); MySQL and PostgreSQL failed on it. Only rows that
made full sync fail change: any scalar, nesting that changes the element count
such as `[["1","2"]]`, and the binding-count mismatches above outside SQLite.
On SQLite the helper returns `[null]` for those mismatches, which binds one
value that matches no user, so the query runs and its empty result still
passes through the `server.users.get` plugin hook, as it did. The failed rows
keep just the top-level JSON strings
of a stored list that are exactly an integer's decimal form (`"1"`, `"0"` or
`"-2"`: `v2_user.group_id` is a signed integer, though on MySQL `v2_plan.group_id`
is unsigned; not `"01"` or `" 1"`) within
the range `v2_user.group_id` holds (32-bit on MySQL and PostgreSQL, where
PostgreSQL would reject a larger value and fail the query; 64-bit on SQLite),
the
groups subscriptions match on every database, and nothing else; a stored JSON
object that full sync rejected serves no group, since MySQL subscriptions never
match an object. Before, these rows
returned 500 to the node's user sync and stopped ban pushes to every node.
Device-ban pushes match the newly banned users against every distinct stored
value in one statement per 500 values (`group_id IN (?)` per value, one result
column each, which keeps under every database's column limit; restricted to
their IDs),
so a ban reaches exactly the online nodes whose full sync includes the user's
group (`"01"` reaches group 1, as full sync does). Only nodes that get a ban
are checked for being online. If the database rejects a value, values are
compared one by one and a node with a rejected value gets no bans, since its
full sync fails there too. The node and user reads, all chunks included, run in
one transaction at REPEATABLE READ (as `user/fetch` does), so a user who changes
group between two chunk reads is still matched in exactly one group.

Subscriptions, incremental sync, group notifications and the traffic-exceeded
check select nodes in SQL with `whereJsonContains`. For lists of string IDs
(what the admin form writes) and string scalars every path agrees. They already
disagreed with full sync before this change for integer lists, booleans and
objects (and, depending on the database, nested values); those rows are left as
they were. Making every path agree on them would change who can connect to
such nodes, so it is a separate decision.

Group `0` keeps two exceptions from before this change. A node storing the
scalar `"0"` gets no users from full sync (PHP's `empty("0")` skipped it),
while subscriptions still match it on SQLite, MySQL and PostgreSQL. A node
storing `["0"]` serves group `0` in full sync and subscriptions, but
incremental sync (`NodeSyncService::notifyUserChanged`) and the
traffic-exceeded check (`CheckTrafficExceeded`) skip users whose `group_id` is
`0`, so their changes reach the node only at the next full sync.

## Traffic ranking

The dashboard traffic ranking (`stat/getTrafficRank`) leaves out
administrators (`is_admin`) from the user ranking and admin-only nodes from the
node ranking. A node is admin-only when every group full sync serves it, as
the database matches its values, is named `admin`, and there is at least one. A
value with no group row counts only if users are still in that group, which
keeps the node in the ranking. Group names are matched (ASCII case-insensitive,
compared in PHP so MySQL's accent-insensitive collation does not also match
names such as `Ádmin`). Deleted nodes cannot be classified and stay in the
ranking, as does a node with a value the database cannot compare (full sync
fails for it there, so it serves no one); such a value affects only its own
node. Only nodes with traffic in the period are classified. Whether users
remain in a group without a row is answered for up to 500 such values per
statement (one result column each), reading the user table once per statement,
and only for nodes whose matched
groups are all admin groups. These group and user reads share one REPEATABLE
READ transaction, so a user changing group between chunks cannot make every
chunk miss them. The legacy `getServerLastRank` and `getServerYesterdayRank` endpoints,
which the current admin build does not call, are unchanged.

## Online users by node

Clicking a node's online-count cell opens `GET
/api/v2/{secure_path}/server/manage/getOnlineUsers?id=...`. Only administrators
may use this endpoint; ordinary users and staff receive 403. The ID must be a
positive integer (422 otherwise); a missing node or missing immediate parent
returns 400. Child nodes read their immediate parent's device index, matching
the ID fallback used by the existing online-count accessor. This is not a
recursive traversal or an aggregation of child nodes.

The response contains `list` (`id`, `email`, `online_ip_count`, `last_seen_at`),
`total` (the number of returned users), `reported_online` (the existing traffic
report count), and `source_node_id` (the index being read). Users are ordered by
ID; deleted users are omitted. A user is included when the node has at least
one nonempty IP field no more than 300 seconds old. IPs are deduplicated within
that node, and `last_seen_at` is the latest included timestamp, in Unix seconds.
The fresh-device count and traffic-report count have different sources and
need not agree.

This query reads the node's existing Redis index and device hashes only. It
does not refresh TTLs, remove stale entries, change device state, or initiate
the legacy all-user index migration. For deployments using a WebSocket worker,
restart that worker after an upgrade so its startup migration can run.
Full device snapshots also invoke the existing per-node index backfill while
migration is incomplete. Until the
index is populated, this view can be incomplete or empty even when the reported
count is positive. The empty-state text includes this possibility alongside
missing device reports and records older than five minutes.

Redis hash reads are pipelined in batches of at most 200 users, but the overall
query and response are not paginated or capped. It scans the entire node index,
reads each indexed user's device hash, and performs one integer-literal SQL
`IN` query for fresh users. These IDs are not individual bound parameters.
Large indices or hashes can still increase memory use, SQL size, latency and
browser rendering cost; no tested capacity threshold is claimed.

The dialog fetches on opening and supports refresh and retry. Its query is
disabled while closed and has no polling interval. Stale queries can refetch
on reconnect while open, but window-focus refetch is disabled globally in this
pinned build (`refetchOnWindowFocus: false`). The global `retry: 1` setting also
applies: a failed request is retried once automatically before the error state
is shown. Closing cancels the request through the query client's AbortSignal;
reopening uses a
new query key. Stable cell components and node-ID row keys preserve the opened
node and dialog across list refreshes and reordering. Removing or filtering
out its row, or switching between desktop and mobile list layouts, can still
unmount the dialog.

The shared Axios response interceptor silently rejects cancellation errors
instead of displaying an error toast or converting them into generic errors.
This applies to all management requests using that client; ordinary errors
retain their existing handling. It does not cancel server-side work already
started by an abandoned HTTP request.

## Copy user expiry

The user's expiry badge retains its tooltip and becomes a keyboard-accessible
copy button. It copies the full date/time using the existing display formatter,
or the localized permanent label for an unlimited expiry. The existing email
clipboard helper and success/failure notifications are reused. Labels are
provided in Chinese, English and Russian.

## Local verification

SQLite configuration preserves `DB_DATABASE=:memory:` as SQLite's special
in-memory database name. Relative file paths still resolve under the project
root. No repository-root `:memory:` file is required or ignored.

With PHP 8.2+ and the project's development dependencies installed, run these
checks in an isolated test environment (not against a production database).
The command generates an ephemeral application key for this test process only.

```sh
APP_KEY="$(php -r 'echo "base64:" . base64_encode(random_bytes(32));')" \
  APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_DRIVER=array \
  SESSION_DRIVER=array QUEUE_CONNECTION=sync LOG_CHANNEL=stderr \
  vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  --do-not-cache-result tests/Unit/SqliteDatabaseConfigTest.php \
  tests/Unit/Services/NodeOnlineUserStatesTest.php \
  tests/Feature/Admin/NodeOnlineUsersTest.php
php scripts/customize-admin.php
```

Browser checks should exercise the generated bundle: opening, empty/error
states and retry, closing an in-flight request, reopening, list refresh and
reordering, narrow-screen layout, and expiry copying. Mocked API responses can
exercise frontend branches, but do not replace the backend feature tests or
deployment validation.

## Snell nodes and Hysteria2 gecko obfs

The same script adds the `snell` node type to the admin (type list, colour,
type filter and settings form) and a `gecko` entry to the Hysteria2 obfs
select. The form and its labels live in `resources/js/admin/` next to the
other components.

Snell nodes use server `version` 5 or 6. Version 5 supports `obfs_mode`
(`none`/`http`); version 6 offers `default` or `unshaped`. The admin no longer
offers `unsafe-raw`, which cannot authenticate independent PSKs.

| `psk_mode` | Snell PSK | Snell `userkey` | ShadowTLS password |
| --- | --- | --- | --- |
| `user` (new node default) | User UUID | Omitted | Shared when enabled |
| `shared_userkey` | Shared | User UUID | Shared if set, user UUID if left blank |
| `shared` (legacy) | Shared | User UUID for plain Snell | User UUID when enabled |

Older saved nodes without `psk_mode` keep `shared` mode. Shared Snell PSKs
are 12-255 bytes. Both `user` and `shared_userkey` work without ShadowTLS.
With ShadowTLS enabled, shared passwords are 12-255 characters, and Snell
determines the user for traffic and limits in those modes. Set
`shadow_tls.server` to the DNS hostname of a reachable TLS 1.3 site.
`shadow_tls.server_port` defaults to 443. ShadowTLS replaces v5 HTTP obfs.

Clash Meta subscriptions include independent-PSK nodes both with and without
ShadowTLS. The request UA must report a recognized mihomo core version:
1.19.28+ for a v5 node (encoded as client v4), or 1.19.34+ for v6. GUI-only
version strings cannot establish core capabilities and are filtered.
Clash Meta subscriptions include `shared_userkey` for v6 on a core that
supports Snell userkeys. The panel recognizes mihomo 1.19.35+ and the Alpha
builds `d569d9e`, `3c0d10a`, and `2be2f08` that contain mihomo PR #3460.
Sing-box subscriptions require core `1.14.4` or later for plain Snell and
Snell over ShadowTLS. Local tests with xboard-node found that
`1.14.0-alpha.38` truncated UDP datagrams over Snell v6 + ShadowTLS, while
`1.14.4` passed with both `user` and `shared_userkey`. For either mode with
ShadowTLS enabled, the subscription uses a ShadowTLS outbound as the Snell
outbound's detour.

Requests with a `mihomo/...` core User-Agent select Clash Meta automatically.
For sing-box 1.12.0 and later, the panel serves a modern version of the
shipped default template when the stored template matches the original.
Older clients continue to use the legacy default. Administrators who have
customized their stored template manage its DNS and routing syntax separately.

Upgrade xboard-node before selecting `shared_userkey`. Existing clients must
refresh their subscriptions when changing modes or the shared ShadowTLS
password. Rotating a user's UUID rotates their individual credential and
revokes their old connections.

Hysteria2 nodes with gecko obfs enabled are sent to sing-box from core
`1.14.0-alpha.26` and left out of every other subscription format. Gecko uses
sing-box's default packet sizes on both sides. Both features need an
xboard-node build whose sing-box includes them.
