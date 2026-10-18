# AlKhair Mobile API Postman Collection

Import both JSON files into Postman, select **AlKhair Mobile - Local**, set `login` and `password`, then run **01 Authentication / Login - Create Token**. The login request stores the Sanctum token automatically. Collection pre-request logic supplies today's date and current-month range when the date variables are empty.

The collection's **01 Authentication** folder also contains the Phase 1 bootstrap, dashboard, profile, password-change, current-device logout, and all-device logout requests. Set `locale`, `new_username`, and `new_password` only when exercising those optional requests.

The **02 Reference Data** folder contains the Phase 2 lookup catalogs used to populate mobile selectors. Requests include optional inactive, search, scope, and workflow-specific filters where supported.

The **03 Core People and Academics** folder contains Phase 3 parent, teacher, and course list/detail/CRUD requests, course finish/reactivation actions, and student/group/enrollment detail requests. Create requests automatically save returned IDs into the selected Postman environment. Parent and teacher APIs create or synchronize linked login accounts but never return generated or submitted passwords.

The **08 Attendance Learning Points** folder now contains the Phase 4 daily-operation reads and writes. It covers scoped student and teacher attendance days, memorization history, Quran-test history, point ledgers, assessment results, attendance scanning, and the existing record actions. Set `student_attendance_day_id` and `teacher_attendance_day_id` from the corresponding list responses when testing details.

The **09 Finance and Activities** folder contains the Phase 5 finance workflow. It covers scoped activity details, registrations, payments, expenses, invoice details and payments, plus the existing activity and invoice write actions. Read lists include disabled example filters for dates, state, student, payment method, and pagination.

The **10 Academic Support** folder contains the Phase 6 schedule, student-note, and curriculum workflow. It includes scoped group-schedule CRUD, scoped student-note CRUD, curriculum catalogs and details, group curriculum progress, and authenticated curriculum-resource downloads. Create requests save `group_schedule_id` and `student_note_id` automatically.

The **11 Advanced Quran Evaluations** folder contains the Phase 7 partial and final Quran-test cycles. It includes eligible-juz discovery, scoped lists and details, cycle creation, four-part attempt recording, and final-test attempt recording. Create and detail requests save the current test and part IDs automatically. Teachers may omit `teacher_id`; managers select an eligible recording teacher.

The **12 Student Progress and Files** folder contains the Phase 8 learner-profile workflow. It includes a consolidated progress snapshot, protected student-file list/upload/download/delete operations, and protected student-photo view/update/delete operations. Choose local files in Postman's multipart body before sending upload requests.

The **13 Finance Operations** folder contains the Phase 9 cash-management workflow. It includes cash-box balances, the unified transaction ledger, manual adjustments, paired cash-box transfers, paired currency exchanges, and pull/expense/revenue/return request reads. Run **List Cash Boxes and Balances** first to populate available cash-box and currency variables automatically. A transfer still requires at least two configured cash boxes, and an exchange requires two supported currencies. All posted movements use the website's existing finance service and ledger rules.

## Base URL

For SaaS `v2`, set `base_url` to the configured tenant hostname, such as
`https://school.example.com` (or `http://school.localhost:8000` locally).
Use that same hostname for login and every authenticated request. The platform
hostname, a bare server IP, and tenant-ID headers do not select a tenant.
Run **01 Authentication / Tenant Capabilities** to discover enabled modules,
available permissions, and required tenant setup. HTTP 409 may require setup or
a password change before operational endpoints become available.

- Local browser or iOS simulator: `http://127.0.0.1:8000`
- Android emulator: `http://10.0.2.2:8000`
- Physical phone: `http://YOUR_COMPUTER_LAN_IP:8000`

For an emulator or phone, start Laravel on all interfaces:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

Allow TCP port 8000 through Windows Firewall when testing from a physical device. The phone and computer must be on the same network.

## Authentication and permissions

The API uses Laravel Sanctum bearer tokens. A user must be active and have API-enabled permissions. Each request description identifies its required permission. A parent account also needs an active linked parent profile.

Disabling a user immediately blocks all previously issued tokens. Mobile applications should store tokens in iOS Keychain or Android Keystore-backed secure storage, avoid logging them, and use HTTPS outside local development.

## Variables

List endpoints help discover record IDs. Put those IDs into the environment variables before running detail or write requests. Disabled query parameters are examples; enable only the filters needed for a request.

For a clean Phase 3 run, create a course before a teacher, and create parent/student/group records before an enrollment. Course deletion and parent/teacher deletion intentionally return `422` while dependent records exist.

All write requests use JSON and include realistic example bodies. Destructive requests are included but should be run carefully against non-production data.

## Recommended handoff smoke test

1. Import the collection and environment, select the environment, and configure `base_url`, `login`, and `password`.
2. Run **Login - Create Token**, **Bootstrap Mobile App**, and **Dashboard**.
3. Run the read-only list/detail requests needed by the mobile role being tested.
4. Run **List Cash Boxes and Balances** before any Phase 9 finance write request.
5. Exercise write and destructive requests only against local or staging data.
6. Confirm logout last; it revokes the token used by subsequent requests.

