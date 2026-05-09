<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use function random_int;

class ManagedUserService
{
    public function syncLinkedUser(?User $user, array $attributes, string $role): array
    {
        $name = trim((string) ($attributes['name'] ?? $user?->name ?? 'User'));
        $phone = filled($attributes['phone'] ?? null) ? trim((string) $attributes['phone']) : $user?->phone;
        $username = filled($attributes['username'] ?? null)
            ? $this->uniqueUsername((string) $attributes['username'], $name, $user?->id)
            : ($user?->username ?: $this->uniqueUsername('', $name, $user?->id));
        $email = filled($attributes['email'] ?? null)
            ? $this->uniqueEmail(trim((string) $attributes['email']), $username, $user?->id)
            : ($user?->email ?: $this->uniqueEmail(null, $username, $user?->id));

        $plainPassword = filled($attributes['password'] ?? null)
            ? (string) $attributes['password']
            : ($user ? null : $this->generatePassword());

        $payload = [
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'phone' => $phone,
            'is_active' => (bool) ($attributes['is_active'] ?? true),
            'email_verified_at' => $user?->email_verified_at ?? now(),
        ];

        if ($plainPassword !== null) {
            $payload['password'] = Hash::make($plainPassword);
            $payload['issued_password'] = $plainPassword;
        }

        $user ??= new User();
        $user->fill($payload);
        $user->save();
        $user->assignRole($role);

        return [
            'user' => $user,
            'credentials' => [
                'login' => $user->username ?: ($user->email ?: $user->phone),
                'email' => $user->email,
                'password' => $plainPassword,
                'role' => $role,
            ],
        ];
    }

    public function generatePassword(int $length = 8): string
    {
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= (string) random_int(0, 9);
        }

        return $password;
    }

    public function uniqueUsername(string $preferred, string $fallbackName, ?int $ignoreUserId = null): string
    {
        $base = Str::of($preferred)
            ->trim()
            ->replaceMatches('/[^a-z0-9._-]+/i', '-')
            ->trim('-_.')
            ->value();

        if ($base === '') {
            $base = Str::slug($fallbackName, '.');
        }

        if ($base === '') {
            $base = 'user';
        }

        $candidate = $base;
        $counter = 2;

        while ($this->usernameTaken($candidate, $ignoreUserId)) {
            $candidate = $base.$counter;
            $counter++;
        }

        return $candidate;
    }

    public function uniqueEmail(?string $preferred, string $username, ?int $ignoreUserId = null): string
    {
        $domain = $this->emailDomain();
        $base = filled($preferred) ? Str::lower(trim((string) $preferred)) : Str::lower($username).'@'.$domain;
        $candidate = $base;
        $counter = 2;

        while ($this->emailTaken($candidate, $ignoreUserId)) {
            [$local, $domain] = array_pad(explode('@', $base, 2), 2, $domain);
            $candidate = $local.'+'.$counter.'@'.$domain;
            $counter++;
        }

        return $candidate;
    }

    protected function emailDomain(): string
    {
        $domain = (string) (AppSetting::groupValues('general')->get('email_domain') ?: 'alkhair.local');
        $domain = (string) Str::of($domain)->lower()->trim()->replaceStart('@', '');

        return $domain !== '' ? $domain : 'alkhair.local';
    }

    protected function usernameTaken(string $username, ?int $ignoreUserId): bool
    {
        return User::query()
            ->when($ignoreUserId, fn ($query) => $query->whereKeyNot($ignoreUserId))
            ->where('username', $username)
            ->exists();
    }

    protected function emailTaken(string $email, ?int $ignoreUserId): bool
    {
        return User::query()
            ->when($ignoreUserId, fn ($query) => $query->whereKeyNot($ignoreUserId))
            ->where('email', $email)
            ->exists();
    }
}
