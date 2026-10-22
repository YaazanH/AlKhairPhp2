# AlKhair API Reference

This document reflects the API routes currently defined in the codebase as of 2026-10-11.

## Base URL

Use your application base URL, then add `/api`.

Example:

```text
https://your-domain.com/api
```

Versioned endpoints currently use:

```text
/api/v1/...
```

## Authentication

The API uses Laravel Sanctum bearer tokens.

Tokens are accepted only while their owning account remains active. Disabling an account immediately blocks its previously issued tokens from every protected API route.

### Login and get token

`POST /api/v1/auth/token`

Request:

```json
{
  "device_name": "android-phone",
  "login": "username-or-email-or-phone",
  "password": "your-password"
}
```

Successful response `201`:

```json
{
  "token": "plain-text-token",
  "token_type": "Bearer",
  "abilities": [
    "students.view",
    "groups.view"
  ],
  "user": {
    "id": 1,
    "name": "Alkhair Admin",
    "username": "admin",
    "roles": [
      "manager"
    ],
    "permissions": [
      "students.view",
      "groups.view"
    ]
  }
}
```

Use the token in the `Authorization` header:

```http
Authorization: Bearer plain-text-token
```

### Logout and revoke current token

`DELETE /api/v1/auth/token`

Response: `204 No Content`

### Logout from every device

`DELETE /api/v1/auth/tokens`

Revokes every Sanctum token belonging to the authenticated user, including the token used for this request.

Response: `204 No Content`

### Authenticated user

`GET /api/v1/me`

Returns the mobile-safe authenticated user payload, including roles, permissions, linked profile IDs, profile-photo URL, and whether the username is editable.

`GET /api/user` remains available as the legacy raw-user endpoint.

## Mobile Application Startup

### Bootstrap

`GET /api/v1/bootstrap`

Returns the authenticated user, effective permissions, mobile navigation, organization branding, supported locales, and feature flags. Mobile clients should call this after login and when returning from a long-lived background session.

### Dashboard

`GET /api/v1/dashboard`

Returns role-aware metrics restricted to the records accessible to the authenticated user.

## Mobile Profile

### Update username

`PATCH /api/v1/me`

Send `username` as JSON. Parent and student usernames are immutable. When an editable username changes, the application derives the email address using the same account rule as the website.

### Update profile photo

`POST /api/v1/me/profile-photo`

Send `profile_photo` as multipart form data. The configured image-size limit and the website's linked student/teacher photo rules also apply to mobile uploads.

### Change password

`PATCH /api/v1/me/password`

```json
{
  "current_password": "current-password",
  "password": "new-password",
  "password_confirmation": "new-password"
}
```

A successful password change keeps the current token and revokes tokens belonging to other devices.

## Localization

Send either `X-Locale: ar` or `X-Locale: en`. When `X-Locale` is absent, the API uses `Accept-Language`, followed by the configured application locale.

## Important Auth Note

API login returns the authenticated user's complete effective application permission set in both `abilities` and `user.permissions`. This is the same permission set used by the website, including permissions inherited through roles and permissions assigned directly to the user.

Current API controllers authorize requests against the user's current application permissions, such as `students.view`, `memorization.record`, and `attendance.student.take`. The token abilities are a login-time snapshot for clients; data-scope checks and endpoint-specific business rules are enforced separately.

## Common Behaviour

- Most list endpoints return Laravel paginator JSON.
- All `/api` errors are returned as JSON, including requests that omit the `Accept` header.
- Common errors:
  - `401` unauthenticated
  - `403` forbidden or inactive account
  - `422` validation or business-rule error
- Most protected endpoints are also scope-aware, so a user may have permission but still be blocked from records outside their scope.
- Native mobile clients should store the bearer token in the platform's secure credential storage, never in plain preferences or application logs.
- Production traffic must use HTTPS. The default bearer-token CORS policy does not use browser credentials or cookies.

## Reference Data

Reference endpoints return compact, non-paginated `data` arrays for mobile selectors. They require authentication and at least one permission related to the catalog's workflow.

Common optional parameters:

| Name | Type | Notes |
| --- | --- | --- |
| `q` | string | Searches supported name/code fields. |
| `include_inactive` | boolean | Defaults to `false`. Enable when editing historical records. |

Available catalogs:

| Endpoint | Important fields or filters |
| --- | --- |
| `GET /api/v1/reference/academic-years` | Dates, current and active flags. |
| `GET /api/v1/reference/grade-levels` | Sort order and active flag. |
| `GET /api/v1/reference/attendance-statuses` | `scope=student`, `teacher`, or `both`; presence/default behavior and points. |
| `GET /api/v1/reference/assessment-types` | Code and scored flag. |
| `GET /api/v1/reference/assessment-score-bands` | Optional `assessment_type_id`; nested assessment and point types. |
| `GET /api/v1/reference/quran-juz` | Juz number and page boundaries. |
| `GET /api/v1/reference/quran-test-types` | Code and sort order. |
| `GET /api/v1/reference/point-types` | Optional `manual_only=1`; category and negative/manual-entry rules. |
| `GET /api/v1/reference/payment-methods` | Name, code and active flag. |
| `GET /api/v1/reference/expense-categories` | Name, code and active flag. |
| `GET /api/v1/reference/currencies` | Optional `include_hidden=1`; rate, precision and base/local flags. |
| `GET /api/v1/reference/teacher-job-titles` | Sort order and active flag. |
| `GET /api/v1/reference/schools` | Name and active flag. |

## Standard Paginated Response Shape

List endpoints return a paginator like this:

```json
{
  "current_page": 1,
  "data": [],
  "first_page_url": "https://example.com/api/v1/students?page=1",
  "from": 1,
  "last_page": 3,
  "last_page_url": "https://example.com/api/v1/students?page=3",
  "links": [],
  "next_page_url": "https://example.com/api/v1/students?page=2",
  "path": "https://example.com/api/v1/students",
  "per_page": 15,
  "prev_page_url": null,
  "to": 15,
  "total": 38
}
```

## Core People and Academics

All core record endpoints require Sanctum authentication, their matching website permission, and (where applicable) the authenticated user's record scope. Passwords are never included in parent or teacher responses.

### Parents

| Method | Endpoint | Permission | Notes |
| --- | --- | --- | --- |
| `GET` | `/api/v1/parents` | `parents.view` | Paginated; supports `search`, `is_active`, and `per_page`. |
| `GET` | `/api/v1/parents/{parent}` | `parents.view` | Includes contact fields and student count. |
| `POST` | `/api/v1/parents` | `parents.create` | Creates the profile and linked parent login in one transaction. |
| `PUT/PATCH` | `/api/v1/parents/{parent}` | `parents.update` | Synchronizes the linked login name, phones, and status. |
| `DELETE` | `/api/v1/parents/{parent}` | `parents.delete` | Blocked while students are linked. |

Parent writes accept `father_name`, `father_work`, `father_phone`, `mother_name`, `mother_phone`, `home_phone`, `address`, `notes`, and `is_active`. The three phone fields are normalized using the same rules as the website, and duplicate profiles are rejected.

### Teachers

| Method | Endpoint | Permission | Notes |
| --- | --- | --- | --- |
| `GET` | `/api/v1/teachers` | `teachers.view` | Paginated; supports `search`, `status`, `course_id`, `is_helping`, and `per_page`. |
| `GET` | `/api/v1/teachers/{teacher}` | `teachers.view` | Includes course, job title, and assigned/assisted group counts. |
| `POST` | `/api/v1/teachers` | `teachers.create` | Creates the profile and linked teacher login transactionally. |
| `PUT/PATCH` | `/api/v1/teachers/{teacher}` | `teachers.update` | Updates the core profile and linked login. |
| `DELETE` | `/api/v1/teachers/{teacher}` | `teachers.delete` | Blocked while assigned or assisted groups exist. |

Teacher writes accept core profile fields plus optional `account_username`, `account_password`, and `account_is_active`. The profile status follows the linked account's active state, matching the website workflow. Advanced role assignment, direct permissions, scope administration, approval decisions, photos, and finance signatures remain website-only administrative workflows in V1.

### Courses

| Method | Endpoint | Permission | Notes |
| --- | --- | --- | --- |
| `GET` | `/api/v1/courses` | `courses.view` | Paginated; supports `search`, `academic_year_id`, `is_active`, and `per_page`. |
| `GET` | `/api/v1/courses/{course}` | `courses.view` | Includes academic year and group/enrollment counts. |
| `POST` | `/api/v1/courses` | `courses.create` | Automatically becomes default when no default course exists. |
| `PUT/PATCH` | `/api/v1/courses/{course}` | `courses.update` | Finished courses are read-only; academic-year changes propagate to their groups. |
| `DELETE` | `/api/v1/courses/{course}` | `courses.delete` | Uses the website's linked-record deletion blockers. |
| `POST` | `/api/v1/courses/{course}/finish` | `courses.update` | Runs the full course lifecycle service. |
| `POST` | `/api/v1/courses/{course}/reactivate` | `courses.update` | Restores lifecycle-managed group, enrollment, assessment, and attendance state. |

Course schedule/calendar editing and course-copy workflows are not part of Phase 3.

### Record details

The existing scoped collections now have matching detail endpoints:

- `GET /api/v1/students/{student}` (`students.view`)
- `GET /api/v1/groups/{group}` (`groups.view`)
- `GET /api/v1/enrollments/{enrollment}` (`enrollments.view`)

Existing student, group, and enrollment update/delete endpoints now reject records outside the authenticated user's access scope with `403`.

## Read Endpoints

### `GET /api/v1/students`

Permission: `students.view`

Query parameters:

| Name | Type | Notes |
| --- | --- | --- |
| `group_id` | integer | Optional |
| `parent_id` | integer | Optional |
| `status` | string | Optional |
| `per_page` | integer | Optional, min 1, max 100 |

Each item in `data`:

```json
{
  "id": 1,
  "student_number": "STU-000001",
  "first_name": "Ahmad",
  "last_name": "Ali",
  "birth_date": "2014-04-10",
  "status": "active",
  "parent": "Samer Ali",
  "grade_level": "Grade 4",
  "current_juz": 3,
  "school_name": "Al Amal School",
  "enrollments_count": 2
}
```

### `GET /api/v1/groups`

Permission: `groups.view`

Query parameters:

| Name | Type | Notes |
| --- | --- | --- |
| `academic_year_id` | integer | Optional |
| `teacher_id` | integer | Optional |
| `is_active` | boolean | Optional |
| `per_page` | integer | Optional, min 1, max 100 |

Each item in `data`:

```json
{
  "id": 1,
  "name": "Boys Hifz Circle",
  "course": "Quran Course",
  "academic_year": "2026",
  "teacher": "Yaman Al-Faisal",
  "assistant_teacher": "Khaled Ali",
  "capacity": 20,
  "starts_on": "2026-01-01",
  "ends_on": "2026-12-31",
  "is_active": true,
  "enrollments_count": 17
}
```

### `GET /api/v1/enrollments`

Permission: `enrollments.view`

Query parameters:

| Name | Type | Notes |
| --- | --- | --- |
| `group_id` | integer | Optional |
| `student_id` | integer | Optional |
| `status` | string | Optional |
| `per_page` | integer | Optional, min 1, max 100 |

Each item in `data`:

```json
{
  "id": 1,
  "status": "active",
  "enrolled_at": "2026-09-01",
  "left_at": null,
  "final_points": 125,
  "memorized_pages": 44,
  "group": {
    "id": 4,
    "name": "Boys Hifz Circle",
    "course_name": "Quran Course"
  },
  "student": {
    "id": 9,
    "full_name": "Ahmad Ali",
    "parent_name": "Samer Ali"
  }
}
```

### `GET /api/v1/assessments`

Permission: `assessments.view`

Query parameters:

| Name | Type | Notes |
| --- | --- | --- |
| `assessment_type_id` | integer | Optional |
| `group_id` | integer | Optional |
| `is_active` | boolean | Optional |
| `per_page` | integer | Optional, min 1, max 100 |

Each item in `data`:

```json
{
  "id": 1,
  "title": "Weekly Quiz",
  "description": "Revision quiz",
  "scheduled_at": "2026-10-05T10:00:00+03:00",
  "due_at": "2026-10-07T18:00:00+03:00",
  "total_mark": 100.0,
  "pass_mark": 60.0,
  "is_active": true,
  "results_count": 15,
  "type": "Quiz",
  "group": {
    "id": 4,
    "name": "Boys Hifz Circle",
    "course_name": "Quran Course"
  }
}
```

### `GET /api/v1/activities`

Permission: `activities.view`

Query parameters:

| Name | Type | Notes |
| --- | --- | --- |
| `group_id` | integer | Optional |
| `is_active` | boolean | Optional |
| `per_page` | integer | Optional, min 1, max 100 |

Each item in `data`:

```json
{
  "id": 1,
  "title": "Family Trip",
  "description": "Monthly activity",
  "activity_date": "2026-10-10",
  "fee_amount": 20.0,
  "expected_revenue": 340.0,
  "collected_revenue": 280.0,
  "expense_total": 95.0,
  "is_active": true,
  "group": {
    "id": 4,
    "name": "Boys Hifz Circle",
    "course_name": "Quran Course"
  }
}
```

### `GET /api/v1/invoices`

Permission: `invoices.view`

Query parameters:

| Name | Type | Notes |
| --- | --- | --- |
| `invoice_type` | string | Optional |
| `parent_id` | integer | Optional |
| `status` | string | Optional |
| `per_page` | integer | Optional, min 1, max 100 |

Each item in `data`:

```json
{
  "id": 1,
  "invoice_no": "INV-000001",
  "invoice_type": "student_fee",
  "issue_date": "2026-10-01",
  "due_date": "2026-10-15",
  "status": "unpaid",
  "total": 50.0,
  "discount": 0.0,
  "paid_total": 20.0,
  "balance": 30.0,
  "items_count": 2,
  "parent": "Samer Ali"
}
```

### `GET /api/v1/reports/overview`

Permission: `reports.view`

Query parameters:

| Name | Type | Notes |
| --- | --- | --- |
| `academic_year_id` | integer | Optional |
| `group_id` | integer | Optional |
| `date_from` | date | Optional |
| `date_to` | date | Optional, must be after or equal to `date_from` |

Response:

```json
{
  "filters": {
    "academic_year_id": 1,
    "assessment_type_id": null,
    "date_from": "2026-09-01",
    "date_to": "2026-09-30",
    "group_id": 4
  },
  "headline": {
    "active_enrollments": 17,
    "cash_collected": 420.0,
    "invoiced_amount": 510.0,
    "memorized_pages": 88,
    "net_points": 950,
    "students_in_scope": 17
  },
  "attendance": {
    "days_recorded": 8,
    "breakdown": [
      {
        "code": "present",
        "count": 90,
        "name": "Present"
      }
    ]
  },
  "assessments": {
    "results_recorded": 24,
    "passed": 18,
    "failed": 6,
    "average_score": 72.5
  },
  "memorization_leaderboard": [],
  "points_leaderboard": [],
  "finance": {
    "activity_collected": 150.0,
    "activity_expenses": 40.0,
    "activity_expected": 200.0,
    "activity_net": 110.0,
    "invoice_billed": 510.0,
    "invoice_collected": 270.0
  },
  "outstanding_invoices": []
}
```

### `GET /api/v1/reports/teachers/daily-summary`

Permission: `reports.view`

Query parameters:

| Name | Type | Notes |
| --- | --- | --- |
| `date` | date | Optional, defaults to today |
| `include_empty` | boolean | Optional |
| `teacher_id` | integer | Optional |

Response:

```json
{
  "date": "2026-09-10",
  "teachers_in_scope": 3,
  "teachers_with_activity": 2,
  "totals": {
    "absences_count": 4,
    "failed_final_attempts_count": 1,
    "failed_partial_attempts_count": 2,
    "memorization_sessions_count": 6,
    "memorized_pages": 18
  },
  "teachers": [
    {
      "teacher": {
        "id": 7,
        "name": "Yaman Al-Faisal"
      },
      "absences_count": 1,
      "failed_final_attempts_count": 0,
      "failed_partial_attempts_count": 1,
      "memorization_sessions_count": 3,
      "memorized_pages": 9,
      "absences": [],
      "memorization_entries": [],
      "failed_partial_attempts": [],
      "failed_final_attempts": []
    }
  ]
}
```

## Master Data Write Endpoints

### `POST /api/v1/students`

Permission: `students.create`

### `PUT|PATCH /api/v1/students/{student}`

Permission: `students.update`

Request body:

```json
{
  "first_name": "Ahmad",
  "last_name": "Ali",
  "birth_date": "2014-04-10",
  "parent_id": 3,
  "status": "active",
  "gender": "male",
  "grade_level_id": 2,
  "joined_at": "2026-09-01",
  "notes": "New student",
  "photo_path": "students/photos/ahmad.jpg",
  "quran_current_juz_id": 3,
  "school_name": "Al Amal School"
}
```

Allowed values:

- `status`: `active`, `inactive`, `graduated`, `blocked`
- `gender`: `male`, `female`

Response:

```json
{
  "id": 1,
  "first_name": "Ahmad",
  "last_name": "Ali",
  "student_number": "STU-000001",
  "birth_date": "2014-04-10",
  "gender": "male",
  "status": "active",
  "parent_id": 3,
  "parent": "Samer Ali",
  "grade_level_id": 2,
  "grade_level": "Grade 4",
  "joined_at": "2026-09-01",
  "notes": "New student",
  "photo_path": "students/photos/ahmad.jpg",
  "quran_current_juz_id": 3,
  "quran_current_juz": 3,
  "school_name": "Al Amal School"
}
```

### `DELETE /api/v1/students/{student}`

Permission: `students.delete`

Response: `204 No Content`

Business rule:

- Returns `422` if the student still has enrollments.

### `POST /api/v1/groups`

Permission: `groups.create`

### `PUT|PATCH /api/v1/groups/{group}`

Permission: `groups.update`

Request body:

```json
{
  "name": "Boys Hifz Circle",
  "academic_year_id": 1,
  "course_id": 2,
  "teacher_id": 7,
  "assistant_teacher_id": 9,
  "capacity": 20,
  "starts_on": "2026-01-01",
  "ends_on": "2026-12-31",
  "grade_level_id": 2,
  "monthly_fee": 15,
  "is_active": true
}
```

Response:

```json
{
  "id": 4,
  "name": "Boys Hifz Circle",
  "academic_year_id": 1,
  "academic_year": "2026",
  "course_id": 2,
  "course": "Quran Course",
  "teacher_id": 7,
  "teacher": "Yaman Al-Faisal",
  "assistant_teacher_id": 9,
  "assistant_teacher": "Khaled Ali",
  "grade_level_id": 2,
  "grade_level": "Grade 4",
  "capacity": 20,
  "monthly_fee": 15.0,
  "starts_on": "2026-01-01",
  "ends_on": "2026-12-31",
  "is_active": true
}
```

### `DELETE /api/v1/groups/{group}`

Permission: `groups.delete`

Response: `204 No Content`

Business rule:

- Returns `422` if the group still has enrollments or schedules.

### `POST /api/v1/enrollments`

Permission: `enrollments.create`

### `PUT|PATCH /api/v1/enrollments/{enrollment}`

Permission: `enrollments.update`

Request body:

```json
{
  "student_id": 1,
  "group_id": 4,
  "enrolled_at": "2026-09-01",
  "status": "active",
  "left_at": null,
  "notes": "Late join"
}
```

Allowed values:

- `status`: `active`, `completed`, `cancelled`

Response:

```json
{
  "id": 8,
  "status": "active",
  "enrolled_at": "2026-09-01",
  "left_at": null,
  "notes": "Late join",
  "final_points": 0,
  "memorized_pages": 0,
  "group": {
    "id": 4,
    "name": "Boys Hifz Circle",
    "course_name": "Quran Course"
  },
  "student": {
    "id": 1,
    "full_name": "Ahmad Ali",
    "parent_name": "Samer Ali"
  }
}
```

### `DELETE /api/v1/enrollments/{enrollment}`

Permission: `enrollments.delete`

Response: `204 No Content`

## Operational Endpoints

Phase 4 adds scoped read models for mobile working screens. Every list supports `per_page` from 1 to 100 and the applicable `date_from`/`date_to` filters.

| Method | Endpoint | Permission | Important filters or behavior |
| --- | --- | --- | --- |
| `GET` | `/api/v1/student-attendance/days` | `attendance.student.view` | `course_id`, `group_id`, `status`, dates; returns scoped group and record counts. |
| `GET` | `/api/v1/student-attendance/days/{day}` | `attendance.student.view` | Returns only accessible groups and their student records. |
| `PATCH` | `/api/v1/student-attendance/days/{day}/status` | `attendance.student.toggle-day-status` | Body: `status=open` or `closed`; archived course days remain read-only. |
| `GET` | `/api/v1/teacher-attendance/days` | `attendance.teacher.view` | `course_id`, `teacher_id`, `status`, dates. |
| `GET` | `/api/v1/teacher-attendance/days/{day}` | `attendance.teacher.view` | Scoped teacher attendance records. |
| `GET` | `/api/v1/memorization` | `memorization.view` | `student_id`, `group_id`, `entry_type`, dates. |
| `GET` | `/api/v1/enrollments/{enrollment}/memorization` | `memorization.view` | History for one accessible enrollment. |
| `GET` | `/api/v1/quran-tests` | `quran-tests.view` or `quran-awqaf-tests.view` | `student_id`, `group_id`, `juz_id`, `status`, dates. |
| `GET` | `/api/v1/enrollments/{enrollment}/quran-tests` | Same as above | Quran-test history for one accessible enrollment. |
| `GET` | `/api/v1/points` | `points.view` | `student_id`, `group_id`, `point_type_id`, `state`, dates. State is `active`, `voided`, or `inactive_source`. |
| `GET` | `/api/v1/enrollments/{enrollment}/points` | `points.view` | Point ledger for one accessible enrollment. |
| `GET` | `/api/v1/assessments/{assessment}` | `assessments.view` | Assessment definition, groups, and scope-filtered results. |

The global and enrollment-specific history endpoints return standard paginated responses. Attendance and assessment detail endpoints return `{ "data": ... }` objects.

### `POST /api/v1/groups/{group}/attendance`

Permission: `attendance.student.take`

Request body:

```json
{
  "attendance_date": "2026-10-05",
  "status": "completed",
  "notes": "Morning session",
  "records": [
    {
      "enrollment_id": 8,
      "attendance_status_id": 1,
      "notes": "On time"
    }
  ]
}
```

Response:

```json
{
  "id": 12,
  "group_id": 4,
  "attendance_date": "2026-10-05",
  "status": "completed",
  "records": [
    {
      "enrollment_id": 8,
      "student_id": 1,
      "student_name": "Ahmad Ali",
      "attendance_status_id": 1,
      "attendance_status_name": "Present",
      "attendance_status_code": "present",
      "notes": "On time"
    }
  ]
}
```

### `POST /api/v1/teacher-attendance`

Permission: `attendance.teacher.take`

`course_id` is required and must refer to an active, unfinished course in the user's scope.
Days are matched by course and date. Closed or archived days cannot be overwritten; reopen them in the attendance screen first.
`status` accepts `open` or `closed` and defaults to `open` for new days.

Request body:

```json
{
  "attendance_date": "2026-10-05",
  "course_id": 4,
  "status": "open",
  "notes": "Daily attendance",
  "records": [
    {
      "teacher_id": 7,
      "attendance_status_id": 5,
      "notes": "Arrived early"
    }
  ]
}
```

Response:

```json
{
  "id": 3,
  "attendance_date": "2026-10-05",
  "course_id": 4,
  "status": "open",
  "records": [
    {
      "teacher_id": 7,
      "teacher_name": "Yaman Al-Faisal",
      "attendance_status_id": 5,
      "attendance_status_name": "Present",
      "attendance_status_code": "present",
      "notes": "Arrived early"
    }
  ]
}
```

### `POST /api/v1/enrollments/{enrollment}/memorization`

Permission: `memorization.record`

Request body:

```json
{
  "entry_type": "new",
  "from_page": 1,
  "to_page": 3,
  "recorded_on": "2026-10-06",
  "teacher_id": 7,
  "notes": "Strong recitation"
}
```

Allowed values:

- `entry_type`: `new`, `review`

Response `201`:

```json
{
  "id": 15,
  "entry_type": "new",
  "from_page": 1,
  "to_page": 3,
  "pages_count": 3,
  "new_pages_count": 3,
  "recorded_on": "2026-10-06",
  "teacher_id": 7,
  "teacher_name": "Yaman Al-Faisal",
  "notes": "Strong recitation"
}
```

Business rule:

- For `entry_type = "new"`, already-achieved pages return `422` with:

```json
{
  "message": "One or more pages were already achieved by this student.",
  "duplicates": [1, 2]
}
```

### `POST /api/v1/enrollments/{enrollment}/quran-tests`

Permission: one of:

- `quran-awqaf-tests.record`
- `quran-tests.record`

Request body:

```json
{
  "juz_id": 3,
  "quran_test_type_id": 4,
  "tested_on": "2026-10-07",
  "teacher_id": 7,
  "status": "passed",
  "score": 95,
  "notes": "Excellent"
}
```

Allowed values:

- `status`: `passed`, `failed`, `cancelled`

Response `201`:

```json
{
  "id": 22,
  "attempt_no": 1,
  "juz_id": 3,
  "juz_number": 3,
  "quran_test_type_id": 4,
  "quran_test_type_name": "Awqaf",
  "score": 95.0,
  "status": "passed",
  "teacher_id": 7,
  "teacher_name": "Yaman Al-Faisal",
  "tested_on": "2026-10-07"
}
```

Current business rules:

- This endpoint currently only accepts the `awqaf` test type.
- If the selected type is `partial` or `final`, it returns `422`.
- Progression rules may also return `422` unless the user has override permissions.

### `POST /api/v1/enrollments/{enrollment}/points/manual`

Permission: `points.create-manual`

Request body:

```json
{
  "point_type_id": 2,
  "points": -3,
  "notes": "Penalty correction"
}
```

Response `201`:

```json
{
  "id": 90,
  "student_id": 1,
  "enrollment_id": 8,
  "point_type_id": 2,
  "point_type_name": "Discipline",
  "points": -3,
  "notes": "Penalty correction",
  "source_type": "manual",
  "entered_at": "2026-10-07T12:30:00+03:00",
  "voided_at": null,
  "voided_by": null
}
```

### `POST /api/v1/points/{pointTransaction}/void`

Permission: `points.void`

Request body: none

Response:

```json
{
  "id": 90,
  "student_id": 1,
  "enrollment_id": 8,
  "point_type_id": 2,
  "point_type_name": "Discipline",
  "points": -3,
  "notes": "Penalty correction",
  "source_type": "manual",
  "entered_at": "2026-10-07T12:30:00+03:00",
  "voided_at": "2026-10-07T13:00:00+03:00",
  "voided_by": 1
}
```

### `POST /api/v1/assessments/{assessment}/results`

Permissions: `assessment-results.record` and `assessment-results.record-scores`

Request body:

```json
{
  "results": [
    {
      "enrollment_id": 8,
      "attempt_no": 1,
      "score": 88,
      "status": "passed",
      "notes": "Good work"
    }
  ]
}
```

Allowed values:

- `status`: `passed`, `failed`, `absent`, `pending`

Response:

```json
{
  "assessment_id": 6,
  "results": [
    {
      "id": 11,
      "enrollment_id": 8,
      "student_id": 1,
      "student_name": "Ahmad Ali",
      "attempt_no": 1,
      "score": 88.0,
      "status": "passed",
      "notes": "Good work"
    }
  ]
}
```

## Student Progress and File Endpoints

Phase 8 provides a consolidated learner snapshot and authenticated document/photo access. Student and enrollment scope checks match the website, and enrollment course data is resolved through each enrollment's group.

| Method | Endpoint | Permission | Result |
| --- | --- | --- | --- |
| `GET` | `/api/v1/students/{student}/progress` | `students.view` | Profile, scoped enrollments, and permission-aware progress metrics |
| `GET` | `/api/v1/students/{student}/files` | `students.view` | Protected student file metadata |
| `POST` | `/api/v1/students/{student}/files` | `students.files.manage` | Upload a student document |
| `GET` | `/api/v1/students/{student}/files/{studentFile}/download` | `students.view` | Protected binary download |
| `DELETE` | `/api/v1/students/{student}/files/{studentFile}` | `students.files.manage` | Remove the database record and stored file |
| `GET` | `/api/v1/students/{student}/photo` | `students.view` | Protected student image response |
| `POST` | `/api/v1/students/{student}/photo` | `students.photo.update` | Replace the student photo |
| `DELETE` | `/api/v1/students/{student}/photo` | `students.photo.update` | Remove the student photo |

The progress response always includes the student profile and scoped enrollment history. Metrics for attendance, assessment results, points, notes, and structured Quran tests are returned as `null` when the authenticated user lacks the matching module permission; they are never inferred from inaccessible records.

File uploads use multipart fields `file_type` and `file`, with a 10 MB maximum. Photo uploads use multipart field `photo`, accept JPG, JPEG, PNG, or WebP, and use the configured image-size limit. Uploaded blobs are cleaned up if database persistence fails, and old photos are removed only after the replacement path is committed.

## Advanced Quran Evaluation Endpoints

Phase 7 exposes the website's structured partial and final Quran-test workflow. These endpoints call the existing domain services, so eligibility, attempt numbering, pass rules, point awards, and progression dates remain identical between web and mobile.

| Method | Endpoint | Permission | Result |
| --- | --- | --- | --- |
| `GET` | `/api/v1/quran-partial-tests` | `quran-partial-tests.view` | Paginated scoped partial-test cycles |
| `GET` | `/api/v1/quran-partial-tests/{partialTest}` | `quran-partial-tests.view` | Four parts and their attempt history |
| `GET` | `/api/v1/enrollments/{enrollment}/quran-partial-tests/eligible-juz` | `quran-partial-tests.record` | Juz values eligible to start |
| `POST` | `/api/v1/enrollments/{enrollment}/quran-partial-tests` | `quran-partial-tests.record` | Start a four-part cycle |
| `POST` | `/api/v1/quran-partial-tests/{partialTest}/parts/{part}/attempts` | `quran-partial-tests.record` | Record and evaluate a part attempt |
| `GET` | `/api/v1/quran-final-tests` | `quran-final-tests.view` | Paginated scoped final-test cycles |
| `GET` | `/api/v1/quran-final-tests/{finalTest}` | `quran-final-tests.view` | Final-test attempt history |
| `GET` | `/api/v1/enrollments/{enrollment}/quran-final-tests/eligible-juz` | `quran-final-tests.record` | Juz values unlocked by passed partial cycles |
| `POST` | `/api/v1/enrollments/{enrollment}/quran-final-tests` | `quran-final-tests.record` | Start a final-test cycle |
| `POST` | `/api/v1/quran-final-tests/{finalTest}/attempts` | `quran-final-tests.record` | Record and evaluate a final attempt |

List endpoints accept `student_id`, `group_id`, `juz_id`, `status=in_progress|passed`, and `per_page`. Starting a partial cycle accepts `juz_id` and optional `allow_parallel_cycle`; the latter should only be enabled after the user explicitly confirms the website-equivalent open-cycle warning.

Partial attempts require `tested_on` and `mistake_count` from 0 to 999. Final attempts require `tested_on` and `score` from 0 to 100. Both accept optional `notes` and `teacher_id`. A teacher-authenticated token always records its own teacher profile; a manager must supply an active teacher who can record the matching test type.

A partial cycle is passed only after all four parts pass. A final cycle is passed only when an attempt reaches the configured passing range. The services set `passed_on` from the qualifying attempt date and create the same point-ledger entries used by the website.

## Academic Support Endpoints

Phase 6 exposes the remaining academic-support workflows used by teachers and managers. Group, student, enrollment, note-visibility, and curriculum access rules match the website.

| Method | Endpoint | Permission | Result |
| --- | --- | --- | --- |
| `GET` | `/api/v1/groups/{group}/schedules` | `groups.view` | Ordered group schedule |
| `POST` | `/api/v1/groups/{group}/schedules` | `group-schedules.manage` | Create a schedule slot |
| `PUT|PATCH` | `/api/v1/groups/{group}/schedules/{schedule}` | `group-schedules.manage` | Update a schedule slot |
| `DELETE` | `/api/v1/groups/{group}/schedules/{schedule}` | `group-schedules.manage` | Delete a schedule slot |
| `GET` | `/api/v1/student-notes` | `student-notes.view` | Paginated notes visible to the user |
| `GET` | `/api/v1/student-notes/{studentNote}` | `student-notes.view` | One visible student note |
| `POST` | `/api/v1/student-notes` | `student-notes.create` | Create a student note |
| `PUT|PATCH` | `/api/v1/student-notes/{studentNote}` | `student-notes.update` | Update an owned or manager-accessible note |
| `DELETE` | `/api/v1/student-notes/{studentNote}` | `student-notes.delete` | Delete an owned or manager-accessible note |
| `GET` | `/api/v1/curricula` | Curriculum access | Paginated curriculum catalog |
| `GET` | `/api/v1/curricula/{curriculum}` | Curriculum access | Subjects, lessons, topics, and resources |
| `GET` | `/api/v1/groups/{group}/curriculum` | Curriculum access | Group curriculum and teaching progress |
| `GET` | `/api/v1/curriculum-resources/{resource}/download` | Curriculum access | Protected PDF download |

Schedule bodies contain `day_of_week` from 0 through 6 and one of the configured `time_slot` keys. A group must retain at least one schedule, and archived-course groups cannot be edited.

Student-note lists accept `student_id`, `enrollment_id`, `source`, `visibility`, `date_from`, `date_to`, and `per_page`. Teachers can only create teacher-source notes, cannot use management-only visibility, cannot see private management notes, and can update or delete only their own notes. A note's student and enrollment cannot be reassigned during an update.

Curriculum catalog lists accept `search`, `grade_level_id`, `is_active`, and `per_page`. Managers can access all curricula; eligible curriculum-recording teachers can access only curricula assigned to their scoped groups.

## Finance Endpoints

Phase 5 adds the finance reads needed by the mobile application. Results are limited by the authenticated user's group and student access. Managers retain full access; scoped users receive `403` when they request an activity or invoice outside their allowed records.

| Method | Endpoint | Permission | Result |
| --- | --- | --- | --- |
| `GET` | `/api/v1/activities/{activity}` | `activities.view` | Activity, audience, target groups, and cached finance totals |
| `GET` | `/api/v1/activities/{activity}/registrations` | `activities.finance.view` or `activities.registrations.manage` | Paginated registrations with paid totals and balances |
| `GET` | `/api/v1/activities/{activity}/payments` | `activities.finance.view` or `activities.payments.manage` | Paginated activity payments |
| `GET` | `/api/v1/activities/{activity}/expenses` | `activities.finance.view` or `activities.expenses.manage` | Paginated activity expenses |
| `GET` | `/api/v1/invoices/{invoice}` | `invoices.view` | Invoice detail with scoped items, payments, paid total, and balance |
| `GET` | `/api/v1/invoices/{invoice}/payments` | `invoices.view` | Paginated payments for one invoice |
| `GET` | `/api/v1/payments` | `payments.view` | Paginated invoice payments available to the user |

Activity registration lists accept `status` and `student_id`. Payment lists accept `state=active|voided`, `date_from`, and `date_to`; the global payment list also accepts `invoice_id` and `payment_method_id`. Expense lists accept `expense_category_id`, `date_from`, and `date_to`. Every list accepts `per_page` from 1 to 100.

Finance write endpoints below enforce the same record scopes as their matching reads. Their existing domain services remain responsible for recalculating totals and synchronizing finance ledger entries.

### `POST /api/v1/activities/{activity}/registrations`

Permission: `activities.registrations.manage`

### `PUT|PATCH /api/v1/activities/{activity}/registrations/{registration}`

Permission: `activities.registrations.manage`

Request body:

```json
{
  "student_id": 1,
  "enrollment_id": 8,
  "fee_amount": 20,
  "status": "registered",
  "notes": "Family confirmed"
}
```

Allowed values:

- `status`: `registered`, `declined`, `cancelled`, `attended`

Response:

```json
{
  "id": 5,
  "activity_id": 3,
  "student_id": 1,
  "student_name": "Ahmad Ali",
  "enrollment_id": 8,
  "fee_amount": 20.0,
  "status": "registered",
  "notes": "Family confirmed"
}
```

### `DELETE /api/v1/activities/{activity}/registrations/{registration}`

Permission: `activities.registrations.manage`

Response: `204 No Content`

Business rule:

- Returns `422` if active payments still exist.

### `POST /api/v1/activities/{activity}/payments`

Permission: `activities.payments.manage`

Request body:

```json
{
  "activity_registration_id": 5,
  "amount": 20,
  "paid_at": "2026-10-08",
  "payment_method_id": 1,
  "reference_no": "REF-100",
  "notes": "Cash"
}
```

Response `201`:

```json
{
  "id": 14,
  "activity_registration_id": 5,
  "student_id": 1,
  "amount": 20.0,
  "paid_at": "2026-10-08",
  "payment_method_id": 1,
  "payment_method_name": "Cash",
  "reference_no": "REF-100",
  "voided_at": null,
  "voided_by": null
}
```

### `POST /api/v1/activities/{activity}/payments/{activityPayment}/void`

Permission: `activities.payments.manage`

Request body: none

Response:

```json
{
  "id": 14,
  "activity_registration_id": 5,
  "student_id": 1,
  "amount": 20.0,
  "paid_at": "2026-10-08",
  "payment_method_id": 1,
  "payment_method_name": "Cash",
  "reference_no": "REF-100",
  "voided_at": "2026-10-08T15:00:00+03:00",
  "voided_by": 1
}
```

### `POST /api/v1/activities/{activity}/expenses`

Permission: `activities.expenses.manage`

### `PUT|PATCH /api/v1/activities/{activity}/expenses/{activityExpense}`

Permission: `activities.expenses.manage`

Request body:

```json
{
  "amount": 45,
  "description": "Bus rental",
  "expense_category_id": 2,
  "spent_on": "2026-10-08"
}
```

Response:

```json
{
  "id": 6,
  "activity_id": 3,
  "amount": 45.0,
  "description": "Bus rental",
  "expense_category_id": 2,
  "expense_category_name": "Transport",
  "spent_on": "2026-10-08"
}
```

### `DELETE /api/v1/activities/{activity}/expenses/{activityExpense}`

Permission: `activities.expenses.manage`

Response: `204 No Content`

### `POST /api/v1/invoices/{invoice}/items`

Permission: `invoices.update`

### `PUT|PATCH /api/v1/invoices/{invoice}/items/{invoiceItem}`

Permission: `invoices.update`

Request body:

```json
{
  "description": "Tuition fee",
  "quantity": 1,
  "unit_price": 50,
  "student_id": 1,
  "enrollment_id": 8,
  "activity_id": null
}
```

Response:

```json
{
  "id": 18,
  "invoice_id": 4,
  "student_id": 1,
  "student_name": "Ahmad Ali",
  "enrollment_id": 8,
  "activity_id": null,
  "description": "Tuition fee",
  "quantity": 1.0,
  "unit_price": 50.0,
  "amount": 50.0
}
```

Business rules:

- If `student_id` is supplied, the student must belong to the invoice parent.
- If `enrollment_id` is supplied, the enrollment must belong to the selected student and invoice parent.

### `DELETE /api/v1/invoices/{invoice}/items/{invoiceItem}`

Permission: `invoices.update`

Response: `204 No Content`

### `POST /api/v1/invoices/{invoice}/payments`

Permission: `payments.create`

Request body:

```json
{
  "amount": 30,
  "paid_at": "2026-10-08",
  "payment_method_id": 1,
  "reference_no": "PAY-300",
  "notes": "Partial payment"
}
```

Response `201`:

```json
{
  "id": 21,
  "invoice_id": 4,
  "amount": 30.0,
  "paid_at": "2026-10-08",
  "payment_method_id": 1,
  "payment_method_name": "Cash",
  "reference_no": "PAY-300",
  "voided_at": null,
  "voided_by": null
}
```

### `POST /api/v1/invoices/{invoice}/payments/{payment}/void`

Permission: `payments.void`

Request body: none

Response:

```json
{
  "id": 21,
  "invoice_id": 4,
  "amount": 30.0,
  "paid_at": "2026-10-08",
  "payment_method_id": 1,
  "payment_method_name": "Cash",
  "reference_no": "PAY-300",
  "voided_at": "2026-10-08T16:00:00+03:00",
  "voided_by": 1
}
```

## Finance Operations Endpoints

Phase 9 exposes the website's central cash-management workflow. Balances and history come from `finance_transactions`; finance requests describe approval workflow and do not replace the ledger.

| Method | Endpoint | Permission | Result |
| --- | --- | --- | --- |
| `GET` | `/api/v1/finance/summary` | `finance.reports.view` or `finance.cash-box.view` | Cash-box balances, totals by currency, and visible pending request counts |
| `GET` | `/api/v1/finance/cash-boxes` | `finance.cash-box.view` | Accessible boxes with per-currency balances and local equivalents |
| `GET` | `/api/v1/finance/transactions` | `finance.cash-box.view` | Paginated unified ledger |
| `GET` | `/api/v1/finance/transactions/{transaction}` | `finance.cash-box.view` | One accessible ledger row |
| `POST` | `/api/v1/finance/adjustments` | `finance.cash-box.adjust` | Post a manual in/out adjustment |
| `GET` | `/api/v1/finance/transfers` | `finance.cash-box.view` | Paginated cash-box transfer history |
| `POST` | `/api/v1/finance/transfers` | `finance.cash-box.transfer` | Transfer one currency between two boxes |
| `GET` | `/api/v1/finance/exchanges` | `finance.exchange.view` | Paginated currency-exchange history |
| `POST` | `/api/v1/finance/exchanges` | `finance.exchange.create` | Exchange currencies, optionally using a manually supplied destination amount |
| `GET` | `/api/v1/finance/requests` | Matching request `view` permission | Paginated pull, expense, revenue, and return workflow records |
| `GET` | `/api/v1/finance/requests/{financeRequest}` | Matching request `view` permission | One finance request |

Transaction filters are `cash_box_id`, `currency_id`, `type`, `direction=in|out`, `date_from`, `date_to`, and `per_page`. Transfer and exchange lists accept the date and pagination filters. Finance-request lists accept `type`, `status`, and `per_page`.

Adjustment bodies require `cash_box_id`, `currency_id`, `direction`, `amount`, and `description`; `transaction_date` defaults to today. Transfers require different source and destination boxes, a currency supported by both boxes, a positive amount, and `transfer_date`. Exchanges require source/destination boxes, different currencies supported by their selected boxes, a positive `from_amount`, and `exchange_date`. If `to_amount` is omitted, the configured rates calculate it.

The existing finance feature settings can disable manual adjustments or cash-box transfers. Service-level balance checks, currency compatibility, normalization, numbering, snapshots, and paired ledger entries remain authoritative, so invalid outgoing operations return validation errors without partial writes.

## Route Discovery

The importable Postman collection is checked against the routes registered by Laravel. To inspect the authoritative current route list directly, run:

```powershell
php artisan route:list --path=api/v1
```

The collection also contains the legacy `/api/user` request and two API-documentation links.
