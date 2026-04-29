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

if (!userCanAccess($currentUser, 'academy_players_academies')) {
    header('Location: dashboard.php?access=denied');
    exit;
}

const ACADEMY_PLAYERS_PAGE_FILE = 'academies_players.php';
const ACADEMY_PLAYER_STATUS_FILTERS = [
    'all' => 'الكل',
    'active' => 'الاشتراكات السارية',
    'expired' => 'الاشتراكات المنتهية',
    'with_balance' => 'عليهم متبقي',
];

function normalizeAcademyPlayersArabicNumbers(string $value): string
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

function sanitizeAcademyPlayerText(string $value): string
{
    $value = trim(normalizeAcademyPlayersArabicNumbers($value));
    $sanitizedValue = preg_replace('/\s+/u', ' ', $value);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function sanitizeAcademyPlayerPhone(string $value): string
{
    $value = trim(normalizeAcademyPlayersArabicNumbers($value));
    $sanitizedValue = preg_replace('/[^0-9+]/', '', $value);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function normalizeAcademyPlayerDecimal(string $value): string
{
    $value = trim(normalizeAcademyPlayersArabicNumbers($value));
    return str_replace(',', '.', $value);
}

function isValidAcademyPlayerDecimal(string $value, bool $allowZero = false): bool
{
    if ($value === '' || preg_match('/^\d+(?:\.\d{1,2})?$/', $value) !== 1) {
        return false;
    }

    return $allowZero ? (float) $value >= 0 : (float) $value > 0;
}

function formatAcademyPlayerAmount($value): string
{
    return number_format((float) $value, 2, '.', '');
}

function formatAcademyPlayerMoney($value): string
{
    return number_format((float) $value, 2);
}

function formatAcademyPlayerDate(?string $value): string
{
    if (!is_string($value) || $value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('Y-m-d', $timestamp);
}

function isValidAcademyPlayerDateInput(string $value): bool
{
    return $value !== '' && strtotime($value) !== false;
}

function buildAcademyPlayersPageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return ACADEMY_PLAYERS_PAGE_FILE . ($queryString !== '' ? '?' . $queryString : '');
}

function generateAcademyPlayersSecurityToken(): string
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

    throw new RuntimeException('تعذر إنشاء رمز أمان للاعبي الأكاديميات');
}

function getAcademyPlayersCsrfToken(): string
{
    if (
        !isset($_SESSION['academies_players_csrf_token'])
        || !is_string($_SESSION['academies_players_csrf_token'])
        || $_SESSION['academies_players_csrf_token'] === ''
    ) {
        $_SESSION['academies_players_csrf_token'] = generateAcademyPlayersSecurityToken();
    }

    return $_SESSION['academies_players_csrf_token'];
}

function isValidAcademyPlayersCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getAcademyPlayersCsrfToken(), $submittedToken);
}

function setAcademyPlayersFlash(string $message, string $type): void
{
    $_SESSION['academies_players_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumeAcademyPlayersFlash(): array
{
    $flash = $_SESSION['academies_players_flash'] ?? null;
    unset($_SESSION['academies_players_flash']);

    if (!is_array($flash)) {
        return ['message' => '', 'type' => ''];
    }

    return [
        'message' => (string) ($flash['message'] ?? ''),
        'type' => (string) ($flash['type'] ?? ''),
    ];
}

function normalizeAcademyPlayersStatusFilter(string $value): string
{
    return array_key_exists($value, ACADEMY_PLAYER_STATUS_FILTERS) ? $value : 'all';
}

function academyPlayerSubscriptionStatusLabel(bool $isExpired): string
{
    return $isExpired ? 'منتهي' : 'مستمر';
}

function academyPlayersFilterParamsFromArray(array $source): array
{
    return [
        'search' => sanitizeAcademyPlayerText((string) ($source['current_search'] ?? $source['search'] ?? '')),
        'academy_id' => trim((string) ($source['current_academy_id'] ?? $source['academy_id'] ?? '')),
        'status' => normalizeAcademyPlayersStatusFilter((string) ($source['current_status'] ?? $source['status'] ?? 'all')),
    ];
}

function fetchAcademies(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, academy_name, subscription_price FROM academies ORDER BY academy_name ASC, id ASC');
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function fetchAcademyById(PDO $pdo, int $academyId): ?array
{
    $stmt = $pdo->prepare('SELECT id, academy_name, subscription_price FROM academies WHERE id = ? LIMIT 1');
    $stmt->execute([$academyId]);
    $academy = $stmt->fetch(PDO::FETCH_ASSOC);
    return $academy ?: null;
}

function fetchAcademyPlayerById(PDO $pdo, int $playerId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT ap.*, COALESCE(a.academy_name, ap.subscription_name) AS academy_name
         FROM academy_players ap
         LEFT JOIN academies a ON a.id = ap.academy_id
         WHERE ap.id = ? AND ap.academy_id > 0
         LIMIT 1'
    );
    $stmt->execute([$playerId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    return $player ?: null;
}

function fetchAcademyPlayerPayments(PDO $pdo, int $playerId): array
{
    $stmt = $pdo->prepare(
        'SELECT payment_type, amount, receipt_number, created_at
         FROM academy_player_payments
         WHERE player_id = ?
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([$playerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetchAcademyPlayersList(PDO $pdo, array $filters): array
{
    $whereClauses = ['ap.academy_id > 0'];
    $params = [];

    if ($filters['search'] !== '') {
        $whereClauses[] = 'ap.player_name LIKE ?';
        $params[] = '%' . $filters['search'] . '%';
    }

    if ($filters['academy_id'] !== '' && ctype_digit($filters['academy_id'])) {
        $whereClauses[] = 'ap.academy_id = ?';
        $params[] = (int) $filters['academy_id'];
    }

    if ($filters['status'] === 'active') {
        $whereClauses[] = 'ap.subscription_end_date > CURDATE()';
    } elseif ($filters['status'] === 'expired') {
        $whereClauses[] = 'ap.subscription_end_date <= CURDATE()';
    } elseif ($filters['status'] === 'with_balance') {
        $whereClauses[] = 'ap.remaining_amount > 0';
    }

    $stmt = $pdo->prepare(
        'SELECT
            ap.*,
            COALESCE(a.academy_name, ap.subscription_name) AS academy_name,
            (
                SELECT GROUP_CONCAT(app.receipt_number ORDER BY app.created_at DESC, app.id DESC SEPARATOR " • ")
                FROM academy_player_payments app
                WHERE app.player_id = ap.id
                  AND app.receipt_number IS NOT NULL
                  AND app.receipt_number <> ""
                  AND app.payment_type IN ("settlement", "renewal")
            ) AS collection_receipts
         FROM academy_players ap
         LEFT JOIN academies a ON a.id = ap.academy_id
         WHERE ' . implode(' AND ', $whereClauses) . '
         ORDER BY ap.subscription_end_date ASC, ap.updated_at DESC, ap.id DESC'
    );
    $stmt->execute($params);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $today = date('Y-m-d');
    foreach ($players as &$player) {
        $player['is_expired'] = isset($player['subscription_end_date'])
            && (string) $player['subscription_end_date'] !== ''
            && (string) $player['subscription_end_date'] <= $today;
        $player['has_balance'] = (float) ($player['remaining_amount'] ?? 0) > 0;
        $player['subscription_status_label'] = academyPlayerSubscriptionStatusLabel(!empty($player['is_expired']));
    }
    unset($player);

    return $players;
}

function fetchAcademyPlayersStatistics(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT
            COUNT(*) AS total_players,
            COALESCE(SUM(CASE WHEN subscription_end_date <= CURDATE() THEN 1 ELSE 0 END), 0) AS expired_players,
            COALESCE(SUM(CASE WHEN remaining_amount > 0 THEN 1 ELSE 0 END), 0) AS players_with_balance,
            COALESCE(SUM(paid_amount), 0) AS total_paid,
            COALESCE(SUM(remaining_amount), 0) AS total_remaining
         FROM academy_players
         WHERE academy_id > 0'
    );

    return $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
}

function fetchAcademyCollectionSummaries(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT
            a.id,
            a.academy_name,
            a.subscription_price,
            COUNT(ap.id) AS total_players,
            COALESCE(SUM(CASE WHEN ap.subscription_end_date <= CURDATE() THEN 1 ELSE 0 END), 0) AS expired_players,
            COALESCE(SUM(ap.paid_amount), 0) AS total_paid,
            COALESCE(SUM(ap.remaining_amount), 0) AS total_remaining
         FROM academies a
         LEFT JOIN academy_players ap ON ap.academy_id = a.id
         GROUP BY a.id, a.academy_name, a.subscription_price
         ORDER BY a.academy_name ASC, a.id ASC'
    );

    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

$flash = consumeAcademyPlayersFlash();
$message = $flash['message'];
$messageType = $flash['type'];
$filters = academyPlayersFilterParamsFromArray($_GET);
$academies = fetchAcademies($pdo);
$academiesById = [];
foreach ($academies as $academy) {
    $academiesById[(int) ($academy['id'] ?? 0)] = $academy;
}

$paymentPlayer = null;
$renewPlayer = null;
$submittedRenewData = null;
$detailsPlayer = null;
$paymentHistory = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $redirectFilters = academyPlayersFilterParamsFromArray($_POST);

    if ($action !== '' && !isValidAcademyPlayersCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = 'انتهت صلاحية الطلب، أعد المحاولة.';
        $messageType = 'error';
    } elseif ($action === 'save') {
        $academyIdInput = trim((string) ($_POST['academy_id'] ?? '0'));
        $playerName = sanitizeAcademyPlayerText((string) ($_POST['player_name'] ?? ''));
        $phone = sanitizeAcademyPlayerPhone((string) ($_POST['phone'] ?? ''));
        $guardianPhone = sanitizeAcademyPlayerPhone((string) ($_POST['guardian_phone'] ?? ''));
        $startDate = trim((string) ($_POST['subscription_start_date'] ?? ''));
        $endDate = trim((string) ($_POST['subscription_end_date'] ?? ''));
        $paidAmountInput = normalizeAcademyPlayerDecimal((string) ($_POST['paid_amount'] ?? '0'));
        $receiptNumber = sanitizeAcademyPlayerText((string) ($_POST['receipt_number'] ?? ''));
        $academyId = ctype_digit($academyIdInput) ? (int) $academyIdInput : 0;
        $academy = $academiesById[$academyId] ?? null;

        if ($academy === null) {
            $message = 'اختر أكاديمية صحيحة.';
            $messageType = 'error';
        } elseif ($playerName === '') {
            $message = 'أدخل اسم اللاعب.';
            $messageType = 'error';
        } elseif (!isValidAcademyPlayerDateInput($startDate)) {
            $message = 'اختر تاريخ بداية صحيحًا.';
            $messageType = 'error';
        } elseif (!isValidAcademyPlayerDateInput($endDate)) {
            $message = 'اختر تاريخ نهاية صحيحًا.';
            $messageType = 'error';
        } elseif ($endDate < $startDate) {
            $message = 'تاريخ النهاية يجب أن يكون بعد تاريخ البداية.';
            $messageType = 'error';
        } elseif (!isValidAcademyPlayerDecimal($paidAmountInput === '' ? '0' : $paidAmountInput, true)) {
            $message = 'أدخل مبلغًا صحيحًا للمدفوع.';
            $messageType = 'error';
        } else {
            $academyPrice = (float) ($academy['subscription_price'] ?? 0);
            $paidAmount = (float) ($paidAmountInput === '' ? '0' : $paidAmountInput);
            $totalAmount = $academyPrice;
            $remainingAmount = max($totalAmount - $paidAmount, 0);

            if ($paidAmount > $totalAmount) {
                $message = 'المدفوع لا يمكن أن يكون أكبر من مبلغ الاشتراك.';
                $messageType = 'error';
            } elseif ($paidAmount > 0 && $receiptNumber === '') {
                $message = 'أدخل رقم الإيصال.';
                $messageType = 'error';
            } else {
                try {
                    $pdo->beginTransaction();

                    $insertStmt = $pdo->prepare(
                        'INSERT INTO academy_players (
                            academy_id,
                            subscription_id,
                            player_name,
                            phone,
                            guardian_phone,
                            subscription_start_date,
                            subscription_end_date,
                            subscription_name,
                            subscription_base_price,
                            subscription_amount,
                            paid_amount,
                            remaining_amount,
                            receipt_number,
                            last_payment_at,
                            created_by_user_id
                        ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' 
                    );
                    $insertStmt->execute([
                        $academyId,
                        $playerName,
                        $phone,
                        $guardianPhone,
                        $startDate,
                        $endDate,
                        (string) ($academy['academy_name'] ?? ''),
                        formatAcademyPlayerAmount($academyPrice),
                        formatAcademyPlayerAmount($totalAmount),
                        formatAcademyPlayerAmount($paidAmount),
                        formatAcademyPlayerAmount($remainingAmount),
                        $receiptNumber,
                        $paidAmount > 0 ? date('Y-m-d H:i:s') : null,
                        (int) ($currentUser['id'] ?? 0) ?: null,
                    ]);

                    $playerId = (int) $pdo->lastInsertId();

                    recordAcademyPlayerPayment($pdo, [
                        'player_id' => $playerId,
                        'payment_type' => 'registration',
                        'amount' => $paidAmount,
                        'receipt_number' => $receiptNumber,
                        'created_by_user_id' => (int) ($currentUser['id'] ?? 0) ?: null,
                        'player_name_snapshot' => $playerName,
                        'subscription_name_snapshot' => (string) ($academy['academy_name'] ?? ''),
                        'subscription_amount_snapshot' => $totalAmount,
                        'paid_amount_before_snapshot' => 0,
                        'paid_amount_after_snapshot' => $paidAmount,
                        'remaining_amount_before_snapshot' => $totalAmount,
                        'remaining_amount_after_snapshot' => $remainingAmount,
                    ]);

                    $pdo->commit();
                    setAcademyPlayersFlash('تم تسجيل اللاعب بنجاح.', 'success');
                    header('Location: ' . buildAcademyPlayersPageUrl($redirectFilters));
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $message = 'حدث خطأ أثناء تسجيل اللاعب.';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'collect_payment') {
        $playerId = (int) trim((string) ($_POST['player_id'] ?? '0'));
        $paymentAmountInput = normalizeAcademyPlayerDecimal((string) ($_POST['payment_amount'] ?? ''));
        $receiptNumber = sanitizeAcademyPlayerText((string) ($_POST['receipt_number'] ?? ''));
        $paymentPlayer = fetchAcademyPlayerById($pdo, $playerId);

        if ($paymentPlayer === null) {
            $message = 'اللاعب المطلوب غير موجود.';
            $messageType = 'error';
        } elseif (!isValidAcademyPlayerDecimal($paymentAmountInput)) {
            $message = 'أدخل مبلغ سداد صحيحًا.';
            $messageType = 'error';
        } elseif ($receiptNumber === '') {
            $message = 'أدخل رقم إيصال السداد.';
            $messageType = 'error';
        } elseif ((float) ($paymentPlayer['remaining_amount'] ?? 0) <= 0) {
            $message = 'لا يوجد مبلغ متبقي على هذا اللاعب.';
            $messageType = 'error';
        } else {
            $paymentAmount = (float) $paymentAmountInput;
            $currentRemaining = (float) ($paymentPlayer['remaining_amount'] ?? 0);

            if ($paymentAmount > $currentRemaining) {
                $message = 'مبلغ السداد أكبر من المتبقي.';
                $messageType = 'error';
            } else {
                try {
                    $pdo->beginTransaction();

                    $updatedPaidAmount = (float) ($paymentPlayer['paid_amount'] ?? 0) + $paymentAmount;
                    $updatedRemainingAmount = max($currentRemaining - $paymentAmount, 0);

                    $updateStmt = $pdo->prepare(
                        'UPDATE academy_players
                         SET paid_amount = ?, remaining_amount = ?, last_payment_at = CURRENT_TIMESTAMP
                         WHERE id = ? AND academy_id > 0'
                    );
                    $updateStmt->execute([
                        formatAcademyPlayerAmount($updatedPaidAmount),
                        formatAcademyPlayerAmount($updatedRemainingAmount),
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
                    setAcademyPlayersFlash('تم تسجيل السداد بنجاح.', 'success');
                    header('Location: ' . buildAcademyPlayersPageUrl($redirectFilters));
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $message = 'حدث خطأ أثناء تسجيل السداد.';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'renew_subscription') {
        $playerId = (int) trim((string) ($_POST['player_id'] ?? '0'));
        $startDate = trim((string) ($_POST['renew_start_date'] ?? ''));
        $endDate = trim((string) ($_POST['renew_end_date'] ?? ''));
        $paidAmountInput = normalizeAcademyPlayerDecimal((string) ($_POST['renew_paid_amount'] ?? '0'));
        $receiptNumber = sanitizeAcademyPlayerText((string) ($_POST['renew_receipt_number'] ?? ''));
        $renewPlayer = fetchAcademyPlayerById($pdo, $playerId);
        $submittedRenewData = [
            'renew_start_date' => $startDate,
            'renew_end_date' => $endDate,
            'renew_paid_amount' => $paidAmountInput === '' ? '0.00' : $paidAmountInput,
            'renew_receipt_number' => $receiptNumber,
        ];

        if ($renewPlayer === null) {
            $message = 'اللاعب المطلوب غير موجود.';
            $messageType = 'error';
        } elseif (!$renewPlayer['subscription_end_date'] || (string) $renewPlayer['subscription_end_date'] > date('Y-m-d')) {
            $message = 'التجديد متاح للاشتراكات المنتهية فقط.';
            $messageType = 'error';
        } else {
            $academy = fetchAcademyById($pdo, (int) ($renewPlayer['academy_id'] ?? 0));
            if ($academy === null) {
                $message = 'الأكاديمية المرتبطة باللاعب غير موجودة.';
                $messageType = 'error';
            } elseif (!isValidAcademyPlayerDateInput($startDate)) {
                $message = 'اختر تاريخ بداية صحيحًا للتجديد.';
                $messageType = 'error';
            } elseif (!isValidAcademyPlayerDateInput($endDate)) {
                $message = 'اختر تاريخ نهاية صحيحًا للتجديد.';
                $messageType = 'error';
            } elseif ($endDate < $startDate) {
                $message = 'تاريخ النهاية يجب أن يكون بعد تاريخ البداية.';
                $messageType = 'error';
            } elseif (!isValidAcademyPlayerDecimal($paidAmountInput === '' ? '0' : $paidAmountInput, true)) {
                $message = 'أدخل مبلغًا صحيحًا للتجديد.';
                $messageType = 'error';
            } else {
                $oldRemaining = (float) ($renewPlayer['remaining_amount'] ?? 0);
                $newPrice = (float) ($academy['subscription_price'] ?? 0);
                $renewalTotal = $oldRemaining + $newPrice;
                $renewalPaid = (float) ($paidAmountInput === '' ? '0' : $paidAmountInput);
                $renewalRemaining = max($renewalTotal - $renewalPaid, 0);

                if ($renewalPaid > $renewalTotal) {
                    $message = 'المدفوع في التجديد لا يمكن أن يكون أكبر من الإجمالي.';
                    $messageType = 'error';
                } elseif ($renewalPaid > 0 && $receiptNumber === '') {
                    $message = 'أدخل رقم إيصال التجديد.';
                    $messageType = 'error';
                } else {
                    try {
                        $pdo->beginTransaction();

                        $updateStmt = $pdo->prepare(
                            'UPDATE academy_players
                             SET subscription_start_date = ?,
                                 subscription_end_date = ?,
                                 subscription_name = ?,
                                 subscription_base_price = ?,
                                 subscription_amount = ?,
                                 paid_amount = ?,
                                 remaining_amount = ?,
                                 receipt_number = ?,
                                 renewal_count = renewal_count + 1,
                                 last_renewed_at = CURRENT_TIMESTAMP,
                                 last_renewed_by_user_id = ?,
                                 last_payment_at = ?
                              WHERE id = ? AND academy_id > 0'
                        );
                        $updateStmt->execute([
                            $startDate,
                            $endDate,
                            (string) ($academy['academy_name'] ?? ''),
                            formatAcademyPlayerAmount($newPrice),
                            formatAcademyPlayerAmount($renewalTotal),
                            formatAcademyPlayerAmount($renewalPaid),
                            formatAcademyPlayerAmount($renewalRemaining),
                            $receiptNumber,
                            (int) ($currentUser['id'] ?? 0) ?: null,
                            $renewalPaid > 0 ? date('Y-m-d H:i:s') : ($renewPlayer['last_payment_at'] ?? null),
                            $playerId,
                        ]);

                        recordAcademyPlayerPayment($pdo, [
                            'player_id' => $playerId,
                            'payment_type' => 'renewal',
                            'amount' => $renewalPaid,
                            'receipt_number' => $receiptNumber,
                            'created_by_user_id' => (int) ($currentUser['id'] ?? 0) ?: null,
                            'player_name_snapshot' => (string) ($renewPlayer['player_name'] ?? ''),
                            'subscription_name_snapshot' => (string) ($academy['academy_name'] ?? ''),
                            'subscription_amount_snapshot' => $renewalTotal,
                            'paid_amount_before_snapshot' => 0,
                            'paid_amount_after_snapshot' => $renewalPaid,
                            'remaining_amount_before_snapshot' => $renewalTotal,
                            'remaining_amount_after_snapshot' => $renewalRemaining,
                        ]);

                        $pdo->commit();
                        setAcademyPlayersFlash('تم تجديد اشتراك اللاعب بنجاح.', 'success');
                        header('Location: ' . buildAcademyPlayersPageUrl($redirectFilters));
                        exit;
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $message = 'حدث خطأ أثناء تجديد الاشتراك.';
                        $messageType = 'error';
                    }
                }
            }
        }
    } elseif ($action === 'delete') {
        $playerId = (int) trim((string) ($_POST['player_id'] ?? '0'));
        $player = fetchAcademyPlayerById($pdo, $playerId);

        if ($player === null) {
            $message = 'اللاعب المطلوب غير موجود.';
            $messageType = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                $deletePaymentsStmt = $pdo->prepare('DELETE FROM academy_player_payments WHERE player_id = ?');
                $deletePaymentsStmt->execute([$playerId]);
                $deletePlayerStmt = $pdo->prepare('DELETE FROM academy_players WHERE id = ? AND academy_id > 0');
                $deletePlayerStmt->execute([$playerId]);
                $pdo->commit();

                setAcademyPlayersFlash('تم حذف اللاعب بنجاح.', 'success');
                header('Location: ' . buildAcademyPlayersPageUrl($redirectFilters));
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = 'حدث خطأ أثناء حذف اللاعب.';
                $messageType = 'error';
            }
        }
    }
}

if (isset($_GET['pay']) && ctype_digit((string) $_GET['pay'])) {
    $paymentPlayer = fetchAcademyPlayerById($pdo, (int) $_GET['pay']);
}

if (isset($_GET['renew']) && ctype_digit((string) $_GET['renew'])) {
    $renewPlayer = fetchAcademyPlayerById($pdo, (int) $_GET['renew']);
}

if (isset($_GET['details']) && ctype_digit((string) $_GET['details'])) {
    $detailsPlayer = fetchAcademyPlayerById($pdo, (int) $_GET['details']);
    if ($detailsPlayer !== null) {
        $paymentHistory = fetchAcademyPlayerPayments($pdo, (int) ($detailsPlayer['id'] ?? 0));
    }
}

$players = fetchAcademyPlayersList($pdo, $filters);
$overallStats = fetchAcademyPlayersStatistics($pdo);
$academySummaries = fetchAcademyCollectionSummaries($pdo);
$academyPlayersCsrfToken = getAcademyPlayersCsrfToken();
$currentFilterParams = [
    'search' => $filters['search'],
    'academy_id' => $filters['academy_id'],
    'status' => $filters['status'] === 'all' ? '' : $filters['status'],
];
$renewPlayerIsExpired = $renewPlayer !== null
    && !empty($renewPlayer['subscription_end_date'])
    && (string) $renewPlayer['subscription_end_date'] <= date('Y-m-d');
$renewAcademy = $renewPlayer !== null ? ($academiesById[(int) ($renewPlayer['academy_id'] ?? 0)] ?? null) : null;
$renewPrice = (float) ($renewAcademy['subscription_price'] ?? 0);
$renewOldRemaining = $renewPlayer !== null ? (float) ($renewPlayer['remaining_amount'] ?? 0) : 0.0;
$renewFormData = [
    'renew_start_date' => date('Y-m-d'),
    'renew_end_date' => '',
    'renew_paid_amount' => '0.00',
    'renew_receipt_number' => '',
];
if (is_array($submittedRenewData)) {
    $renewFormData = array_merge($renewFormData, $submittedRenewData);
}
$renewPaidValue = (float) normalizeAcademyPlayerDecimal((string) ($renewFormData['renew_paid_amount'] ?? '0'));
$renewTotalValue = $renewPrice + $renewOldRemaining;
$renewRemainingValue = max($renewTotalValue - $renewPaidValue, 0);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لاعبين الأكاديميات</title>
    <link rel="stylesheet" href="assets/css/academies-players.css">
</head>
<body class="light-mode" data-page-url="<?php echo htmlspecialchars(ACADEMY_PLAYERS_PAGE_FILE, ENT_QUOTES, 'UTF-8'); ?>">
<div class="academy-players-page">
    <header class="page-header">
        <div class="header-text">
            <span class="eyebrow">لاعبين الأكاديميات</span>
            <h1>لاعبين الأكاديميات</h1>
        </div>
        <div class="header-actions">
            <div class="theme-switch-box">
                <span>فاتح</span>
                <label class="switch">
                    <input type="checkbox" id="themeToggle">
                    <span class="slider"></span>
                </label>
                <span>داكن</span>
            </div>
            <a href="academies.php" class="back-btn">الأكاديميات</a>
            <a href="dashboard.php" class="clear-btn link-btn">لوحة التحكم</a>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div class="message-box <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <section class="hero-grid">
        <article class="hero-card hero-card-main">
            <span>إدارة التسجيل والتحصيل والتجديد</span>
            <strong><?php echo (int) ($overallStats['total_players'] ?? 0); ?> لاعب</strong>
        </article>
        <article class="hero-card">
            <span>الاشتراكات المنتهية</span>
            <strong><?php echo (int) ($overallStats['expired_players'] ?? 0); ?></strong>
        </article>
        <article class="hero-card">
            <span>عليهم متبقي</span>
            <strong><?php echo (int) ($overallStats['players_with_balance'] ?? 0); ?></strong>
        </article>
        <article class="hero-card">
            <span>إجمالي المحصل</span>
            <strong><?php echo formatAcademyPlayerMoney($overallStats['total_paid'] ?? 0); ?> ج.م</strong>
        </article>
        <article class="hero-card">
            <span>إجمالي المتبقي</span>
            <strong><?php echo formatAcademyPlayerMoney($overallStats['total_remaining'] ?? 0); ?> ج.م</strong>
        </article>
    </section>

    <section class="content-grid">
        <div class="form-card">
            <div class="card-head">
                <h2>تسجيل لاعب جديد</h2>
            </div>
            <form method="POST" class="player-form" id="playerForm" autocomplete="off">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($academyPlayersCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="current_search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="current_academy_id" value="<?php echo htmlspecialchars($filters['academy_id'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($filters['status'], ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="player_name">اسم اللاعب</label>
                        <input type="text" name="player_name" id="player_name" required>
                    </div>
                    <div class="form-group">
                        <label for="phone">رقم الهاتف</label>
                        <input type="text" name="phone" id="phone" inputmode="tel">
                    </div>
                    <div class="form-group">
                        <label for="guardian_phone">رقم ولي الأمر</label>
                        <input type="text" name="guardian_phone" id="guardian_phone" inputmode="tel">
                    </div>
                    <div class="form-group">
                        <label for="academy_id">الأكاديمية</label>
                        <select name="academy_id" id="academy_id" required>
                            <option value="">اختر الأكاديمية</option>
                            <?php foreach ($academies as $academy): ?>
                                <option value="<?php echo (int) ($academy['id'] ?? 0); ?>" data-price="<?php echo htmlspecialchars(formatAcademyPlayerAmount($academy['subscription_price'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars((string) ($academy['academy_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="subscription_start_date">تاريخ بداية الاشتراك</label>
                        <input type="date" name="subscription_start_date" id="subscription_start_date" required>
                    </div>
                    <div class="form-group">
                        <label for="subscription_end_date">تاريخ نهاية الاشتراك</label>
                        <input type="date" name="subscription_end_date" id="subscription_end_date" required>
                    </div>
                    <div class="form-group">
                        <label for="academy_price_display">مبلغ الاشتراك</label>
                        <input type="text" id="academy_price_display" value="0.00" readonly>
                    </div>
                    <div class="form-group">
                        <label for="subscription_amount">الإجمالي</label>
                        <input type="text" id="subscription_amount" value="0.00" readonly>
                    </div>
                    <div class="form-group">
                        <label for="paid_amount">المدفوع</label>
                        <input type="number" min="0" step="0.01" name="paid_amount" id="paid_amount" value="0.00">
                    </div>
                    <div class="form-group">
                        <label for="remaining_amount">المتبقي</label>
                        <input type="text" id="remaining_amount" value="0.00" readonly>
                    </div>
                    <div class="form-group form-group-full">
                        <label for="receipt_number">رقم الإيصال</label>
                        <input type="text" name="receipt_number" id="receipt_number">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="save-btn">تسجيل اللاعب</button>
                    <button type="button" class="clear-btn" id="clearBtn">مسح</button>
                </div>
            </form>
        </div>

        <aside class="side-panel">
            <div class="side-card">
                <h3>البحث والتصفية</h3>
                <form method="GET" class="stack-form" autocomplete="off">
                    <div class="form-group">
                        <label for="search">اسم اللاعب</label>
                        <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="academy_filter">الأكاديمية</label>
                        <select name="academy_id" id="academy_filter">
                            <option value="">كل الأكاديميات</option>
                            <?php foreach ($academies as $academy): ?>
                                <option value="<?php echo (int) ($academy['id'] ?? 0); ?>" <?php echo (string) ($academy['id'] ?? '') === $filters['academy_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) ($academy['academy_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="status">الحالة</label>
                        <select name="status" id="status">
                            <?php foreach (ACADEMY_PLAYER_STATUS_FILTERS as $statusKey => $statusLabel): ?>
                                <option value="<?php echo htmlspecialchars($statusKey === 'all' ? '' : $statusKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filters['status'] === $statusKey ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-actions compact-actions">
                        <button type="submit" class="save-btn">عرض</button>
                        <a href="academies_players.php" class="clear-btn link-btn">إعادة ضبط</a>
                    </div>
                </form>
            </div>

            <?php if ($paymentPlayer !== null && (float) ($paymentPlayer['remaining_amount'] ?? 0) > 0): ?>
                <div class="side-card action-card" id="collect-payment-card">
                    <h3>سداد المتبقي</h3>
                    <div class="info-list">
                        <div><span>اللاعب</span><strong><?php echo htmlspecialchars((string) ($paymentPlayer['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>الأكاديمية</span><strong><?php echo htmlspecialchars((string) ($paymentPlayer['academy_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>المتبقي</span><strong><?php echo formatAcademyPlayerMoney($paymentPlayer['remaining_amount'] ?? 0); ?> ج.م</strong></div>
                    </div>
                    <form method="POST" class="stack-form" autocomplete="off">
                        <input type="hidden" name="action" value="collect_payment">
                        <input type="hidden" name="player_id" value="<?php echo (int) ($paymentPlayer['id'] ?? 0); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($academyPlayersCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_academy_id" value="<?php echo htmlspecialchars($filters['academy_id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($filters['status'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="form-group">
                            <label for="payment_amount">المبلغ</label>
                            <input type="number" name="payment_amount" id="payment_amount" min="0.01" max="<?php echo htmlspecialchars(formatAcademyPlayerAmount($paymentPlayer['remaining_amount'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label for="payment_receipt_number">رقم الإيصال</label>
                            <input type="text" name="receipt_number" id="payment_receipt_number" required>
                        </div>
                        <div class="form-actions compact-actions">
                            <button type="submit" class="save-btn">تسجيل السداد</button>
                            <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl($currentFilterParams), ENT_QUOTES, 'UTF-8'); ?>" class="clear-btn link-btn">إغلاق</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($renewPlayerIsExpired): ?>
                <div class="side-card action-card" id="renew-card" data-old-remaining="<?php echo htmlspecialchars(formatAcademyPlayerAmount($renewOldRemaining), ENT_QUOTES, 'UTF-8'); ?>" data-new-price="<?php echo htmlspecialchars(formatAcademyPlayerAmount($renewPrice), ENT_QUOTES, 'UTF-8'); ?>">
                    <h3>تجديد الاشتراك</h3>
                    <div class="info-list">
                        <div><span>اللاعب</span><strong><?php echo htmlspecialchars((string) ($renewPlayer['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>الأكاديمية</span><strong><?php echo htmlspecialchars((string) ($renewPlayer['academy_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>حالة الاشتراك</span><strong><?php echo htmlspecialchars((string) ($renewPlayer['subscription_status_label'] ?? 'منتهي'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                    <form method="POST" class="stack-form" autocomplete="off">
                        <input type="hidden" name="action" value="renew_subscription">
                        <input type="hidden" name="player_id" value="<?php echo (int) ($renewPlayer['id'] ?? 0); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($academyPlayersCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_academy_id" value="<?php echo htmlspecialchars($filters['academy_id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($filters['status'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="renew_start_date">تاريخ بداية الاشتراك الجديد</label>
                                <input type="date" name="renew_start_date" id="renew_start_date" value="<?php echo htmlspecialchars((string) $renewFormData['renew_start_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="renew_end_date">تاريخ نهاية الاشتراك الجديد</label>
                                <input type="date" name="renew_end_date" id="renew_end_date" value="<?php echo htmlspecialchars((string) $renewFormData['renew_end_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="renew_base_price_display">مبلغ الاشتراك</label>
                                <input type="text" id="renew_base_price_display" value="<?php echo htmlspecialchars(formatAcademyPlayerAmount($renewPrice), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label for="renew_old_remaining_display">المتبقي القديم</label>
                                <input type="text" id="renew_old_remaining_display" value="<?php echo htmlspecialchars(formatAcademyPlayerAmount($renewOldRemaining), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label for="renew_total_amount">الإجمالي</label>
                                <input type="text" id="renew_total_amount" value="<?php echo htmlspecialchars(formatAcademyPlayerAmount($renewTotalValue), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label for="renew_paid_amount">المدفوع</label>
                                <input type="number" name="renew_paid_amount" id="renew_paid_amount" min="0" step="0.01" max="<?php echo htmlspecialchars(formatAcademyPlayerAmount($renewTotalValue), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string) $renewFormData['renew_paid_amount'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="renew_remaining_amount">المتبقي</label>
                                <input type="text" id="renew_remaining_amount" value="<?php echo htmlspecialchars(formatAcademyPlayerAmount($renewRemainingValue), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label for="renew_receipt_number">رقم الإيصال</label>
                                <input type="text" name="renew_receipt_number" id="renew_receipt_number" value="<?php echo htmlspecialchars((string) $renewFormData['renew_receipt_number'], ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="info-list">
                            <div><span>الإجمالي الجديد</span><strong id="renew_total_display"><?php echo formatAcademyPlayerMoney($renewTotalValue); ?> ج.م</strong></div>
                            <div><span>المتبقي بعد الدفع</span><strong id="renew_remaining_display"><?php echo formatAcademyPlayerMoney($renewRemainingValue); ?> ج.م</strong></div>
                        </div>
                        <div class="form-actions compact-actions">
                            <button type="submit" class="save-btn">تجديد الاشتراك</button>
                            <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl($currentFilterParams), ENT_QUOTES, 'UTF-8'); ?>" class="clear-btn link-btn">إغلاق</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($detailsPlayer !== null): ?>
                <div class="side-card action-card" id="details-card">
                    <h3>سجل اللاعب</h3>
                    <div class="info-list">
                        <div><span>اللاعب</span><strong><?php echo htmlspecialchars((string) ($detailsPlayer['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>الأكاديمية</span><strong><?php echo htmlspecialchars((string) ($detailsPlayer['academy_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>مرات التجديد</span><strong><?php echo (int) ($detailsPlayer['renewal_count'] ?? 0); ?></strong></div>
                    </div>
                    <div class="payments-history">
                        <?php if ($paymentHistory !== []): ?>
                            <?php foreach ($paymentHistory as $paymentItem): ?>
                                <div class="payment-history-item">
                                    <strong><?php echo formatAcademyPlayerMoney($paymentItem['amount'] ?? 0); ?> ج.م</strong>
                                    <span><?php echo htmlspecialchars((string) ($paymentItem['payment_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span><?php echo htmlspecialchars((string) ($paymentItem['receipt_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span><?php echo htmlspecialchars(formatAcademyPlayerDate((string) ($paymentItem['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">لا توجد حركات مسجلة.</div>
                        <?php endif; ?>
                    </div>
                    <div class="form-actions compact-actions">
                        <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl($currentFilterParams), ENT_QUOTES, 'UTF-8'); ?>" class="clear-btn link-btn">إغلاق</a>
                    </div>
                </div>
            <?php endif; ?>
        </aside>
    </section>

    <section class="table-card">
        <div class="card-head table-head">
            <div>
                <h2>ملخص الأكاديميات</h2>
            </div>
            <span class="table-count"><?php echo count($academySummaries); ?> أكاديمية</span>
        </div>
        <div class="table-wrapper summary-table-wrapper">
            <table class="summary-table">
                <thead>
                    <tr>
                        <th>الأكاديمية</th>
                        <th>سعر الاشتراك</th>
                        <th>عدد اللاعبين</th>
                        <th>الاشتراكات المنتهية</th>
                        <th>إجمالي المحصل</th>
                        <th>إجمالي المتبقي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($academySummaries !== []): ?>
                        <?php foreach ($academySummaries as $academySummary): ?>
                            <tr>
                                <td data-label="الأكاديمية"><?php echo htmlspecialchars((string) ($academySummary['academy_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="سعر الاشتراك"><?php echo formatAcademyPlayerMoney($academySummary['subscription_price'] ?? 0); ?> ج.م</td>
                                <td data-label="عدد اللاعبين"><?php echo (int) ($academySummary['total_players'] ?? 0); ?></td>
                                <td data-label="الاشتراكات المنتهية"><?php echo (int) ($academySummary['expired_players'] ?? 0); ?></td>
                                <td data-label="إجمالي المحصل"><?php echo formatAcademyPlayerMoney($academySummary['total_paid'] ?? 0); ?> ج.م</td>
                                <td data-label="إجمالي المتبقي"><?php echo formatAcademyPlayerMoney($academySummary['total_remaining'] ?? 0); ?> ج.م</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-row">لا توجد أكاديميات مسجلة.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="table-card">
        <div class="card-head table-head">
            <div>
                <h2>جدول اللاعبين</h2>
            </div>
            <span class="table-count"><?php echo count($players); ?> لاعب</span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>اسم اللاعب</th>
                        <th>رقم الهاتف</th>
                        <th>رقم ولي الأمر</th>
                        <th>الأكاديمية</th>
                        <th>بداية الاشتراك</th>
                        <th>نهاية الاشتراك</th>
                        <th>مبلغ الاشتراك</th>
                        <th>الإجمالي</th>
                        <th>المدفوع</th>
                        <th>المتبقي</th>
                        <th>إيصال التسجيل</th>
                        <th>إيصالات التحصيل</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($players !== []): ?>
                        <?php foreach ($players as $player): ?>
                            <tr>
                                <td data-label="اسم اللاعب"><strong><?php echo htmlspecialchars((string) ($player['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td data-label="رقم الهاتف"><?php echo htmlspecialchars((string) ($player['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="رقم ولي الأمر"><?php echo htmlspecialchars((string) ($player['guardian_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="الأكاديمية"><?php echo htmlspecialchars((string) ($player['academy_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="بداية الاشتراك"><?php echo htmlspecialchars(formatAcademyPlayerDate((string) ($player['subscription_start_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="نهاية الاشتراك"><?php echo htmlspecialchars(formatAcademyPlayerDate((string) ($player['subscription_end_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="مبلغ الاشتراك"><span class="amount-badge"><?php echo formatAcademyPlayerMoney($player['subscription_base_price'] ?? 0); ?> ج.م</span></td>
                                <td data-label="الإجمالي"><span class="amount-badge total"><?php echo formatAcademyPlayerMoney($player['subscription_amount'] ?? 0); ?> ج.م</span></td>
                                <td data-label="المدفوع"><span class="amount-badge collected"><?php echo formatAcademyPlayerMoney($player['paid_amount'] ?? 0); ?> ج.م</span></td>
                                <td data-label="المتبقي"><span class="amount-badge remaining <?php echo !empty($player['has_balance']) ? 'has-value' : ''; ?>"><?php echo formatAcademyPlayerMoney($player['remaining_amount'] ?? 0); ?> ج.م</span></td>
                                <td data-label="إيصال التسجيل"><?php echo htmlspecialchars((string) (($player['receipt_number'] ?? '') !== '' ? $player['receipt_number'] : '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="إيصالات التحصيل"><?php echo htmlspecialchars((string) (($player['collection_receipts'] ?? '') !== '' ? $player['collection_receipts'] : '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="الحالة">
                                    <span class="status-badge <?php echo !empty($player['is_expired']) ? 'expired' : 'active'; ?>">
                                        <?php echo htmlspecialchars((string) ($player['subscription_status_label'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td data-label="الإجراءات">
                                    <div class="action-buttons">
                                        <?php if ((float) ($player['remaining_amount'] ?? 0) > 0): ?>
                                            <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl(array_merge($currentFilterParams, ['pay' => $player['id']])), ENT_QUOTES, 'UTF-8'); ?>#collect-payment-card" class="pay-btn">سداد</a>
                                        <?php else: ?>
                                            <span class="action-disabled">مسدد</span>
                                        <?php endif; ?>
                                        <?php if (!empty($player['is_expired'])): ?>
                                            <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl(array_merge($currentFilterParams, ['renew' => $player['id']])), ENT_QUOTES, 'UTF-8'); ?>#renew-card" class="edit-btn">تجديد</a>
                                        <?php endif; ?>
                                        <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl(array_merge($currentFilterParams, ['details' => $player['id']])), ENT_QUOTES, 'UTF-8'); ?>#details-card" class="link-btn files-btn">السجل</a>
                                        <form method="POST" class="inline-form delete-player-form">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="player_id" value="<?php echo (int) ($player['id'] ?? 0); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($academyPlayersCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="current_search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="current_academy_id" value="<?php echo htmlspecialchars($filters['academy_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($filters['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" class="delete-btn">حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="14" class="empty-row">لا توجد نتائج مطابقة.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<script src="assets/js/academies-players.js"></script>
</body>
</html>
