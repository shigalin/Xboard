# Usage overview and online IPs

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
