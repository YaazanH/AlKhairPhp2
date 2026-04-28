# Teacher Daily Summary to Telegram

This workflow sends one daily Telegram digest for teachers, using the app API as the data source.

## What it sends

For the target date, the workflow sends a teacher-grouped summary with:

- student absences
- memorization sessions and total memorized pages
- failed partial test attempts
- failed final test attempts

## Required app access

Use an API token from an active user that has:

- `reports.view`

In practice, a `manager`, `admin`, or `super_admin` account is the simplest choice.

## Create the API token

PowerShell example:

```powershell
$body = @{
    device_name = 'n8n-teacher-daily-summary'
    login = 'YOUR_USERNAME_OR_EMAIL_OR_PHONE'
    password = 'YOUR_PASSWORD'
} | ConvertTo-Json

$response = Invoke-RestMethod `
    -Method POST `
    -Uri 'https://alkheir-mosque.com/api/v1/auth/token' `
    -ContentType 'application/json' `
    -Body $body

$response.token
```

The returned `token` goes into the workflow `Config` node.

## Import and configure

1. Import `teacher-daily-summary-telegram.json` into n8n.
2. Open the `Config` node and set:
   - `appBaseUrl`
   - `apiToken`
   - `telegramChatId`
3. Open the `Send to Telegram Group` node and select your Telegram bot credential.
4. Confirm the schedule in `Daily Schedule`.
   - It is set to `20:00` every day in `Asia/Damascus`.
5. Activate the workflow.

## Telegram requirements

- Add the bot to the target group or channel.
- Make sure the bot is allowed to post messages there.
- `telegramChatId` can be a numeric chat ID or a public channel username like `@yourchannel`.

## Report date

The workflow sends the summary for the current day using:

```text
{{$now.setZone('Asia/Damascus').toFormat('yyyy-MM-dd')}}
```

If you want the message to run after midnight for the previous day, change the `date` query expression in `Fetch Daily Summary` to:

```text
{{$now.setZone('Asia/Damascus').minus({ days: 1 }).toFormat('yyyy-MM-dd')}}
```
