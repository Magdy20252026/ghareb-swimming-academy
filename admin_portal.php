<?php
session_start();
require_once "config.php";
require_once "app_helpers.php";

const ADMIN_PORTAL_PAYMENT_CYCLES = [
    'weekly' => 'أسبوعي',
    'monthly' => 'شهري',
];
const ADMIN_PORTAL_SESSION_KEY = 'admin_portal_administrator_id';

function normalizeAdminPortalArabicNumbers(string $value): string
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

function sanitizeAdminPortalPhone(string $value): string
{
    $value = trim(normalizeAdminPortalArabicNumbers($value));
    $filteredValue = preg_replace('/[^0-9+]/', '', $value);
    if ($filteredValue === null) {
        return '';
    }

    $sanitizedValue = preg_replace('/(?!^)\+/', '', $filteredValue);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function sanitizeAdminPortalPassword(string $value): string
{
    return $value;
}

function formatAdminPortalAmount(float $value): string
{
    return number_format($value, 2);
}

function formatAdminPortalDateLabel(string $date): string
{
    if ($date === '') {
        return '—';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    $days = getArabicWeekdayLabels();
    $englishDayName = date('l', $timestamp);
    $arabicDayName = $days[$englishDayName] ?? $englishDayName;

    return $arabicDayName . ' • ' . date('Y-m-d', $timestamp);
}

function formatAdminPortalTime(?string $value): string
{
    if (!is_string($value) || trim($value) === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('H:i:s', $timestamp);
}

function formatAdminPortalLeaveDays(?string $leaveDays): string
{
    $dayLabels = getArabicWeekdayLabels();
    $formattedDays = [];
    foreach (decodeStoredWeekdays($leaveDays) as $leaveDay) {
        $formattedDays[] = $dayLabels[$leaveDay] ?? $leaveDay;
    }

    return $formattedDays === [] ? 'لا توجد' : implode(' / ', $formattedDays);
}

function fetchAdminPortalAttendanceRows(PDO $pdo, int $administratorId): array
{
    $attendanceStmt = $pdo->prepare(
        "SELECT attendance_date, check_in_time, check_out_time, work_hours
         FROM administrator_attendance
         WHERE administrator_id = ?
         ORDER BY attendance_date ASC, id ASC"
    );
    $attendanceStmt->execute([$administratorId]);

    return $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchAdminPortalAdvanceRows(PDO $pdo, int $administratorId): array
{
    $advancesStmt = $pdo->prepare(
        "SELECT advance_date, amount
         FROM admin_advances
         WHERE administrator_id = ?
         ORDER BY advance_date ASC, id ASC"
    );
    $advancesStmt->execute([$administratorId]);

    return $advancesStmt->fetchAll(PDO::FETCH_ASSOC);
}

function buildAdminPortalDailyRecords(array $attendanceRows, array $advanceRows, ?string $leaveDays = null): array
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
            'check_in_time' => null,
            'check_out_time' => null,
            'work_hours' => 0.0,
            'advance_amount' => 0.0,
            'attendance_status' => false,
            'is_leave_day' => isStoredWeekdaySelected($leaveDays, $day->format('l')),
        ];
    }

    foreach ($attendanceRows as $attendanceRow) {
        $date = (string) ($attendanceRow['attendance_date'] ?? '');
        if ($date === '' || !isset($dailyRecords[$date])) {
            continue;
        }

        $dailyRecords[$date]['attendance_status'] = true;
        $dailyRecords[$date]['work_hours'] += (float) ($attendanceRow['work_hours'] ?? 0);

        $checkInTime = $attendanceRow['check_in_time'] ?? null;
        $checkOutTime = $attendanceRow['check_out_time'] ?? null;

        if (is_string($checkInTime) && trim($checkInTime) !== '') {
            $dailyRecords[$date]['check_in_time'] = $checkInTime;
        }

        if (is_string($checkOutTime) && trim($checkOutTime) !== '') {
            $dailyRecords[$date]['check_out_time'] = $checkOutTime;
        }
    }

    foreach ($advanceRows as $advanceRow) {
        $date = (string) ($advanceRow['advance_date'] ?? '');
        if ($date === '' || !isset($dailyRecords[$date])) {
            continue;
        }

        $dailyRecords[$date]['advance_amount'] += (float) ($advanceRow['amount'] ?? 0);
    }

    krsort($dailyRecords);
    return array_values($dailyRecords);
}

function calculateAdminPortalTotals(array $dailyRecords): array
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
        } elseif (empty($dailyRecord['is_leave_day'])) {
            $absenceDays++;
        }
    }
    $periodBounds = getDescendingDailyRecordBounds($dailyRecords);

    return [
        'total_hours' => $totalHours,
        'total_advances' => $totalAdvances,
        'attendance_days' => $attendanceDays,
        'absence_days' => $absenceDays,
        'period_start' => $periodBounds['period_start'],
        'period_end' => $periodBounds['period_end'],
    ];
}

function clearAdminPortalSession(): void
{
    unset($_SESSION[ADMIN_PORTAL_SESSION_KEY]);
}

function fetchAdminPortalAdministratorByPhone(PDO $pdo, string $phone): ?array
{
    $administratorStmt = $pdo->prepare(
        "SELECT id, full_name, phone, password_hash, leave_days
         FROM administrators
         WHERE phone = ?
         LIMIT 1"
    );
    $administratorStmt->execute([$phone]);

    return $administratorStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetchAdminPortalAdministratorById(PDO $pdo, int $administratorId): ?array
{
    $administratorStmt = $pdo->prepare(
        "SELECT id, full_name, phone, password_hash, leave_days
         FROM administrators
         WHERE id = ?
         LIMIT 1"
    );
    $administratorStmt->execute([$administratorId]);

    return $administratorStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$submittedPhone = '';
$submittedPassword = '';
$message = '';
$messageType = 'info';
$siteSettings = getSiteSettings($pdo);
$academyName = $siteSettings['academy_name'];
$academyLogoPath = $siteSettings['academy_logo_path'];
$academyLogoInitial = getAcademyLogoInitial($academyName);
$selectedAdministrator = null;
$administratorAttendanceRows = [];
$administratorAdvanceRows = [];
$administratorDailyRecords = [];
$administratorPaymentHistory = [];
$administratorTotals = [
    'total_hours' => 0.0,
    'total_advances' => 0.0,
    'attendance_days' => 0,
    'absence_days' => 0,
    'period_start' => null,
    'period_end' => null,
];

if (isset($_GET['logout'])) {
    clearAdminPortalSession();
    header('Location: admin_portal.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'logout') {
        clearAdminPortalSession();
        $message = '✅ تم تسجيل الخروج من بوابة الإداريين.';
        $messageType = 'success';
    } elseif ($action === 'login') {
        $submittedPhone = sanitizeAdminPortalPhone($_POST['phone'] ?? '');
        $submittedPassword = sanitizeAdminPortalPassword($_POST['password'] ?? '');

        if ($submittedPhone === '' || $submittedPassword === '') {
            clearAdminPortalSession();
            $message = '❌ أدخل رقم الهاتف وكلمة المرور لتسجيل الدخول.';
            $messageType = 'error';
        } else {
            $administrator = fetchAdminPortalAdministratorByPhone($pdo, $submittedPhone);
            $passwordHash = (string) ($administrator['password_hash'] ?? '');

            if ($administrator === null || $passwordHash === '' || !password_verify($submittedPassword, $passwordHash)) {
                clearAdminPortalSession();
                $message = '❌ بيانات الدخول غير صحيحة.';
                $messageType = 'error';
            } else {
                if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
                    try {
                        $updatedHash = password_hash($submittedPassword, PASSWORD_DEFAULT);
                        $updateStmt = $pdo->prepare("UPDATE administrators SET password_hash = ? WHERE id = ?");
                        $updateStmt->execute([$updatedHash, $administrator['id']]);
                        $administrator['password_hash'] = $updatedHash;
                    } catch (Throwable $exception) {
                        error_log(sprintf(
                            'تعذر تحديث تشفير كلمة مرور الإداري #%d: %s',
                            (int) $administrator['id'],
                            $exception->getMessage()
                        ));
                    }
                }

                session_regenerate_id(true);
                $_SESSION[ADMIN_PORTAL_SESSION_KEY] = (int) $administrator['id'];
                $selectedAdministrator = $administrator;
                $submittedPhone = (string) $administrator['phone'];
            }
        }
    } else {
        $message = '❌ تعذر تنفيذ الطلب.';
        $messageType = 'error';
    }
}

if ($selectedAdministrator === null && isset($_SESSION[ADMIN_PORTAL_SESSION_KEY])) {
    $sessionAdministratorId = (int) $_SESSION[ADMIN_PORTAL_SESSION_KEY];
    if ($sessionAdministratorId > 0) {
        $selectedAdministrator = fetchAdminPortalAdministratorById($pdo, $sessionAdministratorId);
        if ($selectedAdministrator === null) {
            clearAdminPortalSession();
        } else {
            $submittedPhone = (string) ($selectedAdministrator['phone'] ?? '');
        }
    }
}

if ($selectedAdministrator !== null) {
    $administratorAttendanceRows = fetchAdminPortalAttendanceRows($pdo, (int) $selectedAdministrator['id']);
    $administratorAdvanceRows = fetchAdminPortalAdvanceRows($pdo, (int) $selectedAdministrator['id']);
    $administratorDailyRecords = buildAdminPortalDailyRecords(
        $administratorAttendanceRows,
        $administratorAdvanceRows,
        $selectedAdministrator['leave_days'] ?? null
    );
    $administratorTotals = calculateAdminPortalTotals($administratorDailyRecords);

    $historyStmt = $pdo->prepare(
        "SELECT
            payment_cycle,
            period_start,
            period_end,
            total_hours,
            salary_amount,
            total_advances,
            net_amount,
            attendance_days,
            paid_at
         FROM admin_salary_payments
         WHERE administrator_id = ?
         ORDER BY paid_at DESC, id DESC"
    );
    $historyStmt->execute([(int) $selectedAdministrator['id']]);
    $administratorPaymentHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($message === '') {
        if ($administratorDailyRecords === [] && $administratorPaymentHistory === []) {
            $message = 'ℹ️ لا توجد بيانات حالية أو سجل قبض لهذا الإداري حتى الآن.';
            $messageType = 'info';
        } elseif ($administratorDailyRecords === []) {
            $message = 'ℹ️ لا توجد بيانات حالية معلقة، ويمكنك مراجعة سجل القبض أسفل الصفحة.';
            $messageType = 'info';
        } else {
            $message = '✅ تم تحميل بيانات الإداري الحالية بنجاح.';
            $messageType = 'success';
        }
    }
}

$latestPayment = $administratorPaymentHistory[0] ?? null;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بوابة الإداريين - <?php echo academyHtmlspecialchars($academyName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="assets/css/coach-portal.css">
</head>
<body class="light-mode" data-reset-url="<?php echo academyHtmlspecialchars($selectedAdministrator !== null ? 'admin_portal.php?logout=1' : 'admin_portal.php', ENT_QUOTES, 'UTF-8'); ?>">
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
                <span class="page-badge">🗂️ بوابة الإداريين</span>
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
            <h2>🔐 تسجيل دخول الإداري</h2>
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
                    <?php echo $selectedAdministrator === null ? 'aria-required="true"' : ''; ?>
                    <?php echo $selectedAdministrator === null ? 'required' : ''; ?>
                >
            </div>

            <div class="search-actions">
                <button type="submit" class="primary-btn">🔓 دخول وعرض البيانات</button>
                <a href="<?php echo academyHtmlspecialchars($selectedAdministrator !== null ? 'admin_portal.php?logout=1' : 'admin_portal.php', ENT_QUOTES, 'UTF-8'); ?>" class="secondary-btn" id="clearBtn">
                    <?php echo $selectedAdministrator !== null ? '🚪 تسجيل الخروج' : '🧹 مسح'; ?>
                </a>
            </div>
        </form>
    </section>

    <?php if ($message !== ''): ?>
        <div class="message-box <?php echo academyHtmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo academyHtmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($selectedAdministrator !== null): ?>
        <section class="coach-overview-card">
            <div class="coach-identity">
                <div class="coach-avatar">👨‍💼</div>
                <div>
                    <span class="mini-badge">👤 بيانات الإداري</span>
                    <h2><?php echo academyHtmlspecialchars($selectedAdministrator['full_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <div class="coach-meta">
                        <span>📞 <?php echo academyHtmlspecialchars($selectedAdministrator['phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span>🛌 أيام الإجازة: <?php echo academyHtmlspecialchars(formatAdminPortalLeaveDays($selectedAdministrator['leave_days'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span>
                            🗓️ الفترة الحالية:
                            <?php echo academyHtmlspecialchars((string) ($administratorTotals['period_start'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                            →
                            <?php echo academyHtmlspecialchars((string) ($administratorTotals['period_end'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="availability-pill <?php echo (float) ($latestPayment['net_amount'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
                <span>💳 آخر صافي قبض مسجل</span>
                <strong>
                    <?php echo $latestPayment !== null
                        ? academyHtmlspecialchars(formatAdminPortalAmount((float) ($latestPayment['net_amount'] ?? 0)), ENT_QUOTES, 'UTF-8')
                        : 'لا يوجد'; ?>
                </strong>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card accent-orange">
                <span class="stat-icon">⏱️</span>
                <div>
                    <strong><?php echo academyHtmlspecialchars(formatAdminPortalAmount((float) $administratorTotals['total_hours']), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <p>عدد الساعات الحالية</p>
                </div>
            </article>

            <article class="stat-card accent-green">
                <span class="stat-icon">✅</span>
                <div>
                    <strong><?php echo (int) $administratorTotals['attendance_days']; ?></strong>
                    <p>عدد أيام الحضور</p>
                </div>
            </article>

            <article class="stat-card accent-red">
                <span class="stat-icon">❌</span>
                <div>
                    <strong><?php echo (int) $administratorTotals['absence_days']; ?></strong>
                    <p>عدد أيام الغياب داخل الفترة الحالية</p>
                </div>
            </article>

            <article class="stat-card accent-blue">
                <span class="stat-icon">🧾</span>
                <div>
                    <strong><?php echo academyHtmlspecialchars(formatAdminPortalAmount((float) $administratorTotals['total_advances']), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <p>إجمالي السلف الحالية</p>
                </div>
            </article>

            <article class="stat-card accent-gold">
                <span class="stat-icon">📚</span>
                <div>
                    <strong><?php echo count($administratorPaymentHistory); ?></strong>
                    <p>عدد مرات القبض السابقة</p>
                </div>
            </article>

            <article class="stat-card accent-purple">
                <span class="stat-icon">💵</span>
                <div>
                    <strong>
                        <?php echo $latestPayment !== null
                            ? academyHtmlspecialchars(formatAdminPortalAmount((float) ($latestPayment['salary_amount'] ?? 0)), ENT_QUOTES, 'UTF-8')
                            : '0.00'; ?>
                    </strong>
                    <p>آخر مرتب مسجل</p>
                </div>
            </article>
        </section>

        <section class="content-grid">
            <article class="table-card">
                <div class="card-head">
                    <h2>🗓️ الحضور والغياب الحالي</h2>
                </div>

                <?php if ($administratorDailyRecords !== []): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>اليوم</th>
                                    <th>الحالة</th>
                                    <th>وقت الحضور</th>
                                    <th>وقت الانصراف</th>
                                    <th>الساعات</th>
                                    <th>السلفة اليومية</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($administratorDailyRecords as $dailyRecord): ?>
                                    <?php
                                        $isPresent = !empty($dailyRecord['attendance_status']);
                                        $isLeaveDay = !empty($dailyRecord['is_leave_day']);
                                        $statusClass = $isPresent ? 'present' : ($isLeaveDay ? 'neutral' : 'absent');
                                        $statusLabel = $isPresent ? '✅ حاضر' : ($isLeaveDay ? '🛌 إجازة' : '❌ غائب');
                                    ?>
                                    <tr>
                                        <td data-label="اليوم"><?php echo academyHtmlspecialchars(formatAdminPortalDateLabel((string) $dailyRecord['date']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الحالة">
                                            <span class="status-pill <?php echo $statusClass; ?>">
                                                <?php echo $statusLabel; ?>
                                            </span>
                                        </td>
                                        <td data-label="وقت الحضور"><?php echo academyHtmlspecialchars(formatAdminPortalTime($dailyRecord['check_in_time'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="وقت الانصراف"><?php echo academyHtmlspecialchars(formatAdminPortalTime($dailyRecord['check_out_time'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الساعات"><?php echo academyHtmlspecialchars(formatAdminPortalAmount((float) $dailyRecord['work_hours']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="السلفة اليومية"><?php echo academyHtmlspecialchars(formatAdminPortalAmount((float) $dailyRecord['advance_amount']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">🌟 لا توجد بيانات حالية معلقة لهذا الإداري.</div>
                <?php endif; ?>
            </article>

            <article class="table-card">
                <div class="card-head">
                    <h2>💳 سجل القبض</h2>
                </div>

                <?php if ($administratorPaymentHistory !== []): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>نوع القبض</th>
                                    <th>الفترة</th>
                                    <th>أيام الحضور</th>
                                    <th>الساعات</th>
                                    <th>المرتب</th>
                                    <th>السلف</th>
                                    <th>الصافي</th>
                                    <th>تاريخ القبض</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($administratorPaymentHistory as $historyItem): ?>
                                    <tr>
                                        <td data-label="نوع القبض"><?php echo academyHtmlspecialchars(ADMIN_PORTAL_PAYMENT_CYCLES[$historyItem['payment_cycle']] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الفترة">
                                            <?php echo academyHtmlspecialchars((string) ($historyItem['period_start'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                            →
                                            <?php echo academyHtmlspecialchars((string) ($historyItem['period_end'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td data-label="أيام الحضور"><?php echo (int) ($historyItem['attendance_days'] ?? 0); ?></td>
                                        <td data-label="الساعات"><?php echo academyHtmlspecialchars(formatAdminPortalAmount((float) ($historyItem['total_hours'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="المرتب"><?php echo academyHtmlspecialchars(formatAdminPortalAmount((float) ($historyItem['salary_amount'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="السلف"><?php echo academyHtmlspecialchars(formatAdminPortalAmount((float) ($historyItem['total_advances'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="الصافي" class="<?php echo (float) ($historyItem['net_amount'] ?? 0) >= 0 ? 'positive-text' : 'negative-text'; ?>">
                                            <?php echo academyHtmlspecialchars(formatAdminPortalAmount((float) ($historyItem['net_amount'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td data-label="تاريخ القبض"><?php echo academyHtmlspecialchars((string) ($historyItem['paid_at'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">🧾 لا يوجد سجل قبض مسجل لهذا الإداري حتى الآن.</div>
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
