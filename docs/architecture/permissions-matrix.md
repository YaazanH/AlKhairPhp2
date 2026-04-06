# Alkhair App Permissions Baseline

Current implementation status on April 4, 2026:

- management module permissions for `parents`, `teachers`, `students`, `courses`, `groups`, and `enrollments` are enforced
- contextual media and schedule pages for `students/{student}/files` and `groups/{group}/schedules` are enforced
- `admin` and `manager` receive those permissions in the seeder
- `teacher`, `parent`, and `student` remain dashboard-only in the current build until ownership-scoped policies are implemented
- the broader matrix below is still the target design for later phases

Use permission names, not role checks, in policies, controllers, Livewire components, and Blade.

## Permission Naming Convention

Use dot-based names:

- `dashboard.admin.view`
- `students.view`
- `students.create`
- `students.update`
- `students.delete`
- `students.photo.update`
- `students.files.manage`
- `students.notes.private-teacher.view`
- `students.notes.private-management.view`
- `students.notes.shared.create`
- `enrollments.manage`
- `attendance.student.take`
- `attendance.teacher.take`
- `memorization.record`
- `memorization.override-duplicate-page`
- `quran-tests.record`
- `quran-tests.override-progression`
- `points.view`
- `points.create-manual`
- `points.void`
- `finance.invoices.manage`
- `finance.payments.manage`
- `activities.manage`
- `settings.manage`
- `users.manage`
- `roles.manage`
- `reports.view`
- `api.tokens.manage`

## Baseline Roles

### Admin

- full access to all permissions
- manages users, roles, settings, finance, reporting, and API clients

### Manager

- near-full business access
- no role administration by default
- no dangerous system setting changes by default
- can override progression and duplicate page checks

### Teacher

- access limited to assigned groups and their active enrollments
- can take student attendance
- can record memorization and Quran tests
- can enter assessment results
- can create teacher-visible notes
- can add allowed manual points if business wants this

### Parent

- read-only access to own children
- can view dashboards, attendance, memorization summary, invoices, and payments
- can add parent-origin notes if enabled

### Student

- read-only access to own dashboard
- can view personal attendance, points, memorization, assessments, and calendar

## Suggested Permission Mapping

### Admin

- `*`

### Manager

- `dashboard.admin.view`
- `dashboard.manager.view`
- `students.view`
- `students.create`
- `students.update`
- `students.photo.update`
- `students.files.manage`
- `students.notes.private-management.view`
- `students.notes.private-management.create`
- `students.notes.shared.view`
- `students.notes.shared.create`
- `teachers.view`
- `teachers.create`
- `teachers.update`
- `parents.view`
- `parents.create`
- `parents.update`
- `courses.view`
- `courses.create`
- `courses.update`
- `groups.view`
- `groups.create`
- `groups.update`
- `group-schedules.manage`
- `enrollments.manage`
- `attendance.student.take`
- `attendance.student.view`
- `attendance.teacher.take`
- `attendance.teacher.view`
- `memorization.record`
- `memorization.view`
- `memorization.override-duplicate-page`
- `quran-tests.record`
- `quran-tests.view`
- `quran-tests.override-progression`
- `assessments.manage`
- `assessment-results.manage`
- `points.view`
- `points.create-manual`
- `points.void`
- `finance.invoices.manage`
- `finance.payments.manage`
- `activities.manage`
- `reports.view`
- `api.tokens.manage`

### Teacher

- `dashboard.teacher.view`
- `students.view`
- `groups.view`
- `groups.my.view`
- `enrollments.view`
- `attendance.student.take`
- `attendance.student.view`
- `memorization.record`
- `memorization.view`
- `quran-tests.record`
- `quran-tests.view`
- `assessments.view`
- `assessment-results.manage`
- `points.view`
- `students.notes.private-teacher.view`
- `students.notes.private-teacher.create`
- `students.notes.shared.view`
- `students.notes.shared.create`
- `calendar.view`

### Parent

- `dashboard.parent.view`
- `students.my.view`
- `attendance.my-children.view`
- `memorization.my-children.view`
- `quran-tests.my-children.view`
- `assessments.my-children.view`
- `points.my-children.view`
- `finance.invoices.my-family.view`
- `finance.payments.my-family.view`
- `calendar.view`
- `students.notes.parent.create`

### Student

- `dashboard.student.view`
- `attendance.self.view`
- `memorization.self.view`
- `quran-tests.self.view`
- `assessments.self.view`
- `points.self.view`
- `calendar.view`

## Policy Notes

- Teachers must be restricted by ownership or assignment, not only by permission name.
- Parents must be restricted to students under their own `parent_id`.
- Students must only access data linked to their own student profile.
- Managers can have broad business permissions without becoming full system admins.
- Admin override should use a global gate rule instead of controller-specific exceptions.
