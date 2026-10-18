<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\UpdateProfilePhotoRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Services\ManagedUserService;
use App\Support\MobileUserData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => MobileUserData::from($request->user())]);
    }

    public function update(UpdateProfileRequest $request, ManagedUserService $managedUsers): JsonResponse
    {
        $user = $request->user()->loadMissing(['studentProfile', 'teacherProfile']);
        $validated = $request->validated();

        if (array_key_exists('username', $validated)) {
            if ($user->hasImmutableUsername() && $validated['username'] !== $user->username) {
                throw ValidationException::withMessages([
                    'username' => __('access.profile_accounts.username_locked'),
                ]);
            }

            if (! $user->hasImmutableUsername()) {
                $username = $managedUsers->uniqueUsername($validated['username'], $user->name, $user->id);

                $user->forceFill([
                    'username' => $username,
                    'email' => $managedUsers->uniqueEmail(null, $username, $user->id),
                ])->save();
            }
        }

        return response()->json(['data' => MobileUserData::from($user->fresh())]);
    }

    public function updatePhoto(UpdateProfilePhotoRequest $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['studentProfile', 'teacherProfile']);
        $user->storeProfilePhotoUpload($request->file('profile_photo'));

        return response()->json(['data' => MobileUserData::from($user->fresh())]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $revokedTokens = DB::transaction(function () use ($request, $user): int {
            $user->completePasswordChange($request->validated('password'));

            $otherTokens = $user->tokens();
            $currentToken = $user->currentAccessToken();

            if ($currentToken && method_exists($currentToken, 'getKey')) {
                $otherTokens->where('id', '!=', $currentToken->getKey());
            }

            return $otherTokens->delete();
        });

        return response()->json([
            'data' => [
                'password_updated' => true,
                'other_tokens_revoked' => $revokedTokens,
            ],
        ]);
    }
}
