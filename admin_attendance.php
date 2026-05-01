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

if (!userCanAccess($currentUser, "admin_attendance")) {
    header("Location: dashboard.php?access=denied");
    exit;
}

const ADMIN_ATTENDANCE_DUPLICATE_KEY_ERROR = 1062;
const ADMIN_ATTENDANCE_TIMEZONE = 'Africa/Cairo';
const ADMIN_ATTENDANCE_MAX_HOURS = 24;

function adminAttendanceTimezone(): DateTimeZone
{
    static $timezone = null;

    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone(ADMIN_ATTENDANCE_TIMEZONE);
    }

    return $timezone;
}

function adminAttendanceNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', adminAttendanceTimezone());
}

function normalizeAdminAttendanceArabicNumbers(string $value): string
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

function sanitizeAdminAttendanceBarcode(string $value): string
{
    $value = trim(normalizeAdminAttendanceArabicNumbers($value));
    $normalizedValue = preg_replace('/\s+/u', '', $value);
    return $normalizedValue === null ? '' : $normalizedValue;
}

function isValidAdminAttendanceDate(string $value): bool
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value, adminAttendanceTimezone());
    return $date !== false && $date->format('Y-m-d') === $value;
}

function buildAdminAttendancePageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return 'admin_attendance.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function generateAdminAttendanceSecurityToken(): string
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

    throw new RuntimeException('تعذر إنشاء رمز أمان لحضور الإداريين');
}

function getAdminAttendanceCsrfToken(): string
{
    if (
        !isset($_SESSION['admin_attendance_csrf_token'])
        || !is_string($_SESSION['admin_attendance_csrf_token'])
        || $_SESSION['admin_attendance_csrf_token'] === ''
    ) {
        try {
            $_SESSION['admin_attendance_csrf_token'] = generateAdminAttendanceSecurityToken();
        } catch (Throwable $exception) {
            error_log('تعذر إنشاء رمز التحقق الخاص بحضور الإداريين');
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>خطأ</title></head><body>تعذر تهيئة الصفحة</body></html>';
            exit;
        }
    }

    return $_SESSION['admin_attendance_csrf_token'];
}

function isValidAdminAttendanceCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getAdminAttendanceCsrfToken(), $submittedToken);
}

function setAdminAttendanceFlash(string $message, string $type): void
{
    $_SESSION['admin_attendance_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumeAdminAttendanceFlash(): array
{
    $flash = $_SESSION['admin_attendance_flash'] ?? null;
    unset($_SESSION['admin_attendance_flash']);

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

function getAdministratorAttendanceLeaveDayOptions(): array
{
    return [
        'Saturday' => 'السبت',
        'Sunday' => 'الأحد',
        'Monday' => 'الاثنين',
        'Tuesday' => 'الثلاثاء',
        'Wednesday' => 'الأربعاء',
        'Thursday' => 'الخميس',
        'Friday' => 'الجمعة',
    ];
}

function decodeAdministratorAttendanceLeaveDays(?string $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decodedValue = json_decode($value, true);
    if (!is_array($decodedValue)) {
        return [];
    }

    $allowedValues = array_keys(getAdministratorAttendanceLeaveDayOptions());
    $leaveDays = [];

    foreach ($decodedValue as $leaveDay) {
        if (is_string($leaveDay) && in_array($leaveDay, $allowedValues, true)) {
            $leaveDays[] = $leaveDay;
        }
    }

    return array_values(array_unique($leaveDays));
}

function isAdministratorAttendanceLeaveDay(?string $leaveDays, string $dayName): bool
{
    return in_array($dayName, decodeAdministratorAttendanceLeaveDays($leaveDays), true);
}

function getAdministratorAttendanceDayLabel(string $dayName): string
{
    $dayOptions = getAdministratorAttendanceLeaveDayOptions();
    return $dayOptions[$dayName] ?? $dayName;
}

function getAdministratorAttendanceStorageDayName(DateTimeImmutable $date): string
{
    return $date->format('l');
}

function createAdministratorAttendanceDate(string $value, string $format): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat($format, $value, adminAttendanceTimezone());
    return $date instanceof DateTimeImmutable ? $date : null;
}

function calculateAdministratorAttendanceWorkHours(string $checkInTime, string $checkOutTime): float
{
    $checkInDate = createAdministratorAttendanceDate($checkInTime, 'Y-m-d H:i:s');
    $checkOutDate = createAdministratorAttendanceDate($checkOutTime, 'Y-m-d H:i:s');

    if ($checkInDate === null || $checkOutDate === null) {
        return 0.0;
    }

    $seconds = max(0, $checkOutDate->getTimestamp() - $checkInDate->getTimestamp());
    $seconds = min($seconds, ADMIN_ATTENDANCE_MAX_HOURS * 3600);

    return round($seconds / 3600, 2);
}

function calculateAdministratorAttendanceOpenHours(?string $checkInTime, DateTimeImmutable $now): float
{
    if (!is_string($checkInTime) || trim($checkInTime) === '') {
        return 0.0;
    }

    return calculateAdministratorAttendanceWorkHours($checkInTime, $now->format('Y-m-d H:i:s'));
}

function formatAdministratorAttendanceDateTime(?string $value): string
{
    if (!is_string($value) || trim($value) === '') {
        return '—';
    }

    $date = createAdministratorAttendanceDate($value, 'Y-m-d H:i:s');
    if ($date === null) {
        return '—';
    }

    return $date->format('H:i:s');
}

function formatAdministratorAttendanceHours(float $value): string
{
    return number_format($value, 2);
}

function getAdministratorAttendanceInitial(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '؟';
    }

    if (function_exists('mb_substr')) {
        return (string) mb_substr($name, 0, 1, 'UTF-8');
    }

    if (preg_match('/./u', $name, $matches) === 1) {
        return $matches[0];
    }

    return substr($name, 0, 1);
}

$nowEgypt = adminAttendanceNow();
$todayDate = $nowEgypt->format('Y-m-d');
$selectedDate = trim($_GET['date'] ?? $todayDate);
if (!isValidAdminAttendanceDate($selectedDate)) {
    $selectedDate = $todayDate;
}

$flashMessage = consumeAdminAttendanceFlash();
$message = $flashMessage['message'];
$messageType = $flashMessage['type'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action !== '' && !isValidAdminAttendanceCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = '❌ انتهت صلاحية الطلب';
        $messageType = 'error';
        $action = '';
    }

    if ($action === 'register') {
        $barcode = sanitizeAdminAttendanceBarcode($_POST['barcode'] ?? '');

        if ($barcode === '') {
            $message = '❌ الباركود غير صحيح';
            $messageType = 'error';
        } else {
            $nowEgypt = adminAttendanceNow();
            $todayDate = $nowEgypt->format('Y-m-d');
            $todayDayName = getAdministratorAttendanceStorageDayName($nowEgypt);
            $currentTimestamp = $nowEgypt->format('Y-m-d H:i:s');

            try {
                $administratorStmt = $pdo->prepare(
                    "SELECT id, full_name, phone, barcode, leave_days
                     FROM administrators
                     WHERE barcode = ?
                     LIMIT 1"
                );
                $administratorStmt->execute([$barcode]);
                $administrator = $administratorStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                if ($administrator === null) {
                    $message = '❌ الباركود غير مسجل';
                    $messageType = 'error';
                } elseif (isAdministratorAttendanceLeaveDay($administrator['leave_days'] ?? null, $todayDayName)) {
                    $message = '❌ اليوم إجازة لهذا الإداري';
                    $messageType = 'error';
                } else {
                    $pdo->beginTransaction();

                    $attendanceStmt = $pdo->prepare(
                        "SELECT id, check_in_time, check_out_time, work_hours
                         FROM administrator_attendance
                         WHERE administrator_id = ? AND attendance_date = ?
                         LIMIT 1
                         FOR UPDATE"
                    );
                    $attendanceStmt->execute([(int) $administrator['id'], $todayDate]);
                    $attendanceRow = $attendanceStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                    if ($attendanceRow === null) {
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO administrator_attendance (administrator_id, attendance_date, check_in_time, check_out_time, work_hours)
                             VALUES (?, ?, ?, NULL, 0.00)"
                        );
                        $insertStmt->execute([(int) $administrator['id'], $todayDate, $currentTimestamp]);
                        $pdo->commit();

                        setAdminAttendanceFlash('✅ ' . $administrator['full_name'] . ' • حضور ' . $nowEgypt->format('H:i:s'), 'success');
                        header('Location: ' . buildAdminAttendancePageUrl(['date' => $todayDate]));
                        exit;
                    }

                    if (!empty($attendanceRow['check_out_time'])) {
                        $pdo->commit();
                        $message = '❌ تم إغلاق اليوم لهذا الإداري';
                        $messageType = 'error';
                    } else {
                        $workHours = calculateAdministratorAttendanceWorkHours((string) $attendanceRow['check_in_time'], $currentTimestamp);
                        $updateStmt = $pdo->prepare(
                            "UPDATE administrator_attendance
                             SET check_out_time = ?, work_hours = ?
                             WHERE id = ?"
                        );
                        $updateStmt->execute([$currentTimestamp, $workHours, (int) $attendanceRow['id']]);
                        $pdo->commit();

                        setAdminAttendanceFlash('✅ ' . $administrator['full_name'] . ' • انصراف ' . $nowEgypt->format('H:i:s'), 'success');
                        header('Location: ' . buildAdminAttendancePageUrl(['date' => $todayDate]));
                        exit;
                    }
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($exception instanceof PDOException && (int) ($exception->errorInfo[1] ?? 0) === ADMIN_ATTENDANCE_DUPLICATE_KEY_ERROR) {
                    $message = '❌ السجل موجود بالفعل';
                } else {
                    $message = '❌ تعذر حفظ الحركة';
                }
                $messageType = 'error';
            }
        }
    }
}

$selectedDateObject = DateTimeImmutable::createFromFormat('Y-m-d', $selectedDate, adminAttendanceTimezone()) ?: $nowEgypt;
$selectedDayName = getAdministratorAttendanceStorageDayName($selectedDateObject);
$selectedDayLabel = getAdministratorAttendanceDayLabel($selectedDayName);
$selectedDateLabel = $selectedDate === $todayDate ? 'اليوم' : 'السجل';

$attendanceRecordsStmt = $pdo->prepare(
    "SELECT
        aa.id,
        aa.administrator_id,
        aa.attendance_date,
        aa.check_in_time,
        aa.check_out_time,
        aa.work_hours,
        a.full_name,
        a.phone,
        a.barcode,
        a.leave_days
     FROM administrator_attendance aa
     INNER JOIN administrators a ON a.id = aa.administrator_id
     WHERE aa.attendance_date = ?
     ORDER BY aa.check_in_time DESC, a.full_name ASC"
);
$attendanceRecordsStmt->execute([$selectedDate]);
$attendanceRecords = $attendanceRecordsStmt->fetchAll(PDO::FETCH_ASSOC);

$administratorsStmt = $pdo->query(
    "SELECT id, full_name, phone, barcode, leave_days
     FROM administrators
     ORDER BY full_name ASC"
);
$administrators = $administratorsStmt->fetchAll(PDO::FETCH_ASSOC);

$attendanceByAdministratorId = [];
$totalCompleted = 0;
$totalWorkHours = 0.0;

foreach ($attendanceRecords as $attendanceIndex => $attendanceRecord) {
    $hasCheckOut = !empty($attendanceRecord['check_out_time']);
    $displayHours = $hasCheckOut
        ? (float) ($attendanceRecord['work_hours'] ?? 0)
        : ($selectedDate === $todayDate
            ? calculateAdministratorAttendanceOpenHours($attendanceRecord['check_in_time'] ?? null, $nowEgypt)
            : (float) ($attendanceRecord['work_hours'] ?? 0));

    $attendanceRecord['status_label'] = $hasCheckOut ? 'انصراف' : 'حضور';
    $attendanceRecord['display_hours'] = $displayHours;
    $attendanceRecord['display_check_in'] = formatAdministratorAttendanceDateTime($attendanceRecord['check_in_time'] ?? null);
    $attendanceRecord['display_check_out'] = formatAdministratorAttendanceDateTime($attendanceRecord['check_out_time'] ?? null);

    $attendanceRecords[$attendanceIndex] = $attendanceRecord;
    $attendanceByAdministratorId[(int) $attendanceRecord['administrator_id']] = $attendanceRecord;
    $totalWorkHours += $displayHours;

    if ($hasCheckOut) {
        $totalCompleted++;
    }
}

$leaveAdministrators = [];
$absentAdministrators = [];

foreach ($administrators as $administrator) {
    $administratorId = (int) ($administrator['id'] ?? 0);
    $isLeaveDay = isAdministratorAttendanceLeaveDay($administrator['leave_days'] ?? null, $selectedDayName);

    if ($isLeaveDay) {
        $leaveAdministrators[] = $administrator;
        continue;
    }

    if (!isset($attendanceByAdministratorId[$administratorId])) {
        $absentAdministrators[] = $administrator;
    }
}

$totalAdministrators = count($administrators);
$totalPresent = count($attendanceRecords);
$totalAbsent = count($absentAdministrators);
$adminAttendanceCsrfToken = getAdminAttendanceCsrfToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>حضور الإداريين</title>
    <link rel="stylesheet" href="assets/css/admin-attendance.css">
</head>
<body class="light-mode">
<div class="admin-attendance-page" data-today-date="<?php echo academyHtmlspecialchars($todayDate, ENT_QUOTES, 'UTF-8'); ?>">
    <header class="page-header">
        <div class="header-text">
            <span class="section-badge">📍 حضور الإداريين</span>
            <h1>حضور الإداريين</h1>
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

            <a href="dashboard.php" class="back-btn">⬅️ لوحة التحكم</a>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div class="message-box <?php echo academyHtmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo academyHtmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <section class="hero-grid">
        <article class="hero-card live-card">
            <div class="hero-card-head">
                <span class="hero-chip"><?php echo academyHtmlspecialchars($selectedDayLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="hero-chip muted"><?php echo academyHtmlspecialchars($selectedDateLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="live-clock" id="liveClock"><?php echo academyHtmlspecialchars($nowEgypt->format('H:i:s'), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="live-date" id="liveDate"><?php echo academyHtmlspecialchars($nowEgypt->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?></div>
        </article>

        <article class="hero-card register-card">
            <form method="POST" class="attendance-form" id="attendanceForm" autocomplete="off">
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($adminAttendanceCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-group">
                    <label for="barcode">الباركود</label>
                    <input
                        type="text"
                        id="barcode"
                        name="barcode"
                        inputmode="numeric"
                        maxlength="50"
                        required
                    >
                </div>

                <div class="form-actions">
                    <button type="submit" class="submit-btn" id="submitBtn">حضور / انصراف</button>
                    <button type="button" class="ghost-btn" id="clearBtn">مسح</button>
                </div>
            </form>

            <div class="mobile-scanner-section" id="mobileScannerSection">
                <div class="mobile-scanner-head">
                    <button type="button" class="camera-btn" id="startCameraBtn">الكاميرا</button>
                    <button type="button" class="camera-btn secondary" id="stopCameraBtn" hidden>إيقاف</button>
                    <span class="scan-state" id="scanState">جاهز</span>
                </div>
                <div class="camera-frame" id="cameraFrame" hidden>
                    <video id="cameraPreview" playsinline muted></video>
                </div>
            </div>
        </article>
    </section>

    <section class="filter-card">
        <div class="filter-meta">
            <span class="date-chip"><?php echo academyHtmlspecialchars($selectedDayLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            <strong><?php echo academyHtmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>

        <form method="GET" class="date-filter-form">
            <input type="date" name="date" value="<?php echo academyHtmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>" required>
            <button type="submit" class="filter-btn">عرض</button>
        </form>
    </section>

    <section class="stats-grid">
        <article class="stat-card total-card">
            <div class="stat-icon">👥</div>
            <div>
                <h2><?php echo $totalAdministrators; ?></h2>
                <p>الإداريين</p>
            </div>
        </article>

        <article class="stat-card present-card">
            <div class="stat-icon">✅</div>
            <div>
                <h2><?php echo $totalPresent; ?></h2>
                <p>الحضور</p>
            </div>
        </article>

        <article class="stat-card checkout-card">
            <div class="stat-icon">📤</div>
            <div>
                <h2><?php echo $totalCompleted; ?></h2>
                <p>الانصراف</p>
            </div>
        </article>

        <article class="stat-card absent-card">
            <div class="stat-icon">🚫</div>
            <div>
                <h2><?php echo $totalAbsent; ?></h2>
                <p>الغياب</p>
            </div>
        </article>

        <article class="stat-card hours-card">
            <div class="stat-icon">⏱️</div>
            <div>
                <h2><?php echo academyHtmlspecialchars(formatAdministratorAttendanceHours($totalWorkHours), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p>الساعات</p>
            </div>
        </article>
    </section>

    <section class="lists-grid">
        <article class="list-card">
            <div class="card-head">
                <h2>الغياب</h2>
                <span class="count-badge"><?php echo $totalAbsent; ?></span>
            </div>

            <?php if (!empty($absentAdministrators)): ?>
                <div class="person-list">
                    <?php foreach ($absentAdministrators as $administrator): ?>
                        <div class="person-item">
                            <div class="person-avatar"><?php echo academyHtmlspecialchars(getAdministratorAttendanceInitial((string) $administrator['full_name']), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="person-content">
                                <strong><?php echo academyHtmlspecialchars((string) $administrator['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span><?php echo academyHtmlspecialchars((string) $administrator['phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <span class="status-badge absent-badge">غياب</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">لا يوجد</div>
            <?php endif; ?>
        </article>

        <article class="list-card">
            <div class="card-head">
                <h2>الإجازات</h2>
                <span class="count-badge"><?php echo count($leaveAdministrators); ?></span>
            </div>

            <?php if (!empty($leaveAdministrators)): ?>
                <div class="person-list">
                    <?php foreach ($leaveAdministrators as $administrator): ?>
                        <div class="person-item">
                            <div class="person-avatar leave-avatar"><?php echo academyHtmlspecialchars(getAdministratorAttendanceInitial((string) $administrator['full_name']), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="person-content">
                                <strong><?php echo academyHtmlspecialchars((string) $administrator['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span><?php echo academyHtmlspecialchars((string) $administrator['phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <span class="status-badge leave-badge">إجازة</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">لا يوجد</div>
            <?php endif; ?>
        </article>
    </section>

    <section class="table-card">
        <div class="card-head table-head">
            <h2>سجل الحضور والانصراف</h2>
            <span class="count-badge"><?php echo $totalPresent; ?></span>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>الإداري</th>
                        <th>الباركود</th>
                        <th>الحضور</th>
                        <th>الانصراف</th>
                        <th>الساعات</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($attendanceRecords)): ?>
                        <?php foreach ($attendanceRecords as $attendanceRecord): ?>
                            <tr>
                                <td>
                                    <div class="table-person">
                                        <div class="table-avatar"><?php echo academyHtmlspecialchars(getAdministratorAttendanceInitial((string) $attendanceRecord['full_name']), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div>
                                            <strong><?php echo academyHtmlspecialchars((string) $attendanceRecord['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <span><?php echo academyHtmlspecialchars((string) $attendanceRecord['phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td dir="ltr"><?php echo academyHtmlspecialchars((string) ($attendanceRecord['barcode'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars((string) $attendanceRecord['display_check_in'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars((string) $attendanceRecord['display_check_out'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(formatAdministratorAttendanceHours((float) $attendanceRecord['display_hours']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <span class="status-badge <?php echo !empty($attendanceRecord['check_out_time']) ? 'checkout-badge' : 'present-badge'; ?>">
                                        <?php echo academyHtmlspecialchars((string) $attendanceRecord['status_label'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-row">لا يوجد</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script src="assets/js/admin-attendance.js"></script>
</body>
</html>
