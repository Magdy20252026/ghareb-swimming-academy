<?php
session_start();
require_once 'config.php';
require_once 'app_helpers.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$currentUser = loadCurrentUser($pdo);

if ($currentUser === null) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

if (!userCanAccess($currentUser, 'settle_remaining')) {
    header('Location: dashboard.php?access=denied');
    exit;
}

define('SETTLE_REMAINING_PAGE_FILE', basename(__FILE__));

function normalizeSettleRemainingArabicNumbers(string $value): string
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

function sanitizeSettleRemainingText(string $value): string
{
    $value = trim(normalizeSettleRemainingArabicNumbers($value));
    $sanitizedValue = preg_replace('/\s+/u', ' ', $value);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function normalizeSettleRemainingDecimal(string $value): string
{
    $value = trim(normalizeSettleRemainingArabicNumbers($value));
    return str_replace(',', '.', $value);
}

function isValidSettleRemainingDecimal(string $value): bool
{
    return $value !== ''
        && preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1
        && (float) $value > 0;
}

function formatSettleRemainingAmount(int|float|string $value): string
{
    return number_format((float) $value, 2, '.', '');
}

function formatSettleRemainingMoney(int|float|string $value): string
{
    return number_format((float) $value, 2);
}

function buildSettleRemainingPageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return SETTLE_REMAINING_PAGE_FILE . ($queryString !== '' ? '?' . $queryString : '');
}

function generateSettleRemainingSecurityToken(): string
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

    throw new RuntimeException('تعذر إنشاء رمز أمان لصفحة تسديد الباقي');
}

function getSettleRemainingCsrfToken(): string
{
    if (
        !isset($_SESSION['settle_remaining_csrf_token'])
        || !is_string($_SESSION['settle_remaining_csrf_token'])
        || $_SESSION['settle_remaining_csrf_token'] === ''
    ) {
        try {
            $_SESSION['settle_remaining_csrf_token'] = generateSettleRemainingSecurityToken();
        } catch (Throwable $exception) {
            http_response_code(500);
            exit('❌ تعذر تهيئة أمان الصفحة، يرجى إعادة المحاولة لاحقًا');
        }
    }

    return $_SESSION['settle_remaining_csrf_token'];
}

function isValidSettleRemainingCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getSettleRemainingCsrfToken(), $submittedToken);
}

function setSettleRemainingFlash(string $message, string $type): void
{
    $_SESSION['settle_remaining_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumeSettleRemainingFlash(): array
{
    $flash = $_SESSION['settle_remaining_flash'] ?? null;
    unset($_SESSION['settle_remaining_flash']);

    if (!is_array($flash)) {
        return ['message' => '', 'type' => ''];
    }

    return [
        'message' => (string) ($flash['message'] ?? ''),
        'type' => (string) ($flash['type'] ?? ''),
    ];
}

function fetchSettleRemainingBranches(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT DISTINCT subscription_branch
         FROM academy_players
         WHERE academy_id = 0
           AND remaining_amount > 0
           AND subscription_branch IS NOT NULL
           AND subscription_branch <> ""
         ORDER BY subscription_branch ASC'
    );
    $rawBranches = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $sanitizedBranches = array_map('sanitizeSettleRemainingText', $rawBranches);
    return array_values(array_filter($sanitizedBranches, static fn(string $value): bool => $value !== ''));
}

function fetchSettleRemainingSummary(PDO $pdo, string $search, string $branch): array
{
    $sql = 'SELECT
                COUNT(*) AS players_count,
                COALESCE(SUM(paid_amount), 0) AS total_paid,
                COALESCE(SUM(remaining_amount), 0) AS total_remaining
            FROM academy_players
            WHERE academy_id = 0
              AND remaining_amount > 0';
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (barcode LIKE ? OR player_name LIKE ?)';
        $searchValue = '%' . $search . '%';
        $params[] = $searchValue;
        $params[] = $searchValue;
    }

    if ($branch !== '') {
        $sql .= ' AND subscription_branch = ?';
        $params[] = $branch;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function fetchSettleRemainingPlayers(PDO $pdo, string $search, string $branch): array
{
    $sql = 'SELECT
                id,
                barcode,
                player_name,
                subscription_name,
                subscription_branch,
                paid_amount,
                remaining_amount
            FROM academy_players
            WHERE academy_id = 0
              AND remaining_amount > 0';
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (barcode LIKE ? OR player_name LIKE ?)';
        $searchValue = '%' . $search . '%';
        $params[] = $searchValue;
        $params[] = $searchValue;
    }

    if ($branch !== '') {
        $sql .= ' AND subscription_branch = ?';
        $params[] = $branch;
    }

    $sql .= ' ORDER BY remaining_amount DESC, updated_at DESC, id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetchSettleRemainingPlayerById(PDO $pdo, int $playerId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT
            id,
            barcode,
            player_name,
            subscription_name,
            subscription_branch,
            subscription_amount,
            paid_amount,
            remaining_amount
         FROM academy_players
         WHERE academy_id = 0
           AND id = ?
         LIMIT 1'
    );
    $stmt->execute([$playerId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    return $player ?: null;
}

$flashMessage = consumeSettleRemainingFlash();
$message = $flashMessage['message'];
$messageType = $flashMessage['type'];
$search = sanitizeSettleRemainingText((string) ($_GET['search'] ?? ''));
$branch = sanitizeSettleRemainingText((string) ($_GET['branch'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $search = sanitizeSettleRemainingText((string) ($_POST['current_search'] ?? $search));
    $branch = sanitizeSettleRemainingText((string) ($_POST['current_branch'] ?? $branch));

    if ($action !== '' && !isValidSettleRemainingCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = '❌ انتهت صلاحية الطلب، يرجى إعادة المحاولة.';
        $messageType = 'error';
    } elseif ($action === 'collect_payment') {
        $playerId = (int) trim((string) ($_POST['player_id'] ?? '0'));
        $paymentAmountInput = normalizeSettleRemainingDecimal((string) ($_POST['payment_amount'] ?? ''));
        $receiptNumber = sanitizeSettleRemainingText((string) ($_POST['receipt_number'] ?? ''));
        $paymentPlayer = fetchSettleRemainingPlayerById($pdo, $playerId);

        if ($paymentPlayer === null) {
            $message = '❌ السباح المطلوب غير موجود.';
            $messageType = 'error';
        } elseif (!isValidSettleRemainingDecimal($paymentAmountInput)) {
            $message = '❌ أدخل مبلغ سداد صحيحًا.';
            $messageType = 'error';
        } elseif ($receiptNumber === '') {
            $message = '❌ أدخل رقم إيصال السداد.';
            $messageType = 'error';
        } elseif ((float) ($paymentPlayer['remaining_amount'] ?? 0) <= 0) {
            $message = '❌ لا يوجد مبلغ متبقٍ على هذا السباح.';
            $messageType = 'error';
        } else {
            $paymentAmount = (float) $paymentAmountInput;
            $currentRemaining = (float) ($paymentPlayer['remaining_amount'] ?? 0);

            if ($paymentAmount > $currentRemaining) {
                $message = '❌ مبلغ السداد أكبر من المتبقي.';
                $messageType = 'error';
            } else {
                try {
                    $pdo->beginTransaction();

                    $updatedPaidAmount = (float) ($paymentPlayer['paid_amount'] ?? 0) + $paymentAmount;
                    $updatedRemainingAmount = max($currentRemaining - $paymentAmount, 0);

                    $paymentUpdateStmt = $pdo->prepare(
                        'UPDATE academy_players
                         SET paid_amount = ?, remaining_amount = ?, last_payment_at = CURRENT_TIMESTAMP
                         WHERE id = ? AND academy_id = 0'
                    );
                    $paymentUpdateStmt->execute([
                        formatSettleRemainingAmount($updatedPaidAmount),
                        formatSettleRemainingAmount($updatedRemainingAmount),
                        $playerId,
                    ]);

                    recordAcademyPlayerPayment($pdo, [
                        'player_id' => $playerId,
                        'payment_type' => 'settlement',
                        'amount' => $paymentAmount,
                        'receipt_number' => $receiptNumber,
                        'created_by_user_id' => (int) ($currentUser['id'] ?? 0) ?: null,
                        'player_name_snapshot' => (string) ($paymentPlayer['player_name'] ?? ''),
                        'subscription_name_snapshot' => (string) ($paymentPlayer['subscription_name'] ?? ''),
                        'subscription_amount_snapshot' => (float) ($paymentPlayer['subscription_amount'] ?? 0),
                        'paid_amount_before_snapshot' => (float) ($paymentPlayer['paid_amount'] ?? 0),
                        'paid_amount_after_snapshot' => $updatedPaidAmount,
                        'remaining_amount_before_snapshot' => $currentRemaining,
                        'remaining_amount_after_snapshot' => $updatedRemainingAmount,
                    ]);

                    $pdo->commit();
                    setSettleRemainingFlash('✅ تم تسجيل السداد بنجاح.', 'success');
                    header('Location: ' . buildSettleRemainingPageUrl([
                        'search' => $search,
                        'branch' => $branch,
                    ]));
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $message = '❌ حدث خطأ أثناء تسجيل السداد.';
                    $messageType = 'error';
                }
            }
        }
    }
}

$branchOptions = fetchSettleRemainingBranches($pdo);
if ($branch !== '' && !in_array($branch, $branchOptions, true)) {
    $branch = '';
}

$players = fetchSettleRemainingPlayers($pdo, $search, $branch);
$summary = fetchSettleRemainingSummary($pdo, $search, $branch);
$settleRemainingCsrfToken = getSettleRemainingCsrfToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسديد الباقي</title>
    <link rel="stylesheet" href="assets/css/settle-remaining.css">
</head>
<body class="light-mode">
<div class="academy-players-page remaining-settlement-page">
    <header class="page-header">
        <div class="header-text">
            <span class="eyebrow">تسديد الباقي</span>
            <h1>تسديد الباقي</h1>
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
            <a href="academy_players.php" class="clear-btn link-btn">السباحين</a>
            <a href="dashboard.php" class="back-btn">لوحة التحكم</a>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div class="message-box <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <section class="hero-grid">
        <article class="hero-card hero-card-main">
            <span class="hero-icon">💳</span>
            <h2>السباحون عليهم متبقي</h2>
        </article>
        <article class="hero-card">
            <span>عدد السباحين</span>
            <strong><?php echo (int) ($summary['players_count'] ?? 0); ?></strong>
        </article>
        <article class="hero-card">
            <span>إجمالي المدفوع</span>
            <strong><?php echo formatSettleRemainingMoney($summary['total_paid'] ?? 0); ?> ج.م</strong>
        </article>
        <article class="hero-card">
            <span>إجمالي المتبقي</span>
            <strong><?php echo formatSettleRemainingMoney($summary['total_remaining'] ?? 0); ?> ج.م</strong>
        </article>
    </section>

    <section class="toolbar-card">
        <form method="GET" class="filter-form-horizontal" autocomplete="off">
            <div class="form-group toolbar-field-wide toolbar-field-search">
                <label for="search">بحث بالباركود أو الاسم</label>
                <input type="search" id="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="الباركود أو الاسم">
            </div>
            <div class="form-group">
                <label for="branch">الفرع</label>
                <select id="branch" name="branch">
                    <option value="">كل الفروع</option>
                    <?php foreach ($branchOptions as $branchOption): ?>
                        <option value="<?php echo htmlspecialchars($branchOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $branch === $branchOption ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($branchOption, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-form-actions header-actions">
                <button type="submit" class="save-btn">بحث</button>
                <a href="<?php echo htmlspecialchars(SETTLE_REMAINING_PAGE_FILE, ENT_QUOTES, 'UTF-8'); ?>" class="clear-btn link-btn">مسح</a>
            </div>
        </form>
    </section>

    <section class="table-card">
        <div class="table-head">
            <h2>جدول التسديد</h2>
            <span class="table-count"><?php echo (int) count($players); ?> سجل</span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الباركود</th>
                        <th>اسم السباح</th>
                        <th>المجموعة</th>
                        <th>المدفوع</th>
                        <th>المتبقي</th>
                        <th>تسديد الباقي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($players !== []): ?>
                        <?php foreach ($players as $index => $player): ?>
                            <tr>
                                <td data-label="#"><?php echo $index + 1; ?></td>
                                <td data-label="الباركود"><span class="table-cell-text"><?php echo htmlspecialchars((string) (($player['barcode'] ?? '') !== '' ? $player['barcode'] : '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="اسم السباح"><span class="table-cell-text"><?php echo htmlspecialchars((string) ($player['player_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="المجموعة">
                                    <div class="stacked-cell">
                                        <strong><?php echo htmlspecialchars((string) (($player['subscription_name'] ?? '') !== '' ? $player['subscription_name'] : '—'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <span><?php echo htmlspecialchars((string) (($player['subscription_branch'] ?? '') !== '' ? $player['subscription_branch'] : '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </td>
                                <td data-label="المدفوع"><span class="amount-badge collected"><?php echo formatSettleRemainingMoney($player['paid_amount'] ?? 0); ?> ج.م</span></td>
                                <td data-label="المتبقي"><span class="amount-badge remaining has-value"><?php echo formatSettleRemainingMoney($player['remaining_amount'] ?? 0); ?> ج.م</span></td>
                                <td data-label="تسديد الباقي">
                                    <form method="POST" class="settlement-form" autocomplete="off">
                                        <input type="hidden" name="action" value="collect_payment">
                                        <input type="hidden" name="player_id" value="<?php echo (int) ($player['id'] ?? 0); ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($settleRemainingCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="current_search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="current_branch" value="<?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="form-group">
                                            <label for="payment_amount_<?php echo (int) ($player['id'] ?? 0); ?>">المبلغ</label>
                                            <input
                                                type="number"
                                                name="payment_amount"
                                                id="payment_amount_<?php echo (int) ($player['id'] ?? 0); ?>"
                                                min="0.01"
                                                max="<?php echo htmlspecialchars(formatSettleRemainingAmount($player['remaining_amount'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
                                                step="0.01"
                                                value="<?php echo htmlspecialchars(formatSettleRemainingAmount($player['remaining_amount'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
                                                required
                                            >
                                        </div>
                                        <div class="form-group">
                                            <label for="receipt_number_<?php echo (int) ($player['id'] ?? 0); ?>">رقم الإيصال</label>
                                            <input type="text" name="receipt_number" id="receipt_number_<?php echo (int) ($player['id'] ?? 0); ?>" required>
                                        </div>
                                        <div class="form-group settlement-submit-group">
                                            <button type="submit" class="save-btn">تسديد</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="empty-row">لا توجد بيانات</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<script src="assets/js/settle-remaining.js"></script>
</body>
</html>
