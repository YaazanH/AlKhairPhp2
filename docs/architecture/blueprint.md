# Alkhair App Domain Blueprint

## 1. Product Scope

`alkhairapp` manages:

- students, parents, teachers, managers, and admins
- courses, groups, schedules, and enrollments
- Quran memorization and Quran test progression
- attendance for students and teachers
- points, rewards, penalties, and dashboards
- exams, quizzes, worksheets, and activities
- invoices, payments, and activity finances
- notes, photos, and reporting
- API access and OpenAPI documentation for third-party integrations

## 2. Architecture Decisions

### Identity and Access

- Use one `users` table for authentication.
- Use role-specific profile tables:
  - `parents`
  - `students`
  - `teachers`
- Keep business data out of `users`.
- Use role and permission checks everywhere; do not hardcode role names into controllers.

### Course vs Group

- `courses` are templates or subjects.
- `groups` are actual running teaching groups tied to academic year, teacher, capacity, and schedule.
- Students enroll into `groups`, not directly into `courses`.

### Settings-Driven, Not Over-Generic

Admin-editable master tables should drive business rules:

- attendance statuses
- point types
- point policies
- assessment types
- Quran test types
- grade levels
- payment methods
- expense categories
- academic years

History tables must stay strict and auditable:

- point transactions
- memorization sessions
- attendance records
- assessment results
- Quran tests
- invoices and payments

## 3. Recommended Laravel Stack

- Laravel with the official Livewire starter kit
- Livewire for dashboards and CRUD screens
- MySQL for primary storage
- Laravel Sanctum for API token auth
- Spatie `laravel-permission` for roles/permissions
- Scramble for OpenAPI/Swagger-style API docs
- Spatie `laravel-activitylog` for audit logging on sensitive actions

Why this stack:

- Laravel's official starter kit supports Livewire auth flows and app layouts.
- Sanctum fits first-party UI plus future mobile/external app token access.
- Spatie permission is the standard choice for Laravel role/permission control.
- Scramble produces OpenAPI docs and a docs UI from Laravel routes/code.

## 4. Migration List

Migration order should follow dependencies.

### A. Foundation

1. `users`
2. `password_reset_tokens`
3. `sessions`
4. `cache`
5. `jobs`
6. `failed_jobs`
7. `personal_access_tokens`
8. Spatie permission tables:
   - `permissions`
   - `roles`
   - `model_has_permissions`
   - `model_has_roles`
   - `role_has_permissions`
9. `activity_log`

### B. Master Data

10. `academic_years`
11. `grade_levels`
12. `attendance_statuses`
13. `assessment_types`
14. `quran_test_types`
15. `point_types`
16. `point_policies`
17. `payment_methods`
18. `expense_categories`
19. `app_settings`
20. `quran_juzs`

### C. People

21. `parents`
22. `teachers`
23. `students`
24. `student_files`

### D. Learning Structure

25. `courses`
26. `groups`
27. `group_schedules`
28. `enrollments`

### E. Attendance

29. `teacher_attendance_days`
30. `teacher_attendance_records`
31. `group_attendance_days`
32. `student_attendance_records`

### F. Quran Tracking

33. `memorization_sessions`
34. `memorization_session_pages`
35. `student_page_achievements`
36. `quran_tests`

### G. Assessments

37. `assessments`
38. `assessment_results`
39. `assessment_score_bands`

### H. Points

40. `point_transactions`

### I. Activities and Calendar

41. `activities`
42. `activity_registrations`
43. `activity_expenses`
44. `calendar_events`

### J. Finance

45. `invoices`
46. `invoice_items`
47. `payments`

### K. Notes

48. `student_notes`

## 5. Table Blueprint

Only core columns are listed here. Laravel timestamps and soft deletes should be added where appropriate.

### `users`

- `id`
- `name`
- `username` unique
- `email` nullable unique
- `phone` nullable unique
- `password`
- `is_active`
- `last_login_at` nullable

### `academic_years`

- `id`
- `name`
- `starts_on`
- `ends_on`
- `is_current`
- `is_active`

### `grade_levels`

- `id`
- `name`
- `sort_order`
- `is_active`

### `attendance_statuses`

- `id`
- `name`
- `code` unique
- `scope` enum: `student`, `teacher`, `both`
- `default_points`
- `color`
- `is_present`
- `is_active`

Examples: present, absent, late, excused, early-leave.

### `assessment_types`

- `id`
- `name`
- `code` unique
- `is_scored`
- `is_active`

Examples: exam, quiz, worksheet.

### `quran_test_types`

- `id`
- `name`
- `code` unique
- `sort_order`
- `is_active`

Examples: partial, final, awqaf.

### `point_types`

- `id`
- `name`
- `code` unique
- `category`
- `default_points`
- `allow_manual_entry`
- `allow_negative`
- `is_active`

Examples: memorization-page, attendance-present, attendance-late, quiz-score, worksheet-score, penalty, bonus.

### `point_policies`

- `id`
- `name`
- `point_type_id`
- `source_type`
- `trigger_key`
- `grade_level_id` nullable
- `from_value` nullable
- `to_value` nullable
- `points`
- `priority`
- `is_active`

This table makes the system dynamic. Examples:

- attendance status `present` gives `+2`
- attendance status `late` gives `+1`
- each memorized page gives `+1`
- quiz score from `80` to `100` gives `+5`

### `payment_methods`

- `id`
- `name`
- `code`
- `is_active`

### `expense_categories`

- `id`
- `name`
- `code`
- `is_active`

### `app_settings`

- `id`
- `group`
- `key`
- `value` text or json
- `type`

Use this only for small global settings, not relational business rules.

### `quran_juzs`

- `id`
- `juz_number` unique
- `from_page`
- `to_page`

Seed all 30 rows.

### `parents`

- `id`
- `user_id` nullable unique
- `father_name`
- `father_work` nullable
- `father_phone` nullable
- `mother_name` nullable
- `mother_phone` nullable
- `home_phone` nullable
- `address` nullable
- `notes` nullable
- `is_active`

### `teachers`

- `id`
- `user_id` nullable unique
- `first_name`
- `last_name`
- `phone`
- `job_title` nullable
- `status` enum: `active`, `blocked`, `inactive`
- `hired_at` nullable
- `notes` nullable

### `students`

- `id`
- `user_id` nullable unique
- `parent_id`
- `first_name`
- `last_name`
- `birth_date`
- `gender` nullable
- `school_name` nullable
- `grade_level_id` nullable
- `quran_current_juz_id` nullable
- `photo_path` nullable
- `status` enum: `active`, `inactive`, `blocked`, `graduated`
- `joined_at` nullable
- `notes` nullable

### `student_files`

- `id`
- `student_id`
- `file_type`
- `file_path`
- `original_name`
- `uploaded_by`

Use this for photos and future documents.

### `courses`

- `id`
- `name`
- `description` nullable
- `is_active`

### `groups`

- `id`
- `course_id`
- `academic_year_id`
- `teacher_id`
- `assistant_teacher_id` nullable
- `grade_level_id` nullable
- `name`
- `capacity`
- `starts_on` nullable
- `ends_on` nullable
- `monthly_fee` nullable
- `is_active`

### `group_schedules`

- `id`
- `group_id`
- `day_of_week`
- `starts_at`
- `ends_at`
- `room_name` nullable
- `is_active`

### `enrollments`

- `id`
- `student_id`
- `group_id`
- `enrolled_at`
- `status` enum: `active`, `paused`, `completed`, `withdrawn`
- `left_at` nullable
- `final_points_cached` default `0`
- `memorized_pages_cached` default `0`
- `notes` nullable

Caching summary values here is acceptable for reporting, but every report must still be reconcilable from source rows.

### `teacher_attendance_days`

- `id`
- `attendance_date` unique
- `status` enum: `open`, `closed`
- `created_by`
- `notes` nullable

### `teacher_attendance_records`

- `id`
- `teacher_attendance_day_id`
- `teacher_id`
- `attendance_status_id`
- `notes` nullable

Unique key: `teacher_attendance_day_id + teacher_id`.

### `group_attendance_days`

- `id`
- `group_id`
- `attendance_date`
- `status` enum: `open`, `closed`
- `created_by`
- `notes` nullable

Unique key: `group_id + attendance_date`.

### `student_attendance_records`

- `id`
- `group_attendance_day_id`
- `enrollment_id`
- `attendance_status_id`
- `notes` nullable

Unique key: `group_attendance_day_id + enrollment_id`.

### `memorization_sessions`

- `id`
- `enrollment_id`
- `student_id`
- `teacher_id`
- `recorded_on`
- `entry_type` enum: `new`, `review`, `correction`
- `from_page` nullable
- `to_page` nullable
- `pages_count`
- `notes` nullable

This is the header row for one memorization action.

### `memorization_session_pages`

- `id`
- `memorization_session_id`
- `page_no`

Unique key: `memorization_session_id + page_no`.

### `student_page_achievements`

- `id`
- `student_id`
- `page_no`
- `first_enrollment_id`
- `first_session_id`
- `first_recorded_on`

Unique key: `student_id + page_no`.

This table prevents duplicate lifetime memorization entry and speeds up lifetime reports.

### `quran_tests`

- `id`
- `enrollment_id`
- `student_id`
- `teacher_id`
- `juz_id`
- `quran_test_type_id`
- `tested_on`
- `score` nullable
- `status` enum: `passed`, `failed`, `cancelled`
- `attempt_no`
- `notes` nullable

Recommended rule layer:

- final test requires four passed partial tests for the same juz
- awqaf test requires passed final test for the same juz

This should be enforced in service logic, not only in UI.

### `assessments`

- `id`
- `group_id`
- `assessment_type_id`
- `title`
- `description` nullable
- `scheduled_at` nullable
- `due_at` nullable
- `total_mark` nullable
- `pass_mark` nullable
- `is_active`

### `assessment_results`

- `id`
- `assessment_id`
- `enrollment_id`
- `student_id`
- `teacher_id`
- `score` nullable
- `status` enum: `passed`, `failed`, `absent`, `pending`
- `attempt_no`
- `notes` nullable

### `assessment_score_bands`

- `id`
- `assessment_type_id`
- `name`
- `from_mark`
- `to_mark`
- `point_type_id` nullable
- `points` nullable
- `is_fail`
- `is_active`

This supports configurable reward logic by score range.

### `point_transactions`

- `id`
- `student_id`
- `enrollment_id`
- `point_type_id`
- `policy_id` nullable
- `source_type`
- `source_id`
- `points` signed integer
- `entered_by`
- `entered_at`
- `notes` nullable
- `voided_at` nullable
- `voided_by` nullable
- `void_reason` nullable

Rules:

- never update totals directly
- keep the ledger as the source of truth
- for mistakes, void the original row and create the replacement row

### `activities`

- `id`
- `title`
- `description` nullable
- `activity_date`
- `group_id` nullable
- `fee_amount` nullable
- `is_active`

### `activity_registrations`

- `id`
- `activity_id`
- `student_id`
- `enrollment_id` nullable
- `fee_amount`
- `status` enum: `registered`, `cancelled`, `attended`
- `notes` nullable

### `activity_expenses`

- `id`
- `activity_id`
- `expense_category_id`
- `amount`
- `spent_on`
- `description`
- `entered_by`

### `calendar_events`

- `id`
- `title`
- `event_type`
- `starts_at`
- `ends_at` nullable
- `all_day`
- `group_id` nullable
- `audience`
- `location` nullable
- `description` nullable
- `created_by`

### `invoices`

- `id`
- `parent_id`
- `invoice_no` unique
- `invoice_type` enum: `tuition`, `activity`, `other`
- `issue_date`
- `due_date` nullable
- `status` enum: `draft`, `issued`, `partial`, `paid`, `cancelled`
- `subtotal`
- `discount`
- `total`
- `notes` nullable

### `invoice_items`

- `id`
- `invoice_id`
- `student_id` nullable
- `enrollment_id` nullable
- `activity_id` nullable
- `description`
- `quantity`
- `unit_price`
- `amount`

### `payments`

- `id`
- `invoice_id`
- `payment_method_id`
- `paid_at`
- `amount`
- `reference_no` nullable
- `received_by`
- `notes` nullable

### `student_notes`

- `id`
- `student_id`
- `enrollment_id` nullable
- `author_id`
- `source` enum: `parent`, `teacher`, `management`, `system`
- `visibility` enum: `private_teacher`, `private_management`, `shared_internal`, `visible_to_parent`
- `note`
- `noted_at`

## 6. Key Relationships

- parent has many students
- student belongs to parent
- student has many enrollments
- course has many groups
- group belongs to course
- group belongs to academic year
- group belongs to main teacher
- group may belong to assistant teacher
- group has many schedules
- group has many enrollments
- enrollment belongs to student
- enrollment belongs to group
- enrollment has many memorization sessions
- enrollment has many point transactions
- enrollment has many attendance records
- student has many page achievements
- parent has many invoices
- invoice has many items
- invoice has many payments

## 7. Business Rules

### Attendance

- teacher attendance is global per date
- student attendance is per group per date
- creating an attendance day should preload expected records for fast entry
- attendance status may automatically trigger points through `point_policies`

### Memorization

- each entered memorization session may include a page range for UX
- pages are stored as one row per page in `memorization_session_pages`
- first lifetime achievement per page is stored in `student_page_achievements`
- duplicate lifetime page entry should be blocked unless a manager overrides it explicitly

### Points

- points can be positive or negative
- manual points are allowed only for specific point types
- automatic points are generated from attendance, memorization, and assessment logic
- manual edits should create audit history

### Quran Progression

- a student may have repeated partial attempts on the same juz
- failed attempts remain stored
- progression checks use passed attempts count and test type prerequisites
- service-layer validation decides whether final or awqaf is currently allowed

### Finance

- invoices belong to a parent
- invoice items may reference student, enrollment, and activity
- activities may generate fees and expenses
- reports must show both collected payments and activity spending

## 8. API Strategy

Use web routes for Blade/Livewire and API routes for integrations.

Expose versioned API routes:

- `/api/v1/auth`
- `/api/v1/students`
- `/api/v1/teachers`
- `/api/v1/parents`
- `/api/v1/groups`
- `/api/v1/enrollments`
- `/api/v1/attendance`
- `/api/v1/memorization`
- `/api/v1/quran-tests`
- `/api/v1/assessments`
- `/api/v1/points`
- `/api/v1/invoices`
- `/api/v1/payments`
- `/api/v1/activities`

Recommended API rules:

- use Sanctum tokens for external clients
- apply permission middleware to API endpoints
- keep request validation in Form Requests
- keep business rules in service classes or actions
- generate OpenAPI docs from actual routes and request/response classes

## 9. Reporting Targets

Plan reports from the start:

- student lifetime memorized pages
- memorized pages by enrollment and by date range
- group attendance summary
- teacher attendance summary
- point ledger and point balance by enrollment
- top students by points
- Quran test progression by juz
- unpaid invoices
- payments by date range and payment method
- activity revenue vs expense

## 10. Build Order

### Phase 1

- scaffold Laravel app
- configure MySQL
- install auth, Livewire, Sanctum, permissions, OpenAPI docs, activity log
- create master tables and seeds

### Phase 2

- build users, parents, teachers, students
- build roles and permissions UI
- build academic years, courses, groups, schedules, enrollments

### Phase 3

- build student and teacher attendance flow
- build Quran memorization flow
- build Quran test progression rules
- build points ledger and automatic point generation

### Phase 4

- build assessments and score-based rewards
- build invoices, payments, and activity finance
- build notes, files, and dashboards

### Phase 5

- expose API resources
- publish OpenAPI docs
- add exports and reporting screens
- harden authorization and audit coverage

## 11. First Seeder Targets

- roles: admin, manager, teacher, parent, student
- attendance statuses: present, absent, late, excused
- assessment types: exam, quiz, worksheet
- Quran test types: partial, final, awqaf
- point types for attendance, memorization, quiz, worksheet, penalty, bonus
- academic year current row
- 30 Quran juz rows

