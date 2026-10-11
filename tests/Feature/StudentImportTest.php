<?php

namespace Tests\Feature;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
use App\Models\ParentProfile;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGender;
use App\Models\User;
use App\Services\Landlord\TenantContext;
use App\Services\StudentRosterImportService;
use App\Services\XlsxExportService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        $this->seed(RoleSeeder::class);
        $this->plan = Plan::create(['code' => 'modular', 'name' => 'Modular', 'is_active' => true]);
        $tenant = Tenant::create(['uuid' => (string) Str::uuid(), 'slug' => 'import-test', 'name' => 'Import Test', 'status' => 'active']);
        $tenant->subscription()->create(['plan_id' => $this->plan->id, 'status' => 'active']);
        app(TenantContext::class)->set($tenant);
        $this->modules(['students']);
        $this->actingAs($this->admin());
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        app(TenantContext::class)->clear();
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_students_only_import_creates_student_accounts_and_credentials(): void
    {
        $file = $this->csv([
            ['first_name', 'last_name', 'student_phone', 'birth_date', 'status'],
            ['Ahmad', 'Saleh', '0999000000', '2014-01-15', 'active'],
            ['Mona', 'Saleh', '', '2015-02-20', 'inactive'],
        ]);

        $credentials = app(StudentRosterImportService::class)->import($file, 'csv', false);

        $this->assertCount(2, $credentials);
        $this->assertDatabaseCount('students', 2);
        $this->assertDatabaseCount('parents', 0);
        $this->assertDatabaseCount('users', 3);
        $student = Student::query()->where('first_name', 'Ahmad')->with('user')->firstOrFail();
        $this->assertTrue($student->user->hasRole('student'));
        $this->assertSame($student->student_number, $student->user->username);
        $this->assertSame(StudentGender::query()->where('is_default', true)->value('code'), $student->gender);
        $this->assertTrue(Hash::check($credentials[0]['password'], $student->user->password));
        $this->assertSame($credentials[0]['password'], $student->user->issued_password);
    }

    public function test_parent_phone_creates_one_parent_account_for_siblings(): void
    {
        $this->modules(['parents']);
        $file = $this->csv([
            ['first_name', 'last_name', 'birth_date', 'parent_phone', 'father_name', 'mother_name'],
            ['Ahmad', 'Khaled', '2014-01-15', '0999111111', 'Khaled Saleh', ''],
            ['Mona', 'Khaled', '2015-02-20', '+963999111111', '', 'Mariam Saleh'],
        ]);

        $preview = app(StudentRosterImportService::class)->preview($file, 'csv', true);
        $this->assertSame(0, $preview['error_count']);
        $credentials = app(StudentRosterImportService::class)->import($file, 'csv', true);

        $this->assertDatabaseCount('parents', 1);
        $this->assertDatabaseCount('students', 2);
        $parent = ParentProfile::query()->with(['user', 'students'])->firstOrFail();
        $this->assertCount(2, $parent->students);
        $this->assertSame('+963999111111', $parent->getRawOriginal('father_phone'));
        $this->assertSame('Mariam Saleh', $parent->mother_name);
        $this->assertTrue($parent->user->hasRole('parent'));
        $this->assertCount(3, $credentials);
        $this->assertCount(1, collect($credentials)->where('role', 'parent'));
        $this->assertCount(2, collect($credentials)->where('role', 'student'));
    }

    public function test_parent_data_is_rejected_when_parents_module_is_disabled(): void
    {
        $file = $this->csv([
            ['first_name', 'last_name', 'birth_date', 'parent_phone', 'father_name'],
            ['Ahmad', 'Khaled', '2014-01-15', '0999111111', 'Khaled Saleh'],
        ]);

        $preview = app(StudentRosterImportService::class)->preview($file, 'csv', false);

        $this->assertSame(1, $preview['error_count']);
        try {
            app(StudentRosterImportService::class)->import($file, 'csv', false);
            $this->fail('Expected parent data to be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('students', 0);
        }
    }

    public function test_duplicate_row_prevents_the_entire_import(): void
    {
        Student::query()->create(['first_name' => 'Existing', 'last_name' => 'Student', 'birth_date' => '2014-01-15', 'status' => 'active']);
        $file = $this->csv([
            ['first_name', 'last_name', 'birth_date'],
            ['New', 'Student', '2015-01-15'],
            ['Existing', 'Student', '2014-01-15'],
        ]);

        try {
            app(StudentRosterImportService::class)->import($file, 'csv', false);
            $this->fail('Expected the duplicate row to block the import.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('students', 1);
        }
    }

    public function test_schools_are_normalized_reused_and_created_after_preview_warning(): void
    {
        School::query()->create(['name' => 'Al Amal School', 'is_active' => true]);
        $file = $this->csv([
            ['first_name', 'last_name', 'birth_date', 'school_name'],
            ['Ahmad', 'Saleh', '2014-01-15', '  al amal school  '],
            ['Mona', 'Saleh', '2015-02-20', 'New Future School'],
            ['Omar', 'Saleh', '2016-03-21', ' new   future school '],
        ]);

        $preview = app(StudentRosterImportService::class)->preview($file, 'csv', false);

        $this->assertSame(1, $preview['warning_count']);
        $this->assertSame('Al Amal School', $preview['rows'][0]['school']);
        $this->assertSame('New Future School', $preview['rows'][1]['school']);
        app(StudentRosterImportService::class)->import($file, 'csv', false);

        $this->assertDatabaseCount('schools', 2);
        $this->assertSame(2, Student::query()->where('school_name', 'New Future School')->count());
    }

    public function test_existing_parent_is_matched_by_normalized_phone_without_resetting_credentials(): void
    {
        $this->modules(['parents']);
        $parentUser = User::factory()->create([
            'username' => 'existing-parent',
            'password' => 'ExistingPass123!',
            'issued_password' => 'ExistingPass123!',
        ]);
        $parentUser->assignRole('parent');
        $parent = ParentProfile::query()->create([
            'user_id' => $parentUser->id,
            'father_name' => 'Existing Parent',
            'father_phone' => '+963999333333',
            'is_active' => true,
        ]);
        $file = $this->csv([
            ['first_name', 'last_name', 'birth_date', 'parent_phone', 'father_name'],
            ['Ahmad', 'Existing', '2014-01-15', '0999333333', 'Existing Parent'],
        ]);

        $credentials = app(StudentRosterImportService::class)->import($file, 'csv', true);

        $this->assertDatabaseCount('parents', 1);
        $this->assertSame($parent->id, Student::query()->firstOrFail()->parent_id);
        $this->assertCount(1, $credentials);
        $this->assertSame('student', $credentials[0]['role']);
        $this->assertSame('ExistingPass123!', $parentUser->fresh()->issued_password);
    }

    public function test_generated_xlsx_template_can_be_read_for_preview(): void
    {
        $download = app(XlsxExportService::class)->download(
            'student-import-template',
            ['first_name', 'last_name', 'birth_date'],
            [['Ahmad', 'Saleh', '2014-01-15']],
        );
        $file = $this->temporaryPath();
        ob_start();
        $download->sendContent();
        file_put_contents($file, (string) ob_get_clean());

        $preview = app(StudentRosterImportService::class)->preview($file, 'xlsx', false);

        $this->assertSame(1, $preview['valid_count']);
        $this->assertSame(0, $preview['error_count']);
    }

    public function test_import_route_requires_the_students_module(): void
    {
        $this->get('/students/import')->assertOk();
        $this->modules([]);
        $this->get('/students/import')->assertForbidden();
    }

    public function test_tenant_admin_can_preview_import_and_receive_credentials(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'students.csv',
            "first_name,last_name,birth_date\nAhmad,Saleh,2014-01-15\n",
        );

        Volt::test('students.import')
            ->set('importFile', $file)
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSet('validCount', 1)
            ->assertSet('errorCount', 0)
            ->call('import')
            ->assertHasNoErrors()
            ->assertSet('credentials', fn (array $credentials) => count($credentials) === 1 && filled($credentials[0]['password']));

        $this->assertDatabaseHas('students', ['first_name' => 'Ahmad', 'last_name' => 'Saleh']);
    }

    private function modules(array $codes): void
    {
        $ids = [];
        foreach ($codes as $code) {
            $ids[] = Feature::firstOrCreate(['code' => $code], ['name' => $code, 'is_active' => true])->id;
        }
        $this->plan->features()->sync($ids);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function csv(array $rows): string
    {
        $path = $this->temporaryPath();
        $handle = fopen($path, 'wb');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);

        return $path;
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'student-import-test-');
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
