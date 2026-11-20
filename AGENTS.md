# XBoard (shigalin fork)

Admin UI customizations, their endpoints and local test commands are documented in
`docs/en/development/admin-customizations.md`. Read it when reviewing or changing
`resources/js/admin/`, `scripts/customize-admin.php` or the admin endpoints it describes.

## Before handoff

Apply the checks below to the behavior affected by the change. During a review, identify gaps
that affect your conclusion. When implementing a change, reuse existing tests and add focused
coverage for gaps that matter. Report any material behavior you could not verify.

1. **Stored data that validation lets through.** List every shape the column can hold: legacy rows, null,
   and JSON that is an object, scalar, boolean or nested value instead of the expected list. Also check
   references to deleted rows. Validation is often loose (`ServerSave` checks only that `group_ids` is
   an array). A malformed row degrades only itself: the list or ranking around it still returns, and the
   row is classified conservatively. Test at least one malformed value of each shape.
2. **Database behavior.** For changes to comparisons, column lengths, JSON, raw SQL, indexes or
   transaction isolation, run the relevant tests on each supported driver whose behavior affects
   the result. Check MySQL 8's accent-insensitive collation and fixed-width columns where relevant
   (`Helper::guid()` fits `v2_user.token`, a `CHAR(32)`). Include PostgreSQL when its integer
   coercion, JSON comparisons or default `READ COMMITTED` isolation matter.
3. **Calendar boundaries.** The app works in UTC+8 calendar days through `date()` and `strtotime`.
   Cover the first and last day of a month, PHP month overflow, browser DST, and calendar dates vs.
   instants. Tests use timestamps relative to a fixed point and pass on any day of the month.
4. **One snapshot.** Read values that must agree (user counters and per-node detail, a total and
   a count) from the same database snapshot. For multiple statements, `Helper::readSnapshot()`
   provides this on SQLite, MySQL and PostgreSQL when it opens the transaction. If a caller already
   opened a transaction, check its isolation level. Under `READ COMMITTED`, a concurrent reset
   can become visible between reads.
5. **Admin list refreshes.** The node list polls every 30 seconds; other queries, including the user
   list, refetch on remount or key change once their data is older than the global 30-second
   `staleTime`. Use stable cell components, key rows by ID, and check the narrow-screen layout. The zh-CN, en-US and ru-RU locales have no
   plural forms, so write count text that reads correctly for any number.
6. **Explicit definitions.** State the unit and meaning in code comments and docs: raw vs. rated
   traffic, bytes vs. characters, what "today", "used" or "admin-only" mean. When the code does not
   settle a definition, ask the user.
7. **Review the affected behavior.** On the first review and after a fix, state the outcome that
   must hold. Follow it through the relevant inputs, representations and consumers, including
   unchanged code. Verify it where the user or caller observes it. Apply the same check to any
   new mechanism introduced by the fix.
8. **Consumers.** Follow every changed field and query to what consumes it, and exercise that path:
   the admin bundle code that reads a response field (filters, forms), plugin hooks that can rewrite a
   query (`admin.user.fetch.query` may replace the select or alias the table), and other callers of a
   shared parser or helper. Keep added logic outside plugin-adjustable queries.
9. **Access paths.** Subscriptions, incremental sync and group notifications pick nodes with SQL
   `whereJsonContains('group_ids', …)`; full user sync and device bans read `Server::syncGroupIds()`.
   Before changing how any of them reads `group_ids` or user groups, probe each path on HEAD and on
   your change, on SQLite and MySQL, per stored shape. Keep every result that worked on HEAD and
   change only what failed, so no one loses or gains access as a side effect. Let the database compare
   group values with the query the path itself runs (`Server::groupValueMatches()`) instead of
   re-implementing its type coercion in PHP, and check every consumer against that same oracle
   (`GroupIdsConsistencyTest`).
10. **Attribute re-review findings.** Recheck earlier findings and report which are fixed, still
    present or unverified. For a newly reported issue, compare with the previously reviewed version
    before attributing it to the fix. Uncommitted revisions can share the same HEAD, so use the
    earlier diff or a check run on both versions. If the origin cannot be established, say so.
