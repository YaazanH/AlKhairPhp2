<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use App\Support\ArabicUsernameTransliterator;
use App\Support\PhoneNumberFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

use function random_int;

class ManagedUserService
{
    public function syncLinkedUser(?User $user, array $attributes, string $role, bool $accountSettings = false): array
    {
        $shared = $user && $this->isSharedAccount($user, $role);
        if ($shared) {
            if (! $accountSettings) {
                return $this->existingUserResult($user, $role);
            }
            abort_unless(auth()->user()?->can('users.update'), 403);
        }

        $name = trim((string) ($attributes['name'] ?? $user?->name ?? 'User'));
        $phones = $attributes['phones'] ?? [$attributes['phone'] ?? null];

        if (! is_array($phones)) {
            $phones = [$phones];
        }

        // Parent/student login dialogs do not edit the shared staff identity.
        if ($shared && $role !== 'teacher') {
            $name = $user->name;
            $phones = [$user->phone];
        }

        $phone = $this->resolveUniquePhone($phones, $user?->id, $user?->phone);
        if ($user && filled($user->username) && $user->hasImmutableUsername()) {
            $username = $user->username;
        } else {
            $username = filled($attributes['username'] ?? null)
                ? $this->uniqueUsername((string) $attributes['username'], $name, $user?->id)
                : ($user?->username ?: $this->uniqueUsername('', $name, $user?->id));
        }
        $email = $shared && $username === $user->username && filled($user->email)
            ? $user->email
            : $this->uniqueEmail(null, $username, $user?->id);

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

        $user ??= new User;
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

    public function isSharedAccount(User $user, string $profile): bool
    {
        foreach (['teacher', 'parent', 'student'] as $type) {
            if ($type !== $profile && $user->{$type.'Profile'}()->withTrashed()->exists()) {
                return true;
            }
        }

        return $profile !== 'teacher' && $user->roles()->where('name', '!=', $profile)->exists();
    }

    public function exclusiveAccountsQuery(string $profile): Builder
    {
        $query = User::query();
        foreach (['teacher', 'parent', 'student'] as $type) {
            if ($type !== $profile) {
                $query->whereDoesntHave($type.'Profile', fn ($profileQuery) => $profileQuery->withTrashed());
            }
        }
        if ($profile !== 'teacher') {
            $query->whereDoesntHave('roles', fn ($roleQuery) => $roleQuery->where('name', '!=', $profile));
        }

        return $query;
    }

    public function removeProfileAccount(?User $user, string $profile): void
    {
        if (! $user) {
            return;
        }
        if ($this->isSharedAccount($user, $profile)) {
            $user->removeRole($profile);
        } else {
            $user->delete();
        }
    }

    public function reuseLinkedUser(User $user, string $role): array
    {
        abort_unless(auth()->user()?->can('users.update'), 403);

        return $this->existingUserResult($user, $role);
    }

    protected function existingUserResult(User $user, string $role): array
    {
        $user->assignRole($role);

        return ['user' => $user, 'credentials' => [
            'login' => $user->username ?: ($user->email ?: $user->phone),
            'email' => $user->email, 'password' => null, 'role' => $role,
        ]];
    }

    public function resolveUniquePhone(array $candidates, ?int $ignoreUserId = null, ?string $fallback = null): ?string
    {
        foreach ($candidates as $candidate) {
            $phone = $this->normalizePhone($candidate);

            if ($phone !== null && ! $this->phoneTaken($phone, $ignoreUserId)) {
                return $phone;
            }
        }

        $fallbackPhone = $this->normalizePhone($fallback);

        if ($fallbackPhone !== null && ! $this->phoneTaken($fallbackPhone, $ignoreUserId)) {
            return $fallbackPhone;
        }

        return null;
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
        $base = preg_match('/\p{Arabic}/u', $preferred)
            ? ArabicUsernameTransliterator::toUsername($preferred)
            : Str::of($preferred)
                ->trim()
                ->replaceMatches('/[^a-z0-9._-]+/i', '-')
                ->trim('-_.')
                ->value();

        if ($base === '') {
            $base = ArabicUsernameTransliterator::toUsername($fallbackName);
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

    protected function phoneTaken(string $phone, ?int $ignoreUserId): bool
    {
        return User::query()
            ->when($ignoreUserId, fn ($query) => $query->whereKeyNot($ignoreUserId))
            ->where('phone', $phone)
            ->exists();
    }

    protected function normalizePhone(mixed $value): ?string
    {
        return PhoneNumberFormatter::normalize($value);
    }
}
