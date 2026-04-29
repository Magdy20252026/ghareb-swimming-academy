<?php
session_start();
require_once 'config.php';

const COACH_PORTAL_NOTIFICATION_SESSION_KEY = 'coach_portal_coach_id';
const COACH_PORTAL_NOTIFICATION_FEED_LIMIT = 100;

function coachPortalNotificationBaseQueryParams(): array
{
    $queryParams = [];
    $installationKey = trim((string) ($_GET['i'] ?? ''));
    if ($installationKey !== '') {
        $queryParams['i'] = $installationKey;
    }

    return $queryParams;
}

function coachPortalNotificationBuildUrl(string $path, array $extraQueryParams = []): string
{
    $queryParams = array_merge(coachPortalNotificationBaseQueryParams(), $extraQueryParams);
    if ($queryParams === []) {
        return $path;
    }

    return $path . '?' . http_build_query($queryParams);
}

function coachPortalNotificationJsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function coachPortalNotificationCoach(PDO $pdo, int $coachId): ?array
{
    $coachStmt = $pdo->prepare(
        "SELECT id, full_name
         FROM coaches
         WHERE id = ?
         LIMIT 1"
    );
    $coachStmt->execute([$coachId]);

    return $coachStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function coachPortalNotificationPaymentCycleLabel(string $value): string
{
    $labels = [
        'weekly' => 'أسبوعي',
        'monthly' => 'شهري',
    ];

    if (!isset($labels[$value])) {
        error_log('Unexpected coach salary payment cycle in notification feed: ' . $value);
    }

    return $labels[$value] ?? '—';
}

function coachPortalNotificationAmount($amount): string
{
    return number_format((float) $amount, 2, '.', '');
}

function coachPortalNotificationDateLabel(?string $value): string
{
    $normalizedValue = trim((string) $value);
    if ($normalizedValue === '') {
        return '—';
    }

    $timestamp = strtotime($normalizedValue);
    if ($timestamp === false) {
        return $normalizedValue;
    }

    return date('Y-m-d', $timestamp);
}

function coachPortalNotificationPeriod(array $payment): string
{
    $periodStart = trim((string) ($payment['period_start'] ?? ''));
    $periodEnd = trim((string) ($payment['period_end'] ?? ''));

    if ($periodStart === '' && $periodEnd === '') {
        return 'غير محددة';
    }

    if ($periodStart === '') {
        return 'حتى ' . $periodEnd;
    }

    if ($periodEnd === '') {
        return 'من ' . $periodStart;
    }

    return 'من ' . $periodStart . ' إلى ' . $periodEnd;
}

function coachPortalAttendanceStateToken(PDO $pdo, int $coachId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS records_count,
            COALESCE(MAX(id), 0) AS last_record_id,
            COALESCE(MAX(updated_at), '') AS last_updated_at
         FROM coach_attendance
         WHERE coach_id = ?"
    );
    $stmt->execute([$coachId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function coachPortalAdvanceStateToken(PDO $pdo, int $coachId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS records_count,
            COALESCE(MAX(id), 0) AS last_record_id,
            COALESCE(MAX(updated_at), '') AS last_updated_at
         FROM coach_advances
         WHERE coach_id = ?"
    );
    $stmt->execute([$coachId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function coachPortalPaymentStateToken(PDO $pdo, int $coachId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS records_count,
            COALESCE(MAX(id), 0) AS last_record_id,
            COALESCE(MAX(reserved_at), '') AS last_reserved_at,
            COALESCE(MAX(paid_at), '') AS last_paid_at
         FROM coach_salary_payments
         WHERE coach_id = ?"
    );
    $stmt->execute([$coachId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function coachPortalAdminNotificationStateToken(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT
            COUNT(*) AS records_count,
            COALESCE(MAX(id), 0) AS last_record_id,
            COALESCE(MAX(updated_at), '') AS last_updated_at
         FROM coach_notifications"
    );

    return $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
}

function coachPortalNormalizeStateTokenValue($value)
{
    if (!is_array($value)) {
        return $value;
    }

    ksort($value);
    foreach ($value as $key => $nestedValue) {
        $value[$key] = coachPortalNormalizeStateTokenValue($nestedValue);
    }

    return $value;
}

function coachPortalBuildStateToken(PDO $pdo, int $coachId): string
{
    $state = coachPortalNormalizeStateTokenValue([
        'coach_id' => $coachId,
        'attendance' => coachPortalAttendanceStateToken($pdo, $coachId),
        'advances' => coachPortalAdvanceStateToken($pdo, $coachId),
        'payments' => coachPortalPaymentStateToken($pdo, $coachId),
        'notifications' => coachPortalAdminNotificationStateToken($pdo),
    ]);

    return hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$sessionCoachId = (int) ($_SESSION[COACH_PORTAL_NOTIFICATION_SESSION_KEY] ?? 0);
if ($sessionCoachId <= 0) {
    coachPortalNotificationJsonResponse([
        'authenticated' => false,
        'items' => [],
    ], 401);
}

$coach = coachPortalNotificationCoach($pdo, $sessionCoachId);
if ($coach === null) {
    unset($_SESSION[COACH_PORTAL_NOTIFICATION_SESSION_KEY]);
    coachPortalNotificationJsonResponse([
        'authenticated' => false,
        'items' => [],
    ], 401);
}

$items = [];

$adminNotificationsStmt = $pdo->query(
    "SELECT id, notification_message, created_at
     FROM coach_notifications
     ORDER BY created_at DESC, id DESC
     LIMIT 25"
);
$adminNotifications = $adminNotificationsStmt ? ($adminNotificationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

foreach ($adminNotifications as $notification) {
    $notificationId = (int) ($notification['id'] ?? 0);
    $createdAt = trim((string) ($notification['created_at'] ?? ''));
    $items[] = [
        'id' => 'admin-' . $notificationId . '-' . $createdAt,
        'type' => 'admin_notice',
        'title' => '📣 إشعار جديد من الإدارة',
        'body' => trim((string) ($notification['notification_message'] ?? '')),
        'event_at' => $createdAt,
        'url' => coachPortalNotificationBuildUrl('coach_portal.php'),
    ];
}

$attendanceUpdatesStmt = $pdo->prepare(
    "SELECT id, attendance_date, work_hours, created_at, updated_at
     FROM coach_attendance
     WHERE coach_id = ?
     ORDER BY updated_at DESC, id DESC
     LIMIT 25"
);
$attendanceUpdatesStmt->execute([$sessionCoachId]);
$attendanceUpdates = $attendanceUpdatesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($attendanceUpdates as $attendance) {
    $attendanceId = (int) ($attendance['id'] ?? 0);
    $updatedAt = trim((string) ($attendance['updated_at'] ?? ''));
    $attendanceDate = (string) ($attendance['attendance_date'] ?? '');
    $items[] = [
        'id' => 'attendance-' . $attendanceId . '-' . $updatedAt,
        'type' => 'attendance_updated',
        'title' => '🗓️ تم تحديث الحضور',
        'body' => sprintf(
            'تم تحديث حضورك بتاريخ %s بعدد ساعات %s',
            coachPortalNotificationDateLabel($attendanceDate),
            coachPortalNotificationAmount($attendance['work_hours'] ?? 0)
        ),
        'event_at' => $updatedAt,
        'url' => coachPortalNotificationBuildUrl('coach_portal.php'),
    ];
}

$advanceUpdatesStmt = $pdo->prepare(
    "SELECT id, advance_date, amount, created_at, updated_at
     FROM coach_advances
     WHERE coach_id = ?
     ORDER BY updated_at DESC, id DESC
     LIMIT 25"
);
$advanceUpdatesStmt->execute([$sessionCoachId]);
$advanceUpdates = $advanceUpdatesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($advanceUpdates as $advance) {
    $advanceId = (int) ($advance['id'] ?? 0);
    $updatedAt = trim((string) ($advance['updated_at'] ?? ''));
    $advanceDate = (string) ($advance['advance_date'] ?? '');
    $items[] = [
        'id' => 'advance-' . $advanceId . '-' . $updatedAt,
        'type' => 'advance_updated',
        'title' => '💸 تم تحديث السلف',
        'body' => sprintf(
            'تم تحديث سلفة بتاريخ %s بمبلغ %s',
            coachPortalNotificationDateLabel($advanceDate),
            coachPortalNotificationAmount($advance['amount'] ?? 0)
        ),
        'event_at' => $updatedAt,
        'url' => coachPortalNotificationBuildUrl('coach_portal.php'),
    ];
}

$pendingPaymentsStmt = $pdo->prepare(
    "SELECT
        id,
        payment_cycle,
        period_start,
        period_end,
        gross_amount,
        total_advances,
        net_amount,
        reserved_at
     FROM coach_salary_payments
     WHERE coach_id = ? AND payment_status = 'pending'
     ORDER BY reserved_at DESC, id DESC"
);
$pendingPaymentsStmt->execute([$sessionCoachId]);
$pendingPayments = $pendingPaymentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($pendingPayments as $payment) {
    $paymentId = (int) ($payment['id'] ?? 0);
    $reservedAt = trim((string) ($payment['reserved_at'] ?? ''));
    $items[] = [
        'id' => 'pending-' . $paymentId . '-' . $reservedAt,
        'type' => 'salary_reserved',
        'title' => '📌 تم حجز راتب لك',
        'body' => sprintf(
            '%s • نوع القبض: %s • الإجمالي: %s • السلف: %s • الصافي: %s',
            coachPortalNotificationPeriod($payment),
            coachPortalNotificationPaymentCycleLabel((string) ($payment['payment_cycle'] ?? '')),
            coachPortalNotificationAmount($payment['gross_amount'] ?? 0),
            coachPortalNotificationAmount($payment['total_advances'] ?? 0),
            coachPortalNotificationAmount($payment['net_amount'] ?? 0)
        ),
        'event_at' => $reservedAt,
        'url' => coachPortalNotificationBuildUrl('coach_portal.php'),
    ];
}

$paidPaymentsStmt = $pdo->prepare(
    "SELECT
        id,
        payment_cycle,
        period_start,
        period_end,
        gross_amount,
        total_advances,
        net_amount,
        paid_at
     FROM coach_salary_payments
     WHERE coach_id = ? AND payment_status = 'paid'
     ORDER BY paid_at DESC, id DESC
     LIMIT 25"
);
$paidPaymentsStmt->execute([$sessionCoachId]);
$paidPayments = $paidPaymentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($paidPayments as $payment) {
    $paymentId = (int) ($payment['id'] ?? 0);
    $paidAt = trim((string) ($payment['paid_at'] ?? ''));
    $items[] = [
        'id' => 'paid-' . $paymentId . '-' . $paidAt,
        'type' => 'salary_paid',
        'title' => '💳 تم صرف راتبك',
        'body' => sprintf(
            '%s • نوع القبض: %s • الإجمالي: %s • السلف: %s • الصافي المصروف: %s',
            coachPortalNotificationPeriod($payment),
            coachPortalNotificationPaymentCycleLabel((string) ($payment['payment_cycle'] ?? '')),
            coachPortalNotificationAmount($payment['gross_amount'] ?? 0),
            coachPortalNotificationAmount($payment['total_advances'] ?? 0),
            coachPortalNotificationAmount($payment['net_amount'] ?? 0)
        ),
        'event_at' => $paidAt,
        'url' => coachPortalNotificationBuildUrl('coach_portal.php'),
    ];
}

usort($items, static function (array $left, array $right): int {
    $leftTime = strtotime((string) ($left['event_at'] ?? '')) ?: 0;
    $rightTime = strtotime((string) ($right['event_at'] ?? '')) ?: 0;

    return $rightTime <=> $leftTime;
});

coachPortalNotificationJsonResponse([
    'authenticated' => true,
    'coach_scope' => 'coach-' . (int) ($coach['id'] ?? 0),
    'coach_name' => (string) ($coach['full_name'] ?? ''),
    'generated_at' => date('c'),
    'state_token' => coachPortalBuildStateToken($pdo, (int) ($coach['id'] ?? 0)),
    'items' => array_slice($items, 0, COACH_PORTAL_NOTIFICATION_FEED_LIMIT),
]);
