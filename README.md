# MagIRC

> [!NOTE]
> MagIRC 2 was written with the help of an AI coding assistant (Claude Code, Anthropic). The design decisions, the Anope integration and the review of every change are the maintainer's; the code was tested against a live Anope 2.1 network before release. Read it with the same care you would give any other dependency, and report anything that looks wrong in the issue tracker.

MagIRC is a web frontend for IRC network statistics, built for [Anope](https://www.anope.org/) 2.1. It shows who is online, which channels are busy, how the servers are doing and, optionally, how active each channel and user is.

MagIRC 2 is a rewrite of the PHP 5 era MagIRC 1.x. It reads live data from Anope's JSON-RPC interface instead of the removed `irc2sql` module, needs no MySQL for the core pages, and has a new interface with light and dark themes.

## Features

* Network overview with the number of users online over the last 24 hours and 30 days, peaks, busiest channels and servers
* Server, channel, user and operator pages, with sortable and filterable tables
* Lists only registered channels and users logged in to an account by default (Anope keeps no data about anything else), while the counts still include everyone; both rules can be switched off
* Countries of the users online (GeoLite2, optional)
* Channel activity statistics from Anope's `chanstats` module (optional): lines, words, smileys, kicks, activity by hour of day, per channel and per account
* JSON API for the live data and history
* Light and dark theme, following the visitor's system by default
* Language menu in the header; English and Portuguese (`pt_PT`) included, `display.locale` lists what to offer

## Requirements

* PHP 8.2 or newer with the `pdo`, `pdo_sqlite`, `mbstring`, `json` and `intl` extensions
  (`pdo_mysql` as well if you want the activity statistics)
* [Composer](https://getcomposer.org)
* A web server pointing at the `public/` directory (Apache with `mod_rewrite`, nginx, Caddy, ...)
* Anope 2.1.16 or newer with the `httpd`, `jsonrpc` and `rpc_data` modules
* For the activity statistics: Anope's `mysql` and `chanstats` modules and a MySQL or MariaDB server
* For the country statistics: a MaxMind GeoLite2 database (free account)

## Installation

1. Get the code and its dependencies:

       git clone https://github.com/TehPeGaSuS/magirc.git
       cd magirc
       composer install --no-dev

   The JavaScript chart library is bundled in `public/assets/vendor/`; no Node.js is needed to run MagIRC.

2. Configure Anope. `doc/anope-2.1.example.conf` shows the `httpd`, `jsonrpc` and `rpc_data` blocks to enable, and the optional `mysql` + `chanstats` setup. Bind `httpd` to localhost or put it behind TLS: whoever can reach it with the token can read the full user list.

3. Configure MagIRC:

       cp conf/magirc.example.php conf/magirc.php

   Edit the file. The network name and the Anope RPC token are the only required settings; every other key has a working default and is documented in the file.

4. Check everything:

       php bin/magirc check

   It verifies the configuration, the connection to Anope and the token's permissions, the history store, the chanstats database (including the MySQL event scheduler) and the GeoIP file, and tells you how to fix whatever is wrong.

5. Point your web server at `public/`. `doc/WEBSERVER.md` has complete examples for Apache (PHP-FPM and mod_php), nginx and Caddy, including serving MagIRC under a sub-path. The short version: document root `public/`, every request that is not an existing file goes to `index.php` (Apache does this through the shipped `public/.htaccess`; nginx with `try_files $uri /index.php$is_args$args;`).

6. Record history. The charts and the peaks come from samples MagIRC takes itself. Add to cron:

       */5 * * * *  php /path/to/magirc/bin/magirc sample

   The `data/` and `tmp/` directories must be writable by the web server user and by the user running cron.

## Optional: country statistics

MagIRC looks up each user's address in a GeoLite2 Country database. Set `geoip.database` to the path of the `.mmdb` file. To let MagIRC download and refresh it, create a free account at https://www.maxmind.com/en/geolite2/signup, generate a license key, set `geoip.account_id` and `geoip.license_key`, and run weekly from cron:

    0 4 * * 1  php /path/to/magirc/bin/magirc geoip:update

## Optional: activity statistics

Anope's `chanstats` module counts messages in channels that enabled it (`/msg ChanServ SET CHANSTATS #channel ON`) for users that enabled it (`/msg NickServ SET CHANSTATS ON`). It needs MySQL or MariaDB. Point `chanstats.dsn` at that database and set `chanstats.prefix` to the same value as in Anope's `chanstats.conf`.

The "today", "this week" and "this month" figures only work when the MySQL event scheduler is on (`SET GLOBAL event_scheduler = ON;` and `event_scheduler = ON` under `[mysqld]`), because Anope resets those counters with scheduled events. With the scheduler off, MagIRC shows all-time figures only and `bin/magirc check` says why.

## Upgrading from MagIRC 1.x

MagIRC 2 is a new application; there is no in-place upgrade. Install it as above, then:

* Remove the `irc2sql` module and MagIRC's old `anope_history`/`anope_maxusage` tables and events from your Anope database: nothing uses them any more.
* The `magirc` database and the admin panel are gone. Settings live in `conf/magirc.php`; the welcome text lives in `content/welcome.md`.
* Client statistics (CTCP VERSION) were dropped: many clients block the request and Anope 2.1 does not collect it.
* Old REST URLs changed. See `doc/API.md`.

## Development

    composer install
    php -S 127.0.0.1:8090 -t public public/index.php
    vendor/bin/phpunit

`doc/RPC-MAPPING.md` documents which rpc_data fields and chanstats columns each page uses. `tools/` holds the scripts used to test against a local Anope.

## Credits and license

MagIRC was created by Sebastian Vassiliou (h9k) as a frontend for Denora and Anope 2.0; the 1.x code lives on at [h9k/magirc](https://github.com/h9k/magirc). Version 2 is a rewrite maintained by PeGaSuS in this repository, which was detached from the original fork network after the rewrite.

GPL-3.0-or-later.
