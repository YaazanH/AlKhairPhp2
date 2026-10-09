# SaaS installation on a new CloudPanel VPS

This guide installs AlKhair SaaS next to an existing application on the same
VPS. It does not alter the existing application, its files, its database, or
its domain.

The example names below use:

| Item | Example |
| --- | --- |
| SaaS platform host | `saas.example.com` |
| Tenant host | `demo.saas.example.com` |
| CloudPanel site user | `alkhair-saas` |
| Application release directory | `/home/alkhair-saas/htdocs/saas-app` |
| Laravel document root | `/home/alkhair-saas/htdocs/saas-app/public` |
| Landlord database | `alkhair-saas-landlord` |
| MySQL application user | `alkhair-saas` |

Replace every example domain, account name, database name, and email address
with the values for the new server.

## 1. Prepare DNS

At the DNS provider, add records pointing to the VPS public IP:

| Type | Name | Value |
| --- | --- | --- |
| A | `saas` | VPS IPv4 address |
| A | `*.saas` | VPS IPv4 address |

The wildcard record is required for future tenants. With the example above,
`school-a.saas.example.com` and `school-b.saas.example.com` both reach the
same SaaS application. Do not replace the existing root-domain records used by
the existing application.

Confirm resolution before requesting TLS certificates:

```bash
getent hosts saas.example.com
getent hosts demo.saas.example.com
```

## 2. Create the CloudPanel PHP site

1. In CloudPanel, create a new PHP site for `www.saas.example.com` using the
   target PHP version. This creates a distinct Linux site user.
2. Add `saas.example.com` as a domain alias if CloudPanel requires it. The
   custom Vhost in this guide handles both `www` and non-`www` names.
3. Do not use this site's generated `public` placeholder directory for the
   application source. Clone the release to the separate directory specified
   below.

CloudPanel's **Root Directory** setting is relative to its generated site
directory. It cannot safely point at a sibling directory such as
`/home/alkhair-saas/htdocs/saas-app/public`. The Vhost explicitly sets the
Laravel root instead.

## 3. Verify server dependencies

Connect as the new site user and verify PHP, Composer, Node, and PHP modules:

```bash
php8.4 -v
composer --version
node --version
npm --version
php8.4 -m
```

Required PHP modules include `bcmath`, `curl`, `dom`, `gd`, `intl`,
`mbstring`, `pdo_mysql`, `xml`, and `zip`.

Use Node 20 or newer for the current frontend build.

## 4. Clone and build the release

As the site user:

```bash
cd /home/alkhair-saas/htdocs
git clone --branch integration/modules-tenant-master-mudeer REPOSITORY_URL saas-app
cd saas-app

git branch --show-current
git rev-parse --short HEAD

composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts
npm ci
npm run build
```

Use the intended release branch and record its commit SHA. Do not deploy from
an unreviewed working tree.

Ensure Laravel can write its runtime directories:

```bash
chmod -R ug+rwX storage bootstrap/cache
```

## 5. Issue a wildcard TLS certificate

One wildcard certificate covers every tenant hostname. You do not issue a new
certificate when adding a tenant.

Use DNS-01 validation with the DNS provider's API token. For example, with
acme.sh and a provider-supported DNS API:

```bash
acme.sh --issue --server letsencrypt --dns DNS_PROVIDER_PLUGIN \
  -d saas.example.com -d '*.saas.example.com'
```

Install the certificate and key in a root-owned directory, then use CloudPanel
to install the certificate for the SaaS site's primary domain. Configure the
acme.sh install command's renewal hook to re-run CloudPanel's certificate
install command. Confirm that the acme.sh cron job exists:

```bash
crontab -l | grep -F acme.sh
```

Validate the site configuration afterward:

```bash
nginx -t
```

## 6. Configure the CloudPanel Vhost

Open **Sites → www.saas.example.com → Vhost** and replace its contents with
the following template. The two explicit `root` lines must point to the actual
Laravel `public` directory.

```nginx
server {
    listen 80;
    listen [::]:80;
    listen 443 quic;
    listen 443 ssl;
    listen [::]:443 quic;
    listen [::]:443 ssl;

    http2 on;
    http3 off;

    {{ssl_certificate_key}}
    {{ssl_certificate}}

    server_name saas.example.com www.saas.example.com *.saas.example.com;

    root /home/alkhair-saas/htdocs/saas-app/public;
    {{nginx_access_log}}
    {{nginx_error_log}}

    if ($host = www.saas.example.com) {
        return 301 https://saas.example.com$request_uri;
    }

    if ($scheme != "https") {
        return 301 https://$host$request_uri;
    }

    location ^~ /.well-known/ {
        auth_basic off;
        allow all;
    }

    {{settings}}

    include /etc/nginx/global_settings;

    # Tenant public files must be authorized by Laravel.
    location ^~ /storage/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_redirect off;
        proxy_read_timeout 720;
    }

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_redirect off;
        proxy_max_temp_file_size 0;
        proxy_connect_timeout 720;
        proxy_send_timeout 720;
        proxy_read_timeout 720;
        proxy_buffer_size 128k;
        proxy_buffers 4 256k;
        proxy_busy_buffers_size 256k;
        proxy_temp_file_write_size 256k;
    }

    location ~ /\.(?!well-known) { deny all; }

}

server {
    listen 8080;
    listen [::]:8080;

    server_name saas.example.com www.saas.example.com *.saas.example.com;

    root /home/alkhair-saas/htdocs/saas-app/public;

    index index.php index.html;

    location ^~ /storage/ {
        rewrite ^ /index.php last;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ /\.(?!well-known) { deny all; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_intercept_errors on;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        try_files $uri =404;
        fastcgi_read_timeout 3600;
        fastcgi_send_timeout 3600;
        fastcgi_param HTTPS "on";
        fastcgi_param SERVER_PORT 443;
        fastcgi_pass 127.0.0.1:{{php_fpm_port}};
        fastcgi_param PHP_VALUE "{{php_settings}}";
    }

}
```

After saving, test and reload Nginx as root:

```bash
nginx -t && systemctl reload nginx
```

If the site displays `Hello World :-)`, Nginx is still using CloudPanel's
placeholder root. Check the expanded Vhost:

```bash
nginx -T 2>&1 | grep -n -A 70 -B 10 'server_name.*saas\.example\.com'
```

Both server blocks must show the Laravel root, not
`/home/alkhair-saas/htdocs/www.saas.example.com/public`.

## 7. Create the landlord database

In CloudPanel, create a database and application user. CloudPanel does not
allow underscores in these field names, so use hyphens or letters/numbers:

```text
Database name:      alkhair-saas-landlord
Database user name: alkhair-saas
```

Create a strong password and store it in a password manager. CloudPanel
creates this user with access to the landlord database.

## 8. Configure `.env`

The `.env` file is server-specific and must never be committed. Create it from
the example if it is missing:

```bash
cd /home/alkhair-saas/htdocs/saas-app
cp .env.example .env
chmod 640 .env
```

Set these values. Use the same MySQL user and password for all three
connection sections. `DB_*` and `LANDLORD_DB_*` intentionally point to the
same landlord database. `TENANT_DB_*` is a template; its database name is set
dynamically per tenant.

```env
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://saas.example.com
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=alkhair-saas-landlord
DB_USERNAME=alkhair-saas
DB_PASSWORD=REPLACE_WITH_DATABASE_PASSWORD

LANDLORD_DB_CONNECTION=mysql
LANDLORD_DB_HOST=127.0.0.1
LANDLORD_DB_PORT=3306
LANDLORD_DB_DATABASE=alkhair-saas-landlord
LANDLORD_DB_USERNAME=alkhair-saas
LANDLORD_DB_PASSWORD=REPLACE_WITH_DATABASE_PASSWORD

TENANT_DB_CONNECTION=mysql
TENANT_DB_HOST=127.0.0.1
TENANT_DB_PORT=3306
TENANT_DB_USERNAME=alkhair-saas
TENANT_DB_PASSWORD=REPLACE_WITH_DATABASE_PASSWORD
TENANT_BASE_DOMAIN=saas.example.com

SESSION_DOMAIN=
SESSION_DRIVER=file

CACHE_STORE=database
QUEUE_CONNECTION=database
```

Keep only one `SESSION_DOMAIN` line and leave it empty. This makes browser
sessions host-only: a platform session is not sent to tenant subdomains.

Generate the key once:

```bash
php8.4 artisan key:generate --force
```

Do not regenerate `APP_KEY` after tenant data or encrypted backups exist.

## 9. Create landlord tables and cache support

Check the connection without showing credentials:

```bash
php8.4 artisan config:clear
php8.4 artisan tinker --execute="DB::connection('landlord')->getPdo(); echo 'Database connection works.'.PHP_EOL;"
```

Create only the SaaS control-plane tables, then seed the standard plans:

```bash
php8.4 artisan migrate --database=landlord --path=database/migrations/landlord --force
php8.4 artisan db:seed --database=landlord --class=Database\\Seeders\\LandlordCatalogSeeder --force
```

Laravel's database cache also needs its own support tables in the landlord
database. Run only the cache migration:

```bash
php8.4 artisan migrate --database=landlord --path=database/migrations/0001_01_01_000001_create_cache_table.php --force
```

Do **not** run plain `php artisan migrate` against the landlord database. The
normal migration history is the tenant application schema and is applied when
a tenant is provisioned.

Build runtime caches:

```bash
php8.4 artisan optimize:clear
php8.4 artisan config:cache
php8.4 artisan route:cache
php8.4 artisan view:cache
```

## 10. Create the platform administrator

```bash
php8.4 artisan saas:platform-admin admin@example.com --name="Platform Admin"
```

Enter the password at the prompt. Verify the platform login at:

```text
https://saas.example.com/platform/login
```

## 11. Allow automatic tenant-database creation

The application needs `CREATE` globally because MySQL requires that privilege
to create a database. It receives full rights only for its own tenant database
name pattern, not for the existing application's database.

As root, get the CloudPanel master credentials, connect to MySQL, and apply
the grants. The host below is `%`; first confirm the actual host with
`SELECT User, Host FROM mysql.user WHERE User = 'alkhair-saas';`.

```sql
GRANT CREATE ON *.* TO 'alkhair-saas'@'%';
GRANT ALL PRIVILEGES ON `alkhair\_tenant\_%`.* TO 'alkhair-saas'@'%';
FLUSH PRIVILEGES;
SHOW GRANTS FOR 'alkhair-saas'@'%';
```

The resulting grants should include:

```text
GRANT CREATE ON *.* TO `alkhair-saas`@`%`
GRANT ALL PRIVILEGES ON `alkhair-saas-landlord`.* TO `alkhair-saas`@`%`
GRANT ALL PRIVILEGES ON `alkhair\_tenant\_%`.* TO `alkhair-saas`@`%`
```

## 12. Provision the first tenant

Sign in to the platform and open:

```text
https://saas.example.com/platform/tenants/create
```

Use the **New tenant** form. For a test tenant:

| Form field | Example |
| --- | --- |
| Organisation name | `Demo Tenant` |
| Subdomain | `demo` |
| Tenant administrator name | `Demo Administrator` |
| Tenant administrator email | `tenant-admin@example.com` |
| Temporary password | A new strong password |
| Timezone | `Asia/Damascus` |
| Locale | Arabic |
| Initial package | Core |

Click **Create tenant** once and wait for the result. The application creates
a database named `alkhair_tenant_<unique-id>`, migrates it, seeds it, creates
tenant storage, and registers `demo.saas.example.com`.

Use a tenant-administrator email different from the platform-administrator
email. This creates two tenant users: the tenant owner with the `admin` role,
who must change the temporary password at first sign-in, and the linked
platform administrator with the `super_admin` role. If both emails are the
same, the application deliberately creates one shared platform-administrator
account instead. That account is not required to change its password.

Verify both hosts:

```bash
curl -I https://saas.example.com/platform/login
curl -I https://demo.saas.example.com
```

## 13. Scheduler, backups, and ongoing releases

Create a CloudPanel cron job for the site user:

```cron
* * * * * php8.4 /home/alkhair-saas/htdocs/saas-app/artisan schedule:run >> /dev/null 2>&1
```

Before accepting real tenant data, configure off-VPS backups for:

1. the landlord database;
2. every `alkhair_tenant_<unique-id>` database;
3. `storage/app/public/tenants/{tenant-uuid}`;
4. `storage/app/private/tenants/{tenant-uuid}`.

Test a complete restore on a non-production system. A tenant database and its
two tenant storage directories must be restored together.

For a later release, first back up the affected databases and storage, then
use the deployment sequence in [SaaS VPS runbook](saas-vps-runbook.md).

## Troubleshooting

| Symptom | Cause and action |
| --- | --- |
| `Hello World :-)` | Nginx is serving CloudPanel's placeholder directory. Check both explicit `root` lines in the Vhost. |
| `Table ... cache doesn't exist` during `optimize:clear` | Run only `0001_01_01_000001_create_cache_table.php` against the landlord database. |
| Platform login does not persist | Confirm `SESSION_DRIVER=file`, a single empty `SESSION_DOMAIN=`, and writable `storage/framework/sessions`. |
| Tenant provisioning reports database permission denied | Confirm the `CREATE` global grant and the `alkhair\_tenant\_%` database-pattern grant for the configured MySQL user and host. |
| A tenant host has certificate warnings | Confirm the wildcard DNS record and wildcard certificate contain `*.saas.example.com`. |
| Tenant media returns 404 | Keep the Vhost `/storage/` location routed through Laravel; do not add a broad static storage alias. |
