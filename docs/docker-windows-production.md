# Docker Deployment For Windows Host

This setup is the recommended Docker shape for this repo if the target production machine is a Windows machine running Docker Desktop.

It uses:

- Apache inside the app container
- PHP 8.4
- MySQL 8.4
- one shared app image
- separate `web`, `queue`, and `scheduler` services
- named Docker volumes for the database and Laravel storage

## Why This Shape

This app is not just a single web process. It also needs:

- a queue worker because the app uses Laravel queues
- a scheduler container for Laravel scheduled tasks
- persistent storage because uploads, generated reports, and logs live under `storage`

For a Windows host, this is better than bind-mounting the whole project directory into the container. The code is baked into the image, which avoids many Windows filesystem and permissions problems.

## Files Added

- [Dockerfile](/c:/Users/yazon/source/repos/AlKhairPhp/Dockerfile)
- [docker-compose.yml](/c:/Users/yazon/source/repos/AlKhairPhp/docker-compose.yml)
- [.env.docker.example](/c:/Users/yazon/source/repos/AlKhairPhp/.env.docker.example)
- [docker/apache/000-default.conf](/c:/Users/yazon/source/repos/AlKhairPhp/docker/apache/000-default.conf)
- [docker/php/custom.ini](/c:/Users/yazon/source/repos/AlKhairPhp/docker/php/custom.ini)
- [docker/entrypoint.sh](/c:/Users/yazon/source/repos/AlKhairPhp/docker/entrypoint.sh)
- [docker/scheduler.sh](/c:/Users/yazon/source/repos/AlKhairPhp/docker/scheduler.sh)

## First Run On The Windows Machine

1. Install Docker Desktop.
2. Copy `.env.docker.example` to `.env.docker`.
3. Edit `.env.docker` and set:
   - `APP_URL`
   - `APP_PORT`
   - `DB_DATABASE`
   - `DB_USERNAME`
   - `DB_PASSWORD`
   - `DB_ROOT_PASSWORD`
4. Build the image:

```powershell
docker compose --env-file .env.docker build
```

5. Generate the Laravel app key:

```powershell
docker compose --env-file .env.docker run --rm --no-deps web php artisan key:generate --show
```

6. Paste the returned key into `.env.docker` as `APP_KEY=...`.
7. Start the stack:

```powershell
docker compose --env-file .env.docker up -d
```

8. Run database migrations:

```powershell
docker compose --env-file .env.docker exec web php artisan migrate --force
```

9. If this is a fresh environment and you want seed data:

```powershell
docker compose --env-file .env.docker exec web php artisan db:seed --force
```

10. Optimize Laravel:

```powershell
docker compose --env-file .env.docker exec web php artisan optimize
```

11. Open the app in the browser:

```text
http://localhost:8080
```

If you changed `APP_PORT`, use that port instead.

## Updating The App Later

After new code is pulled on the server:

```powershell
docker compose --env-file .env.docker build
docker compose --env-file .env.docker up -d
docker compose --env-file .env.docker exec web php artisan migrate --force
docker compose --env-file .env.docker exec web php artisan optimize:clear
docker compose --env-file .env.docker exec web php artisan optimize
```

## Useful Commands

Show logs:

```powershell
docker compose --env-file .env.docker logs -f web
docker compose --env-file .env.docker logs -f queue
docker compose --env-file .env.docker logs -f scheduler
docker compose --env-file .env.docker logs -f db
```

Open a shell inside the app container:

```powershell
docker compose --env-file .env.docker exec web sh
```

Restart only the queue worker:

```powershell
docker compose --env-file .env.docker restart queue
```

## Notes

- The database is stored in the `db_data` Docker volume.
- Laravel `storage` is stored in the `app_storage` Docker volume.
- `bootstrap/cache` is stored in the `app_cache` Docker volume.
- This setup does not expose MySQL to the outside by default.
- This setup uses Apache, not nginx and not IIS.

## Before Real Production Use

Check these items on the target machine:

- HTTPS will be terminated by your reverse proxy or firewall edge if needed.
- `.env.docker` contains `APP_ENV=production` and `APP_DEBUG=false`.
- You have a real backup plan for both the database volume and uploaded files.
- You know how to restore `db_data` and `app_storage` before going live.
