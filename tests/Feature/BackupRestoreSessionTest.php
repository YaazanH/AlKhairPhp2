<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BackupRestoreSessionService;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackupRestoreSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_confirmation_is_the_same_lowercase_word_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $this->assertSame('restore', trans('backups.restore.confirmation_phrase', locale: $locale));
        }
    }

    public function test_the_current_login_survives_a_restored_database_without_its_session_row(): void
    {
        $actor = User::factory()->create();
        $session = $this->databaseSession();
        app()->instance('session.store', $session);
        Auth::forgetGuards();
        $guard = Auth::guard('web');
        $guard->login($actor);
        $session->put('locale', 'ar');
        $session->save();
        $previousId = $session->getId();

        // A restored backup predates this login and can contain an older password.
        DB::table('sessions')->where('id', $previousId)->delete();
        $restoredPassword = Hash::make('password-in-backup');
        DB::table('users')->where('id', $actor->id)->update(['password' => $restoredPassword]);

        $this->assertTrue(app(BackupRestoreSessionService::class)->resume($actor));
        $this->assertNotSame($previousId, $session->getId());
        $session->save();

        $nextSession = $this->databaseSession();
        $nextSession->setId($session->getId());
        $nextSession->start();
        $nextGuard = new SessionGuard('web', $guard->getProvider(), $nextSession);
        $this->assertSame($actor->id, $nextGuard->user()?->id);
        $this->assertSame($restoredPassword, $nextGuard->user()?->getAuthPassword());
        $this->assertSame($restoredPassword, $nextSession->get('password_hash_web'));
        $this->assertSame('ar', $nextSession->get('locale'));
    }

    public function test_restore_does_not_sign_in_as_an_unrelated_account_reusing_the_same_id(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        DB::table('users')->where('id', $actor->id)->update([
            'username' => 'different-account',
            'email' => 'different@example.test',
        ]);

        $this->assertFalse(app(BackupRestoreSessionService::class)->resume($actor));
        $this->assertGuest('web');
    }

    public function test_imported_backup_can_move_the_current_account_to_a_different_id(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        DB::table('users')->where('id', $actor->id)->update([
            'username' => 'unrelated-restored-account',
            'email' => 'unrelated@example.test',
        ]);
        $restoredActor = User::factory()->create([
            'username' => $actor->username,
            'email' => $actor->email,
        ]);

        $this->assertTrue(app(BackupRestoreSessionService::class)->resume($actor));
        $this->assertAuthenticatedAs($restoredActor, 'web');
    }

    private function databaseSession(): Store
    {
        return new Store('backup-restore-test', new DatabaseSessionHandler(
            DB::connection(), 'sessions', 120, app(),
        ));
    }
}
