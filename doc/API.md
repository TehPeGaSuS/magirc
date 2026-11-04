# MagIRC JSON API

All endpoints are `GET`, return `application/json` and are served from the same base path as the site (prefix them with `app.base_path` if MagIRC is not at the web root). Data comes from the same cached snapshot the pages use, refreshed every `anope.cache_ttl` seconds, so polling faster than that returns the same answer.

| Endpoint | Returns |
|---|---|
| `/api/status` | `{time, counts: {users, channels, servers, operators}, peaks: {type: {count, time}}}` |
| `/api/history?range=day\|week\|month\|year` | `[{time, users, channels, servers, operators}, ...]`, bucketed (5 min, 1 h, 6 h, 1 day) |
| `/api/history?range=...&server=NAME` | `[{time, users}, ...]` for one server |
| `/api/servers` | `[{name, description, online, users, operators, uplink, linked_since, last_seen}, ...]` |
| `/api/channels` | `[{name, users, topic, modes, registered, created}, ...]` (visible channels only) |
| `/api/users` | `[{nick, ident, host, real, server, account, oper, bot, tls, away, signon, channels, country, country_name}, ...]` |
| `/api/countries` | `[{code, name, count, percent}, ...]` |

Times are unix timestamps. Hidden channels (secret, private or listed in `display.hide_channels`) and users on U-lined servers are never included.

Errors use the same status codes as the pages (`404` unknown object, `503` services unreachable) with a body of `{"error": "...", "message": "..."}`.

The 1.x endpoints under `/rest/...` no longer exist.
