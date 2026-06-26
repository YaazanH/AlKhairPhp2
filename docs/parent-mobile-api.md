# Parent Mobile API

Base URL: `/api/v1`

Authentication uses Laravel Sanctum bearer tokens. The parent can log in with username, email, or phone.

## Login

`POST /auth/token`

Body:

```json
{
  "device_name": "Parent Mobile",
  "login": "parent-phone-or-username",
  "password": "password"
}
```

Use the returned token as:

```text
Authorization: Bearer <token>
```

## Parent Endpoints

All endpoints below require `Authorization: Bearer <token>`.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/parent/profile` | Parent profile, user info, and child summaries. |
| GET | `/parent/summary` | Dashboard totals for children, pages, points, invoices, and activity responses. |
| GET | `/parent/children` | All children linked to the logged-in parent. |
| GET | `/parent/children/{student}` | One child profile and active enrollment summary. |
| GET | `/parent/children/{student}/attendance` | Attendance records. |
| GET | `/parent/children/{student}/memorization` | Memorization sessions and pages. |
| GET | `/parent/children/{student}/points` | Point ledger entries. |
| GET | `/parent/children/{student}/assessments` | Assessment results. |
| GET | `/parent/children/{student}/quran-tests` | Quran test history, including legacy, partial, and final tests. |
| GET | `/parent/children/{student}/notes` | Notes marked `visible_to_parent`. |
| GET | `/parent/invoices` | Parent invoices with paid and balance totals. |
| GET | `/parent/invoices/{invoice}` | Invoice detail with items and payments. |
| GET | `/parent/activities` | Active activities eligible for the parent's children. |
| POST | `/parent/activities/{activity}/responses` | Register or decline an activity for a child. |
| DELETE | `/auth/token` | Revoke the current token. |

## Common Filters

List endpoints support:

```text
date_from=2026-09-01
date_to=2026-09-30
per_page=25
page=1
```

Additional filters:

| Endpoint | Extra filter |
| --- | --- |
| `/parent/children/{student}/memorization` | `entry_type=new`, `review`, or `correction` |
| `/parent/invoices` | `status=sent`, `paid`, etc. |

## Activity Response

`POST /parent/activities/{activity}/responses`

Body:

```json
{
  "student_id": 1,
  "response": "registered"
}
```

Allowed responses are `registered` and `declined`. Declining is blocked after a non-voided activity payment exists.

## Scope Rules

The API only returns records for the authenticated parent profile:

- Children must belong to the parent.
- Invoices must belong to the parent.
- Activity responses require the child to be eligible for the activity audience.
- Notes are returned only when `visibility` is `visible_to_parent`.

## Postman

Import `docs/postman/AlKhair Parent Mobile API.postman_collection.json`.

Set these collection variables:

| Variable | Example |
| --- | --- |
| `base_url` | `http://localhost:8000/api/v1` |
| `login` | Parent phone, username, or email |
| `password` | Parent password |
| `student_id` | Child ID from `/parent/children` |
| `invoice_id` | Invoice ID from `/parent/invoices` |
| `activity_id` | Activity ID from `/parent/activities` |
