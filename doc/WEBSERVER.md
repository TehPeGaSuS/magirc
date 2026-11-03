# Web server configuration

MagIRC's document root is the `public/` directory. Everything else (`conf/`,
`data/`, `src/`, `vendor/`, ...) must stay outside it, which these examples
guarantee by only ever exposing `public/`. Every request that is not an
existing file under `public/` goes to `public/index.php`.

The PHP process needs write access to `data/` and `tmp/`. On Debian/Ubuntu
that is the `www-data` user:

    chown -R www-data:www-data /var/www/magirc/data /var/www/magirc/tmp

Run the cron jobs (`sample`, `geoip:update`) as the same user so the files
they create stay writable for the web server.

## Apache with PHP-FPM

Modules needed: `proxy_fcgi`, `rewrite` (`a2enmod proxy_fcgi rewrite`) and
`setenvif` for the `proxy-fcgi-pathinfo` line. Adjust the socket path to your
PHP version (`ls /run/php/`).

```apache
<VirtualHost *:443>
    ServerName stats.example.org
    DocumentRoot /var/www/magirc/public

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/stats.example.org/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/stats.example.org/privkey.pem

    <Directory /var/www/magirc/public>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted

        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ index.php [QSA,L]
    </Directory>

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Long cache for the static assets; they are versioned by query string.
    <LocationMatch "^/assets/">
        Header set Cache-Control "public, max-age=604800"
    </LocationMatch>

    ErrorLog  ${APACHE_LOG_DIR}/magirc-error.log
    CustomLog ${APACHE_LOG_DIR}/magirc-access.log combined
</VirtualHost>

<VirtualHost *:80>
    ServerName stats.example.org
    Redirect permanent / https://stats.example.org/
</VirtualHost>
```

With `AllowOverride None` the rewrite rules live in the vhost as above and
the shipped `public/.htaccess` is ignored. If you prefer `.htaccess`, set
`AllowOverride All` and drop the three `Rewrite*` lines from the vhost.

### Apache with mod_php

Same vhost without the `<FilesMatch>` block; `libapache2-mod-php` handles
`.php` files directly.

## nginx with PHP-FPM

```nginx
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name stats.example.org;

    ssl_certificate     /etc/letsencrypt/live/stats.example.org/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/stats.example.org/privkey.pem;

    root  /var/www/magirc/public;
    index index.php;

    access_log /var/log/nginx/magirc-access.log;
    error_log  /var/log/nginx/magirc-error.log;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    # Only index.php is ever executed.
    location ~ \.php$ {
        return 404;
    }

    location /assets/ {
        expires 7d;
        add_header Cache-Control "public";
    }
}

server {
    listen 80;
    listen [::]:80;
    server_name stats.example.org;
    return 301 https://$host$request_uri;
}
```

## Caddy

```caddyfile
stats.example.org {
    root * /var/www/magirc/public
    php_fastcgi unix//run/php/php8.3-fpm.sock
    file_server
    header /assets/* Cache-Control "public, max-age=604800"
}
```

Caddy obtains the certificate itself.

## Serving under a sub-path

To serve MagIRC at `https://www.example.org/stats/` instead of its own host,
set `'base_path' => '/stats'` in `conf/magirc.php` and map that prefix to
`public/`:

nginx:

```nginx
location /stats/ {
    alias /var/www/magirc/public/;
    try_files $uri /stats/index.php$is_args$args;
}

location ~ ^/stats/index\.php(/|$) {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /var/www/magirc/public/index.php;
    internal;
}
```

Apache:

```apache
Alias /stats /var/www/magirc/public
<Directory /var/www/magirc/public>
    Require all granted
    RewriteEngine On
    RewriteBase /stats
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</Directory>
```

## Reverse proxy in front

If TLS terminates on another host or container, nothing changes for
MagIRC: it builds its links from the request path only. Pass
`X-Forwarded-Proto` through if you want your access logs to show the
original scheme.

## Checking

After reloading the web server:

* `https://stats.example.org/` renders the overview;
* `https://stats.example.org/api/status` returns JSON;
* `https://stats.example.org/../conf/magirc.php` and
  `https://stats.example.org/conf/magirc.php` are 404s (they must be: the
  document root is `public/`, nothing above it is reachable).
