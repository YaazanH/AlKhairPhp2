<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthTokenController extends Controller
{
    /**
     * Issue a Sanctum token for an active API-enabled user.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_name' => ['required', 'string', 'max:255'],
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where(function ($query) use ($validated) {
                $query
                    ->where('username', $validated['login'])
                    ->orWhere('email', $validated['login'])
                    ->orWhere('phone', $validated['login']);
            })
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        abort_unless($user->is_active, 403, 'This account is inactive.');

        $abilities = $this->resolveApiAbilities($user);

        abort_if(empty($abilities), 403, 'This account does not have API access.');

        $token = $user->createToken($validated['device_name'], $abilities);

        return response()->json([
            'abilities' => $abilities,
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'permissions' => $abilities,
                'roles' => $user->getRoleNames()->values()->all(),
                'username' => $user->username,
            ],
        ], 201);
    }

    /**
     * Revoke the current access token.
     */
    public function destroy(Request $request)
    {
        $token = $request->user()?->currentAccessToken();

        abort_unless($token, 400, 'No current access token could be resolved.');

        $token->delete();

        return response()->noContent();
    }

    protected function resolveApiAbilities(User $user): array
    {
        $apiPermissions = [
            'activities.view',
            'assessments.view',
            'enrollments.create',
            'enrollments.delete',
            'enrollments.update',
            'enrollments.view',
            'groups.create',
            'groups.delete',
            'groups.update',
            'groups.view',
            'invoices.view',
            'reports.view',
            'students.create',
            'students.delete',
            'students.update',
            'students.view',
        ];

        return $user->getAllPermissions()
            ->pluck('name')
            ->filter(fn (string $permission) => in_array($permission, $apiPermissions, true))
            ->values()
            ->all();
    }
}
