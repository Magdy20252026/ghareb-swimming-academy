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

if (!userCanAccess($currentUser, 'player_attendance')) {
    header('Location: dashboard.php?access=denied');
    exit;
}

define('PLAYER_ATTENDANCE_PAGE_FILE', basename(__FILE__));
const PLAYER_ATTENDANCE_STATUS_OPEN = 'open';
const PLAYER_ATTENDANCE_STATUS_CLOSED = 'closed';
const PLAYER_ATTENDANCE_RECORD_PRESENT = 'present';
const PLAYER_ATTENDANCE_RECORD_ABSENT = 'absent';
const PLAYER_ATTENDANCE_TIMEZONE = 'Africa/Cairo';
const PLAYER_ATTENDANCE_NOTE_MAX_LENGTH = 500;
const PLAYER_ATTENDANCE_SESSION_DUPLICATE_KEY_ERROR = 1062;
const PLAYER_ATTENDANCE_ABSENT_COUNT_ZERO = 0;
const PLAYER_ATTENDANCE_PLAYER_UPLOAD_PUBLIC_DIR = 'uploads/academy_players';
const PLAYER_ATTENDANCE_IMAGE_FILENAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*\.(?:jpe?g|png|gif|webp)$/i';
const PLAYER_ATTENDANCE_PRESENT_SWIMMERS_TABLE_COLUMN_COUNT = 27;

function playerAttendanceTimezone(): DateTimeZone
{
    static $timezone = null;

    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone(PLAYER_ATTENDANCE_TIMEZONE);
    }

    return $timezone;
}

function playerAttendanceNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', playerAttendanceTimezone());
}

function playerAttendanceToday(): string
{
    return playerAttendanceNow()->format('Y-m-d');
}

function normalizePlayerAttendanceArabicNumbers(string $value): string
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

function sanitizePlayerAttendanceBarcode(string $value): string
{
    $value = trim(normalizePlayerAttendanceArabicNumbers($value));
    $sanitizedValue = preg_replace('/\s+/u', '', $value);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function sanitizePlayerAttendanceNote(string $value): string
{
    $value = trim(normalizePlayerAttendanceArabicNumbers($value));
    $value = preg_replace('/\s+/u', ' ', $value);
    $value = $value === null ? '' : $value;

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, PLAYER_ATTENDANCE_NOTE_MAX_LENGTH, 'UTF-8');
    }

    return substr($value, 0, PLAYER_ATTENDANCE_NOTE_MAX_LENGTH);
}

function sanitizePlayerAttendancePhone(string $value): string
{
    $value = trim(normalizePlayerAttendanceArabicNumbers($value));
    $sanitizedValue = preg_replace('/[^0-9+]/', '', $value);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function formatPlayerAttendanceWhatsappPhone(string $value): string
{
    $phone = sanitizePlayerAttendancePhone($value);
    if ($phone === '') {
        return '';
    }

    if (str_starts_with($phone, '+')) {
        return substr($phone, 1);
    }

    if (str_starts_with($phone, '00')) {
        return substr($phone, 2);
    }

    if (str_starts_with($phone, '0')) {
        return '20' . ltrim($phone, '0');
    }

    return $phone;
}

function buildPlayerAttendancePageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return PLAYER_ATTENDANCE_PAGE_FILE . ($queryString !== '' ? '?' . $queryString : '');
}

function generatePlayerAttendanceSecurityToken(): string
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

    throw new RuntimeException('تعذر إنشاء رمز أمان لحضور السباحين بعد تعذر random_bytes و openssl_random_pseudo_bytes');
}

function getPlayerAttendanceCsrfToken(): string
{
    if (
        !isset($_SESSION['player_attendance_csrf_token'])
        || !is_string($_SESSION['player_attendance_csrf_token'])
        || $_SESSION['player_attendance_csrf_token'] === ''
    ) {
        try {
            $_SESSION['player_attendance_csrf_token'] = generatePlayerAttendanceSecurityToken();
        } catch (Throwable $exception) {
            error_log(sprintf(
                'تعذر إنشاء رمز التحقق الخاص بحضور السباحين [%s:%s] %s',
                get_class($exception),
                (string) $exception->getCode(),
                $exception->getMessage()
            ));
            http_response_code(500);
            exit('تعذر تهيئة الصفحة');
        }
    }

    return $_SESSION['player_attendance_csrf_token'];
}

function isValidPlayerAttendanceCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getPlayerAttendanceCsrfToken(), $submittedToken);
}

function setPlayerAttendanceFlash(string $message, string $type): void
{
    $_SESSION['player_attendance_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumePlayerAttendanceFlash(): array
{
    $flash = $_SESSION['player_attendance_flash'] ?? null;
    unset($_SESSION['player_attendance_flash']);

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

function setPlayerAttendanceActiveSessionId(int $sessionId): void
{
    if ($sessionId > 0) {
        $_SESSION['player_attendance_active_session_id'] = $sessionId;
    }
}

function getPlayerAttendanceActiveSessionId(): int
{
    $sessionId = $_SESSION['player_attendance_active_session_id'] ?? 0;
    return is_numeric($sessionId) ? (int) $sessionId : 0;
}

function clearPlayerAttendanceActiveSessionId(): void
{
    unset($_SESSION['player_attendance_active_session_id']);
}

function playerAttendanceRedirect(array $params = []): void
{
    header('Location: ' . buildPlayerAttendancePageUrl($params));
    exit;
}

function parsePlayerAttendanceInteger($value): int
{
    $value = trim((string) $value);
    return ctype_digit($value) ? (int) $value : 0;
}

function formatPlayerAttendanceDate(?string $value): string
{
    if (!is_string($value) || $value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('Y-m-d', $timestamp);
}

function formatPlayerAttendanceDateTime(?string $value): string
{
    if (!is_string($value) || $value === '') {
        return '—';
    }

    try {
        $date = new DateTimeImmutable($value, playerAttendanceTimezone());
        return $date->format('Y-m-d H:i');
    } catch (Throwable $exception) {
        $timestamp = strtotime($value);
        return $timestamp === false ? '—' : date('Y-m-d H:i', $timestamp);
    }
}

function calculatePlayerAttendanceAge(?string $birthDate): string
{
    if (!is_string($birthDate) || $birthDate === '' || strtotime($birthDate) === false) {
        return '—';
    }

    try {
        $birth = new DateTimeImmutable($birthDate, playerAttendanceTimezone());
        $today = new DateTimeImmutable(playerAttendanceToday(), playerAttendanceTimezone());
        return (string) $birth->diff($today)->y;
    } catch (Throwable $exception) {
        return '—';
    }
}

function formatPlayerAttendanceMoney($value): string
{
    return number_format((float) $value, 2);
}

function playerAttendanceBoolLabel($value): string
{
    return (int) $value === 1 ? 'نعم' : 'لا';
}

function playerAttendanceSessionStatusLabel(string $status): string
{
    return $status === PLAYER_ATTENDANCE_STATUS_CLOSED ? 'مغلقة' : 'مفتوحة';
}

function playerAttendanceRecordStatusLabel(string $status): string
{
    return $status === PLAYER_ATTENDANCE_RECORD_PRESENT ? 'حاضر' : 'غياب';
}

function buildPlayerAttendanceDuplicateMessage(string $playerName): string
{
    return 'لا يمكن تسجيل حضور السباح مرتين في نفس اليوم: ' . $playerName;
}

function normalizePlayerAttendanceImagePath($path): ?string
{
    if (!is_string($path)) {
        return null;
    }

    $path = trim($path);
    if (strpos($path, PLAYER_ATTENDANCE_PLAYER_UPLOAD_PUBLIC_DIR . '/') !== 0) {
        return null;
    }

    $fileName = basename($path);
    if ($fileName === '' || preg_match(PLAYER_ATTENDANCE_IMAGE_FILENAME_PATTERN, $fileName) !== 1) {
        return null;
    }

    $normalizedPath = PLAYER_ATTENDANCE_PLAYER_UPLOAD_PUBLIC_DIR . '/' . $fileName;
    if ($path !== $normalizedPath) {
        return null;
    }

    $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, PLAYER_ATTENDANCE_PLAYER_UPLOAD_PUBLIC_DIR) . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($absolutePath)) {
        return null;
    }

    if (function_exists('finfo_open') && defined('FILEINFO_MIME_TYPE')) {
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($fileInfo !== false) {
            $mimeType = finfo_file($fileInfo, $absolutePath);
            finfo_close($fileInfo);

            if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                return null;
            }
        }
    }

    return $normalizedPath;
}

function playerAttendanceExportHeaders(): array
{
    return [
        'الباركود',
        'الاسم',
        'وقت الحضور',
        'رقم السباح',
        'رقم ولي الأمر',
        'تاريخ الميلاد',
        'السن',
        'المجموعة',
        'الفرع',
        'المستوى',
        'المدرب',
        'المواعيد',
        'التمارين المتبقية',
        'بداية الاشتراك',
        'نهاية الاشتراك',
        'السعر',
        'الخصم',
        'الإجمالي',
        'المدفوع',
        'المتبقي',
        'الإيصال',
        'التقرير الطبي',
        'كارنية الاتحاد',
        'النجوم',
        'آخر نجمة',
        'الملاحظة',
        'رابط صورة السباح',
    ];
}

function exportPlayerAttendanceAsXlsx(array $records, string $attendanceDate): void
{
    $rows = [];

    foreach ($records as $record) {
        $rows[] = [
            (string) ($record['barcode'] ?? ''),
            (string) ($record['player_name'] ?? ''),
            formatPlayerAttendanceDateTime((string) ($record['marked_at'] ?? '')),
            (string) ($record['phone'] ?? ''),
            (string) ($record['guardian_phone'] ?? ''),
            formatPlayerAttendanceDate((string) ($record['birth_date'] ?? '')),
            (string) ($record['player_age'] ?? ''),
            (string) ($record['subscription_name'] ?? ''),
            (string) ($record['subscription_branch'] ?? ''),
            (string) ($record['subscription_category'] ?? ''),
            (string) ($record['subscription_coach_name'] ?? ''),
            formatAcademyTrainingSchedule((string) ($record['subscription_training_schedule'] ?? '')),
            (string) ((int) ($record['available_exercises_count'] ?? 0)),
            formatPlayerAttendanceDate((string) ($record['subscription_start_date'] ?? '')),
            formatPlayerAttendanceDate((string) ($record['subscription_end_date'] ?? '')),
            formatPlayerAttendanceMoney($record['subscription_base_price'] ?? 0),
            formatPlayerAttendanceMoney($record['additional_discount'] ?? 0),
            formatPlayerAttendanceMoney($record['subscription_amount'] ?? 0),
            formatPlayerAttendanceMoney($record['paid_amount'] ?? 0),
            formatPlayerAttendanceMoney($record['remaining_amount'] ?? 0),
            (string) ($record['receipt_number'] ?? ''),
            playerAttendanceBoolLabel($record['medical_report_required'] ?? 0),
            playerAttendanceBoolLabel($record['federation_card_required'] ?? 0),
            (string) (($record['stars_count'] ?? '') !== '' ? $record['stars_count'] : '—'),
            formatPlayerAttendanceDate((string) ($record['last_star_date'] ?? '')),
            (string) ($record['note'] ?? ''),
            (string) (normalizePlayerAttendanceImagePath($record['player_image_path'] ?? null) ?? ''),
        ];
    }

    $temporaryFile = createAcademyXlsxFile(
        playerAttendanceExportHeaders(),
        $rows,
        'حضور السباحين',
        'كشف حضور السباحين ' . $attendanceDate
    );
    outputAcademyXlsxDownload($temporaryFile, 'swimmer-attendance-' . $attendanceDate . '.xlsx');
}

function getPlayerAttendanceSubscriptions(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT
            s.id,
            s.subscription_name,
            s.subscription_branch,
            s.subscription_category,
            s.training_days_count,
            s.available_exercises_count,
            s.training_schedule,
            s.max_trainees,
            c.full_name AS coach_name,
            COUNT(ap.id) AS registered_swimmers_count
        FROM subscriptions s
        LEFT JOIN coaches c ON c.id = s.coach_id
        LEFT JOIN academy_players ap
            ON ap.subscription_id = s.id
            AND ap.subscription_start_date <= CURDATE()
            AND ap.subscription_end_date >= CURDATE()
            AND ap.available_exercises_count > 0
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
        ORDER BY s.subscription_name ASC, s.id DESC"
    );

    $subscriptions = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    foreach ($subscriptions as &$subscription) {
        $trainingSchedule = (string) ($subscription['training_schedule'] ?? '');
        $scheduleItems = json_decode($trainingSchedule, true);
        if ($trainingSchedule !== '' && (!is_array($scheduleItems) || json_last_error() !== JSON_ERROR_NONE)) {
            error_log('تعذر قراءة مواعيد مجموعة حضور السباحين رقم ' . (int) ($subscription['id'] ?? 0));
        }
        $subscription['schedule_summary'] = formatAcademyTrainingSchedule($trainingSchedule);
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

function findPlayerAttendanceSubscription(array $subscriptions, int $subscriptionId): ?array
{
    foreach ($subscriptions as $subscription) {
        if ((int) ($subscription['id'] ?? 0) === $subscriptionId) {
            return $subscription;
        }
    }

    return null;
}

function fetchPlayerAttendanceSessionById(PDO $pdo, int $sessionId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            sas.*,
            s.subscription_name,
            s.subscription_branch,
            s.subscription_category,
            s.training_days_count,
            s.available_exercises_count AS group_exercises_count,
            s.training_schedule,
            s.max_trainees,
            c.full_name AS coach_name,
            u.username AS opened_by_username
        FROM swimmer_attendance_sessions sas
        INNER JOIN subscriptions s ON s.id = sas.subscription_id
        LEFT JOIN coaches c ON c.id = s.coach_id
        LEFT JOIN users u ON u.id = sas.opened_by_user_id
        WHERE sas.id = ?
        LIMIT 1"
    );
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    return $session ?: null;
}

function fetchPlayerAttendanceTodaySession(PDO $pdo, int $subscriptionId, string $attendanceDate): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            sas.id
        FROM swimmer_attendance_sessions sas
        WHERE sas.subscription_id = ? AND sas.attendance_date = ?
        LIMIT 1"
    );
    $stmt->execute([$subscriptionId, $attendanceDate]);
    $sessionId = $stmt->fetchColumn();

    return $sessionId ? fetchPlayerAttendanceSessionById($pdo, (int) $sessionId) : null;
}

function getPlayerAttendanceEligiblePlayers(PDO $pdo, int $subscriptionId, string $attendanceDate): array
{
    $stmt = $pdo->prepare(
        "SELECT
            ap.*
        FROM academy_players ap
        WHERE ap.subscription_id = ?
            AND ap.subscription_start_date <= ?
            AND ap.subscription_end_date >= ?
            AND ap.available_exercises_count > 0
        ORDER BY ap.player_name ASC, ap.id ASC"
    );
    $stmt->execute([$subscriptionId, $attendanceDate, $attendanceDate]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function findPlayerAttendanceEligiblePlayerByBarcode(PDO $pdo, int $subscriptionId, string $attendanceDate, string $barcode): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            ap.*
        FROM academy_players ap
        WHERE ap.subscription_id = ?
            AND ap.subscription_start_date <= ?
            AND ap.subscription_end_date >= ?
            AND ap.available_exercises_count > 0
            AND ap.barcode = ?
        LIMIT 1"
    );
    $stmt->execute([$subscriptionId, $attendanceDate, $attendanceDate, $barcode]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    return $player ?: null;
}

function buildPlayerAttendanceSnapshot(array $player, int $remainingExercisesCount): array
{
    return [
        'barcode' => (string) ($player['barcode'] ?? ''),
        'player_name' => (string) ($player['player_name'] ?? ''),
        'phone' => (string) ($player['phone'] ?? ''),
        'guardian_phone' => (string) ($player['guardian_phone'] ?? ''),
        'birth_date' => (string) ($player['birth_date'] ?? ''),
        'player_age' => calculatePlayerAttendanceAge($player['birth_date'] ?? null),
        'subscription_name' => (string) ($player['subscription_name'] ?? ''),
        'subscription_branch' => (string) ($player['subscription_branch'] ?? ''),
        'subscription_category' => (string) ($player['subscription_category'] ?? ''),
        'subscription_coach_name' => (string) ($player['subscription_coach_name'] ?? ''),
        'subscription_training_schedule' => (string) ($player['subscription_training_schedule'] ?? ''),
        'subscription_training_days_count' => (string) ($player['subscription_training_days_count'] ?? '0'),
        'available_exercises_count' => (string) $remainingExercisesCount,
        'subscription_start_date' => (string) ($player['subscription_start_date'] ?? ''),
        'subscription_end_date' => (string) ($player['subscription_end_date'] ?? ''),
        'max_trainees' => (string) ($player['max_trainees'] ?? '0'),
        'subscription_base_price' => (string) ($player['subscription_base_price'] ?? '0.00'),
        'additional_discount' => (string) ($player['additional_discount'] ?? '0.00'),
        'subscription_amount' => (string) ($player['subscription_amount'] ?? '0.00'),
        'paid_amount' => (string) ($player['paid_amount'] ?? '0.00'),
        'remaining_amount' => (string) ($player['remaining_amount'] ?? '0.00'),
        'receipt_number' => (string) ($player['receipt_number'] ?? ''),
        'medical_report_required' => (string) ((int) ($player['medical_report_required'] ?? 0)),
        'federation_card_required' => (string) ((int) ($player['federation_card_required'] ?? 0)),
        'stars_count' => (string) ($player['stars_count'] ?? ''),
        'last_star_date' => (string) ($player['last_star_date'] ?? ''),
        'player_image_path' => (string) ($player['player_image_path'] ?? ''),
    ];
}

function createPlayerAttendanceSession(PDO $pdo, int $subscriptionId, int $userId, string $attendanceDate): int
{
    $players = getPlayerAttendanceEligiblePlayers($pdo, $subscriptionId, $attendanceDate);
    if ($players === []) {
        throw new RuntimeException('لا يوجد سباحون مسجلون في هذه المجموعة');
    }

    try {
        $pdo->beginTransaction();

        $insertSessionStmt = $pdo->prepare(
            'INSERT INTO swimmer_attendance_sessions (subscription_id, attendance_date, opened_by_user_id, status, total_swimmers, present_count, absent_count) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insertSessionStmt->execute([
            $subscriptionId,
            $attendanceDate,
            $userId,
            PLAYER_ATTENDANCE_STATUS_OPEN,
            count($players),
            0,
            PLAYER_ATTENDANCE_ABSENT_COUNT_ZERO,
        ]);
        $sessionId = (int) $pdo->lastInsertId();

        $pdo->commit();
        return $sessionId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function isPlayerAttendanceSessionEditableByUser(?array $session, int $userId): bool
{
    return is_array($session)
        && (int) ($session['opened_by_user_id'] ?? 0) === $userId
        && (string) ($session['status'] ?? '') === PLAYER_ATTENDANCE_STATUS_OPEN;
}

function fetchPlayerAttendanceRecords(PDO $pdo, int $sessionId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            sar.*,
            ap.barcode AS live_barcode,
            ap.player_name AS live_player_name,
            ap.phone AS live_phone,
            ap.guardian_phone AS live_guardian_phone,
            ap.birth_date AS live_birth_date,
            ap.player_image_path AS live_player_image_path,
            ap.subscription_name AS live_subscription_name,
            ap.subscription_branch AS live_subscription_branch,
            ap.subscription_category AS live_subscription_category,
            ap.subscription_coach_name AS live_subscription_coach_name,
            ap.subscription_training_schedule AS live_subscription_training_schedule,
            ap.subscription_training_days_count AS live_subscription_training_days_count,
            ap.available_exercises_count AS live_available_exercises_count,
            ap.subscription_start_date AS live_subscription_start_date,
            ap.subscription_end_date AS live_subscription_end_date,
            ap.max_trainees AS live_max_trainees,
            ap.subscription_base_price AS live_subscription_base_price,
            ap.additional_discount AS live_additional_discount,
            ap.subscription_amount AS live_subscription_amount,
            ap.paid_amount AS live_paid_amount,
            ap.remaining_amount AS live_remaining_amount,
            ap.receipt_number AS live_receipt_number,
            ap.medical_report_required AS live_medical_report_required,
            ap.federation_card_required AS live_federation_card_required,
            ap.stars_count AS live_stars_count,
            ap.last_star_date AS live_last_star_date
        FROM swimmer_attendance_records sar
        LEFT JOIN academy_players ap ON ap.id = sar.player_id
        WHERE sar.session_id = ?"
    );
    $stmt->execute([$sessionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $records = [];
    foreach ($rows as $row) {
        $snapshot = json_decode((string) ($row['player_snapshot'] ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $records[] = [
            'id' => (int) ($row['id'] ?? 0),
            'session_id' => (int) ($row['session_id'] ?? 0),
            'player_id' => (int) ($row['player_id'] ?? 0),
            'attendance_status' => (string) ($row['attendance_status'] ?? PLAYER_ATTENDANCE_RECORD_PRESENT),
            'note' => (string) ($row['note'] ?? ''),
            'marked_at' => (string) ($row['marked_at'] ?? ''),
            'barcode' => (string) (($row['live_barcode'] ?? null) ?: ($snapshot['barcode'] ?? '')),
            'player_name' => (string) (($row['live_player_name'] ?? null) ?: ($snapshot['player_name'] ?? '')),
            'phone' => (string) (($row['live_phone'] ?? null) ?: ($snapshot['phone'] ?? '')),
            'guardian_phone' => (string) (($row['live_guardian_phone'] ?? null) ?: ($snapshot['guardian_phone'] ?? '')),
            'birth_date' => (string) (($row['live_birth_date'] ?? null) ?: ($snapshot['birth_date'] ?? '')),
            'player_image_path' => (string) (($row['live_player_image_path'] ?? null) ?: ($snapshot['player_image_path'] ?? '')),
            'subscription_name' => (string) (($row['live_subscription_name'] ?? null) ?: ($snapshot['subscription_name'] ?? '')),
            'subscription_branch' => (string) (($row['live_subscription_branch'] ?? null) ?: ($snapshot['subscription_branch'] ?? '')),
            'subscription_category' => (string) (($row['live_subscription_category'] ?? null) ?: ($snapshot['subscription_category'] ?? '')),
            'subscription_coach_name' => (string) (($row['live_subscription_coach_name'] ?? null) ?: ($snapshot['subscription_coach_name'] ?? '')),
            'subscription_training_schedule' => formatAcademyTrainingSchedule((string) (($row['live_subscription_training_schedule'] ?? null) ?: ($snapshot['subscription_training_schedule'] ?? ''))),
            'available_exercises_count' => (string) (($row['live_available_exercises_count'] ?? null) ?: ($snapshot['available_exercises_count'] ?? '0')),
            'subscription_start_date' => (string) (($row['live_subscription_start_date'] ?? null) ?: ($snapshot['subscription_start_date'] ?? '')),
            'subscription_end_date' => (string) (($row['live_subscription_end_date'] ?? null) ?: ($snapshot['subscription_end_date'] ?? '')),
            'subscription_base_price' => (string) (($row['live_subscription_base_price'] ?? null) ?: ($snapshot['subscription_base_price'] ?? '0.00')),
            'additional_discount' => (string) (($row['live_additional_discount'] ?? null) ?: ($snapshot['additional_discount'] ?? '0.00')),
            'subscription_amount' => (string) (($row['live_subscription_amount'] ?? null) ?: ($snapshot['subscription_amount'] ?? '0.00')),
            'paid_amount' => (string) (($row['live_paid_amount'] ?? null) ?: ($snapshot['paid_amount'] ?? '0.00')),
            'remaining_amount' => (string) (($row['live_remaining_amount'] ?? null) ?: ($snapshot['remaining_amount'] ?? '0.00')),
            'receipt_number' => (string) (($row['live_receipt_number'] ?? null) ?: ($snapshot['receipt_number'] ?? '')),
            'medical_report_required' => (int) (($row['live_medical_report_required'] ?? null) !== null ? $row['live_medical_report_required'] : ($snapshot['medical_report_required'] ?? 0)),
            'federation_card_required' => (int) (($row['live_federation_card_required'] ?? null) !== null ? $row['live_federation_card_required'] : ($snapshot['federation_card_required'] ?? 0)),
            'stars_count' => (string) (($row['live_stars_count'] ?? null) ?: ($snapshot['stars_count'] ?? '')),
            'last_star_date' => (string) (($row['live_last_star_date'] ?? null) ?: ($snapshot['last_star_date'] ?? '')),
            'player_age' => calculatePlayerAttendanceAge((string) (($row['live_birth_date'] ?? null) ?: ($snapshot['birth_date'] ?? ''))),
        ];
    }

    usort($records, static function (array $left, array $right): int {
        if ($left['attendance_status'] !== $right['attendance_status']) {
            return $left['attendance_status'] === PLAYER_ATTENDANCE_RECORD_PRESENT ? -1 : 1;
        }

        return strcasecmp($left['player_name'], $right['player_name']);
    });

    return $records;
}

function fetchPlayerAttendanceHistory(PDO $pdo, int $subscriptionId, int $limit = 20): array
{
    $safeLimit = max(1, (int) $limit);
    $stmt = $pdo->prepare(
        "SELECT
            sas.*,
            u.username AS opened_by_username
        FROM swimmer_attendance_sessions sas
        LEFT JOIN users u ON u.id = sas.opened_by_user_id
        WHERE sas.subscription_id = ?
        ORDER BY sas.attendance_date DESC, sas.id DESC
        LIMIT ?"
    );
    $stmt->bindValue(1, $subscriptionId, PDO::PARAM_INT);
    $stmt->bindValue(2, $safeLimit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function findPlayerAttendanceActivePlayerByBarcode(PDO $pdo, string $attendanceDate, string $barcode): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            ap.*
        FROM academy_players ap
        WHERE ap.barcode = ?
            AND ap.subscription_id > 0
            AND ap.subscription_start_date <= ?
            AND ap.subscription_end_date >= ?
        LIMIT 1"
    );
    $stmt->execute([$barcode, $attendanceDate, $attendanceDate]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    return $player ?: null;
}

function fetchPlayerAttendanceRecordByPlayer(PDO $pdo, int $sessionId, int $playerId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            id,
            attendance_status,
            note
        FROM swimmer_attendance_records
        WHERE session_id = ? AND player_id = ?
        LIMIT 1"
    );
    $stmt->execute([$sessionId, $playerId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    return $record ?: null;
}

function fetchPlayerAttendanceTodayRecordById(PDO $pdo, int $recordId, string $attendanceDate): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            sar.id,
            sar.session_id,
            sar.player_id,
            sar.attendance_status
        FROM swimmer_attendance_records sar
        INNER JOIN swimmer_attendance_sessions sas ON sas.id = sar.session_id
        WHERE sar.id = ? AND sas.attendance_date = ?
        LIMIT 1"
    );
    $stmt->execute([$recordId, $attendanceDate]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    return $record ?: null;
}

function reopenPlayerAttendanceSession(PDO $pdo, int $sessionId): void
{
    $stmt = $pdo->prepare(
        'UPDATE swimmer_attendance_sessions SET status = ?, absent_count = ?, closed_at = NULL WHERE id = ?'
    );
    $stmt->execute([
        PLAYER_ATTENDANCE_STATUS_OPEN,
        PLAYER_ATTENDANCE_ABSENT_COUNT_ZERO,
        $sessionId,
    ]);
}

function synchronizePlayerAttendanceSessionCounts(PDO $pdo, int $sessionId): void
{
    $countStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS present_count
        FROM swimmer_attendance_records
        WHERE session_id = ?
            AND attendance_status = ?"
    );
    $countStmt->execute([$sessionId, PLAYER_ATTENDANCE_RECORD_PRESENT]);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $updateStmt = $pdo->prepare(
        'UPDATE swimmer_attendance_sessions SET present_count = ?, absent_count = ? WHERE id = ?'
    );
    $updateStmt->execute([
        (int) ($counts['present_count'] ?? 0),
        PLAYER_ATTENDANCE_ABSENT_COUNT_ZERO,
        $sessionId,
    ]);
}

function fetchPlayerAttendanceTodayRecords(PDO $pdo, string $attendanceDate): array
{
    $sessionStmt = $pdo->prepare(
        "SELECT
            id
        FROM swimmer_attendance_sessions
        WHERE attendance_date = ?
        ORDER BY opened_at DESC, id DESC"
    );
    $sessionStmt->execute([$attendanceDate]);
    $sessionIds = $sessionStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $records = [];
    foreach ($sessionIds as $sessionId) {
        foreach (fetchPlayerAttendanceRecords($pdo, (int) $sessionId) as $record) {
            if (($record['attendance_status'] ?? '') !== PLAYER_ATTENDANCE_RECORD_PRESENT) {
                continue;
            }

            $records[] = $record;
        }
    }

    usort($records, static function (array $left, array $right): int {
        $leftMarkedAt = (string) ($left['marked_at'] ?? '');
        $rightMarkedAt = (string) ($right['marked_at'] ?? '');
        if ($leftMarkedAt !== '' && $rightMarkedAt !== '') {
            $timeComparison = strcmp($rightMarkedAt, $leftMarkedAt);
            if ($timeComparison !== 0) {
                return $timeComparison;
            }
        }

        return strcasecmp((string) ($left['player_name'] ?? ''), (string) ($right['player_name'] ?? ''));
    });

    return $records;
}

function countPlayerAttendanceTodaySubscriptions(array $records): int
{
    $sessionIds = [];

    foreach ($records as $record) {
        $sessionId = (int) ($record['session_id'] ?? 0);
        if ($sessionId <= 0) {
            continue;
        }

        $sessionIds[$sessionId] = true;
    }

    return count($sessionIds);
}

function playerAttendanceLatestMarkedAt(array $records): string
{
    if ($records === []) {
        return '—';
    }

    return formatPlayerAttendanceDateTime((string) ($records[0]['marked_at'] ?? ''));
}

$today = playerAttendanceToday();
$siteSettings = getSiteSettings($pdo);
$academyName = $siteSettings['academy_name'];
$flashMessage = consumePlayerAttendanceFlash();
$message = $flashMessage['message'];
$messageType = $flashMessage['type'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action !== '' && !isValidPlayerAttendanceCsrfToken($_POST['csrf_token'] ?? null)) {
        setPlayerAttendanceFlash('انتهت صلاحية الطلب', 'error');
        playerAttendanceRedirect();
    }

    if ($action === 'scan_barcode') {
        $barcode = sanitizePlayerAttendanceBarcode((string) ($_POST['barcode'] ?? ''));
        if ($barcode === '') {
            setPlayerAttendanceFlash('اكتب الباركود', 'error');
            playerAttendanceRedirect();
        }

        try {
            $targetPlayer = findPlayerAttendanceActivePlayerByBarcode($pdo, $today, $barcode);
            if ($targetPlayer === null) {
                throw new RuntimeException('الباركود غير مسجل في اشتراك نشط اليوم');
            }

            if ((int) ($targetPlayer['available_exercises_count'] ?? 0) <= 0) {
                throw new RuntimeException('لا توجد تمارين متاحة لهذا السباح اليوم');
            }

            $targetSession = fetchPlayerAttendanceTodaySession($pdo, (int) ($targetPlayer['subscription_id'] ?? 0), $today);
            if ($targetSession === null) {
                try {
                    $sessionId = createPlayerAttendanceSession(
                        $pdo,
                        (int) ($targetPlayer['subscription_id'] ?? 0),
                        (int) $currentUser['id'],
                        $today
                    );
                    $targetSession = fetchPlayerAttendanceSessionById($pdo, $sessionId);
                } catch (PDOException $exception) {
                    if ((int) ($exception->errorInfo[1] ?? 0) !== PLAYER_ATTENDANCE_SESSION_DUPLICATE_KEY_ERROR) {
                        throw $exception;
                    }

                    $targetSession = fetchPlayerAttendanceTodaySession($pdo, (int) ($targetPlayer['subscription_id'] ?? 0), $today);
                }
            }

            if ($targetSession === null) {
                throw new RuntimeException('تعذر تهيئة جلسة الحضور');
            }

            $targetRecord = fetchPlayerAttendanceRecordByPlayer(
                $pdo,
                (int) ($targetSession['id'] ?? 0),
                (int) ($targetPlayer['id'] ?? 0)
            );

            if ($targetRecord && (string) ($targetRecord['attendance_status'] ?? '') === PLAYER_ATTENDANCE_RECORD_PRESENT) {
                setPlayerAttendanceFlash(buildPlayerAttendanceDuplicateMessage((string) ($targetPlayer['player_name'] ?? '')), 'error');
                playerAttendanceRedirect();
            }

            $pdo->beginTransaction();
            if ((string) ($targetSession['status'] ?? '') === PLAYER_ATTENDANCE_STATUS_CLOSED) {
                reopenPlayerAttendanceSession($pdo, (int) ($targetSession['id'] ?? 0));
            }

            $remainingExercisesCount = max((int) ($targetPlayer['available_exercises_count'] ?? 0) - 1, 0);
            $playerSnapshot = json_encode(
                buildPlayerAttendanceSnapshot($targetPlayer, $remainingExercisesCount),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($targetRecord) {
                $markPresentStmt = $pdo->prepare(
                    'UPDATE swimmer_attendance_records SET attendance_status = ?, player_snapshot = ?, marked_at = CURRENT_TIMESTAMP WHERE id = ? AND attendance_status = ?'
                );
                $markPresentStmt->execute([
                    PLAYER_ATTENDANCE_RECORD_PRESENT,
                    $playerSnapshot,
                    (int) ($targetRecord['id'] ?? 0),
                    PLAYER_ATTENDANCE_RECORD_ABSENT,
                ]);

                if ($markPresentStmt->rowCount() < 1) {
                    throw new RuntimeException(buildPlayerAttendanceDuplicateMessage((string) ($targetPlayer['player_name'] ?? '')));
                }
            } else {
                $insertRecordStmt = $pdo->prepare(
                    'INSERT INTO swimmer_attendance_records (session_id, player_id, attendance_status, note, player_snapshot, marked_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
                );
                try {
                    $insertRecordStmt->execute([
                        (int) $targetSession['id'],
                        (int) ($targetPlayer['id'] ?? 0),
                        PLAYER_ATTENDANCE_RECORD_PRESENT,
                        '',
                        $playerSnapshot,
                    ]);
                } catch (PDOException $exception) {
                    if ((int) ($exception->errorInfo[1] ?? 0) === PLAYER_ATTENDANCE_SESSION_DUPLICATE_KEY_ERROR) {
                        throw new RuntimeException(buildPlayerAttendanceDuplicateMessage((string) ($targetPlayer['player_name'] ?? '')));
                    }

                    throw $exception;
                }
            }

            $updatePlayerStmt = $pdo->prepare(
                'UPDATE academy_players SET available_exercises_count = GREATEST(available_exercises_count - 1, 0) WHERE id = ? AND available_exercises_count > 0'
            );
            $updatePlayerStmt->execute([(int) ($targetPlayer['id'] ?? 0)]);

            synchronizePlayerAttendanceSessionCounts($pdo, (int) ($targetSession['id'] ?? 0));
            $pdo->commit();

            setPlayerAttendanceFlash('تم تسجيل حضور ' . (string) ($targetPlayer['player_name'] ?? ''), 'success');
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setPlayerAttendanceFlash($exception->getMessage(), 'error');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setPlayerAttendanceFlash('تعذر تسجيل الحضور', 'error');
        }

        playerAttendanceRedirect();
    }

    if ($action === 'export_xlsx') {
        exportPlayerAttendanceAsXlsx(fetchPlayerAttendanceTodayRecords($pdo, $today), $today);
    }

    if ($action === 'save_note') {
        $recordId = parsePlayerAttendanceInteger($_POST['record_id'] ?? '0');
        $note = sanitizePlayerAttendanceNote((string) ($_POST['note'] ?? ''));
        if ($recordId <= 0) {
            setPlayerAttendanceFlash('السجل غير صحيح', 'error');
            playerAttendanceRedirect();
        }

        $targetRecord = fetchPlayerAttendanceTodayRecordById($pdo, $recordId, $today);
        if ($targetRecord === null || (string) ($targetRecord['attendance_status'] ?? '') !== PLAYER_ATTENDANCE_RECORD_PRESENT) {
            setPlayerAttendanceFlash('سجل الحضور غير موجود', 'error');
            playerAttendanceRedirect();
        }

        $updateNoteStmt = $pdo->prepare('UPDATE swimmer_attendance_records SET note = ? WHERE id = ?');
        $updateNoteStmt->execute([$note, $recordId]);
        setPlayerAttendanceFlash('تم حفظ الملاحظة', 'success');
        playerAttendanceRedirect();
    }
}

$presentRecords = fetchPlayerAttendanceTodayRecords($pdo, $today);
$todaySubscriptionsCount = countPlayerAttendanceTodaySubscriptions($presentRecords);
$latestMarkedAt = playerAttendanceLatestMarkedAt($presentRecords);
$attendanceCsrfToken = getPlayerAttendanceCsrfToken();
$pageTitle = 'حضور السباحين - ' . $academyName;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo academyHtmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="assets/css/player-attendance.css">
</head>
<body class="light-mode" data-page-url="<?php echo academyHtmlspecialchars(PLAYER_ATTENDANCE_PAGE_FILE, ENT_QUOTES, 'UTF-8'); ?>">
<div class="player-attendance-page">
    <header class="page-header">
        <div class="header-stack">
            <a href="dashboard.php" class="back-btn">لوحة التحكم</a>
            <h1>حضور السباحين</h1>
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
        </div>
    </header>

    <?php if ($message !== '' && $messageType !== ''): ?>
        <div class="message-box <?php echo academyHtmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo academyHtmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <section class="stats-grid">
        <div class="stat-card">
            <span>التاريخ</span>
            <strong><?php echo academyHtmlspecialchars(formatPlayerAttendanceDate($today), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="stat-card">
            <span>الحاضرون اليوم</span>
            <strong><?php echo count($presentRecords); ?></strong>
        </div>
        <div class="stat-card">
            <span>المجموعات المحضرة</span>
            <strong><?php echo $todaySubscriptionsCount; ?></strong>
        </div>
        <div class="stat-card">
            <span>آخر تحضير</span>
            <strong><?php echo academyHtmlspecialchars($latestMarkedAt, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </section>

    <section class="scan-card">
        <div class="card-head card-head-inline">
            <h2>تحضير السباحين</h2>
            <span class="meta-chip"><?php echo academyHtmlspecialchars(formatPlayerAttendanceDate($today), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <form method="POST" id="barcodeForm" class="scan-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($attendanceCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="scan_barcode">
            <div class="field-group grow barcode-field">
                <label for="barcode">الباركود</label>
                <input type="text" name="barcode" id="barcode" inputmode="numeric" autofocus>
            </div>
            <button type="submit" class="primary-btn large-btn">تحضير</button>
            <button type="button" class="secondary-btn mobile-only" id="cameraTrigger" hidden>تحضير بالكاميرا</button>
        </form>
    </section>

    <section class="table-card">
        <div class="card-head card-head-inline">
            <h2>جدول الحاضرين</h2>
            <div class="card-head-actions">
                <span class="meta-chip"><?php echo count($presentRecords); ?> سباح</span>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($attendanceCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="export_xlsx">
                    <button type="submit" class="secondary-btn">استخراج كشف إكسل</button>
                </form>
            </div>
        </div>
        <div class="table-wrap">
            <table class="attendance-table">
                <thead>
                    <tr>
                        <th>الصورة</th>
                        <th>الباركود</th>
                        <th>الاسم</th>
                        <th>وقت الحضور (القاهرة)</th>
                        <th>رقم السباح</th>
                        <th>رقم ولي الأمر</th>
                        <th>تاريخ الميلاد</th>
                        <th>السن</th>
                        <th>المجموعة</th>
                        <th>الفرع</th>
                        <th>المستوى</th>
                        <th>المدرب</th>
                        <th>المواعيد</th>
                        <th>التمارين المتبقية</th>
                        <th>بداية الاشتراك</th>
                        <th>نهاية الاشتراك</th>
                        <th>السعر</th>
                        <th>الخصم</th>
                        <th>الإجمالي</th>
                        <th>المدفوع</th>
                        <th>المتبقي</th>
                        <th>الإيصال</th>
                        <th>التقرير الطبي</th>
                        <th>كارنية الاتحاد</th>
                        <th>النجوم</th>
                        <th>آخر نجمة</th>
                        <th>الملاحظة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($presentRecords !== []): ?>
                        <?php foreach ($presentRecords as $record): ?>
                            <?php $playerImagePath = normalizePlayerAttendanceImagePath($record['player_image_path'] ?? null); ?>
                            <tr>
                                <td class="attendance-avatar-cell">
                                    <div class="attendance-avatar-shell">
                                        <?php if ($playerImagePath !== null): ?>
                                            <img class="attendance-avatar-image" src="<?php echo academyHtmlspecialchars($playerImagePath, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo academyHtmlspecialchars('صورة ' . (string) ($record['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php else: ?>
                                            <span class="attendance-avatar-placeholder">🏊</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo academyHtmlspecialchars((string) ($record['barcode'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars((string) ($record['player_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(formatPlayerAttendanceDateTime((string) ($record['marked_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars((string) ($record['phone'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars((string) ($record['guardian_phone'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(formatPlayerAttendanceDate((string) ($record['birth_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars((string) ($record['player_age'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars((string) ($record['subscription_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars((string) ($record['subscription_branch'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars((string) ($record['subscription_category'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars((string) ($record['subscription_coach_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(formatAcademyTrainingSchedule((string) ($record['subscription_training_schedule'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int) ($record['available_exercises_count'] ?? 0); ?></td>
                                <td><?php echo academyHtmlspecialchars(formatPlayerAttendanceDate((string) ($record['subscription_start_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(formatPlayerAttendanceDate((string) ($record['subscription_end_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(formatPlayerAttendanceMoney($record['subscription_base_price'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(formatPlayerAttendanceMoney($record['additional_discount'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(formatPlayerAttendanceMoney($record['subscription_amount'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(formatPlayerAttendanceMoney($record['paid_amount'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(formatPlayerAttendanceMoney($record['remaining_amount'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars((string) ($record['receipt_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(playerAttendanceBoolLabel($record['medical_report_required'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(playerAttendanceBoolLabel($record['federation_card_required'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars((string) (($record['stars_count'] ?? '') !== '' ? $record['stars_count'] : '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo academyHtmlspecialchars(formatPlayerAttendanceDate((string) ($record['last_star_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <form method="POST" class="note-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($attendanceCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="save_note">
                                        <input type="hidden" name="record_id" value="<?php echo (int) ($record['id'] ?? 0); ?>">
                                        <textarea name="note" rows="2"><?php echo academyHtmlspecialchars((string) ($record['note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                        <button type="submit" class="tiny-btn">حفظ</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo PLAYER_ATTENDANCE_PRESENT_SWIMMERS_TABLE_COLUMN_COUNT; ?>" class="empty-state">لا يوجد حضور مسجل اليوم</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="camera-modal" id="cameraModal" hidden>
    <div class="camera-modal__content">
        <div class="camera-modal__head">
            <button type="button" class="secondary-btn" id="cameraClose">إغلاق</button>
        </div>
        <video id="cameraVideo" autoplay playsinline muted></video>
    </div>
</div>

<script src="assets/js/player-attendance.js"></script>
</body>
</html>
