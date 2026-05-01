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

if (!userCanAccess($currentUser, "coach_attendance")) {
    header("Location: dashboard.php?access=denied");
    exit;
}

const COACH_ATTENDANCE_DUPLICATE_KEY_ERROR = 1062;
const MAX_COACH_WORK_HOURS_PER_DAY = 24;

function normalizeAttendanceArabicNumbers(string $value): string
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

function sanitizeAttendanceHours(string $value): string
{
    $value = trim(normalizeAttendanceArabicNumbers($value));
    $value = str_replace(',', '.', $value);
    $value = preg_replace('/[^0-9.]/', '', $value);

    $parts = explode('.', $value);
    if (count($parts) > 2) {
        $value = $parts[0] . '.' . implode('', array_slice($parts, 1));
    }

    return $value;
}

function formatAttendanceHoursValue(float $value): string
{
    return number_format($value, 2, '.', '');
}

function isValidAttendanceDate(string $value): bool
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function isValidWorkHours(string $value): bool
{
    return $value !== ''
        && preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1
        && (float) $value > 0
        && (float) $value <= MAX_COACH_WORK_HOURS_PER_DAY;
}

function buildCoachAttendancePageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return 'coach_attendance.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function generateCoachAttendanceSecurityToken(): string
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

    throw new RuntimeException('تعذر إنشاء رمز أمان لحضور المدربين');
}

function getCoachAttendanceCsrfToken(): string
{
    if (
        !isset($_SESSION['coach_attendance_csrf_token'])
        || !is_string($_SESSION['coach_attendance_csrf_token'])
        || $_SESSION['coach_attendance_csrf_token'] === ''
    ) {
        try {
            $_SESSION['coach_attendance_csrf_token'] = generateCoachAttendanceSecurityToken();
        } catch (Throwable $exception) {
            error_log('تعذر إنشاء رمز التحقق الخاص بحضور المدربين');
            http_response_code(500);
            exit('❌ تعذر تهيئة أمان الصفحة، يرجى إعادة المحاولة لاحقًا');
        }
    }

    return $_SESSION['coach_attendance_csrf_token'];
}

function isValidCoachAttendanceCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getCoachAttendanceCsrfToken(), $submittedToken);
}

function setCoachAttendanceFlash(string $message, string $type, string $context = ''): void
{
    $_SESSION['coach_attendance_flash'] = [
        'message' => $message,
        'type' => $type,
        'context' => $context,
    ];
}

function consumeCoachAttendanceFlash(): array
{
    $flash = $_SESSION['coach_attendance_flash'] ?? null;
    unset($_SESSION['coach_attendance_flash']);

    if (!is_array($flash)) {
        return [
            'message' => '',
            'type' => '',
            'context' => '',
        ];
    }

    return [
        'message' => (string) ($flash['message'] ?? ''),
        'type' => (string) ($flash['type'] ?? ''),
        'context' => (string) ($flash['context'] ?? ''),
    ];
}

$selectedDate = trim($_GET['date'] ?? date('Y-m-d'));
if (!isValidAttendanceDate($selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$flashMessage = consumeCoachAttendanceFlash();
$message = $flashMessage['message'];
$messageType = $flashMessage['type'];
$messageContext = $flashMessage['context'];
$editAttendance = null;
$formCoachId = '';
$formWorkHoursValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $postedDate = trim($_POST['attendance_date'] ?? $selectedDate);
    if (isValidAttendanceDate($postedDate)) {
        $selectedDate = $postedDate;
    }

    if ($action !== '' && !isValidCoachAttendanceCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = '❌ انتهت صلاحية الطلب، يرجى إعادة المحاولة';
        $messageType = 'error';
        $action = '';
    }

    if ($action === 'save') {
        $attendanceId = trim($_POST['attendance_id'] ?? '');
        $coachId = trim($_POST['coach_id'] ?? '');
        $workHoursInput = sanitizeAttendanceHours($_POST['work_hours'] ?? '');
        $formCoachId = $coachId;
        $formWorkHoursValue = $workHoursInput;

        if (!isValidAttendanceDate($selectedDate)) {
            $message = '❌ تاريخ الحضور غير صحيح';
            $messageType = 'error';
        } elseif ($coachId === '' || ctype_digit($coachId) === false) {
            $message = '❌ يرجى اختيار المدرب من القائمة';
            $messageType = 'error';
        } elseif (!isValidWorkHours($workHoursInput)) {
            $message = '❌ يرجى إدخال عدد ساعات صحيح بين 0.01 و ' . MAX_COACH_WORK_HOURS_PER_DAY . ' ساعة';
            $messageType = 'error';
        } else {
            try {
                $coachStmt = $pdo->prepare("SELECT id FROM coaches WHERE id = ? LIMIT 1");
                $coachStmt->execute([$coachId]);
                $coachExists = $coachStmt->fetch(PDO::FETCH_ASSOC);

                if (!$coachExists) {
                    $message = '❌ المدرب المطلوب غير موجود';
                    $messageType = 'error';
                } else {
                    $workHours = formatAttendanceHoursValue((float) $workHoursInput);

                    if ($attendanceId === '') {
                        $insertStmt = $pdo->prepare("INSERT INTO coach_attendance (coach_id, attendance_date, work_hours) VALUES (?, ?, ?)");
                        $insertStmt->execute([$coachId, $selectedDate, $workHours]);

                        setCoachAttendanceFlash('✅ تم تسجيل حضور المدرب بنجاح', 'success', 'save');
                        header('Location: ' . buildCoachAttendancePageUrl(['date' => $selectedDate]));
                        exit;
                    } else {
                        $existingAttendanceStmt = $pdo->prepare("SELECT id FROM coach_attendance WHERE id = ? LIMIT 1");
                        $existingAttendanceStmt->execute([$attendanceId]);
                        $existingAttendance = $existingAttendanceStmt->fetch(PDO::FETCH_ASSOC);

                        if (!$existingAttendance) {
                            $message = '❌ سجل الحضور المطلوب غير موجود';
                            $messageType = 'error';
                        } else {
                            $updateStmt = $pdo->prepare("UPDATE coach_attendance SET coach_id = ?, attendance_date = ?, work_hours = ? WHERE id = ?");
                            $updateStmt->execute([$coachId, $selectedDate, $workHours, $attendanceId]);

                            setCoachAttendanceFlash('✏️ تم تعديل سجل حضور المدرب بنجاح', 'success', 'save');
                            header('Location: ' . buildCoachAttendancePageUrl(['date' => $selectedDate]));
                            exit;
                        }
                    }
                }
            } catch (PDOException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) === COACH_ATTENDANCE_DUPLICATE_KEY_ERROR) {
                    $message = '❌ هذا المدرب مسجل مسبقاً في اليوم المحدد';
                } else {
                    $message = '❌ حدث خطأ أثناء حفظ حضور المدرب';
                }
                $messageType = 'error';
            }
        }
    }

    if ($action === 'delete') {
        $attendanceId = trim($_POST['attendance_id'] ?? '');

        if ($attendanceId === '' || ctype_digit($attendanceId) === false) {
            $message = '❌ سجل الحضور المطلوب غير موجود';
            $messageType = 'error';
        } else {
            try {
                $deleteStmt = $pdo->prepare("DELETE FROM coach_attendance WHERE id = ?");
                $deleteStmt->execute([$attendanceId]);

                if ($deleteStmt->rowCount() > 0) {
                    setCoachAttendanceFlash('🗑️ تم حذف سجل الحضور بنجاح', 'success', 'delete');
                    header('Location: ' . buildCoachAttendancePageUrl(['date' => $selectedDate]));
                    exit;
                }

                $message = '❌ سجل الحضور المطلوب غير موجود';
                $messageType = 'error';
            } catch (PDOException $exception) {
                $message = '❌ حدث خطأ أثناء حذف سجل الحضور';
                $messageType = 'error';
            }
        }
    }
}

if (isset($_GET['edit'])) {
    $editId = trim($_GET['edit']);

    if ($editId !== '' && ctype_digit($editId)) {
        $editStmt = $pdo->prepare(
            "SELECT ca.id, ca.coach_id, ca.attendance_date, ca.work_hours, c.full_name, c.phone, c.hourly_rate
             FROM coach_attendance ca
             INNER JOIN coaches c ON c.id = ca.coach_id
             WHERE ca.id = ? AND ca.attendance_date = ?
             LIMIT 1"
        );
        $editStmt->execute([$editId, $selectedDate]);
        $editAttendance = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($editAttendance === null) {
            $message = '❌ سجل الحضور المطلوب غير موجود في هذا اليوم';
            $messageType = 'error';
        }
    }
}

$presentCoachesStmt = $pdo->prepare(
    "SELECT
        ca.id,
        ca.coach_id,
        ca.attendance_date,
        ca.work_hours,
        ca.created_at,
        c.full_name,
        c.phone,
        c.hourly_rate,
        (ca.work_hours * c.hourly_rate) AS estimated_total
     FROM coach_attendance ca
     INNER JOIN coaches c ON c.id = ca.coach_id
     WHERE ca.attendance_date = ?
     ORDER BY c.full_name ASC"
);
$presentCoachesStmt->execute([$selectedDate]);
$presentCoaches = $presentCoachesStmt->fetchAll(PDO::FETCH_ASSOC);

$absentCoachesStmt = $pdo->prepare(
    "SELECT c.id, c.full_name, c.phone, c.hourly_rate
     FROM coaches c
     LEFT JOIN coach_attendance ca
         ON ca.coach_id = c.id
         AND ca.attendance_date = ?
     WHERE ca.id IS NULL
     ORDER BY c.full_name ASC"
);
$absentCoachesStmt->execute([$selectedDate]);
$absentCoaches = $absentCoachesStmt->fetchAll(PDO::FETCH_ASSOC);

$availableParams = [$selectedDate];
$availableWhere = "NOT EXISTS (
    SELECT 1
    FROM coach_attendance ca
    WHERE ca.coach_id = c.id
      AND ca.attendance_date = ?
)";

if ($editAttendance !== null) {
    $availableWhere .= " OR c.id = ?";
    $availableParams[] = $editAttendance['coach_id'];
}

$availableCoachesStmt = $pdo->prepare(
    "SELECT c.id, c.full_name, c.phone, c.hourly_rate
     FROM coaches c
     WHERE {$availableWhere}
     ORDER BY c.full_name ASC"
);
$availableCoachesStmt->execute($availableParams);
$availableCoaches = $availableCoachesStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCoachesStmt = $pdo->query("SELECT COUNT(*) FROM coaches");
$totalCoaches = (int) $totalCoachesStmt->fetchColumn();
$totalPresent = count($presentCoaches);
$totalAbsent = count($absentCoaches);
$totalWorkHours = 0.0;
$totalEstimatedAmount = 0.0;

foreach ($presentCoaches as $presentCoach) {
    $totalWorkHours += (float) ($presentCoach['work_hours'] ?? 0);
    $totalEstimatedAmount += (float) ($presentCoach['estimated_total'] ?? 0);
}

$selectedCoachId = $formCoachId !== ''
    ? $formCoachId
    : ($editAttendance !== null ? (string) $editAttendance['coach_id'] : '');
$selectedCoachMeta = null;

foreach ($availableCoaches as $availableCoach) {
    if ((string) $availableCoach['id'] === $selectedCoachId) {
        $selectedCoachMeta = $availableCoach;
        break;
    }
}

$attendanceCsrfToken = getCoachAttendanceCsrfToken();
$resetUrl = buildCoachAttendancePageUrl(['date' => $selectedDate]);
$selectedDateLabel = $selectedDate === date('Y-m-d') ? 'اليوم الحالي' : 'اليوم المحدد';
$hasAvailableCoaches = !empty($availableCoaches);
$canViewCompensation = (($currentUser['role'] ?? '') === 'مدير');
$presentTableColspan = $canViewCompensation ? 8 : 6;
$workHoursFieldValue = $formWorkHoursValue !== ''
    ? $formWorkHoursValue
    : ($editAttendance['work_hours'] ?? '');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>حضور المدربين</title>
    <link rel="stylesheet" href="assets/css/coach-attendance.css">
</head>
<body
    class="light-mode"
    data-reset-url="<?php echo academyHtmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8'); ?>"
    data-can-view-compensation="<?php echo $canViewCompensation ? '1' : '0'; ?>"
>

<div class="coach-attendance-page">
    <header class="page-header">
        <div class="header-text">
            <span class="section-badge">🕒 حضور المدربين</span>
            <h1>حضور المدربين</h1>
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
                <h2><?php echo $totalCoaches; ?></h2>
                <p>إجمالي المدربين</p>
            </div>
        </article>

        <article class="stat-card present-card">
            <div class="stat-icon">✅</div>
            <div>
                <h2><?php echo $totalPresent; ?></h2>
                <p>مدربون حاضرون</p>
            </div>
        </article>

        <article class="stat-card absent-card">
            <div class="stat-icon">🚫</div>
            <div>
                <h2><?php echo $totalAbsent; ?></h2>
                <p>غياب تلقائي لليوم</p>
            </div>
        </article>

        <article class="stat-card hours-card">
            <div class="stat-icon">⏱️</div>
            <div>
                <h2><?php echo number_format($totalWorkHours, 2); ?></h2>
                <p>إجمالي ساعات العمل</p>
            </div>
        </article>
    </section>

    <section class="date-card">
        <div>
            <h2>📅 اليوم</h2>
        </div>

        <form method="GET" class="date-filter-form">
            <label for="attendance_date" class="date-label">اليوم</label>
            <input type="date" id="attendance_date" name="date" value="<?php echo academyHtmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>" required>
            <button type="submit" class="filter-btn">عرض البيانات</button>
        </form>

        <div class="date-highlight">
            <span class="date-chip"><?php echo academyHtmlspecialchars($selectedDateLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            <strong><?php echo academyHtmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </section>

    <section class="content-grid">
        <article class="form-card">
            <div class="card-head">
                <h2><?php echo $editAttendance ? '✏️ تعديل حضور مدرب' : '➕ تسجيل حضور مدرب'; ?></h2>
            </div>

            <form method="POST" class="attendance-form" autocomplete="off">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="attendance_id" value="<?php echo academyHtmlspecialchars($editAttendance['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="attendance_date" value="<?php echo academyHtmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($attendanceCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="coach_id" id="coach_id" value="<?php echo academyHtmlspecialchars($selectedCoachId, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-row form-row-single">
                    <div class="form-group">
                        <label>👤 اختيار المدرب</label>
                        <div class="professional-select" id="coachSelect" data-placeholder="اختر المدرب من القائمة">
                            <button type="button" class="select-trigger" id="coachSelectTrigger" aria-expanded="false">
                                <span class="select-trigger-text" id="coachSelectText">
                                    <?php echo $selectedCoachMeta ? academyHtmlspecialchars($selectedCoachMeta['full_name'], ENT_QUOTES, 'UTF-8') : 'اختر المدرب من القائمة'; ?>
                                </span>
                                <span class="select-trigger-icon">▾</span>
                            </button>

                            <div class="select-dropdown" id="coachSelectDropdown" hidden>
                                <div class="select-search-box">
                                    <input type="search" id="coachSearchInput" placeholder="ابحث باسم المدرب أو الهاتف">
                                </div>

                                <div class="select-options" id="coachOptionsList">
                                    <?php foreach ($availableCoaches as $availableCoach): ?>
                                        <button
                                            type="button"
                                            class="select-option<?php echo (string) $availableCoach['id'] === $selectedCoachId ? ' is-selected' : ''; ?>"
                                            data-value="<?php echo academyHtmlspecialchars((string) $availableCoach['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-name="<?php echo academyHtmlspecialchars($availableCoach['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-phone="<?php echo academyHtmlspecialchars($availableCoach['phone'], ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php if ($canViewCompensation): ?>
                                                data-rate="<?php echo academyHtmlspecialchars(number_format((float) $availableCoach['hourly_rate'], 2), ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php endif; ?>
                                        >
                                            <span class="option-name"><?php echo academyHtmlspecialchars($availableCoach['full_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="option-meta">
                                                📞 <?php echo academyHtmlspecialchars($availableCoach['phone'], ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if ($canViewCompensation): ?>
                                                    • 💵 <?php echo academyHtmlspecialchars(number_format((float) $availableCoach['hourly_rate'], 2), ENT_QUOTES, 'UTF-8'); ?>
                                                <?php endif; ?>
                                            </span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>

                                <p class="select-empty" id="coachSelectEmpty"<?php echo $hasAvailableCoaches ? ' hidden' : ''; ?>>لا يوجد مدربون متاحون للتسجيل في هذا اليوم.</p>
                            </div>
                        </div>

                        <div class="selected-coach-meta" id="selectedCoachMeta">
                            <?php if ($selectedCoachMeta): ?>
                                <span>📞 <?php echo academyHtmlspecialchars($selectedCoachMeta['phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($canViewCompensation): ?>
                                    <span>💵 سعر الساعة: <?php echo academyHtmlspecialchars(number_format((float) $selectedCoachMeta['hourly_rate'], 2), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="work_hours">⏳ عدد ساعات العمل</label>
                        <input
                            type="text"
                            id="work_hours"
                            name="work_hours"
                            value="<?php echo academyHtmlspecialchars($workHoursFieldValue, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="مثال: 6 أو 7.5"
                            inputmode="decimal"
                            maxlength="5"
                            pattern="[0-9٠-٩۰-۹]+([\\.,][0-9٠-٩۰-۹]{1,2})?"
                            required
                        >
                    </div>

                    <?php if ($canViewCompensation): ?>
                        <div class="form-group form-group-highlight">
                            <label>💰 إجمالي الأجر المتوقع لليوم</label>
                            <div class="estimated-box" id="estimatedAmountBox">0.00</div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button type="submit" class="save-btn"<?php echo (!$hasAvailableCoaches && !$editAttendance) ? ' disabled' : ''; ?>>💾 حفظ السجل</button>
                    <a href="<?php echo academyHtmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8'); ?>" class="clear-btn" id="clearBtn">🧹 سجل جديد</a>
                </div>
            </form>
        </article>

        <article class="absences-card">
            <div class="card-head">
                <h2>🚫 المدربون الغائبون تلقائيًا</h2>
            </div>

            <?php if (!empty($absentCoaches)): ?>
                <div class="absence-list">
                    <?php foreach ($absentCoaches as $absentCoach): ?>
                        <div class="absence-item">
                            <div class="absence-avatar"><?php echo academyHtmlspecialchars(mb_substr($absentCoach['full_name'], 0, 1), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="absence-content">
                                <strong><?php echo academyHtmlspecialchars($absentCoach['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span>📞 <?php echo academyHtmlspecialchars($absentCoach['phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <span class="status-badge absent-badge">غياب</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($totalCoaches === 0): ?>
                <div class="empty-state">ℹ️ لا يوجد مدربون مسجلون حالياً، أضف المدربين أولاً من صفحة المدربين ثم ابدأ تسجيل الحضور.</div>
            <?php else: ?>
                <div class="empty-state success-state">🎉 لا يوجد غياب في هذا اليوم، جميع المدربين مسجلون بالحضور.</div>
            <?php endif; ?>
        </article>
    </section>

    <section class="table-card">
        <div class="card-head card-head-inline">
            <div>
                <h2>📋 جدول المدربين الحاضرين</h2>
            </div>
            <?php if ($canViewCompensation): ?>
                <div class="table-summary-chip">💵 إجمالي الأجر المتوقع: <?php echo academyHtmlspecialchars(number_format($totalEstimatedAmount, 2), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم المدرب</th>
                        <th>الهاتف</th>
                        <th>عدد الساعات</th>
                        <?php if ($canViewCompensation): ?>
                            <th>سعر الساعة</th>
                            <th>إجمالي اليوم</th>
                        <?php endif; ?>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($presentCoaches)): ?>
                        <?php foreach ($presentCoaches as $index => $presentCoach): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <div class="coach-cell">
                                        <span class="coach-avatar"><?php echo academyHtmlspecialchars(mb_substr($presentCoach['full_name'], 0, 1), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span><?php echo academyHtmlspecialchars($presentCoach['full_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </td>
                                <td><?php echo academyHtmlspecialchars($presentCoach['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(number_format((float) $presentCoach['work_hours'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php if ($canViewCompensation): ?>
                                    <td><?php echo academyHtmlspecialchars(number_format((float) $presentCoach['hourly_rate'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo academyHtmlspecialchars(number_format((float) $presentCoach['estimated_total'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php endif; ?>
                                <td><span class="status-badge present-badge">حاضر</span></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="<?php echo academyHtmlspecialchars(buildCoachAttendancePageUrl(['date' => $selectedDate, 'edit' => $presentCoach['id']]), ENT_QUOTES, 'UTF-8'); ?>" class="edit-btn">✏️ تعديل</a>
                                        <form method="POST" class="inline-form js-confirm-submit" data-confirm-message="هل تريد حذف سجل حضور هذا المدرب؟">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="attendance_id" value="<?php echo academyHtmlspecialchars((string) $presentCoach['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="attendance_date" value="<?php echo academyHtmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($attendanceCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" class="delete-btn">🗑️ حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $presentTableColspan; ?>" class="empty-row">لا توجد سجلات حضور لهذا اليوم حتى الآن.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script src="assets/js/coach-attendance.js"></script>
</body>
</html>
