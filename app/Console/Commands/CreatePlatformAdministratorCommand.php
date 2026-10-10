<?php

namespace App\Console\Commands;

use App\Models\Landlord\PlatformAdministrator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreatePlatformAdministratorCommand extends Command
{
    protected $signature = 'saas:platform-admin
        {email : Platform administrator email address}
        {--name= : Display name}
        {--password= : Password; omit to enter it securely at a prompt}';

    protected $description = 'Create or reactivate a Platform Admin in the landlord database.';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: $this->ask('Platform administrator name')));
        $password = (string) ($this->option('password') ?: $this->secret('Platform administrator password'));

        if ($name === '' || $password === '') {
            $this->components->error('A name and password are required.');

            return self::FAILURE;
        }

        $administrator = PlatformAdministrator::query()->firstOrNew(['email' => $email]);
        $administrator->fill([
            'uuid' => $administrator->uuid ?: (string) Str::uuid(),
            'name' => $name,
            'password' => $password,
            'is_active' => true,
        ])->save();

        $this->components->info("Platform administrator {$email} is ready.");

        return self::SUCCESS;
    }
}
