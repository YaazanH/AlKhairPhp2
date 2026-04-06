<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\GradeLevel;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_pages_require_the_settings_permission(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create([
            'name' => 'Settings Manager',
            'phone' => '0777000001',
            'username' => 'settings-manager',
        ]);
        $manager->assignRole('manager');

        $teacher = User::factory()->create([
            'name' => 'Settings Teacher',
            'phone' => '0777000002',
            'username' => 'settings-teacher',
        ]);
        $teacher->assignRole('teacher');

        $this->get(route('settings.organization'))->assertRedirect(route('login'));

        $this->actingAs($manager);
        $this->get(route('settings.organization'))->assertOk();
        $this->get(route('settings.tracking'))->assertOk();
        $this->get(route('settings.points'))->assertOk();
        $this->get(route('settings.finance'))->assertOk();

        auth()->logout();

        $this->actingAs($teacher);
        $this->get(route('settings.organization'))->assertForbidden();
        $this->get(route('settings.tracking'))->assertForbidden();
        $this->get(route('settings.points'))->assertForbidden();
        $this->get(route('settings.finance'))->assertForbidden();
    }

    public function test_manager_can_manage_organization_settings(): void
    {
        $this->signIn();

        Volt::test('settings.organization')
            ->set('school_name', 'Alkhair Center')
            ->set('school_phone', '0944555000')
            ->set('school_email', 'info@alkhair.test')
            ->set('school_address', 'Damascus')
            ->set('school_timezone', 'Asia/Damascus')
            ->set('school_currency', 'SYP')
            ->call('saveOrganizationSettings')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_settings', [
            'group' => 'general',
            'key' => 'school_name',
            'value' => 'Alkhair Center',
        ]);

        Volt::test('settings.organization')
            ->set('academic_year_name', '2026/2027')
            ->set('academic_year_starts_on', '2026-08-01')
            ->set('academic_year_ends_on', '2027-07-31')
            ->set('academic_year_is_current', true)
            ->call('saveAcademicYear')
            ->assertHasNoErrors();

        $academicYear = AcademicYear::query()->firstOrFail();

        Volt::test('settings.organization')
            ->call('editAcademicYear', $academicYear->id)
            ->set('academic_year_is_active', false)
            ->call('saveAcademicYear')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('academic_years', [
            'id' => $academicYear->id,
            'is_active' => false,
            'is_current' => true,
        ]);

        Volt::test('settings.organization')
            ->set('grade_level_name', 'Grade 5')
            ->set('grade_level_sort_order', '5')
            ->call('saveGradeLevel')
            ->assertHasNoErrors();

        $gradeLevel = GradeLevel::query()->firstOrFail();

        Volt::test('settings.organization')
            ->call('editGradeLevel', $gradeLevel->id)
            ->set('grade_level_sort_order', '6')
            ->call('saveGradeLevel')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('grade_levels', [
            'id' => $gradeLevel->id,
            'sort_order' => 6,
        ]);

        Volt::test('settings.organization')
            ->call('deleteAcademicYear', $academicYear->id);

        Volt::test('settings.organization')
            ->call('deleteGradeLevel', $gradeLevel->id);

        $this->assertDatabaseMissing('academic_years', ['id' => $academicYear->id]);
        $this->assertDatabaseMissing('grade_levels', ['id' => $gradeLevel->id]);
    }

    public function test_manager_can_manage_tracking_point_and_finance_settings(): void
    {
        $this->signIn();

        $gradeLevel = GradeLevel::query()->create([
            'is_active' => true,
            'name' => 'Grade 4',
            'sort_order' => 4,
        ]);

        Volt::test('settings.tracking')
            ->set('attendance_status_name', 'Late')
            ->set('attendance_status_code', 'late-api')
            ->set('attendance_status_scope', 'student')
            ->set('attendance_status_default_points', '-1')
            ->set('attendance_status_is_present', true)
            ->call('saveAttendanceStatus')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('attendance_statuses', [
            'code' => 'late-api',
            'default_points' => -1,
            'scope' => 'student',
        ]);

        Volt::test('settings.tracking')
            ->set('assessment_type_name', 'Oral Exam')
            ->set('assessment_type_code', 'oral-exam')
            ->set('assessment_type_is_scored', true)
            ->call('saveAssessmentType')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('assessment_types', [
            'code' => 'oral-exam',
            'name' => 'Oral Exam',
        ]);

        Volt::test('settings.tracking')
            ->set('quran_test_type_name', 'Stage Gate')
            ->set('quran_test_type_code', 'stage-gate')
            ->set('quran_test_type_sort_order', '4')
            ->call('saveQuranTestType')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quran_test_types', [
            'code' => 'stage-gate',
            'sort_order' => 4,
        ]);

        Volt::test('settings.points')
            ->set('point_type_name', 'Behavior Bonus')
            ->set('point_type_code', 'behavior-bonus')
            ->set('point_type_category', 'behavior')
            ->set('point_type_default_points', '3')
            ->set('point_type_allow_manual_entry', true)
            ->set('point_type_allow_negative', false)
            ->call('savePointType')
            ->assertHasNoErrors();

        $pointType = \App\Models\PointType::query()->where('code', 'behavior-bonus')->firstOrFail();

        Volt::test('settings.points')
            ->set('point_policy_point_type_id', $pointType->id)
            ->set('point_policy_name', 'Behavior Grade 4')
            ->set('point_policy_source_type', 'behavior')
            ->set('point_policy_trigger_key', 'excellent')
            ->set('point_policy_grade_level_id', $gradeLevel->id)
            ->set('point_policy_from_value', '90')
            ->set('point_policy_to_value', '100')
            ->set('point_policy_points', '7')
            ->set('point_policy_priority', '10')
            ->call('savePointPolicy')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('point_policies', [
            'grade_level_id' => $gradeLevel->id,
            'name' => 'Behavior Grade 4',
            'point_type_id' => $pointType->id,
            'points' => 7,
            'source_type' => 'behavior',
            'trigger_key' => 'excellent',
        ]);

        Volt::test('settings.finance')
            ->set('invoice_prefix', 'ALK')
            ->call('saveFinanceSettings')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_settings', [
            'group' => 'finance',
            'key' => 'invoice_prefix',
            'value' => 'ALK',
        ]);

        Volt::test('settings.finance')
            ->set('payment_method_name', 'Bank Transfer')
            ->set('payment_method_code', 'bank-transfer')
            ->call('savePaymentMethod')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('payment_methods', [
            'code' => 'bank-transfer',
            'name' => 'Bank Transfer',
        ]);

        Volt::test('settings.finance')
            ->set('expense_category_name', 'Transport')
            ->set('expense_category_code', 'transport')
            ->call('saveExpenseCategory')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('expense_categories', [
            'code' => 'transport',
            'name' => 'Transport',
        ]);
    }

    private function signIn(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'name' => 'Manager User',
            'phone' => '0999999910',
            'username' => 'settings-manager-user',
        ]);

        $user->assignRole('manager');

        $this->actingAs($user);
    }
}
