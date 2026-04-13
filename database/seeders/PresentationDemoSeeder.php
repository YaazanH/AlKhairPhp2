<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\ActivityExpense;
use App\Models\ActivityPayment;
use App\Models\ActivityRegistration;
use App\Models\AppSetting;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ExpenseCategory;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\GroupSchedule;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranJuz;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentNote;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\TeacherAttendanceRecord;
use App\Models\User;
use App\Services\ActivityAudienceService;
use App\Services\AssessmentService;
use App\Services\FinanceService;
use App\Services\MemorizationService;
use App\Services\PointLedgerService;
use App\Services\StudentAttendanceDayService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class PresentationDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            MasterDataSeeder::class,
            QuranJuzSeeder::class,
            WebsiteSeeder::class,
            AdminUserSeeder::class,
        ]);

        $admin = User::query()->where('email', env('SEED_ADMIN_EMAIL', 'admin@alkhair.test'))->firstOrFail();
        auth()->setUser($admin);

        $year = AcademicYear::query()->where('is_current', true)->firstOrFail();
        $grades = GradeLevel::query()->pluck('id', 'name');
        $juzs = QuranJuz::query()->pluck('id', 'juz_number');
        $attendanceStatuses = AttendanceStatus::query()->pluck('id', 'code');
        $assessmentTypes = AssessmentType::query()->pluck('id', 'code');
        $paymentMethods = PaymentMethod::query()->pluck('id', 'code');
        $expenseCategories = ExpenseCategory::query()->pluck('id', 'code');
        PointType::query()->updateOrCreate(
            ['code' => 'participation-reward'],
            [
                'name' => 'Participation Reward',
                'category' => 'manual',
                'default_points' => 5,
                'allow_manual_entry' => true,
                'allow_negative' => false,
                'is_active' => true,
            ],
        );
        PointType::query()->updateOrCreate(
            ['code' => 'follow-up-reminder'],
            [
                'name' => 'Follow-up Reminder',
                'category' => 'manual',
                'default_points' => -2,
                'allow_manual_entry' => true,
                'allow_negative' => true,
                'is_active' => true,
            ],
        );

        $pointTypes = PointType::query()->pluck('id', 'code');
        $quranTestTypes = QuranTestType::query()->pluck('id', 'code');
        $websiteSettings = AppSetting::groupValues('website');
        $galleryPaths = collect($websiteSettings->get('gallery_paths', []))->values();
        $heroImage = $websiteSettings->get('hero_image_path');

        $teacherUser = $this->upsertUser('teacher.demo@alkhair.test', 'ustadh.ahmad', '0998111111', 'Ahmad Al Hadi', 'teacher');
        $parentUser = $this->upsertUser('parent.demo@alkhair.test', 'hamdan.family', '0998111112', 'Hamdan Family', 'parent');
        $studentUser = $this->upsertUser('student.demo@alkhair.test', 'hasan.hamdan', '0998111113', 'Hasan Hamdan', 'student');
        $this->upsertUser('manager.demo@alkhair.test', 'manager.demo', '0998111110', 'Operations Manager', 'manager');

        $teachers = [
            'Ahmad Al Hadi' => Teacher::query()->updateOrCreate(
                ['phone' => '0998222001'],
                [
                    'user_id' => $teacherUser->id,
                    'first_name' => 'Ahmad',
                    'last_name' => 'Al Hadi',
                    'job_title' => 'Lead Quran Teacher',
                    'status' => 'active',
                    'hired_at' => '2024-08-15',
                    'notes' => 'Demo presentation teacher profile.',
                ],
            ),
            'Fatimah Noor' => Teacher::query()->updateOrCreate(
                ['phone' => '0998222002'],
                [
                    'first_name' => 'Fatimah',
                    'last_name' => 'Noor',
                    'job_title' => 'Tajweed Teacher',
                    'status' => 'active',
                    'hired_at' => '2024-09-01',
                    'notes' => 'Supports girls memorization and tajweed circles.',
                ],
            ),
            'Yusuf Kareem' => Teacher::query()->updateOrCreate(
                ['phone' => '0998222003'],
                [
                    'first_name' => 'Yusuf',
                    'last_name' => 'Kareem',
                    'job_title' => 'Youth Mentor',
                    'status' => 'active',
                    'hired_at' => '2025-01-10',
                    'notes' => 'Handles youth revision and engagement programs.',
                ],
            ),
        ];

        $courses = [
            'Hifz Foundations' => Course::query()->updateOrCreate(
                ['name' => 'Hifz Foundations'],
                ['description' => 'Core memorization track for younger students.', 'is_active' => true],
            ),
            'Tajweed Essentials' => Course::query()->updateOrCreate(
                ['name' => 'Tajweed Essentials'],
                ['description' => 'Pronunciation and recitation improvement track.', 'is_active' => true],
            ),
            'Teen Revision Circle' => Course::query()->updateOrCreate(
                ['name' => 'Teen Revision Circle'],
                ['description' => 'Revision, discipline, and exam preparation for older students.', 'is_active' => true],
            ),
        ];

        $groups = [
            'Boys Hifz Circle' => Group::query()->updateOrCreate(
                ['name' => 'Boys Hifz Circle'],
                [
                    'course_id' => $courses['Hifz Foundations']->id,
                    'academic_year_id' => $year->id,
                    'teacher_id' => $teachers['Ahmad Al Hadi']->id,
                    'assistant_teacher_id' => $teachers['Yusuf Kareem']->id,
                    'grade_level_id' => $grades['Grade 4'] ?? null,
                    'capacity' => 18,
                    'starts_on' => $year->starts_on,
                    'ends_on' => $year->ends_on,
                    'monthly_fee' => 18,
                    'is_active' => true,
                ],
            ),
            'Girls Tajweed Circle' => Group::query()->updateOrCreate(
                ['name' => 'Girls Tajweed Circle'],
                [
                    'course_id' => $courses['Tajweed Essentials']->id,
                    'academic_year_id' => $year->id,
                    'teacher_id' => $teachers['Fatimah Noor']->id,
                    'assistant_teacher_id' => null,
                    'grade_level_id' => $grades['Grade 5'] ?? null,
                    'capacity' => 16,
                    'starts_on' => $year->starts_on,
                    'ends_on' => $year->ends_on,
                    'monthly_fee' => 16,
                    'is_active' => true,
                ],
            ),
            'Teen Review Circle' => Group::query()->updateOrCreate(
                ['name' => 'Teen Review Circle'],
                [
                    'course_id' => $courses['Teen Revision Circle']->id,
                    'academic_year_id' => $year->id,
                    'teacher_id' => $teachers['Yusuf Kareem']->id,
                    'assistant_teacher_id' => $teachers['Ahmad Al Hadi']->id,
                    'grade_level_id' => $grades['Grade 8'] ?? null,
                    'capacity' => 14,
                    'starts_on' => $year->starts_on,
                    'ends_on' => $year->ends_on,
                    'monthly_fee' => 22,
                    'is_active' => true,
                ],
            ),
        ];

        $this->seedSchedules($groups);

        $parents = [
            'Hamdan Family' => ParentProfile::query()->updateOrCreate(
                ['father_phone' => '0998333001'],
                [
                    'user_id' => $parentUser->id,
                    'father_name' => 'Samer Hamdan',
                    'father_work' => 'Engineer',
                    'mother_name' => 'Amina Hamdan',
                    'mother_phone' => '0998333002',
                    'home_phone' => '011-555-1000',
                    'address' => 'Al Mazzeh, Damascus',
                    'notes' => 'Highly engaged family for the demo environment.',
                    'is_active' => true,
                ],
            ),
            'Sakr Family' => ParentProfile::query()->updateOrCreate(
                ['father_phone' => '0998333003'],
                [
                    'father_name' => 'Maher Sakr',
                    'father_work' => 'Teacher',
                    'mother_name' => 'Rana Sakr',
                    'mother_phone' => '0998333004',
                    'home_phone' => '011-555-2000',
                    'address' => 'Kafr Sousa, Damascus',
                    'notes' => 'Two-student family with regular attendance.',
                    'is_active' => true,
                ],
            ),
            'Darwish Family' => ParentProfile::query()->updateOrCreate(
                ['father_phone' => '0998333005'],
                [
                    'father_name' => 'Bilal Darwish',
                    'father_work' => 'Accountant',
                    'mother_name' => 'Hala Darwish',
                    'mother_phone' => '0998333006',
                    'home_phone' => '011-555-3000',
                    'address' => 'Qudsaya, Damascus',
                    'notes' => 'Family participates in activities and events.',
                    'is_active' => true,
                ],
            ),
            'Qudsi Family' => ParentProfile::query()->updateOrCreate(
                ['father_phone' => '0998333007'],
                [
                    'father_name' => 'Omar Qudsi',
                    'father_work' => 'Merchant',
                    'mother_name' => 'Nour Qudsi',
                    'mother_phone' => '0998333008',
                    'home_phone' => '011-555-4000',
                    'address' => 'Baramkeh, Damascus',
                    'notes' => 'Older students with strong revision scores.',
                    'is_active' => true,
                ],
            ),
        ];

        $students = [
            'Hasan Hamdan' => $this->upsertStudent(['first_name' => 'Hasan', 'last_name' => 'Hamdan', 'parent_id' => $parents['Hamdan Family']->id], ['user_id' => $studentUser->id, 'birth_date' => '2014-03-18', 'gender' => 'male', 'school_name' => 'Al Amal School', 'grade_level_id' => $grades['Grade 4'] ?? null, 'quran_current_juz_id' => $juzs[2] ?? null, 'photo_path' => $galleryPaths->get(0) ?: $heroImage, 'status' => 'active', 'joined_at' => '2025-09-01', 'notes' => 'Primary student for parent and student scoped demo logins.']),
            'Huda Hamdan' => $this->upsertStudent(['first_name' => 'Huda', 'last_name' => 'Hamdan', 'parent_id' => $parents['Hamdan Family']->id], ['birth_date' => '2012-10-11', 'gender' => 'female', 'school_name' => 'Al Amal School', 'grade_level_id' => $grades['Grade 6'] ?? null, 'quran_current_juz_id' => $juzs[3] ?? null, 'photo_path' => $galleryPaths->get(1) ?: $heroImage, 'status' => 'active', 'joined_at' => '2025-09-01', 'notes' => 'Sibling enrolled in girls tajweed circle.']),
            'Mariam Sakr' => $this->upsertStudent(['first_name' => 'Mariam', 'last_name' => 'Sakr', 'parent_id' => $parents['Sakr Family']->id], ['birth_date' => '2015-01-08', 'gender' => 'female', 'school_name' => 'Future Scholars', 'grade_level_id' => $grades['Grade 3'] ?? null, 'quran_current_juz_id' => $juzs[1] ?? null, 'photo_path' => $galleryPaths->get(2) ?: $heroImage, 'status' => 'active', 'joined_at' => '2025-09-03', 'notes' => 'Consistent attendance and steady memorization.']),
            'Ilyas Sakr' => $this->upsertStudent(['first_name' => 'Ilyas', 'last_name' => 'Sakr', 'parent_id' => $parents['Sakr Family']->id], ['birth_date' => '2013-05-22', 'gender' => 'male', 'school_name' => 'Future Scholars', 'grade_level_id' => $grades['Grade 5'] ?? null, 'quran_current_juz_id' => $juzs[2] ?? null, 'photo_path' => $galleryPaths->get(0) ?: $heroImage, 'status' => 'active', 'joined_at' => '2025-09-03', 'notes' => 'Shows improvement in quizzes and attendance.']),
            'Ahmad Darwish' => $this->upsertStudent(['first_name' => 'Ahmad', 'last_name' => 'Darwish', 'parent_id' => $parents['Darwish Family']->id], ['birth_date' => '2011-08-15', 'gender' => 'male', 'school_name' => 'Al Riyadah School', 'grade_level_id' => $grades['Grade 8'] ?? null, 'quran_current_juz_id' => $juzs[4] ?? null, 'photo_path' => $galleryPaths->get(1) ?: $heroImage, 'status' => 'active', 'joined_at' => '2025-09-05', 'notes' => 'Teen revision student with strong exam results.']),
            'Aya Darwish' => $this->upsertStudent(['first_name' => 'Aya', 'last_name' => 'Darwish', 'parent_id' => $parents['Darwish Family']->id], ['birth_date' => '2014-12-02', 'gender' => 'female', 'school_name' => 'Al Riyadah School', 'grade_level_id' => $grades['Grade 5'] ?? null, 'quran_current_juz_id' => $juzs[2] ?? null, 'photo_path' => $galleryPaths->get(2) ?: $heroImage, 'status' => 'active', 'joined_at' => '2025-09-05', 'notes' => 'Participates in mosque activities and tajweed class.']),
            'Leen Qudsi' => $this->upsertStudent(['first_name' => 'Leen', 'last_name' => 'Qudsi', 'parent_id' => $parents['Qudsi Family']->id], ['birth_date' => '2010-07-03', 'gender' => 'female', 'school_name' => 'Damascus International School', 'grade_level_id' => $grades['Grade 9'] ?? null, 'quran_current_juz_id' => $juzs[5] ?? null, 'photo_path' => $galleryPaths->get(0) ?: $heroImage, 'status' => 'active', 'joined_at' => '2025-09-10', 'notes' => 'Advanced revision student.']),
            'Omar Qudsi' => $this->upsertStudent(['first_name' => 'Omar', 'last_name' => 'Qudsi', 'parent_id' => $parents['Qudsi Family']->id], ['birth_date' => '2013-11-19', 'gender' => 'male', 'school_name' => 'Damascus International School', 'grade_level_id' => $grades['Grade 6'] ?? null, 'quran_current_juz_id' => $juzs[3] ?? null, 'photo_path' => $galleryPaths->get(1) ?: $heroImage, 'status' => 'active', 'joined_at' => '2025-09-10', 'notes' => 'Enjoys events and attendance rewards.']),
        ];

        $enrollments = [
            'Hasan Hamdan' => $this->upsertEnrollment($students['Hasan Hamdan'], $groups['Boys Hifz Circle'], '2025-09-01'),
            'Huda Hamdan' => $this->upsertEnrollment($students['Huda Hamdan'], $groups['Girls Tajweed Circle'], '2025-09-01'),
            'Mariam Sakr' => $this->upsertEnrollment($students['Mariam Sakr'], $groups['Girls Tajweed Circle'], '2025-09-03'),
            'Ilyas Sakr' => $this->upsertEnrollment($students['Ilyas Sakr'], $groups['Boys Hifz Circle'], '2025-09-03'),
            'Ahmad Darwish' => $this->upsertEnrollment($students['Ahmad Darwish'], $groups['Teen Review Circle'], '2025-09-05'),
            'Aya Darwish' => $this->upsertEnrollment($students['Aya Darwish'], $groups['Girls Tajweed Circle'], '2025-09-05'),
            'Leen Qudsi' => $this->upsertEnrollment($students['Leen Qudsi'], $groups['Teen Review Circle'], '2025-09-10'),
            'Omar Qudsi' => $this->upsertEnrollment($students['Omar Qudsi'], $groups['Boys Hifz Circle'], '2025-09-10'),
        ];

        $this->seedTeacherAttendance($teachers, $attendanceStatuses, $admin);
        $this->seedStudentAttendance($groups, $enrollments, $attendanceStatuses, $admin);
        $this->seedMemorization($enrollments);
        $this->seedQuranTests($enrollments, $teachers, $quranTestTypes, $juzs);
        $this->seedAssessments($groups, $assessmentTypes, $teachers, $enrollments, $admin);
        $this->seedNotes($students, $enrollments, $admin);
        $this->seedManualPoints($enrollments, $pointTypes, app(PointLedgerService::class), $admin);
        $this->seedActivities($groups, $enrollments, $paymentMethods, $expenseCategories, $admin);
        $this->seedInvoices($parents, $enrollments, $paymentMethods, $admin);
    }

    protected function seedSchedules(array $groups): void
    {
        $definitions = [
            'Boys Hifz Circle' => [
                ['day' => 1, 'starts' => '16:00', 'ends' => '17:30', 'room' => 'Room A'],
                ['day' => 3, 'starts' => '16:00', 'ends' => '17:30', 'room' => 'Room A'],
            ],
            'Girls Tajweed Circle' => [
                ['day' => 2, 'starts' => '16:30', 'ends' => '18:00', 'room' => 'Room B'],
                ['day' => 4, 'starts' => '16:30', 'ends' => '18:00', 'room' => 'Room B'],
            ],
            'Teen Review Circle' => [
                ['day' => 6, 'starts' => '10:00', 'ends' => '12:00', 'room' => 'Hall 2'],
            ],
        ];

        foreach ($definitions as $groupName => $rows) {
            foreach ($rows as $row) {
                GroupSchedule::query()->updateOrCreate(
                    [
                        'group_id' => $groups[$groupName]->id,
                        'day_of_week' => $row['day'],
                        'starts_at' => $row['starts'],
                    ],
                    [
                        'ends_at' => $row['ends'],
                        'room_name' => $row['room'],
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    protected function seedTeacherAttendance(array $teachers, Collection $attendanceStatuses, User $admin): void
    {
        $presentId = $attendanceStatuses->get('present');

        $day = TeacherAttendanceDay::query()->updateOrCreate(
            ['attendance_date' => now()->subDays(1)->toDateString()],
            [
                'status' => 'closed',
                'created_by' => $admin->id,
                'notes' => 'Presentation demo attendance day.',
            ],
        );

        foreach ($teachers as $teacher) {
            TeacherAttendanceRecord::query()->updateOrCreate(
                [
                    'teacher_attendance_day_id' => $day->id,
                    'teacher_id' => $teacher->id,
                ],
                [
                    'attendance_status_id' => $presentId,
                    'notes' => 'Ready for session.',
                ],
            );
        }
    }

    protected function seedStudentAttendance(array $groups, array $enrollments, Collection $attendanceStatuses, User $admin): void
    {
        $dayService = app(StudentAttendanceDayService::class);
        $ledger = app(PointLedgerService::class);
        $presentId = (int) $attendanceStatuses->get('present');
        $lateId = (int) $attendanceStatuses->get('late');
        $absentId = (int) $attendanceStatuses->get('absent');

        $maps = [
            now()->subDays(2)->toDateString() => [
                'Hasan Hamdan' => $presentId,
                'Huda Hamdan' => $presentId,
                'Mariam Sakr' => $presentId,
                'Ilyas Sakr' => $lateId,
                'Ahmad Darwish' => $presentId,
                'Aya Darwish' => $presentId,
                'Leen Qudsi' => $presentId,
                'Omar Qudsi' => $lateId,
            ],
            now()->subDays(1)->toDateString() => [
                'Hasan Hamdan' => $presentId,
                'Huda Hamdan' => $presentId,
                'Mariam Sakr' => $lateId,
                'Ilyas Sakr' => $presentId,
                'Ahmad Darwish' => $presentId,
                'Aya Darwish' => $absentId,
                'Leen Qudsi' => $presentId,
                'Omar Qudsi' => $presentId,
            ],
        ];

        foreach ($maps as $date => $statusMap) {
            $day = $dayService->createOrSyncDay($date, collect($groups)->values(), $admin, 'Demo attendance day', 'open');

            foreach ($statusMap as $studentKey => $statusId) {
                $enrollment = $enrollments[$studentKey];
                $groupDay = GroupAttendanceDay::query()
                    ->where('student_attendance_day_id', $day->id)
                    ->where('group_id', $enrollment->group_id)
                    ->firstOrFail();

                $record = StudentAttendanceRecord::query()->updateOrCreate(
                    [
                        'group_attendance_day_id' => $groupDay->id,
                        'enrollment_id' => $enrollment->id,
                    ],
                    [
                        'attendance_status_id' => $statusId,
                        'notes' => null,
                    ],
                );

                $ledger->voidSourceTransactions('student_attendance_record', $record->id, __('workflow.student_attendance.messages.void_reason'));

                $status = AttendanceStatus::query()->find($statusId);

                if ($status) {
                    $ledger->recordAttendanceStatusPoints(
                        $enrollment,
                        'student_attendance_record',
                        $record->id,
                        $status,
                        __('workflow.student_attendance.messages.automatic_points', ['status' => $status->name]),
                    );
                }

                $ledger->syncEnrollmentCaches($enrollment->fresh(['student']));
            }

            GroupAttendanceDay::query()
                ->where('student_attendance_day_id', $day->id)
                ->update([
                    'status' => 'closed',
                    'notes' => 'Demo attendance captured.',
                ]);

            $dayService->syncAggregateStatus($day->fresh());
        }
    }

    protected function seedMemorization(array $enrollments): void
    {
        $service = app(MemorizationService::class);

        $sessions = [
            ['student' => 'Hasan Hamdan', 'date' => now()->subDays(7)->toDateString(), 'from' => 12, 'to' => 14],
            ['student' => 'Hasan Hamdan', 'date' => now()->subDays(4)->toDateString(), 'from' => 15, 'to' => 16],
            ['student' => 'Mariam Sakr', 'date' => now()->subDays(6)->toDateString(), 'from' => 8, 'to' => 10],
            ['student' => 'Ilyas Sakr', 'date' => now()->subDays(5)->toDateString(), 'from' => 18, 'to' => 19],
            ['student' => 'Ahmad Darwish', 'date' => now()->subDays(3)->toDateString(), 'from' => 44, 'to' => 46],
            ['student' => 'Leen Qudsi', 'date' => now()->subDays(2)->toDateString(), 'from' => 60, 'to' => 62],
        ];

        foreach ($sessions as $row) {
            $enrollment = $enrollments[$row['student']];
            $exists = MemorizationSession::query()
                ->where('enrollment_id', $enrollment->id)
                ->whereDate('recorded_on', $row['date'])
                ->where('from_page', $row['from'])
                ->where('to_page', $row['to'])
                ->exists();

            if ($exists) {
                continue;
            }

            $service->saveSession($enrollment, [
                'teacher_id' => $enrollment->group->teacher_id,
                'recorded_on' => $row['date'],
                'entry_type' => 'new',
                'from_page' => $row['from'],
                'to_page' => $row['to'],
                'notes' => 'Presentation demo memorization entry.',
            ]);
        }
    }

    protected function seedQuranTests(array $enrollments, array $teachers, Collection $quranTestTypes, Collection $juzs): void
    {
        $partialTypeId = $quranTestTypes->get('partial');
        $finalTypeId = $quranTestTypes->get('final');

        QuranTest::query()->updateOrCreate(
            [
                'enrollment_id' => $enrollments['Hasan Hamdan']->id,
                'quran_test_type_id' => $partialTypeId,
                'tested_on' => now()->subDays(10)->toDateString(),
            ],
            [
                'student_id' => $enrollments['Hasan Hamdan']->student_id,
                'teacher_id' => $teachers['Ahmad Al Hadi']->id,
                'juz_id' => $juzs[1] ?? null,
                'score' => 93,
                'status' => 'passed',
                'attempt_no' => 1,
                'notes' => 'Strong recitation and confidence.',
            ],
        );

        QuranTest::query()->updateOrCreate(
            [
                'enrollment_id' => $enrollments['Ahmad Darwish']->id,
                'quran_test_type_id' => $finalTypeId,
                'tested_on' => now()->subDays(8)->toDateString(),
            ],
            [
                'student_id' => $enrollments['Ahmad Darwish']->student_id,
                'teacher_id' => $teachers['Yusuf Kareem']->id,
                'juz_id' => $juzs[4] ?? null,
                'score' => 88,
                'status' => 'passed',
                'attempt_no' => 1,
                'notes' => 'Good stability and revision pace.',
            ],
        );
    }

    protected function seedAssessments(array $groups, Collection $assessmentTypes, array $teachers, array $enrollments, User $admin): void
    {
        $service = app(AssessmentService::class);

        $quiz = Assessment::query()->updateOrCreate(
            ['title' => 'Week 6 Hifz Quiz', 'group_id' => $groups['Boys Hifz Circle']->id],
            [
                'assessment_type_id' => $assessmentTypes->get('quiz'),
                'description' => 'Quick recitation and revision check.',
                'scheduled_at' => now()->subDays(6),
                'due_at' => now()->subDays(6),
                'total_mark' => 100,
                'pass_mark' => 60,
                'is_active' => true,
                'created_by' => $admin->id,
            ],
        );

        $worksheet = Assessment::query()->updateOrCreate(
            ['title' => 'Teen Revision Worksheet', 'group_id' => $groups['Teen Review Circle']->id],
            [
                'assessment_type_id' => $assessmentTypes->get('worksheet'),
                'description' => 'Revision worksheet and tajweed application.',
                'scheduled_at' => now()->subDays(4),
                'due_at' => now()->subDays(4),
                'total_mark' => 100,
                'pass_mark' => 60,
                'is_active' => true,
                'created_by' => $admin->id,
            ],
        );

        $rows = [
            [$quiz, $enrollments['Hasan Hamdan'], $teachers['Ahmad Al Hadi']->id, 91, 'passed', 'Strong focus and retention.'],
            [$quiz, $enrollments['Ilyas Sakr'], $teachers['Ahmad Al Hadi']->id, 78, 'passed', 'Needs slower pacing on late arrival days.'],
            [$worksheet, $enrollments['Ahmad Darwish'], $teachers['Yusuf Kareem']->id, 87, 'passed', 'Excellent revision discipline.'],
            [$worksheet, $enrollments['Leen Qudsi'], $teachers['Yusuf Kareem']->id, 94, 'passed', 'High accuracy and calm delivery.'],
        ];

        foreach ($rows as [$assessment, $enrollment, $teacherId, $score, $status, $notes]) {
            $result = AssessmentResult::query()->updateOrCreate(
                [
                    'assessment_id' => $assessment->id,
                    'enrollment_id' => $enrollment->id,
                ],
                [
                    'student_id' => $enrollment->student_id,
                    'teacher_id' => $teacherId,
                    'score' => $score,
                    'status' => $status,
                    'attempt_no' => 1,
                    'notes' => $notes,
                ],
            );

            $service->syncResultPoints($result->fresh(['assessment', 'enrollment.student']));
        }
    }

    protected function seedNotes(array $students, array $enrollments, User $admin): void
    {
        $notes = [
            ['student' => 'Hasan Hamdan', 'enrollment' => 'Hasan Hamdan', 'visibility' => 'shared_staff', 'source' => 'teacher', 'body' => 'Responds well to structured revision targets and positive reinforcement.'],
            ['student' => 'Huda Hamdan', 'enrollment' => 'Huda Hamdan', 'visibility' => 'parent', 'source' => 'management', 'body' => 'Family is highly engaged and follows up quickly on assignments.'],
            ['student' => 'Ahmad Darwish', 'enrollment' => 'Ahmad Darwish', 'visibility' => 'private_management', 'source' => 'management', 'body' => 'Candidate for future student mentor role during teen sessions.'],
        ];

        foreach ($notes as $row) {
            StudentNote::query()->updateOrCreate(
                [
                    'student_id' => $students[$row['student']]->id,
                    'body' => $row['body'],
                ],
                [
                    'enrollment_id' => $enrollments[$row['enrollment']]->id,
                    'author_id' => $admin->id,
                    'source' => $row['source'],
                    'visibility' => $row['visibility'],
                    'noted_at' => now()->subDays(3),
                ],
            );
        }
    }

    protected function seedManualPoints(array $enrollments, Collection $pointTypes, PointLedgerService $ledger, User $admin): void
    {
        $transactions = [
            ['student' => 'Hasan Hamdan', 'point_type_id' => $pointTypes->get('participation-reward'), 'points' => 5, 'notes' => 'Demo punctuality award'],
            ['student' => 'Aya Darwish', 'point_type_id' => $pointTypes->get('follow-up-reminder'), 'points' => -2, 'notes' => 'Demo missed worksheet reminder'],
        ];

        foreach ($transactions as $row) {
            $enrollment = $enrollments[$row['student']];

            PointTransaction::query()->updateOrCreate(
                [
                    'student_id' => $enrollment->student_id,
                    'enrollment_id' => $enrollment->id,
                    'source_type' => 'manual',
                    'notes' => $row['notes'],
                ],
                [
                    'point_type_id' => $row['point_type_id'],
                    'policy_id' => null,
                    'source_id' => null,
                    'points' => $row['points'],
                    'entered_by' => $admin->id,
                    'entered_at' => now()->subDays(1),
                ],
            );

            $ledger->syncEnrollmentCaches($enrollment->fresh(['student']));
        }
    }

    protected function seedActivities(array $groups, array $enrollments, Collection $paymentMethods, Collection $expenseCategories, User $admin): void
    {
        $finance = app(FinanceService::class);
        $audience = app(ActivityAudienceService::class);

        $activities = [
            'Family Spring Picnic' => Activity::query()->updateOrCreate(
                ['title' => 'Family Spring Picnic'],
                [
                    'description' => 'Weekend outing for students and families with Quran games, lunch, and parent networking.',
                    'activity_date' => now()->addDays(12)->toDateString(),
                    'audience_scope' => ActivityAudienceService::SCOPE_MULTIPLE_GROUPS,
                    'group_id' => null,
                    'fee_amount' => 7.50,
                    'is_active' => true,
                ],
            ),
            'Teen Revision Intensive' => Activity::query()->updateOrCreate(
                ['title' => 'Teen Revision Intensive'],
                [
                    'description' => 'Focused Saturday review session for teen students before the next round of tests.',
                    'activity_date' => now()->addDays(7)->toDateString(),
                    'audience_scope' => ActivityAudienceService::SCOPE_SINGLE_GROUP,
                    'group_id' => $groups['Teen Review Circle']->id,
                    'fee_amount' => 5.00,
                    'is_active' => true,
                ],
            ),
            'Eid Community Fair' => Activity::query()->updateOrCreate(
                ['title' => 'Eid Community Fair'],
                [
                    'description' => 'A mosque-wide celebration with student booths, prizes, and family volunteers.',
                    'activity_date' => now()->addDays(20)->toDateString(),
                    'audience_scope' => ActivityAudienceService::SCOPE_ALL_GROUPS,
                    'group_id' => null,
                    'fee_amount' => 3.00,
                    'is_active' => true,
                ],
            ),
        ];

        $audience->syncTargets(
            $activities['Family Spring Picnic'],
            ActivityAudienceService::SCOPE_MULTIPLE_GROUPS,
            null,
            [
                $groups['Boys Hifz Circle']->id,
                $groups['Girls Tajweed Circle']->id,
            ],
        );

        $audience->syncTargets(
            $activities['Teen Revision Intensive'],
            ActivityAudienceService::SCOPE_SINGLE_GROUP,
            $groups['Teen Review Circle']->id,
        );

        $audience->syncTargets(
            $activities['Eid Community Fair'],
            ActivityAudienceService::SCOPE_ALL_GROUPS,
            null,
        );

        $registrations = [
            ['activity' => 'Family Spring Picnic', 'student' => 'Hasan Hamdan', 'status' => 'registered', 'notes' => 'Parent confirmed with one sibling.', 'paid' => 7.50],
            ['activity' => 'Family Spring Picnic', 'student' => 'Huda Hamdan', 'status' => 'registered', 'notes' => 'Joining with family transport.', 'paid' => 7.50],
            ['activity' => 'Family Spring Picnic', 'student' => 'Mariam Sakr', 'status' => 'registered', 'notes' => 'Confirmed after parent response.', 'paid' => 0],
            ['activity' => 'Family Spring Picnic', 'student' => 'Ilyas Sakr', 'status' => 'declined', 'notes' => 'Family unavailable that weekend.', 'paid' => 0],
            ['activity' => 'Teen Revision Intensive', 'student' => 'Ahmad Darwish', 'status' => 'registered', 'notes' => 'Teacher recommended attendance.', 'paid' => 5.00],
            ['activity' => 'Teen Revision Intensive', 'student' => 'Leen Qudsi', 'status' => 'registered', 'notes' => 'Confirmed by parent.', 'paid' => 0],
            ['activity' => 'Eid Community Fair', 'student' => 'Hasan Hamdan', 'status' => 'registered', 'notes' => 'Signed up for games booth.', 'paid' => 3.00],
            ['activity' => 'Eid Community Fair', 'student' => 'Aya Darwish', 'status' => 'registered', 'notes' => 'Helping with welcome desk.', 'paid' => 0],
            ['activity' => 'Eid Community Fair', 'student' => 'Omar Qudsi', 'status' => 'registered', 'notes' => 'Attending with siblings.', 'paid' => 0],
        ];

        foreach ($registrations as $row) {
            $activity = $activities[$row['activity']];
            $enrollment = $enrollments[$row['student']];

            $registration = ActivityRegistration::query()->updateOrCreate(
                [
                    'activity_id' => $activity->id,
                    'student_id' => $enrollment->student_id,
                ],
                [
                    'enrollment_id' => $enrollment->id,
                    'fee_amount' => $activity->fee_amount ?? 0,
                    'status' => $row['status'],
                    'notes' => $row['notes'],
                ],
            );

            if ($row['paid'] > 0) {
                ActivityPayment::query()->updateOrCreate(
                    [
                        'activity_registration_id' => $registration->id,
                        'reference_no' => 'DEMO-ACT-'.$registration->id,
                    ],
                    [
                        'payment_method_id' => $paymentMethods->get('cash'),
                        'paid_at' => now()->subDays(1)->toDateString(),
                        'amount' => $row['paid'],
                        'entered_by' => $admin->id,
                        'notes' => 'Presentation demo activity payment.',
                        'voided_at' => null,
                        'voided_by' => null,
                        'void_reason' => null,
                    ],
                );
            }
        }

        $expenses = [
            ['activity' => 'Family Spring Picnic', 'category' => 'refreshments', 'amount' => 26.00, 'description' => 'Snacks and drinks', 'spent_on' => now()->subDays(1)->toDateString()],
            ['activity' => 'Family Spring Picnic', 'category' => 'transport', 'amount' => 18.00, 'description' => 'Bus reservation deposit', 'spent_on' => now()->subDays(1)->toDateString()],
            ['activity' => 'Teen Revision Intensive', 'category' => 'supplies', 'amount' => 12.50, 'description' => 'Printed revision packs', 'spent_on' => now()->toDateString()],
            ['activity' => 'Eid Community Fair', 'category' => 'prizes', 'amount' => 34.00, 'description' => 'Student prize packs', 'spent_on' => now()->toDateString()],
        ];

        foreach ($expenses as $row) {
            ActivityExpense::query()->updateOrCreate(
                [
                    'activity_id' => $activities[$row['activity']]->id,
                    'description' => $row['description'],
                ],
                [
                    'expense_category_id' => $expenseCategories->get($row['category']),
                    'amount' => $row['amount'],
                    'spent_on' => $row['spent_on'],
                    'entered_by' => $admin->id,
                ],
            );
        }

        foreach ($activities as $activity) {
            $finance->syncActivityTotals($activity->fresh());
        }
    }

    protected function seedInvoices(array $parents, array $enrollments, Collection $paymentMethods, User $admin): void
    {
        $finance = app(FinanceService::class);

        $invoices = [
            'Hamdan April Tuition' => Invoice::query()->updateOrCreate(
                ['invoice_no' => 'DEMO-INV-001'],
                [
                    'parent_id' => $parents['Hamdan Family']->id,
                    'invoice_type' => 'tuition',
                    'issue_date' => now()->subDays(6)->toDateString(),
                    'due_date' => now()->addDays(9)->toDateString(),
                    'status' => 'issued',
                    'discount' => 0,
                    'notes' => 'Demo family tuition invoice for the current month.',
                ],
            ),
            'Sakr Mixed Charges' => Invoice::query()->updateOrCreate(
                ['invoice_no' => 'DEMO-INV-002'],
                [
                    'parent_id' => $parents['Sakr Family']->id,
                    'invoice_type' => 'mixed',
                    'issue_date' => now()->subDays(4)->toDateString(),
                    'due_date' => now()->addDays(11)->toDateString(),
                    'status' => 'issued',
                    'discount' => 2.50,
                    'notes' => 'Demo invoice mixing tuition and event costs.',
                ],
            ),
            'Darwish Program Fees' => Invoice::query()->updateOrCreate(
                ['invoice_no' => 'DEMO-INV-003'],
                [
                    'parent_id' => $parents['Darwish Family']->id,
                    'invoice_type' => 'tuition',
                    'issue_date' => now()->subDays(2)->toDateString(),
                    'due_date' => now()->addDays(14)->toDateString(),
                    'status' => 'issued',
                    'discount' => 0,
                    'notes' => 'Teen circle and tajweed monthly charges.',
                ],
            ),
        ];

        $this->seedInvoiceItem($invoices['Hamdan April Tuition'], $enrollments['Hasan Hamdan'], 'April tuition - Hasan Hamdan', 1, 18.00);
        $this->seedInvoiceItem($invoices['Hamdan April Tuition'], $enrollments['Huda Hamdan'], 'April tuition - Huda Hamdan', 1, 16.00);

        $this->seedInvoiceItem($invoices['Sakr Mixed Charges'], $enrollments['Mariam Sakr'], 'April tuition - Mariam Sakr', 1, 16.00);
        $this->seedInvoiceItem($invoices['Sakr Mixed Charges'], $enrollments['Ilyas Sakr'], 'April tuition - Ilyas Sakr', 1, 18.00);
        $this->seedInvoiceItem($invoices['Sakr Mixed Charges'], $enrollments['Mariam Sakr'], 'Family Spring Picnic fee', 1, 7.50);

        $this->seedInvoiceItem($invoices['Darwish Program Fees'], $enrollments['Ahmad Darwish'], 'April tuition - Ahmad Darwish', 1, 22.00);
        $this->seedInvoiceItem($invoices['Darwish Program Fees'], $enrollments['Aya Darwish'], 'April tuition - Aya Darwish', 1, 16.00);

        foreach ($invoices as $invoice) {
            $finance->syncInvoiceTotals($invoice->fresh());
        }

        $payments = [
            ['invoice' => 'Hamdan April Tuition', 'reference' => 'DEMO-PAY-001', 'amount' => 20.00, 'paid_at' => now()->subDays(3)->toDateString(), 'method' => 'cash'],
            ['invoice' => 'Sakr Mixed Charges', 'reference' => 'DEMO-PAY-002', 'amount' => 39.00, 'paid_at' => now()->subDays(2)->toDateString(), 'method' => 'bank-transfer'],
        ];

        foreach ($payments as $row) {
            Payment::query()->updateOrCreate(
                [
                    'invoice_id' => $invoices[$row['invoice']]->id,
                    'reference_no' => $row['reference'],
                ],
                [
                    'payment_method_id' => $paymentMethods->get($row['method']),
                    'paid_at' => $row['paid_at'],
                    'amount' => $row['amount'],
                    'received_by' => $admin->id,
                    'notes' => 'Presentation demo invoice payment.',
                    'voided_at' => null,
                    'voided_by' => null,
                    'void_reason' => null,
                ],
            );
        }

        foreach ($invoices as $invoice) {
            $finance->syncInvoiceTotals($invoice->fresh());
        }
    }

    protected function seedInvoiceItem(Invoice $invoice, Enrollment $enrollment, string $description, int $quantity, float $unitPrice): void
    {
        InvoiceItem::query()->updateOrCreate(
            [
                'invoice_id' => $invoice->id,
                'description' => $description,
            ],
            [
                'student_id' => $enrollment->student_id,
                'enrollment_id' => $enrollment->id,
                'activity_id' => null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => $quantity * $unitPrice,
            ],
        );
    }

    protected function upsertUser(string $email, string $username, string $phone, string $name, string $role): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        $user->forceFill([
            'name' => $name,
            'username' => $username,
            'phone' => $phone,
            'password' => 'P@ssw0rd',
            'issued_password' => 'P@ssw0rd',
            'is_active' => true,
        ])->save();

        $user->syncRoles([$role]);

        return $user->fresh();
    }

    protected function upsertStudent(array $identity, array $attributes): Student
    {
        return Student::query()->updateOrCreate(
            $identity,
            array_merge([
                'status' => 'active',
                'joined_at' => now()->toDateString(),
            ], $attributes),
        );
    }

    protected function upsertEnrollment(Student $student, Group $group, string $enrolledAt): Enrollment
    {
        return Enrollment::query()->updateOrCreate(
            [
                'student_id' => $student->id,
                'group_id' => $group->id,
            ],
            [
                'enrolled_at' => $enrolledAt,
                'status' => 'active',
                'left_at' => null,
                'notes' => 'Presentation demo enrollment.',
            ],
        );
    }
}
