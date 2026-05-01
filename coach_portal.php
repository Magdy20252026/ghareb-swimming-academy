<?php
session_start();
require_once "config.php";
require_once "app_helpers.php";

const COACH_PORTAL_PAYMENT_CYCLES = [
    'weekly' => 'أسبوعي',
    'monthly' => 'شهري',
];
const COACH_PORTAL_PAYMENT_STATUSES = [
    'pending' => 'قبض مستحق',
    'paid' => 'تم الصرف',
];
const COACH_PORTAL_SESSION_KEY = 'coach_portal_coach_id';
const COACH_PORTAL_MIN_PASSWORD_LENGTH = 6;

function normalizeCoachPortalArabicNumbers(string $value): string
{
    return strtr($value, [
        "٠" => "0",
        "١" => "1",
        "٢" => "2",
        "٣" => "3",
        "٤" => "4",
        "٥" => "5",
        "٦" => "6",
        "٧" => "7",
        "٨" => "8",
        "٩" => "9",
        "۰" => "0",
        "۱" => "1",
        "۲" => "2",
        "۳" => "3",
        "۴" => "4",
        "۵" => "5",
        "۶" => "6",
        "۷" => "7",
        "۸" => "8",
        "۹" => "9",
    ]);
}

function sanitizeCoachPortalPhone(string $value): string
{
    $value = trim(normalizeCoachPortalArabicNumbers($value));
    $filteredValue = preg_replace('/[^0-9+]/', '', $value);
    if ($filteredValue === null) {
        return '';
    }

    $sanitizedValue = preg_replace('/(?!^)\+/', '', $filteredValue);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function sanitizeCoachPortalPassword(string $value): string
{
    return $value;
}

function coachPortalPasswordLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function isValidCoachPortalPassword(string $value): bool
{
    return coachPortalPasswordLength($value) >= COACH_PORTAL_MIN_PASSWORD_LENGTH;
}

function generateCoachPortalSecurityToken(): string
{
    $fallbackAttempted = false;

    try {
        return bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $fallbackAttempted = true;
            $isStrong = false;
            $randomBytes = openssl_random_pseudo_bytes(32, $isStrong);
            if ($randomBytes !== false && $isStrong) {
                return bin2hex($randomBytes);
            }
        }

        error_log(sprintf(
            'Failed to generate coach portal security token via random_bytes%s: %s',
            $fallbackAttempted ? ' with openssl_random_pseudo_bytes fallback attempt' : '',
            $exception->getMessage()
        ));
    }

    throw new RuntimeException('Failed to generate coach portal security token');
}

function getCoachPortalCsrfToken(): string
{
    if (
        !isset($_SESSION['coach_portal_csrf_token'])
        || !is_string($_SESSION['coach_portal_csrf_token'])
        || $_SESSION['coach_portal_csrf_token'] === ''
    ) {
        $_SESSION['coach_portal_csrf_token'] = generateCoachPortalSecurityToken();
    }

    return $_SESSION['coach_portal_csrf_token'];
}

function isValidCoachPortalCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getCoachPortalCsrfToken(), $submittedToken);
}

function formatCoachPortalAmount(float $value): string
{
    return number_format($value, 2);
}

function formatCoachPortalDateLabel(string $date): string
{
    if ($date === '') {
        return '—';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    $days = [
        'Sunday' => 'الأحد',
        'Monday' => 'الاثنين',
        'Tuesday' => 'الثلاثاء',
        'Wednesday' => 'الأربعاء',
        'Thursday' => 'الخميس',
        'Friday' => 'الجمعة',
        'Saturday' => 'السبت',
    ];
    $englishDayName = date('l', $timestamp);
    $arabicDayName = $days[$englishDayName] ?? $englishDayName;

    return $arabicDayName . ' • ' . date('Y-m-d', $timestamp);
}

function formatCoachPortalTimestamp(?string $value): string
{
    $normalizedValue = trim((string) $value);
    if ($normalizedValue === '') {
        return '—';
    }

    $timestamp = strtotime($normalizedValue);
    if ($timestamp === false) {
        return '—';
    }

    return date('Y-m-d H:i', $timestamp);
}

function coachPortalBaseQueryParams(): array
{
    $queryParams = [];
    $installationKey = trim((string) ($_GET['i'] ?? ''));
    if ($installationKey !== '') {
        $queryParams['i'] = $installationKey;
    }

    return $queryParams;
}

function coachPortalBuildUrl(string $path, array $extraQueryParams = []): string
{
    $queryParams = array_merge(coachPortalBaseQueryParams(), $extraQueryParams);
    if ($queryParams === []) {
        return $path;
    }

    return $path . '?' . http_build_query($queryParams);
}

function fetchCoachPortalAttendanceRows(PDO $pdo, int $coachId): array
{
    $attendanceStmt = $pdo->prepare(
        "SELECT attendance_date, work_hours
         FROM coach_attendance
         WHERE coach_id = ?
         ORDER BY attendance_date ASC, id ASC"
    );
    $attendanceStmt->execute([$coachId]);

    return $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchCoachPortalAdvanceRows(PDO $pdo, int $coachId): array
{
    $advancesStmt = $pdo->prepare(
        "SELECT advance_date, amount
         FROM coach_advances
         WHERE coach_id = ?
         ORDER BY advance_date ASC, id ASC"
    );
    $advancesStmt->execute([$coachId]);

    return $advancesStmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchCoachPortalNotifications(PDO $pdo): array
{
    $notificationsStmt = $pdo->query(
        "SELECT id, notification_message, created_at
         FROM coach_notifications
         ORDER BY created_at DESC, id DESC"
    );

    return $notificationsStmt ? ($notificationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function buildCoachPortalDailyRecords(array $attendanceRows, array $advanceRows, float $hourlyRate): array
{
    $timelineDates = [];

    foreach ($attendanceRows as $attendanceRow) {
        $date = (string) ($attendanceRow['attendance_date'] ?? '');
        if ($date !== '') {
            $timelineDates[] = $date;
        }
    }

    foreach ($advanceRows as $advanceRow) {
        $date = (string) ($advanceRow['advance_date'] ?? '');
        if ($date !== '') {
            $timelineDates[] = $date;
        }
    }

    if ($timelineDates === []) {
        return [];
    }

    sort($timelineDates);
    $startDate = new DateTimeImmutable($timelineDates[0]);
    $endDate = new DateTimeImmutable($timelineDates[count($timelineDates) - 1]);
    $inclusiveEndDate = $endDate->modify('+1 day');
    $period = new DatePeriod($startDate, new DateInterval('P1D'), $inclusiveEndDate);
    $dailyRecords = [];

    foreach ($period as $day) {
        $date = $day->format('Y-m-d');
        $dailyRecords[$date] = [
            'date' => $date,
            'work_hours' => 0.0,
            'advance_amount' => 0.0,
            'attendance_status' => false,
            'daily_due_amount' => 0.0,
            'daily_available_amount' => 0.0,
        ];
    }

    foreach ($attendanceRows as $attendanceRow) {
        $date = (string) ($attendanceRow['attendance_date'] ?? '');
        if ($date === '' || !isset($dailyRecords[$date])) {
            continue;
        }

        $dailyRecords[$date]['attendance_status'] = true;
        $dailyRecords[$date]['work_hours'] += (float) ($attendanceRow['work_hours'] ?? 0);
    }

    foreach ($advanceRows as $advanceRow) {
        $date = (string) ($advanceRow['advance_date'] ?? '');
        if ($date === '' || !isset($dailyRecords[$date])) {
            continue;
        }

        $dailyRecords[$date]['advance_amount'] += (float) ($advanceRow['amount'] ?? 0);
    }

    foreach ($dailyRecords as $date => $dailyRecord) {
        $dailyRecords[$date]['daily_due_amount'] = $dailyRecord['work_hours'] * $hourlyRate;
        $dailyRecords[$date]['daily_available_amount'] = $dailyRecords[$date]['daily_due_amount'] - $dailyRecord['advance_amount'];
    }

    krsort($dailyRecords);
    return array_values($dailyRecords);
}

function calculateCoachPortalTotals(array $dailyRecords, float $hourlyRate): array
{
    $totalHours = 0.0;
    $totalAdvances = 0.0;
    $attendanceDays = 0;
    $absenceDays = 0;

    foreach ($dailyRecords as $dailyRecord) {
        $totalHours += (float) ($dailyRecord['work_hours'] ?? 0);
        $totalAdvances += (float) ($dailyRecord['advance_amount'] ?? 0);

        if (!empty($dailyRecord['attendance_status'])) {
            $attendanceDays++;
        } else {
            $absenceDays++;
        }
    }

    $grossAmount = $totalHours * $hourlyRate;

    return [
        'total_hours' => $totalHours,
        'total_advances' => $totalAdvances,
        'gross_amount' => $grossAmount,
        'available_amount' => $grossAmount - $totalAdvances,
        'attendance_days' => $attendanceDays,
        'absence_days' => $absenceDays,
        'period_start' => $dailyRecords === [] ? null : (string) ($dailyRecords[count($dailyRecords) - 1]['date'] ?? null),
        'period_end' => $dailyRecords === [] ? null : (string) ($dailyRecords[0]['date'] ?? null),
    ];
}

function clearCoachPortalSession(): void
{
    unset($_SESSION[COACH_PORTAL_SESSION_KEY]);
}

function fetchCoachPortalCoachByPhone(PDO $pdo, string $phone): ?array
{
    $coachStmt = $pdo->prepare(
        "SELECT id, full_name, phone, password_hash, hourly_rate
         FROM coaches
         WHERE phone = ?
         LIMIT 1"
    );
    $coachStmt->execute([$phone]);

    return $coachStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetchCoachPortalCoachById(PDO $pdo, int $coachId): ?array
{
    $coachStmt = $pdo->prepare(
        "SELECT id, full_name, phone, password_hash, hourly_rate
         FROM coaches
         WHERE id = ?
         LIMIT 1"
    );
    $coachStmt->execute([$coachId]);

    return $coachStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$submittedPhone = '';
$submittedPassword = '';
$message = '';
$messageType = 'info';
$siteSettings = getSiteSettings($pdo);
$academyName = $siteSettings['academy_name'];
$academyLogoPath = $siteSettings['academy_logo_path'];
$academyLogoInitial = getAcademyLogoInitial($academyName);
$selectedCoach = null;
$coachAttendanceRows = [];
$coachAdvanceRows = [];
$coachDailyRecords = [];
$coachPendingPayments = [];
$coachPaymentHistory = [];
$coachNotifications = [];
$coachPendingTotalAmount = 0.0;
$coachTotals = [
    'total_hours' => 0.0,
    'total_advances' => 0.0,
    'gross_amount' => 0.0,
    'available_amount' => 0.0,
    'attendance_days' => 0,
    'absence_days' => 0,
    'period_start' => null,
    'period_end' => null,
];

if (isset($_GET['logout'])) {
    clearCoachPortalSession();
    header('Location: ' . coachPortalBuildUrl('coach_portal.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'logout') {
        clearCoachPortalSession();
        $message = '✅ تم تسجيل الخروج من بوابة المدربين.';
        $messageType = 'success';
    } elseif ($action === 'login') {
        $submittedPhone = sanitizeCoachPortalPhone($_POST['phone'] ?? '');
        $submittedPassword = sanitizeCoachPortalPassword($_POST['password'] ?? '');

        if ($submittedPhone === '' || $submittedPassword === '') {
            clearCoachPortalSession();
            $message = '❌ أدخل رقم الهاتف وكلمة المرور لتسجيل الدخول.';
            $messageType = 'error';
        } else {
            $coach = fetchCoachPortalCoachByPhone($pdo, $submittedPhone);
            $passwordHash = (string) ($coach['password_hash'] ?? '');

            if ($coach === null || $passwordHash === '' || !password_verify($submittedPassword, $passwordHash)) {
                clearCoachPortalSession();
                $message = '❌ بيانات الدخول غير صحيحة.';
                $messageType = 'error';
            } else {
                if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
                    try {
                        $updatedHash = password_hash($submittedPassword, PASSWORD_DEFAULT);
                        $updateStmt = $pdo->prepare("UPDATE coaches SET password_hash = ? WHERE id = ?");
                        $updateStmt->execute([$updatedHash, $coach['id']]);
                        $coach['password_hash'] = $updatedHash;
                    } catch (Throwable $exception) {
                        error_log(sprintf(
                            'تعذر تحديث تشفير كلمة مرور المدرب #%d: %s',
                            (int) $coach['id'],
                            $exception->getMessage()
                        ));
                    }
                }

                session_regenerate_id(true);
                $_SESSION[COACH_PORTAL_SESSION_KEY] = (int) $coach['id'];
                $selectedCoach = $coach;
                $submittedPhone = (string) $coach['phone'];
            }
        }
    } elseif ($action === 'change_password') {
        $sessionCoachId = (int) ($_SESSION[COACH_PORTAL_SESSION_KEY] ?? 0);
        $selectedCoach = $sessionCoachId > 0 ? fetchCoachPortalCoachById($pdo, $sessionCoachId) : null;
        $currentPassword = sanitizeCoachPortalPassword($_POST['current_password'] ?? '');
        $newPassword = sanitizeCoachPortalPassword($_POST['new_password'] ?? '');
        $confirmPassword = sanitizeCoachPortalPassword($_POST['confirm_password'] ?? '');

        if ($selectedCoach === null) {
            clearCoachPortalSession();
            $message = '❌ يرجى تسجيل الدخول أولاً لتغيير كلمة المرور.';
            $messageType = 'error';
        } elseif (!isValidCoachPortalCsrfToken($_POST['csrf_token'] ?? null)) {
            $message = '❌ انتهت صلاحية الطلب، يرجى إعادة المحاولة.';
            $messageType = 'error';
        } elseif ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $message = '❌ يرجى إدخال كلمة المرور الحالية والجديدة وتأكيدها.';
            $messageType = 'error';
        } elseif (!password_verify($currentPassword, (string) ($selectedCoach['password_hash'] ?? ''))) {
            $message = '❌ كلمة المرور الحالية غير صحيحة.';
            $messageType = 'error';
        } elseif (!isValidCoachPortalPassword($newPassword)) {
            $message = '❌ كلمة المرور الجديدة يجب أن تكون ' . COACH_PORTAL_MIN_PASSWORD_LENGTH . ' أحرف أو أكثر.';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = '❌ تأكيد كلمة المرور الجديدة غير مطابق.';
            $messageType = 'error';
        } elseif ($currentPassword === $newPassword) {
            $message = '❌ اختر كلمة مرور جديدة مختلفة عن الحالية.';
            $messageType = 'error';
        } else {
            try {
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare("UPDATE coaches SET password_hash = ? WHERE id = ?");
                $updateStmt->execute([$newPasswordHash, (int) $selectedCoach['id']]);
                $selectedCoach['password_hash'] = $newPasswordHash;
                $submittedPhone = (string) ($selectedCoach['phone'] ?? '');
                $message = '✅ تم تغيير كلمة المرور بنجاح.';
                $messageType = 'success';
            } catch (Throwable $exception) {
                $message = '❌ تعذر تغيير كلمة المرور حالياً.';
                $messageType = 'error';
            }
        }
    } else {
        $message = '❌ تعذر تنفيذ الطلب.';
        $messageType = 'error';
    }
}

if ($selectedCoach === null && isset($_SESSION[COACH_PORTAL_SESSION_KEY])) {
    $sessionCoachId = (int) $_SESSION[COACH_PORTAL_SESSION_KEY];
    if ($sessionCoachId > 0) {
        $selectedCoach = fetchCoachPortalCoachById($pdo, $sessionCoachId);
        if ($selectedCoach === null) {
            clearCoachPortalSession();
        } else {
            $submittedPhone = (string) ($selectedCoach['phone'] ?? '');
        }
    }
}

if ($selectedCoach !== null) {
    $coachNotifications = fetchCoachPortalNotifications($pdo);
    $coachAttendanceRows = fetchCoachPortalAttendanceRows($pdo, (int) $selectedCoach['id']);
    $coachAdvanceRows = fetchCoachPortalAdvanceRows($pdo, (int) $selectedCoach['id']);
    $coachDailyRecords = buildCoachPortalDailyRecords(
        $coachAttendanceRows,
        $coachAdvanceRows,
        (float) ($selectedCoach['hourly_rate'] ?? 0)
    );
    $coachTotals = calculateCoachPortalTotals(
        $coachDailyRecords,
        (float) ($selectedCoach['hourly_rate'] ?? 0)
    );

    $pendingStmt = $pdo->prepare(
        "SELECT
            payment_cycle,
            period_start,
            period_end,
            total_hours,
            gross_amount,
            total_advances,
            net_amount,
            reserved_at
         FROM coach_salary_payments
         WHERE coach_id = ? AND payment_status = 'pending'
         ORDER BY COALESCE(period_end, period_start) DESC, id DESC"
    );
    $pendingStmt->execute([(int) $selectedCoach['id']]);
    $coachPendingPayments = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($coachPendingPayments as $pendingPayment) {
        $coachPendingTotalAmount += (float) ($pendingPayment['net_amount'] ?? 0);
    }

    $historyStmt = $pdo->prepare(
        "SELECT
            payment_cycle,
            period_start,
            period_end,
            total_hours,
            gross_amount,
            total_advances,
            net_amount,
            paid_at
         FROM coach_salary_payments
         WHERE coach_id = ? AND payment_status = 'paid'
         ORDER BY paid_at DESC, id DESC"
    );
    $historyStmt->execute([(int) $selectedCoach['id']]);
    $coachPaymentHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($message === '') {
        if ($coachDailyRecords === [] && $coachPendingPayments === [] && $coachPaymentHistory === []) {
            $message = 'ℹ️ لا توجد بيانات حالية أو مستحقات محفوظة أو سجل قبض لهذا المدرب حتى الآن.';
            $messageType = 'info';
        } elseif ($coachPendingPayments !== []) {
            $message = '✅ يوجد قبض مستحق محفوظ للمدرب ويمكنك مراجعة فترات الاستحقاق أسفل الصفحة.';
            $messageType = 'success';
        } elseif ($coachDailyRecords === []) {
            $message = 'ℹ️ لا توجد بيانات حالية معلقة، ويمكنك مراجعة سجل القبض أسفل الصفحة.';
            $messageType = 'info';
        } else {
            $message = '✅ تم تحميل بيانات المدرب الحالية بنجاح.';
            $messageType = 'success';
        }
    }
}

$coachPortalCsrfToken = getCoachPortalCsrfToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بوابة المدربين - <?php echo academyHtmlspecialchars($academyName, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="theme-color" content="#2563eb">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="بوابة المدربين">
    <link rel="manifest" href="<?php echo academyHtmlspecialchars(coachPortalBuildUrl('coach_portal_manifest.php'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="icon" href="<?php echo academyHtmlspecialchars(coachPortalBuildUrl('coach_portal_icon.php'), ENT_QUOTES, 'UTF-8'); ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?php echo academyHtmlspecialchars(coachPortalBuildUrl('coach_portal_icon.php'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="assets/css/coach-portal.css">
</head>
<body
    class="light-mode"
    data-reset-url="<?php echo academyHtmlspecialchars($selectedCoach !== null ? coachPortalBuildUrl('coach_portal.php', ['logout' => '1']) : coachPortalBuildUrl('coach_portal.php'), ENT_QUOTES, 'UTF-8'); ?>"
    data-portal-url="<?php echo academyHtmlspecialchars(coachPortalBuildUrl('coach_portal.php'), ENT_QUOTES, 'UTF-8'); ?>"
    data-notifications-feed-url="<?php echo academyHtmlspecialchars($selectedCoach !== null ? coachPortalBuildUrl('coach_portal_notifications_feed.php') : '', ENT_QUOTES, 'UTF-8'); ?>"
    data-notifications-scope="<?php echo academyHtmlspecialchars($selectedCoach !== null ? 'coach-' . (int) ($selectedCoach['id'] ?? 0) : '', ENT_QUOTES, 'UTF-8'); ?>"
    data-notifications-app-name="<?php echo academyHtmlspecialchars('بوابة المدربين - ' . $academyName, ENT_QUOTES, 'UTF-8'); ?>"
    data-notifications-icon-url="<?php echo academyHtmlspecialchars(coachPortalBuildUrl('coach_portal_icon.php'), ENT_QUOTES, 'UTF-8'); ?>"
    data-notifications-service-worker-url="<?php echo academyHtmlspecialchars(coachPortalBuildUrl('coach_portal_sw.js'), ENT_QUOTES, 'UTF-8'); ?>"
>
<div class="portal-shell">
    <header class="hero-card">
        <div class="brand-block">
            <div class="brand-logo-wrap">
                <div class="brand-logo">
                    <?php if ($academyLogoPath !== null): ?>
                        <img src="<?php echo academyHtmlspecialchars($academyLogoPath, ENT_QUOTES, 'UTF-8'); ?>" alt="شعار <?php echo academyHtmlspecialchars($academyName, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php else: ?>
                        <span><?php echo academyHtmlspecialchars($academyLogoInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </div>
                <span class="brand-wave">🌊</span>
            </div>
            <div class="brand-text">
                <span class="page-badge">🏅 بوابة المدربين</span>
                <h1><?php echo academyHtmlspecialchars($academyName, ENT_QUOTES, 'UTF-8'); ?></h1>
            </div>
        </div>

        <div class="hero-tools">
            <div class="theme-switch-box">
                <span>☀️</span>
                <label class="switch">
                    <input type="checkbox" id="themeToggle">
                    <span class="slider"></span>
                </label>
                <span>🌙</span>
            </div>
        </div>
    </header>

    <section class="search-card">
        <div class="card-head">
            <h2>🔐 تسجيل دخول المدرب</h2>
        </div>

        <form method="POST" class="search-form auth-form" autocomplete="off">
            <input type="hidden" name="action" value="login">
            <div class="field-group">
                <label for="phone">رقم الهاتف</label>
                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    inputmode="tel"
                    autocomplete="tel"
                    placeholder="مثال: 01000000000"
                    value="<?php echo academyHtmlspecialchars($submittedPhone, ENT_QUOTES, 'UTF-8'); ?>"
                >
            </div>

            <div class="field-group">
                <label for="password">كلمة المرور</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="current-password"
                    placeholder="أدخل كلمة المرور"
                    <?php echo $selectedCoach === null ? 'aria-required="true"' : ''; ?>
                    <?php echo $selectedCoach === null ? 'required' : ''; ?>
                >
            </div>

            <div class="search-actions">
                <button type="submit" class="primary-btn">🔓 دخول وعرض البيانات</button>
                <a href="<?php echo academyHtmlspecialchars($selectedCoach !== null ? coachPortalBuildUrl('coach_portal.php', ['logout' => '1']) : coachPortalBuildUrl('coach_portal.php'), ENT_QUOTES, 'UTF-8'); ?>" class="secondary-btn" id="clearBtn">
                    <?php echo $selectedCoach !== null ? '🚪 تسجيل الخروج' : '🧹 مسح'; ?>
                </a>
            </div>
        </form>
    </section>

    <?php if ($message !== ''): ?>
        <div class="message-box <?php echo academyHtmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo academyHtmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($selectedCoach !== null): ?>
        <section class="coach-overview-card">
            <div class="coach-identity">
                <div class="coach-avatar">🏊‍♂️</div>
                <div>
                    <span class="mini-badge">👤 بيانات المدرب</span>
                    <h2><?php echo academyHtmlspecialchars($selectedCoach['full_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <div class="coach-meta">
                        <span>📞 <?php echo academyHtmlspecialchars($selectedCoach['phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span>💵 سعر الساعة: <?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) ($selectedCoach['hourly_rate'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span>
                            🗓️ الفترة الحالية:
                            <?php echo academyHtmlspecialchars((string) ($coachTotals['period_start'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                            →
                            <?php echo academyHtmlspecialchars((string) ($coachTotals['period_end'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="coach-overview-pills">
                <div class="notification-center">
                    <button type="button" class="secondary-btn notification-btn" id="enableNotificationsBtn">🔔 تفعيل إشعارات التطبيق</button>
                    <span class="notification-status" id="notificationsStatus">سيتم تحديث الصفحة تلقائياً، ويمكن تفعيل الإشعارات ليصلك التنبيه خارج التطبيق أيضاً.</span>
                </div>
                <div class="availability-pill <?php echo (float) $coachPendingTotalAmount >= 0 ? 'positive' : 'negative'; ?>">
                    <span>📌 إجمالي القبض المستحق</span>
                    <strong><?php echo academyHtmlspecialchars(formatCoachPortalAmount($coachPendingTotalAmount), ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div class="availability-pill <?php echo (float) $coachTotals['available_amount'] >= 0 ? 'positive' : 'negative'; ?>">
                    <span>💸 إجمالي القبض الحالي</span>
                    <strong><?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) $coachTotals['available_amount']), ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
            </div>
        </section>

        <section class="search-card password-card">
            <div class="card-head">
                <h2>🔒 تغيير كلمة المرور</h2>
            </div>

            <form method="POST" class="search-form auth-form password-form" autocomplete="off">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($coachPortalCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="field-group">
                    <label for="current_password">كلمة المرور الحالية</label>
                    <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                </div>

                <div class="field-group">
                    <label for="new_password">كلمة المرور الجديدة</label>
                    <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>
                </div>

                <div class="field-group">
                    <label for="confirm_password">تأكيد كلمة المرور الجديدة</label>
                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                </div>

                <div class="search-actions">
                    <button type="submit" class="primary-btn">💾 حفظ كلمة المرور</button>
                </div>
            </form>
        </section>

        <section class="table-card coach-notifications-section">
            <div class="card-head">
                <h2>📣 اشعارات المدربين</h2>
            </div>
            <?php if ($coachNotifications !== []): ?>
                <div class="coach-notifications-grid">
                    <?php foreach ($coachNotifications as $coachNotification): ?>
                        <article class="coach-notification-card">
                            <strong><?php echo academyHtmlspecialchars(formatCoachPortalTimestamp((string) ($coachNotification['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <p><?php echo nl2br(academyHtmlspecialchars((string) ($coachNotification['notification_message'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">لا توجد إشعارات حالياً.</div>
            <?php endif; ?>
        </section>

        <section class="stats-grid">
            <article class="stat-card accent-orange">
                <span class="stat-icon">⏱️</span>
                <div>
                    <strong><?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) $coachTotals['total_hours']), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <p>عدد الساعات المستحقة</p>
                </div>
            </article>

            <article class="stat-card accent-green">
                <span class="stat-icon">✅</span>
                <div>
                    <strong><?php echo (int) $coachTotals['attendance_days']; ?></strong>
                    <p>عدد أيام الحضور</p>
                </div>
            </article>

            <article class="stat-card accent-red">
                <span class="stat-icon">❌</span>
                <div>
                    <strong><?php echo (int) $coachTotals['absence_days']; ?></strong>
                    <p>عدد أيام الغياب داخل الفترة الحالية</p>
                </div>
            </article>

            <article class="stat-card accent-blue">
                <span class="stat-icon">💵</span>
                <div>
                    <strong><?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) $coachTotals['gross_amount']), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <p>إجمالي المستحق قبل السلف</p>
                </div>
            </article>

            <article class="stat-card accent-gold">
                <span class="stat-icon">🧾</span>
                <div>
                    <strong><?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) $coachTotals['total_advances']), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <p>إجمالي السلف الحالية</p>
                </div>
            </article>

            <article class="stat-card accent-purple">
                <span class="stat-icon">📚</span>
                <div>
                    <strong><?php echo count($coachPaymentHistory); ?></strong>
                    <p>عدد مرات القبض السابقة</p>
                </div>
            </article>

            <article class="stat-card accent-blue">
                <span class="stat-icon">📌</span>
                <div>
                    <strong><?php echo count($coachPendingPayments); ?></strong>
                    <p>عدد فترات القبض المستحق</p>
                </div>
            </article>
        </section>

        <section class="content-grid">
            <article class="table-card">
                <div class="card-head">
                    <h2>🗓️ الساعات اليومية والحضور والغياب</h2>
                </div>

                <?php if ($coachDailyRecords !== []): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>اليوم</th>
                                    <th>الحالة</th>
                                    <th>الساعات المسجلة</th>
                                    <th>المستحق اليومي</th>
                                    <th>السلفة اليومية</th>
                                    <th>المتاح اليوم</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($coachDailyRecords as $dailyRecord): ?>
                                    <?php $isPresent = !empty($dailyRecord['attendance_status']); ?>
                                    <tr>
                                        <td data-label="اليوم"><?php echo academyHtmlspecialchars(formatCoachPortalDateLabel((string) $dailyRecord['date']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الحالة">
                                            <span class="status-pill <?php echo $isPresent ? 'present' : 'absent'; ?>">
                                                <?php echo $isPresent ? '✅ حاضر' : '❌ غائب'; ?>
                                            </span>
                                        </td>
                                        <td data-label="الساعات المسجلة"><?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) $dailyRecord['work_hours']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="المستحق اليومي"><?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) $dailyRecord['daily_due_amount']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="السلفة اليومية"><?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) $dailyRecord['advance_amount']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="المتاح اليوم" class="<?php echo (float) $dailyRecord['daily_available_amount'] >= 0 ? 'positive-text' : 'negative-text'; ?>">
                                            <?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) $dailyRecord['daily_available_amount']), ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">🌟 لا توجد ساعات أو سلف حالية معلقة لهذا المدرب.</div>
                <?php endif; ?>
            </article>

            <article class="table-card">
                <div class="card-head">
                    <h2>📌 القبض المستحق</h2>
                </div>

                <?php if ($coachPendingPayments !== []): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>الحالة</th>
                                    <th>نوع القبض</th>
                                    <th>فترة الاستحقاق</th>
                                    <th>الساعات</th>
                                    <th>الإجمالي</th>
                                    <th>السلف</th>
                                    <th>الصافي</th>
                                    <th>تاريخ الحجز</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($coachPendingPayments as $pendingItem): ?>
                                    <tr>
                                        <td data-label="الحالة"><?php echo academyHtmlspecialchars(COACH_PORTAL_PAYMENT_STATUSES['pending'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="نوع القبض"><?php echo academyHtmlspecialchars(COACH_PORTAL_PAYMENT_CYCLES[$pendingItem['payment_cycle']] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="فترة الاستحقاق">
                                            <?php echo academyHtmlspecialchars((string) ($pendingItem['period_start'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                            →
                                            <?php echo academyHtmlspecialchars((string) ($pendingItem['period_end'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td data-label="الساعات"><?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) ($pendingItem['total_hours'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الإجمالي"><?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) ($pendingItem['gross_amount'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="السلف"><?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) ($pendingItem['total_advances'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الصافي" class="<?php echo (float) ($pendingItem['net_amount'] ?? 0) >= 0 ? 'positive-text' : 'negative-text'; ?>">
                                            <?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) ($pendingItem['net_amount'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td data-label="تاريخ الحجز"><?php echo academyHtmlspecialchars(formatCoachPortalTimestamp((string) ($pendingItem['reserved_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">📌 لا يوجد قبض مستحق محفوظ لهذا المدرب حالياً.</div>
                <?php endif; ?>
            </article>

            <article class="table-card">
                <div class="card-head">
                    <h2>💳 سجل القبض</h2>
                </div>

                <?php if ($coachPaymentHistory !== []): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>الحالة</th>
                                    <th>نوع القبض</th>
                                    <th>الفترة</th>
                                    <th>الساعات</th>
                                    <th>الإجمالي</th>
                                    <th>السلف</th>
                                    <th>الصافي</th>
                                    <th>تاريخ القبض</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($coachPaymentHistory as $historyItem): ?>
                                    <tr>
                                        <td data-label="الحالة"><?php echo academyHtmlspecialchars(COACH_PORTAL_PAYMENT_STATUSES['paid'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="نوع القبض"><?php echo academyHtmlspecialchars(COACH_PORTAL_PAYMENT_CYCLES[$historyItem['payment_cycle']] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الفترة">
                                            <?php echo academyHtmlspecialchars((string) ($historyItem['period_start'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                            →
                                            <?php echo academyHtmlspecialchars((string) ($historyItem['period_end'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td data-label="الساعات"><?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) ($historyItem['total_hours'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الإجمالي"><?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) ($historyItem['gross_amount'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="السلف"><?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) ($historyItem['total_advances'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الصافي" class="<?php echo (float) ($historyItem['net_amount'] ?? 0) >= 0 ? 'positive-text' : 'negative-text'; ?>">
                                            <?php echo academyHtmlspecialchars(formatCoachPortalAmount((float) ($historyItem['net_amount'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td data-label="تاريخ القبض"><?php echo academyHtmlspecialchars(formatCoachPortalTimestamp((string) ($historyItem['paid_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">🧾 لا يوجد سجل قبض مسجل لهذا المدرب حتى الآن.</div>
                <?php endif; ?>
            </article>
        </section>
    <?php elseif ($submittedPhone === ''): ?>
        <section class="empty-placeholder">
            <div class="empty-icon">📲</div>
            <h2>ابدأ بتسجيل الدخول</h2>
        </section>
    <?php endif; ?>
</div>

<script src="assets/js/coach-portal.js"></script>
</body>
</html>
