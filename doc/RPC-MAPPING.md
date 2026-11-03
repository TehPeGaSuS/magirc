# MagIRC 2.x data sources: rpc_data + chanstats

Verified on 2026-11-03 against Anope 2.1.28-git linked to UnrealIRCd 6, with
`httpd`, `jsonrpc`, `rpc_data` and `chanstats` (MariaDB 11.8) loaded.
See `anope-2.1.example.conf` for the Anope side.

MagIRC 1.x read the `irc2sql` tables. `irc2sql` was removed in Anope 2.1.16
(May 2025) in favour of `rpc_data`, so 1.x cannot run against 2.1 at all.

## Calling the RPC

* URL: `http://<httpd ip>:<port>/jsonrpc`, JSON-RPC 2.0 over POST.
* Auth: `Authorization: Bearer <base64(token)>`. Anope base64-decodes the
  bearer value before comparing it with the configured `token`. Sending the
  raw token yields `-32601 No authorization for method`.
* Per-token `methods` globs are evaluated in order; `~` negates. A method not
  matched by any glob is refused with the same `-32601` error, so the token
  needs `anope.*` (or the individual `anope.list*`/`anope.user` etc.).
* All methods return the full object; "name" detail level on the `list*`
  methods returns bare names, "full" returns a map keyed by name.

## Field mapping

| 1.x source (irc2sql column) | 2.x source | Notes |
|---|---|---|
| `anope_user.nick` | `anope.user.nick` | |
| `.ident` / `.vident` | `.ident` / `.vident` | |
| `.host` / `.chost` / `.vhost` | `.host` / `.chost` / `.vhost` | cloaked host and vhost both present |
| `.realname` | `.real` | |
| `.ip` | `.address` | real IP, needs the token to be trusted |
| `.signon` | `.signon` | unix time |
| `.modes` | `.modes` (array, e.g. `["+xiw"]`) | IRC operator = `o` in modes |
| `.account` | `.account.display`, `.account.uniqueid`, `.account.opertype` | `null` when not logged in |
| `.away` / `.awaymsg` | `.away.time` / `.away.message` or `null` | not exercised in the test (see gaps) |
| `.secure` | `.tls` | |
| `.fingerprint` | `.fingerprint` | |
| `.server` | `.server` | |
| `.uuid` | `.uid` | |
| `.country_code` / `.country_name` / city / region | **gone** | irc2sql did GeoIP itself; MagIRC 2 must look `address` up in a GeoLite2 database |
| `.version` (CTCP VERSION reply) | **gone** | irc2sql sent CTCP VERSION on connect; rpc_data has nothing. See gaps |
| `anope_chan.channel` / `.topic` / `.topicauthor` / `.topictime` | `anope.channel.name` / `.topic.value` / `.topic.setby` / `.topic.setat` | `topic` is `null` when unset |
| `.modes` | `.modes` (array) + `.listmodes` (map) | hide `+s`/`+p` channels in MagIRC as before |
| `.currentusers` | `len(.users)` | `users` entries carry status prefixes (`@~Alice`) |
| `.time` (creation) | `.created` | |
| — | `.registered` | new, boolean |
| `anope_ison` (user ↔ channel) | `anope.user.channels` (with prefixes) and `anope.channel.users` | both directions available |
| `anope_server.server` / `.description` | `anope.server.name` / `.description` | |
| `.currentusers` | count of `anope.listUsers full` by `.server` | one call gives everything |
| `.online` / `.split_time` | **gone** | `listServers` only knows linked servers; offline servers and split times need MagIRC's own history store |
| `.version` / `.connect_time` / `.uptime` | **gone** | not in `anope.server`; `sid`, `uplink`, `downlinks`, `synced`, `ulined`, `juped` are |
| `.ulined` | `.ulined` | use it to drop services pseudo-clients from user counts and lists |
| `anope_maxusers` | **gone** | MagIRC's own sampler (history store) |
| `anope_history` (MagIRC's own event) | MagIRC's own sampler | the MySQL events in `setup/sql/anope.sql` counted irc2sql rows, so they are dead too |
| "operators" page (umode +o users) | `listUsers full`, filter `o` in `.modes` | `anope.listOpers` is *services* opers (accounts with an opertype), a different thing; show both |

## chanstats (optional activity pages)

Table `<prefix>chanstats`, one row per (`chan`, `nick`, `type`) with
`type` in `total, monthly, weekly, daily`. Columns: `letters words line actions
smileys_happy smileys_sad smileys_other kicks kicked modes topics time0..time23`.
Row kinds, as observed:

* `chan='#x', nick=''`: channel totals.
* `chan='#x', nick='Alice'`: per-account rows (account display name, not nick).
* `chan='', nick='Alice'`: the account's totals across channels.

Only channels with `CS SET CHANSTATS #x ON` are counted; only identified
accounts with `NS SET CHANSTATS ON` get per-account rows. Messages from
users without an opted-in account were **not** counted in the channel totals
in the test (3 users chatting, totals only reflected the 2 opted-in ones), so
"channel activity" really means "activity of opted-in users".

The daily/weekly/monthly rows are plain counters reset by three MySQL
`EVENT`s (`chanstats_event_cleanup_{daily,weekly,monthly}`), which only run
when the server's `event_scheduler` is ON. With it OFF (MySQL/MariaDB
default) the four rows per key stay identical forever. MagIRC 2 therefore:

1. detects the state with `SELECT @@GLOBAL.event_scheduler` and
   `SELECT EVENT_NAME, STATUS, LAST_EXECUTED FROM information_schema.EVENTS
   WHERE EVENT_NAME LIKE 'chanstats_event_cleanup_%'`;
2. shows Today/This week/This month/All time when the scheduler is on and
   the events exist;
3. shows a single All-time view otherwise, and reports the reason in the
   `check` CLI together with the `SET GLOBAL event_scheduler = ON` fix.

A later option is to snapshot the `total` rows daily into MagIRC's own store
and derive periods by subtraction, which works regardless of the scheduler
and also gives "yesterday"/"last week" comparisons.

## Gaps and decisions needed

* **Client breakdown** (the "Clients" pie, from CTCP VERSION replies): no
  equivalent in `rpc_data`, and many clients block CTCP VERSION anyway.
  Decision (2026-11-03): removed entirely. No client pages, no `client`
  column, no CTCP probing.
* **GeoIP**: MagIRC 2 does the lookup itself from `address` (GeoLite2
  country database via the `maxminddb` PHP extension or `geoip2/geoip2`).
* **Away state**: field exists; not confirmed end-to-end in the test
  (UnrealIRCd did relay nothing for a test AWAY). Treat as best-effort.
* **Server version / uptime / splits**: not available; those columns go, or
  MagIRC records link/unlink times itself from successive samples.
* **History and peaks**: MagIRC's own sampler, no MySQL events, no
  `setup/sql/anope.sql`.
