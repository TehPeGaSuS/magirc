<?php
/**
 * MagIRC 2.x configuration.
 *
 * Copy this file to conf/magirc.php and edit it. Every key below has a sane
 * default; the only ones you must set are the network name and the Anope
 * RPC token. Run `php bin/magirc check` afterwards to verify the setup.
 */

return [

    // ---- Network ----------------------------------------------------------
    'network' => [
        'name'       => 'MyNetwork',
        'url'        => 'https://www.mynet.tld/',
        // Server users should connect to (round-robin host) and its ports.
        // The footer shows the TLS port when set and the plain port only as
        // a fallback. Set 'port' to null if you do not want to advertise a
        // plaintext port at all.
        'server'     => 'irc.mynet.tld',
        'port'       => null,
        'port_tls'   => 6697,
        // Optional webchat URL. "%nick%" and "%channel%" are substituted.
        'webchat'    => '',
    ],

    // ---- Anope JSON-RPC (rpc_data) ----------------------------------------
    // See doc/anope-2.1.example.conf for the Anope side.
    'anope' => [
        'url'   => 'http://127.0.0.1:8080/jsonrpc',
        // The token from the jsonrpc:token block in Anope's modules.conf.
        // MagIRC base64-encodes it as Anope expects; paste it as-is.
        'token' => '',
        // Seconds a fetched snapshot of the network is reused before asking
        // Anope again. Keeps a busy site from hammering services.
        'cache_ttl' => 15,
        'timeout'   => 5,
    ],

    // ---- Channel activity statistics (optional) ---------------------------
    // Anope's chanstats module, MySQL/MariaDB only. Leave 'dsn' empty to
    // disable the activity pages entirely.
    'chanstats' => [
        'dsn'      => '',          // e.g. 'mysql:host=127.0.0.1;port=3306;dbname=anope;charset=utf8mb4'
        'username' => 'anope',
        'password' => '',
        // Same value as chanstats:prefix in Anope's chanstats.conf.
        'prefix'   => 'anope_',
    ],

    // ---- MagIRC's own history store ---------------------------------------
    // Holds user/channel/server counts sampled by `php bin/magirc sample`
    // (run it from cron, e.g. every 5 minutes) and the all-time peaks.
    'history' => [
        'dsn'      => 'sqlite:' . __DIR__ . '/../data/history.sqlite',
        'username' => null,
        'password' => null,
        // How long samples are kept, in days. 0 keeps everything.
        'retention_days' => 365,
    ],

    // ---- GeoIP (optional) -------------------------------------------------
    // Path to a MaxMind GeoLite2-Country.mmdb (or City) database. Empty
    // disables the country statistics.
    //
    // `php bin/magirc geoip:update` downloads and refreshes the file for you.
    // It needs a free MaxMind account (https://www.maxmind.com/en/geolite2/signup)
    // and a license key; MaxMind refreshes GeoLite2 twice a week, so running
    // the command weekly from cron keeps it current.
    'geoip' => [
        'database'    => '',   // e.g. __DIR__ . '/../data/GeoLite2-Country.mmdb'
        'account_id'  => '',
        'license_key' => '',
        'edition'     => 'GeoLite2-Country',
    ],

    // ---- Display ----------------------------------------------------------
    'display' => [
        'locale'    => 'en_US',
        'timezone'  => 'UTC',
        'theme'     => 'default',
        // Page shown on "/": 'status' (network overview) or 'welcome'
        // (content/welcome.md rendered as Markdown).
        'home'      => 'status',
        // Show the MagIRC version in the footer.
        'show_version' => true,
        // Only list channels registered with ChanServ and users logged in to
        // a NickServ account. Anope keeps no data about anything else, so
        // unregistered channels and users are transient noise on a stats
        // site. These rules affect the lists and detail pages only: the
        // counts ("users online", "channels", history, peaks, per-server and
        // per-channel user counts) include everyone and every public channel.
        'registered_channels_only' => true,
        'registered_users_only'    => true,
        // Hide secret (+s) and private (+p) channels, and users on U-lined
        // (services) servers.
        'hide_secret_channels'  => true,
        'hide_private_channels' => true,
        'hide_ulined'           => true,
        // Channels and servers to hide regardless of modes.
        'hide_channels' => ['#opers', '#services'],
        'hide_servers'  => [],
        // Show the user's realname on the user pages.
        'show_realname' => true,
        // Rows per page in the long tables (users, channels, members). The
        // browser does the paging, so sorting and filtering still cover the
        // whole list. 0 disables paging.
        'page_size' => 50,
        // Also list services operators (accounts with an oper type, online
        // or not) on the operators page. Off by default: it publishes the
        // account names of everyone with services access.
        'show_services_opers' => false,
    ],

    // ---- Application ------------------------------------------------------
    'app' => [
        // Base path when MagIRC is not installed at the web root, e.g. '/stats'.
        'base_path' => '',
        // Writable directory for Twig's compiled templates.
        'cache_dir' => __DIR__ . '/../tmp',
        'debug'     => false,
    ],
];
