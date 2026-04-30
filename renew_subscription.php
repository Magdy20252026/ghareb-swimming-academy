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

if (!userCanAccess($currentUser, 'renew_subscription')) {
    header('Location: dashboard.php?access=denied');
    exit;
}

define('RENEW_SUBSCRIPTION_PAGE_FILE', basename(__FILE__));
const RENEW_SUBSCRIPTION_TABLE_COLUMNS_COUNT = 9;
const RENEW_SUBSCRIPTION_WEEK_DAYS = [
    'saturday' => 'السبت',
    'sunday' => 'الأحد',
    'monday' => 'الإثنين',
    'tuesday' => 'الثلاثاء',
    'wednesday' => 'الأربعاء',
    'thursday' => 'الخميس',
    'friday' => 'الجمعة',
];
const RENEW_SUBSCRIPTION_CUSTOM_STARS_CATEGORY = 'فرق ستار 3-4';
const RENEW_SUBSCRIPTION_CUSTOM_STARS_OPTIONS = [3, 4];
const RENEW_SUBSCRIPTION_MEDICAL_CATEGORIES = [
    'مدارس سباحة',
    'تجهيزي فرق جديد',
    'تجهيزي فرق A',
    'تجهيزي فرق B',
    'فرق استارات 1 نجمة',
    'فرق استارات 2 نجمة',
    'فرق ستار 3-4',
    'فرق استارات 3 نجمة',
    'فرق استارات 4 نجمة',
    'قطاع بطولة فرق براعم',
    'قطاع بطولة كلاسك',
    'قطاع بطولة زعانف',
];
const RENEW_SUBSCRIPTION_FEDERATION_CATEGORIES = [
    'قطاع بطولة فرق براعم',
    'قطاع بطولة كلاسك',
    'قطاع بطولة زعانف',
];

function normalizeRenewArabicNumbers(string $value): string
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

function sanitizeRenewText(string $value): string
{
    $value = trim(normalizeRenewArabicNumbers($value));
    $sanitizedValue = preg_replace('/\s+/u', ' ', $value);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function normalizeRenewDecimal(string $value): string
{
    $value = trim(normalizeRenewArabicNumbers($value));
    return str_replace(',', '.', $value);
}

function isValidRenewDecimal(string $value, bool $allowZero = false): bool
{
    if ($value === '' || preg_match('/^\d+(?:\.\d{1,2})?$/', $value) !== 1) {
        return false;
    }

    return $allowZero ? (float) $value >= 0 : (float) $value > 0;
}

function formatRenewAmount(int|float|string $value): string
{
    return number_format((float) $value, 2, '.', '');
}

function formatRenewMoney(int|float|string $value): string
{
    return number_format((float) $value, 2);
}

function formatRenewDate(?string $value): string
{
    if (!is_string($value) || trim($value) === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('Y-m-d', $timestamp);
}

function isValidRenewDate(string $value): bool
{
    return $value !== '' && strtotime($value) !== false;
}

function buildRenewPageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return RENEW_SUBSCRIPTION_PAGE_FILE . ($queryString !== '' ? '?' . $queryString : '');
}

function generateRenewSecurityToken(): string
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

    throw new RuntimeException('تعذر إنشاء رمز أمان لتجديد الاشتراك');
}

function getRenewCsrfToken(): string
{
    if (
        !isset($_SESSION['renew_subscription_csrf_token'])
        || !is_string($_SESSION['renew_subscription_csrf_token'])
        || $_SESSION['renew_subscription_csrf_token'] === ''
    ) {
        try {
            $_SESSION['renew_subscription_csrf_token'] = generateRenewSecurityToken();
        } catch (Throwable $exception) {
            http_response_code(500);
            exit('❌ تعذر تهيئة أمان الصفحة، يرجى إعادة المحاولة لاحقًا');
        }
    }

    return $_SESSION['renew_subscription_csrf_token'];
}

function isValidRenewCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getRenewCsrfToken(), $submittedToken);
}

function setRenewFlash(string $message, string $type): void
{
    $_SESSION['renew_subscription_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumeRenewFlash(): array
{
    $flash = $_SESSION['renew_subscription_flash'] ?? null;
    unset($_SESSION['renew_subscription_flash']);

    if (!is_array($flash)) {
        return ['message' => '', 'type' => ''];
    }

    return [
        'message' => (string) ($flash['message'] ?? ''),
        'type' => (string) ($flash['type'] ?? ''),
    ];
}

function decodeRenewSchedule(?string $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decodedValue = json_decode($value, true);
    if (!is_array($decodedValue)) {
        return [];
    }

    $schedule = [];
    foreach ($decodedValue as $item) {
        if (!is_array($item)) {
            continue;
        }

        $dayKey = trim((string) ($item['key'] ?? ''));
        $timeValue = formatAcademyTimeTo12Hour((string) ($item['time'] ?? ''));
        if ($dayKey === '' || $timeValue === '' || !isset(RENEW_SUBSCRIPTION_WEEK_DAYS[$dayKey])) {
            continue;
        }

        $schedule[] = [
            'key' => $dayKey,
            'label' => RENEW_SUBSCRIPTION_WEEK_DAYS[$dayKey],
            'time' => $timeValue,
        ];
    }

    return $schedule;
}

function buildRenewScheduleSummary(array $schedule): string
{
    $parts = [];

    foreach ($schedule as $item) {
        $label = trim((string) ($item['label'] ?? ''));
        $time = trim((string) ($item['time'] ?? ''));
        if ($label === '' || $time === '') {
            continue;
        }

        $parts[] = $label . ' - ' . formatAcademyTimeTo12Hour($time);
    }

    return $parts === [] ? '—' : implode(' • ', $parts);
}

function renewPhpWeekDayName(DateTimeImmutable $date): string
{
    return strtolower($date->format('l'));
}

function calculateRenewEndDate(array $subscription, string $startDate): string
{
    $schedule = decodeRenewSchedule((string) ($subscription['training_schedule'] ?? ''));
    $availableExercisesCount = max((int) ($subscription['available_exercises_count'] ?? 0), 1);

    if ($schedule === [] || !isValidRenewDate($startDate)) {
        return '';
    }

    try {
        $currentDate = new DateTimeImmutable($startDate);
    } catch (Throwable $exception) {
        return '';
    }

    $scheduleLookup = [];
    foreach ($schedule as $item) {
        $scheduleLookup[(string) ($item['key'] ?? '')] = true;
    }

    $sessionsCount = 0;
    $endDate = $currentDate;

    while ($sessionsCount < $availableExercisesCount) {
        if (isset($scheduleLookup[renewPhpWeekDayName($currentDate)])) {
            $sessionsCount++;
            $endDate = $currentDate;
        }

        if ($sessionsCount >= $availableExercisesCount) {
            break;
        }

        $currentDate = $currentDate->modify('+1 day');
    }

    return $endDate->format('Y-m-d');
}

function renewCategoryRequiresMedical(string $category): bool
{
    return in_array($category, RENEW_SUBSCRIPTION_MEDICAL_CATEGORIES, true);
}

function renewCategoryRequiresFederation(string $category): bool
{
    return in_array($category, RENEW_SUBSCRIPTION_FEDERATION_CATEGORIES, true);
}

function renewCategoryStarsCount(string $category): int
{
    if (preg_match('/فرق استارات\s+([1-4])\s+نجمة/u', $category, $matches) === 1) {
        return (int) $matches[1];
    }

    return 0;
}

function renewCategoryAllowsCustomStars(string $category): bool
{
    return $category === RENEW_SUBSCRIPTION_CUSTOM_STARS_CATEGORY;
}

function renewAllowedCustomStarsCount(int $starsCount): bool
{
    return in_array($starsCount, RENEW_SUBSCRIPTION_CUSTOM_STARS_OPTIONS, true);
}

function formatRenewSubscriptionDisplay(array $subscription): string
{
    $subscriptionName = sanitizeRenewText((string) ($subscription['subscription_name'] ?? ''));
    if ($subscriptionName !== '') {
        return $subscriptionName;
    }

    $subscriptionBranch = sanitizeRenewText((string) ($subscription['subscription_branch'] ?? ''));
    return $subscriptionBranch !== '' ? $subscriptionBranch : '—';
}

function fetchRenewSubscriptions(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT
            s.id,
            s.subscription_name,
            s.subscription_branch,
            s.subscription_category,
            s.training_days_count,
            s.available_exercises_count,
            s.training_schedule,
            s.max_trainees,
            c.full_name AS coach_name,
            COALESCE(SUM(CASE WHEN ap.academy_id = 0 AND ap.subscription_end_date >= CURDATE() AND ap.available_exercises_count > 0 THEN 1 ELSE 0 END), 0) AS active_players_count
         FROM subscriptions s
         INNER JOIN coaches c ON c.id = s.coach_id
         LEFT JOIN academy_players ap ON ap.subscription_id = s.id
         GROUP BY
            s.id,
            s.subscription_name,
            s.subscription_branch,
            s.subscription_category,
            s.training_days_count,
            s.available_exercises_count,
            s.training_schedule,
            s.max_trainees,
            c.full_name
         ORDER BY s.subscription_name ASC, s.id ASC'
    );

    $subscriptions = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($subscriptions as &$subscription) {
        $schedule = decodeRenewSchedule((string) ($subscription['training_schedule'] ?? ''));
        $subscription['schedule_summary'] = buildRenewScheduleSummary($schedule);
        $subscription['schedule_days'] = implode(',', array_map(static fn(array $item): string => (string) ($item['key'] ?? ''), $schedule));
        $subscription['subscription_name'] = buildAcademySubscriptionName(
            (string) ($subscription['subscription_category'] ?? ''),
            (string) ($subscription['coach_name'] ?? ''),
            (string) ($subscription['schedule_summary'] ?? ''),
            (string) ($subscription['subscription_branch'] ?? ''),
            (string) ($subscription['subscription_name'] ?? '')
        );
    }
    unset($subscription);

    return $subscriptions;
}

function fetchRenewPlayerById(PDO $pdo, int $playerId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM academy_players WHERE id = ? AND academy_id = 0 LIMIT 1');
    $stmt->execute([$playerId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    return $player ?: null;
}

function isRenewableExpiredPlayer(?array $player): bool
{
    if (!is_array($player)) {
        return false;
    }

    $availableExercisesCount = (int) ($player['available_exercises_count'] ?? 0);
    $endDate = (string) ($player['subscription_end_date'] ?? '');
    $today = date('Y-m-d');

    return $availableExercisesCount <= 0 || ($endDate !== '' && $endDate < $today);
}

function fetchExpiredRenewalPlayers(PDO $pdo, string $search, string $branch): array
{
    $sql = 'SELECT *
            FROM academy_players
            WHERE academy_id = 0
              AND (subscription_end_date < CURDATE() OR available_exercises_count <= 0)';
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (barcode LIKE ? OR player_name LIKE ? OR guardian_phone LIKE ? OR phone LIKE ?)';
        $searchValue = '%' . $search . '%';
        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
    }

    if ($branch !== '') {
        $sql .= ' AND subscription_branch = ?';
        $params[] = $branch;
    }

    $sql .= ' ORDER BY subscription_end_date ASC, updated_at DESC, id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetchRenewBranches(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT DISTINCT subscription_branch
         FROM academy_players
         WHERE academy_id = 0
           AND subscription_branch IS NOT NULL
           AND CHAR_LENGTH(TRIM(subscription_branch)) > 0
           AND (subscription_end_date < CURDATE() OR available_exercises_count <= 0)
         ORDER BY subscription_branch ASC'
    );
    $branches = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $sanitizedBranches = array_map(static fn($branch): string => sanitizeRenewText((string) $branch), $branches);

    return array_values(array_filter($sanitizedBranches, static fn(string $branch): bool => $branch !== ''));
}

function fetchRenewSummary(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT
            COUNT(*) AS total_expired,
            COALESCE(SUM(CASE WHEN remaining_amount > 0 THEN 1 ELSE 0 END), 0) AS with_balance_count,
            COALESCE(SUM(remaining_amount), 0) AS total_remaining
         FROM academy_players
         WHERE academy_id = 0
           AND (subscription_end_date < CURDATE() OR available_exercises_count <= 0)'
    );

    return $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
}

function countRenewActivePlayersForSubscription(PDO $pdo, int $subscriptionId, ?int $excludePlayerId = null): int
{
    $sql = 'SELECT COUNT(*)
            FROM academy_players
            WHERE academy_id = 0
              AND subscription_id = ?
              AND subscription_end_date >= CURDATE()
              AND available_exercises_count > 0';
    $params = [$subscriptionId];

    if ($excludePlayerId !== null && $excludePlayerId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludePlayerId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$flashMessage = consumeRenewFlash();
$message = $flashMessage['message'];
$messageType = $flashMessage['type'];
$search = sanitizeRenewText((string) ($_GET['search'] ?? ''));
$branch = sanitizeRenewText((string) ($_GET['branch'] ?? ''));
$subscriptions = fetchRenewSubscriptions($pdo);
$subscriptionsById = [];
foreach ($subscriptions as $subscription) {
    $subscriptionsById[(int) ($subscription['id'] ?? 0)] = $subscription;
}
$branchOptions = fetchRenewBranches($pdo);
if ($branch !== '' && !in_array($branch, $branchOptions, true)) {
    $branchOptions[] = $branch;
    sort($branchOptions);
}

$renewPlayer = null;
$submittedRenewData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $search = sanitizeRenewText((string) ($_POST['current_search'] ?? $search));
    $branch = sanitizeRenewText((string) ($_POST['current_branch'] ?? $branch));

    if ($action !== '' && !isValidRenewCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = '❌ انتهت صلاحية الطلب، يرجى إعادة المحاولة.';
        $messageType = 'error';
    } elseif ($action === 'renew_subscription') {
        $playerId = (int) trim((string) ($_POST['player_id'] ?? '0'));
        $subscriptionId = (int) trim((string) ($_POST['subscription_id'] ?? '0'));
        $startDate = trim((string) ($_POST['renew_start_date'] ?? ''));
        $basePriceInput = normalizeRenewDecimal((string) ($_POST['renew_base_price'] ?? '0'));
        $paidAmountInput = normalizeRenewDecimal((string) ($_POST['renew_paid_amount'] ?? '0'));
        $receiptNumber = sanitizeRenewText((string) ($_POST['renew_receipt_number'] ?? ''));

        $renewPlayer = fetchRenewPlayerById($pdo, $playerId);
        $subscription = $subscriptionsById[$subscriptionId] ?? null;

        $submittedRenewData = [
            'subscription_id' => $subscriptionId > 0 ? (string) $subscriptionId : '',
            'renew_start_date' => $startDate,
            'renew_base_price' => $basePriceInput === '' ? '0.00' : $basePriceInput,
            'renew_paid_amount' => $paidAmountInput === '' ? '0.00' : $paidAmountInput,
            'renew_receipt_number' => $receiptNumber,
        ];

        if ($renewPlayer === null) {
            $message = '❌ السباح المطلوب غير موجود.';
            $messageType = 'error';
        } elseif (!isRenewableExpiredPlayer($renewPlayer)) {
            $message = '❌ التجديد متاح للاشتراكات المنتهية فقط.';
            $messageType = 'error';
        } elseif ($subscription === null) {
            $message = '❌ اختر مجموعة صحيحة.';
            $messageType = 'error';
        } elseif (!isValidRenewDate($startDate)) {
            $message = '❌ اختر تاريخ بداية اشتراك صحيحًا.';
            $messageType = 'error';
        } elseif (!isValidRenewDecimal($basePriceInput === '' ? '0' : $basePriceInput, true)) {
            $message = '❌ أدخل سعر اشتراك صحيحًا.';
            $messageType = 'error';
        } elseif (!isValidRenewDecimal($paidAmountInput === '' ? '0' : $paidAmountInput, true)) {
            $message = '❌ أدخل مبلغًا صحيحًا للمدفوع.';
            $messageType = 'error';
        } else {
            $oldRemaining = (float) ($renewPlayer['remaining_amount'] ?? 0);
            $basePrice = (float) ($basePriceInput === '' ? '0' : $basePriceInput);
            $paidAmount = (float) ($paidAmountInput === '' ? '0' : $paidAmountInput);
            $renewalTotal = $oldRemaining + $basePrice;
            $renewalRemaining = max($renewalTotal - $paidAmount, 0);
            $subscriptionEndDate = calculateRenewEndDate($subscription, $startDate);
            $subscriptionCategory = (string) ($subscription['subscription_category'] ?? '');
            $subscriptionBranch = sanitizeRenewText((string) ($subscription['subscription_branch'] ?? ''));
            if ($subscriptionBranch === '') {
                $subscriptionBranch = (string) ($renewPlayer['subscription_branch'] ?? '');
            }
            $requiresMedical = renewCategoryRequiresMedical($subscriptionCategory);
            $requiresFederation = renewCategoryRequiresFederation($subscriptionCategory);
            $fixedStarsCount = renewCategoryStarsCount($subscriptionCategory);
            $allowsCustomStars = renewCategoryAllowsCustomStars($subscriptionCategory);
            $existingStarsCount = (int) ($renewPlayer['stars_count'] ?? 0);
            $starsCount = $allowsCustomStars
                ? (renewAllowedCustomStarsCount($existingStarsCount) ? $existingStarsCount : (RENEW_SUBSCRIPTION_CUSTOM_STARS_OPTIONS[0] ?? 0))
                : $fixedStarsCount;
            $lastStarDate = $starsCount > 0 ? ((string) ($renewPlayer['last_star_date'] ?? '') ?: null) : null;

            if ($paidAmount > $renewalTotal) {
                $message = '❌ المدفوع لا يمكن أن يكون أكبر من الإجمالي.';
                $messageType = 'error';
            } elseif ($paidAmount > 0 && $receiptNumber === '') {
                $message = '❌ أدخل رقم الإيصال.';
                $messageType = 'error';
            } elseif ($subscriptionEndDate === '') {
                $message = '❌ لا يمكن احتساب تاريخ نهاية الاشتراك.';
                $messageType = 'error';
            } else {
                $currentPlayersCount = countRenewActivePlayersForSubscription($pdo, $subscriptionId, $playerId > 0 ? $playerId : null);
                if ($currentPlayersCount >= (int) ($subscription['max_trainees'] ?? 0)) {
                    $message = '❌ تم الوصول إلى الحد الأقصى لهذه المجموعة.';
                    $messageType = 'error';
                } else {
                    try {
                        $pdo->beginTransaction();

                        $updateStmt = $pdo->prepare(
                            'UPDATE academy_players SET
                                subscription_id = ?,
                                subscription_start_date = ?,
                                subscription_end_date = ?,
                                subscription_name = ?,
                                subscription_branch = ?,
                                subscription_category = ?,
                                subscription_training_days_count = ?,
                                available_exercises_count = ?,
                                subscription_training_schedule = ?,
                                subscription_coach_name = ?,
                                max_trainees = ?,
                                subscription_base_price = ?,
                                additional_discount = ?,
                                subscription_amount = ?,
                                paid_amount = ?,
                                remaining_amount = ?,
                                receipt_number = ?,
                                medical_report_required = ?,
                                medical_report_path = ?,
                                federation_card_required = ?,
                                federation_card_path = ?,
                                stars_count = ?,
                                last_star_date = ?,
                                renewal_count = renewal_count + 1,
                                last_renewed_at = CURRENT_TIMESTAMP,
                                last_renewed_by_user_id = ?,
                                last_payment_at = ?
                             WHERE id = ? AND academy_id = 0'
                        );
                        $updateStmt->execute([
                            $subscriptionId,
                            $startDate,
                            $subscriptionEndDate,
                            (string) ($subscription['subscription_name'] ?? ''),
                            $subscriptionBranch,
                            $subscriptionCategory,
                            (int) ($subscription['training_days_count'] ?? 0),
                            (int) ($subscription['available_exercises_count'] ?? 0),
                            (string) ($subscription['schedule_summary'] ?? ''),
                            (string) ($subscription['coach_name'] ?? ''),
                            (int) ($subscription['max_trainees'] ?? 0),
                            formatRenewAmount($basePrice),
                            '0.00',
                            formatRenewAmount($renewalTotal),
                            formatRenewAmount($paidAmount),
                            formatRenewAmount($renewalRemaining),
                            $receiptNumber,
                            $requiresMedical ? (!empty($renewPlayer['medical_report_required']) ? 1 : 0) : 0,
                            $requiresMedical ? ((string) ($renewPlayer['medical_report_path'] ?? '') ?: null) : null,
                            $requiresFederation ? (!empty($renewPlayer['federation_card_required']) ? 1 : 0) : 0,
                            $requiresFederation ? ((string) ($renewPlayer['federation_card_path'] ?? '') ?: null) : null,
                            $starsCount > 0 ? $starsCount : null,
                            $lastStarDate,
                            (int) ($currentUser['id'] ?? 0) ?: null,
                            $paidAmount > 0 ? date('Y-m-d H:i:s') : ($renewPlayer['last_payment_at'] ?? null),
                            $playerId,
                        ]);

                        recordAcademyPlayerPayment($pdo, [
                            'player_id' => $playerId,
                            'payment_type' => 'renewal',
                            'amount' => $paidAmount,
                            'receipt_number' => $receiptNumber,
                            'created_by_user_id' => (int) ($currentUser['id'] ?? 0) ?: null,
                            'player_name_snapshot' => (string) ($renewPlayer['player_name'] ?? ''),
                            'subscription_name_snapshot' => (string) ($subscription['subscription_name'] ?? ''),
                            'subscription_amount_snapshot' => $renewalTotal,
                            'paid_amount_before_snapshot' => 0,
                            'paid_amount_after_snapshot' => $paidAmount,
                            'remaining_amount_before_snapshot' => $renewalTotal,
                            'remaining_amount_after_snapshot' => $renewalRemaining,
                        ]);

                        $pdo->commit();
                        setRenewFlash('✅ تم تجديد اشتراك السباح بنجاح.', 'success');
                        header('Location: ' . buildRenewPageUrl(['search' => $search, 'branch' => $branch]));
                        exit;
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $message = '❌ حدث خطأ أثناء تجديد الاشتراك.';
                        $messageType = 'error';
                    }
                }
            }
        }
    }
}

if (isset($_GET['renew']) && ctype_digit((string) $_GET['renew'])) {
    $requestedPlayer = fetchRenewPlayerById($pdo, (int) $_GET['renew']);
    if ($requestedPlayer !== null && isRenewableExpiredPlayer($requestedPlayer)) {
        $renewPlayer = $requestedPlayer;
    } elseif ($message === '') {
        $message = '❌ السباح المطلوب غير متاح للتجديد.';
        $messageType = 'error';
    }
}

$expiredPlayers = fetchExpiredRenewalPlayers($pdo, $search, $branch);
$renewSummary = fetchRenewSummary($pdo);
$renewFormData = [
    'subscription_id' => $renewPlayer !== null && isset($renewPlayer['subscription_id']) ? (string) $renewPlayer['subscription_id'] : '',
    'renew_start_date' => date('Y-m-d'),
    'renew_base_price' => '0.00',
    'renew_paid_amount' => '0.00',
    'renew_receipt_number' => '',
];

if ($renewFormData['subscription_id'] === '' && $subscriptions !== []) {
    $renewFormData['subscription_id'] = (string) ($subscriptions[0]['id'] ?? '');
}

if (is_array($submittedRenewData)) {
    $renewFormData = array_merge($renewFormData, $submittedRenewData);
}

$selectedSubscription = null;
if ($renewFormData['subscription_id'] !== '' && ctype_digit($renewFormData['subscription_id'])) {
    $selectedSubscription = $subscriptionsById[(int) $renewFormData['subscription_id']] ?? null;
}
if ($selectedSubscription === null && $subscriptions !== []) {
    $selectedSubscription = $subscriptions[0];
    $renewFormData['subscription_id'] = (string) ($selectedSubscription['id'] ?? '');
}

$renewStartDateValue = (string) ($renewFormData['renew_start_date'] ?? '');
$renewComputedEndDate = $selectedSubscription !== null && isValidRenewDate($renewStartDateValue)
    ? calculateRenewEndDate($selectedSubscription, $renewStartDateValue)
    : '';
$renewOldRemaining = $renewPlayer !== null ? (float) ($renewPlayer['remaining_amount'] ?? 0) : 0.0;
$renewBasePriceValue = (float) normalizeRenewDecimal((string) ($renewFormData['renew_base_price'] ?? '0'));
$renewPaidValue = (float) normalizeRenewDecimal((string) ($renewFormData['renew_paid_amount'] ?? '0'));
$renewTotalValue = $renewOldRemaining + $renewBasePriceValue;
$renewRemainingValue = max($renewTotalValue - $renewPaidValue, 0);
$renewCsrfToken = getRenewCsrfToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تجديد الاشتراك</title>
    <link rel="stylesheet" href="assets/css/renew-subscription.css">
</head>
<body class="light-mode" data-page-url="<?php echo htmlspecialchars(RENEW_SUBSCRIPTION_PAGE_FILE, ENT_QUOTES, 'UTF-8'); ?>">
<div class="academy-players-page">
    <header class="page-header">
        <div class="header-text">
            <span class="eyebrow">تجديد الاشتراك</span>
            <h1>تجديد اشتراكات السباحين</h1>
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
            <span class="hero-icon">🔄</span>
            <h2>الاشتراكات المنتهية الجاهزة للتجديد</h2>
        </article>
        <article class="hero-card">
            <span>إجمالي المنتهي</span>
            <strong><?php echo (int) ($renewSummary['total_expired'] ?? 0); ?></strong>
        </article>
        <article class="hero-card">
            <span>عليهم متبقي</span>
            <strong><?php echo (int) ($renewSummary['with_balance_count'] ?? 0); ?></strong>
        </article>
        <article class="hero-card">
            <span>إجمالي المتبقي القديم</span>
            <strong><?php echo formatRenewMoney($renewSummary['total_remaining'] ?? 0); ?> ج.م</strong>
        </article>
        <article class="hero-card">
            <span>نتائج البحث</span>
            <strong><?php echo count($expiredPlayers); ?></strong>
        </article>
    </section>

    <section class="content-grid">
        <div class="form-card">
            <div class="card-head">
                <h2><?php echo $renewPlayer !== null ? 'نموذج التجديد' : 'اختر سباحًا من الجدول'; ?></h2>
            </div>

            <?php if ($renewPlayer !== null): ?>
                <div class="subscription-overview-grid">
                    <div class="overview-item">
                        <span>السباح</span>
                        <strong><?php echo htmlspecialchars((string) ($renewPlayer['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="overview-item">
                        <span>المجموعة الحالية</span>
                        <strong><?php echo htmlspecialchars((string) ($renewPlayer['subscription_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="overview-item">
                        <span>نهاية الاشتراك الحالي</span>
                        <strong><?php echo htmlspecialchars(formatRenewDate((string) ($renewPlayer['subscription_end_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="overview-item">
                        <span>المتبقي القديم</span>
                        <strong id="old_remaining_display"><?php echo formatRenewMoney($renewOldRemaining); ?> ج.م</strong>
                    </div>
                </div>

                <form method="POST" class="player-form" id="renewSubscriptionForm" autocomplete="off">
                    <input type="hidden" name="action" value="renew_subscription">
                    <input type="hidden" name="player_id" value="<?php echo (int) ($renewPlayer['id'] ?? 0); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($renewCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="current_search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="current_branch" value="<?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-grid form-grid-compact">
                        <div class="form-group form-group-full subscription-select-group">
                            <label for="subscription_id">المجموعة الجديدة</label>
                            <select name="subscription_id" id="subscription_id" required <?php echo $subscriptions === [] ? 'disabled' : ''; ?>>
                                <?php if ($subscriptions === []): ?>
                                    <option value="">لا توجد مجموعات متاحة</option>
                                <?php else: ?>
                                    <?php foreach ($subscriptions as $subscription): ?>
                                        <?php $subscriptionId = (int) ($subscription['id'] ?? 0); ?>
                                        <option
                                            value="<?php echo $subscriptionId; ?>"
                                            data-category="<?php echo htmlspecialchars((string) ($subscription['subscription_category'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-coach="<?php echo htmlspecialchars((string) ($subscription['coach_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-schedule="<?php echo htmlspecialchars((string) ($subscription['schedule_summary'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-schedule-days="<?php echo htmlspecialchars((string) ($subscription['schedule_days'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-exercises="<?php echo (int) ($subscription['available_exercises_count'] ?? 0); ?>"
                                            data-max-trainees="<?php echo (int) ($subscription['max_trainees'] ?? 0); ?>"
                                            data-current-count="<?php echo (int) ($subscription['active_players_count'] ?? 0); ?>"
                                            <?php echo (string) $subscriptionId === (string) $renewFormData['subscription_id'] ? 'selected' : ''; ?>
                                        ><?php echo htmlspecialchars(formatRenewSubscriptionDisplay($subscription), ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <div class="subscription-overview-grid">
                        <div class="overview-item">
                            <span>مستوى المجموعة</span>
                            <strong id="subscription_category_display"><?php echo htmlspecialchars((string) ($selectedSubscription['subscription_category'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <div class="overview-item">
                            <span>المدرب</span>
                            <strong id="subscription_coach_display"><?php echo htmlspecialchars((string) ($selectedSubscription['coach_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <div class="overview-item">
                            <span>الأيام والساعة</span>
                            <strong id="subscription_schedule_display"><?php echo htmlspecialchars((string) ($selectedSubscription['schedule_summary'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <div class="overview-item">
                            <span>عدد التمارين</span>
                            <strong id="subscription_exercises_display"><?php echo htmlspecialchars((string) ($selectedSubscription['available_exercises_count'] ?? '0'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <div class="overview-item">
                            <span>السباحين / الحد الأقصى</span>
                            <strong id="subscription_capacity_display"><?php echo htmlspecialchars(((string) ($selectedSubscription['active_players_count'] ?? '0')) . ' / ' . ((string) ($selectedSubscription['max_trainees'] ?? '0')), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <div class="overview-item">
                            <span>تاريخ نهاية الاشتراك الجديد</span>
                            <strong id="renew_end_display"><?php echo htmlspecialchars($renewComputedEndDate !== '' ? $renewComputedEndDate : '—', ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                    </div>

                    <div class="form-grid form-grid-compact payment-grid">
                        <div class="form-group">
                            <label for="renew_start_date">تاريخ بداية الاشتراك</label>
                            <input type="date" name="renew_start_date" id="renew_start_date" value="<?php echo htmlspecialchars((string) $renewFormData['renew_start_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="renew_base_price">سعر الاشتراك</label>
                            <input type="number" min="0" step="0.01" name="renew_base_price" id="renew_base_price" value="<?php echo htmlspecialchars((string) $renewFormData['renew_base_price'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="renew_old_remaining">المتبقي القديم</label>
                            <input type="text" id="renew_old_remaining" value="<?php echo htmlspecialchars(formatRenewAmount($renewOldRemaining), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label for="renew_total_amount">الإجمالي</label>
                            <input type="text" id="renew_total_amount" value="<?php echo htmlspecialchars(formatRenewAmount($renewTotalValue), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label for="renew_paid_amount">المدفوع</label>
                            <input type="number" min="0" step="0.01" name="renew_paid_amount" id="renew_paid_amount" value="<?php echo htmlspecialchars((string) $renewFormData['renew_paid_amount'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="renew_remaining_amount">المتبقي</label>
                            <input type="text" id="renew_remaining_amount" value="<?php echo htmlspecialchars(formatRenewAmount($renewRemainingValue), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label for="renew_receipt_number">رقم الإيصال</label>
                            <input type="text" name="renew_receipt_number" id="renew_receipt_number" value="<?php echo htmlspecialchars((string) $renewFormData['renew_receipt_number'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="save-btn" <?php echo $subscriptions === [] ? 'disabled' : ''; ?>>تجديد الاشتراك</button>
                        <a href="<?php echo htmlspecialchars(buildRenewPageUrl(['search' => $search, 'branch' => $branch]), ENT_QUOTES, 'UTF-8'); ?>" class="clear-btn link-btn">إلغاء</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <aside class="side-panel">
            <div class="side-card">
                <h3>البحث</h3>
                <form method="GET" class="filter-form" autocomplete="off">
                    <div class="form-group">
                        <label for="search">ابحث بالكود أو اسم السباح أو رقم ولي الأمر</label>
                        <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="branch">الفرع</label>
                        <select name="branch" id="branch">
                            <option value="">كل الفروع</option>
                            <?php foreach ($branchOptions as $branchOption): ?>
                                <option value="<?php echo htmlspecialchars($branchOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $branch === $branchOption ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($branchOption, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-actions compact-actions">
                        <button type="submit" class="save-btn">بحث</button>
                        <a href="renew_subscription.php" class="clear-btn link-btn">إعادة ضبط</a>
                    </div>
                </form>
            </div>
        </aside>
    </section>

    <section class="table-card">
        <div class="card-head table-head">
            <div>
                <h2>السباحون المنتهية اشتراكاتهم</h2>
            </div>
            <span class="table-count"><?php echo count($expiredPlayers); ?> سباح</span>
        </div>
        <div class="table-wrapper renew-table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>كود السباح</th>
                        <th>اسم السباح</th>
                        <th>رقم السباح</th>
                        <th>رقم ولي الأمر</th>
                        <th>المجموعة الحالية</th>
                        <th>نهاية الاشتراك</th>
                        <th>المتبقي القديم</th>
                        <th>مرات التجديد</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($expiredPlayers !== []): ?>
                        <?php foreach ($expiredPlayers as $player): ?>
                            <tr>
                                <td data-label="كود السباح"><span class="table-cell-text"><?php echo htmlspecialchars((string) ($player['barcode'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="اسم السباح"><strong><?php echo htmlspecialchars((string) ($player['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td data-label="رقم السباح"><span class="table-cell-text"><?php echo htmlspecialchars((string) ($player['phone'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="رقم ولي الأمر"><span class="table-cell-text"><?php echo htmlspecialchars((string) ($player['guardian_phone'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="المجموعة الحالية"><span class="table-cell-text table-cell-multiline"><?php echo htmlspecialchars((string) ($player['subscription_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="نهاية الاشتراك"><span class="table-cell-text table-cell-date"><?php echo htmlspecialchars(formatRenewDate((string) ($player['subscription_end_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="المتبقي القديم"><span class="amount-badge remaining <?php echo (float) ($player['remaining_amount'] ?? 0) > 0 ? 'has-value' : ''; ?>"><?php echo formatRenewMoney($player['remaining_amount'] ?? 0); ?> ج.م</span></td>
                                <td data-label="مرات التجديد"><span class="table-cell-text"><?php echo (int) ($player['renewal_count'] ?? 0); ?></span></td>
                                <td data-label="الإجراءات">
                                    <div class="action-buttons">
                                        <a href="<?php echo htmlspecialchars(buildRenewPageUrl(['search' => $search, 'branch' => $branch, 'renew' => $player['id']]), ENT_QUOTES, 'UTF-8'); ?>#renewSubscriptionForm" class="pay-btn">تجديد</a>
                                        <a href="<?php echo htmlspecialchars('academy_players.php?search=' . urlencode((string) ($player['barcode'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" class="link-btn files-btn">صفحة السباحين</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo RENEW_SUBSCRIPTION_TABLE_COLUMNS_COUNT; ?>" class="empty-row">لا توجد اشتراكات منتهية مطابقة للبحث.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<script src="assets/js/renew-subscription.js"></script>
</body>
</html>
