# Database and document backups

Automatic backups contain only the database. The backup page has separate manual
actions for the database and for documents/uploads. Each type has its own retention
limit. Existing full backups remain readable. Restoring a database backup leaves
files alone; restoring a files backup leaves the database alone. Restoration creates
a safety copy of the same type first.

## Deployment

Run `php artisan migrate --force` after deploying to add the backup scope column.
Keep the existing application key: encrypted backups need that key to be read.

The PHP runtime needs ZipArchive and the database command-line clients. The Docker
image installs MySQL/MariaDB and PostgreSQL clients. Existing images must be rebuilt:

```sh
docker compose --env-file .env.docker up -d --build web queue scheduler
docker compose --env-file .env.docker exec web php artisan migrate --force
docker compose --env-file .env.docker logs --tail=100 scheduler
```

For a non-Docker server, the hosting administrator must run Laravel's scheduler
every minute as the application user. Replace the PHP and project paths:

```cron
* * * * * cd /path/to/AlKhairPhp2 && /usr/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

The application user needs write access to storage and permission to read/dump the
database. `mysqldump`/`mysql` or `pg_dump`/`pg_restore` must be on PATH (or set the
`BACKUP_*_BINARY` variables from `config/backups.php`).

## Diagnosis

The backup page reports whether the scheduled backup command has checked in during
the last five minutes. A missing heartbeat means the scheduler needs attention;
opening the website alone does not run scheduled backups.

```sh
php artisan schedule:list
php artisan backup:health
php artisan backup:run --scheduled
```

The last command creates a database backup only when a scheduled slot is due.
Failures retry after 15 minutes; abandoned attempts stop blocking after two hours.
Concurrent backup and restore operations share a lock, and schedule eligibility is
checked inside that lock. Inspect `storage/logs/laravel.log` for failure details.

For manual operations:

```sh
php artisan backup:run
php artisan backup:run --files
```

`--files` cannot be combined with `--scheduled`.
