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

if (!userCanAccess($currentUser, 'academy_players')) {
    header('Location: dashboard.php?access=denied');
    exit;
}

define('ACADEMY_PLAYERS_PAGE_FILE', basename(__FILE__));
define('ACADEMY_PLAYERS_UPLOAD_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'academy_players');
define('ACADEMY_PLAYERS_UPLOAD_PUBLIC_DIR', 'uploads/academy_players');
const ACADEMY_PLAYER_STATUS_FILTERS = [
    'all' => 'الكل',
    'active' => 'المجموعات المستمرة',
    'expired' => 'المجموعات المنتهية',
    'with_balance' => 'مجموعات متبقي عليها مبالغ',
];
const ACADEMY_PLAYER_MEDICAL_REPORT_FILTERS = [
    'all' => 'الكل',
    'required_missing' => 'مطلوب وغير مرفوع',
];
const ACADEMY_PLAYERS_CUSTOM_STARS_CATEGORY = 'فرق ستار 3-4';
const ACADEMY_PLAYERS_CUSTOM_STARS_OPTIONS = [3, 4];
const ACADEMY_PLAYERS_MANUAL_STARS_OPTIONS = [1, 2, 3, 4];
const ACADEMY_PLAYERS_TABLE_COLUMNS_COUNT = 25;
const ACADEMY_PLAYERS_PER_PAGE = 10;
const ACADEMY_PLAYER_DEFAULT_PASSWORD = '123456';
const ACADEMY_PLAYERS_WEEK_DAYS = [
    'saturday' => 'السبت',
    'sunday' => 'الأحد',
    'monday' => 'الإثنين',
    'tuesday' => 'الثلاثاء',
    'wednesday' => 'الأربعاء',
    'thursday' => 'الخميس',
    'friday' => 'الجمعة',
];
const ACADEMY_PLAYERS_MEDICAL_CATEGORIES = [
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
const ACADEMY_PLAYERS_FEDERATION_CATEGORIES = [
    'قطاع بطولة فرق براعم',
    'قطاع بطولة كلاسك',
    'قطاع بطولة زعانف',
];
const ACADEMY_PLAYERS_EXPORT_HEADERS = [
    'الباركود',
    'اسم السباح',
    'رقم الأب',
    'رقم الأم',
    'تاريخ الميلاد',
    'السن',
    'المجموعة',
    'مستوى المجموعة',
    'الفرع',
    'المدرب',
    'الأيام والساعة',
    'عدد التمارين',
    'تاريخ بداية الاشتراك',
    'نهاية الاشتراك',
    'السعر',
    'خصم الإدارة',
    'الإجمالي',
    'المدفوع',
    'المتبقي',
    'رقم الإيصال',
    'التقرير الطبي',
    'كارنية الاتحاد',
    'النجوم',
    'تاريخ آخر نجمة',
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

function sanitizeAcademyPlayerIdentifier(string $value): string
{
    $value = trim(normalizeAcademyPlayersArabicNumbers($value));
    $sanitizedValue = preg_replace('/\s+/u', '', $value);
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

function calculateAcademyPlayerAgeFromYear(?int $birthYear): string
{
    if ($birthYear === null || $birthYear <= 0) {
        return '—';
    }
    $age = (int) date('Y') - $birthYear;
    return $age >= 0 ? (string) $age : '—';
}

function calculateAcademyPlayerAge(?string $birthDate): string
{
    if (!is_string($birthDate) || $birthDate === '' || strtotime($birthDate) === false) {
        return '—';
    }

    try {
        $birth = new DateTimeImmutable($birthDate);
        $today = new DateTimeImmutable(date('Y-m-d'));
    } catch (Throwable $exception) {
        return '—';
    }

    return (string) $birth->diff($today)->y;
}

function resolvePlayerBirthYear(array $player): ?int
{
    if (isset($player['birth_year']) && $player['birth_year'] !== null && $player['birth_year'] !== '') {
        $year = (int) $player['birth_year'];
        return $year > 0 ? $year : null;
    }
    if (isset($player['birth_date']) && is_string($player['birth_date']) && $player['birth_date'] !== '') {
        $ts = strtotime($player['birth_date']);
        return $ts !== false ? (int) date('Y', $ts) : null;
    }
    return null;
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

    throw new RuntimeException('تعذر إنشاء رمز أمان للسباحين');
}

function getAcademyPlayersCsrfToken(): string
{
    if (
        !isset($_SESSION['academy_players_csrf_token'])
        || !is_string($_SESSION['academy_players_csrf_token'])
        || $_SESSION['academy_players_csrf_token'] === ''
    ) {
        try {
            $_SESSION['academy_players_csrf_token'] = generateAcademyPlayersSecurityToken();
        } catch (Throwable $exception) {
            error_log('تعذر إنشاء رمز التحقق الخاص بالسباحين');
            http_response_code(500);
            exit('❌ تعذر تهيئة أمان الصفحة، يرجى إعادة المحاولة لاحقًا');
        }
    }

    return $_SESSION['academy_players_csrf_token'];
}

function isValidAcademyPlayersCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getAcademyPlayersCsrfToken(), $submittedToken);
}

function setAcademyPlayersFlash(string $message, string $type): void
{
    $_SESSION['academy_players_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumeAcademyPlayersFlash(): array
{
    $flash = $_SESSION['academy_players_flash'] ?? null;
    unset($_SESSION['academy_players_flash']);

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

function normalizeAcademyPlayersMedicalReportFilter(string $value): string
{
    return array_key_exists($value, ACADEMY_PLAYER_MEDICAL_REPORT_FILTERS) ? $value : 'all';
}

function academyPlayersFilterParamsFromArray(array $source): array
{
    return [
        'search' => sanitizeAcademyPlayerText((string) ($source['current_search'] ?? $source['search'] ?? '')),
        'subscription_id' => trim((string) ($source['current_subscription_id'] ?? $source['subscription_id'] ?? '')),
        'branch' => sanitizeAcademyPlayerText((string) ($source['current_branch'] ?? $source['branch'] ?? '')),
        'category' => sanitizeAcademyPlayerText((string) ($source['current_category'] ?? $source['category'] ?? '')),
        'status' => normalizeAcademyPlayersStatusFilter((string) ($source['current_status'] ?? $source['status'] ?? 'all')),
        'medical_report' => normalizeAcademyPlayersMedicalReportFilter((string) ($source['current_medical_report'] ?? $source['medical_report'] ?? 'all')),
    ];
}

function normalizeAcademyPlayersPageNumber($value): int
{
    $normalizedValue = trim(normalizeAcademyPlayersArabicNumbers((string) $value));
    if ($normalizedValue === '' || !ctype_digit($normalizedValue)) {
        return 1;
    }

    return max(1, (int) $normalizedValue);
}

function normalizeAcademyPlayersView(string $value): string
{
    return $value === 'summary' ? 'summary' : 'list';
}

function isAcademyPlayerExpired(?string $endDate, int $availableExercisesCount, string $today): bool
{
    if ($availableExercisesCount <= 0) {
        return true;
    }

    return is_string($endDate) && $endDate !== '' && $endDate < $today;
}

function academyPlayerSubscriptionStatusLabel(bool $isExpired): string
{
    return $isExpired ? 'منتهي' : 'مستمر';
}

function decodeAcademyPlayerSchedule(?string $value): array
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
        if ($dayKey === '' || !isset(ACADEMY_PLAYERS_WEEK_DAYS[$dayKey])) {
            continue;
        }

        $schedule[$dayKey] = [
            'key' => $dayKey,
            'label' => ACADEMY_PLAYERS_WEEK_DAYS[$dayKey],
            'time' => $timeValue,
        ];
    }

    $orderedSchedule = [];
    foreach (ACADEMY_PLAYERS_WEEK_DAYS as $dayKey => $label) {
        if (isset($schedule[$dayKey])) {
            $orderedSchedule[] = $schedule[$dayKey];
        }
    }

    return $orderedSchedule;
}

function buildAcademyPlayerScheduleSummary(array $schedule): string
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

    return implode(' • ', $parts);
}

function formatAcademyPlayerSubscriptionDisplay(array $subscription): string
{
    $subscriptionName = sanitizeAcademyPlayerText((string) ($subscription['subscription_name'] ?? ''));
    return $subscriptionName !== '' ? $subscriptionName : '—';
}

function academyPlayersPhpWeekDayName(DateTimeImmutable $date): string
{
    return strtolower($date->format('l'));
}

function calculateAcademyPlayerEndDate(array $subscription, string $startDate, ?int $availableExercisesCount = null): string
{
    $schedule = decodeAcademyPlayerSchedule((string) ($subscription['training_schedule'] ?? ''));
    $resolvedExercisesCount = $availableExercisesCount;
    if ($resolvedExercisesCount === null) {
        $resolvedExercisesCount = (int) ($subscription['available_exercises_count'] ?? 0);
    }
    $resolvedExercisesCount = max($resolvedExercisesCount, 0);

    if ($schedule === [] || !isValidAcademyPlayerDateInput($startDate)) {
        return '';
    }

    try {
        $currentDate = new DateTimeImmutable($startDate);
    } catch (Throwable $exception) {
        return '';
    }

    if ($resolvedExercisesCount === 0) {
        return $currentDate->format('Y-m-d');
    }

    $scheduleLookup = [];
    foreach ($schedule as $item) {
        $scheduleLookup[(string) ($item['key'] ?? '')] = true;
    }

    $sessionsCount = 0;
    $endDate = $currentDate;

    while ($sessionsCount < $resolvedExercisesCount) {
        if (isset($scheduleLookup[academyPlayersPhpWeekDayName($currentDate)])) {
            $sessionsCount++;
            $endDate = $currentDate;
        }

        if ($sessionsCount >= $resolvedExercisesCount) {
            break;
        }

        $currentDate = $currentDate->modify('+1 day');
    }

    return $endDate->format('Y-m-d');
}

function academyPlayerCategoryRequiresMedical(string $category): bool
{
    return in_array($category, ACADEMY_PLAYERS_MEDICAL_CATEGORIES, true);
}

function academyPlayerCategoryRequiresFederation(string $category): bool
{
    return in_array($category, ACADEMY_PLAYERS_FEDERATION_CATEGORIES, true);
}

function academyPlayerCategoryStarsCount(string $category): int
{
    if (preg_match('/فرق استارات\s+([1-4])\s+نجمة/u', $category, $matches) === 1) {
        return (int) $matches[1];
    }

    return 0;
}

function academyPlayerCategoryAllowsCustomStars(string $category): bool
{
    return $category === ACADEMY_PLAYERS_CUSTOM_STARS_CATEGORY;
}

function academyPlayerCategoryHasStars(string $category): bool
{
    return academyPlayerCategoryAllowsCustomStars($category) || academyPlayerCategoryStarsCount($category) > 0;
}

function academyPlayerIsAllowedCustomStarsCount(int $starsCount): bool
{
    return in_array($starsCount, ACADEMY_PLAYERS_CUSTOM_STARS_OPTIONS, true);
}

function academyPlayerCustomStarsOptionsText(): string
{
    $options = array_map(static fn(int $starsCount): string => (string) $starsCount, ACADEMY_PLAYERS_CUSTOM_STARS_OPTIONS);
    $lastOption = array_pop($options);

    if ($lastOption === null) {
        return '';
    }

    if ($options === []) {
        return $lastOption;
    }

    return implode('، ', $options) . ' و' . $lastOption;
}

function academyPlayerResolveStarsCount(string $category, $storedStarsCount): int
{
    if (academyPlayerCategoryAllowsCustomStars($category)) {
        $resolvedStarsCount = (int) $storedStarsCount;
        return academyPlayerIsAllowedCustomStarsCount($resolvedStarsCount) ? $resolvedStarsCount : 0;
    }

    $fixedStarsCount = academyPlayerCategoryStarsCount($category);
    if ($fixedStarsCount > 0) {
        return $fixedStarsCount;
    }

    $storedStarsCount = (int) $storedStarsCount;
    return in_array($storedStarsCount, ACADEMY_PLAYERS_MANUAL_STARS_OPTIONS, true) ? $storedStarsCount : 0;
}

function academyPlayerStarsText(int $starsCount): string
{
    return $starsCount > 0 ? str_repeat('★', $starsCount) : '—';
}

function academyPlayerStarsOptionLabel(int $starsCount): string
{
    return match ($starsCount) {
        1 => '1 نجمة',
        2 => '2 نجمتان',
        default => $starsCount . ' نجوم',
    };
}

function academyPlayerAllowedStarsOptions(string $category): array
{
    if (academyPlayerCategoryAllowsCustomStars($category)) {
        return ACADEMY_PLAYERS_CUSTOM_STARS_OPTIONS;
    }

    $fixedStarsCount = academyPlayerCategoryStarsCount($category);
    if ($fixedStarsCount > 0) {
        return [$fixedStarsCount];
    }

    return [];
}

function fetchAcademyPlayersSubscriptions(PDO $pdo): array
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
            s.subscription_price,
            c.full_name AS coach_name,
            COALESCE(SUM(CASE WHEN ap.subscription_end_date >= CURDATE() AND ap.available_exercises_count > 0 THEN 1 ELSE 0 END), 0) AS active_players_count
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
            s.subscription_price,
            c.full_name
         ORDER BY s.subscription_name ASC, s.id ASC'
    );

    $subscriptions = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($subscriptions as &$subscription) {
        $schedule = decodeAcademyPlayerSchedule((string) ($subscription['training_schedule'] ?? ''));
        $subscription['schedule_summary'] = buildAcademyPlayerScheduleSummary($schedule);
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

function fetchAcademyPlayerById(PDO $pdo, int $playerId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM academy_players WHERE id = ? LIMIT 1');
    $stmt->execute([$playerId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    return $player ?: null;
}

function fetchAcademyPlayerPayments(PDO $pdo, int $playerId): array
{
    $stmt = $pdo->prepare(
        'SELECT amount, receipt_number, payment_type, created_at
         FROM academy_player_payments
         WHERE player_id = ?
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([$playerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetchAcademyPlayerStatistics(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT
            COUNT(*) AS total_players,
            COALESCE(SUM(CASE WHEN subscription_end_date >= CURDATE() AND available_exercises_count > 0 THEN 1 ELSE 0 END), 0) AS active_players,
            COALESCE(SUM(CASE WHEN subscription_end_date < CURDATE() OR available_exercises_count <= 0 THEN 1 ELSE 0 END), 0) AS expired_players,
            COALESCE(SUM(CASE WHEN remaining_amount > 0 THEN 1 ELSE 0 END), 0) AS players_with_balance,
            COALESCE(SUM(remaining_amount), 0) AS total_remaining,
            COALESCE(SUM(paid_amount), 0) AS total_paid
         FROM academy_players'
    );

    return $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
}

function fetchAcademyPlayersList(PDO $pdo, array $filters): array
{
    [$whereClauses, $params] = academyPlayersBuildFilteredQueryParts($filters);
    return academyPlayersFetchPlayers($pdo, $whereClauses, $params);
}

function academyPlayersBuildFilteredQueryParts(array $filters): array
{
    $whereClauses = [];
    $params = [];

    if ($filters['search'] !== '') {
        $whereClauses[] = '(ap.player_name LIKE ? OR ap.barcode LIKE ? OR ap.phone LIKE ? OR ap.subscription_name LIKE ? OR ap.subscription_category LIKE ? OR ap.subscription_branch LIKE ?)';
        $searchValue = '%' . $filters['search'] . '%';
        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
    }

    if ($filters['subscription_id'] !== '' && ctype_digit($filters['subscription_id'])) {
        $whereClauses[] = 'ap.subscription_id = ?';
        $params[] = (int) $filters['subscription_id'];
    }

    if ($filters['branch'] !== '') {
        $whereClauses[] = 'ap.subscription_branch = ?';
        $params[] = $filters['branch'];
    }

    if ($filters['category'] !== '') {
        $whereClauses[] = 'ap.subscription_category = ?';
        $params[] = $filters['category'];
    }

    if ($filters['status'] === 'active') {
        $whereClauses[] = 'ap.subscription_end_date >= CURDATE() AND ap.available_exercises_count > 0';
    } elseif ($filters['status'] === 'expired') {
        $whereClauses[] = '(ap.subscription_end_date < CURDATE() OR ap.available_exercises_count <= 0)';
    } elseif ($filters['status'] === 'with_balance') {
        $whereClauses[] = 'ap.remaining_amount > 0';
    }

    if ($filters['medical_report'] === 'required_missing') {
        $whereClauses[] = 'ap.medical_report_required = 1 AND ((ap.medical_report_path IS NULL OR ap.medical_report_path = "") AND (ap.medical_report_files IS NULL OR ap.medical_report_files = ""))';
    }

    return [$whereClauses, $params];
}

function academyPlayersFetchPlayers(PDO $pdo, array $whereClauses, array $params, ?int $limit = null, int $offset = 0): array
{
    $sql = 'SELECT
                ap.*,
                (
                    SELECT GROUP_CONCAT(app.receipt_number ORDER BY app.created_at DESC, app.id DESC SEPARATOR " • ")
                    FROM academy_player_payments app
                    WHERE app.player_id = ap.id
                      AND app.payment_type = "settlement"
                      AND app.receipt_number IS NOT NULL
                      AND app.receipt_number <> ""
                ) AS settlement_receipt_numbers
            FROM academy_players ap';
    if ($whereClauses !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
    }
    $sql .= ' ORDER BY ap.subscription_end_date ASC, ap.updated_at DESC, ap.id DESC';

    if ($limit !== null) {
        $sql .= ' LIMIT ? OFFSET ?';
        $params[] = max(1, $limit);
        $params[] = max(0, $offset);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($players as &$player) {
        $player['medical_report_files_list'] = decodeAcademyPlayerMedicalReportFiles(
            $player['medical_report_files'] ?? null,
            $player['medical_report_path'] ?? null
        );
        $player['is_expired'] = isAcademyPlayerExpired(
            (string) ($player['subscription_end_date'] ?? ''),
            (int) ($player['available_exercises_count'] ?? 0),
            date('Y-m-d')
        );
        $player['subscription_status_label'] = academyPlayerSubscriptionStatusLabel($player['is_expired']);
        $player['has_balance'] = (float) ($player['remaining_amount'] ?? 0) > 0;
        $resolvedBirthYear = resolvePlayerBirthYear($player);
        $player['birth_year_display'] = $resolvedBirthYear !== null ? (string) $resolvedBirthYear : '—';
        $player['age_text'] = calculateAcademyPlayerAgeFromYear($resolvedBirthYear);
        $player['stars_count'] = academyPlayerResolveStarsCount(
            (string) ($player['subscription_category'] ?? ''),
            $player['stars_count'] ?? null
        );
        $player['stars_text'] = academyPlayerStarsText((int) $player['stars_count']);
    }
    unset($player);

    return $players;
}

function fetchAcademyPlayersPage(PDO $pdo, array $filters, int $page, int $perPage): array
{
    [$whereClauses, $params] = academyPlayersBuildFilteredQueryParts($filters);
    $offset = max(0, ($page - 1) * $perPage);
    return academyPlayersFetchPlayers($pdo, $whereClauses, $params, $perPage, $offset);
}

function countAcademyPlayers(PDO $pdo, array $filters): int
{
    [$whereClauses, $params] = academyPlayersBuildFilteredQueryParts($filters);
    $sql = 'SELECT COUNT(*) FROM academy_players ap';

    if ($whereClauses !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function renderAcademyPlayersPagination(array $currentFilterParams, int $currentPage, int $totalPages): void
{
    if ($totalPages <= 1) {
        return;
    }

    $pageNumbers = [];
    $pageNumbers[] = 1;
    for ($page = max(2, $currentPage - 2); $page <= min($totalPages - 1, $currentPage + 2); $page++) {
        $pageNumbers[] = $page;
    }
    $pageNumbers[] = $totalPages;
    $pageNumbers = array_values(array_unique($pageNumbers));
    sort($pageNumbers);
    ?>
    <nav class="table-pagination" aria-label="صفحات جدول السباحين">
        <div class="pagination-links">
            <?php if ($currentPage > 1): ?>
                <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl(array_merge($currentFilterParams, ['page' => $currentPage - 1])), ENT_QUOTES, 'UTF-8'); ?>" class="pagination-link pagination-nav">السابق</a>
            <?php endif; ?>

            <?php $lastRenderedPage = 0; ?>
            <?php foreach ($pageNumbers as $pageNumber): ?>
                <?php if ($lastRenderedPage > 0 && $pageNumber - $lastRenderedPage > 1): ?>
                    <span class="pagination-ellipsis">…</span>
                <?php endif; ?>

                <?php if ($pageNumber === $currentPage): ?>
                    <span class="pagination-link is-active" aria-current="page"><?php echo $pageNumber; ?></span>
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl(array_merge($currentFilterParams, ['page' => $pageNumber])), ENT_QUOTES, 'UTF-8'); ?>" class="pagination-link"><?php echo $pageNumber; ?></a>
                <?php endif; ?>

                <?php $lastRenderedPage = $pageNumber; ?>
            <?php endforeach; ?>

            <?php if ($currentPage < $totalPages): ?>
                <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl(array_merge($currentFilterParams, ['page' => $currentPage + 1])), ENT_QUOTES, 'UTF-8'); ?>" class="pagination-link pagination-nav">التالي</a>
            <?php endif; ?>
        </div>
    </nav>
    <?php
}

function fetchAcademyPlayersCategories(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT DISTINCT subscription_category FROM academy_players WHERE subscription_category IS NOT NULL AND subscription_category <> "" ORDER BY subscription_category ASC');
    $categories = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    return array_values(array_filter(array_map(static fn($value): string => sanitizeAcademyPlayerText((string) $value), $categories)));
}

function fetchAcademyPlayersBranches(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT DISTINCT subscription_branch FROM academy_players WHERE subscription_branch IS NOT NULL AND subscription_branch <> "" ORDER BY subscription_branch ASC');
    $branches = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    return array_values(array_filter(array_map(static fn($value): string => sanitizeAcademyPlayerText((string) $value), $branches)));
}

function buildAcademyPlayersSubscriptionSummary(array $subscriptions, string $category = ''): array
{
    $normalizedCategory = sanitizeAcademyPlayerText($category);
    $availableGroups = [];
    $availableGroupsCount = 0;
    $fullGroupsCount = 0;

    foreach ($subscriptions as $subscription) {
        $subscriptionCategory = sanitizeAcademyPlayerText((string) ($subscription['subscription_category'] ?? ''));
        if ($normalizedCategory !== '' && $subscriptionCategory !== $normalizedCategory) {
            continue;
        }

        $maxTrainees = (int) ($subscription['max_trainees'] ?? 0);
        if ($maxTrainees <= 0) {
            continue;
        }

        $activePlayersCount = (int) ($subscription['active_players_count'] ?? 0);
        if ($activePlayersCount >= $maxTrainees) {
            $fullGroupsCount++;
            continue;
        }

        $availableGroupsCount++;
        $availableGroups[] = [
            'subscription_name' => (string) ($subscription['subscription_name'] ?? ''),
            'coach_name' => (string) ($subscription['coach_name'] ?? ''),
            'subscription_branch' => (string) ($subscription['subscription_branch'] ?? ''),
            'active_players_count' => $activePlayersCount,
            'max_trainees' => $maxTrainees,
            'remaining_slots' => $maxTrainees - $activePlayersCount,
        ];
    }

    return [
        'available_groups_count' => $availableGroupsCount,
        'full_groups_count' => $fullGroupsCount,
        'available_groups' => $availableGroups,
    ];
}

function renderAcademyPlayersHorizontalToolbar(
    array $filters,
    array $subscriptions,
    array $branchOptions,
    array $categoryOptions,
    string $summaryCategory,
    array $currentFilterParams,
    string $toolbarKey,
    string $summaryPageUrl
): void {
    $searchId = 'search_' . $toolbarKey;
    $subscriptionId = 'subscription_filter_' . $toolbarKey;
    $branchId = 'branch_filter_' . $toolbarKey;
    $categoryId = 'category_filter_' . $toolbarKey;
    $statusId = 'status_filter_' . $toolbarKey;
    $medicalReportId = 'medical_report_filter_' . $toolbarKey;
    $exportUrl = buildAcademyPlayersPageUrl(array_merge($currentFilterParams, ['page' => null, 'export' => 'xlsx']));
    ?>
    <section class="toolbar-card">
        <div class="toolbar-row">
            <div class="toolbar-actions">
                <button
                    type="button"
                    class="save-btn desktop-player-launcher"
                    data-open-player-modal
                    aria-label="إضافة سباح"
                    aria-haspopup="dialog"
                    aria-controls="playerFormModal"
                    aria-expanded="false"
                >
                    إضافة سباح
                </button>
                <a href="<?php echo htmlspecialchars($summaryPageUrl, ENT_QUOTES, 'UTF-8'); ?>" class="link-btn files-btn">ملخص المجموعات</a>
                <a href="<?php echo htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8'); ?>" class="link-btn export-btn">استخراج إكسل</a>
            </div>
            <form method="GET" class="filter-form filter-form-horizontal" autocomplete="off">
                <input type="hidden" name="summary_category" value="<?php echo htmlspecialchars($summaryCategory, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group toolbar-field-search">
                    <label for="<?php echo htmlspecialchars($searchId, ENT_QUOTES, 'UTF-8'); ?>">البحث</label>
                    <input type="text" name="search" id="<?php echo htmlspecialchars($searchId, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group subscription-select-group toolbar-field-wide">
                    <label for="<?php echo htmlspecialchars($subscriptionId, ENT_QUOTES, 'UTF-8'); ?>">المجموعة</label>
                    <select name="subscription_id" id="<?php echo htmlspecialchars($subscriptionId, ENT_QUOTES, 'UTF-8'); ?>">
                        <option value="">كل المجموعات</option>
                        <?php foreach ($subscriptions as $subscription): ?>
                            <?php $subscriptionDisplayText = formatAcademyPlayerSubscriptionDisplay($subscription); ?>
                            <option value="<?php echo (int) ($subscription['id'] ?? 0); ?>" <?php echo (string) ($subscription['id'] ?? '') === $filters['subscription_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subscriptionDisplayText, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="<?php echo htmlspecialchars($branchId, ENT_QUOTES, 'UTF-8'); ?>">الفرع</label>
                    <select name="branch" id="<?php echo htmlspecialchars($branchId, ENT_QUOTES, 'UTF-8'); ?>">
                        <option value="">كل الفروع</option>
                        <?php foreach ($branchOptions as $branchOption): ?>
                            <option value="<?php echo htmlspecialchars($branchOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filters['branch'] === $branchOption ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($branchOption, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="<?php echo htmlspecialchars($categoryId, ENT_QUOTES, 'UTF-8'); ?>">مستوى المجموعة</label>
                    <select name="category" id="<?php echo htmlspecialchars($categoryId, ENT_QUOTES, 'UTF-8'); ?>">
                        <option value="">كل المستويات</option>
                        <?php foreach ($categoryOptions as $categoryOption): ?>
                            <option value="<?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filters['category'] === $categoryOption ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="<?php echo htmlspecialchars($statusId, ENT_QUOTES, 'UTF-8'); ?>">الحالة</label>
                    <select name="status" id="<?php echo htmlspecialchars($statusId, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php foreach (ACADEMY_PLAYER_STATUS_FILTERS as $statusKey => $statusLabel): ?>
                            <option value="<?php echo htmlspecialchars($statusKey === 'all' ? '' : $statusKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filters['status'] === $statusKey ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="<?php echo htmlspecialchars($medicalReportId, ENT_QUOTES, 'UTF-8'); ?>">التقرير الطبي</label>
                    <select name="medical_report" id="<?php echo htmlspecialchars($medicalReportId, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php foreach (ACADEMY_PLAYER_MEDICAL_REPORT_FILTERS as $medicalReportKey => $medicalReportLabel): ?>
                            <option value="<?php echo htmlspecialchars($medicalReportKey === 'all' ? '' : $medicalReportKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filters['medical_report'] === $medicalReportKey ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($medicalReportLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions compact-actions toolbar-form-actions">
                    <button type="submit" class="save-btn">عرض</button>
                    <a href="academy_players.php" class="clear-btn link-btn">إعادة ضبط</a>
                </div>
            </form>
        </div>
    </section>
    <?php
}

function canAcademyPlayersUserManageDiscount(array $user): bool
{
    return in_array(($user['role'] ?? ''), ['مدير', 'مشرف'], true);
}

function countAcademyPlayersForSubscription(PDO $pdo, int $subscriptionId, ?int $excludePlayerId = null): int
{
    $sql = 'SELECT COUNT(*) FROM academy_players WHERE subscription_id = ? AND subscription_end_date >= CURDATE() AND available_exercises_count > 0';
    $params = [$subscriptionId];

    if ($excludePlayerId !== null && $excludePlayerId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludePlayerId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function academyPlayersFindDuplicateBarcode(PDO $pdo, string $barcode, ?int $excludePlayerId = null): ?array
{
    if ($barcode === '') {
        return null;
    }

    $sql = 'SELECT id, player_name FROM academy_players WHERE barcode = ?';
    $params = [$barcode];
    if ($excludePlayerId !== null && $excludePlayerId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludePlayerId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    return $player ?: null;
}

function academyPlayersNormalizeBoolean($value): bool
{
    return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
}

function normalizeAcademyPlayerUploadPublicPath($uploadPath): ?string
{
    if (!is_string($uploadPath)) {
        return null;
    }

    $normalizedPath = trim($uploadPath);
    if (strpos($normalizedPath, ACADEMY_PLAYERS_UPLOAD_PUBLIC_DIR . '/') !== 0) {
        return null;
    }

    $fileName = basename($normalizedPath);
    if ($fileName === '' || $fileName === '.' || $fileName === '..') {
        return null;
    }

    if ($normalizedPath !== ACADEMY_PLAYERS_UPLOAD_PUBLIC_DIR . '/' . $fileName) {
        return null;
    }

    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*\.[A-Za-z0-9]+$/', $fileName) !== 1) {
        return null;
    }

    return ACADEMY_PLAYERS_UPLOAD_PUBLIC_DIR . '/' . $fileName;
}

function ensureAcademyPlayersUploadDirectoryExists(string $directory): bool
{
    if (is_dir($directory)) {
        return true;
    }

    return mkdir($directory, 0755, true) || is_dir($directory);
}

function generateAcademyPlayerImageToken(): ?string
{
    try {
        return bin2hex(random_bytes(12));
    } catch (Throwable $exception) {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $isStrong = false;
            $randomBytes = openssl_random_pseudo_bytes(12, $isStrong);
            if ($randomBytes !== false && $isStrong) {
                return bin2hex($randomBytes);
            }
        }
    }

    return null;
}

function detectAcademyPlayerImageExtension(string $originalName, string $mimeType): string
{
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $extension = preg_replace('/[^a-z0-9]+/i', '', $extension);
    if ($extension !== '') {
        return $extension;
    }

    $mimeParts = explode('/', strtolower($mimeType), 2);
    $mimeSubtype = $mimeParts[1] ?? 'img';
    $mimeSubtype = str_replace(['svg+xml', 'x-icon', 'vnd.microsoft.icon'], ['svg', 'ico', 'ico'], $mimeSubtype);
    $mimeSubtype = preg_replace('/[^a-z0-9]+/i', '', $mimeSubtype);

    return $mimeSubtype !== '' ? $mimeSubtype : 'img';
}

function uploadAcademyPlayerImage(array $file, string $kind): array
{
    $emptyResult = [
        'path' => null,
        'attempted' => false,
        'error' => false,
    ];

    $originalName = trim((string) ($file['name'] ?? ''));
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($originalName === '' || $uploadError === UPLOAD_ERR_NO_FILE) {
        return $emptyResult;
    }

    if ($uploadError !== UPLOAD_ERR_OK) {
        return [
            'path' => null,
            'attempted' => true,
            'error' => true,
        ];
    }

    if (!ensureAcademyPlayersUploadDirectoryExists(ACADEMY_PLAYERS_UPLOAD_DIR)) {
        return [
            'path' => null,
            'attempted' => true,
            'error' => true,
        ];
    }

    $temporaryPath = $file['tmp_name'] ?? '';
    if (!is_string($temporaryPath) || $temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        return [
            'path' => null,
            'attempted' => true,
            'error' => true,
        ];
    }

    $mimeType = '';
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    if ($finfo !== false) {
        $detectedMimeType = finfo_file($finfo, $temporaryPath);
        if (is_string($detectedMimeType)) {
            $mimeType = $detectedMimeType;
        }
        finfo_close($finfo);
    } elseif (function_exists('mime_content_type')) {
        $detectedMimeType = mime_content_type($temporaryPath);
        if (is_string($detectedMimeType)) {
            $mimeType = $detectedMimeType;
        }
    }

    if ($mimeType === '' || strpos($mimeType, 'image/') !== 0) {
        return [
            'path' => null,
            'attempted' => true,
            'error' => true,
        ];
    }

    $token = generateAcademyPlayerImageToken();
    if ($token === null) {
        return [
            'path' => null,
            'attempted' => true,
            'error' => true,
        ];
    }

    $extension = detectAcademyPlayerImageExtension($originalName, $mimeType);
    $fileName = sprintf('academy-player-%s-%s.%s', preg_replace('/[^a-z0-9_-]+/i', '-', $kind), $token, $extension);
    $destinationPath = ACADEMY_PLAYERS_UPLOAD_DIR . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($temporaryPath, $destinationPath)) {
        return [
            'path' => null,
            'attempted' => true,
            'error' => true,
        ];
    }

    return [
        'path' => ACADEMY_PLAYERS_UPLOAD_PUBLIC_DIR . '/' . $fileName,
        'attempted' => true,
        'error' => false,
    ];
}

function resolveAcademyPlayerImageAbsolutePath(?string $imagePath): ?string
{
    $normalizedPath = normalizeAcademyPlayerUploadPublicPath($imagePath);
    if ($normalizedPath === null) {
        return null;
    }

    $uploadsDirectory = realpath(ACADEMY_PLAYERS_UPLOAD_DIR);
    if ($uploadsDirectory === false) {
        return null;
    }

    $absolutePath = $uploadsDirectory . DIRECTORY_SEPARATOR . basename($normalizedPath);
    $resolvedDirectory = realpath(dirname($absolutePath));
    if ($resolvedDirectory === false || $resolvedDirectory !== $uploadsDirectory) {
        return null;
    }

    return $absolutePath;
}

function deleteAcademyPlayerImage(?string $imagePath): bool
{
    $absolutePath = resolveAcademyPlayerImageAbsolutePath($imagePath);
    if ($absolutePath === null || !is_file($absolutePath)) {
        return true;
    }

    return unlink($absolutePath);
}

function decodeAcademyPlayerMedicalReportFiles($storedFiles, $fallbackPath = null): array
{
    $paths = [];

    if (is_string($storedFiles) && trim($storedFiles) !== '') {
        $decodedValue = json_decode($storedFiles, true);
        if (is_array($decodedValue)) {
            foreach ($decodedValue as $path) {
                $normalizedPath = normalizeAcademyPlayerUploadPublicPath($path);
                if ($normalizedPath !== null) {
                    $paths[] = $normalizedPath;
                }
            }
        }
    }

    $fallbackNormalizedPath = normalizeAcademyPlayerUploadPublicPath($fallbackPath);
    if ($fallbackNormalizedPath !== null) {
        array_unshift($paths, $fallbackNormalizedPath);
    }

    return array_values(array_unique($paths));
}

function encodeAcademyPlayerMedicalReportFiles(array $paths): ?string
{
    $normalizedPaths = [];
    foreach ($paths as $path) {
        $normalizedPath = normalizeAcademyPlayerUploadPublicPath($path);
        if ($normalizedPath !== null) {
            $normalizedPaths[] = $normalizedPath;
        }
    }

    $normalizedPaths = array_values(array_unique($normalizedPaths));
    if ($normalizedPaths === []) {
        return null;
    }

    $encodedValue = json_encode($normalizedPaths, JSON_UNESCAPED_UNICODE);
    return is_string($encodedValue) ? $encodedValue : null;
}

function uploadAcademyPlayerImages(array $files, string $kind): array
{
    $uploadedPaths = [];

    if (
        !isset($files['name']) || !is_array($files['name'])
        || !isset($files['tmp_name']) || !is_array($files['tmp_name'])
        || !isset($files['error']) || !is_array($files['error'])
    ) {
        return ['paths' => [], 'attempted' => false, 'error' => false];
    }

    $totalFiles = count($files['name']);
    $attempted = false;

    for ($index = 0; $index < $totalFiles; $index++) {
        $singleFile = [
            'name' => $files['name'][$index] ?? '',
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];

        $uploadResult = uploadAcademyPlayerImage($singleFile, $kind);
        $attempted = $attempted || !empty($uploadResult['attempted']);

        if (!empty($uploadResult['error'])) {
            foreach ($uploadedPaths as $uploadedPath) {
                deleteAcademyPlayerImage($uploadedPath);
            }

            return ['paths' => [], 'attempted' => true, 'error' => true];
        }

        if (!empty($uploadResult['path'])) {
            $uploadedPaths[] = (string) $uploadResult['path'];
        }
    }

    return [
        'paths' => $uploadedPaths,
        'attempted' => $attempted,
        'error' => false,
    ];
}

function deleteAcademyPlayerImages(array $paths): void
{
    foreach ($paths as $path) {
        deleteAcademyPlayerImage(is_string($path) ? $path : null);
    }
}

function academyPlayersXmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function academyPlayersExcelColumnName(int $index): string
{
    $columnName = '';
    $index++;

    while ($index > 0) {
        $modulo = ($index - 1) % 26;
        $columnName = chr(65 + $modulo) . $columnName;
        $index = (int) floor(($index - $modulo) / 26);
    }

    return $columnName;
}

function createAcademyPlayersXlsx(array $headers, array $rows): string
{
    $sheetRows = array_merge([$headers], $rows);
    $sheetXmlRows = [];

    foreach ($sheetRows as $rowIndex => $row) {
        $cellsXml = [];
        foreach (array_values($row) as $columnIndex => $value) {
            $cellReference = academyPlayersExcelColumnName($columnIndex) . ($rowIndex + 1);
            $cellsXml[] = sprintf(
                '<c r="%s" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
                $cellReference,
                academyPlayersXmlEscape((string) $value)
            );
        }

        $sheetXmlRows[] = sprintf('<row r="%d">%s</row>', $rowIndex + 1, implode('', $cellsXml));
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<sheetData>' . implode('', $sheetXmlRows) . '</sheetData>'
        . '</worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="السباحين" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Tahoma"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $createdAt = gmdate('Y-m-d\TH:i:s\Z');
    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>السباحين</dc:title>'
        . '<dc:creator>ghareb-swimming-academy</dc:creator>'
        . '<cp:lastModifiedBy>ghareb-swimming-academy</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>Microsoft Excel</Application>'
        . '</Properties>';

    $temporaryFile = tempnam(sys_get_temp_dir(), 'academy_players_xlsx_');
    if ($temporaryFile === false) {
        throw new RuntimeException('تعذر إنشاء ملف التصدير');
    }

    $zip = new ZipArchive();
    if ($zip->open($temporaryFile, ZipArchive::OVERWRITE) !== true) {
        @unlink($temporaryFile);
        throw new RuntimeException('تعذر إنشاء ملف الإكسل');
    }

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $rootRelsXml);
    $zip->addFromString('docProps/core.xml', $coreXml);
    $zip->addFromString('docProps/app.xml', $appXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    return $temporaryFile;
}

function exportAcademyPlayersAsXlsx(array $players): void
{
    $rows = [];

    foreach ($players as $player) {
        $rows[] = [
            (string) ($player['barcode'] ?? ''),
            (string) ($player['player_name'] ?? ''),
            (string) ($player['phone'] ?? ''),
            (string) ($player['guardian_phone'] ?? ''),
            (string) (resolvePlayerBirthYear($player) ?? ''),
            calculateAcademyPlayerAgeFromYear(resolvePlayerBirthYear($player)),
            (string) ($player['subscription_name'] ?? ''),
            (string) ($player['subscription_category'] ?? ''),
            (string) ($player['subscription_branch'] ?? ''),
            (string) ($player['subscription_coach_name'] ?? ''),
            formatAcademyTrainingSchedule((string) ($player['subscription_training_schedule'] ?? '')),
            (string) ((int) ($player['available_exercises_count'] ?? 0)),
            formatAcademyPlayerDate((string) ($player['subscription_start_date'] ?? '')),
            formatAcademyPlayerDate((string) ($player['subscription_end_date'] ?? '')),
            formatAcademyPlayerMoney($player['subscription_base_price'] ?? 0),
            formatAcademyPlayerMoney($player['additional_discount'] ?? 0),
            formatAcademyPlayerMoney($player['subscription_amount'] ?? 0),
            formatAcademyPlayerMoney($player['paid_amount'] ?? 0),
            formatAcademyPlayerMoney($player['remaining_amount'] ?? 0),
            (string) ($player['receipt_number'] ?? ''),
            !empty($player['medical_report_required']) ? 'نعم' : 'لا',
            !empty($player['federation_card_required']) ? 'نعم' : 'لا',
            academyPlayerStarsText(academyPlayerResolveStarsCount(
                (string) ($player['subscription_category'] ?? ''),
                $player['stars_count'] ?? null
            )),
            formatAcademyPlayerDate((string) ($player['last_star_date'] ?? '')),
        ];
    }

    $temporaryFile = createAcademyPlayersXlsx(ACADEMY_PLAYERS_EXPORT_HEADERS, $rows);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="academy-players-' . date('Y-m-d') . '.xlsx"');
    header('Content-Length: ' . (string) filesize($temporaryFile));
    header('Cache-Control: max-age=0');

    readfile($temporaryFile);
    @unlink($temporaryFile);
    exit;
}

function academyPlayersAllowedFileColumns(): array
{
    return [
        'player_image_path' => 'player_image_path',
        'birth_certificate_path' => 'birth_certificate_path',
        'medical_report_path' => 'medical_report_path',
        'federation_card_path' => 'federation_card_path',
    ];
}

function academyPlayersPrepareFileUpdateStatement(PDO $pdo, string $fileColumn, bool $setNull): PDOStatement
{
    return match ($fileColumn) {
        'player_image_path' => $pdo->prepare(
            $setNull
                ? 'UPDATE academy_players SET player_image_path = NULL WHERE id = ?'
                : 'UPDATE academy_players SET player_image_path = ? WHERE id = ?'
        ),
        'birth_certificate_path' => $pdo->prepare(
            $setNull
                ? 'UPDATE academy_players SET birth_certificate_path = NULL WHERE id = ?'
                : 'UPDATE academy_players SET birth_certificate_path = ? WHERE id = ?'
        ),
        'medical_report_path' => $pdo->prepare(
            $setNull
                ? 'UPDATE academy_players SET medical_report_path = NULL WHERE id = ?'
                : 'UPDATE academy_players SET medical_report_path = ? WHERE id = ?'
        ),
        'federation_card_path' => $pdo->prepare(
            $setNull
                ? 'UPDATE academy_players SET federation_card_path = NULL WHERE id = ?'
                : 'UPDATE academy_players SET federation_card_path = ? WHERE id = ?'
        ),
        default => throw new InvalidArgumentException('حقل ملف غير مدعوم'),
    };
}

$flashMessage = consumeAcademyPlayersFlash();
$message = $flashMessage['message'];
$messageType = $flashMessage['type'];
$canManageDiscount = canAcademyPlayersUserManageDiscount($currentUser);
$subscriptions = fetchAcademyPlayersSubscriptions($pdo);
$subscriptionsById = [];
foreach ($subscriptions as $subscription) {
    $subscriptionsById[(int) $subscription['id']] = $subscription;
}

$filters = academyPlayersFilterParamsFromArray($_GET);
$currentView = normalizeAcademyPlayersView((string) ($_GET['view'] ?? ''));
$requestedPlayersPage = normalizeAcademyPlayersPageNumber($_GET['page'] ?? 1);

if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    $playersForExport = fetchAcademyPlayersList($pdo, $filters);
    exportAcademyPlayersAsXlsx($playersForExport);
}

$editPlayer = null;
$paymentPlayer = null;
$filesPlayer = null;
$starsPlayer = null;
$passwordPlayer = null;
$playerPayments = [];
$submittedPlayerFormData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $redirectFilters = academyPlayersFilterParamsFromArray($_POST);
    $redirectView = normalizeAcademyPlayersView((string) ($_POST['current_view'] ?? ''));
    $redirectPage = normalizeAcademyPlayersPageNumber($_POST['current_page'] ?? 1);
    $redirectPageParams = array_merge($redirectFilters, [
        'page' => $redirectPage > 1 ? (string) $redirectPage : null,
        'view' => $redirectView === 'summary' ? 'summary' : '',
    ]);

    if ($action !== '' && !isValidAcademyPlayersCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = '❌ انتهت صلاحية الطلب، يرجى إعادة المحاولة.';
        $messageType = 'error';
    } elseif ($action === 'save') {
        $id = trim((string) ($_POST['id'] ?? ''));
        $playerId = ctype_digit($id) ? (int) $id : 0;
        $existingPlayer = $playerId > 0 ? fetchAcademyPlayerById($pdo, $playerId) : null;
        $existingPlayerResolvedStarsCount = $existingPlayer === null
            ? 0
            : academyPlayerResolveStarsCount((string) ($existingPlayer['subscription_category'] ?? ''), $existingPlayer['stars_count'] ?? null);
        $existingPlayerLastStarDate = (string) ($existingPlayer['last_star_date'] ?? '');
        $existingPlayerAvailableExercisesCount = $existingPlayer === null
            ? null
            : max((int) ($existingPlayer['available_exercises_count'] ?? 0), 0);
        $subscriptionId = (int) trim((string) ($_POST['subscription_id'] ?? '0'));
        $subscription = $subscriptionsById[$subscriptionId] ?? null;
        $subscriptionBranchFromDb = sanitizeAcademyPlayerText((string) ($subscription['subscription_branch'] ?? ''));
        $barcode = sanitizeAcademyPlayerIdentifier((string) ($_POST['barcode'] ?? ''));
        $playerName = sanitizeAcademyPlayerText((string) ($_POST['player_name'] ?? ''));
        $playerPhone = sanitizeAcademyPlayerText((string) ($_POST['phone'] ?? ''));
        $guardianPhone = sanitizeAcademyPlayerPhone((string) ($_POST['guardian_phone'] ?? ''));
        $subscriptionBranch = sanitizeAcademyPlayerText((string) ($_POST['subscription_branch'] ?? ''));
        if ($subscriptionBranch === '' && $subscription !== null) {
            $subscriptionBranch = $subscriptionBranchFromDb;
        }
        $birthYearInput = trim((string) ($_POST['birth_year'] ?? ''));
        $birthYear = (ctype_digit($birthYearInput) && strlen($birthYearInput) === 4) ? (int) $birthYearInput : null;
        $birthDate = $birthYear !== null ? ($birthYear . '-01-01') : '';
        $subscriptionStartDateInput = trim((string) ($_POST['subscription_start_date'] ?? ''));
        $subscriptionBasePriceInput = normalizeAcademyPlayerDecimal((string) ($_POST['subscription_base_price'] ?? '0'));
        $paidAmountInput = normalizeAcademyPlayerDecimal((string) ($_POST['paid_amount'] ?? '0'));
        $additionalDiscountInput = normalizeAcademyPlayerDecimal((string) ($_POST['additional_discount'] ?? '0'));
        $receiptNumber = sanitizeAcademyPlayerText((string) ($_POST['receipt_number'] ?? ''));
        $allRequiredDocuments = academyPlayersNormalizeBoolean($_POST['all_required_documents'] ?? '0');
        $birthCertificateRequired = academyPlayersNormalizeBoolean($_POST['birth_certificate_required'] ?? '0');
        $medicalReportRequired = academyPlayersNormalizeBoolean($_POST['medical_report_required'] ?? '0');
        $federationCardRequired = academyPlayersNormalizeBoolean($_POST['federation_card_required'] ?? '0');
        if ($allRequiredDocuments) {
            $birthCertificateRequired = true;
            $medicalReportRequired = true;
            $federationCardRequired = true;
        }
        $submittedStarsCountInput = trim((string) ($_POST['stars_count'] ?? ''));
        $submittedStarsCount = ctype_digit($submittedStarsCountInput) ? (int) $submittedStarsCountInput : 0;
        $lastStarDate = trim((string) ($_POST['last_star_date'] ?? ''));
        $defaultBasePriceValue = $subscription !== null
            ? formatAcademyPlayerAmount($subscription['subscription_price'] ?? 0)
            : formatAcademyPlayerAmount($existingPlayer['subscription_base_price'] ?? 0);
        if ($subscriptionBasePriceInput === '') {
            $subscriptionBasePriceInput = $defaultBasePriceValue;
        }
        $resolvedAvailableExercisesCount = $existingPlayerAvailableExercisesCount;
        if ($resolvedAvailableExercisesCount === null) {
            $resolvedAvailableExercisesCount = max((int) ($subscription['available_exercises_count'] ?? 0), 0);
        }

        $submittedPlayerFormData = [
            'id' => $id,
            'barcode' => $barcode,
            'player_name' => $playerName,
            'phone' => $playerPhone,
            'guardian_phone' => $guardianPhone,
            'subscription_branch' => $subscriptionBranch,
            'birth_year' => $birthYear !== null ? (string) $birthYear : '',
            'subscription_id' => $subscriptionId > 0 ? (string) $subscriptionId : '',
            'subscription_category' => (string) ($subscription['subscription_category'] ?? ($existingPlayer['subscription_category'] ?? '')),
            'subscription_coach_name' => (string) ($subscription['coach_name'] ?? ($existingPlayer['subscription_coach_name'] ?? '')),
            'subscription_training_schedule' => (string) ($subscription['schedule_summary'] ?? ($existingPlayer['subscription_training_schedule'] ?? '')),
            'available_exercises_count' => (string) $resolvedAvailableExercisesCount,
            'max_trainees' => (string) ($subscription['max_trainees'] ?? ($existingPlayer['max_trainees'] ?? '')),
            'current_players_count' => (string) ($subscription['active_players_count'] ?? '0'),
            'subscription_base_price' => $subscriptionBasePriceInput,
            'additional_discount' => $additionalDiscountInput === '' ? '0.00' : $additionalDiscountInput,
            'subscription_amount' => formatAcademyPlayerAmount(0),
            'paid_amount' => $paidAmountInput === '' ? '0.00' : $paidAmountInput,
            'remaining_amount' => formatAcademyPlayerAmount(0),
            'receipt_number' => $receiptNumber,
            'all_required_documents' => $allRequiredDocuments,
            'birth_certificate_required' => $birthCertificateRequired,
            'medical_report_required' => $medicalReportRequired,
            'federation_card_required' => $federationCardRequired,
            'stars_count' => $submittedStarsCount > 0 ? (string) $submittedStarsCount : '',
            'last_star_date' => $lastStarDate,
            'subscription_start_date' => $subscriptionStartDateInput,
            'subscription_end_date' => '',
            'player_image_path' => (string) ($existingPlayer['player_image_path'] ?? ''),
            'birth_certificate_path' => (string) ($existingPlayer['birth_certificate_path'] ?? ''),
            'medical_report_path' => (string) ($existingPlayer['medical_report_path'] ?? ''),
            'federation_card_path' => (string) ($existingPlayer['federation_card_path'] ?? ''),
        ];

        if ($subscription === null) {
            $message = '❌ اختر مجموعة صحيحة.';
            $messageType = 'error';
        } elseif ($subscriptionBranch !== '' && $subscriptionBranch !== $subscriptionBranchFromDb) {
            $message = '❌ المجموعة المختارة يجب أن تكون من الفرع: ' . ($subscriptionBranchFromDb !== '' ? $subscriptionBranchFromDb : '—') . '.';
            $messageType = 'error';
        } elseif ($barcode === '') {
            $message = '❌ أدخل باركود السباح.';
            $messageType = 'error';
        } elseif ($playerName === '') {
            $message = '❌ أدخل اسم السباح.';
            $messageType = 'error';
        } elseif ($subscriptionBranch === '') {
            $message = '❌ أدخل الفرع.';
            $messageType = 'error';
        } elseif ($birthYear === null || $birthYear < 1950 || $birthYear > (int) date('Y')) {
            $message = '❌ أدخل سنة ميلاد صحيحة (مثال: 2010).';
            $messageType = 'error';
        } elseif (!isValidAcademyPlayerDateInput($subscriptionStartDateInput)) {
            $message = '❌ اختر تاريخ بداية اشتراك صحيحًا.';
            $messageType = 'error';
        } elseif (!isValidAcademyPlayerDecimal($subscriptionBasePriceInput === '' ? '0' : $subscriptionBasePriceInput, true)) {
            $message = '❌ أدخل سعرًا صحيحًا.';
            $messageType = 'error';
        } elseif ($lastStarDate !== '' && !isValidAcademyPlayerDateInput($lastStarDate)) {
            $message = '❌ اختر تاريخ آخر نجمة بشكل صحيح.';
            $messageType = 'error';
        } elseif (!isValidAcademyPlayerDecimal($paidAmountInput, true)) {
            $message = '❌ أدخل مبلغًا صحيحًا للمدفوع.';
            $messageType = 'error';
        } elseif (!$canManageDiscount && $additionalDiscountInput !== '' && (float) $additionalDiscountInput > 0) {
            $message = '❌ لا يمكن تعديل خصم الإدارة من هذا الحساب.';
            $messageType = 'error';
        } elseif (!isValidAcademyPlayerDecimal($additionalDiscountInput === '' ? '0' : $additionalDiscountInput, true)) {
            $message = '❌ أدخل خصم إدارة صحيحًا.';
            $messageType = 'error';
        } else {
            $duplicateBarcode = academyPlayersFindDuplicateBarcode($pdo, $barcode, $playerId > 0 ? $playerId : null);
            if ($duplicateBarcode !== null) {
                $message = '❌ هذا الباركود مسجل بالفعل.';
                $messageType = 'error';
            }
        }

        if ($message === '') {
            $basePrice = (float) ($subscriptionBasePriceInput === '' ? '0' : $subscriptionBasePriceInput);
            $additionalDiscount = $canManageDiscount ? (float) ($additionalDiscountInput === '' ? '0' : $additionalDiscountInput) : 0.0;
            $subscriptionAmount = max($basePrice - $additionalDiscount, 0);
            $paidAmount = (float) $paidAmountInput;
            $remainingAmount = max($subscriptionAmount - $paidAmount, 0);
            $subscriptionCategory = (string) ($subscription['subscription_category'] ?? '');
            $fixedStarsCount = academyPlayerCategoryStarsCount($subscriptionCategory);
            $allowsCustomStars = academyPlayerCategoryAllowsCustomStars($subscriptionCategory);
            if ($allowsCustomStars) {
                $starsCount = $submittedStarsCount;
            } elseif ($fixedStarsCount > 0) {
                $starsCount = $fixedStarsCount;
            } else {
                $starsCount = $existingPlayerResolvedStarsCount;
                if ($starsCount > 0 && $lastStarDate === '') {
                    $lastStarDate = $existingPlayerLastStarDate;
                }
            }
            $subscriptionEndDate = calculateAcademyPlayerEndDate($subscription, $subscriptionStartDateInput, $resolvedAvailableExercisesCount);
            $submittedPlayerFormData['subscription_base_price'] = formatAcademyPlayerAmount($basePrice);
            $submittedPlayerFormData['subscription_amount'] = formatAcademyPlayerAmount($subscriptionAmount);
            $submittedPlayerFormData['remaining_amount'] = formatAcademyPlayerAmount($remainingAmount);
            $submittedPlayerFormData['subscription_end_date'] = $subscriptionEndDate;
            $submittedPlayerFormData['all_required_documents'] = $birthCertificateRequired && $medicalReportRequired && $federationCardRequired;
            $submittedPlayerFormData['birth_certificate_required'] = $birthCertificateRequired;
            $submittedPlayerFormData['medical_report_required'] = $medicalReportRequired;
            $submittedPlayerFormData['federation_card_required'] = $federationCardRequired;
            $submittedPlayerFormData['stars_count'] = $starsCount > 0 ? (string) $starsCount : '';
            $submittedPlayerFormData['last_star_date'] = $starsCount > 0 ? $lastStarDate : '';

            if ($additionalDiscount > $basePrice) {
                $message = '❌ خصم الإدارة لا يمكن أن يكون أكبر من السعر.';
                $messageType = 'error';
            } elseif ($paidAmount > $subscriptionAmount) {
                $message = '❌ المدفوع لا يمكن أن يكون أكبر من الإجمالي.';
                $messageType = 'error';
            } elseif ($subscriptionEndDate === '') {
                $message = '❌ لا يمكن احتساب نهاية الاشتراك.';
                $messageType = 'error';
            } elseif ($paidAmount > 0 && $receiptNumber === '') {
                $message = '❌ أدخل رقم الإيصال.';
                $messageType = 'error';
            } elseif ($allowsCustomStars && !academyPlayerIsAllowedCustomStarsCount($submittedStarsCount)) {
                $message = '❌ اختر عدد النجوم من ' . academyPlayerCustomStarsOptionsText() . '.';
                $messageType = 'error';
            } elseif ($starsCount === 0) {
                $submittedPlayerFormData['stars_count'] = '';
                $lastStarDate = '';
                $submittedPlayerFormData['last_star_date'] = '';
            }

            if ($message === '') {
                $currentPlayersCount = countAcademyPlayersForSubscription($pdo, $subscriptionId, $playerId > 0 ? $playerId : null);
                $submittedPlayerFormData['current_players_count'] = (string) $currentPlayersCount;
                if ($existingPlayer === null && $currentPlayersCount >= (int) ($subscription['max_trainees'] ?? 0)) {
                    $message = '❌ تم الوصول إلى الحد الأقصى لهذه المجموعة.';
                    $messageType = 'error';
                }
            }

            $playerImageUpload = ['path' => null, 'attempted' => false, 'error' => false];
            $birthCertificateUpload = ['path' => null, 'attempted' => false, 'error' => false];
            $medicalReportUpload = ['paths' => [], 'attempted' => false, 'error' => false];
            $federationCardUpload = ['path' => null, 'attempted' => false, 'error' => false];
            $uploadedPaths = [];

            if ($message === '') {
                $playerImageUpload = uploadAcademyPlayerImage($_FILES['player_image'] ?? [], 'player');
                $birthCertificateUpload = uploadAcademyPlayerImage($_FILES['birth_certificate_file'] ?? [], 'birth-certificate');
                $medicalReportUpload = uploadAcademyPlayerImages($_FILES['medical_report_file'] ?? [], 'medical');
                $federationCardUpload = uploadAcademyPlayerImage($_FILES['federation_card_file'] ?? [], 'federation');

                foreach ([$playerImageUpload, $birthCertificateUpload, $federationCardUpload] as $uploadResult) {
                    if (!empty($uploadResult['path'])) {
                        $uploadedPaths[] = $uploadResult['path'];
                    }
                }
                foreach (($medicalReportUpload['paths'] ?? []) as $uploadedMedicalPath) {
                    $uploadedPaths[] = $uploadedMedicalPath;
                }

                if (!empty($playerImageUpload['error']) || !empty($birthCertificateUpload['error']) || !empty($medicalReportUpload['error']) || !empty($federationCardUpload['error'])) {
                    $message = '❌ تعذر رفع أحد الملفات.';
                    $messageType = 'error';
                }
            }

            if ($message === '') {
                if ($subscriptionStartDateInput === '' || $subscriptionEndDate === '') {
                    $message = '❌ لا يمكن احتساب مواعيد الاشتراك.';
                    $messageType = 'error';
                } else {
                    try {
                        $pdo->beginTransaction();

                        $playerImagePath = $playerImageUpload['path'] ?? ($existingPlayer['player_image_path'] ?? null);
                        $birthCertificatePath = $birthCertificateUpload['path'] ?? ($existingPlayer['birth_certificate_path'] ?? null);
                        $existingMedicalReportPaths = decodeAcademyPlayerMedicalReportFiles(
                            $existingPlayer['medical_report_files'] ?? null,
                            $existingPlayer['medical_report_path'] ?? null
                        );
                        $newMedicalReportPaths = $medicalReportUpload['paths'] ?? [];
                        $medicalReportPaths = $newMedicalReportPaths !== []
                            ? array_values(array_unique(array_merge($existingMedicalReportPaths, $newMedicalReportPaths)))
                            : $existingMedicalReportPaths;
                        $medicalReportPath = $medicalReportPaths[0] ?? null;
                        $medicalReportFiles = encodeAcademyPlayerMedicalReportFiles($medicalReportPaths);
                        $federationCardPath = $federationCardUpload['path'] ?? ($existingPlayer['federation_card_path'] ?? null);

                        $playerPayload = [
                            'academy_id' => (int) ($existingPlayer['academy_id'] ?? 0),
                            'subscription_id' => $subscriptionId,
                            'barcode' => $barcode,
                            'player_name' => $playerName,
                            'phone' => $playerPhone,
                            'guardian_phone' => $guardianPhone,
                            'birth_year' => $birthYear,
                            'birth_date' => $birthDate !== '' ? $birthDate : null,
                            'player_image_path' => $playerImagePath,
                            'subscription_start_date' => $subscriptionStartDateInput,
                            'subscription_end_date' => $subscriptionEndDate,
                            'subscription_name' => (string) ($subscription['subscription_name'] ?? ''),
                            'subscription_branch' => $subscriptionBranch,
                            'subscription_category' => (string) ($subscription['subscription_category'] ?? ''),
                            'subscription_training_days_count' => (int) ($subscription['training_days_count'] ?? 0),
                            'available_exercises_count' => $resolvedAvailableExercisesCount,
                            'subscription_training_schedule' => (string) ($subscription['schedule_summary'] ?? ''),
                            'subscription_coach_name' => (string) ($subscription['coach_name'] ?? ''),
                            'max_trainees' => (int) ($subscription['max_trainees'] ?? 0),
                            'subscription_base_price' => formatAcademyPlayerAmount($basePrice),
                            'additional_discount' => formatAcademyPlayerAmount($additionalDiscount),
                            'subscription_amount' => formatAcademyPlayerAmount($subscriptionAmount),
                            'paid_amount' => formatAcademyPlayerAmount($paidAmount),
                            'remaining_amount' => formatAcademyPlayerAmount($remainingAmount),
                            'receipt_number' => $receiptNumber,
                            'birth_certificate_required' => $birthCertificateRequired ? 1 : 0,
                            'birth_certificate_path' => $birthCertificatePath,
                            'medical_report_required' => $medicalReportRequired ? 1 : 0,
                            'medical_report_path' => $medicalReportPath,
                            'medical_report_files' => $medicalReportFiles,
                            'federation_card_required' => $federationCardRequired ? 1 : 0,
                            'federation_card_path' => $federationCardPath,
                            'stars_count' => $starsCount > 0 ? $starsCount : null,
                            'last_star_date' => $starsCount > 0 && $lastStarDate !== '' ? $lastStarDate : null,
                            'password_hash' => $existingPlayer === null ? password_hash(ACADEMY_PLAYER_DEFAULT_PASSWORD, PASSWORD_DEFAULT) : null,
                        ];

                        if ($existingPlayer === null) {
                            $playerInsertColumns = [
                                'academy_id',
                                'subscription_id',
                                'barcode',
                                'player_name',
                                'phone',
                                'guardian_phone',
                                'birth_year',
                                'birth_date',
                                'player_image_path',
                                'subscription_start_date',
                                'subscription_end_date',
                                'subscription_name',
                                'subscription_branch',
                                'subscription_category',
                                'subscription_training_days_count',
                                'available_exercises_count',
                                'subscription_training_schedule',
                                'subscription_coach_name',
                                'max_trainees',
                                'subscription_base_price',
                                'additional_discount',
                                'subscription_amount',
                                'paid_amount',
                                'remaining_amount',
                                'receipt_number',
                                'birth_certificate_required',
                                'birth_certificate_path',
                                'medical_report_required',
                                'medical_report_path',
                                'medical_report_files',
                                'federation_card_required',
                                'federation_card_path',
                                'stars_count',
                                'last_star_date',
                                'password_hash',
                                'created_by_user_id',
                            ];
                            $playerInsertValues = [
                                $playerPayload['academy_id'],
                                $playerPayload['subscription_id'],
                                $playerPayload['barcode'],
                                $playerPayload['player_name'],
                                $playerPayload['phone'],
                                $playerPayload['guardian_phone'],
                                $playerPayload['birth_year'],
                                $playerPayload['birth_date'],
                                $playerPayload['player_image_path'],
                                $playerPayload['subscription_start_date'],
                                $playerPayload['subscription_end_date'],
                                $playerPayload['subscription_name'],
                                $playerPayload['subscription_branch'],
                                $playerPayload['subscription_category'],
                                $playerPayload['subscription_training_days_count'],
                                $playerPayload['available_exercises_count'],
                                $playerPayload['subscription_training_schedule'],
                                $playerPayload['subscription_coach_name'],
                                $playerPayload['max_trainees'],
                                $playerPayload['subscription_base_price'],
                                $playerPayload['additional_discount'],
                                $playerPayload['subscription_amount'],
                                $playerPayload['paid_amount'],
                                $playerPayload['remaining_amount'],
                                $playerPayload['receipt_number'],
                                $playerPayload['birth_certificate_required'],
                                $playerPayload['birth_certificate_path'],
                                $playerPayload['medical_report_required'],
                                $playerPayload['medical_report_path'],
                                $playerPayload['medical_report_files'],
                                $playerPayload['federation_card_required'],
                                $playerPayload['federation_card_path'],
                                $playerPayload['stars_count'],
                                $playerPayload['last_star_date'],
                                $playerPayload['password_hash'],
                                (int) ($currentUser['id'] ?? 0) ?: null,
                            ];
                            $insertStmt = $pdo->prepare(
                                'INSERT INTO academy_players ('
                                . implode(', ', $playerInsertColumns)
                                . ') VALUES ('
                                . implode(', ', array_fill(0, count($playerInsertValues), '?'))
                                . ')'
                            );
                            $insertStmt->execute($playerInsertValues);
                            $newPlayerId = (int) $pdo->lastInsertId();

                            recordAcademyPlayerPayment($pdo, [
                                'player_id' => $newPlayerId,
                                'payment_type' => 'registration',
                                'amount' => $paidAmount,
                                'receipt_number' => $receiptNumber,
                                'created_by_user_id' => (int) ($currentUser['id'] ?? 0) ?: null,
                                'player_name_snapshot' => $playerPayload['player_name'],
                                'subscription_name_snapshot' => $playerPayload['subscription_name'],
                                'subscription_amount_snapshot' => $subscriptionAmount,
                                'paid_amount_before_snapshot' => 0,
                                'paid_amount_after_snapshot' => $paidAmount,
                                'remaining_amount_before_snapshot' => $subscriptionAmount,
                                'remaining_amount_after_snapshot' => $remainingAmount,
                            ]);

                            $pdo->commit();

                            setAcademyPlayersFlash('✅ تم تسجيل السباح بنجاح.', 'success');
                        } else {
                            $updateStmt = $pdo->prepare(
                                'UPDATE academy_players SET
                                    academy_id = ?,
                                    subscription_id = ?,
                                    barcode = ?,
                                    player_name = ?,
                                    phone = ?,
                                    guardian_phone = ?,
                                    birth_year = ?,
                                    birth_date = ?,
                                    player_image_path = ?,
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
                                    birth_certificate_required = ?,
                                    birth_certificate_path = ?,
                                    medical_report_required = ?,
                                    medical_report_path = ?,
                                    medical_report_files = ?,
                                    federation_card_required = ?,
                                    federation_card_path = ?,
                                    stars_count = ?,
                                    last_star_date = ?
                                WHERE id = ?'
                            );
                            $updateStmt->execute([
                                $playerPayload['academy_id'],
                                $playerPayload['subscription_id'],
                                $playerPayload['barcode'],
                                $playerPayload['player_name'],
                                $playerPayload['phone'],
                                $playerPayload['guardian_phone'],
                                $playerPayload['birth_year'],
                                $playerPayload['birth_date'],
                                $playerPayload['player_image_path'],
                                $playerPayload['subscription_start_date'],
                                $playerPayload['subscription_end_date'],
                                $playerPayload['subscription_name'],
                                $playerPayload['subscription_branch'],
                                $playerPayload['subscription_category'],
                                $playerPayload['subscription_training_days_count'],
                                $playerPayload['available_exercises_count'],
                                $playerPayload['subscription_training_schedule'],
                                $playerPayload['subscription_coach_name'],
                                $playerPayload['max_trainees'],
                                $playerPayload['subscription_base_price'],
                                $playerPayload['additional_discount'],
                                $playerPayload['subscription_amount'],
                                $playerPayload['paid_amount'],
                                $playerPayload['remaining_amount'],
                                $playerPayload['receipt_number'],
                                $playerPayload['birth_certificate_required'],
                                $playerPayload['birth_certificate_path'],
                                $playerPayload['medical_report_required'],
                                $playerPayload['medical_report_path'],
                                $playerPayload['medical_report_files'],
                                $playerPayload['federation_card_required'],
                                $playerPayload['federation_card_path'],
                                $playerPayload['stars_count'],
                                $playerPayload['last_star_date'],
                                $playerId,
                            ]);

                            $pdo->commit();

                            if (!empty($playerImageUpload['path']) && !empty($existingPlayer['player_image_path'])) {
                                deleteAcademyPlayerImage((string) $existingPlayer['player_image_path']);
                            }
                            if (!empty($birthCertificateUpload['path']) && !empty($existingPlayer['birth_certificate_path'])) {
                                deleteAcademyPlayerImage((string) $existingPlayer['birth_certificate_path']);
                            }
                            if (!empty($federationCardUpload['path']) && !empty($existingPlayer['federation_card_path'])) {
                                deleteAcademyPlayerImage((string) $existingPlayer['federation_card_path']);
                            }

                            setAcademyPlayersFlash('✅ تم تحديث بيانات السباح.', 'success');
                        }

                        header('Location: ' . buildAcademyPlayersPageUrl($redirectPageParams));
                        exit;
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        foreach ($uploadedPaths as $uploadedPath) {
                            deleteAcademyPlayerImage($uploadedPath);
                        }
                        $message = '❌ حدث خطأ أثناء حفظ بيانات السباح.';
                        $messageType = 'error';
                    }
                }
            }
        }
    } elseif ($action === 'collect_payment') {
        $playerId = (int) trim((string) ($_POST['player_id'] ?? '0'));
        $paymentAmountInput = normalizeAcademyPlayerDecimal((string) ($_POST['payment_amount'] ?? ''));
        $receiptNumber = sanitizeAcademyPlayerText((string) ($_POST['receipt_number'] ?? ''));
        $paymentPlayer = fetchAcademyPlayerById($pdo, $playerId);

        if ($paymentPlayer === null) {
            $message = '❌ السباح المطلوب غير موجود.';
            $messageType = 'error';
        } elseif (!isValidAcademyPlayerDecimal($paymentAmountInput)) {
            $message = '❌ أدخل مبلغ سداد صحيحًا.';
            $messageType = 'error';
        } elseif ($receiptNumber === '') {
            $message = '❌ أدخل رقم إيصال السداد.';
            $messageType = 'error';
        } elseif ((float) ($paymentPlayer['remaining_amount'] ?? 0) <= 0) {
            $message = '❌ لا يوجد مبلغ متبقي على هذا السباح.';
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
                         WHERE id = ?'
                    );
                    $paymentUpdateStmt->execute([
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
                    setAcademyPlayersFlash('✅ تم تسجيل السداد بنجاح.', 'success');
                    header('Location: ' . buildAcademyPlayersPageUrl($redirectPageParams));
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
    } elseif ($action === 'delete') {
        $playerId = (int) trim((string) ($_POST['player_id'] ?? '0'));
        $playerToDelete = fetchAcademyPlayerById($pdo, $playerId);

        if ($playerToDelete === null) {
            $message = '❌ السباح المطلوب غير موجود.';
            $messageType = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                $deletePaymentsStmt = $pdo->prepare('DELETE FROM academy_player_payments WHERE player_id = ?');
                $deletePaymentsStmt->execute([$playerId]);
                $deletePlayerStmt = $pdo->prepare('DELETE FROM academy_players WHERE id = ?');
                $deletePlayerStmt->execute([$playerId]);
                $pdo->commit();

                deleteAcademyPlayerImage((string) ($playerToDelete['player_image_path'] ?? ''));
                deleteAcademyPlayerImages(decodeAcademyPlayerMedicalReportFiles(
                    $playerToDelete['medical_report_files'] ?? null,
                    $playerToDelete['medical_report_path'] ?? null
                ));
                deleteAcademyPlayerImage((string) ($playerToDelete['birth_certificate_path'] ?? ''));
                deleteAcademyPlayerImage((string) ($playerToDelete['federation_card_path'] ?? ''));

                setAcademyPlayersFlash('✅ تم حذف السباح بنجاح.', 'success');
                header('Location: ' . buildAcademyPlayersPageUrl($redirectPageParams));
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = '❌ حدث خطأ أثناء حذف السباح.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'upload_file') {
        $playerId = (int) trim((string) ($_POST['player_id'] ?? '0'));
        $fileField = trim((string) ($_POST['file_field'] ?? ''));
        $filesPlayer = fetchAcademyPlayerById($pdo, $playerId);
        $fieldMap = [
            'player_image_path' => ['column' => 'player_image_path', 'upload' => 'player_file', 'kind' => 'player'],
            'birth_certificate_path' => ['column' => 'birth_certificate_path', 'upload' => 'player_file', 'kind' => 'birth-certificate'],
            'medical_report_path' => ['column' => 'medical_report_path', 'upload' => 'player_file', 'kind' => 'medical'],
            'federation_card_path' => ['column' => 'federation_card_path', 'upload' => 'player_file', 'kind' => 'federation'],
        ];
        $allowedFileColumns = academyPlayersAllowedFileColumns();
        $resolvedFileColumn = $allowedFileColumns[$fileField] ?? null;

        if ($filesPlayer === null || !isset($fieldMap[$fileField]) || $resolvedFileColumn === null) {
            $message = '❌ السباح أو الملف المطلوب غير موجود.';
            $messageType = 'error';
        } else {
            if ($fileField === 'medical_report_path') {
                $uploadResult = uploadAcademyPlayerImages($_FILES[$fieldMap[$fileField]['upload']] ?? [], $fieldMap[$fileField]['kind']);
                if (($uploadResult['paths'] ?? []) === [] || !empty($uploadResult['error'])) {
                    $message = '❌ تعذر رفع الملف.';
                    $messageType = 'error';
                } else {
                    try {
                        $existingMedicalReportPaths = decodeAcademyPlayerMedicalReportFiles(
                            $filesPlayer['medical_report_files'] ?? null,
                            $filesPlayer['medical_report_path'] ?? null
                        );
                        $updatedMedicalReportPaths = array_values(array_unique(array_merge(
                            $existingMedicalReportPaths,
                            $uploadResult['paths']
                        )));
                        $updateStmt = $pdo->prepare('UPDATE academy_players SET medical_report_path = ?, medical_report_files = ? WHERE id = ?');
                        $updateStmt->execute([
                            $updatedMedicalReportPaths[0] ?? null,
                            encodeAcademyPlayerMedicalReportFiles($updatedMedicalReportPaths),
                            $playerId,
                        ]);
                        setAcademyPlayersFlash('✅ تم تحديث الملف بنجاح.', 'success');
                        header('Location: ' . buildAcademyPlayersPageUrl(array_merge($redirectPageParams, ['files' => $playerId])));
                        exit;
                    } catch (Throwable $exception) {
                        deleteAcademyPlayerImages($uploadResult['paths'] ?? []);
                        $message = '❌ حدث خطأ أثناء حفظ الملف.';
                        $messageType = 'error';
                    }
                }
            } else {
                $uploadResult = uploadAcademyPlayerImage($_FILES[$fieldMap[$fileField]['upload']] ?? [], $fieldMap[$fileField]['kind']);
                if (empty($uploadResult['path']) || !empty($uploadResult['error'])) {
                    $message = '❌ تعذر رفع الملف.';
                    $messageType = 'error';
                } else {
                    try {
                        $updateStmt = academyPlayersPrepareFileUpdateStatement($pdo, $resolvedFileColumn, false);
                        $updateStmt->execute([$uploadResult['path'], $playerId]);
                        deleteAcademyPlayerImage((string) ($filesPlayer[$resolvedFileColumn] ?? ''));
                        setAcademyPlayersFlash('✅ تم تحديث الملف بنجاح.', 'success');
                        header('Location: ' . buildAcademyPlayersPageUrl(array_merge($redirectPageParams, ['files' => $playerId])));
                        exit;
                    } catch (Throwable $exception) {
                        deleteAcademyPlayerImage((string) $uploadResult['path']);
                        $message = '❌ حدث خطأ أثناء حفظ الملف.';
                        $messageType = 'error';
                    }
                }
            }
        }
    } elseif ($action === 'delete_file') {
        $playerId = (int) trim((string) ($_POST['player_id'] ?? '0'));
        $fileField = trim((string) ($_POST['file_field'] ?? ''));
        $filesPlayer = fetchAcademyPlayerById($pdo, $playerId);
        $allowedFields = academyPlayersAllowedFileColumns();
        $resolvedFileColumn = $allowedFields[$fileField] ?? null;

        if ($filesPlayer === null || $resolvedFileColumn === null) {
            $message = '❌ الملف المطلوب غير موجود.';
            $messageType = 'error';
        } else {
            try {
                if ($resolvedFileColumn === 'medical_report_path') {
                    deleteAcademyPlayerImages(decodeAcademyPlayerMedicalReportFiles(
                        $filesPlayer['medical_report_files'] ?? null,
                        $filesPlayer['medical_report_path'] ?? null
                    ));
                    $updateStmt = $pdo->prepare('UPDATE academy_players SET medical_report_path = NULL, medical_report_files = NULL WHERE id = ?');
                    $updateStmt->execute([$playerId]);
                } else {
                    $updateStmt = academyPlayersPrepareFileUpdateStatement($pdo, $resolvedFileColumn, true);
                    $updateStmt->execute([$playerId]);
                    deleteAcademyPlayerImage((string) ($filesPlayer[$resolvedFileColumn] ?? ''));
                }
                setAcademyPlayersFlash('✅ تم حذف الملف بنجاح.', 'success');
                header('Location: ' . buildAcademyPlayersPageUrl(array_merge($redirectPageParams, ['files' => $playerId])));
                exit;
            } catch (Throwable $exception) {
                $message = '❌ حدث خطأ أثناء حذف الملف.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'update_star') {
        $playerId = (int) trim((string) ($_POST['player_id'] ?? '0'));
        $starsPlayer = fetchAcademyPlayerById($pdo, $playerId);
        $submittedStarsCountInput = trim((string) ($_POST['stars_count'] ?? ''));
        $submittedStarsCount = ctype_digit($submittedStarsCountInput) ? (int) $submittedStarsCountInput : 0;
        $lastStarDate = trim((string) ($_POST['last_star_date'] ?? ''));

        if ($starsPlayer === null) {
            $message = '❌ السباح المطلوب غير موجود.';
            $messageType = 'error';
        } elseif (!academyPlayerCategoryHasStars((string) ($starsPlayer['subscription_category'] ?? ''))) {
            $starsPlayer = null;
            $message = '❌ هذا السباح لا ينتمي إلى مجموعة نجوم.';
            $messageType = 'error';
        } elseif (!isValidAcademyPlayerDateInput($lastStarDate)) {
            $message = '❌ اختر تاريخ النجمة بشكل صحيح.';
            $messageType = 'error';
        } else {
            $allowedStarsOptions = academyPlayerAllowedStarsOptions((string) ($starsPlayer['subscription_category'] ?? ''));
            $resolvedStarsCount = count($allowedStarsOptions) === 1 ? (int) $allowedStarsOptions[0] : $submittedStarsCount;

            if (!in_array($resolvedStarsCount, $allowedStarsOptions, true)) {
                $message = '❌ اختر عدد نجوم صحيحًا.';
                $messageType = 'error';
            } else {
                try {
                    $updateStarStmt = $pdo->prepare('UPDATE academy_players SET stars_count = ?, last_star_date = ? WHERE id = ?');
                    $updateStarStmt->execute([$resolvedStarsCount, $lastStarDate, $playerId]);

                    setAcademyPlayersFlash('✅ تم تحديث تاريخ النجمة بنجاح.', 'success');
                    header('Location: ' . buildAcademyPlayersPageUrl($redirectPageParams));
                    exit;
                } catch (Throwable $exception) {
                    $message = '❌ حدث خطأ أثناء تحديث تاريخ النجمة.';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'change_password') {
        $playerId = (int) trim((string) ($_POST['player_id'] ?? '0'));
        $passwordPlayer = fetchAcademyPlayerById($pdo, $playerId);
        $newPassword = trim((string) ($_POST['new_password'] ?? ''));
        $confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));

        if ($passwordPlayer === null) {
            $message = '❌ السباح المطلوب غير موجود.';
            $messageType = 'error';
        } elseif ($newPassword === '' || $confirmPassword === '') {
            $message = '❌ أدخل كلمة السر الجديدة.';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = '❌ كلمتا السر غير متطابقتين.';
            $messageType = 'error';
        } elseif (!isValidSwimmerAccountPassword($newPassword)) {
            $message = '❌ كلمة السر يجب أن تكون ' . SWIMMER_ACCOUNT_MIN_PASSWORD_LENGTH . ' أحرف على الأقل.';
            $messageType = 'error';
        } else {
            try {
                $updatePasswordStmt = $pdo->prepare('UPDATE academy_players SET password_hash = ? WHERE id = ?');
                $updatePasswordStmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $playerId]);
                setAcademyPlayersFlash('✅ تم تحديث كلمة السر بنجاح.', 'success');
                header('Location: ' . buildAcademyPlayersPageUrl($redirectPageParams));
                exit;
            } catch (Throwable $exception) {
                $message = '❌ حدث خطأ أثناء تحديث كلمة السر.';
                $messageType = 'error';
            }
        }
    }
}

if (isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
    $editPlayer = fetchAcademyPlayerById($pdo, (int) $_GET['edit']);
    if ($editPlayer === null && $message === '') {
        $message = '❌ السباح المطلوب غير موجود.';
        $messageType = 'error';
    }
}

if (isset($_GET['pay']) && ctype_digit((string) $_GET['pay'])) {
    $paymentPlayer = fetchAcademyPlayerById($pdo, (int) $_GET['pay']);
    if ($paymentPlayer === null && $message === '') {
        $message = '❌ السباح المطلوب غير موجود.';
        $messageType = 'error';
    }
}

if (isset($_GET['files']) && ctype_digit((string) $_GET['files'])) {
    $filesPlayer = fetchAcademyPlayerById($pdo, (int) $_GET['files']);
    if ($filesPlayer === null && $message === '') {
        $message = '❌ السباح المطلوب غير موجود.';
        $messageType = 'error';
    }
}

if (isset($_GET['stars']) && ctype_digit((string) $_GET['stars'])) {
    $starsPlayer = fetchAcademyPlayerById($pdo, (int) $_GET['stars']);
    if ($starsPlayer === null && $message === '') {
        $message = '❌ السباح المطلوب غير موجود.';
        $messageType = 'error';
    } elseif ($message === '' && $starsPlayer !== null && !academyPlayerCategoryHasStars((string) ($starsPlayer['subscription_category'] ?? ''))) {
        $starsPlayer = null;
        $message = '❌ هذا السباح لا ينتمي إلى مجموعة نجوم.';
        $messageType = 'error';
    }
}

if (isset($_GET['password']) && ctype_digit((string) $_GET['password'])) {
    $passwordPlayer = fetchAcademyPlayerById($pdo, (int) $_GET['password']);
    if ($passwordPlayer === null && $message === '') {
        $message = '❌ السباح المطلوب غير موجود.';
        $messageType = 'error';
    }
}

if ($filesPlayer !== null) {
    $playerPayments = fetchAcademyPlayerPayments($pdo, (int) ($filesPlayer['id'] ?? 0));
}

$totalPlayersCount = countAcademyPlayers($pdo, $filters);
$totalPlayersPages = max(1, (int) ceil($totalPlayersCount / ACADEMY_PLAYERS_PER_PAGE));
$currentPlayersPage = min($requestedPlayersPage, $totalPlayersPages);
$players = fetchAcademyPlayersPage($pdo, $filters, $currentPlayersPage, ACADEMY_PLAYERS_PER_PAGE);
$overallStats = fetchAcademyPlayerStatistics($pdo);
$categoryOptions = array_values(array_unique(array_merge(
    fetchAcademyPlayersCategories($pdo),
    array_values(array_unique(array_map(static fn(array $subscription): string => (string) ($subscription['subscription_category'] ?? ''), $subscriptions)))
)));
$categoryOptions = array_values(array_filter($categoryOptions, static fn(string $value): bool => $value !== ''));
sort($categoryOptions);
$summaryCategory = sanitizeAcademyPlayerText((string) ($_GET['summary_category'] ?? ''));
if ($summaryCategory === '' && $filters['category'] !== '') {
    $summaryCategory = $filters['category'];
}
if ($summaryCategory !== '' && !in_array($summaryCategory, $categoryOptions, true)) {
    $categoryOptions[] = $summaryCategory;
    sort($categoryOptions);
}
$branchOptions = array_values(array_unique(array_merge(
    fetchAcademyPlayersBranches($pdo),
    $filters['branch'] !== '' ? [$filters['branch']] : []
)));
$branchOptions = array_values(array_filter($branchOptions, static fn(string $value): bool => $value !== ''));
sort($branchOptions);
$subscriptionSummary = buildAcademyPlayersSubscriptionSummary($subscriptions, $summaryCategory);

$currentFilterParams = [
    'search' => $filters['search'],
    'subscription_id' => $filters['subscription_id'],
    'branch' => $filters['branch'],
    'category' => $filters['category'],
    'page' => $currentPlayersPage > 1 ? (string) $currentPlayersPage : null,
    'summary_category' => $summaryCategory,
    'status' => $filters['status'] === 'all' ? '' : $filters['status'],
    'medical_report' => $filters['medical_report'] === 'all' ? '' : $filters['medical_report'],
    'view' => $currentView === 'summary' ? 'summary' : '',
];
$summaryPageUrl = buildAcademyPlayersPageUrl(array_merge($currentFilterParams, ['view' => 'summary']));
$playersPageUrl = buildAcademyPlayersPageUrl(array_merge($currentFilterParams, ['view' => '']));

$isNewPlayerForm = $editPlayer === null;

$playerFormData = [
    'id' => (string) ($editPlayer['id'] ?? ''),
    'barcode' => (string) ($editPlayer['barcode'] ?? ''),
    'player_name' => (string) ($editPlayer['player_name'] ?? ''),
    'phone' => (string) ($editPlayer['phone'] ?? ''),
    'guardian_phone' => (string) ($editPlayer['guardian_phone'] ?? ''),
    'birth_year' => (function() use ($editPlayer): string {
        if (isset($editPlayer['birth_year']) && $editPlayer['birth_year'] !== null && $editPlayer['birth_year'] !== '') {
            return (string) (int) $editPlayer['birth_year'];
        }
        if (isset($editPlayer['birth_date']) && is_string($editPlayer['birth_date']) && $editPlayer['birth_date'] !== '') {
            $ts = strtotime($editPlayer['birth_date']);
            return $ts !== false ? date('Y', $ts) : '';
        }
        return '';
    })(),
    'subscription_id' => isset($editPlayer['subscription_id']) ? (string) $editPlayer['subscription_id'] : '',
    'subscription_branch' => (string) ($editPlayer['subscription_branch'] ?? ''),
    'subscription_category' => (string) ($editPlayer['subscription_category'] ?? ''),
    'subscription_coach_name' => (string) ($editPlayer['subscription_coach_name'] ?? ''),
    'subscription_training_schedule' => (string) ($editPlayer['subscription_training_schedule'] ?? ''),
    'available_exercises_count' => isset($editPlayer['available_exercises_count']) ? (string) $editPlayer['available_exercises_count'] : '',
    'max_trainees' => isset($editPlayer['max_trainees']) ? (string) $editPlayer['max_trainees'] : '',
    'current_players_count' => '',
    'subscription_base_price' => isset($editPlayer['subscription_base_price']) ? formatAcademyPlayerAmount($editPlayer['subscription_base_price']) : '0.00',
    'additional_discount' => isset($editPlayer['additional_discount']) ? formatAcademyPlayerAmount($editPlayer['additional_discount']) : '0.00',
    'subscription_amount' => isset($editPlayer['subscription_amount']) ? formatAcademyPlayerAmount($editPlayer['subscription_amount']) : '0.00',
    'paid_amount' => isset($editPlayer['paid_amount']) ? formatAcademyPlayerAmount($editPlayer['paid_amount']) : '0.00',
    'remaining_amount' => isset($editPlayer['remaining_amount']) ? formatAcademyPlayerAmount($editPlayer['remaining_amount']) : '0.00',
    'receipt_number' => (string) ($editPlayer['receipt_number'] ?? ''),
    'all_required_documents' => $isNewPlayerForm || (!empty($editPlayer['birth_certificate_required']) && !empty($editPlayer['medical_report_required']) && !empty($editPlayer['federation_card_required'])),
    'birth_certificate_required' => $isNewPlayerForm || !empty($editPlayer['birth_certificate_required']),
    'medical_report_required' => $isNewPlayerForm || !empty($editPlayer['medical_report_required']),
    'federation_card_required' => $isNewPlayerForm || !empty($editPlayer['federation_card_required']),
    'stars_count' => isset($editPlayer['stars_count']) && (int) $editPlayer['stars_count'] > 0 ? (string) (int) $editPlayer['stars_count'] : '',
    'last_star_date' => (string) ($editPlayer['last_star_date'] ?? ''),
    'subscription_start_date' => (string) ($editPlayer['subscription_start_date'] ?? ''),
    'subscription_end_date' => (string) ($editPlayer['subscription_end_date'] ?? ''),
    'player_image_path' => (string) ($editPlayer['player_image_path'] ?? ''),
    'birth_certificate_path' => (string) ($editPlayer['birth_certificate_path'] ?? ''),
    'medical_report_path' => (string) ($editPlayer['medical_report_path'] ?? ''),
    'federation_card_path' => (string) ($editPlayer['federation_card_path'] ?? ''),
];

if (is_array($submittedPlayerFormData)) {
    $playerFormData = array_merge($playerFormData, $submittedPlayerFormData);
}

$playerFormData['subscription_training_schedule'] = formatAcademyTrainingSchedule((string) ($playerFormData['subscription_training_schedule'] ?? ''));

$playerFormSubscriptions = array_values(array_filter(
    $subscriptions,
    static function (array $subscription) use ($editPlayer): bool {
        if ($editPlayer !== null) {
            return true;
        }

        $maxTrainees = (int) ($subscription['max_trainees'] ?? 0);
        $activePlayersCount = (int) ($subscription['active_players_count'] ?? 0);
        return $maxTrainees <= 0 || $activePlayersCount < $maxTrainees;
    }
));

$playerFormBranchOptions = array_values(array_unique(array_filter(array_map(
    static fn(array $subscription): string => sanitizeAcademyPlayerText((string) ($subscription['subscription_branch'] ?? '')),
    $playerFormSubscriptions
))));
sort($playerFormBranchOptions);

if ($playerFormData['subscription_id'] === '' && $playerFormSubscriptions !== []) {
    $firstSubscription = $playerFormSubscriptions[0];
    $playerFormData['subscription_id'] = (string) $firstSubscription['id'];
    $playerFormData['subscription_category'] = (string) ($firstSubscription['subscription_category'] ?? '');
    $playerFormData['subscription_coach_name'] = (string) ($firstSubscription['coach_name'] ?? '');
    $playerFormData['subscription_training_schedule'] = (string) ($firstSubscription['schedule_summary'] ?? '');
    $playerFormData['available_exercises_count'] = (string) ($firstSubscription['available_exercises_count'] ?? '');
    $playerFormData['max_trainees'] = (string) ($firstSubscription['max_trainees'] ?? '');
    $playerFormData['current_players_count'] = (string) ($firstSubscription['active_players_count'] ?? '0');
    $playerFormData['subscription_branch'] = (string) ($firstSubscription['subscription_branch'] ?? '');
    $playerFormData['subscription_base_price'] = formatAcademyPlayerAmount($firstSubscription['subscription_price'] ?? 0);
}

if ($playerFormData['subscription_branch'] === '' && $playerFormBranchOptions !== []) {
    $playerFormData['subscription_branch'] = $playerFormBranchOptions[0];
}

if ($playerFormData['current_players_count'] === '' && $playerFormData['subscription_id'] !== '' && ctype_digit($playerFormData['subscription_id'])) {
    $playerFormData['current_players_count'] = (string) countAcademyPlayersForSubscription(
        $pdo,
        (int) $playerFormData['subscription_id'],
        ctype_digit($playerFormData['id']) ? (int) $playerFormData['id'] : null
    );
}

$resolvedFormStarsCount = $playerFormData['stars_count'] !== ''
    ? (int) $playerFormData['stars_count']
    : academyPlayerResolveStarsCount($playerFormData['subscription_category'], null);
$playerFormData['stars_count'] = $resolvedFormStarsCount > 0 ? (string) $resolvedFormStarsCount : '';

$playerFormModalHeading = $editPlayer ? 'تعديل سباح' : 'إضافة سباح جديد';
$playerFormLauncherText = $editPlayer ? 'اضغط على الزر لفتح نافذة تعديل السباح.' : 'اضغط على الزر لفتح نافذة إضافة سباح جديد.';
$playerFormLauncherButtonText = $editPlayer ? 'تعديل سباح' : 'إضافة سباح';

$academyPlayersCsrfToken = getAcademyPlayersCsrfToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>السباحين</title>
    <link rel="stylesheet" href="assets/css/academy-players.css">
</head>
<body
    class="light-mode"
    data-page-url="<?php echo htmlspecialchars(ACADEMY_PLAYERS_PAGE_FILE, ENT_QUOTES, 'UTF-8'); ?>"
    data-can-manage-discount="<?php echo $canManageDiscount ? '1' : '0'; ?>"
    data-custom-stars-options="<?php echo htmlspecialchars(implode(',', ACADEMY_PLAYERS_CUSTOM_STARS_OPTIONS), ENT_QUOTES, 'UTF-8'); ?>"
    data-form-available-exercises-count="<?php echo (int) ($playerFormData['available_exercises_count'] === '' ? 0 : $playerFormData['available_exercises_count']); ?>"
    data-form-modal-open="<?php echo ($editPlayer || is_array($submittedPlayerFormData)) ? '1' : '0'; ?>"
    data-form-close-url="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl($currentFilterParams), ENT_QUOTES, 'UTF-8'); ?>"
>
<div class="academy-players-page">
    <header class="page-header">
        <div class="header-text">
            <span class="eyebrow">السباحين</span>
            <h1>السباحين</h1>
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
            <a href="dashboard.php" class="back-btn">الرجوع</a>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div class="message-box <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <section class="hero-grid">
        <article class="hero-card hero-card-main">
            <span class="hero-icon">🏊</span>
            <h2>إدارة السباحين</h2>
        </article>
        <article class="hero-card">
            <span>إجمالي السباحين</span>
            <strong><?php echo (int) ($overallStats['total_players'] ?? 0); ?></strong>
        </article>
        <article class="hero-card">
            <span>المجموعات المستمرة</span>
            <strong><?php echo (int) ($overallStats['active_players'] ?? 0); ?></strong>
        </article>
        <article class="hero-card">
            <span>المجموعات المنتهية</span>
            <strong><?php echo (int) ($overallStats['expired_players'] ?? 0); ?></strong>
        </article>
        <article class="hero-card">
            <span>سباحين عليهم متبقي</span>
            <strong><?php echo (int) ($overallStats['players_with_balance'] ?? 0); ?></strong>
        </article>
        <article class="hero-card">
            <span>إجمالي المتبقي</span>
            <strong><?php echo formatAcademyPlayerMoney($overallStats['total_remaining'] ?? 0); ?> ج.م</strong>
        </article>
    </section>

    <button
        type="button"
        class="save-btn mobile-player-launcher"
        data-open-player-modal
        aria-label="إضافة سباح"
        aria-haspopup="dialog"
        aria-controls="playerFormModal"
        aria-expanded="false"
    >
        إضافة سباح
    </button>

    <div class="modal-overlay hidden" id="playerFormModal" role="dialog" aria-modal="true" aria-labelledby="playerFormModalTitle">
        <div class="modal-shell">
            <div class="form-card modal-form-card">
                <div class="card-head modal-card-head">
                    <h2 id="playerFormModalTitle"><?php echo htmlspecialchars($playerFormModalHeading, ENT_QUOTES, 'UTF-8'); ?></h2>
                    <button type="button" class="modal-close-btn" data-close-player-modal>إغلاق</button>
                </div>

        <form method="POST" enctype="multipart/form-data" class="player-form" id="playerForm" autocomplete="off">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="id" value="<?php echo htmlspecialchars($playerFormData['id'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($academyPlayersCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="current_search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="current_subscription_id" value="<?php echo htmlspecialchars($filters['subscription_id'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="current_branch" value="<?php echo htmlspecialchars($filters['branch'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="current_category" value="<?php echo htmlspecialchars($filters['category'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($filters['status'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="current_medical_report" value="<?php echo htmlspecialchars($filters['medical_report'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="current_page" value="<?php echo $currentPlayersPage; ?>">
            <input type="hidden" name="current_view" value="<?php echo htmlspecialchars($currentView, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-grid form-grid-compact">
                <div class="form-group">
                    <label for="barcode">باركود السباح</label>
                    <input type="text" name="barcode" id="barcode" value="<?php echo htmlspecialchars($playerFormData['barcode'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="player_name">اسم السباح</label>
                    <input type="text" name="player_name" id="player_name" value="<?php echo htmlspecialchars($playerFormData['player_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="phone">رقم الأب</label>
                    <input type="text" name="phone" id="phone" inputmode="tel" value="<?php echo htmlspecialchars($playerFormData['phone'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group">
                    <label for="guardian_phone">رقم الأم</label>
                    <input type="text" name="guardian_phone" id="guardian_phone" inputmode="tel" value="<?php echo htmlspecialchars($playerFormData['guardian_phone'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group">
                    <label for="subscription_branch">الفرع</label>
                    <select name="subscription_branch" id="subscription_branch" <?php echo $playerFormBranchOptions === [] ? 'disabled' : ''; ?>>
                        <?php if ($playerFormBranchOptions === []): ?>
                            <option value="">لا توجد فروع</option>
                        <?php else: ?>
                            <?php foreach ($playerFormBranchOptions as $branchOption): ?>
                                <option value="<?php echo htmlspecialchars($branchOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $playerFormData['subscription_branch'] === $branchOption ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($branchOption, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="birth_date">سنة الميلاد</label>
                    <input type="number" name="birth_year" id="birth_date" value="<?php echo htmlspecialchars($playerFormData['birth_year'], ENT_QUOTES, 'UTF-8'); ?>" min="1950" max="<?php echo (int) date('Y'); ?>" placeholder="مثال: 2010" required>
                </div>
                <div class="form-group">
                    <label for="subscription_start_date">تاريخ بداية الاشتراك</label>
                    <input type="date" name="subscription_start_date" id="subscription_start_date" value="<?php echo htmlspecialchars($playerFormData['subscription_start_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="player_age">السن (مُحتسب)</label>
                    <input type="text" id="player_age" value="<?php echo htmlspecialchars(calculateAcademyPlayerAgeFromYear($playerFormData['birth_year'] !== '' ? (int) $playerFormData['birth_year'] : null), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                </div>
                <div class="form-group file-group">
                    <label for="player_image">صورة السباح</label>
                    <input type="file" name="player_image" id="player_image" accept="image/*">
                </div>
                <div class="form-group form-group-full subscription-select-group">
                    <label for="subscription_id">المجموعة</label>
                    <select name="subscription_id" id="subscription_id" required <?php echo $playerFormSubscriptions === [] ? 'disabled' : ''; ?>>
                        <?php if ($playerFormSubscriptions === []): ?>
                            <option value="">لا توجد مجموعات</option>
                        <?php else: ?>
                            <?php foreach ($playerFormSubscriptions as $subscription): ?>
                                <?php $subscriptionId = (int) ($subscription['id'] ?? 0); ?>
                                <?php $subscriptionDisplayText = formatAcademyPlayerSubscriptionDisplay($subscription); ?>
                                <option
                                    value="<?php echo $subscriptionId; ?>"
                                    data-branch="<?php echo htmlspecialchars((string) ($subscription['subscription_branch'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-category="<?php echo htmlspecialchars((string) ($subscription['subscription_category'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-coach="<?php echo htmlspecialchars((string) ($subscription['coach_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-schedule="<?php echo htmlspecialchars((string) ($subscription['schedule_summary'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-schedule-days="<?php echo htmlspecialchars((string) ($subscription['schedule_days'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-exercises="<?php echo (int) ($subscription['available_exercises_count'] ?? 0); ?>"
                                    data-max-trainees="<?php echo (int) ($subscription['max_trainees'] ?? 0); ?>"
                                    data-current-count="<?php echo (int) ($subscription['active_players_count'] ?? 0); ?>"
                                    data-price="<?php echo htmlspecialchars(formatAcademyPlayerAmount($subscription['subscription_price'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-stars-count="<?php echo academyPlayerCategoryStarsCount((string) ($subscription['subscription_category'] ?? '')); ?>"
                                    data-allows-custom-stars="<?php echo academyPlayerCategoryAllowsCustomStars((string) ($subscription['subscription_category'] ?? '')) ? '1' : '0'; ?>"
                                    <?php echo (string) $subscriptionId === $playerFormData['subscription_id'] ? 'selected' : ''; ?>
                                ><?php echo htmlspecialchars($subscriptionDisplayText, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="subscription-overview-grid">
                <div class="overview-item">
                    <span>مستوى المجموعة</span>
                    <strong id="subscription_category_display"><?php echo htmlspecialchars($playerFormData['subscription_category'] !== '' ? $playerFormData['subscription_category'] : '—', ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div class="overview-item">
                    <span>المدرب</span>
                    <strong id="subscription_coach_display"><?php echo htmlspecialchars($playerFormData['subscription_coach_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div class="overview-item">
                    <span>الأيام والساعة</span>
                    <strong id="subscription_schedule_display"><?php echo htmlspecialchars($playerFormData['subscription_training_schedule'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div class="overview-item">
                    <span>عدد التمارين المتاحة</span>
                    <strong id="subscription_exercises_display"><?php echo htmlspecialchars($playerFormData['available_exercises_count'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div class="overview-item">
                    <span>السباحين / الحد الأقصى</span>
                    <strong id="subscription_capacity_display"><?php echo htmlspecialchars(($playerFormData['current_players_count'] === '' ? '0' : $playerFormData['current_players_count']) . ' / ' . ($playerFormData['max_trainees'] === '' ? '0' : $playerFormData['max_trainees']), ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div class="overview-item">
                    <span>تاريخ نهاية الاشتراك</span>
                    <strong id="subscription_end_display"><?php echo htmlspecialchars($playerFormData['subscription_end_date'] !== '' ? $playerFormData['subscription_end_date'] : '—', ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
            </div>

            <div class="form-grid form-grid-compact payment-grid">
                <div class="form-group">
                    <label for="subscription_base_price">السعر</label>
                    <input type="number" min="0" step="0.01" name="subscription_base_price" id="subscription_base_price" value="<?php echo htmlspecialchars($playerFormData['subscription_base_price'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <?php if ($canManageDiscount): ?>
                    <div class="form-group" id="additionalDiscountGroup">
                        <label for="additional_discount">خصم الإدارة</label>
                        <input type="number" min="0" step="0.01" name="additional_discount" id="additional_discount" value="<?php echo htmlspecialchars($playerFormData['additional_discount'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="subscription_amount">الإجمالي</label>
                    <input type="text" id="subscription_amount" value="<?php echo htmlspecialchars($playerFormData['subscription_amount'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="paid_amount">المدفوع</label>
                    <input type="number" min="0" step="0.01" name="paid_amount" id="paid_amount" value="<?php echo htmlspecialchars($playerFormData['paid_amount'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="remaining_amount">المتبقي</label>
                    <input type="text" id="remaining_amount" value="<?php echo htmlspecialchars($playerFormData['remaining_amount'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="receipt_number">رقم الإيصال</label>
                    <input type="text" name="receipt_number" id="receipt_number" value="<?php echo htmlspecialchars($playerFormData['receipt_number'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <div class="conditional-grid">
                <div class="toggle-card">
                    <label class="toggle-line">
                        <span>كل المستندات</span>
                        <input type="checkbox" name="all_required_documents" id="all_required_documents" value="1" <?php echo !empty($playerFormData['all_required_documents']) ? 'checked' : ''; ?>>
                    </label>
                </div>
                <div class="toggle-card" id="birthCertificateToggleCard">
                    <label class="toggle-line">
                        <span>شهادة الميلاد</span>
                        <input type="checkbox" name="birth_certificate_required" id="birth_certificate_required" value="1" <?php echo !empty($playerFormData['birth_certificate_required']) ? 'checked' : ''; ?>>
                    </label>
                    <div class="inline-upload <?php echo !empty($playerFormData['birth_certificate_required']) ? '' : 'hidden'; ?>" id="birthCertificateUploadWrap">
                        <input type="file" name="birth_certificate_file" id="birth_certificate_file" accept="image/*">
                    </div>
                </div>
                <div class="toggle-card" id="medicalReportToggleCard">
                    <label class="toggle-line">
                        <span>التقرير الطبي</span>
                        <input type="checkbox" name="medical_report_required" id="medical_report_required" value="1" <?php echo !empty($playerFormData['medical_report_required']) ? 'checked' : ''; ?>>
                    </label>
                    <div class="inline-upload <?php echo !empty($playerFormData['medical_report_required']) ? '' : 'hidden'; ?>" id="medicalReportUploadWrap">
                        <input type="file" name="medical_report_file[]" id="medical_report_file" accept="image/*" multiple>
                    </div>
                </div>
                <div class="toggle-card" id="federationCardToggleCard">
                    <label class="toggle-line">
                        <span>كارنية الاتحاد</span>
                        <input type="checkbox" name="federation_card_required" id="federation_card_required" value="1" <?php echo !empty($playerFormData['federation_card_required']) ? 'checked' : ''; ?>>
                    </label>
                    <div class="inline-upload <?php echo !empty($playerFormData['federation_card_required']) ? '' : 'hidden'; ?>" id="federationCardUploadWrap">
                        <input type="file" name="federation_card_file" id="federation_card_file" accept="image/*">
                    </div>
                </div>
                <div class="toggle-card hidden" id="starsCard">
                    <div class="stars-preview" id="starsPreview"><?php echo htmlspecialchars(academyPlayerStarsText((int) ($playerFormData['stars_count'] === '' ? 0 : $playerFormData['stars_count'])), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group compact-field hidden" id="starsCountGroup">
                        <label for="stars_count">عدد النجوم</label>
                        <select name="stars_count" id="stars_count">
                            <option value="">اختر عدد النجوم</option>
                            <?php foreach (ACADEMY_PLAYERS_CUSTOM_STARS_OPTIONS as $starsOption): ?>
                                <option value="<?php echo $starsOption; ?>" <?php echo (string) $starsOption === $playerFormData['stars_count'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(academyPlayerStarsOptionLabel((int) $starsOption), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group compact-field">
                        <label for="last_star_date">تاريخ آخر نجمة (اختياري)</label>
                        <input type="date" name="last_star_date" id="last_star_date" value="<?php echo htmlspecialchars($playerFormData['last_star_date'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="save-btn" <?php echo $playerFormSubscriptions === [] ? 'disabled' : ''; ?>><?php echo $editPlayer ? 'حفظ التعديل' : 'تسجيل السباح'; ?></button>
                <button type="button" class="clear-btn" id="clearBtn">مسح</button>
            </div>
        </form>
            </div>
        </div>
    </div>

    <?php if ($currentView === 'summary'): ?>
        <section class="summary-page-card">
            <div class="summary-page-header">
                <div>
                    <span class="eyebrow">ملخص المجموعات</span>
                    <h2>ملخص المجموعات</h2>
                </div>
                <div class="toolbar-actions">
                    <button
                        type="button"
                        class="save-btn desktop-player-launcher"
                        data-open-player-modal
                        aria-label="إضافة سباح"
                        aria-haspopup="dialog"
                        aria-controls="playerFormModal"
                        aria-expanded="false"
                    >
                        إضافة سباح
                    </button>
                    <a href="<?php echo htmlspecialchars($playersPageUrl, ENT_QUOTES, 'UTF-8'); ?>" class="back-btn">العودة لجدول السباحين</a>
                </div>
            </div>
            <form method="GET" class="filter-form filter-form-horizontal summary-page-form" autocomplete="off">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="subscription_id" value="<?php echo htmlspecialchars($filters['subscription_id'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="branch" value="<?php echo htmlspecialchars($filters['branch'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="category" value="<?php echo htmlspecialchars($filters['category'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($filters['status'] === 'all' ? '' : $filters['status'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="medical_report" value="<?php echo htmlspecialchars($filters['medical_report'] === 'all' ? '' : $filters['medical_report'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="view" value="summary">
                <div class="form-group">
                    <label for="summary_category">مستوى الملخص</label>
                    <select name="summary_category" id="summary_category">
                        <option value="">كل المستويات</option>
                        <?php foreach ($categoryOptions as $categoryOption): ?>
                            <option value="<?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $summaryCategory === $categoryOption ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions compact-actions toolbar-form-actions">
                    <button type="submit" class="save-btn">تحديث الملخص</button>
                </div>
            </form>
            <div class="mini-stats summary-stats-grid">
                <div class="mini-stat">
                    <span>المبالغ المسددة</span>
                    <strong><?php echo formatAcademyPlayerMoney($overallStats['total_paid'] ?? 0); ?> ج.م</strong>
                </div>
                <div class="mini-stat accent-stat">
                    <span>المجموعات المتاحة لإضافة سباحين</span>
                    <strong><?php echo (int) ($subscriptionSummary['available_groups_count'] ?? 0); ?></strong>
                </div>
                <div class="mini-stat">
                    <span>المجموعات المكتملة</span>
                    <strong><?php echo (int) ($subscriptionSummary['full_groups_count'] ?? 0); ?></strong>
                </div>
            </div>
            <div class="summary-groups-list summary-page-groups">
                <h3>المجموعات المتاحة</h3>
                <?php if (!empty($subscriptionSummary['available_groups'])): ?>
                    <div class="info-list compact-list">
                        <?php foreach ($subscriptionSummary['available_groups'] as $availableGroup): ?>
                            <div>
                                <span>
                                    <?php echo htmlspecialchars((string) ($availableGroup['subscription_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ((string) ($availableGroup['subscription_branch'] ?? '') !== ''): ?>
                                        <small class="summary-inline-note"><?php echo htmlspecialchars((string) $availableGroup['subscription_branch'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    <?php endif; ?>
                                </span>
                                <strong>
                                    <?php echo (int) ($availableGroup['active_players_count'] ?? 0); ?> / <?php echo (int) ($availableGroup['max_trainees'] ?? 0); ?>
                                    <small class="summary-inline-note">متبقي <?php echo (int) ($availableGroup['remaining_slots'] ?? 0); ?></small>
                                </strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-file">لا توجد مجموعات متاحة ضمن المستوى المحدد حالياً.</div>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <?php renderAcademyPlayersHorizontalToolbar($filters, $subscriptions, $branchOptions, $categoryOptions, $summaryCategory, $currentFilterParams, 'top', $summaryPageUrl); ?>
    <?php endif; ?>

    <section class="action-panels">

            <?php if ($paymentPlayer !== null && (float) ($paymentPlayer['remaining_amount'] ?? 0) > 0): ?>
                <div class="side-card action-card" id="collect-payment-card">
                    <h3>سداد</h3>
                    <div class="info-list">
                        <div><span>السباح</span><strong><?php echo htmlspecialchars((string) ($paymentPlayer['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>المجموعة</span><strong><?php echo htmlspecialchars((string) ($paymentPlayer['subscription_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>الفرع</span><strong><?php echo htmlspecialchars((string) ($paymentPlayer['subscription_branch'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>المتبقي</span><strong><?php echo formatAcademyPlayerMoney($paymentPlayer['remaining_amount'] ?? 0); ?> ج.م</strong></div>
                    </div>
                    <form method="POST" class="stack-form" autocomplete="off">
                        <input type="hidden" name="action" value="collect_payment">
                        <input type="hidden" name="player_id" value="<?php echo (int) ($paymentPlayer['id'] ?? 0); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($academyPlayersCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_subscription_id" value="<?php echo htmlspecialchars($filters['subscription_id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_branch" value="<?php echo htmlspecialchars($filters['branch'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_category" value="<?php echo htmlspecialchars($filters['category'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($filters['status'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_medical_report" value="<?php echo htmlspecialchars($filters['medical_report'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_page" value="<?php echo $currentPlayersPage; ?>">
                        <div class="form-group">
                            <label for="payment_amount">مبلغ السداد</label>
                            <input type="number" name="payment_amount" id="payment_amount" min="0.01" max="<?php echo htmlspecialchars(formatAcademyPlayerAmount($paymentPlayer['remaining_amount'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label for="payment_receipt_number">رقم إيصال السداد</label>
                            <input type="text" name="receipt_number" id="payment_receipt_number" required>
                        </div>
                        <div class="form-actions compact-actions">
                            <button type="submit" class="save-btn">تسجيل السداد</button>
                            <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl($currentFilterParams), ENT_QUOTES, 'UTF-8'); ?>" class="clear-btn link-btn">إغلاق</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($filesPlayer !== null): ?>
                <?php $filesPlayerStarsCount = academyPlayerResolveStarsCount((string) ($filesPlayer['subscription_category'] ?? ''), $filesPlayer['stars_count'] ?? null); ?>
                <?php $filesPlayerMedicalReportPaths = decodeAcademyPlayerMedicalReportFiles($filesPlayer['medical_report_files'] ?? null, $filesPlayer['medical_report_path'] ?? null); ?>
                <div class="side-card action-card" id="player-files-card">
                    <h3>ملفات السباح</h3>
                    <div class="info-list compact-list">
                        <div><span>السباح</span><strong><?php echo htmlspecialchars((string) ($filesPlayer['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>المجموعة</span><strong><?php echo htmlspecialchars((string) ($filesPlayer['subscription_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>الفرع</span><strong><?php echo htmlspecialchars((string) ($filesPlayer['subscription_branch'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <?php if ($filesPlayerStarsCount > 0): ?>
                            <div><span>النجوم</span><strong><?php echo htmlspecialchars(academyPlayerStarsText($filesPlayerStarsCount), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div><span>تاريخ آخر نجمة</span><strong><?php echo htmlspecialchars(formatAcademyPlayerDate((string) ($filesPlayer['last_star_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <?php endif; ?>
                    </div>

                    <div class="files-stack">
                        <?php
                        $fileCards = [
                            'player_image_path' => ['title' => 'صورة السباح', 'required' => true],
                            'birth_certificate_path' => ['title' => 'شهادة الميلاد', 'required' => !empty($filesPlayer['birth_certificate_required'])],
                            'medical_report_path' => ['title' => 'التقرير الطبي', 'required' => !empty($filesPlayer['medical_report_required'])],
                            'federation_card_path' => ['title' => 'كارنية الاتحاد', 'required' => !empty($filesPlayer['federation_card_required'])],
                        ];
                        ?>
                        <?php foreach ($fileCards as $fileField => $fileCard): ?>
                            <?php
                            $filePath = $fileField === 'medical_report_path'
                                ? ($filesPlayerMedicalReportPaths[0] ?? null)
                                : normalizeAcademyPlayerUploadPublicPath($filesPlayer[$fileField] ?? null);
                            ?>
                            <article class="file-card">
                                <div class="file-card-head">
                                    <strong><?php echo htmlspecialchars($fileCard['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if ($filePath !== null): ?>
                                        <a href="<?php echo htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="file-view-link">عرض</a>
                                    <?php endif; ?>
                                </div>
                                <?php if ($fileField === 'medical_report_path' && $filesPlayerMedicalReportPaths !== []): ?>
                                    <div class="file-gallery">
                                        <?php foreach ($filesPlayerMedicalReportPaths as $medicalReportImagePath): ?>
                                            <a href="<?php echo htmlspecialchars($medicalReportImagePath, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="file-preview">
                                                <img src="<?php echo htmlspecialchars($medicalReportImagePath, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($fileCard['title'], ENT_QUOTES, 'UTF-8'); ?>">
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php elseif ($filePath !== null): ?>
                                    <div class="file-preview">
                                        <img src="<?php echo htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($fileCard['title'], ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                <?php else: ?>
                                    <div class="empty-file">غير مرفوع</div>
                                <?php endif; ?>

                                <form method="POST" enctype="multipart/form-data" class="stack-form compact-stack">
                                    <input type="hidden" name="action" value="upload_file">
                                    <input type="hidden" name="player_id" value="<?php echo (int) ($filesPlayer['id'] ?? 0); ?>">
                                    <input type="hidden" name="file_field" value="<?php echo htmlspecialchars($fileField, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($academyPlayersCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="current_search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="current_subscription_id" value="<?php echo htmlspecialchars($filters['subscription_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="current_branch" value="<?php echo htmlspecialchars($filters['branch'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="current_category" value="<?php echo htmlspecialchars($filters['category'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($filters['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="current_medical_report" value="<?php echo htmlspecialchars($filters['medical_report'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="current_page" value="<?php echo $currentPlayersPage; ?>">
                                    <input type="file" name="player_file<?php echo $fileField === 'medical_report_path' ? '[]' : ''; ?>" accept="image/*" <?php echo $fileField === 'medical_report_path' ? 'multiple' : ''; ?> required>
                                    <button type="submit" class="link-btn file-action-btn">رفع</button>
                                </form>

                                <?php if ($filePath !== null): ?>
                                    <form method="POST" class="inline-form compact-inline-form">
                                        <input type="hidden" name="action" value="delete_file">
                                        <input type="hidden" name="player_id" value="<?php echo (int) ($filesPlayer['id'] ?? 0); ?>">
                                        <input type="hidden" name="file_field" value="<?php echo htmlspecialchars($fileField, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($academyPlayersCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="current_search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="current_subscription_id" value="<?php echo htmlspecialchars($filters['subscription_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="current_branch" value="<?php echo htmlspecialchars($filters['branch'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="current_category" value="<?php echo htmlspecialchars($filters['category'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($filters['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="current_medical_report" value="<?php echo htmlspecialchars($filters['medical_report'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="current_page" value="<?php echo $currentPlayersPage; ?>">
                                        <button type="submit" class="delete-btn compact-delete-btn">حذف</button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($playerPayments !== []): ?>
                        <div class="payments-history">
                            <h4>سجل السداد</h4>
                            <div class="payments-list">
                                <?php foreach ($playerPayments as $paymentItem): ?>
                                    <div class="payment-history-item">
                                        <strong><?php echo formatAcademyPlayerMoney($paymentItem['amount'] ?? 0); ?> ج.م</strong>
                                        <span><?php echo htmlspecialchars((string) ($paymentItem['receipt_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span><?php echo htmlspecialchars(formatAcademyPlayerDate((string) ($paymentItem['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-actions compact-actions">
                        <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl($currentFilterParams), ENT_QUOTES, 'UTF-8'); ?>" class="clear-btn link-btn">إغلاق</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($passwordPlayer !== null): ?>
                <div class="side-card action-card" id="player-password-card">
                    <h3>تغيير كلمة السر</h3>
                    <div class="info-list compact-list">
                        <div><span>السباح</span><strong><?php echo htmlspecialchars((string) ($passwordPlayer['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>الباركود</span><strong><?php echo htmlspecialchars((string) ($passwordPlayer['barcode'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                    <form method="POST" class="stack-form" autocomplete="off">
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="player_id" value="<?php echo (int) ($passwordPlayer['id'] ?? 0); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($academyPlayersCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_subscription_id" value="<?php echo htmlspecialchars($filters['subscription_id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_branch" value="<?php echo htmlspecialchars($filters['branch'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_category" value="<?php echo htmlspecialchars($filters['category'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($filters['status'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_medical_report" value="<?php echo htmlspecialchars($filters['medical_report'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_page" value="<?php echo $currentPlayersPage; ?>">
                        <div class="form-group">
                            <label for="new_password">كلمة السر الجديدة</label>
                            <input type="password" name="new_password" id="new_password" required>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">تأكيد كلمة السر</label>
                            <input type="password" name="confirm_password" id="confirm_password" required>
                        </div>
                        <div class="form-actions compact-actions">
                            <button type="submit" class="save-btn">حفظ</button>
                            <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl($currentFilterParams), ENT_QUOTES, 'UTF-8'); ?>" class="clear-btn link-btn">إغلاق</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($starsPlayer !== null): ?>
                <?php $starsPlayerAllowedOptions = academyPlayerAllowedStarsOptions((string) ($starsPlayer['subscription_category'] ?? '')); ?>
                <?php $starsPlayerResolvedCount = academyPlayerResolveStarsCount((string) ($starsPlayer['subscription_category'] ?? ''), $starsPlayer['stars_count'] ?? null); ?>
                <div class="side-card action-card" id="player-stars-card">
                    <h3>تحديث بيانات النجمة</h3>
                    <div class="info-list compact-list">
                        <div><span>السباح</span><strong><?php echo htmlspecialchars((string) ($starsPlayer['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>المجموعة</span><strong><?php echo htmlspecialchars((string) ($starsPlayer['subscription_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                    <form method="POST" class="stack-form" autocomplete="off">
                        <input type="hidden" name="action" value="update_star">
                        <input type="hidden" name="player_id" value="<?php echo (int) ($starsPlayer['id'] ?? 0); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($academyPlayersCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_subscription_id" value="<?php echo htmlspecialchars($filters['subscription_id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_branch" value="<?php echo htmlspecialchars($filters['branch'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_category" value="<?php echo htmlspecialchars($filters['category'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($filters['status'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_medical_report" value="<?php echo htmlspecialchars($filters['medical_report'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="current_page" value="<?php echo $currentPlayersPage; ?>">
                        <div class="form-group">
                            <label for="star_player_count">عدد النجوم</label>
                            <?php if (count($starsPlayerAllowedOptions) === 1): ?>
                                <input type="hidden" name="stars_count" value="<?php echo (int) $starsPlayerAllowedOptions[0]; ?>">
                                <input type="text" id="star_player_count" value="<?php echo htmlspecialchars(academyPlayerStarsOptionLabel((int) $starsPlayerAllowedOptions[0]), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                            <?php else: ?>
                                <select name="stars_count" id="star_player_count" required>
                                    <option value="">اختر عدد النجوم</option>
                                    <?php foreach ($starsPlayerAllowedOptions as $starsOption): ?>
                                        <option value="<?php echo $starsOption; ?>" <?php echo $starsPlayerResolvedCount === (int) $starsOption ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(academyPlayerStarsOptionLabel((int) $starsOption), ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="star_player_date">تاريخ الحصول على النجمة</label>
                            <input type="date" name="last_star_date" id="star_player_date" value="<?php echo htmlspecialchars((string) ($starsPlayer['last_star_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="form-actions compact-actions">
                            <button type="submit" class="save-btn">حفظ تاريخ النجمة</button>
                            <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl($currentFilterParams), ENT_QUOTES, 'UTF-8'); ?>" class="clear-btn link-btn">إغلاق</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
    </section>

    <?php if ($currentView !== 'summary'): ?>
        <section class="table-card">
            <div class="card-head table-head">
                <div>
                    <h2>جدول السباحين</h2>
                </div>
                <span class="table-count">صفحة <?php echo $currentPlayersPage; ?> من <?php echo $totalPlayersPages; ?> · <?php echo $totalPlayersCount; ?> سباح</span>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                        <th>صورة السباح</th>
                        <th>اسم السباح</th>
                        <th>الباركود</th>
                        <th>رقم الأب</th>
                        <th>رقم الأم</th>
                        <th>سنة الميلاد</th>
                        <th>السن</th>
                        <th>المجموعة</th>
                        <th>مستوى المجموعة</th>
                        <th>الفرع</th>
                        <th>التقرير الطبي</th>
                        <th>عدد النجوم</th>
                        <th>تاريخ آخر نجمة</th>
                        <th>بداية الاشتراك</th>
                        <th>نهاية الاشتراك</th>
                        <th>حالة الاشتراك</th>
                        <th>المدرب</th>
                        <th>أيام التمرين الوقت واليوم</th>
                        <th>التمارين المتاحة</th>
                        <th>الإجمالي</th>
                        <th>المدفوع</th>
                        <th>رقم إيصال الدفع</th>
                        <th>المتبقي</th>
                        <th>رقم إيصال سداد المتبقي</th>
                        <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($players !== []): ?>
                        <?php foreach ($players as $player): ?>
                            <?php $playerImagePath = normalizeAcademyPlayerUploadPublicPath($player['player_image_path'] ?? null); ?>
                            <?php $playerMedicalReportFiles = decodeAcademyPlayerMedicalReportFiles($player['medical_report_files'] ?? null, $player['medical_report_path'] ?? null); ?>
                            <?php $playerMedicalReportPath = $playerMedicalReportFiles[0] ?? null; ?>
                            <?php $playerRequiresMedicalReport = !empty($player['medical_report_required']); ?>
                            <?php $playerMedicalReportStatusClass = $playerMedicalReportPath !== null ? 'active' : ($playerRequiresMedicalReport ? 'warning' : 'neutral'); ?>
                            <?php $playerMedicalReportStatusText = $playerMedicalReportPath !== null ? ('مرفوع (الملفات: ' . count($playerMedicalReportFiles) . ')') : ($playerRequiresMedicalReport ? 'غير مرفوع' : 'غير مطلوب'); ?>
                            <?php $playerHasStarsCategory = academyPlayerCategoryHasStars((string) ($player['subscription_category'] ?? '')); ?>
                            <?php $playerLastStarDate = formatAcademyPlayerDate((string) ($player['last_star_date'] ?? '')); ?>
                            <?php $playerStarsCount = (int) ($player['stars_count'] ?? 0); ?>
                            <?php $playerHasStars = $playerHasStarsCategory && $playerStarsCount > 0; ?>
                            <tr>
                                <td data-label="صورة السباح" class="table-avatar-cell">
                                    <div class="table-avatar-box">
                                        <div class="player-avatar-shell">
                                            <?php if ($playerImagePath !== null): ?>
                                                <img class="player-avatar-image" src="<?php echo htmlspecialchars($playerImagePath, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string) ($player['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php else: ?>
                                                <span class="player-avatar">🏅</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="اسم السباح">
                                    <div class="stacked-cell">
                                        <strong><?php echo htmlspecialchars((string) ($player['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </div>
                                </td>
                                <td data-label="الباركود"><span class="table-cell-text"><?php echo htmlspecialchars((string) (($player['barcode'] ?? '') !== '' ? $player['barcode'] : '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="رقم الأب"><span class="table-cell-text"><?php echo htmlspecialchars((string) ($player['phone'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="رقم الأم"><span class="table-cell-text"><?php echo htmlspecialchars((string) ($player['guardian_phone'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="سنة الميلاد"><span class="birth-year-badge"><?php echo htmlspecialchars((string) ($player['birth_year_display'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="السن"><span class="age-badge"><?php echo htmlspecialchars((string) ($player['age_text'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="المجموعة"><span class="table-cell-text"><?php echo htmlspecialchars((string) ($player['subscription_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="مستوى المجموعة"><span class="table-cell-text"><?php echo htmlspecialchars((string) (($player['subscription_category'] ?? '') !== '' ? $player['subscription_category'] : '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="الفرع"><span class="table-cell-text"><?php echo htmlspecialchars((string) ($player['subscription_branch'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="التقرير الطبي">
                                    <div class="stacked-cell">
                                        <strong><?php echo htmlspecialchars($playerRequiresMedicalReport ? 'مطلوب' : 'غير مطلوب', ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <span class="status-badge <?php echo htmlspecialchars($playerMedicalReportStatusClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($playerMedicalReportStatusText, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </td>
                                <td data-label="عدد النجوم">
                                    <div class="stacked-cell">
                                        <strong><?php echo $playerHasStars ? htmlspecialchars((string) $playerStarsCount, ENT_QUOTES, 'UTF-8') : '—'; ?></strong>
                                        <span><?php echo $playerHasStars ? htmlspecialchars((string) ($player['stars_text'] ?? '—'), ENT_QUOTES, 'UTF-8') : 'لا توجد نجوم'; ?></span>
                                    </div>
                                </td>
                                <td data-label="تاريخ آخر نجمة"><span class="table-cell-text table-cell-date"><?php echo htmlspecialchars($playerHasStars ? $playerLastStarDate : '—', ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="بداية الاشتراك"><span class="table-cell-text table-cell-date"><?php echo htmlspecialchars(formatAcademyPlayerDate((string) ($player['subscription_start_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="نهاية الاشتراك"><span class="table-cell-text table-cell-date"><?php echo htmlspecialchars(formatAcademyPlayerDate((string) ($player['subscription_end_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="حالة الاشتراك">
                                    <span class="status-badge <?php echo !empty($player['is_expired']) ? 'expired' : 'active'; ?>">
                                        <?php echo htmlspecialchars((string) ($player['subscription_status_label'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td data-label="المدرب"><span class="table-cell-text"><?php echo htmlspecialchars((string) ($player['subscription_coach_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="أيام التمرين الوقت واليوم"><span class="table-cell-text table-cell-multiline"><?php echo htmlspecialchars(formatAcademyTrainingSchedule((string) ($player['subscription_training_schedule'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="التمارين المتاحة"><span class="table-cell-text"><?php echo (int) ($player['available_exercises_count'] ?? 0); ?></span></td>
                                <td data-label="الإجمالي"><span class="amount-badge total"><?php echo formatAcademyPlayerMoney($player['subscription_amount'] ?? 0); ?> ج.م</span></td>
                                <td data-label="المدفوع"><span class="amount-badge collected"><?php echo formatAcademyPlayerMoney($player['paid_amount'] ?? 0); ?> ج.م</span></td>
                                <td data-label="رقم إيصال الدفع"><span class="table-cell-text"><?php echo htmlspecialchars((string) ($player['receipt_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="المتبقي"><span class="amount-badge remaining <?php echo !empty($player['has_balance']) ? 'has-value' : ''; ?>"><?php echo formatAcademyPlayerMoney($player['remaining_amount'] ?? 0); ?> ج.م</span></td>
                                <td data-label="رقم إيصال سداد المتبقي"><span class="table-cell-text table-cell-multiline"><?php echo htmlspecialchars((string) (($player['settlement_receipt_numbers'] ?? '') !== '' ? $player['settlement_receipt_numbers'] : '—'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="الإجراءات">
                                    <div class="action-buttons">
                                        <?php if ((float) ($player['remaining_amount'] ?? 0) > 0): ?>
                                            <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl(array_merge($currentFilterParams, ['pay' => $player['id']])), ENT_QUOTES, 'UTF-8'); ?>#collect-payment-card" class="pay-btn">سداد</a>
                                        <?php else: ?>
                                            <span class="action-disabled">مسدد</span>
                                        <?php endif; ?>
                                        <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl(array_merge($currentFilterParams, ['edit' => $player['id']])), ENT_QUOTES, 'UTF-8'); ?>" class="edit-btn">تعديل</a>
                                        <?php if ($playerHasStarsCategory): ?>
                                            <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl(array_merge($currentFilterParams, ['stars' => $player['id']])), ENT_QUOTES, 'UTF-8'); ?>#player-stars-card" class="link-btn" aria-label="<?php echo htmlspecialchars('تحديث نجمة ' . (string) ($player['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">النجمة</a>
                                        <?php endif; ?>
                                        <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl(array_merge($currentFilterParams, ['files' => $player['id']])), ENT_QUOTES, 'UTF-8'); ?>#player-files-card" class="link-btn files-btn">ملفات السباح</a>
                                        <a href="<?php echo htmlspecialchars(buildAcademyPlayersPageUrl(array_merge($currentFilterParams, ['password' => $player['id']])), ENT_QUOTES, 'UTF-8'); ?>#player-password-card" class="link-btn">كلمة السر</a>
                                        <form method="POST" class="inline-form delete-player-form">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="player_id" value="<?php echo (int) ($player['id'] ?? 0); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($academyPlayersCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="current_search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="current_subscription_id" value="<?php echo htmlspecialchars($filters['subscription_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="current_branch" value="<?php echo htmlspecialchars($filters['branch'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="current_category" value="<?php echo htmlspecialchars($filters['category'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($filters['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="current_medical_report" value="<?php echo htmlspecialchars($filters['medical_report'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="current_page" value="<?php echo $currentPlayersPage; ?>">
                                            <button type="submit" class="delete-btn">حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo ACADEMY_PLAYERS_TABLE_COLUMNS_COUNT; ?>" class="empty-row">لا توجد نتائج مطابقة.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPlayersCount > 0): ?>
                <?php
                $tablePageStart = (($currentPlayersPage - 1) * ACADEMY_PLAYERS_PER_PAGE) + 1;
                $tablePageEnd = min($totalPlayersCount, $currentPlayersPage * ACADEMY_PLAYERS_PER_PAGE);
                ?>
                <div class="table-pagination-summary">
                    عرض <?php echo $tablePageStart; ?> - <?php echo $tablePageEnd; ?> من <?php echo $totalPlayersCount; ?> سباح
                </div>
            <?php endif; ?>
            <?php renderAcademyPlayersPagination($currentFilterParams, $currentPlayersPage, $totalPlayersPages); ?>
        </section>

        <?php renderAcademyPlayersHorizontalToolbar($filters, $subscriptions, $branchOptions, $categoryOptions, $summaryCategory, $currentFilterParams, 'bottom', $summaryPageUrl); ?>
    <?php endif; ?>
</div>
<script src="assets/js/academy-players.js"></script>
</body>
</html>
