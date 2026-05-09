#!/usr/bin/env bash
set -euo pipefail

# Reset live test data while keeping application settings and master setup data.
#
# Intended use on the Linux production server from the Laravel project root:
#   bash scripts/reset-live-test-data.sh --dry-run
#   bash scripts/reset-live-test-data.sh --execute RESET_LIVE_TEST_DATA
#
# Optional:
#   PROTECTED_ROLE_NAMES=super_admin,admin,manager bash scripts/reset-live-test-data.sh --dry-run
#   KEEP_USER_EMAILS=owner@example.com,api@example.com bash scripts/reset-live-test-data.sh --execute RESET_LIVE_TEST_DATA
#   bash scripts/reset-live-test-data.sh --execute RESET_LIVE_TEST_DATA --include-community-contacts
#   bash scripts/reset-live-test-data.sh --execute RESET_LIVE_TEST_DATA --delete-operational-files

MODE=""
CONFIRM_TOKEN=""
INCLUDE_COMMUNITY_CONTACTS=0
DELETE_OPERATIONAL_FILES=0

usage() {
    sed -n '4,14p' "$0" | sed 's/^# \{0,1\}//'
}

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --dry-run)
            MODE="dry-run"
            shift
            ;;
        --execute)
            MODE="execute"
            CONFIRM_TOKEN="${2:-}"
            shift 2
            ;;
        --include-community-contacts)
            INCLUDE_COMMUNITY_CONTACTS=1
            shift
            ;;
        --delete-operational-files)
            DELETE_OPERATIONAL_FILES=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            fail "Unknown argument: $1"
            ;;
    esac
done

[ -n "$MODE" ] || { usage; exit 1; }

if [ "$MODE" = "execute" ] && [ "$CONFIRM_TOKEN" != "RESET_LIVE_TEST_DATA" ]; then
    fail "Execution requires: --execute RESET_LIVE_TEST_DATA"
fi

[ -f artisan ] || fail "Run this script from the Laravel project root."
[ -f .env ] || fail ".env was not found."

command -v mysql >/dev/null 2>&1 || fail "mysql client is required."
command -v mysqldump >/dev/null 2>&1 || fail "mysqldump is required."

env_value() {
    local key="$1"
    local raw

    raw="$(grep -E "^${key}=" .env | tail -n 1 | sed -E "s/^${key}=//" || true)"
    raw="${raw%$'\r'}"
    raw="${raw%\"}"
    raw="${raw#\"}"
    raw="${raw%\'}"
    raw="${raw#\'}"

    printf '%s' "$raw"
}

sql_escape() {
    printf "%s" "$1" | sed "s/'/''/g"
}

APP_ENV="$(env_value APP_ENV)"
DB_CONNECTION="$(env_value DB_CONNECTION)"
DB_DATABASE="$(env_value DB_DATABASE)"
DB_HOST="$(env_value DB_HOST)"
DB_PORT="$(env_value DB_PORT)"
DB_SOCKET="$(env_value DB_SOCKET)"
DB_USERNAME="$(env_value DB_USERNAME)"
DB_PASSWORD="$(env_value DB_PASSWORD)"

[ -n "$DB_DATABASE" ] || fail "DB_DATABASE is empty."
[ -n "$DB_USERNAME" ] || fail "DB_USERNAME is empty."

case "$DB_CONNECTION" in
    mysql|mariadb|"")
        ;;
    *)
        fail "This live reset script supports MySQL/MariaDB only. Current DB_CONNECTION=${DB_CONNECTION}."
        ;;
esac

MYSQL_BASE_ARGS=(--user="$DB_USERNAME")
MYSQL_BASE_ARGS+=(--default-character-set=utf8mb4)

if [ -n "$DB_SOCKET" ]; then
    MYSQL_BASE_ARGS+=(--socket="$DB_SOCKET")
else
    MYSQL_BASE_ARGS+=(--host="${DB_HOST:-127.0.0.1}")
    if [ -n "$DB_PORT" ]; then
        MYSQL_BASE_ARGS+=(--port="$DB_PORT")
    fi
fi

export MYSQL_PWD="$DB_PASSWORD"
trap 'unset MYSQL_PWD' EXIT

mysql_exec() {
    mysql "${MYSQL_BASE_ARGS[@]}" "$DB_DATABASE" "$@"
}

mysql_scalar() {
    mysql "${MYSQL_BASE_ARGS[@]}" --batch --raw --skip-column-names "$DB_DATABASE" -e "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; $1"
}

table_exists() {
    local table="$1"
    local escaped

    escaped="$(sql_escape "$table")"
    mysql_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '${escaped}';"
}

table_count() {
    local table="$1"
    mysql_scalar "SELECT COUNT(*) FROM \`${table}\`;"
}

table_has_auto_increment() {
    local table="$1"
    local escaped

    escaped="$(sql_escape "$table")"
    mysql_scalar "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '${escaped}' AND extra LIKE '%auto_increment%';"
}

PROTECTED_ROLE_NAMES="${PROTECTED_ROLE_NAMES:-super_admin,admin,manager}"
KEEP_USER_EMAILS="${KEEP_USER_EMAILS:-}"

SQL_PROTECTED_ROLE_NAMES="$(sql_escape "$PROTECTED_ROLE_NAMES")"
SQL_KEEP_USER_EMAILS="$(sql_escape "$KEEP_USER_EMAILS")"

RESET_TABLES=(
    finance_request_attachments
    finance_currency_exchanges
    finance_cash_box_transfers
    finance_transactions
    finance_requests
    payments
    invoice_items
    invoices
    activity_payments
    activity_expenses
    activity_registrations
    activity_group_targets
    activities
    quran_partial_test_attempts
    quran_partial_test_parts
    quran_partial_tests
    quran_final_test_attempts
    quran_final_tests
    quran_tests
    assessment_results
    assessment_groups
    assessments
    point_transactions
    memorization_session_pages
    student_page_achievements
    memorization_sessions
    student_attendance_records
    group_attendance_days
    student_attendance_days
    teacher_attendance_records
    teacher_attendance_days
    student_notes
    student_files
    enrollments
    group_schedules
    groups
    courses
    barcode_scan_events
    barcode_scan_imports
    user_scope_overrides
    activity_log
    sessions
    jobs
    job_batches
    failed_jobs
    cache
    cache_locks
    students
    parents
    teachers
)

if [ "$INCLUDE_COMMUNITY_CONTACTS" -eq 1 ]; then
    RESET_TABLES+=(community_contacts)
fi

REQUIRED_TABLES=(
    users
    roles
    model_has_roles
    model_has_permissions
    personal_access_tokens
    password_reset_tokens
    finance_cash_box_user
    students
    parents
    teachers
)

for table in "${REQUIRED_TABLES[@]}" "${RESET_TABLES[@]}"; do
    if [ "$(table_exists "$table")" != "1" ]; then
        fail "Required table is missing: ${table}. Run migrations first or update the reset script."
    fi
done

profile_user_delete_count_sql="
SELECT COUNT(*)
FROM (
    SELECT DISTINCT profile_users.id
    FROM (
        SELECT user_id AS id FROM students WHERE user_id IS NOT NULL
        UNION
        SELECT user_id AS id FROM parents WHERE user_id IS NOT NULL
        UNION
        SELECT user_id AS id FROM teachers WHERE user_id IS NOT NULL
    ) profile_users
    LEFT JOIN (
        SELECT DISTINCT users.id
        FROM users
        JOIN model_has_roles
            ON model_has_roles.model_id = users.id
            AND CONVERT(model_has_roles.model_type USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT('%User' USING utf8mb4) COLLATE utf8mb4_unicode_ci
        JOIN roles ON roles.id = model_has_roles.role_id
        WHERE FIND_IN_SET(
            CONVERT(roles.name USING utf8mb4) COLLATE utf8mb4_unicode_ci,
            CONVERT('${SQL_PROTECTED_ROLE_NAMES}' USING utf8mb4) COLLATE utf8mb4_unicode_ci
        ) > 0

        UNION

        SELECT MIN(id) AS id FROM users

        UNION

        SELECT id FROM users
        WHERE '${SQL_KEEP_USER_EMAILS}' <> ''
        AND FIND_IN_SET(
            CONVERT(email USING utf8mb4) COLLATE utf8mb4_unicode_ci,
            CONVERT('${SQL_KEEP_USER_EMAILS}' USING utf8mb4) COLLATE utf8mb4_unicode_ci
        ) > 0
    ) protected_users ON protected_users.id = profile_users.id
    WHERE protected_users.id IS NULL
) final_users;
"

echo "Project: $(pwd)"
echo "Environment: ${APP_ENV:-unknown}"
echo "Database: ${DB_DATABASE}"
echo "Protected roles: ${PROTECTED_ROLE_NAMES}"

if [ -n "$KEEP_USER_EMAILS" ]; then
    echo "Extra protected emails: ${KEEP_USER_EMAILS}"
fi

echo "Community contacts reset: $([ "$INCLUDE_COMMUNITY_CONTACTS" -eq 1 ] && echo yes || echo no)"
echo

echo "Records that will be cleared:"
for table in "${RESET_TABLES[@]}"; do
    printf '  %-35s %s\n' "$table" "$(table_count "$table")"
done

printf '  %-35s %s\n' "non-protected profile users" "$(mysql_scalar "$profile_user_delete_count_sql")"
echo

if [ "$MODE" = "dry-run" ]; then
    echo "Dry run only. No data was changed."
    exit 0
fi

BACKUP_ROOT="${BACKUP_ROOT:-$(pwd)/storage/app/reset-backups}"
BACKUP_DIR="${BACKUP_ROOT}/$(date +%Y%m%d-%H%M%S)"

mkdir -p "$BACKUP_DIR"

echo "Creating database backup..."
if ! mysqldump "${MYSQL_BASE_ARGS[@]}" --single-transaction --routines --triggers --events --no-tablespaces "$DB_DATABASE" > "$BACKUP_DIR/db-before-reset.sql"; then
    echo "Retrying backup without events..."
    mysqldump "${MYSQL_BASE_ARGS[@]}" --single-transaction --routines --triggers --no-tablespaces "$DB_DATABASE" > "$BACKUP_DIR/db-before-reset.sql"
fi

if [ -d storage/app/public ]; then
    echo "Creating storage backup..."
    tar -czf "$BACKUP_DIR/storage-public-before-reset.tar.gz" storage/app/public
fi

DELETE_SQL=""
for table in "${RESET_TABLES[@]}"; do
    DELETE_SQL+="DELETE FROM \`${table}\`;
"
done

echo "Resetting operational data..."

mysql_exec <<SQL
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @reset_protected_roles = CONVERT('${SQL_PROTECTED_ROLE_NAMES}' USING utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @reset_keep_user_emails = CONVERT('${SQL_KEEP_USER_EMAILS}' USING utf8mb4) COLLATE utf8mb4_unicode_ci;

CREATE TEMPORARY TABLE reset_profile_users_to_delete (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

INSERT IGNORE INTO reset_profile_users_to_delete (id)
SELECT user_id FROM students WHERE user_id IS NOT NULL;

INSERT IGNORE INTO reset_profile_users_to_delete (id)
SELECT user_id FROM parents WHERE user_id IS NOT NULL;

INSERT IGNORE INTO reset_profile_users_to_delete (id)
SELECT user_id FROM teachers WHERE user_id IS NOT NULL;

CREATE TEMPORARY TABLE reset_protected_users (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

INSERT IGNORE INTO reset_protected_users (id)
SELECT DISTINCT users.id
FROM users
JOIN model_has_roles
    ON model_has_roles.model_id = users.id
    AND CONVERT(model_has_roles.model_type USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT('%User' USING utf8mb4) COLLATE utf8mb4_unicode_ci
JOIN roles ON roles.id = model_has_roles.role_id
WHERE FIND_IN_SET(
    CONVERT(roles.name USING utf8mb4) COLLATE utf8mb4_unicode_ci,
    @reset_protected_roles
) > 0;

INSERT IGNORE INTO reset_protected_users (id)
SELECT id FROM users ORDER BY id LIMIT 1;

INSERT IGNORE INTO reset_protected_users (id)
SELECT id
FROM users
WHERE @reset_keep_user_emails <> ''
AND FIND_IN_SET(
    CONVERT(email USING utf8mb4) COLLATE utf8mb4_unicode_ci,
    @reset_keep_user_emails
) > 0;

CREATE TEMPORARY TABLE reset_final_users_to_delete (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

INSERT IGNORE INTO reset_final_users_to_delete (id)
SELECT reset_profile_users_to_delete.id
FROM reset_profile_users_to_delete
LEFT JOIN reset_protected_users
    ON reset_protected_users.id = reset_profile_users_to_delete.id
WHERE reset_protected_users.id IS NULL;

CREATE TEMPORARY TABLE reset_final_user_emails (
    email VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

INSERT IGNORE INTO reset_final_user_emails (email)
SELECT CONVERT(users.email USING utf8mb4) COLLATE utf8mb4_unicode_ci
FROM users
JOIN reset_final_users_to_delete
    ON reset_final_users_to_delete.id = users.id
WHERE users.email IS NOT NULL;

SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

${DELETE_SQL}

DELETE FROM finance_cash_box_user
WHERE user_id IN (SELECT id FROM reset_final_users_to_delete);

DELETE FROM personal_access_tokens
WHERE CONVERT(tokenable_type USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT('%User' USING utf8mb4) COLLATE utf8mb4_unicode_ci
AND tokenable_id IN (SELECT id FROM reset_final_users_to_delete);

DELETE FROM model_has_permissions
WHERE CONVERT(model_type USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT('%User' USING utf8mb4) COLLATE utf8mb4_unicode_ci
AND model_id IN (SELECT id FROM reset_final_users_to_delete);

DELETE FROM model_has_roles
WHERE CONVERT(model_type USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT('%User' USING utf8mb4) COLLATE utf8mb4_unicode_ci
AND model_id IN (SELECT id FROM reset_final_users_to_delete);

DELETE password_reset_tokens
FROM password_reset_tokens
JOIN reset_final_user_emails
    ON CONVERT(reset_final_user_emails.email USING utf8mb4) COLLATE utf8mb4_unicode_ci =
       CONVERT(password_reset_tokens.email USING utf8mb4) COLLATE utf8mb4_unicode_ci;

DELETE FROM users
WHERE id IN (SELECT id FROM reset_final_users_to_delete);

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;
SQL

echo "Resetting operational auto-increment counters..."

for table in "${RESET_TABLES[@]}"; do
    if [ "$(table_has_auto_increment "$table")" = "1" ]; then
        mysql_exec -e "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; SET FOREIGN_KEY_CHECKS = 0; ALTER TABLE \`${table}\` AUTO_INCREMENT = 1; SET FOREIGN_KEY_CHECKS = 1;"
    fi
done

if [ "$DELETE_OPERATIONAL_FILES" -eq 1 ]; then
    echo "Deleting operational uploaded files..."
    rm -rf storage/app/public/students/photos \
           storage/app/public/students/files \
           storage/app/public/teachers/photos \
           storage/app/public/finance/requests
fi

if command -v php >/dev/null 2>&1; then
    php artisan optimize:clear >/dev/null || true
fi

echo
echo "Reset complete."
echo "Backup saved in: ${BACKUP_DIR}"
echo "Settings, roles, permissions, website setup, finance setup, print templates, academic years, tracking rules, sidebar setup, and master data were kept."