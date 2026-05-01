<?php
session_start();
require_once "config.php";
require_once "app_helpers.php";

if (!isset($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

$currentUser = loadCurrentUser($pdo);

if ($currentUser === null) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

if (!userCanAccess($currentUser, "admin_salaries")) {
    header("Location: dashboard.php?access=denied");
    exit;
}

const ADMIN_SALARY_PAYMENT_CYCLES = [
    'weekly' => 'أسبوعي',
    'monthly' => 'شهري',
];
const MAX_ADMIN_SALARY_AMOUNT = 999999.99;

function buildAdminSalariesPageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return 'admin_salaries.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function generateAdminSalarySecurityToken(): string
{
    try {
        return bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $isStrong = false;
            $randomBytes = openssl_random_pseudo_bytes(32, $isStrong);
            if ($randomBytes !== false && $isStrong) {
                return bin2hex($randomBytes);
            }
        }
    }

    throw new RuntimeException('تعذر إنشاء رمز أمان لقبض مرتبات الإداريين');
}

function getAdminSalaryCsrfToken(): string
{
    if (
        !isset($_SESSION['admin_salary_csrf_token'])
        || !is_string($_SESSION['admin_salary_csrf_token'])
        || $_SESSION['admin_salary_csrf_token'] === ''
    ) {
        try {
            $_SESSION['admin_salary_csrf_token'] = generateAdminSalarySecurityToken();
        } catch (Throwable $exception) {
            error_log('تعذر إنشاء رمز التحقق الخاص بقبض مرتبات الإداريين');
            http_response_code(500);
            exit('❌ تعذر تهيئة أمان الصفحة، يرجى إعادة المحاولة لاحقًا');
        }
    }

    return $_SESSION['admin_salary_csrf_token'];
}

function isValidAdminSalaryCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getAdminSalaryCsrfToken(), $submittedToken);
}

function setAdminSalaryFlash(string $message, string $type): void
{
    $_SESSION['admin_salary_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumeAdminSalaryFlash(): array
{
    $flash = $_SESSION['admin_salary_flash'] ?? null;
    unset($_SESSION['admin_salary_flash']);

    if (!is_array($flash)) {
        return [
            'message' => '',
            'type' => '',
        ];
    }

    return [
        'message' => (string) ($flash['message'] ?? ''),
        'type' => (string) ($flash['type'] ?? ''),
    ];
}

function normalizeAdminSalaryArabicNumbers(string $value): string
{
    return strtr($value, [
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9',
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
    ]);
}

function sanitizeAdminSalaryAmount(string $value): string
{
    $value = trim(normalizeAdminSalaryArabicNumbers($value));
    $value = str_replace(',', '.', $value);
    $value = preg_replace('/[^0-9.]/', '', $value);

    if ($value === null) {
        return '';
    }

    $parts = explode('.', $value);
    if (count($parts) > 2) {
        $value = $parts[0] . '.' . implode('', array_slice($parts, 1));
    }

    return $value;
}

function isValidAdminSalaryAmount(string $value): bool
{
    return $value !== ''
        && preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1
        && (float) $value >= 0
        && (float) $value <= MAX_ADMIN_SALARY_AMOUNT;
}

function formatAdminSalaryAmount(float $value): string
{
    return number_format($value, 2, '.', '');
}

function encodeAdminSalarySnapshot(array $rows): string
{
    $encodedRows = json_encode($rows, JSON_UNESCAPED_UNICODE);
    return is_string($encodedRows) && $encodedRows !== '' ? $encodedRows : '[]';
}

function fetchAdminSalaryAttendanceRows(PDO $pdo, int $administratorId): array
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

function fetchAdminSalaryAdvanceRows(PDO $pdo, int $administratorId): array
{
    $advanceStmt = $pdo->prepare(
        "SELECT advance_date, amount, created_at
         FROM admin_advances
         WHERE administrator_id = ?
         ORDER BY advance_date ASC, id ASC"
    );
    $advanceStmt->execute([$administratorId]);

    return $advanceStmt->fetchAll(PDO::FETCH_ASSOC);
}

function calculateAdminSalaryTotals(
    array $attendanceRows,
    array $advanceRows,
    float $salaryAmount,
    ?string $leaveDays = null
): array
{
    $totalHours = 0.0;
    $totalAdvances = 0.0;
    $dailyRecords = buildAdminSalaryDailyRecords($attendanceRows, $advanceRows, $leaveDays);
    $attendanceDays = 0;
    $absenceDays = 0;

    foreach ($attendanceRows as $attendanceRow) {
        $totalHours += (float) ($attendanceRow['work_hours'] ?? 0);
    }

    foreach ($advanceRows as $advanceRow) {
        $totalAdvances += (float) ($advanceRow['amount'] ?? 0);
    }

    foreach ($dailyRecords as $dailyRecord) {
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
        'salary_amount' => $salaryAmount,
        'net_amount' => $salaryAmount - $totalAdvances,
        'attendance_days' => $attendanceDays,
        'absence_days' => $absenceDays,
        'advance_records_count' => count($advanceRows),
        'period_start' => $periodBounds['period_start'],
        'period_end' => $periodBounds['period_end'],
    ];
}

function buildAdminSalaryDailyRecords(array $attendanceRows, array $advanceRows, ?string $leaveDays = null): array
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

function formatAdminSalaryDateTime(?string $value): string
{
    if (!is_string($value) || trim($value) === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '—';
    }

    return date('H:i:s', $timestamp);
}

$flashMessage = consumeAdminSalaryFlash();
$message = $flashMessage['message'];
$messageType = $flashMessage['type'];
$selectedAdministratorId = trim($_GET['administrator_id'] ?? '');
$formPaymentCycle = 'monthly';
$formSalaryAmount = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $selectedAdministratorId = trim($_POST['administrator_id'] ?? $selectedAdministratorId);
    $formPaymentCycle = trim($_POST['payment_cycle'] ?? $formPaymentCycle);
    $formSalaryAmount = sanitizeAdminSalaryAmount($_POST['salary_amount'] ?? $formSalaryAmount);

    if ($action !== '' && !isValidAdminSalaryCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = '❌ انتهت صلاحية الطلب، يرجى إعادة المحاولة';
        $messageType = 'error';
        $action = '';
    }

    if ($action === 'pay') {
        if ($selectedAdministratorId === '' || ctype_digit($selectedAdministratorId) === false) {
            $message = '❌ يرجى اختيار الإداري من القائمة';
            $messageType = 'error';
        } elseif (!array_key_exists($formPaymentCycle, ADMIN_SALARY_PAYMENT_CYCLES)) {
            $message = '❌ يرجى اختيار نوع القبض';
            $messageType = 'error';
        } elseif (!isValidAdminSalaryAmount($formSalaryAmount)) {
            $message = '❌ يرجى إدخال مرتب صحيح';
            $messageType = 'error';
        } else {
            try {
                $administratorStmt = $pdo->prepare(
                    "SELECT id, full_name, phone, leave_days
                     FROM administrators
                     WHERE id = ?
                     LIMIT 1"
                );
                $administratorStmt->execute([(int) $selectedAdministratorId]);
                $selectedAdministrator = $administratorStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                if ($selectedAdministrator === null) {
                    $message = '❌ الإداري المطلوب غير موجود';
                    $messageType = 'error';
                } else {
                    $attendanceRows = fetchAdminSalaryAttendanceRows($pdo, (int) $selectedAdministratorId);
                    $advanceRows = fetchAdminSalaryAdvanceRows($pdo, (int) $selectedAdministratorId);

                    if ($attendanceRows === [] && $advanceRows === []) {
                        $message = '❌ لا توجد بيانات حالية لهذا الإداري';
                        $messageType = 'error';
                    } else {
                        $salaryAmount = (float) $formSalaryAmount;
                        $totals = calculateAdminSalaryTotals(
                            $attendanceRows,
                            $advanceRows,
                            $salaryAmount,
                            $selectedAdministrator['leave_days'] ?? null
                        );
                        $attendanceSnapshot = encodeAdminSalarySnapshot($attendanceRows);
                        $advancesSnapshot = encodeAdminSalarySnapshot($advanceRows);

                        $pdo->beginTransaction();

                        $insertStmt = $pdo->prepare(
                            "INSERT INTO admin_salary_payments (
                                administrator_id,
                                payment_cycle,
                                period_start,
                                period_end,
                                total_hours,
                                salary_amount,
                                total_advances,
                                net_amount,
                                attendance_days,
                                advance_records_count,
                                paid_by_user_id,
                                attendance_snapshot,
                                advances_snapshot
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->execute([
                            (int) $selectedAdministratorId,
                            $formPaymentCycle,
                            $totals['period_start'],
                            $totals['period_end'],
                            formatAdminSalaryAmount((float) $totals['total_hours']),
                            formatAdminSalaryAmount((float) $totals['salary_amount']),
                            formatAdminSalaryAmount((float) $totals['total_advances']),
                            formatAdminSalaryAmount((float) $totals['net_amount']),
                            (int) $totals['attendance_days'],
                            (int) $totals['advance_records_count'],
                            (int) ($currentUser['id'] ?? 0),
                            $attendanceSnapshot,
                            $advancesSnapshot,
                        ]);

                        $deleteAttendanceStmt = $pdo->prepare("DELETE FROM administrator_attendance WHERE administrator_id = ?");
                        $deleteAttendanceStmt->execute([(int) $selectedAdministratorId]);

                        $deleteAdvancesStmt = $pdo->prepare("DELETE FROM admin_advances WHERE administrator_id = ?");
                        $deleteAdvancesStmt->execute([(int) $selectedAdministratorId]);

                        $pdo->commit();

                        setAdminSalaryFlash('✅ تم تسجيل قبض الإداري وتصفير بياناته الحالية بنجاح', 'success');
                        header('Location: ' . buildAdminSalariesPageUrl(['administrator_id' => $selectedAdministratorId]));
                        exit;
                    }
                }
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = '❌ حدث خطأ أثناء تنفيذ قبض مرتب الإداري';
                $messageType = 'error';
            }
        }
    }
}

$administratorsStmt = $pdo->query(
    "SELECT
        a.id,
        a.full_name,
        a.phone,
        a.leave_days,
        COALESCE(att.total_hours, 0) AS total_hours,
        COALESCE(att.attendance_days, 0) AS attendance_days,
        COALESCE(att.last_attendance_date, '') AS last_attendance_date,
        COALESCE(adv.total_advances, 0) AS total_advances,
        COALESCE(adv.advance_records_count, 0) AS advance_records_count,
        COALESCE(adv.last_advance_date, '') AS last_advance_date
     FROM administrators a
     LEFT JOIN (
         SELECT
             administrator_id,
             SUM(work_hours) AS total_hours,
             COUNT(*) AS attendance_days,
             MAX(attendance_date) AS last_attendance_date
         FROM administrator_attendance
         GROUP BY administrator_id
     ) att ON att.administrator_id = a.id
     LEFT JOIN (
         SELECT
             administrator_id,
             SUM(amount) AS total_advances,
             COUNT(*) AS advance_records_count,
             MAX(advance_date) AS last_advance_date
         FROM admin_advances
         GROUP BY administrator_id
     ) adv ON adv.administrator_id = a.id
     ORDER BY
         (COALESCE(att.total_hours, 0) > 0 OR COALESCE(adv.total_advances, 0) > 0) DESC,
         a.full_name ASC"
);
$administrators = $administratorsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalAdministrators = count($administrators);
$administratorsReadyForPayment = 0;
$totalOutstandingHours = 0.0;
$totalOutstandingAdvances = 0.0;

foreach ($administrators as $administratorRow) {
    $administratorHours = (float) ($administratorRow['total_hours'] ?? 0);
    $administratorAdvances = (float) ($administratorRow['total_advances'] ?? 0);
    $totalOutstandingHours += $administratorHours;
    $totalOutstandingAdvances += $administratorAdvances;

    if ($administratorHours > 0 || $administratorAdvances > 0) {
        $administratorsReadyForPayment++;
    }
}

$selectedAdministrator = null;
foreach ($administrators as $administratorRow) {
    if ((string) $administratorRow['id'] === $selectedAdministratorId) {
        $selectedAdministrator = $administratorRow;
        break;
    }
}

if ($selectedAdministrator === null && $administrators !== []) {
    foreach ($administrators as $administratorRow) {
        if ((float) ($administratorRow['total_hours'] ?? 0) > 0 || (float) ($administratorRow['total_advances'] ?? 0) > 0) {
            $selectedAdministrator = $administratorRow;
            break;
        }
    }

    if ($selectedAdministrator === null) {
        $selectedAdministrator = $administrators[0];
    }

    $selectedAdministratorId = (string) ($selectedAdministrator['id'] ?? '');
}

$selectedAdministratorAttendanceRows = [];
$selectedAdministratorAdvanceRows = [];
$selectedAdministratorDailyRecords = [];
$selectedAdministratorHistory = [];
$selectedSalaryAmount = $formSalaryAmount !== '' ? (float) $formSalaryAmount : 0.0;
$selectedAdministratorTotals = [
    'total_hours' => 0.0,
    'total_advances' => 0.0,
    'salary_amount' => $selectedSalaryAmount,
    'net_amount' => $selectedSalaryAmount,
    'attendance_days' => 0,
    'absence_days' => 0,
    'advance_records_count' => 0,
    'period_start' => null,
    'period_end' => null,
];

if ($selectedAdministrator !== null) {
    $selectedAdministratorAttendanceRows = fetchAdminSalaryAttendanceRows($pdo, (int) $selectedAdministrator['id']);
    $selectedAdministratorAdvanceRows = fetchAdminSalaryAdvanceRows($pdo, (int) $selectedAdministrator['id']);
    $selectedAdministratorTotals = calculateAdminSalaryTotals(
        $selectedAdministratorAttendanceRows,
        $selectedAdministratorAdvanceRows,
        $selectedSalaryAmount,
        $selectedAdministrator['leave_days'] ?? null
    );
    $selectedAdministratorDailyRecords = buildAdminSalaryDailyRecords(
        $selectedAdministratorAttendanceRows,
        $selectedAdministratorAdvanceRows,
        $selectedAdministrator['leave_days'] ?? null
    );

    $historyStmt = $pdo->prepare(
        "SELECT
            p.id,
            p.payment_cycle,
            p.period_start,
            p.period_end,
            p.total_hours,
            p.salary_amount,
            p.total_advances,
            p.net_amount,
            p.attendance_days,
            p.advance_records_count,
            p.paid_at,
            u.username AS paid_by_username
         FROM admin_salary_payments p
         LEFT JOIN users u ON u.id = p.paid_by_user_id
         WHERE p.administrator_id = ?
         ORDER BY p.paid_at DESC, p.id DESC"
    );
    $historyStmt->execute([(int) $selectedAdministrator['id']]);
    $selectedAdministratorHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
}

$adminSalaryCsrfToken = getAdminSalaryCsrfToken();
$hasAdministrators = !empty($administrators);
$hasSelectedAdministratorData = $selectedAdministrator !== null
    && ($selectedAdministratorAttendanceRows !== [] || $selectedAdministratorAdvanceRows !== []);
$historyColspan = 9;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>قبض مرتبات الإداريين</title>
    <link rel="stylesheet" href="assets/css/admin-salaries.css">
</head>
<body
    class="light-mode"
    data-reset-url="<?php echo academyHtmlspecialchars(buildAdminSalariesPageUrl(), ENT_QUOTES, 'UTF-8'); ?>"
    data-default-confirm-message="هل أنت متأكد من تنفيذ القبض الآن؟"
>
<div class="coach-salaries-page admin-salaries-page">
    <header class="page-header">
        <div class="header-text">
            <span class="section-badge">💳 قبض مرتبات الإداريين</span>
            <h1>قبض مرتبات الإداريين</h1>
        </div>

        <div class="header-actions">
            <div class="theme-switch-box">
                <span>☀️</span>
                <label class="switch">
                    <input type="checkbox" id="themeToggle">
                    <span class="slider"></span>
                </label>
                <span>🌙</span>
            </div>

            <a href="dashboard.php" class="back-btn">⬅️ الرجوع للوحة التحكم</a>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div class="message-box <?php echo academyHtmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo academyHtmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <section class="stats-grid">
        <article class="stat-card total-card">
            <div class="stat-icon">👥</div>
            <div>
                <h2><?php echo $totalAdministrators; ?></h2>
                <p>إجمالي الإداريين</p>
            </div>
        </article>

        <article class="stat-card present-card">
            <div class="stat-icon">✅</div>
            <div>
                <h2><?php echo $administratorsReadyForPayment; ?></h2>
                <p>إداريون جاهزون للقبض</p>
            </div>
        </article>

        <article class="stat-card absent-card salary-stat-card">
            <div class="stat-icon">⏱️</div>
            <div>
                <h2><?php echo academyHtmlspecialchars(number_format($totalOutstandingHours, 2), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p>إجمالي الساعات الحالية</p>
            </div>
        </article>

        <article class="stat-card hours-card salary-stat-card">
            <div class="stat-icon">💵</div>
            <div>
                <h2><?php echo academyHtmlspecialchars(number_format($totalOutstandingAdvances, 2), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p>إجمالي السلف الحالية</p>
            </div>
        </article>
    </section>

    <section class="content-grid coach-salaries-content-grid">
        <article class="form-card salary-control-card">
            <div class="card-head">
                <h2>👤 بيانات القبض</h2>
            </div>

            <form method="GET" class="attendance-form salary-filter-form" autocomplete="off">
                <div class="form-row form-row-single">
                    <div class="form-group">
                        <label>اختيار الإداري</label>
                        <input type="hidden" name="administrator_id" id="administrator_id_filter" value="<?php echo academyHtmlspecialchars($selectedAdministratorId, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="professional-select" id="administratorSelect" data-placeholder="اختر الإداري من القائمة">
                            <button type="button" class="select-trigger" id="administratorSelectTrigger" aria-expanded="false">
                                <span class="select-trigger-text" id="administratorSelectText">
                                    <?php echo $selectedAdministrator ? academyHtmlspecialchars($selectedAdministrator['full_name'], ENT_QUOTES, 'UTF-8') : 'اختر الإداري من القائمة'; ?>
                                </span>
                                <span class="select-trigger-icon">▾</span>
                            </button>

                            <div class="select-dropdown" id="administratorSelectDropdown" hidden>
                                <div class="select-search-box">
                                    <input type="search" id="administratorSearchInput" placeholder="ابحث باسم الإداري أو الهاتف">
                                </div>

                                <div class="select-options" id="administratorOptionsList">
                                    <?php foreach ($administrators as $administrator): ?>
                                        <?php
                                            $administratorHasData = (float) ($administrator['total_hours'] ?? 0) > 0 || (float) ($administrator['total_advances'] ?? 0) > 0;
                                        ?>
                                        <button
                                            type="button"
                                            class="select-option<?php echo (string) $administrator['id'] === $selectedAdministratorId ? ' is-selected' : ''; ?>"
                                            data-value="<?php echo academyHtmlspecialchars((string) $administrator['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-name="<?php echo academyHtmlspecialchars($administrator['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-phone="<?php echo academyHtmlspecialchars($administrator['phone'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-hours="<?php echo academyHtmlspecialchars(number_format((float) $administrator['total_hours'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-advances="<?php echo academyHtmlspecialchars(number_format((float) $administrator['total_advances'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-status="<?php echo $administratorHasData ? 'جاهز للقبض' : 'لا توجد بيانات حالية'; ?>"
                                        >
                                            <span class="option-name"><?php echo academyHtmlspecialchars($administrator['full_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="option-meta">
                                                📞 <?php echo academyHtmlspecialchars($administrator['phone'], ENT_QUOTES, 'UTF-8'); ?>
                                                • ⏱️ <?php echo academyHtmlspecialchars(number_format((float) $administrator['total_hours'], 2), ENT_QUOTES, 'UTF-8'); ?>
                                                • 💵 <?php echo academyHtmlspecialchars(number_format((float) $administrator['total_advances'], 2), ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>

                                <p class="select-empty" id="administratorSelectEmpty"<?php echo $hasAdministrators ? ' hidden' : ''; ?>>لا يوجد إداريون مسجلون حالياً.</p>
                            </div>
                        </div>

                        <div class="selected-coach-meta salary-selected-meta" id="selectedAdministratorMeta">
                            <?php if ($selectedAdministrator): ?>
                                <span>📞 <?php echo academyHtmlspecialchars($selectedAdministrator['phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span>⏱️ <?php echo academyHtmlspecialchars(number_format((float) $selectedAdministrator['total_hours'], 2), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span>💵 <?php echo academyHtmlspecialchars(number_format((float) $selectedAdministrator['total_advances'], 2), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="form-actions salary-filter-actions">
                    <button type="submit" class="filter-btn"<?php echo !$hasAdministrators ? ' disabled' : ''; ?>>عرض البيانات</button>
                    <a href="<?php echo academyHtmlspecialchars(buildAdminSalariesPageUrl(), ENT_QUOTES, 'UTF-8'); ?>" class="clear-btn" id="clearBtn">تحديث</a>
                </div>
            </form>

            <?php if ($selectedAdministrator !== null): ?>
                <div class="salary-summary-grid">
                    <article class="salary-summary-box">
                        <span>أيام الحضور</span>
                        <strong><?php echo (int) $selectedAdministratorTotals['attendance_days']; ?> يوم</strong>
                    </article>
                    <article class="salary-summary-box">
                        <span>أيام الغياب</span>
                        <strong><?php echo (int) $selectedAdministratorTotals['absence_days']; ?> يوم</strong>
                    </article>
                    <article class="salary-summary-box">
                        <span>إجمالي الساعات</span>
                        <strong><?php echo academyHtmlspecialchars(number_format((float) $selectedAdministratorTotals['total_hours'], 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </article>
                    <article class="salary-summary-box">
                        <span>إجمالي السلف</span>
                        <strong><?php echo academyHtmlspecialchars(number_format((float) $selectedAdministratorTotals['total_advances'], 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </article>
                    <article class="salary-summary-box">
                        <span>سجلات السلف</span>
                        <strong><?php echo (int) $selectedAdministratorTotals['advance_records_count']; ?></strong>
                    </article>
                    <article class="salary-summary-box salary-summary-highlight">
                        <span>المرتب المتاح</span>
                        <strong id="salaryAmountPreview"><?php echo academyHtmlspecialchars(number_format((float) $selectedAdministratorTotals['salary_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </article>
                    <article class="salary-summary-box salary-summary-net <?php echo (float) $selectedAdministratorTotals['net_amount'] >= 0 ? 'is-positive' : 'is-negative'; ?>">
                        <span>صافي القبض</span>
                        <strong id="salaryNetPreview"><?php echo academyHtmlspecialchars(number_format((float) $selectedAdministratorTotals['net_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </article>
                </div>

                <form method="POST" class="attendance-form salary-payment-form js-confirm-submit" data-confirm-message="هل تريد تسجيل قبض هذا الإداري وتصفير بياناته الحالية؟">
                    <input type="hidden" name="action" value="pay">
                    <input type="hidden" name="administrator_id" value="<?php echo academyHtmlspecialchars((string) $selectedAdministrator['id'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($adminSalaryCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-group">
                        <label>نوع القبض</label>
                        <div class="payment-cycle-grid">
                            <?php foreach (ADMIN_SALARY_PAYMENT_CYCLES as $cycleKey => $cycleLabel): ?>
                                <label class="payment-cycle-option<?php echo $formPaymentCycle === $cycleKey ? ' is-active' : ''; ?>">
                                    <input type="radio" name="payment_cycle" value="<?php echo academyHtmlspecialchars($cycleKey, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $formPaymentCycle === $cycleKey ? ' checked' : ''; ?>>
                                    <span><?php echo academyHtmlspecialchars($cycleLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-group-highlight">
                            <label for="salary_amount">💳 المرتب المتاح</label>
                            <input
                                type="text"
                                id="salary_amount"
                                name="salary_amount"
                                value="<?php echo academyHtmlspecialchars($formSalaryAmount, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="مثال: 3000 أو 4500.75"
                                inputmode="decimal"
                                maxlength="10"
                                pattern="[0-9٠-٩۰-۹]+([\\.,][0-9٠-٩۰-۹]{1,2})?"
                                data-total-advances="<?php echo academyHtmlspecialchars(number_format((float) $selectedAdministratorTotals['total_advances'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                                required
                            >
                        </div>

                        <div class="form-group form-group-highlight">
                            <label>📌 صافي القبض الحالي</label>
                            <div class="estimated-box advance-total-box <?php echo (float) $selectedAdministratorTotals['net_amount'] >= 0 ? 'positive-text' : 'negative-text'; ?>" id="salaryNetBox">
                                <?php echo academyHtmlspecialchars(number_format((float) $selectedAdministratorTotals['net_amount'], 2), ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </div>
                    </div>

                    <div class="salary-period-row">
                        <span class="table-summary-chip">من <?php echo academyHtmlspecialchars((string) ($selectedAdministratorTotals['period_start'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="table-summary-chip">إلى <?php echo academyHtmlspecialchars((string) ($selectedAdministratorTotals['period_end'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="save-btn"<?php echo !$hasSelectedAdministratorData ? ' disabled' : ''; ?>>💳 تسجيل القبض</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="empty-state">لا يوجد إداريون لعرضهم.</div>
            <?php endif; ?>
        </article>

        <article class="absences-card salary-history-preview-card">
            <div class="card-head">
                <h2>🧾 سجل قبض الإداري</h2>
            </div>

            <?php if (!empty($selectedAdministratorHistory)): ?>
                <div class="salary-history-preview-list">
                    <?php foreach (array_slice($selectedAdministratorHistory, 0, 5) as $historyItem): ?>
                        <div class="salary-history-preview-item">
                            <div>
                                <strong><?php echo academyHtmlspecialchars(ADMIN_SALARY_PAYMENT_CYCLES[$historyItem['payment_cycle']] ?? '—', ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span><?php echo academyHtmlspecialchars((string) $historyItem['period_start'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo academyHtmlspecialchars((string) $historyItem['period_end'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="salary-history-preview-meta">
                                <span><?php echo academyHtmlspecialchars(date('Y-m-d H:i', strtotime((string) $historyItem['paid_at'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                <strong><?php echo academyHtmlspecialchars(number_format((float) $historyItem['net_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($selectedAdministrator !== null): ?>
                <div class="empty-state">لا يوجد سجل قبض لهذا الإداري حتى الآن.</div>
            <?php else: ?>
                <div class="empty-state">اختر إداريًا لعرض السجل.</div>
            <?php endif; ?>
        </article>
    </section>

    <section class="table-card">
        <div class="card-head card-head-inline">
            <div>
                <h2>📅 سجل حضور وانصراف الأيام الحالية</h2>
            </div>
            <?php if ($selectedAdministrator !== null): ?>
                <div class="table-summary-chip"><?php echo academyHtmlspecialchars($selectedAdministrator['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>التاريخ</th>
                        <th>الحضور</th>
                        <th>وقت الحضور</th>
                        <th>وقت الانصراف</th>
                        <th>عدد الساعات</th>
                        <th>سلف اليوم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($selectedAdministratorDailyRecords)): ?>
                        <?php foreach ($selectedAdministratorDailyRecords as $index => $dailyRecord): ?>
                            <?php
                                $isLeaveDay = !empty($dailyRecord['is_leave_day']);
                                $statusClass = !empty($dailyRecord['attendance_status'])
                                    ? 'present-badge'
                                    : ($isLeaveDay ? 'leave-badge' : 'absent-badge');
                                $statusLabel = !empty($dailyRecord['attendance_status'])
                                    ? 'مسجل'
                                    : ($isLeaveDay ? 'إجازة' : 'بدون حضور');
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo academyHtmlspecialchars($dailyRecord['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo $statusLabel; ?>
                                    </span>
                                </td>
                                <td><?php echo academyHtmlspecialchars(formatAdminSalaryDateTime($dailyRecord['check_in_time']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(formatAdminSalaryDateTime($dailyRecord['check_out_time']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(number_format((float) $dailyRecord['work_hours'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(number_format((float) $dailyRecord['advance_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="empty-row">لا توجد بيانات حالية لهذا الإداري.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="table-card">
        <div class="card-head card-head-inline">
            <div>
                <h2>🗂️ سجل القبض الكامل</h2>
            </div>
            <?php if ($selectedAdministrator !== null): ?>
                <div class="table-summary-chip"><?php echo academyHtmlspecialchars($selectedAdministrator['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>تاريخ القبض</th>
                        <th>نوع القبض</th>
                        <th>فترة الحساب</th>
                        <th>إجمالي الساعات</th>
                        <th>إجمالي السلف</th>
                        <th>المرتب</th>
                        <th>صافي القبض</th>
                        <th>المستخدم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($selectedAdministratorHistory)): ?>
                        <?php foreach ($selectedAdministratorHistory as $index => $historyItem): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo academyHtmlspecialchars(date('Y-m-d H:i', strtotime((string) $historyItem['paid_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(ADMIN_SALARY_PAYMENT_CYCLES[$historyItem['payment_cycle']] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars((string) $historyItem['period_start'], ENT_QUOTES, 'UTF-8'); ?> / <?php echo academyHtmlspecialchars((string) $historyItem['period_end'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(number_format((float) $historyItem['total_hours'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(number_format((float) $historyItem['total_advances'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(number_format((float) $historyItem['salary_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="<?php echo (float) $historyItem['net_amount'] >= 0 ? 'positive-text' : 'negative-text'; ?>"><?php echo academyHtmlspecialchars(number_format((float) $historyItem['net_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars((string) ($historyItem['paid_by_username'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $historyColspan; ?>" class="empty-row">لا يوجد سجل قبض لهذا الإداري حتى الآن.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script src="assets/js/admin-salaries.js"></script>
</body>
</html>
