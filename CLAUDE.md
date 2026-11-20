# XBoard (shigalin fork)

Admin UI customizations, their endpoints and local test commands are documented in
`docs/en/development/admin-customizations.md`. Read it before you change `resources/js/admin/`,
`scripts/customize-admin.php` or the admin endpoints it describes.

## Before handoff

Reviews of this repo keep finding the same classes of bug after the happy-path tests pass. Before you
report a change as done, check the diff against every item below. Add a test for each item that applies,
and name any item you skipped.

1. **Stored data that validation lets through.** List every shape the column can hold: legacy rows, null,
   and JSON that is an object, scalar, boolean or nested value instead of the expected list. Also check
   references to deleted rows. Validation is often loose (`ServerSave` checks only that `group_ids` is
   an array). A malformed row degrades only itself: the list or ranking around it still returns, and the
   row is classified conservatively. Test at least one malformed value of each shape.
2. **MySQL parity.** Tests default to SQLite, but production runs MySQL 8 with accent-insensitive
   collations and fixed-length columns (`v2_user.token` is `CHAR(32)`: use `Helper::guid()`, not
   `guid(true)`). Run new and changed tests on MySQL whenever the change touches string comparison,
   column lengths, JSON, raw SQL or indexes.
3. **Calendar boundaries.** The app works in UTC+8 calendar days through `date()` and `strtotime`.
   Cover the first and last day of a month, PHP month overflow, browser DST, and calendar dates vs.
   instants. Tests use timestamps relative to a fixed point and pass on any day of the month.
4. **One snapshot.** Values that must agree (user counters and per-node detail, a total and a count)
   come from one statement or one locked transaction. Traffic reports and resets run concurrently.
5. **Admin list refreshes.** The node list polls every 30 seconds; other queries, including the user
   list, refetch on remount or key change once their data is older than the global 30-second
   `staleTime`. Use stable cell components, key rows by ID, and check the narrow-screen layout. The zh-CN, en-US and ru-RU locales have no
   plural forms, so write count text that reads correctly for any number.
6. **Explicit definitions.** State the unit and meaning in code comments and docs: raw vs. rated
   traffic, bytes vs. characters, what "today", "used" or "admin-only" mean. When the code does not
   settle a definition, ask the user.
7. **Fix the class.** When you fix a review finding, fix and test every sibling input of the same kind,
   not only the reported example. Run the fix's own new mechanism back through this list.
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
