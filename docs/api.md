# AlKhair API Reference

This document reflects the API routes currently defined in the codebase as of 2026-06-23.

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

### Authenticated user

`GET /api/user`

Response: the authenticated Laravel user object.

## Important Auth Note

API login returns the authenticated user's complete effective application permission set in both `abilities` and `user.permissions`. This is the same permission set used by the website, including permissions inherited through roles and permissions assigned directly to the user.

Current API controllers authorize requests against the user's current application permissions, such as `students.view`, `memorization.record`, and `attendance.student.take`. The token abilities are a login-time snapshot for clients; data-scope checks and endpoint-specific business rules are enforced separately.

## Common Behavior

- Most list endpoints return Laravel paginator JSON.
- Common errors:
  - `401` unauthenticated
  - `403` forbidden or inactive account
  - `422` validation or business-rule error
- Most protected endpoints are also scope-aware, so a user may have permission but still be blocked from records outside their scope.

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

Request body:

```json
{
  "attendance_date": "2026-10-05",
  "status": "completed",
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
  "status": "completed",
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

## Finance Endpoints

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

## Route Summary

Current API routes defined in `routes/api.php`:

```text
GET     /api/user
POST    /api/v1/auth/token
DELETE  /api/v1/auth/token

GET     /api/v1/reports/overview
GET     /api/v1/reports/teachers/daily-summary

GET     /api/v1/students
GET     /api/v1/groups
GET     /api/v1/enrollments
GET     /api/v1/assessments
GET     /api/v1/activities
GET     /api/v1/invoices

POST    /api/v1/students
PUT     /api/v1/students/{student}
PATCH   /api/v1/students/{student}
DELETE  /api/v1/students/{student}

POST    /api/v1/groups
PUT     /api/v1/groups/{group}
PATCH   /api/v1/groups/{group}
DELETE  /api/v1/groups/{group}

POST    /api/v1/enrollments
PUT     /api/v1/enrollments/{enrollment}
PATCH   /api/v1/enrollments/{enrollment}
DELETE  /api/v1/enrollments/{enrollment}

POST    /api/v1/groups/{group}/attendance
POST    /api/v1/teacher-attendance
POST    /api/v1/enrollments/{enrollment}/memorization
POST    /api/v1/enrollments/{enrollment}/quran-tests
POST    /api/v1/enrollments/{enrollment}/points/manual
POST    /api/v1/points/{pointTransaction}/void
POST    /api/v1/assessments/{assessment}/results

POST    /api/v1/activities/{activity}/registrations
PUT     /api/v1/activities/{activity}/registrations/{registration}
PATCH   /api/v1/activities/{activity}/registrations/{registration}
DELETE  /api/v1/activities/{activity}/registrations/{registration}

POST    /api/v1/activities/{activity}/payments
POST    /api/v1/activities/{activity}/payments/{activityPayment}/void

POST    /api/v1/activities/{activity}/expenses
PUT     /api/v1/activities/{activity}/expenses/{activityExpense}
PATCH   /api/v1/activities/{activity}/expenses/{activityExpense}
DELETE  /api/v1/activities/{activity}/expenses/{activityExpense}

POST    /api/v1/invoices/{invoice}/items
PUT     /api/v1/invoices/{invoice}/items/{invoiceItem}
PATCH   /api/v1/invoices/{invoice}/items/{invoiceItem}
DELETE  /api/v1/invoices/{invoice}/items/{invoiceItem}

POST    /api/v1/invoices/{invoice}/payments
POST    /api/v1/invoices/{invoice}/payments/{payment}/void
```
