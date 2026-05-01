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

if (!userCanAccess($currentUser, "coach_advances")) {
    header("Location: dashboard.php?access=denied");
    exit;
}

const MAX_COACH_ADVANCE_AMOUNT = 999999.99;

function normalizeCoachAdvanceArabicNumbers(string $value): string
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

function sanitizeCoachAdvanceAmount(string $value): string
{
    $value = trim(normalizeCoachAdvanceArabicNumbers($value));
    $value = str_replace(',', '.', $value);
    $value = preg_replace('/[^0-9.]/', '', $value);

    $parts = explode('.', $value);
    if (count($parts) > 2) {
        $value = $parts[0] . '.' . implode('', array_slice($parts, 1));
    }

    return $value;
}

function formatCoachAdvanceAmount(float $value): string
{
    return number_format($value, 2, '.', '');
}

function isValidCoachAdvanceDate(string $value): bool
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function isValidCoachAdvanceAmount(string $value): bool
{
    return $value !== ''
        && preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1
        && (float) $value > 0
        && (float) $value <= MAX_COACH_ADVANCE_AMOUNT;
}

function buildCoachAdvancesPageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return 'coach_advances.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function generateCoachAdvancesSecurityToken(): string
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

    throw new RuntimeException('تعذر إنشاء رمز أمان لسلف المدربين');
}

function getCoachAdvancesCsrfToken(): string
{
    if (
        !isset($_SESSION['coach_advances_csrf_token'])
        || !is_string($_SESSION['coach_advances_csrf_token'])
        || $_SESSION['coach_advances_csrf_token'] === ''
    ) {
        try {
            $_SESSION['coach_advances_csrf_token'] = generateCoachAdvancesSecurityToken();
        } catch (Throwable $exception) {
            error_log('تعذر إنشاء رمز التحقق الخاص بسلف المدربين');
            http_response_code(500);
            exit('❌ تعذر تهيئة أمان الصفحة، يرجى إعادة المحاولة لاحقًا');
        }
    }

    return $_SESSION['coach_advances_csrf_token'];
}

function isValidCoachAdvancesCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getCoachAdvancesCsrfToken(), $submittedToken);
}

function setCoachAdvancesFlash(string $message, string $type): void
{
    $_SESSION['coach_advances_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumeCoachAdvancesFlash(): array
{
    $flash = $_SESSION['coach_advances_flash'] ?? null;
    unset($_SESSION['coach_advances_flash']);

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

$selectedDate = trim($_GET['date'] ?? date('Y-m-d'));
if (!isValidCoachAdvanceDate($selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$flashMessage = consumeCoachAdvancesFlash();
$message = $flashMessage['message'];
$messageType = $flashMessage['type'];
$editAdvance = null;
$formCoachId = '';
$formAdvanceAmount = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $postedDate = trim($_POST['advance_date'] ?? $selectedDate);
    if (isValidCoachAdvanceDate($postedDate)) {
        $selectedDate = $postedDate;
    }

    if ($action !== '' && !isValidCoachAdvancesCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = '❌ انتهت صلاحية الطلب، يرجى إعادة المحاولة';
        $messageType = 'error';
        $action = '';
    }

    if ($action === 'save') {
        $advanceId = trim($_POST['advance_id'] ?? '');
        $coachId = trim($_POST['coach_id'] ?? '');
        $amountInput = sanitizeCoachAdvanceAmount($_POST['amount'] ?? '');
        $formCoachId = $coachId;
        $formAdvanceAmount = $amountInput;

        if (!isValidCoachAdvanceDate($selectedDate)) {
            $message = '❌ تاريخ السلفة غير صحيح';
            $messageType = 'error';
        } elseif ($coachId === '' || ctype_digit($coachId) === false) {
            $message = '❌ يرجى اختيار المدرب من القائمة';
            $messageType = 'error';
        } elseif (!isValidCoachAdvanceAmount($amountInput)) {
            $message = '❌ يرجى إدخال مبلغ صحيح أكبر من صفر';
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
                    $amount = formatCoachAdvanceAmount((float) $amountInput);

                    if ($advanceId === '') {
                        $insertStmt = $pdo->prepare("INSERT INTO coach_advances (coach_id, advance_date, amount) VALUES (?, ?, ?)");
                        $insertStmt->execute([$coachId, $selectedDate, $amount]);

                        setCoachAdvancesFlash('✅ تم تسجيل سلفة المدرب بنجاح', 'success');
                        header('Location: ' . buildCoachAdvancesPageUrl(['date' => $selectedDate]));
                        exit;
                    }

                    $existingAdvanceStmt = $pdo->prepare("SELECT id FROM coach_advances WHERE id = ? LIMIT 1");
                    $existingAdvanceStmt->execute([$advanceId]);
                    $existingAdvance = $existingAdvanceStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$existingAdvance) {
                        $message = '❌ سجل السلفة المطلوب غير موجود';
                        $messageType = 'error';
                    } else {
                        $updateStmt = $pdo->prepare("UPDATE coach_advances SET coach_id = ?, advance_date = ?, amount = ? WHERE id = ?");
                        $updateStmt->execute([$coachId, $selectedDate, $amount, $advanceId]);

                        setCoachAdvancesFlash('✏️ تم تعديل سلفة المدرب بنجاح', 'success');
                        header('Location: ' . buildCoachAdvancesPageUrl(['date' => $selectedDate]));
                        exit;
                    }
                }
            } catch (PDOException $exception) {
                $message = '❌ حدث خطأ أثناء حفظ سلفة المدرب';
                $messageType = 'error';
            }
        }
    }

    if ($action === 'delete') {
        $advanceId = trim($_POST['advance_id'] ?? '');

        if ($advanceId === '' || ctype_digit($advanceId) === false) {
            $message = '❌ سجل السلفة المطلوب غير موجود';
            $messageType = 'error';
        } else {
            try {
                $deleteStmt = $pdo->prepare("DELETE FROM coach_advances WHERE id = ?");
                $deleteStmt->execute([$advanceId]);

                if ($deleteStmt->rowCount() > 0) {
                    setCoachAdvancesFlash('🗑️ تم حذف سجل السلفة بنجاح', 'success');
                    header('Location: ' . buildCoachAdvancesPageUrl(['date' => $selectedDate]));
                    exit;
                }

                $message = '❌ سجل السلفة المطلوب غير موجود';
                $messageType = 'error';
            } catch (PDOException $exception) {
                $message = '❌ حدث خطأ أثناء حذف سجل السلفة';
                $messageType = 'error';
            }
        }
    }
}

if (isset($_GET['edit'])) {
    $editId = trim($_GET['edit']);

    if ($editId !== '' && ctype_digit($editId)) {
        $editStmt = $pdo->prepare(
            "SELECT ca.id, ca.coach_id, ca.advance_date, ca.amount, ca.created_at, c.full_name, c.phone
             FROM coach_advances ca
             INNER JOIN coaches c ON c.id = ca.coach_id
             WHERE ca.id = ?
             LIMIT 1"
        );
        $editStmt->execute([$editId]);
        $editAdvance = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($editAdvance === null) {
            $message = '❌ سجل السلفة المطلوب غير موجود في هذا اليوم';
            $messageType = 'error';
        } elseif ((string) ($editAdvance['advance_date'] ?? '') !== $selectedDate) {
            $editAdvance = null;
            $message = '❌ سجل السلفة المطلوب غير موجود في هذا اليوم';
            $messageType = 'error';
        }
    }
}

$advanceRecordsStmt = $pdo->prepare(
    "SELECT
        ca.id,
        ca.coach_id,
        ca.advance_date,
        ca.amount,
        ca.created_at,
        c.full_name,
        c.phone
     FROM coach_advances ca
     INNER JOIN coaches c ON c.id = ca.coach_id
     WHERE ca.advance_date = ?
     ORDER BY ca.created_at DESC, ca.id DESC"
);
$advanceRecordsStmt->execute([$selectedDate]);
$advanceRecords = $advanceRecordsStmt->fetchAll(PDO::FETCH_ASSOC);

$coachesStmt = $pdo->query(
    "SELECT id, full_name, phone, hourly_rate
     FROM coaches
     ORDER BY full_name ASC"
);
$coaches = $coachesStmt->fetchAll(PDO::FETCH_ASSOC);

$coachAdvanceSummaryStmt = $pdo->prepare(
    "SELECT
        c.id,
        c.full_name,
        c.phone,
        COUNT(ca.id) AS records_count,
        SUM(ca.amount) AS total_amount
     FROM coach_advances ca
     INNER JOIN coaches c ON c.id = ca.coach_id
     WHERE ca.advance_date = ?
     GROUP BY c.id, c.full_name, c.phone
     ORDER BY total_amount DESC, c.full_name ASC"
);
$coachAdvanceSummaryStmt->execute([$selectedDate]);
$coachAdvanceSummary = $coachAdvanceSummaryStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCoachesStmt = $pdo->query("SELECT COUNT(*) FROM coaches");
$totalCoaches = (int) $totalCoachesStmt->fetchColumn();
$totalAdvanceRecords = count($advanceRecords);
$totalAdvanceAmount = 0.0;
$uniqueCoachIds = [];

foreach ($advanceRecords as $advanceRecord) {
    $totalAdvanceAmount += (float) ($advanceRecord['amount'] ?? 0);
    $uniqueCoachIds[(string) ($advanceRecord['coach_id'] ?? '')] = true;
}

$totalCoachesWithAdvances = count($uniqueCoachIds);
$selectedCoachId = $formCoachId !== ''
    ? $formCoachId
    : ($editAdvance !== null ? (string) $editAdvance['coach_id'] : '');
$selectedCoachMeta = null;

foreach ($coaches as $coach) {
    if ((string) $coach['id'] === $selectedCoachId) {
        $selectedCoachMeta = $coach;
        break;
    }
}

$coachAdvancesCsrfToken = getCoachAdvancesCsrfToken();
$resetUrl = buildCoachAdvancesPageUrl(['date' => $selectedDate]);
$selectedDateLabel = $selectedDate === date('Y-m-d') ? 'اليوم الحالي' : 'اليوم المحدد';
$hasCoaches = !empty($coaches);
$advanceAmountFieldValue = $formAdvanceAmount !== ''
    ? $formAdvanceAmount
    : ($editAdvance['amount'] ?? '');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سلف المدربين</title>
    <link rel="stylesheet" href="assets/css/coach-advances.css">
</head>
<body
    class="light-mode"
    data-reset-url="<?php echo academyHtmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8'); ?>"
    data-default-confirm-message="هل أنت متأكد من تنفيذ هذا الإجراء؟"
>
<div class="coach-attendance-page coach-advances-page">
    <header class="page-header">
        <div class="header-text">
            <span class="section-badge">💵 سلف المدربين</span>
            <h1>سلف المدربين</h1>
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
            <div class="stat-icon">🧾</div>
            <div>
                <h2><?php echo $totalAdvanceRecords; ?></h2>
                <p>عدد سلف اليوم</p>
            </div>
        </article>

        <article class="stat-card absent-card">
            <div class="stat-icon">🏅</div>
            <div>
                <h2><?php echo $totalCoachesWithAdvances; ?></h2>
                <p>مدربون حصلوا على سلفة</p>
            </div>
        </article>

        <article class="stat-card hours-card">
            <div class="stat-icon">💰</div>
            <div>
                <h2><?php echo academyHtmlspecialchars(number_format($totalAdvanceAmount, 2), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p>إجمالي سلف اليوم</p>
            </div>
        </article>
    </section>

    <section class="date-card">
        <div>
            <h2>📅 اليوم</h2>
        </div>

        <form method="GET" class="date-filter-form">
            <label for="advance_date_filter" class="date-label">اليوم</label>
            <input type="date" id="advance_date_filter" name="date" value="<?php echo academyHtmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>" required>
            <button type="submit" class="filter-btn">عرض البيانات</button>
        </form>

        <div class="date-highlight">
            <span class="date-chip"><?php echo academyHtmlspecialchars($selectedDateLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            <strong><?php echo academyHtmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </section>

    <section class="content-grid coach-advances-content-grid">
        <article class="form-card">
            <div class="card-head">
                <h2><?php echo $editAdvance ? '✏️ تعديل سلفة مدرب' : '➕ تسجيل سلفة مدرب'; ?></h2>
            </div>

            <form method="POST" class="attendance-form" autocomplete="off">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="advance_id" value="<?php echo academyHtmlspecialchars($editAdvance['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="advance_date" value="<?php echo academyHtmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($coachAdvancesCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
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
                                    <?php foreach ($coaches as $coach): ?>
                                        <button
                                            type="button"
                                            class="select-option<?php echo (string) $coach['id'] === $selectedCoachId ? ' is-selected' : ''; ?>"
                                            data-value="<?php echo academyHtmlspecialchars((string) $coach['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-name="<?php echo academyHtmlspecialchars($coach['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-phone="<?php echo academyHtmlspecialchars($coach['phone'], ENT_QUOTES, 'UTF-8'); ?>"
                                        >
                                            <span class="option-name"><?php echo academyHtmlspecialchars($coach['full_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="option-meta">📞 <?php echo academyHtmlspecialchars($coach['phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>

                                <p class="select-empty" id="coachSelectEmpty"<?php echo $hasCoaches ? ' hidden' : ''; ?>>لا يوجد مدربون مسجلون حالياً.</p>
                            </div>
                        </div>

                        <div class="selected-coach-meta" id="selectedCoachMeta">
                            <?php if ($selectedCoachMeta): ?>
                                <span>📞 <?php echo academyHtmlspecialchars($selectedCoachMeta['phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group form-group-highlight">
                        <label for="amount">💰 مبلغ السلفة</label>
                        <input
                            type="text"
                            id="amount"
                            name="amount"
                            value="<?php echo academyHtmlspecialchars($advanceAmountFieldValue, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="مثال: 300 أو 450.75"
                            inputmode="decimal"
                            maxlength="10"
                            pattern="[0-9٠-٩۰-۹]+([\\.,][0-9٠-٩۰-۹]{1,2})?"
                            required
                        >
                    </div>

                    <div class="form-group form-group-highlight">
                        <label>📌 إجمالي سلف اليوم</label>
                        <div class="estimated-box advance-total-box"><?php echo academyHtmlspecialchars(number_format($totalAdvanceAmount, 2), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="save-btn"<?php echo !$hasCoaches ? ' disabled' : ''; ?>>💾 حفظ السلفة</button>
                    <a href="<?php echo academyHtmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8'); ?>" class="clear-btn" id="clearBtn">🧹 سجل جديد</a>
                </div>
            </form>
        </article>

        <article class="absences-card coach-advances-summary-card">
            <div class="card-head">
                <h2>📊 ملخص سلف اليوم</h2>
            </div>

            <?php if (!empty($coachAdvanceSummary)): ?>
                <div class="advance-summary-list">
                    <?php foreach ($coachAdvanceSummary as $summaryItem): ?>
                        <div class="advance-summary-item">
                            <div class="absence-avatar"><?php echo academyHtmlspecialchars(mb_substr($summaryItem['full_name'], 0, 1), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="advance-summary-content">
                                <strong><?php echo academyHtmlspecialchars($summaryItem['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span>📞 <?php echo academyHtmlspecialchars($summaryItem['phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="advance-summary-meta">
                                <span class="status-badge present-badge"><?php echo (int) $summaryItem['records_count']; ?> سجل</span>
                                <strong><?php echo academyHtmlspecialchars(number_format((float) $summaryItem['total_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($totalCoaches === 0): ?>
                <div class="empty-state">لا يوجد مدربون مسجلون حالياً.</div>
            <?php else: ?>
                <div class="empty-state success-state">لا توجد سلف مسجلة في هذا اليوم حتى الآن.</div>
            <?php endif; ?>
        </article>
    </section>

    <section class="table-card">
        <div class="card-head card-head-inline">
            <div>
                <h2>📋 جدول سلف المدربين</h2>
            </div>
            <div class="table-summary-chip">💵 إجمالي اليوم: <?php echo academyHtmlspecialchars(number_format($totalAdvanceAmount, 2), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم المدرب</th>
                        <th>الهاتف</th>
                        <th>المبلغ</th>
                        <th>وقت التسجيل</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($advanceRecords)): ?>
                        <?php foreach ($advanceRecords as $index => $advanceRecord): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <div class="coach-cell">
                                        <span class="coach-avatar"><?php echo academyHtmlspecialchars(mb_substr($advanceRecord['full_name'], 0, 1), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span><?php echo academyHtmlspecialchars($advanceRecord['full_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </td>
                                <td><?php echo academyHtmlspecialchars($advanceRecord['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(number_format((float) $advanceRecord['amount'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(date('H:i', strtotime((string) $advanceRecord['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="<?php echo academyHtmlspecialchars(buildCoachAdvancesPageUrl(['date' => $selectedDate, 'edit' => $advanceRecord['id']]), ENT_QUOTES, 'UTF-8'); ?>" class="edit-btn">✏️ تعديل</a>
                                        <form method="POST" class="inline-form js-confirm-submit" data-confirm-message="هل تريد حذف سجل السلفة هذا؟">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="advance_id" value="<?php echo academyHtmlspecialchars((string) $advanceRecord['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="advance_date" value="<?php echo academyHtmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($coachAdvancesCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" class="delete-btn">🗑️ حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-row">لا توجد سلف مسجلة لهذا اليوم حتى الآن.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script src="assets/js/coach-advances.js"></script>
</body>
</html>
