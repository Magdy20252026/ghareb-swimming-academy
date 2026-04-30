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

if (!userCanAccess($currentUser, 'subscriptions')) {
    header('Location: dashboard.php?access=denied');
    exit;
}

const SUBSCRIPTIONS_PAGE_FILE = 'subscriptions.php';
const SUBSCRIPTIONS_DUPLICATE_KEY_ERROR = 1062;
const SUBSCRIPTIONS_MAX_TRAINING_DAYS = 7;
const SUBSCRIPTION_DEFAULT_DISPLAY_VALUE = '—';
const SUBSCRIPTION_CATEGORIES = [
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
const SUBSCRIPTION_BRANCHES = [
    'بلووتر',
    'دولفين',
];
const SUBSCRIPTION_WEEK_DAYS = [
    'saturday' => 'السبت',
    'sunday' => 'الأحد',
    'monday' => 'الإثنين',
    'tuesday' => 'الثلاثاء',
    'wednesday' => 'الأربعاء',
    'thursday' => 'الخميس',
    'friday' => 'الجمعة',
];
const SUBSCRIPTIONS_EXPORT_HEADERS = [
    'المجموعة',
    'الفرع',
    'المستوى',
    'المدرب',
    'عدد أيام التمرين',
    'عدد التمارين المتاحة',
    'الجدول',
    'الحد الأقصى',
    'السعر',
];

function normalizeSubscriptionsArabicNumbers(string $value): string
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

function sanitizeSubscriptionText(string $value): string
{
    $value = trim($value);
    $sanitizedValue = preg_replace('/\s+/u', ' ', $value);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function sanitizeSubscriptionDecimal(string $value): string
{
    $value = trim(normalizeSubscriptionsArabicNumbers($value));
    return str_replace(',', '.', $value);
}

function isValidSubscriptionDecimal(string $value): bool
{
    return $value !== ''
        && preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1
        && (float) $value > 0;
}

function formatSubscriptionDecimal(int|float|string $value): string
{
    return number_format((float) $value, 2, '.', '');
}

function formatSubscriptionMoney(int|float|string $value): string
{
    return number_format((float) $value, 2);
}

function sanitizeSubscriptionInteger(string $value): int
{
    $value = trim(normalizeSubscriptionsArabicNumbers($value));
    return ctype_digit($value) ? (int) $value : 0;
}

function buildSubscriptionsPageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return SUBSCRIPTIONS_PAGE_FILE . ($queryString !== '' ? '?' . $queryString : '');
}

function generateSubscriptionsSecurityToken(): string
{
    try {
        return bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        error_log(sprintf(
            'تعذر إنشاء رمز أمان لإدارة المجموعات [%s:%s] %s',
            get_class($exception),
            (string) $exception->getCode(),
            $exception->getMessage()
        ));
        if (function_exists('openssl_random_pseudo_bytes')) {
            $isStrong = false;
            $randomBytes = openssl_random_pseudo_bytes(32, $isStrong);
            if ($randomBytes !== false && $isStrong) {
                return bin2hex($randomBytes);
            }
        }
    }

    throw new RuntimeException('تعذر إنشاء رمز أمان للمجموعات');
}

function getSubscriptionsCsrfToken(): string
{
    if (
        !isset($_SESSION['subscriptions_csrf_token'])
        || !is_string($_SESSION['subscriptions_csrf_token'])
        || $_SESSION['subscriptions_csrf_token'] === ''
    ) {
        try {
            $_SESSION['subscriptions_csrf_token'] = generateSubscriptionsSecurityToken();
        } catch (Throwable $exception) {
            error_log(sprintf(
                'تعذر إنشاء رمز التحقق الخاص بإدارة المجموعات [%s:%s] %s',
                get_class($exception),
                (string) $exception->getCode(),
                $exception->getMessage()
            ));
            http_response_code(500);
            exit('❌ تعذر تهيئة أمان الصفحة، يرجى إعادة المحاولة لاحقًا');
        }
    }

    return $_SESSION['subscriptions_csrf_token'];
}

function isValidSubscriptionsCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getSubscriptionsCsrfToken(), $submittedToken);
}

function isValidSubscriptionCategory(string $value): bool
{
    return in_array($value, SUBSCRIPTION_CATEGORIES, true);
}

function isValidSubscriptionBranch(string $value): bool
{
    return in_array($value, SUBSCRIPTION_BRANCHES, true);
}

function isValidSubscriptionWeekDay(string $value): bool
{
    return array_key_exists($value, SUBSCRIPTION_WEEK_DAYS);
}

function isValidSubscriptionTimeValue(string $value): bool
{
    return normalizeAcademyTimeInputValue($value) !== '';
}

function normalizeSubscriptionSchedule($selectedDays, $scheduleTimes, int $trainingDaysCount): array
{
    if (!is_array($selectedDays)) {
        return [];
    }

    $normalizedSchedule = [];
    $safeScheduleTimes = is_array($scheduleTimes) ? $scheduleTimes : [];

    foreach ($selectedDays as $dayKey) {
        if (!is_string($dayKey)) {
            continue;
        }

        $normalizedDayKey = trim($dayKey);
        if (!isValidSubscriptionWeekDay($normalizedDayKey) || isset($normalizedSchedule[$normalizedDayKey])) {
            continue;
        }

        $timeValue = trim((string) ($safeScheduleTimes[$normalizedDayKey] ?? ''));
        $normalizedTimeValue = normalizeAcademyTimeInputValue($timeValue);
        if ($normalizedTimeValue === '') {
            return [];
        }

        $normalizedSchedule[$normalizedDayKey] = [
            'key' => $normalizedDayKey,
            'label' => SUBSCRIPTION_WEEK_DAYS[$normalizedDayKey],
            'time' => formatAcademyTimeTo12Hour($normalizedTimeValue),
        ];
    }

    if (count($normalizedSchedule) !== $trainingDaysCount) {
        return [];
    }

    return array_values($normalizedSchedule);
}

function decodeSubscriptionSchedule(?string $value): array
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
        if (!isValidSubscriptionWeekDay($dayKey) || $timeValue === '') {
            continue;
        }

        $schedule[$dayKey] = [
            'key' => $dayKey,
            'label' => SUBSCRIPTION_WEEK_DAYS[$dayKey],
            'time' => $timeValue,
        ];
    }

    return array_values($schedule);
}

function buildSubscriptionsPlayerSnapshotSubquery(): string
{
    return '
        SELECT
            subscription_id,
            MAX(NULLIF(TRIM(subscription_name), \'\')) AS player_subscription_name,
            MAX(NULLIF(TRIM(subscription_branch), \'\')) AS player_subscription_branch,
            MAX(NULLIF(TRIM(subscription_category), \'\')) AS player_subscription_category
        FROM academy_players
        WHERE subscription_id IS NOT NULL
        GROUP BY subscription_id
    ';
}

function buildSubscriptionScheduleSummary(array $schedule): string
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

function exportSubscriptionsAsXlsx(array $subscriptions): void
{
    $rows = [];
    foreach ($subscriptions as $subscription) {
        $rows[] = [
            (string) ($subscription['subscription_name'] ?? ''),
            (string) ($subscription['subscription_branch'] ?? ''),
            (string) ($subscription['subscription_category'] ?? ''),
            (string) ($subscription['coach_name'] ?? ''),
            (string) ((int) ($subscription['training_days_count'] ?? 0)),
            (string) ((int) ($subscription['available_exercises_count'] ?? 0)),
            (string) ($subscription['schedule_summary'] ?? ''),
            (string) ((int) ($subscription['max_trainees'] ?? 0)),
            formatSubscriptionMoney($subscription['subscription_price'] ?? 0),
        ];
    }

    $temporaryFile = createAcademyXlsxFile(
        SUBSCRIPTIONS_EXPORT_HEADERS,
        $rows,
        'المجموعات',
        'المجموعات'
    );
    outputAcademyXlsxDownload($temporaryFile, 'subscriptions-' . date('Y-m-d') . '.xlsx');
}

function fetchSubscriptionCoaches(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, full_name FROM coaches ORDER BY full_name ASC, id ASC');
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function fetchSubscriptionCoachLookup(array $coaches): array
{
    $lookup = [];
    foreach ($coaches as $coach) {
        $coachId = (int) ($coach['id'] ?? 0);
        $coachName = sanitizeSubscriptionText((string) ($coach['full_name'] ?? ''));
        if ($coachId > 0 && $coachName !== '') {
            $lookup[$coachId] = $coachName;
        }
    }

    return $lookup;
}

function fetchSubscriptionById(PDO $pdo, string $id): ?array
{
    if ($id === '' || !ctype_digit($id)) {
        return null;
    }

    $snapshotSubquery = buildSubscriptionsPlayerSnapshotSubquery();
    $stmt = $pdo->prepare('
        SELECT
            s.*,
            COALESCE(c.full_name, "") AS coach_name,
            aps.player_subscription_name,
            aps.player_subscription_branch,
            aps.player_subscription_category
        FROM subscriptions s
        LEFT JOIN coaches c ON c.id = s.coach_id
        LEFT JOIN (' . $snapshotSubquery . ') aps ON aps.subscription_id = s.id
        WHERE s.id = ?
        LIMIT 1
    ');
    $stmt->execute([$id]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

    return $subscription ?: null;
}

function buildSubscriptionsRegisteredSwimmersCountSubquery(): string
{
    return '
        SELECT
            subscription_id,
            SUM(CASE WHEN subscription_end_date >= CURDATE() AND available_exercises_count > 0 THEN 1 ELSE 0 END) AS registered_swimmers_count
        FROM academy_players
        GROUP BY subscription_id
    ';
}

function isSubscriptionPlaceholderText(string $value): bool
{
    $normalizedValue = sanitizeSubscriptionText($value);
    if ($normalizedValue === '') {
        return false;
    }

    // النمط التالي يحذف الفواصل والشرطات وأرقام الوقت الغربية/العربية ورموز AM/PM حتى لا تُحتسب عند اكتشاف النصوص التالفة.
    $timeAndPunctuationNoisePattern = '/[\s\p{Pd}•.,،:\/\\\\()0-9٠-٩۰-۹AaPpMmصم]+/u';
    $placeholderCandidate = preg_replace($timeAndPunctuationNoisePattern, '', $normalizedValue);
    if ($placeholderCandidate === null) {
        error_log('تعذر تنفيذ preg_replace بسبب خطأ في محرك PCRE أثناء فحص بيانات المجموعة: ' . formatSubscriptionLogValue($normalizedValue));
        return false;
    }

    $containsReplacementCharacter = subscriptionTextContains($placeholderCandidate, '�');
    if ($containsReplacementCharacter) {
        error_log('تم اكتشاف محرف الاستبدال في بيانات المجموعة أثناء فحص الرموز غير المفهومة: ' . formatSubscriptionLogValue($normalizedValue));
    }

    // وجود محرف الاستبدال � يعني أن النص وصل تالفًا من بيانات قديمة، لذلك نعامله كقيمة placeholder غير صالحة للعرض.
    return preg_match('/^[\?؟�]+$/u', $placeholderCandidate) === 1;
}

function formatSubscriptionLogValue(string $value): string
{
    $encodedValue = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encodedValue !== false ? $encodedValue : '[unencodable-subscription-value]';
}

function subscriptionTextContains(string $value, string $needle): bool
{
    static $supportsMbString = null;
    if ($supportsMbString === null) {
        $supportsMbString = function_exists('mb_strpos');
    }

    return $supportsMbString
        ? mb_strpos($value, $needle) !== false
        : strpos($value, $needle) !== false;
}

function pickSubscriptionDisplayText(array $values): string
{
    foreach ($values as $value) {
        $normalizedValue = sanitizeSubscriptionText((string) $value);
        if ($normalizedValue !== '' && !isSubscriptionPlaceholderText($normalizedValue)) {
            return $normalizedValue;
        }
    }

    // نحتفظ بآخر قيمة غير فارغة كخيار أخير حتى لا تظهر الخلية فارغة بالكامل عندما تكون كل المصادر القديمة تالفة.
    foreach ($values as $value) {
        $normalizedValue = sanitizeSubscriptionText((string) $value);
        if ($normalizedValue !== '') {
            return $normalizedValue;
        }
    }

    return '';
}

function resolveSubscriptionKnownValue(array $values, array $allowedValues): string
{
    foreach ($values as $value) {
        $normalizedValue = sanitizeSubscriptionText((string) $value);
        if ($normalizedValue === '') {
            continue;
        }

        foreach ($allowedValues as $allowedValue) {
            if ($normalizedValue === $allowedValue) {
                return $allowedValue;
            }
        }
    }

    foreach ($values as $value) {
        $normalizedValue = sanitizeSubscriptionText((string) $value);
        if ($normalizedValue === '' || isSubscriptionPlaceholderText($normalizedValue)) {
            continue;
        }

        foreach ($allowedValues as $allowedValue) {
            if (subscriptionTextContains($normalizedValue, $allowedValue)) {
                return $allowedValue;
            }
        }
    }

    foreach ($values as $value) {
        $normalizedValue = sanitizeSubscriptionText((string) $value);
        if ($normalizedValue !== '' && !isSubscriptionPlaceholderText($normalizedValue)) {
            return $normalizedValue;
        }
    }

    return '';
}

function normalizeSubscriptionRecordForDisplay(array $subscription, array $coachLookup): array
{
    $coachId = (int) ($subscription['coach_id'] ?? 0);
    $coachName = pickSubscriptionDisplayText([
        (string) ($subscription['coach_name'] ?? ''),
        (string) ($coachLookup[$coachId] ?? ''),
    ]);
    $storedNameSources = [
        (string) ($subscription['subscription_name'] ?? ''),
        (string) ($subscription['player_subscription_name'] ?? ''),
    ];
    $fallbackDisplayName = pickSubscriptionDisplayText($storedNameSources);
    $resolvedBranch = resolveSubscriptionKnownValue([
        (string) ($subscription['subscription_branch'] ?? ''),
        (string) ($subscription['player_subscription_branch'] ?? ''),
        ...$storedNameSources,
    ], SUBSCRIPTION_BRANCHES);
    $resolvedCategory = resolveSubscriptionKnownValue([
        (string) ($subscription['subscription_category'] ?? ''),
        (string) ($subscription['player_subscription_category'] ?? ''),
        ...$storedNameSources,
    ], SUBSCRIPTION_CATEGORIES);

    $subscription['coach_name'] = $coachName;
    $subscription['subscription_branch'] = $resolvedBranch;
    $subscription['subscription_category'] = $resolvedCategory;
    $subscription['subscription_branch_display'] = $resolvedBranch !== '' ? $resolvedBranch : SUBSCRIPTION_DEFAULT_DISPLAY_VALUE;
    $subscription['subscription_category_display'] = $resolvedCategory !== '' ? $resolvedCategory : SUBSCRIPTION_DEFAULT_DISPLAY_VALUE;
    $subscription['subscription_name'] = buildAcademySubscriptionName(
        $resolvedCategory,
        $coachName,
        (string) ($subscription['schedule_summary'] ?? ''),
        $resolvedBranch,
        $fallbackDisplayName
    );

    return $subscription;
}

function getSubscriptionMysqlDriverErrorCode(PDOException $exception): int
{
    $errorInfo = $exception->errorInfo;

    if (is_array($errorInfo) && isset($errorInfo[1]) && is_numeric($errorInfo[1])) {
        return (int) $errorInfo[1];
    }

    return is_numeric($exception->getCode()) ? (int) $exception->getCode() : 0;
}

function resolveSubscriptionsFormData(
    string $submittedAction,
    string $messageType,
    ?array $submittedSubscriptionFormData,
    ?array $editSubscription
): array {
    if ($submittedAction === 'save' && $messageType === 'error' && is_array($submittedSubscriptionFormData)) {
        return $submittedSubscriptionFormData;
    }

    return is_array($editSubscription) ? $editSubscription : [];
}

function buildSubscriptionScheduleFromSubmittedData(array $submittedScheduleLookup): array
{
    $schedule = [];

    foreach ($submittedScheduleLookup as $dayKey => $timeValue) {
        if (!is_string($dayKey) || !isValidSubscriptionWeekDay($dayKey)) {
            continue;
        }

        $normalizedTimeValue = normalizeAcademyTimeInputValue((string) $timeValue);
        $schedule[] = [
            'key' => $dayKey,
            'label' => SUBSCRIPTION_WEEK_DAYS[$dayKey],
            'time' => $normalizedTimeValue !== '' ? formatAcademyTimeTo12Hour($normalizedTimeValue) : '',
        ];
    }

    return $schedule;
}

$message = '';
$messageType = '';
$editSubscription = null;
$submittedAction = '';
$submittedSubscriptionFormData = null;
$submittedScheduleLookup = [];
$coaches = fetchSubscriptionCoaches($pdo);
$coachLookup = fetchSubscriptionCoachLookup($coaches);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $submittedAction = is_string($action) ? $action : '';
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!isValidSubscriptionsCsrfToken($submittedToken)) {
        $message = '❌ تعذر التحقق من الطلب، يرجى إعادة المحاولة.';
        $messageType = 'error';
    } elseif ($action === 'save') {
        $id = trim((string) ($_POST['id'] ?? ''));
        $submittedSubscriptionName = sanitizeSubscriptionText((string) ($_POST['subscription_name'] ?? ''));
        $subscriptionBranch = sanitizeSubscriptionText((string) ($_POST['subscription_branch'] ?? ''));
        $subscriptionCategory = sanitizeSubscriptionText((string) ($_POST['subscription_category'] ?? ''));
        $trainingDaysCount = sanitizeSubscriptionInteger((string) ($_POST['training_days_count'] ?? ''));
        $availableExercisesCount = sanitizeSubscriptionInteger((string) ($_POST['available_exercises_count'] ?? ''));
        $selectedDays = $_POST['training_days'] ?? [];
        $submittedScheduleTimes = is_array($_POST['schedule_time'] ?? null) ? $_POST['schedule_time'] : [];
        $coachId = sanitizeSubscriptionInteger((string) ($_POST['coach_id'] ?? ''));
        $maxTrainees = sanitizeSubscriptionInteger((string) ($_POST['max_trainees'] ?? ''));
        $subscriptionPriceInput = sanitizeSubscriptionDecimal((string) ($_POST['subscription_price'] ?? ''));
        $trainingSchedule = normalizeSubscriptionSchedule($selectedDays, $submittedScheduleTimes, $trainingDaysCount);
        $scheduleSummary = buildSubscriptionScheduleSummary($trainingSchedule);
        $coachName = $coachLookup[$coachId] ?? '';
        $subscriptionName = buildAcademySubscriptionName(
            $subscriptionCategory,
            $coachName,
            $scheduleSummary,
            $subscriptionBranch,
            $submittedSubscriptionName
        );

        $selectedDayLookup = [];
        if (is_array($selectedDays)) {
            foreach ($selectedDays as $dayKey) {
                if (!is_string($dayKey)) {
                    continue;
                }

                $normalizedDayKey = trim($dayKey);
                if (!isValidSubscriptionWeekDay($normalizedDayKey) || isset($selectedDayLookup[$normalizedDayKey])) {
                    continue;
                }

                $selectedDayLookup[$normalizedDayKey] = normalizeAcademyTimeInputValue((string) ($submittedScheduleTimes[$normalizedDayKey] ?? ''));
            }
        }

        $submittedSubscriptionFormData = [
            'id' => $id,
            'subscription_name' => $subscriptionName,
            'subscription_branch' => $subscriptionBranch,
            'subscription_category' => $subscriptionCategory,
            'training_days_count' => $trainingDaysCount > 0 ? $trainingDaysCount : 1,
            'available_exercises_count' => $availableExercisesCount > 0 ? $availableExercisesCount : 1,
            'coach_id' => $coachId,
            'max_trainees' => $maxTrainees > 0 ? $maxTrainees : '',
            'subscription_price' => $subscriptionPriceInput !== '' ? $subscriptionPriceInput : '0.00',
        ];
        $submittedScheduleLookup = $selectedDayLookup;

        if (!isValidSubscriptionCategory($subscriptionCategory)) {
            $message = '❌ يرجى اختيار تصنيف مجموعة صحيح.';
            $messageType = 'error';
        } elseif (!isValidSubscriptionBranch($subscriptionBranch)) {
            $message = '❌ يرجى اختيار فرع صحيح.';
            $messageType = 'error';
        } elseif ($trainingDaysCount < 1 || $trainingDaysCount > SUBSCRIPTIONS_MAX_TRAINING_DAYS) {
            $message = '❌ يرجى اختيار عدد أيام تمرين صحيح.';
            $messageType = 'error';
        } elseif ($availableExercisesCount < 1) {
            $message = '❌ يرجى إدخال عدد التمارين المتاحة بشكل صحيح.';
            $messageType = 'error';
        } elseif ($trainingSchedule === []) {
            $message = '❌ يرجى اختيار أيام التمرين وإدخال ساعة صحيحة لكل يوم.';
            $messageType = 'error';
        } elseif (!isset($coachLookup[$coachId])) {
            $message = '❌ يرجى اختيار مدرب مسجل.';
            $messageType = 'error';
        } elseif ($maxTrainees <= 0) {
            $message = '❌ يرجى إدخال أقصى عدد سباحين بشكل صحيح.';
            $messageType = 'error';
        } elseif (!isValidSubscriptionDecimal($subscriptionPriceInput)) {
            $message = '❌ يرجى إدخال سعر المجموعة بشكل صحيح.';
            $messageType = 'error';
        } elseif ($subscriptionName === '') {
            $message = '❌ تعذر توليد اسم المجموعة، يرجى مراجعة البيانات المختارة.';
            $messageType = 'error';
        } else {
            try {
                $payload = [
                    $subscriptionName,
                    $subscriptionBranch,
                    $subscriptionCategory,
                    $trainingDaysCount,
                    $availableExercisesCount,
                    json_encode($trainingSchedule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $coachId,
                    $maxTrainees,
                    formatSubscriptionDecimal($subscriptionPriceInput),
                ];

                if ($id === '') {
                    $insertStmt = $pdo->prepare('INSERT INTO subscriptions (subscription_name, subscription_branch, subscription_category, training_days_count, available_exercises_count, training_schedule, coach_id, max_trainees, subscription_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $insertStmt->execute($payload);
                    $message = '✅ تم إضافة المجموعة بنجاح.';
                    $messageType = 'success';
                } else {
                    $updateStmt = $pdo->prepare('UPDATE subscriptions SET subscription_name = ?, subscription_branch = ?, subscription_category = ?, training_days_count = ?, available_exercises_count = ?, training_schedule = ?, coach_id = ?, max_trainees = ?, subscription_price = ? WHERE id = ?');
                    $payload[] = $id;
                    $updateStmt->execute($payload);

                    if ($updateStmt->rowCount() > 0) {
                        $message = '✏️ تم تحديث بيانات المجموعة بنجاح.';
                        $messageType = 'success';
                    } else {
                        $existingSubscription = fetchSubscriptionById($pdo, $id);
                        if ($existingSubscription !== null) {
                            $message = '✅ لا توجد تغييرات جديدة على بيانات المجموعة.';
                            $messageType = 'success';
                        } else {
                            $message = '❌ المجموعة المطلوبة غير موجودة.';
                            $messageType = 'error';
                        }
                    }
                }
            } catch (PDOException $exception) {
                $sqlErrorCode = getSubscriptionMysqlDriverErrorCode($exception);
                $message = $sqlErrorCode === SUBSCRIPTIONS_DUPLICATE_KEY_ERROR
                    ? '❌ بيانات المجموعة مسجلة بالفعل.'
                    : '❌ حدث خطأ أثناء حفظ بيانات المجموعة.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $id = trim((string) ($_POST['id'] ?? ''));

        if ($id === '' || !ctype_digit($id)) {
            $message = '❌ المجموعة المطلوبة غير صالحة.';
            $messageType = 'error';
        } else {
            try {
                $deleteStmt = $pdo->prepare('DELETE FROM subscriptions WHERE id = ?');
                $deleteStmt->execute([$id]);

                if ($deleteStmt->rowCount() > 0) {
                    $message = '🗑️ تم حذف المجموعة بنجاح.';
                    $messageType = 'success';
                } else {
                    $message = '❌ المجموعة المطلوبة غير موجودة.';
                    $messageType = 'error';
                }
            } catch (PDOException $exception) {
                $message = '❌ حدث خطأ أثناء حذف المجموعة.';
                $messageType = 'error';
            }
        }
    }
}

if (isset($_GET['edit'])) {
    $editSubscription = fetchSubscriptionById($pdo, trim((string) $_GET['edit']));

    if ($editSubscription === null && $message === '') {
        $message = '❌ المجموعة المطلوبة غير موجودة.';
        $messageType = 'error';
    } elseif (is_array($editSubscription)) {
        $editSubscription['schedule_items'] = decodeSubscriptionSchedule($editSubscription['training_schedule'] ?? null);
        $editSubscription['schedule_summary'] = buildSubscriptionScheduleSummary($editSubscription['schedule_items']);
        $editSubscription = normalizeSubscriptionRecordForDisplay($editSubscription, $coachLookup);
    }
}

$registeredSwimmersCountSubquery = buildSubscriptionsRegisteredSwimmersCountSubquery();
$playerSnapshotSubquery = buildSubscriptionsPlayerSnapshotSubquery();

$statsStmt = $pdo->query('
    SELECT
        COUNT(*) AS total_subscriptions,
        COALESCE(SUM(s.max_trainees), 0) AS total_capacity,
        COUNT(DISTINCT s.subscription_category) AS total_categories,
        COUNT(DISTINCT s.coach_id) AS total_coaches,
        COALESCE(SUM(COALESCE(ap.registered_swimmers_count, 0)), 0) AS total_registered_swimmers
    FROM subscriptions s
    LEFT JOIN (' . $registeredSwimmersCountSubquery . ') ap ON ap.subscription_id = s.id
');
$subscriptionsStats = $statsStmt ? $statsStmt->fetch(PDO::FETCH_ASSOC) : [];

$subscriptionsStmt = $pdo->query('
    SELECT
        s.*,
        c.full_name AS coach_name,
        aps.player_subscription_name,
        aps.player_subscription_branch,
        aps.player_subscription_category,
        COALESCE(ap.registered_swimmers_count, 0) AS registered_swimmers_count
    FROM subscriptions s
    LEFT JOIN coaches c ON c.id = s.coach_id
    LEFT JOIN (' . $playerSnapshotSubquery . ') aps ON aps.subscription_id = s.id
    LEFT JOIN (' . $registeredSwimmersCountSubquery . ') ap ON ap.subscription_id = s.id
    ORDER BY s.updated_at DESC, s.id DESC
');
$subscriptions = $subscriptionsStmt ? $subscriptionsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

foreach ($subscriptions as &$subscription) {
    $subscription['schedule_items'] = decodeSubscriptionSchedule($subscription['training_schedule'] ?? null);
    $subscription['schedule_summary'] = buildSubscriptionScheduleSummary($subscription['schedule_items']);
    $subscription = normalizeSubscriptionRecordForDisplay($subscription, $coachLookup);
}
unset($subscription);

if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    exportSubscriptionsAsXlsx($subscriptions);
}

$subscriptionFormData = resolveSubscriptionsFormData(
    $submittedAction,
    $messageType,
    $submittedSubscriptionFormData,
    $editSubscription
);
$isEditingSubscription = isset($subscriptionFormData['id']) && trim((string) ($subscriptionFormData['id'] ?? '')) !== '';
$shouldAutoOpenSubscriptionModal = $isEditingSubscription || ($submittedAction === 'save' && $messageType === 'error');
$shouldResetSubscriptionModalOnClose = $isEditingSubscription;
$editSchedule = $submittedAction === 'save' && $messageType === 'error'
    ? buildSubscriptionScheduleFromSubmittedData($submittedScheduleLookup)
    : ($editSubscription ? decodeSubscriptionSchedule($editSubscription['training_schedule'] ?? null) : []);
$editScheduleLookup = [];
if ($submittedAction === 'save' && $messageType === 'error') {
    $editScheduleLookup = $submittedScheduleLookup;
} else {
    foreach ($editSchedule as $scheduleItem) {
        $dayKey = $scheduleItem['key'] ?? '';
        if (is_string($dayKey) && $dayKey !== '') {
            $editScheduleLookup[$dayKey] = normalizeAcademyTimeInputValue((string) ($scheduleItem['time'] ?? ''));
        }
    }
}

$editSubscriptionScheduleSummary = buildSubscriptionScheduleSummary($editSchedule);
$editSubscriptionGeneratedName = $subscriptionFormData !== []
    ? buildAcademySubscriptionName(
        (string) ($subscriptionFormData['subscription_category'] ?? ''),
        (string) ($coachLookup[(int) ($subscriptionFormData['coach_id'] ?? 0)] ?? ''),
        $editSubscriptionScheduleSummary,
        (string) ($subscriptionFormData['subscription_branch'] ?? ''),
        (string) ($subscriptionFormData['subscription_name'] ?? '')
    )
    : '';

$subscriptionsCsrfToken = getSubscriptionsCsrfToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المجموعات</title>
    <link rel="stylesheet" href="assets/css/subscriptions.css">
</head>
<body
    class="light-mode"
    data-page-url="<?php echo htmlspecialchars(SUBSCRIPTIONS_PAGE_FILE, ENT_QUOTES, 'UTF-8'); ?>"
    data-form-close-url="<?php echo htmlspecialchars(SUBSCRIPTIONS_PAGE_FILE, ENT_QUOTES, 'UTF-8'); ?>"
    data-form-modal-open="<?php echo $shouldAutoOpenSubscriptionModal ? '1' : '0'; ?>"
    data-form-modal-reset-page="<?php echo $shouldResetSubscriptionModalOnClose ? '1' : '0'; ?>"
>
<div class="subscriptions-page">
    <header class="page-header">
        <div class="header-text">
            <span class="eyebrow">📋 إدارة المجموعات</span>
            <h1>إدارة المجموعات</h1>
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

            <a href="dashboard.php" class="back-btn">⬅️ الرجوع</a>
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
            <h2>المجموعات المسجلة</h2>
        </article>

        <article class="hero-card">
            <span>إجمالي المجموعات</span>
            <strong><?php echo (int) ($subscriptionsStats['total_subscriptions'] ?? 0); ?></strong>
        </article>

        <article class="hero-card">
            <span>المدربون المرتبطون</span>
            <strong><?php echo (int) ($subscriptionsStats['total_coaches'] ?? 0); ?></strong>
        </article>

        <article class="hero-card">
            <span>السباحين المسجلين / السعة الكلية</span>
            <strong><?php echo (int) ($subscriptionsStats['total_registered_swimmers'] ?? 0); ?> / <?php echo (int) ($subscriptionsStats['total_capacity'] ?? 0); ?></strong>
        </article>

        <article class="hero-card">
            <span>عدد المستويات</span>
            <strong><?php echo (int) ($subscriptionsStats['total_categories'] ?? 0); ?></strong>
        </article>
    </section>

    <div class="modal-overlay hidden" id="subscriptionFormModal" role="dialog" aria-modal="true" aria-labelledby="subscriptionFormModalTitle">
        <div class="modal-shell">
            <div class="form-card modal-form-card">
                <div class="card-head modal-card-head">
                    <h2 id="subscriptionFormModalTitle"><?php echo $isEditingSubscription ? '✏️ تعديل مجموعة' : '➕ إضافة مجموعة'; ?></h2>
                    <button type="button" class="modal-close-btn" data-close-subscription-modal>إغلاق</button>
                </div>

                <form method="POST" id="subscriptionsForm" class="subscriptions-form" autocomplete="off">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="id" value="<?php echo htmlspecialchars((string) ($subscriptionFormData['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($subscriptionsCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="client-message" id="clientMessage" aria-live="assertive" hidden></div>

                <div class="form-grid form-grid-double">
                    <div class="form-group">
                        <label for="subscription_name">🏷️ اسم المجموعة</label>
                        <input type="text" name="subscription_name" id="subscription_name" value="<?php echo htmlspecialchars($editSubscriptionGeneratedName, ENT_QUOTES, 'UTF-8'); ?>" readonly aria-describedby="subscription_name_note">
                        <small id="subscription_name_note">يتم توليد الاسم تلقائيًا من المستوى والمدرب والجدول والفرع.</small>
                    </div>

                    <div class="form-group">
                        <label for="subscription_category">🧩 مستوى المجموعة</label>
                        <div class="select-shell">
                            <select name="subscription_category" id="subscription_category" required>
                                <option value="">اختر المستوى</option>
                                <?php foreach (SUBSCRIPTION_CATEGORIES as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($subscriptionFormData['subscription_category'] ?? '') === $category) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="subscription_branch">📍 الفرع</label>
                        <div class="select-shell">
                            <select name="subscription_branch" id="subscription_branch" required>
                                <option value="">اختر الفرع</option>
                                <?php foreach (SUBSCRIPTION_BRANCHES as $branch): ?>
                                    <option value="<?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($subscriptionFormData['subscription_branch'] ?? '') === $branch) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="training_days_count">📅 عدد أيام التمرين</label>
                        <div class="select-shell">
                            <select name="training_days_count" id="training_days_count" required>
                                <?php for ($count = 1; $count <= SUBSCRIPTIONS_MAX_TRAINING_DAYS; $count++): ?>
                                    <option value="<?php echo $count; ?>" <?php echo ((int) ($subscriptionFormData['training_days_count'] ?? 1) === $count) ? 'selected' : ''; ?>>
                                        <?php echo $count; ?> يوم
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="available_exercises_count">🎽 عدد التمارين المتاحة</label>
                        <input type="number" min="1" step="1" name="available_exercises_count" id="available_exercises_count" value="<?php echo htmlspecialchars((string) ($subscriptionFormData['available_exercises_count'] ?? 1), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="coach_id">🏅 المدرب</label>
                        <div class="select-shell">
                            <select name="coach_id" id="coach_id" required>
                                <option value="">اختر المدرب</option>
                                <?php foreach ($coaches as $coach): ?>
                                    <?php $coachId = (int) ($coach['id'] ?? 0); ?>
                                    <option value="<?php echo $coachId; ?>" <?php echo ((int) ($subscriptionFormData['coach_id'] ?? 0) === $coachId) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) ($coach['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="max_trainees">👥 أقصى عدد سباحين</label>
                        <input type="number" min="1" step="1" name="max_trainees" id="max_trainees" value="<?php echo htmlspecialchars((string) ($subscriptionFormData['max_trainees'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="subscription_price">💰 سعر المجموعة</label>
                        <input type="number" min="0.01" step="0.01" name="subscription_price" id="subscription_price" value="<?php echo htmlspecialchars(formatSubscriptionDecimal($subscriptionFormData['subscription_price'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>

                </div>

                <div class="schedule-card">
                    <div class="schedule-head">
                        <h3>أيام التمرين الأسبوعية</h3>
                        <span class="selection-badge"><strong id="selectedDaysCount"><?php echo count($editScheduleLookup); ?></strong>/<span id="allowedDaysCount"><?php echo (int) ($subscriptionFormData['training_days_count'] ?? 1); ?></span></span>
                    </div>

                    <div class="weekdays-grid">
                        <?php foreach (SUBSCRIPTION_WEEK_DAYS as $dayKey => $dayLabel): ?>
                            <?php $isChecked = array_key_exists($dayKey, $editScheduleLookup); ?>
                            <label class="day-option <?php echo $isChecked ? 'checked' : ''; ?>">
                                <input type="checkbox" name="training_days[]" value="<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isChecked ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="schedule-grid">
                        <?php foreach (SUBSCRIPTION_WEEK_DAYS as $dayKey => $dayLabel): ?>
                            <?php $dayTime = $editScheduleLookup[$dayKey] ?? ''; ?>
                            <div class="schedule-item <?php echo $dayTime !== '' ? 'is-active' : ''; ?>" data-day-key="<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>">
                                <label for="schedule_time_<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8'); ?></label>
                                <input type="time" name="schedule_time[<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>]" id="schedule_time_<?php echo htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string) $dayTime, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="save-btn"><?php echo $isEditingSubscription ? '💾 حفظ التعديل' : '✅ إضافة المجموعة'; ?></button>
                    <button type="button" class="clear-btn" id="clearBtn">🧹 تصفية الحقول</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <section class="table-card">
        <div class="card-head table-head">
            <div>
                <h2>📋 جدول المجموعات</h2>
            </div>
            <div class="table-head-actions">
                <span class="table-count"><?php echo count($subscriptions); ?> مجموعة</span>
                <button
                    type="button"
                    class="save-btn desktop-subscription-launcher"
                    data-open-subscription-modal
                    aria-label="إضافة مجموعة"
                    aria-haspopup="dialog"
                    aria-controls="subscriptionFormModal"
                    aria-expanded="false"
                >
                    إضافة مجموعة
                </button>
                <a href="<?php echo htmlspecialchars(buildSubscriptionsPageUrl(['export' => 'xlsx']), ENT_QUOTES, 'UTF-8'); ?>" class="back-btn">استخراج إكسل</a>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>المجموعة</th>
                        <th>الفرع</th>
                        <th>المستوى</th>
                        <th>المدرب</th>
                        <th>الأيام</th>
                        <th>التمارين المتاحة</th>
                        <th>الجدول</th>
                        <th>السباحين / الحد الأقصى</th>
                        <th>السعر</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($subscriptions !== []): ?>
                        <?php foreach ($subscriptions as $index => $subscription): ?>
                            <tr>
                                <td data-label="#"><?php echo $index + 1; ?></td>
                                <td data-label="المجموعة">
                                    <div class="subscription-cell">
                                        <span class="subscription-avatar">📋</span>
                                        <div>
                                            <strong><?php echo htmlspecialchars((string) ($subscription['subscription_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <span><?php echo htmlspecialchars((string) ($subscription['updated_at'] ?? $subscription['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="الفرع"><span class="soft-badge"><?php echo htmlspecialchars((string) $subscription['subscription_branch_display'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="المستوى"><span class="metric-badge"><?php echo htmlspecialchars((string) $subscription['subscription_category_display'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-label="المدرب"><?php echo htmlspecialchars((string) ($subscription['coach_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="الأيام"><span class="days-count"><?php echo (int) ($subscription['training_days_count'] ?? 0); ?> يوم</span></td>
                                <td data-label="التمارين المتاحة"><span class="soft-badge"><?php echo (int) ($subscription['available_exercises_count'] ?? 0); ?> تمرين</span></td>
                                <td data-label="الجدول">
                                    <div class="schedule-summary"><?php echo htmlspecialchars((string) ($subscription['schedule_summary'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td data-label="السباحين / الحد الأقصى"><span class="capacity-chip"><?php echo (int) ($subscription['registered_swimmers_count'] ?? 0); ?> / <?php echo (int) ($subscription['max_trainees'] ?? 0); ?> سباح</span></td>
                                <td data-label="السعر"><span class="soft-badge"><?php echo formatSubscriptionMoney($subscription['subscription_price'] ?? 0); ?> ج.م</span></td>
                                <td data-label="الإجراءات">
                                    <div class="action-buttons">
                                        <a href="<?php echo buildSubscriptionsPageUrl(['edit' => $subscription['id']]); ?>" class="edit-btn">✏️ تعديل</a>
                                        <form method="POST" class="inline-form delete-subscription-form">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars((string) ($subscription['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($subscriptionsCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" class="delete-btn">🗑️ حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="empty-row">لا توجد مجموعات مسجلة حاليًا.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<dialog class="confirm-dialog" id="deleteConfirmDialog" aria-labelledby="deleteConfirmTitle">
    <div class="confirm-dialog-content">
        <h2 id="deleteConfirmTitle">تأكيد الحذف</h2>
        <p id="deleteConfirmText">هل أنت متأكد من حذف هذه المجموعة؟</p>
        <div class="confirm-dialog-actions">
            <button type="button" class="clear-btn" id="cancelDeleteBtn">إلغاء</button>
            <button type="button" class="delete-btn" id="confirmDeleteBtn">تأكيد الحذف</button>
        </div>
    </div>
</dialog>
<script src="assets/js/subscriptions.js"></script>
</body>
</html>
