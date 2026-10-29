# Usage overview and recent IPs

The upstream `.gitmodules` points to `cedar2025/xboard-admin-dist`, which only
contains compiled admin assets. This checkout pins build `a260c84d` (dist commit
`ef5f43d`). The readable usage and recent-IP components live in
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
download, across all rate rows, and sums user traffic including node rates.
Days without records return zero. The summary counts distinct users across the
whole selected period, rather than summing daily counts.

`GET /api/v2/{secure_path}/user/getRecentIPs?id=...` returns node-reported usage
IPs, most recent first, with `last_seen_at` and `is_online`. Both endpoints use
the existing admin middleware. IP history starts with reports received after
this change, retains up to 100 IPs per user for 24 hours, and expires in Redis.
It is independent of the five-minute online state and survives disconnects and
empty device reports. Reports rejected by the sequence fence do not update it.
