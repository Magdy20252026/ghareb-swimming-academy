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

if (!userCanAccess($currentUser, "expenses")) {
    header("Location: dashboard.php?access=denied");
    exit;
}

const MAX_EXPENSE_AMOUNT = 999999.99;
const MAX_EXPENSE_DESCRIPTION_LENGTH = 255;

function normalizeExpensesArabicNumbers(string $value): string
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

function sanitizeExpenseAmount(string $value): string
{
    $value = trim(normalizeExpensesArabicNumbers($value));
    $value = str_replace(',', '.', $value);
    $value = preg_replace('/[^0-9.]/', '', $value);

    $parts = explode('.', $value);
    if (count($parts) > 2) {
        $value = $parts[0] . '.' . implode('', array_slice($parts, 1));
    }

    return $value;
}

function sanitizeExpenseDescription(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, MAX_EXPENSE_DESCRIPTION_LENGTH, 'UTF-8');
    }

    return substr($value, 0, MAX_EXPENSE_DESCRIPTION_LENGTH);
}

function formatExpenseAmount(float $value): string
{
    return number_format($value, 2, '.', '');
}

function isValidExpenseDate(string $value): bool
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function isValidExpenseAmount(string $value): bool
{
    return $value !== ''
        && preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1
        && (float) $value > 0
        && (float) $value <= MAX_EXPENSE_AMOUNT;
}

function buildExpensesPageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return 'expenses.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function generateExpensesSecurityToken(): string
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

    throw new RuntimeException('تعذر إنشاء رمز أمان لصفحة المصروفات');
}

function getExpensesCsrfToken(): string
{
    if (
        !isset($_SESSION['expenses_csrf_token'])
        || !is_string($_SESSION['expenses_csrf_token'])
        || $_SESSION['expenses_csrf_token'] === ''
    ) {
        try {
            $_SESSION['expenses_csrf_token'] = generateExpensesSecurityToken();
        } catch (Throwable $exception) {
            error_log('تعذر إنشاء رمز التحقق الخاص بصفحة المصروفات');
            http_response_code(500);
            exit('تعذر تهيئة أمان الصفحة');
        }
    }

    return $_SESSION['expenses_csrf_token'];
}

function isValidExpensesCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getExpensesCsrfToken(), $submittedToken);
}

function setExpensesFlash(string $message, string $type): void
{
    $_SESSION['expenses_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumeExpensesFlash(): array
{
    $flash = $_SESSION['expenses_flash'] ?? null;
    unset($_SESSION['expenses_flash']);

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

function formatExpenseDateLabel(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    $weekdayLabels = getArabicWeekdayLabels();
    $dayName = date('l', $timestamp);
    return ($weekdayLabels[$dayName] ?? $dayName) . ' • ' . date('Y-m-d', $timestamp);
}

$selectedDate = trim($_GET['date'] ?? date('Y-m-d'));
if (!isValidExpenseDate($selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$selectedDateObject = new DateTimeImmutable($selectedDate);
$previousDate = $selectedDateObject->modify('-1 day')->format('Y-m-d');
$nextDate = $selectedDateObject->modify('+1 day')->format('Y-m-d');
$currentUserId = (int) ($currentUser['id'] ?? 0);
$currentUsername = (string) ($currentUser['username'] ?? '');

$flashMessage = consumeExpensesFlash();
$message = $flashMessage['message'];
$messageType = $flashMessage['type'];
$formDescription = '';
$formAmount = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $postedDate = trim($_POST['expense_date'] ?? $selectedDate);

    if (isValidExpenseDate($postedDate)) {
        $selectedDate = $postedDate;
        $selectedDateObject = new DateTimeImmutable($selectedDate);
        $previousDate = $selectedDateObject->modify('-1 day')->format('Y-m-d');
        $nextDate = $selectedDateObject->modify('+1 day')->format('Y-m-d');
    }

    if ($action !== '' && !isValidExpensesCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = 'تعذر التحقق من الطلب';
        $messageType = 'error';
        $action = '';
    }

    if ($action === 'save') {
        $description = sanitizeExpenseDescription($_POST['description'] ?? '');
        $amountInput = sanitizeExpenseAmount($_POST['amount'] ?? '');
        $formDescription = $description;
        $formAmount = $amountInput;

        if (!isValidExpenseDate($selectedDate)) {
            $message = 'التاريخ غير صحيح';
            $messageType = 'error';
        } elseif ($description === '') {
            $message = 'أدخل البيان';
            $messageType = 'error';
        } elseif (!isValidExpenseAmount($amountInput)) {
            $message = 'أدخل مبلغًا صحيحًا';
            $messageType = 'error';
        } else {
            try {
                $insertStmt = $pdo->prepare(
                    'INSERT INTO expenses (expense_date, description, amount, created_by_user_id) VALUES (?, ?, ?, ?)'
                );
                $insertStmt->execute([
                    $selectedDate,
                    $description,
                    formatExpenseAmount((float) $amountInput),
                    $currentUserId > 0 ? $currentUserId : null,
                ]);

                setExpensesFlash('تم تسجيل المصروف', 'success');
                header('Location: ' . buildExpensesPageUrl(['date' => $selectedDate]));
                exit;
            } catch (PDOException $exception) {
                $message = 'حدث خطأ أثناء حفظ المصروف';
                $messageType = 'error';
            }
        }
    }

    if ($action === 'delete') {
        $expenseId = trim($_POST['expense_id'] ?? '');

        if ($expenseId === '' || ctype_digit($expenseId) === false) {
            $message = 'سجل المصروف غير موجود';
            $messageType = 'error';
        } else {
            try {
                $deleteStmt = $pdo->prepare('DELETE FROM expenses WHERE id = ?');
                $deleteStmt->execute([$expenseId]);

                if ($deleteStmt->rowCount() > 0) {
                    setExpensesFlash('تم حذف المصروف', 'success');
                    header('Location: ' . buildExpensesPageUrl(['date' => $selectedDate]));
                    exit;
                }

                $message = 'سجل المصروف غير موجود';
                $messageType = 'error';
            } catch (PDOException $exception) {
                $message = 'حدث خطأ أثناء حذف المصروف';
                $messageType = 'error';
            }
        }
    }
}

$selectedDaySummaryStmt = $pdo->prepare(
    'SELECT COUNT(*) AS total_records, COALESCE(SUM(amount), 0) AS total_amount
     FROM expenses
     WHERE expense_date = ?'
);
$selectedDaySummaryStmt->execute([$selectedDate]);
$selectedDaySummary = $selectedDaySummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$expenseRecordsStmt = $pdo->prepare(
    'SELECT
        e.id,
        e.expense_date,
        e.description,
        e.amount,
        e.created_at,
        COALESCE(u.username, "-") AS created_by
     FROM expenses e
     LEFT JOIN users u ON u.id = e.created_by_user_id
     WHERE e.expense_date = ?
     ORDER BY e.created_at DESC, e.id DESC'
);
$expenseRecordsStmt->execute([$selectedDate]);
$expenseRecords = $expenseRecordsStmt->fetchAll(PDO::FETCH_ASSOC);

$dailyTotalsStmt = $pdo->query(
    'SELECT expense_date, COUNT(*) AS total_records, COALESCE(SUM(amount), 0) AS total_amount
     FROM expenses
     GROUP BY expense_date
     ORDER BY expense_date DESC'
);
$dailyTotals = $dailyTotalsStmt ? $dailyTotalsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$allExpensesCount = 0;
foreach ($dailyTotals as $dailyTotalRow) {
    $allExpensesCount += (int) ($dailyTotalRow['total_records'] ?? 0);
}

$expensesCsrfToken = getExpensesCsrfToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المصروفات</title>
    <link rel="stylesheet" href="assets/css/expenses.css">
</head>
<body class="light-mode">
<div class="expenses-page">
    <header class="page-header">
        <div class="header-text">
            <h1>المصروفات</h1>
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
            <a href="dashboard.php" class="back-btn">لوحة التحكم</a>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div class="message-box <?php echo academyHtmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo academyHtmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <section class="hero-grid">
        <article class="hero-card hero-card-main">
            <span>إجمالي اليوم</span>
            <strong><?php echo formatExpenseAmount((float) ($selectedDaySummary['total_amount'] ?? 0)); ?> ج.م</strong>
        </article>
        <article class="hero-card">
            <span>عدد المصروفات</span>
            <strong><?php echo (int) ($selectedDaySummary['total_records'] ?? 0); ?></strong>
        </article>
        <article class="hero-card">
            <span>التاريخ</span>
            <strong><?php echo academyHtmlspecialchars(formatExpenseDateLabel($selectedDate), ENT_QUOTES, 'UTF-8'); ?></strong>
        </article>
        <article class="hero-card">
            <span>المستخدم</span>
            <strong><?php echo academyHtmlspecialchars($currentUsername, ENT_QUOTES, 'UTF-8'); ?></strong>
        </article>
    </section>

    <section class="toolbar-grid">
        <div class="filter-card">
            <div class="card-head">
                <h2>التاريخ</h2>
            </div>
            <div class="date-controls">
                <a href="<?php echo academyHtmlspecialchars(buildExpensesPageUrl(['date' => $previousDate]), ENT_QUOTES, 'UTF-8'); ?>" class="nav-btn">اليوم السابق</a>
                <form method="GET" class="date-form">
                    <input type="date" name="date" value="<?php echo academyHtmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>" max="9999-12-31" required>
                    <button type="submit" class="primary-btn">عرض</button>
                </form>
                <a href="<?php echo academyHtmlspecialchars(buildExpensesPageUrl(['date' => $nextDate]), ENT_QUOTES, 'UTF-8'); ?>" class="nav-btn">اليوم التالي</a>
            </div>
        </div>

        <div class="summary-strip">
            <div class="summary-item">
                <span>عدد الأيام</span>
                <strong><?php echo count($dailyTotals); ?></strong>
            </div>
            <div class="summary-item">
                <span>إجمالي السجلات</span>
                <strong><?php echo $allExpensesCount; ?></strong>
            </div>
        </div>
    </section>

    <section class="content-grid">
        <div class="form-card">
            <div class="card-head">
                <h2>تسجيل مصروف</h2>
            </div>

            <form method="POST" class="expense-form" autocomplete="off">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($expensesCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="expense_date" value="<?php echo academyHtmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="field-group">
                    <label for="expenseDescription">البيان</label>
                    <input
                        type="text"
                        id="expenseDescription"
                        name="description"
                        maxlength="<?php echo MAX_EXPENSE_DESCRIPTION_LENGTH; ?>"
                        value="<?php echo academyHtmlspecialchars($formDescription, ENT_QUOTES, 'UTF-8'); ?>"
                        required
                    >
                </div>

                <div class="field-group">
                    <label for="expenseAmount">المبلغ</label>
                    <input
                        type="text"
                        id="expenseAmount"
                        name="amount"
                        inputmode="decimal"
                        value="<?php echo academyHtmlspecialchars($formAmount, ENT_QUOTES, 'UTF-8'); ?>"
                        required
                    >
                </div>

                <button type="submit" class="primary-btn save-btn">حفظ</button>
            </form>
        </div>

        <div class="table-card">
            <div class="card-head table-head">
                <h2>مصروفات اليوم</h2>
                <span class="table-count"><?php echo count($expenseRecords); ?></span>
            </div>

            <div class="table-wrapper">
                <table>
                    <caption class="sr-only">مصروفات التاريخ المحدد</caption>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>البيان</th>
                            <th>المبلغ</th>
                            <th>المستخدم</th>
                            <th>الوقت</th>
                            <th>حذف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($expenseRecords !== []): ?>
                            <?php foreach ($expenseRecords as $index => $expense): ?>
                                <tr>
                                    <td data-label="#"><?php echo $index + 1; ?></td>
                                    <td data-label="البيان"><?php echo academyHtmlspecialchars((string) ($expense['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="المبلغ" class="amount-cell"><?php echo formatExpenseAmount((float) ($expense['amount'] ?? 0)); ?> ج.م</td>
                                    <td data-label="المستخدم"><?php echo academyHtmlspecialchars((string) ($expense['created_by'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="الوقت"><?php echo academyHtmlspecialchars((string) ($expense['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="حذف">
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($expensesCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="expense_date" value="<?php echo academyHtmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="expense_id" value="<?php echo (int) ($expense['id'] ?? 0); ?>">
                                            <button type="submit" class="delete-btn">حذف</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="empty-row">لا توجد مصروفات</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="table-card">
        <div class="card-head table-head">
            <h2>إجماليات الأيام</h2>
            <span class="table-count"><?php echo count($dailyTotals); ?></span>
        </div>

        <div class="table-wrapper">
            <table>
                <caption class="sr-only">إجماليات المصروفات حسب اليوم</caption>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>التاريخ</th>
                        <th>عدد المصروفات</th>
                        <th>الإجمالي</th>
                        <th>عرض</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($dailyTotals !== []): ?>
                        <?php foreach ($dailyTotals as $index => $dayTotal): ?>
                            <?php $dayDate = (string) ($dayTotal['expense_date'] ?? ''); ?>
                            <tr>
                                <td data-label="#"><?php echo $index + 1; ?></td>
                                <td data-label="التاريخ"><?php echo academyHtmlspecialchars(formatExpenseDateLabel($dayDate), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="عدد المصروفات"><?php echo (int) ($dayTotal['total_records'] ?? 0); ?></td>
                                <td data-label="الإجمالي" class="amount-cell"><?php echo formatExpenseAmount((float) ($dayTotal['total_amount'] ?? 0)); ?> ج.م</td>
                                <td data-label="عرض">
                                    <a href="<?php echo academyHtmlspecialchars(buildExpensesPageUrl(['date' => $dayDate]), ENT_QUOTES, 'UTF-8'); ?>" class="table-link">عرض</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="empty-row">لا توجد مصروفات</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script src="assets/js/expenses.js"></script>
</body>
</html>
