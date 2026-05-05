<?php

namespace Tests\Feature;

use App\Models\CommunityContact;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CommunityContactPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_manage_community_contacts(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create([
            'username' => 'contacts-manager',
            'phone' => '79996001',
        ]);
        $manager->assignRole('manager');

        $this->actingAs($manager);

        $this->get(route('community-contacts.index'))->assertOk();

        Volt::test('community-contacts.index')
            ->call('openCreateModal')
            ->set('name', 'Ahmad Driver')
            ->set('category', 'Bus driver')
            ->set('phone', '0999000111')
            ->set('notes', 'Available for trips.')
            ->call('save')
            ->assertHasNoErrors();

        $contact = CommunityContact::query()->where('name', 'Ahmad Driver')->firstOrFail();

        Volt::test('community-contacts.index')
            ->call('edit', $contact->id)
            ->set('category', 'Transport')
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('community_contacts', [
            'id' => $contact->id,
            'category' => 'Transport',
            'is_active' => false,
        ]);

        Volt::test('community-contacts.index')
            ->call('delete', $contact->id)
            ->assertHasNoErrors();

        $this->assertSoftDeleted('community_contacts', [
            'id' => $contact->id,
        ]);
    }

    public function test_teacher_without_permission_cannot_view_community_contacts(): void
    {
        $this->seed(RoleSeeder::class);

        $teacher = User::factory()->create([
            'username' => 'contacts-teacher',
            'phone' => '79996002',
        ]);
        $teacher->assignRole('teacher');

        $this->actingAs($teacher)
            ->get(route('community-contacts.index'))
            ->assertForbidden();
    }
}
