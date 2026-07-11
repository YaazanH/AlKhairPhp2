# AlKhair Mobile API Postman Collection

Import both JSON files into Postman, select **AlKhair Mobile - Local**, set `login` and `password`, then run **01 Authentication / Login - Create Token**. The login request stores the Sanctum token automatically.

## Base URL

- Local browser or iOS simulator: `http://127.0.0.1:8000`
- Android emulator: `http://10.0.2.2:8000`
- Physical phone: `http://YOUR_COMPUTER_LAN_IP:8000`

For an emulator or phone, start Laravel on all interfaces:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

Allow TCP port 8000 through Windows Firewall when testing from a physical device. The phone and computer must be on the same network.

## Authentication and permissions

The API uses Laravel Sanctum bearer tokens. A user must be active and have API-enabled permissions. Each request description identifies its required permission. A parent account also needs an active linked parent profile.

## Variables

List endpoints help discover record IDs. Put those IDs into the environment variables before running detail or write requests. Disabled query parameters are examples; enable only the filters needed for a request.

All write requests use JSON and include realistic example bodies. Destructive requests are included but should be run carefully against non-production data.

