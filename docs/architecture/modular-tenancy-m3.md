# M3: Classes, Teachers, Attendance and Curriculum

Implemented on `modules-and-tenant-feature`, 2026-09-21.

## Behavior delivered

- The tenant-module request boundary now covers people and operational modules through the generalized `EnsureTenantModules` middleware. Direct web, Livewire and API entry points for Teachers, Classes, Student Attendance, Teacher Attendance and Curriculum are rejected when their module is unavailable. Permission assignment and Gate checks continue to reject unavailable module permissions even for super administrators.
- Sidebar ownership now uses the module registry for all currently classified business areas. Teachers, courses/groups/enrollments, both attendance pages and curriculum disappear when unavailable; empty sidebar groups collapse automatically.
- Groups can be created and students enrolled before assigning a teacher. A forward migration makes `groups.teacher_id` nullable and web/API validation accepts an unassigned group. Existing teacher assignment, assistant assignment, scope and availability checks remain active whenever a teacher is selected.
- Teacher-led learning writes fail with a clear 422 response while a group has no primary or assistant teacher. Memorization, Quran-test and assessment API writes use the same guard. Curriculum progress/custom-lesson writes also require an assigned teacher and attribute the record to the acting assigned teacher, or to the group's identified teacher when an authorized administrator records on the group's behalf.
- Student Attendance works without Classes through center attendance days. Center records belong directly to registered students and the attendance day, with no fabricated group or enrollment. The web page creates a center day, preloads active students, records status per student and supports closing/reopening. Mobile/API day creation and scanning select center mode automatically when Classes is unavailable; API clients may explicitly request center mode when Classes is available.
- Existing group attendance remains unchanged when Classes is available. Old enrollment-linked records and new center records share the attendance status model but retain explicit ownership fields.
- Teacher Attendance works without Classes. In that combination, the create flow includes active teacher profiles directly and does not require a course or schedule. When Classes is available, the existing scheduled-teacher/course behavior remains.
- Curriculum remains tenant-owned because all curriculum tables and media execute on the resolved tenant database/storage context. There is no landlord/shared curriculum catalog. Curriculum routes and subject/resource settings now require the Curriculum module.
- Attendance reports include direct center records and module-aware day counts. Class filters and class-based headline data disappear when Classes is unavailable, and attendance panels/data are unavailable when Student Attendance is disabled. Report cache keys include the relevant effective modules.
- Browser remember-cookie cleanup explicitly uses the web session guard, preventing an API guard selected earlier in the same process from invoking unsupported remember-cookie methods.

## Schema migrations

- `2026_09_21_000000_make_group_teacher_optional.php` makes `groups.teacher_id` nullable. Its rollback refuses to proceed while unassigned groups exist.
- `2026_09_21_010000_add_center_student_attendance.php` adds attendance-day scope and direct day/student ownership to student attendance records while retaining the existing enrollment/group relationships for group attendance.

The migrations were created and exercised by disposable SQLite test databases. They were not applied to the user's local MySQL tenant databases. Production rollout must run the normal landlord migration and tenant-database iteration separately; an ordinary default migration command is not proof that every tenant database was updated.

## Verification

- `OperationalModulesTest`: 5 tests / 28 assertions passed for unassigned group creation/enrollment, center attendance without Classes, Teacher Attendance without Classes, scoped teacher summaries, teacher-led write rejection, and disabled-route/sidebar behavior.
- Existing `OperationalWriteApiTest`: 2 tests / 84 assertions passed, preserving group attendance, teacher attendance, learning, points and finance API behavior.
- Existing `StudentAttendanceDayModuleTest`: 15 tests / 112 assertions passed, preserving group-day creation, status changes, quick scanning, teacher scoping, points and PDF export behavior.
- Existing `PeopleModulesTest`: 6 tests / 35 assertions passed after generalizing the middleware.
- Blade compilation, PHP lint, Pint and `git diff --check` passed during M3 verification.

M3 does not add the Platform Admin package/extras screens (M7), onboarding readiness (M8), or native mobile-client changes. Learning/points combinations remain M4 work. Finance, billing and activity isolation remain M5 work. Printing, public website and the final shared-surface audit remain M6 work.
