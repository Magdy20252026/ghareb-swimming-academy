<?php
session_start();
require_once 'config.php';
require_once 'app_helpers.php';

const SWIMMER_PORTAL_SESSION_KEY = 'swimmer_portal_player_id';
const SWIMMER_PORTAL_UPLOAD_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'academy_players';
const SWIMMER_PORTAL_UPLOAD_PUBLIC_DIR = 'uploads/academy_players';
const SWIMMER_PORTAL_STORE_UPLOAD_PUBLIC_DIR = 'uploads/swimmer_store';
const SWIMMER_PORTAL_CARD_REQUESTS_UPLOAD_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'swimmer_card_requests';
const SWIMMER_PORTAL_CARD_REQUESTS_UPLOAD_PUBLIC_DIR = 'uploads/swimmer_card_requests';
const SWIMMER_PORTAL_PAGE = 'swimmer_portal.php';
const SWIMMER_PORTAL_BARCODE_START_CODE_B = 104;
const SWIMMER_PORTAL_BARCODE_CHECKSUM_MODULO = 103;
const SWIMMER_PORTAL_BARCODE_STOP_CODE = 106;
const SWIMMER_PORTAL_BARCODE_MODULE_WIDTH = 2;
const SWIMMER_PORTAL_BARCODE_QUIET_ZONE = 10;
const SWIMMER_PORTAL_BARCODE_HEIGHT = 84;
const SWIMMER_PORTAL_INFO_ROW_BARCODE = 'barcode';
const SWIMMER_NUTRITION_BASE_CALORIES_FACTOR = 34;
const SWIMMER_NUTRITION_MIN_LEAN_MASS_RATIO = 0.55;
const SWIMMER_NUTRITION_HIGH_BODY_FAT_THRESHOLD = 22;
const SWIMMER_NUTRITION_LOW_BODY_FAT_THRESHOLD = 12;
const SWIMMER_NUTRITION_CALORIE_ADJUSTMENT = 220;
const SWIMMER_NUTRITION_JUNIOR_AGE_THRESHOLD = 14;
const SWIMMER_NUTRITION_JUNIOR_BONUS_CALORIES = 120;
const SWIMMER_NUTRITION_PROTEIN_FACTOR = 1.8;
const SWIMMER_NUTRITION_MIN_PROTEIN_GRAMS = 95;
const SWIMMER_NUTRITION_CARB_RATIO = 0.5;
const SWIMMER_NUTRITION_FAT_RATIO = 0.25;
const SWIMMER_NUTRITION_CARB_CALORIES_PER_GRAM = 4;
const SWIMMER_NUTRITION_FAT_CALORIES_PER_GRAM = 9;
const SWIMMER_NUTRITION_MIN_CARBS_GRAMS = 180;
const SWIMMER_NUTRITION_MIN_FATS_GRAMS = 45;
const SWIMMER_NUTRITION_MAX_BODY_FAT_PERCENTAGE = 59;

function swimmerPortalToken(): string
{
    if (!isset($_SESSION['swimmer_portal_token']) || !is_string($_SESSION['swimmer_portal_token']) || $_SESSION['swimmer_portal_token'] === '') {
        $_SESSION['swimmer_portal_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['swimmer_portal_token'];
}

function swimmerPortalValidToken($token): bool
{
    return is_string($token) && $token !== '' && hash_equals(swimmerPortalToken(), $token);
}

function swimmerPortalActiveSection($value): string
{
    $allowedSections = ['info', 'attendance', 'nutrition', 'store', 'files', 'card-request', 'notifications', 'offers', 'group-evaluation', 'journey', 'password'];
    return in_array($value, $allowedSections, true) ? $value : 'info';
}

function swimmerPortalArabicNumbers(string $value): string
{
    return strtr($value, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ]);
}

function swimmerPortalBarcode(string $value): string
{
    $value = trim(swimmerPortalArabicNumbers($value));
    $value = preg_replace('/\s+/u', '', $value);
    return is_string($value) ? $value : '';
}

function swimmerPortalText(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return is_string($value) ? $value : '';
}

function swimmerPortalBarcodePatterns(): array
{
    return [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232',
    ];
}

function swimmerPortalBarcodeStopPattern(): string
{
    return '2331112';
}

function swimmerPortalBarcodeImageSrc(string $value): ?string
{
    $barcode = swimmerPortalBarcode($value);
    if ($barcode === '') {
        return null;
    }

    $patterns = swimmerPortalBarcodePatterns();
    $codes = [SWIMMER_PORTAL_BARCODE_START_CODE_B];
    $checksum = SWIMMER_PORTAL_BARCODE_START_CODE_B;
    $length = strlen($barcode);

    for ($index = 0; $index < $length; $index++) {
        $ascii = ord($barcode[$index]);
        if ($ascii < 32 || $ascii > 126) {
            return null;
        }

        $code = $ascii - 32;
        $codes[] = $code;
        $checksum += $code * ($index + 1);
    }

    $codes[] = $checksum % SWIMMER_PORTAL_BARCODE_CHECKSUM_MODULO;
    $codes[] = SWIMMER_PORTAL_BARCODE_STOP_CODE;

    $moduleWidth = SWIMMER_PORTAL_BARCODE_MODULE_WIDTH;
    $quietZone = SWIMMER_PORTAL_BARCODE_QUIET_ZONE;
    $height = SWIMMER_PORTAL_BARCODE_HEIGHT;
    $x = $quietZone;
    $bars = [];

    foreach ($codes as $code) {
        $pattern = $code === SWIMMER_PORTAL_BARCODE_STOP_CODE
            ? swimmerPortalBarcodeStopPattern()
            : ($patterns[$code] ?? null);
        if ($pattern === null) {
            return null;
        }

        $isBar = true;
        $patternLength = strlen($pattern);
        for ($patternIndex = 0; $patternIndex < $patternLength; $patternIndex++) {
            $width = (int) $pattern[$patternIndex];
            if ($isBar && $width > 0) {
                $bars[] = sprintf(
                    '<rect x="%d" y="0" width="%d" height="%d" fill="#111827"></rect>',
                    (int) ($x * $moduleWidth),
                    (int) ($width * $moduleWidth),
                    (int) $height
                );
            }

            $x += $width;
            $isBar = !$isBar;
        }
    }

    $totalWidth = (int) (($x + $quietZone) * $moduleWidth);
    $label = htmlspecialchars($barcode, ENT_QUOTES, 'UTF-8');
    $background = sprintf('<rect width="%d" height="%d" fill="#ffffff"></rect>', $totalWidth, (int) $height);
    $barsMarkup = implode('', $bars);

    $svg = sprintf(
        '<svg class="barcode-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s" focusable="false">%4$s%5$s</svg>',
        $totalWidth,
        $height,
        $label,
        $background,
        $barsMarkup
    );

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function swimmerPortalFormatDate(?string $value): string
{
    if (!is_string($value) || trim($value) === '' || strtotime($value) === false) {
        return '—';
    }

    return date('Y-m-d', strtotime($value));
}

function swimmerPortalFormatDateTime(?string $value): string
{
    if (!is_string($value) || trim($value) === '' || strtotime($value) === false) {
        return '—';
    }

    return date('Y-m-d H:i', strtotime($value));
}

function swimmerPortalSocialPlatforms(): array
{
    return [
        'facebook' => ['field' => 'facebook_url', 'label' => 'فيسبوك', 'mark' => 'f'],
        'whatsapp' => ['field' => 'whatsapp_url', 'label' => 'واتساب', 'mark' => 'wa'],
        'youtube' => ['field' => 'youtube_url', 'label' => 'يوتيوب', 'mark' => '▶'],
        'tiktok' => ['field' => 'tiktok_url', 'label' => 'تيك توك', 'mark' => '♪'],
        'instagram' => ['field' => 'instagram_url', 'label' => 'إنستاجرام', 'mark' => '◎'],
    ];
}

function swimmerPortalSocialLinks(array $siteSettings): array
{
    $socialLinks = [];

    foreach (swimmerPortalSocialPlatforms() as $platformKey => $platform) {
        $fieldName = $platform['field'];
        $url = trim((string) ($siteSettings[$fieldName] ?? ''));

        if ($url === '') {
            continue;
        }

        $socialLinks[$platformKey] = [
            'label' => $platform['label'],
            'mark' => $platform['mark'],
            'url' => $url,
        ];
    }

    return $socialLinks;
}

function swimmerPortalAge(?string $birthDate): string
{
    if (!is_string($birthDate) || trim($birthDate) === '' || strtotime($birthDate) === false) {
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

function swimmerPortalCategoryStarsCount(string $category): int
{
    if (preg_match('/فرق استارات\\s+([1-4])\\s+نجمة/u', $category, $matches) === 1) {
        return (int) $matches[1];
    }

    return 0;
}

function swimmerPortalResolvedStarsCount(string $category, $storedStarsCount): int
{
    if ($category === 'فرق ستار 3-4') {
        $storedStarsCount = (int) $storedStarsCount;
        return in_array($storedStarsCount, [3, 4], true) ? $storedStarsCount : 0;
    }

    $fixedStarsCount = swimmerPortalCategoryStarsCount($category);
    if ($fixedStarsCount > 0) {
        return $fixedStarsCount;
    }

    $storedStarsCount = (int) $storedStarsCount;
    return in_array($storedStarsCount, [1, 2, 3, 4], true) ? $storedStarsCount : 0;
}

function swimmerPortalStarsText(int $starsCount): string
{
    return $starsCount > 0 ? str_repeat('★', $starsCount) : '—';
}

function swimmerPortalNormalizePath($path): ?string
{
    if (!is_string($path)) {
        return null;
    }

    $path = trim($path);
    if (strpos($path, SWIMMER_PORTAL_UPLOAD_PUBLIC_DIR . '/') !== 0) {
        return null;
    }

    $fileName = basename($path);
    if ($fileName === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $fileName) !== 1) {
        return null;
    }

    return SWIMMER_PORTAL_UPLOAD_PUBLIC_DIR . '/' . $fileName;
}

function swimmerPortalNormalizeCardRequestPath($path): ?string
{
    if (!is_string($path)) {
        return null;
    }

    $path = trim($path);
    if (strpos($path, SWIMMER_PORTAL_CARD_REQUESTS_UPLOAD_PUBLIC_DIR . '/') !== 0) {
        return null;
    }

    $fileName = basename($path);
    if ($fileName === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $fileName) !== 1) {
        return null;
    }

    return SWIMMER_PORTAL_CARD_REQUESTS_UPLOAD_PUBLIC_DIR . '/' . $fileName;
}

function swimmerPortalNormalizeStorePath($path): ?string
{
    if (!is_string($path)) {
        return null;
    }

    $path = trim($path);
    if (strpos($path, SWIMMER_PORTAL_STORE_UPLOAD_PUBLIC_DIR . '/') !== 0) {
        return null;
    }

    $fileName = basename($path);
    if ($fileName === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $fileName) !== 1) {
        return null;
    }

    return SWIMMER_PORTAL_STORE_UPLOAD_PUBLIC_DIR . '/' . $fileName;
}

function swimmerPortalNormalizePlayerImagePath($path): ?string
{
    if (!is_string($path)) {
        return null;
    }

    $path = trim($path);
    if (strpos($path, SWIMMER_PORTAL_UPLOAD_PUBLIC_DIR . '/') !== 0) {
        return null;
    }

    $fileName = basename($path);
    if ($fileName === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*\.[A-Za-z0-9]+$/', $fileName) !== 1) {
        return null;
    }

    return SWIMMER_PORTAL_UPLOAD_PUBLIC_DIR . '/' . $fileName;
}

function swimmerPortalAbsolutePath(?string $path): ?string
{
    $normalizedPath = swimmerPortalNormalizePath($path);
    if ($normalizedPath === null) {
        return null;
    }

    return SWIMMER_PORTAL_UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($normalizedPath);
}

function swimmerPortalDeleteImage(?string $path): void
{
    $absolutePath = swimmerPortalAbsolutePath($path);
    if ($absolutePath !== null && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function swimmerPortalDeleteImages(array $paths): void
{
    foreach ($paths as $path) {
        swimmerPortalDeleteImage(is_string($path) ? $path : null);
    }
}

function swimmerPortalEnsureDir(string $directory): bool
{
    return is_dir($directory) || mkdir($directory, 0755, true);
}

function swimmerPortalUploadImage(array $file, string $directory, string $publicDirectory, string $prefix): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || trim((string) ($file['name'] ?? '')) === '') {
        return ['path' => null, 'error' => false, 'message' => null];
    }

    $directoryReady = swimmerPortalEnsureDir($directory);
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !$directoryReady) {
        $message = !$directoryReady
            ? 'تعذر تجهيز مجلد الرفع.'
            : 'حدثت مشكلة أثناء رفع الملف.';
        return ['path' => null, 'error' => true, 'message' => $message];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['path' => null, 'error' => true, 'message' => 'ملف الرفع غير صالح.'];
    }

    $mime = '';
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    if ($finfo !== false) {
        $mime = (string) finfo_file($finfo, $tmpName);
        finfo_close($finfo);
    }

    if ($mime === '' || strpos($mime, 'image/') !== 0) {
        return ['path' => null, 'error' => true, 'message' => 'يجب اختيار صورة صحيحة.'];
    }

    $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $extension = preg_replace('/[^a-z0-9]+/i', '', $extension);
    $extension = $extension !== '' ? $extension : 'jpg';
    $fileName = $prefix . '-' . bin2hex(random_bytes(12)) . '.' . $extension;
    $destination = $directory . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $destination)) {
        return ['path' => null, 'error' => true, 'message' => 'تعذر حفظ الصورة المرفوعة.'];
    }

    return ['path' => $publicDirectory . '/' . $fileName, 'error' => false, 'message' => null];
}

function swimmerPortalUploadImages(array $files, string $directory, string $publicDirectory, string $prefix): array
{
    if (
        !isset($files['name']) || !is_array($files['name'])
        || !isset($files['tmp_name']) || !is_array($files['tmp_name'])
        || !isset($files['error']) || !is_array($files['error'])
    ) {
        return ['paths' => [], 'error' => false];
    }

    $paths = [];
    foreach ($files['name'] as $index => $name) {
        $upload = swimmerPortalUploadImage([
            'name' => $name,
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ], $directory, $publicDirectory, $prefix);

        if (!empty($upload['error'])) {
            swimmerPortalDeleteImages($paths);
            return ['paths' => [], 'error' => true];
        }

        if (!empty($upload['path'])) {
            $paths[] = (string) $upload['path'];
        }
    }

    return ['paths' => $paths, 'error' => false];
}

function swimmerPortalMedicalReportFiles($storedFiles, $fallbackPath = null): array
{
    $paths = [];

    if (is_string($storedFiles) && trim($storedFiles) !== '') {
        $decoded = json_decode($storedFiles, true);
        if (is_array($decoded)) {
            foreach ($decoded as $path) {
                $normalizedPath = swimmerPortalNormalizePath($path);
                if ($normalizedPath !== null) {
                    $paths[] = $normalizedPath;
                }
            }
        }
    }

    $fallbackNormalizedPath = swimmerPortalNormalizePath($fallbackPath);
    if ($fallbackNormalizedPath !== null) {
        array_unshift($paths, $fallbackNormalizedPath);
    }

    return array_values(array_unique($paths));
}

function swimmerPortalEncodeMedicalReportFiles(array $paths): ?string
{
    $normalized = [];
    foreach ($paths as $path) {
        $normalizedPath = swimmerPortalNormalizePath($path);
        if ($normalizedPath !== null) {
            $normalized[] = $normalizedPath;
        }
    }

    $normalized = array_values(array_unique($normalized));
    if ($normalized === []) {
        return null;
    }

    $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? $encoded : null;
}

function swimmerPortalPlayerByBarcode(PDO $pdo, string $barcode): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM academy_players WHERE barcode = ? LIMIT 1');
    $stmt->execute([$barcode]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    return $player ?: null;
}

function swimmerPortalPlayerById(PDO $pdo, int $playerId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM academy_players WHERE id = ? LIMIT 1');
    $stmt->execute([$playerId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    return $player ?: null;
}

function swimmerPortalProducts(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM academy_store_products ORDER BY updated_at DESC, id DESC');
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function swimmerPortalNotifications(PDO $pdo, array $player): array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM swimmer_notifications
         WHERE (target_branch IS NULL OR target_branch = "" OR target_branch = ?)
           AND (target_subscription IS NULL OR target_subscription = "" OR target_subscription = ?)
           AND (target_level IS NULL OR target_level = "" OR target_level = ?)
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([
        (string) ($player['subscription_branch'] ?? ''),
        (string) ($player['subscription_name'] ?? ''),
        (string) ($player['subscription_category'] ?? ''),
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function swimmerPortalOffers(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT *
         FROM offers
         WHERE is_active = 1
           AND (valid_from IS NULL OR valid_from <= CURDATE())
           AND (valid_until IS NULL OR valid_until >= CURDATE())
         ORDER BY created_at DESC, id DESC'
    );

    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function swimmerPortalCardRequests(PDO $pdo, int $playerId): array
{
    $stmt = $pdo->prepare('SELECT * FROM swimmer_card_requests WHERE player_id = ? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$playerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function swimmerPortalGroupEvaluation(PDO $pdo, int $subscriptionId, string $month): ?array
{
    if ($subscriptionId <= 0 || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT evaluation_score, evaluation_notes, evaluation_month, updated_at
         FROM group_evaluations
         WHERE subscription_id = ? AND evaluation_month = ?
         LIMIT 1'
    );
    $stmt->execute([$subscriptionId, $month]);
    $evaluation = $stmt->fetch(PDO::FETCH_ASSOC);

    return $evaluation ?: null;
}

function swimmerPortalJourneyLevels(PDO $pdo): array
{
    return [
        [
            'title' => 'مشوار قطاع بطولة زعانف',
            'levels' => [
                'مدارس سباحة',
                'تجهيزي فرق جديد',
                'تجهيزي فرق A',
                'تجهيزي فرق B',
                'فرق استارات 1 نجمة',
                'فرق استارات 2 نجمة',
                'قطاع بطولة زعانف',
            ],
        ],
        [
            'title' => 'مشوار قطاع بطولة كلاسك',
            'levels' => [
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
            ],
        ],
    ];
}

function swimmerPortalEvaluationStars(int $score): string
{
    return $score > 0 ? str_repeat('★', $score) : '—';
}

function swimmerPortalAttendanceStatusLabel(string $status): string
{
    return $status === 'present' ? 'حاضر' : 'غائب';
}

function swimmerPortalAttendanceRecords(PDO $pdo, int $playerId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            sar.note,
            sar.marked_at,
            sar.player_snapshot,
            sas.attendance_date,
            s.subscription_name,
            s.subscription_branch,
            s.subscription_category,
            s.training_schedule,
            c.full_name AS coach_name
         FROM swimmer_attendance_records sar
         INNER JOIN swimmer_attendance_sessions sas ON sas.id = sar.session_id
         LEFT JOIN subscriptions s ON s.id = sas.subscription_id
         LEFT JOIN coaches c ON c.id = s.coach_id
         WHERE sar.player_id = ?
           AND sar.attendance_status = 'present'
          ORDER BY sas.attendance_date DESC, sar.id DESC"
    );
    $stmt->execute([$playerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $records = [];

    foreach ($rows as $row) {
        $snapshot = json_decode((string) ($row['player_snapshot'] ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $records[] = [
            'attendance_status' => 'present',
            'note' => (string) ($row['note'] ?? ''),
            'marked_at' => (string) ($row['marked_at'] ?? ''),
            'attendance_date' => (string) ($row['attendance_date'] ?? ''),
            'subscription_name' => (string) (($row['subscription_name'] ?? null) ?: ($snapshot['subscription_name'] ?? '—')),
            'subscription_branch' => (string) (($row['subscription_branch'] ?? null) ?: ($snapshot['subscription_branch'] ?? '—')),
            'subscription_category' => (string) (($row['subscription_category'] ?? null) ?: ($snapshot['subscription_category'] ?? '—')),
            'training_schedule' => formatAcademyTrainingSchedule((string) (($row['training_schedule'] ?? '') ?: ($snapshot['subscription_training_schedule'] ?? ''))),
            'coach_name' => (string) (($row['coach_name'] ?? null) ?: ($snapshot['subscription_coach_name'] ?? '—')),
        ];
    }

    return $records;
}

function swimmerPortalAttendanceSummary(array $records): array
{
    $summary = [
        'total' => count($records),
        'present' => count($records),
    ];

    return $summary;
}

function swimmerPortalAttendanceLastUpdated(array $records): string
{
    if ($records === []) {
        return '—';
    }

    $latestRecord = $records[0];
    $latestTimestamp = (string) ($latestRecord['marked_at'] ?? '');
    if ($latestTimestamp !== '') {
        return swimmerPortalFormatDateTime($latestTimestamp);
    }

    return swimmerPortalFormatDateTime((string) ($latestRecord['attendance_date'] ?? ''));
}

function swimmerPortalNutritionPlan(int $age, float $weight, float $bodyFat): string
{
    $leanMass = max($weight * (1 - ($bodyFat / 100)), $weight * SWIMMER_NUTRITION_MIN_LEAN_MASS_RATIO);
    $calories = (int) round($leanMass * SWIMMER_NUTRITION_BASE_CALORIES_FACTOR);
    if ($bodyFat >= SWIMMER_NUTRITION_HIGH_BODY_FAT_THRESHOLD) {
        $calories -= SWIMMER_NUTRITION_CALORIE_ADJUSTMENT;
    } elseif ($bodyFat <= SWIMMER_NUTRITION_LOW_BODY_FAT_THRESHOLD) {
        $calories += SWIMMER_NUTRITION_CALORIE_ADJUSTMENT;
    }
    if ($age <= SWIMMER_NUTRITION_JUNIOR_AGE_THRESHOLD) {
        $calories += SWIMMER_NUTRITION_JUNIOR_BONUS_CALORIES;
    }

    $protein = (int) round(max($weight * SWIMMER_NUTRITION_PROTEIN_FACTOR, SWIMMER_NUTRITION_MIN_PROTEIN_GRAMS));
    $carbs = (int) round(max(
        ($calories * SWIMMER_NUTRITION_CARB_RATIO) / SWIMMER_NUTRITION_CARB_CALORIES_PER_GRAM,
        SWIMMER_NUTRITION_MIN_CARBS_GRAMS
    ));
    $fats = (int) round(max(
        ($calories * SWIMMER_NUTRITION_FAT_RATIO) / SWIMMER_NUTRITION_FAT_CALORIES_PER_GRAM,
        SWIMMER_NUTRITION_MIN_FATS_GRAMS
    ));
    $water = number_format(max($weight * 0.04, 2.2), 1);
    $goal = $bodyFat >= SWIMMER_NUTRITION_HIGH_BODY_FAT_THRESHOLD
        ? 'خفض الدهون مع الحفاظ على الأداء'
        : ($bodyFat <= SWIMMER_NUTRITION_LOW_BODY_FAT_THRESHOLD ? 'رفع الطاقة والكتلة العضلية' : 'الثبات مع تحسين الاستشفاء');

    $breakfast = $bodyFat >= SWIMMER_NUTRITION_HIGH_BODY_FAT_THRESHOLD
        ? '2 بيض كامل + 3 بياض + فول بزيت زيتون خفيف + رغيف بلدي صغير + خيار وطماطم'
        : '3 بيض كامل + جبنة قريش + شوفان باللبن + موزة';
    $snackOne = $bodyFat >= SWIMMER_NUTRITION_HIGH_BODY_FAT_THRESHOLD
        ? 'علبة زبادي يوناني + تفاحة + 10 لوز'
        : 'علبة زبادي + تمرتين + حفنة مكسرات';
    $lunch = $bodyFat >= SWIMMER_NUTRITION_HIGH_BODY_FAT_THRESHOLD
        ? '200 جم فراخ مشوية + 1 كوب أرز مصري + سلطة كبيرة + خضار سوتيه'
        : '220 جم فراخ أو لحم أحمر خالي الدهن + 1.5 كوب أرز أو مكرونة + سلطة';
    $snackTwo = $bodyFat >= SWIMMER_NUTRITION_HIGH_BODY_FAT_THRESHOLD
        ? 'ساندوتش تونة أو جبنة قريش + برتقالة'
        : 'ساندوتش زبدة فول سوداني بالعسل + موزة';
    $dinner = $bodyFat >= SWIMMER_NUTRITION_HIGH_BODY_FAT_THRESHOLD
        ? '180 جم سمك أو تونة + بطاطس مسلوقة + سلطة خضراء'
        : '200 جم سمك أو تونة + بطاطس أو أرز + خضار';
    $lateMeal = $bodyFat >= SWIMMER_NUTRITION_HIGH_BODY_FAT_THRESHOLD
        ? 'كوب لبن أو جبنة قريش'
        : 'كوب لبن + شوفان خفيف أو جبنة قريش';

    return implode("\n", [
        'الهدف: ' . $goal,
        'السعرات: ' . $calories . ' سعرة',
        'البروتين: ' . $protein . ' جم',
        'الكربوهيدرات: ' . $carbs . ' جم',
        'الدهون: ' . $fats . ' جم',
        'المياه: ' . $water . ' لتر',
        'الفطار: ' . $breakfast,
        'سناك 1: ' . $snackOne,
        'الغداء: ' . $lunch,
        'قبل التمرين: قهوة خفيفة أو تمرتين + ماء',
        'بعد التمرين: لبن أو زبادي + ثمرة موز',
        'سناك 2: ' . $snackTwo,
        'العشاء: ' . $dinner,
        'قبل النوم: ' . $lateMeal,
    ]);
}

function swimmerPortalInfoRows(array $player): array
{
    $birthYear = '—';
    $birthDate = (string) ($player['birth_date'] ?? '');
    $birthTimestamp = $birthDate !== '' ? strtotime($birthDate) : false;
    if ($birthTimestamp !== false) {
        $birthYear = date('Y', $birthTimestamp);
    }

    return [
        ['key' => SWIMMER_PORTAL_INFO_ROW_BARCODE, 'label' => 'الباركود', 'value' => (string) ($player['barcode'] ?? '—')],
        ['key' => 'player_name', 'label' => 'اسم السباح', 'value' => (string) ($player['player_name'] ?? '—')],
        ['key' => 'birth_year', 'label' => 'سنة الميلاد', 'value' => $birthYear],
        ['key' => 'age', 'label' => 'السن', 'value' => swimmerPortalAge((string) ($player['birth_date'] ?? ''))],
        ['key' => 'subscription_name', 'label' => 'المجموعة', 'value' => (string) (($player['subscription_name'] ?? '') !== '' ? $player['subscription_name'] : '—')],
        ['key' => 'subscription_category', 'label' => 'المستوى', 'value' => (string) (($player['subscription_category'] ?? '') !== '' ? $player['subscription_category'] : '—')],
        ['key' => 'subscription_branch', 'label' => 'الفرع', 'value' => (string) (($player['subscription_branch'] ?? '') !== '' ? $player['subscription_branch'] : '—')],
        ['key' => 'subscription_coach_name', 'label' => 'المدرب', 'value' => (string) (($player['subscription_coach_name'] ?? '') !== '' ? $player['subscription_coach_name'] : '—')],
        ['key' => 'subscription_training_schedule', 'label' => 'الأيام والساعة', 'value' => formatAcademyTrainingSchedule((string) ($player['subscription_training_schedule'] ?? ''))],
        ['key' => 'available_exercises_count', 'label' => 'عدد التمارين', 'value' => (string) ((int) ($player['available_exercises_count'] ?? 0))],
        ['key' => 'subscription_start_date', 'label' => 'بداية الاشتراك', 'value' => swimmerPortalFormatDate((string) ($player['subscription_start_date'] ?? ''))],
        ['key' => 'subscription_end_date', 'label' => 'نهاية الاشتراك', 'value' => swimmerPortalFormatDate((string) ($player['subscription_end_date'] ?? ''))],
        ['key' => 'subscription_amount', 'label' => 'الإجمالي', 'value' => number_format((float) ($player['subscription_amount'] ?? 0), 2) . ' ج.م'],
        ['key' => 'paid_amount', 'label' => 'المدفوع', 'value' => number_format((float) ($player['paid_amount'] ?? 0), 2) . ' ج.م'],
        ['key' => 'remaining_amount', 'label' => 'المتبقي', 'value' => number_format((float) ($player['remaining_amount'] ?? 0), 2) . ' ج.م'],
        ['key' => 'stars_count', 'label' => 'عدد النجوم', 'value' => swimmerPortalStarsText((int) swimmerPortalResolvedStarsCount((string) ($player['subscription_category'] ?? ''), $player['stars_count'] ?? null))],
        ['key' => 'last_star_date', 'label' => 'تاريخ آخر نجمة', 'value' => swimmerPortalFormatDate((string) ($player['last_star_date'] ?? ''))],
    ];
}

$siteSettings = getSiteSettings($pdo);
$academyName = $siteSettings['academy_name'];
$academyLogoPath = $siteSettings['academy_logo_path'];
$academyLogoInitial = getAcademyLogoInitial($academyName);
$socialLinks = swimmerPortalSocialLinks($siteSettings);
$message = '';
$messageType = 'success';
$submittedBarcode = '';
$activeSection = swimmerPortalActiveSection((string) ($_GET['section'] ?? 'info'));

if (isset($_GET['logout'])) {
    unset($_SESSION[SWIMMER_PORTAL_SESSION_KEY]);
    header('Location: swimmer_portal.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $activeSection = swimmerPortalActiveSection((string) ($_POST['redirect_section'] ?? $activeSection));

    if ($action === 'login') {
        $submittedBarcode = swimmerPortalBarcode((string) ($_POST['barcode'] ?? ''));
        $submittedPassword = (string) ($_POST['password'] ?? '');

        if ($submittedBarcode === '' || $submittedPassword === '') {
            $message = '❌ أدخل الباركود وكلمة السر.';
            $messageType = 'error';
        } else {
            $player = swimmerPortalPlayerByBarcode($pdo, $submittedBarcode);
            $storedHash = (string) ($player['password_hash'] ?? '');
            $loginSuccess = false;

            if ($player !== null) {
                if ($storedHash !== '' && password_verify($submittedPassword, $storedHash)) {
                    $loginSuccess = true;
                    if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
                        $rehashStmt = $pdo->prepare('UPDATE academy_players SET password_hash = ? WHERE id = ?');
                        $rehashStmt->execute([password_hash($submittedPassword, PASSWORD_DEFAULT), (int) $player['id']]);
                    }
                }
            }

            if ($loginSuccess) {
                $_SESSION[SWIMMER_PORTAL_SESSION_KEY] = (int) $player['id'];
                header('Location: swimmer_portal.php?section=info');
                exit;
            }

            $message = '❌ بيانات الدخول غير صحيحة.';
            $messageType = 'error';
        }
    } elseif ($action !== '' && !swimmerPortalValidToken($_POST['csrf_token'] ?? null)) {
        $message = '❌ انتهت صلاحية الطلب.';
        $messageType = 'error';
    }
}

$player = isset($_SESSION[SWIMMER_PORTAL_SESSION_KEY])
    ? swimmerPortalPlayerById($pdo, (int) $_SESSION[SWIMMER_PORTAL_SESSION_KEY])
    : null;

if ($player === null) {
    unset($_SESSION[SWIMMER_PORTAL_SESSION_KEY]);
}

if ($player !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && swimmerPortalValidToken($_POST['csrf_token'] ?? null)) {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'nutrition_chat') {
        $age = (int) swimmerPortalArabicNumbers((string) ($_POST['age'] ?? '0'));
        $weight = (float) swimmerPortalArabicNumbers((string) ($_POST['weight'] ?? '0'));
        $bodyFat = (float) swimmerPortalArabicNumbers((string) ($_POST['body_fat'] ?? '0'));

        if ($age <= 0 || $weight <= 0 || $bodyFat <= 0 || $bodyFat > SWIMMER_NUTRITION_MAX_BODY_FAT_PERCENTAGE) {
            $message = '❌ أدخل البيانات بشكل صحيح.';
            $messageType = 'error';
        } else {
            $plan = swimmerPortalNutritionPlan($age, $weight, $bodyFat);
            $_SESSION['swimmer_nutrition_chat'][$player['id']][] = [
                'prompt' => 'السن: ' . $age . ' • الوزن: ' . number_format($weight, 1) . ' • نسبة الدهون: ' . number_format($bodyFat, 1),
                'response' => $plan,
            ];
            header('Location: swimmer_portal.php?section=nutrition');
            exit;
        }
    } elseif ($action === 'upload_file') {
        $fileType = trim((string) ($_POST['file_type'] ?? ''));

        if ($fileType === 'profile_image') {
            $upload = swimmerPortalUploadImage($_FILES['profile_image_file'] ?? [], SWIMMER_PORTAL_UPLOAD_DIR, SWIMMER_PORTAL_UPLOAD_PUBLIC_DIR, 'swimmer-profile');
            if (!empty($upload['error']) || empty($upload['path'])) {
                $message = '❌ ' . ((string) ($upload['message'] ?? '') !== '' ? (string) $upload['message'] : 'تعذر رفع الصورة الشخصية.');
                $messageType = 'error';
            } else {
                $updateStmt = $pdo->prepare('UPDATE academy_players SET player_image_path = ? WHERE id = ?');
                $updateStmt->execute([$upload['path'], (int) $player['id']]);
                swimmerPortalDeleteImage((string) ($player['player_image_path'] ?? ''));
                header('Location: swimmer_portal.php?section=files');
                exit;
            }
        } elseif ($fileType === 'birth_certificate') {
            $upload = swimmerPortalUploadImage($_FILES['birth_certificate_file'] ?? [], SWIMMER_PORTAL_UPLOAD_DIR, SWIMMER_PORTAL_UPLOAD_PUBLIC_DIR, 'swimmer-birth-certificate');
            if (!empty($upload['error']) || empty($upload['path'])) {
                $message = '❌ تعذر رفع الملف.';
                $messageType = 'error';
            } else {
                $updateStmt = $pdo->prepare('UPDATE academy_players SET birth_certificate_path = ? WHERE id = ?');
                $updateStmt->execute([$upload['path'], (int) $player['id']]);
                swimmerPortalDeleteImage((string) ($player['birth_certificate_path'] ?? ''));
                header('Location: swimmer_portal.php?section=files');
                exit;
            }
        } elseif ($fileType === 'federation_card') {
            $upload = swimmerPortalUploadImage($_FILES['federation_card_file'] ?? [], SWIMMER_PORTAL_UPLOAD_DIR, SWIMMER_PORTAL_UPLOAD_PUBLIC_DIR, 'swimmer-federation');
            if (!empty($upload['error']) || empty($upload['path'])) {
                $message = '❌ تعذر رفع الملف.';
                $messageType = 'error';
            } else {
                $updateStmt = $pdo->prepare('UPDATE academy_players SET federation_card_path = ? WHERE id = ?');
                $updateStmt->execute([$upload['path'], (int) $player['id']]);
                swimmerPortalDeleteImage((string) ($player['federation_card_path'] ?? ''));
                header('Location: swimmer_portal.php?section=files');
                exit;
            }
        } elseif ($fileType === 'medical_report') {
            $upload = swimmerPortalUploadImages($_FILES['medical_report_files'] ?? [], SWIMMER_PORTAL_UPLOAD_DIR, SWIMMER_PORTAL_UPLOAD_PUBLIC_DIR, 'swimmer-medical');
            if (!empty($upload['error']) || ($upload['paths'] ?? []) === []) {
                $message = '❌ تعذر رفع الملف.';
                $messageType = 'error';
            } else {
                $existingMedicalPaths = swimmerPortalMedicalReportFiles($player['medical_report_files'] ?? null, $player['medical_report_path'] ?? null);
                $updatedMedicalPaths = array_values(array_unique(array_merge($existingMedicalPaths, $upload['paths'])));
                $updateStmt = $pdo->prepare('UPDATE academy_players SET medical_report_path = ?, medical_report_files = ? WHERE id = ?');
                $updateStmt->execute([
                    $updatedMedicalPaths[0] ?? null,
                    swimmerPortalEncodeMedicalReportFiles($updatedMedicalPaths),
                    (int) $player['id'],
                ]);
                header('Location: swimmer_portal.php?section=files');
                exit;
            }
        }
    } elseif ($action === 'delete_file') {
        $fileType = trim((string) ($_POST['file_type'] ?? ''));
        if ($fileType === 'profile_image') {
            $updateStmt = $pdo->prepare('UPDATE academy_players SET player_image_path = NULL WHERE id = ?');
            $updateStmt->execute([(int) $player['id']]);
            swimmerPortalDeleteImage((string) ($player['player_image_path'] ?? ''));
        } elseif ($fileType === 'birth_certificate') {
            $updateStmt = $pdo->prepare('UPDATE academy_players SET birth_certificate_path = NULL WHERE id = ?');
            $updateStmt->execute([(int) $player['id']]);
            swimmerPortalDeleteImage((string) ($player['birth_certificate_path'] ?? ''));
        } elseif ($fileType === 'federation_card') {
            $updateStmt = $pdo->prepare('UPDATE academy_players SET federation_card_path = NULL WHERE id = ?');
            $updateStmt->execute([(int) $player['id']]);
            swimmerPortalDeleteImage((string) ($player['federation_card_path'] ?? ''));
        } elseif ($fileType === 'medical_report') {
            $medicalPaths = swimmerPortalMedicalReportFiles($player['medical_report_files'] ?? null, $player['medical_report_path'] ?? null);
            swimmerPortalDeleteImages($medicalPaths);
            $updateStmt = $pdo->prepare('UPDATE academy_players SET medical_report_path = NULL, medical_report_files = NULL WHERE id = ?');
            $updateStmt->execute([(int) $player['id']]);
        }
        header('Location: swimmer_portal.php?section=files');
        exit;
    } elseif ($action === 'card_request') {
        $upload = swimmerPortalUploadImage($_FILES['card_request_image'] ?? [], SWIMMER_PORTAL_CARD_REQUESTS_UPLOAD_DIR, SWIMMER_PORTAL_CARD_REQUESTS_UPLOAD_PUBLIC_DIR, 'card-request');
        if (!empty($upload['error']) || empty($upload['path'])) {
            $message = '❌ ' . ((string) ($upload['message'] ?? '') !== '' ? (string) $upload['message'] : 'تعذر رفع الصورة.');
            $messageType = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $playerLockStmt = $pdo->prepare(
                    'SELECT card_request_submitted_at
                     FROM academy_players
                     WHERE id = ?
                     LIMIT 1
                     FOR UPDATE'
                );
                $playerLockStmt->execute([(int) $player['id']]);
                $lockedPlayer = $playerLockStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                if ($lockedPlayer === null || !empty($lockedPlayer['card_request_submitted_at'])) {
                    $pdo->rollBack();
                    swimmerPortalDeleteImage((string) $upload['path']);
                    $message = '❌ تم إرسال طلب الكارنية من قبل ولا يمكن تكراره.';
                    $messageType = 'error';
                } else {
                    $existingRequestStmt = $pdo->prepare('SELECT id FROM swimmer_card_requests WHERE player_id = ? LIMIT 1');
                    $existingRequestStmt->execute([(int) $player['id']]);

                    if ($existingRequestStmt->fetchColumn()) {
                        $pdo->rollBack();
                        swimmerPortalDeleteImage((string) $upload['path']);
                        $message = '❌ تم إرسال طلب الكارنية من قبل ولا يمكن تكراره.';
                        $messageType = 'error';
                    } else {
                        $markRequestStmt = $pdo->prepare(
                            'UPDATE academy_players
                             SET card_request_submitted_at = NOW()
                             WHERE id = ? AND card_request_submitted_at IS NULL'
                        );
                        $markRequestStmt->execute([(int) $player['id']]);

                        if ($markRequestStmt->rowCount() === 0) {
                            $pdo->rollBack();
                            swimmerPortalDeleteImage((string) $upload['path']);
                            $message = '❌ تم إرسال طلب الكارنية من قبل ولا يمكن تكراره.';
                            $messageType = 'error';
                        } else {
                            $insertStmt = $pdo->prepare('INSERT INTO swimmer_card_requests (player_id, player_name_snapshot, request_image_path) VALUES (?, ?, ?)');
                            $insertStmt->execute([(int) $player['id'], (string) ($player['player_name'] ?? ''), $upload['path']]);
                            $pdo->commit();
                            header('Location: swimmer_portal.php?section=card-request');
                            exit;
                        }
                    }
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                swimmerPortalDeleteImage((string) $upload['path']);
                error_log('تعذر إرسال طلب الكارنية للاعب رقم ' . (int) ($player['id'] ?? 0) . ': ' . $exception->getMessage());
                $message = '❌ حدث خطأ أثناء إرسال الطلب.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = trim((string) ($_POST['new_password'] ?? ''));
        $confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));
        $storedHash = (string) ($player['password_hash'] ?? '');
        $passwordValid = $storedHash !== '' && password_verify($currentPassword, $storedHash);

        if (!$passwordValid) {
            $message = '❌ كلمة السر الحالية غير صحيحة.';
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
            $updateStmt = $pdo->prepare('UPDATE academy_players SET password_hash = ? WHERE id = ?');
            $updateStmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $player['id']]);
            header('Location: swimmer_portal.php?section=password');
            exit;
        }
    }

    $player = swimmerPortalPlayerById($pdo, (int) $player['id']);
}

$products = swimmerPortalProducts($pdo);
$nutritionHistory = $player !== null ? ($_SESSION['swimmer_nutrition_chat'][$player['id']] ?? []) : [];
$notifications = $player !== null ? swimmerPortalNotifications($pdo, $player) : [];
$offers = swimmerPortalOffers($pdo);
$cardRequests = $player !== null ? swimmerPortalCardRequests($pdo, (int) $player['id']) : [];
$hasSubmittedCardRequest = $player !== null && (!empty($player['card_request_submitted_at']) || $cardRequests !== []);
$attendanceRecords = $player !== null ? swimmerPortalAttendanceRecords($pdo, (int) $player['id']) : [];
$attendanceSummary = swimmerPortalAttendanceSummary($attendanceRecords);
$birthCertificatePath = $player !== null ? swimmerPortalNormalizePath($player['birth_certificate_path'] ?? null) : null;
$medicalReportPaths = $player !== null ? swimmerPortalMedicalReportFiles($player['medical_report_files'] ?? null, $player['medical_report_path'] ?? null) : [];
$playerImagePath = $player !== null ? swimmerPortalNormalizePlayerImagePath($player['player_image_path'] ?? null) : null;
$infoRows = $player !== null ? swimmerPortalInfoRows($player) : [];
$playerBarcodeValue = $player !== null ? swimmerPortalBarcode((string) ($player['barcode'] ?? '')) : '';
$playerBarcodeImageSrc = $playerBarcodeValue !== '' ? swimmerPortalBarcodeImageSrc($playerBarcodeValue) : null;
$journeyLevels = swimmerPortalJourneyLevels($pdo);
$currentEvaluationMonth = date('Y-m');
$previousEvaluationMonth = date('Y-m', strtotime('first day of last month'));
$currentGroupEvaluation = $player !== null ? swimmerPortalGroupEvaluation($pdo, (int) ($player['subscription_id'] ?? 0), $currentEvaluationMonth) : null;
$previousGroupEvaluation = $player !== null ? swimmerPortalGroupEvaluation($pdo, (int) ($player['subscription_id'] ?? 0), $previousEvaluationMonth) : null;
$menuItems = [
    'info' => ['label' => 'معلومات السباح', 'icon' => '👤', 'accent' => 'cyan'],
    'attendance' => ['label' => 'الحضور', 'icon' => '📅', 'accent' => 'blue'],
    'nutrition' => ['label' => 'دكتور التغذية', 'icon' => '🥗', 'accent' => 'green'],
    'store' => ['label' => 'المتجر', 'icon' => '🛍️', 'accent' => 'purple'],
    'files' => ['label' => 'ملفات السباح', 'icon' => '📁', 'accent' => 'orange'],
    'card-request' => ['label' => 'طلب الكارنية', 'icon' => '🪪', 'accent' => 'pink'],
    'notifications' => ['label' => 'اشعارات السباح', 'icon' => '🔔', 'accent' => 'amber'],
    'offers' => ['label' => 'العروض', 'icon' => '🎁', 'accent' => 'red'],
    'group-evaluation' => ['label' => 'تقييم مجموعة السباح', 'icon' => '⭐', 'accent' => 'gold'],
    'journey' => ['label' => 'مشوار السباح', 'icon' => '🏊', 'accent' => 'teal'],
    'password' => ['label' => 'تغيير كلمة السر', 'icon' => '🔐', 'accent' => 'slate'],
];
$defaultMenuItem = $menuItems['info'] ?? ['label' => ''];
if ($defaultMenuItem['label'] === '' && $menuItems !== []) {
    $firstMenuItem = reset($menuItems);
    $defaultMenuItem = is_array($firstMenuItem) ? $firstMenuItem : $defaultMenuItem;
}
$activeMenuItem = $menuItems[$activeSection] ?? $defaultMenuItem;
$activeMenuLabel = (string) ($activeMenuItem['label'] ?? '');
$swimmerPortalCssPath = __DIR__ . '/assets/css/swimmer-portal.css';
$swimmerPortalJsPath = __DIR__ . '/assets/js/swimmer-portal.js';
$swimmerPortalCssVersion = is_file($swimmerPortalCssPath) ? substr(sha1((string) filemtime($swimmerPortalCssPath)), 0, 12) : '1';
$swimmerPortalJsVersion = is_file($swimmerPortalJsPath) ? substr(sha1((string) filemtime($swimmerPortalJsPath)), 0, 12) : '1';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بوابة السباحين - <?php echo htmlspecialchars($academyName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="assets/css/swimmer-portal.css?v=<?php echo $swimmerPortalCssVersion; ?>">
</head>
<body class="light-mode" data-theme-key="swimmer-portal-theme">
<div class="portal-page-shell">
    <?php if ($player === null): ?>
        <section class="portal-login-card">
            <div class="portal-login-head">
                <div class="brand-block">
                    <div class="brand-logo">
                        <?php if ($academyLogoPath !== null): ?>
                            <img src="<?php echo htmlspecialchars($academyLogoPath, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($academyName, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php else: ?>
                            <span><?php echo htmlspecialchars($academyLogoInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span class="page-badge">🏊 بوابة السباحين</span>
                        <h1><?php echo htmlspecialchars($academyName, ENT_QUOTES, 'UTF-8'); ?></h1>
                    </div>
                </div>
                <div class="theme-switch-box">
                    <span>☀️</span>
                    <label class="switch">
                        <input type="checkbox" id="themeToggle">
                        <span class="slider"></span>
                    </label>
                    <span>🌙</span>
                </div>
            </div>

            <?php if ($message !== ''): ?>
                <div class="message-box <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" class="portal-login-form" autocomplete="off">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label for="barcode">باركود السباح</label>
                    <input type="text" name="barcode" id="barcode" value="<?php echo htmlspecialchars($submittedBarcode, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="password">كلمة السر</label>
                    <input type="password" name="password" id="password" required>
                </div>
                <button type="submit" class="primary-btn login-btn">تسجيل الدخول</button>
            </form>
        </section>
    <?php else: ?>
        <div class="portal-layout">
            <aside class="portal-sidebar" id="portalSidebar">
                <div class="sidebar-panel-head">
                    <div class="sidebar-panel-title">
                        <span class="sidebar-panel-kicker">بوابة السباح</span>
                        <strong>القائمة الرئيسية</strong>
                    </div>
                    <button type="button" class="sidebar-toggle-btn" id="sidebarToggle" aria-expanded="true" aria-controls="portalSidebar">
                        <span class="sidebar-toggle-icon">☰</span>
                        <span class="sidebar-toggle-text">طي القائمة</span>
                    </button>
                </div>
                <div class="sidebar-top-block">
                    <div class="player-profile-avatar <?php echo $playerImagePath === null ? 'player-profile-avatar-logo' : ''; ?>">
                        <?php if ($playerImagePath !== null): ?>
                            <img src="<?php echo htmlspecialchars($playerImagePath, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars('صورة ' . (string) ($player['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <?php elseif ($academyLogoPath !== null): ?>
                            <img src="<?php echo htmlspecialchars($academyLogoPath, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($academyName, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php else: ?>
                            <span><?php echo htmlspecialchars($academyLogoInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                    <h2><?php echo htmlspecialchars((string) ($player['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h2>
                    <span class="sidebar-subtitle"><?php echo htmlspecialchars((string) ($player['subscription_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <nav class="sidebar-nav">
                    <?php foreach ($menuItems as $sectionKey => $sectionItem): ?>
                        <?php
                        $sectionLabel = (string) ($sectionItem['label'] ?? '');
                        $sectionIcon = (string) ($sectionItem['icon'] ?? '•');
                        $sectionAccent = (string) ($sectionItem['accent'] ?? 'cyan');
                        ?>
                        <a
                            href="swimmer_portal.php?section=<?php echo htmlspecialchars($sectionKey, ENT_QUOTES, 'UTF-8'); ?>"
                            class="sidebar-link sidebar-accent-<?php echo htmlspecialchars($sectionAccent, ENT_QUOTES, 'UTF-8'); ?> <?php echo $activeSection === $sectionKey ? 'active' : ''; ?>"
                        >
                            <span class="sidebar-link-icon" aria-hidden="true"><?php echo htmlspecialchars($sectionIcon, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="sidebar-link-label"><?php echo htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <a href="swimmer_portal.php?logout=1" class="primary-btn logout-btn">
                    <span aria-hidden="true">🚪</span>
                    <span class="logout-btn-text">تسجيل الخروج</span>
                </a>
            </aside>

            <main class="portal-main">
                <header class="portal-main-header">
                    <div>
                        <span class="page-badge"><?php echo htmlspecialchars($activeMenuLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        <h1>لوحة تحكم حساب السباح</h1>
                    </div>
                    <div class="theme-switch-box">
                        <span>☀️</span>
                        <label class="switch">
                            <input type="checkbox" id="themeToggle">
                            <span class="slider"></span>
                        </label>
                        <span>🌙</span>
                    </div>
                </header>

                <?php if ($message !== ''): ?>
                    <div class="message-box <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <?php if ($activeSection === 'info'): ?>
                    <section class="portal-section-card">
                        <div class="info-hero-card">
                            <div class="player-profile-avatar info-hero-avatar <?php echo $playerImagePath === null ? 'player-profile-avatar-logo' : ''; ?>">
                                <?php if ($playerImagePath !== null): ?>
                                    <img src="<?php echo htmlspecialchars($playerImagePath, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars('صورة ' . (string) ($player['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php elseif ($academyLogoPath !== null): ?>
                                    <img src="<?php echo htmlspecialchars($academyLogoPath, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($academyName, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php else: ?>
                                    <span><?php echo htmlspecialchars($academyLogoInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="info-hero-content">
                                <span class="page-badge">👤 معلومات السباح</span>
                                <h2><?php echo htmlspecialchars((string) ($player['player_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h2>
                                <p><?php echo htmlspecialchars((string) (($player['subscription_name'] ?? '') !== '' ? $player['subscription_name'] : 'لا توجد مجموعة مضافة حالياً'), ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                        </div>
                        <div class="overview-grid">
                            <?php foreach ($infoRows as $infoRow): ?>
                                <?php
                                $rowKey = (string) ($infoRow['key'] ?? '');
                                $rowLabel = (string) ($infoRow['label'] ?? '');
                                $rowValue = (string) ($infoRow['value'] ?? '—');
                                ?>
                                <article class="overview-card <?php echo $rowKey === SWIMMER_PORTAL_INFO_ROW_BARCODE ? 'barcode-card' : ''; ?>">
                                    <span><?php echo htmlspecialchars($rowLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if ($rowKey === SWIMMER_PORTAL_INFO_ROW_BARCODE && $playerBarcodeImageSrc !== null): ?>
                                        <div class="barcode-display">
                                            <div class="barcode-graphic">
                                                <img class="barcode-svg" src="<?php echo htmlspecialchars($playerBarcodeImageSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($playerBarcodeValue, ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <strong class="barcode-value"><?php echo htmlspecialchars($rowValue, ENT_QUOTES, 'UTF-8'); ?></strong>
                                        </div>
                                    <?php else: ?>
                                        <strong><?php echo htmlspecialchars($rowValue, ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php elseif ($activeSection === 'attendance'): ?>
                    <section class="portal-section-card">
                        <div class="overview-grid attendance-summary-grid">
                            <article class="overview-card">
                                <span>إجمالي الحضور</span>
                                <strong><?php echo (int) ($attendanceSummary['total'] ?? 0); ?></strong>
                            </article>
                            <article class="overview-card">
                                <span>عدد الحضور</span>
                                <strong><?php echo (int) ($attendanceSummary['present'] ?? 0); ?></strong>
                            </article>
                            <article class="overview-card">
                                <span>آخر تحديث</span>
                                <strong><?php echo htmlspecialchars(swimmerPortalAttendanceLastUpdated($attendanceRecords), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </article>
                        </div>
                    </section>
                    <section class="portal-section-card">
                        <?php if ($attendanceRecords !== []): ?>
                            <div class="attendance-history-list">
                                <?php foreach ($attendanceRecords as $attendanceRecord): ?>
                                    <article class="attendance-history-card">
                                        <div class="attendance-card-head">
                                            <div>
                                                <strong><?php echo htmlspecialchars(swimmerPortalFormatDate((string) ($attendanceRecord['attendance_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                                                <span><?php echo htmlspecialchars((string) ($attendanceRecord['subscription_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                            <span class="attendance-status-badge present">حاضر</span>
                                        </div>
                                        <div class="attendance-details-grid">
                                            <div class="attendance-detail-item">
                                                <span>المستوى</span>
                                                <strong><?php echo htmlspecialchars((string) ($attendanceRecord['subscription_category'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                            </div>
                                            <div class="attendance-detail-item">
                                                <span>الفرع</span>
                                                <strong><?php echo htmlspecialchars((string) ($attendanceRecord['subscription_branch'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                            </div>
                                            <div class="attendance-detail-item">
                                                <span>المدرب</span>
                                                <strong><?php echo htmlspecialchars((string) ($attendanceRecord['coach_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                            </div>
                                            <div class="attendance-detail-item">
                                                <span>الأيام والساعة</span>
                                                <strong><?php echo htmlspecialchars(formatAcademyTrainingSchedule((string) ($attendanceRecord['training_schedule'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                                            </div>
                                            <div class="attendance-detail-item">
                                                <span>وقت التسجيل</span>
                                                <strong><?php echo htmlspecialchars(swimmerPortalFormatDateTime((string) ($attendanceRecord['marked_at'] ?? $attendanceRecord['attendance_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                                            </div>
                                        </div>
                                        <div class="attendance-note-box">
                                            <span>الملاحظة</span>
                                            <p><?php echo htmlspecialchars((string) (($attendanceRecord['note'] ?? '') !== '' ? $attendanceRecord['note'] : 'لا توجد ملاحظات على هذا اليوم.'), ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="portal-empty-note">لا توجد سجلات حضور أو غياب للسباح حتى الآن.</div>
                        <?php endif; ?>
                    </section>
                <?php elseif ($activeSection === 'nutrition'): ?>
                    <section class="portal-section-card nutrition-layout">
                        <div class="nutrition-chat-card">
                            <h2>دكتور التغذية</h2>
                            <div class="chat-history">
                                <?php foreach ($nutritionHistory as $chatItem): ?>
                                    <div class="chat-bubble swimmer-bubble"><?php echo htmlspecialchars((string) ($chatItem['prompt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="chat-bubble ai-bubble"><?php echo nl2br(htmlspecialchars((string) ($chatItem['response'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <form method="POST" class="portal-form-card stack-form" autocomplete="off">
                            <input type="hidden" name="action" value="nutrition_chat">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(swimmerPortalToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_section" value="nutrition">
                            <div class="form-group">
                                <label for="age">السن</label>
                                <input type="number" name="age" id="age" min="1" required>
                            </div>
                            <div class="form-group">
                                <label for="weight">الوزن</label>
                                <input type="number" name="weight" id="weight" min="1" step="0.1" required>
                            </div>
                            <div class="form-group">
                                <label for="body_fat">نسبة الدهون</label>
                                <input type="number" name="body_fat" id="body_fat" min="1" max="<?php echo SWIMMER_NUTRITION_MAX_BODY_FAT_PERCENTAGE; ?>" step="0.1" required>
                            </div>
                            <button type="submit" class="primary-btn">إرسال</button>
                        </form>
                    </section>
                <?php elseif ($activeSection === 'store'): ?>
                    <section class="portal-section-card">
                        <div class="store-grid">
                            <?php foreach ($products as $product): ?>
                                <?php $productImagePath = swimmerPortalNormalizeStorePath($product['product_image_path'] ?? null); ?>
                                <article class="store-card">
                                    <div class="store-image-wrap">
                                        <?php if ($productImagePath !== null): ?>
                                            <img src="<?php echo htmlspecialchars($productImagePath, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string) ($product['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php else: ?>
                                            <div class="item-image-placeholder">🛍️</div>
                                        <?php endif; ?>
                                    </div>
                                    <strong><?php echo htmlspecialchars((string) ($product['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span><?php echo number_format((float) ($product['product_price'] ?? 0), 2); ?> ج.م</span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php elseif ($activeSection === 'files'): ?>
                    <section class="portal-section-card files-layout">
                        <?php
                        $requiredFiles = [];
                        $requiredFiles[] = [
                            'title' => 'الصورة الشخصية',
                            'type' => 'profile_image',
                            'single_path' => $playerImagePath,
                            'paths' => $playerImagePath !== null ? [$playerImagePath] : [],
                            'input_name' => 'profile_image_file',
                            'multiple' => false,
                        ];
                        if (!empty($player['birth_certificate_required'])) {
                            $requiredFiles[] = [
                                'title' => 'شهادة الميلاد',
                                'type' => 'birth_certificate',
                                'single_path' => $birthCertificatePath,
                                'paths' => $birthCertificatePath !== null ? [$birthCertificatePath] : [],
                                'input_name' => 'birth_certificate_file',
                                'multiple' => false,
                            ];
                        }
                        if (!empty($player['medical_report_required'])) {
                            $requiredFiles[] = [
                                'title' => 'التقرير الطبي',
                                'type' => 'medical_report',
                                'single_path' => $medicalReportPaths[0] ?? null,
                                'paths' => $medicalReportPaths,
                                'input_name' => 'medical_report_files[]',
                                'multiple' => true,
                            ];
                        }
                        if (!empty($player['federation_card_required'])) {
                            $requiredFiles[] = [
                                'title' => 'كارنية الاتحاد',
                                'type' => 'federation_card',
                                'single_path' => swimmerPortalNormalizePath($player['federation_card_path'] ?? null),
                                'paths' => swimmerPortalNormalizePath($player['federation_card_path'] ?? null) !== null ? [swimmerPortalNormalizePath($player['federation_card_path'] ?? null)] : [],
                                'input_name' => 'federation_card_file',
                                'multiple' => false,
                            ];
                        }
                        ?>
                        <?php foreach ($requiredFiles as $requiredFile): ?>
                            <article class="file-box">
                                <div class="file-box-head">
                                    <strong><?php echo htmlspecialchars($requiredFile['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if ($requiredFile['single_path'] !== null): ?>
                                        <a href="<?php echo htmlspecialchars($requiredFile['single_path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="secondary-link">عرض</a>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($requiredFile['multiple'])): ?>
                                    <div class="file-gallery">
                                        <?php foreach ($requiredFile['paths'] as $filePath): ?>
                                            <a href="<?php echo htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="gallery-item">
                                                <img src="<?php echo htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($requiredFile['title'], ENT_QUOTES, 'UTF-8'); ?>">
                                            </a>
                                        <?php endforeach; ?>
                                        <?php if ($requiredFile['paths'] === []): ?>
                                            <div class="empty-state">غير مرفوع</div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="single-file-preview">
                                        <?php if ($requiredFile['single_path'] !== null): ?>
                                            <img src="<?php echo htmlspecialchars($requiredFile['single_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($requiredFile['title'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php else: ?>
                                            <div class="empty-state">غير مرفوع</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <form method="POST" enctype="multipart/form-data" class="stack-form" autocomplete="off">
                                    <input type="hidden" name="action" value="upload_file">
                                    <input type="hidden" name="file_type" value="<?php echo htmlspecialchars($requiredFile['type'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="redirect_section" value="files">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(swimmerPortalToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="file" name="<?php echo htmlspecialchars($requiredFile['input_name'], ENT_QUOTES, 'UTF-8'); ?>" accept="image/*" <?php echo !empty($requiredFile['multiple']) ? 'multiple' : ''; ?> required>
                                    <button type="submit" class="primary-btn">رفع</button>
                                </form>
                                <?php if ($requiredFile['paths'] !== []): ?>
                                    <form method="POST" class="inline-form" autocomplete="off">
                                        <input type="hidden" name="action" value="delete_file">
                                        <input type="hidden" name="file_type" value="<?php echo htmlspecialchars($requiredFile['type'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="redirect_section" value="files">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(swimmerPortalToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="primary-btn danger-btn">حذف</button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php elseif ($activeSection === 'card-request'): ?>
                    <section class="portal-section-card card-request-layout">
                        <?php if (!$hasSubmittedCardRequest): ?>
                            <form method="POST" enctype="multipart/form-data" class="portal-form-card stack-form" autocomplete="off">
                                <input type="hidden" name="action" value="card_request">
                                <input type="hidden" name="redirect_section" value="card-request">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(swimmerPortalToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="form-group">
                                    <label for="card_request_image">صورة السباح</label>
                                    <input type="file" name="card_request_image" id="card_request_image" accept="image/*" required>
                                </div>
                                <button type="submit" class="primary-btn">رفع</button>
                            </form>
                        <?php else: ?>
                            <div class="portal-form-card stack-form">
                                <strong>تم إرسال طلب الكارنية بالفعل</strong>
                                <p>يمكن للسباح إرسال طلب كارنية مرة واحدة فقط، وتم تسجيل طلبك بنجاح.</p>
                            </div>
                        <?php endif; ?>
                        <div class="request-history-grid">
                            <?php foreach ($cardRequests as $request): ?>
                                <?php $requestImagePath = swimmerPortalNormalizeCardRequestPath($request['request_image_path'] ?? null); ?>
                                <article class="store-card">
                                    <div class="store-image-wrap">
                                        <?php if ($requestImagePath !== null): ?>
                                            <img src="<?php echo htmlspecialchars($requestImagePath, ENT_QUOTES, 'UTF-8'); ?>" alt="طلب الكارنية">
                                        <?php else: ?>
                                            <div class="item-image-placeholder">🪪</div>
                                        <?php endif; ?>
                                    </div>
                                    <strong><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string) ($request['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span class="status-chip <?php echo !empty($request['approved_exported_at']) ? 'success-chip' : 'warning-chip'; ?>">
                                        <?php echo !empty($request['approved_exported_at']) ? 'تمت الموافقة' : 'قيد المراجعة'; ?>
                                    </span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php elseif ($activeSection === 'notifications'): ?>
                    <section class="portal-section-card notifications-grid">
                        <?php foreach ($notifications as $notification): ?>
                            <article class="notification-card">
                                <strong><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string) ($notification['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <p><?php echo nl2br(htmlspecialchars((string) ($notification['notification_message'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php elseif ($activeSection === 'offers'): ?>
                    <section class="portal-section-card notifications-grid">
                        <?php if ($offers !== []): ?>
                            <?php foreach ($offers as $offer): ?>
                                <article class="notification-card offer-card">
                                    <div class="split-meta">
                                        <strong><?php echo htmlspecialchars((string) ($offer['offer_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <span class="status-chip success-chip">عرض متاح</span>
                                    </div>
                                    <p><?php echo nl2br(htmlspecialchars((string) ($offer['offer_description'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
                                    <div class="details-grid compact-details-grid">
                                        <div><span>من</span><strong><?php echo htmlspecialchars(swimmerPortalFormatDate((string) ($offer['valid_from'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div><span>إلى</span><strong><?php echo htmlspecialchars(swimmerPortalFormatDate((string) ($offer['valid_until'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="portal-empty-note">لا توجد عروض مضافة حاليًا.</div>
                        <?php endif; ?>
                    </section>
                <?php elseif ($activeSection === 'group-evaluation'): ?>
                    <section class="portal-section-card">
                        <div class="overview-grid attendance-summary-grid">
                            <article class="overview-card">
                                <span>تقييم هذا الشهر</span>
                                <strong><?php echo htmlspecialchars(swimmerPortalEvaluationStars((int) ($currentGroupEvaluation['evaluation_score'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </article>
                            <article class="overview-card">
                                <span>تقييم الشهر السابق</span>
                                <strong><?php echo htmlspecialchars(swimmerPortalEvaluationStars((int) ($previousGroupEvaluation['evaluation_score'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </article>
                            <article class="overview-card">
                                <span>المجموعة</span>
                                <strong><?php echo htmlspecialchars((string) ($player['subscription_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </article>
                        </div>
                        <div class="notifications-grid">
                            <article class="notification-card">
                                <strong><?php echo htmlspecialchars($currentEvaluationMonth, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <p><?php echo nl2br(htmlspecialchars((string) (($currentGroupEvaluation['evaluation_notes'] ?? '') !== '' ? $currentGroupEvaluation['evaluation_notes'] : 'لا توجد ملاحظات مضافة لهذا الشهر.'), ENT_QUOTES, 'UTF-8')); ?></p>
                            </article>
                            <article class="notification-card">
                                <strong><?php echo htmlspecialchars($previousEvaluationMonth, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <p><?php echo nl2br(htmlspecialchars((string) (($previousGroupEvaluation['evaluation_notes'] ?? '') !== '' ? $previousGroupEvaluation['evaluation_notes'] : 'لا توجد ملاحظات مضافة للشهر السابق.'), ENT_QUOTES, 'UTF-8')); ?></p>
                            </article>
                        </div>
                    </section>
                <?php elseif ($activeSection === 'journey'): ?>
                    <section class="portal-section-card">
                        <div class="journey-paths">
                            <?php foreach ($journeyLevels as $journeyPath): ?>
                                <section class="journey-path-card">
                                    <header class="journey-path-head">
                                        <span>المشوار</span>
                                        <strong><?php echo htmlspecialchars((string) ($journeyPath['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </header>
                                    <div class="journey-grid">
                                        <?php foreach (($journeyPath['levels'] ?? []) as $journeyLevel): ?>
                                            <?php $isCurrentLevel = (string) ($player['subscription_category'] ?? '') === (string) $journeyLevel; ?>
                                            <article class="journey-card <?php echo $isCurrentLevel ? 'journey-card-active' : ''; ?>">
                                                <span><?php echo $isCurrentLevel ? 'المستوى الحالي' : 'مستوى'; ?></span>
                                                <strong><?php echo htmlspecialchars((string) $journeyLevel, ENT_QUOTES, 'UTF-8'); ?></strong>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php elseif ($activeSection === 'password'): ?>
                    <section class="portal-section-card password-layout">
                        <form method="POST" class="portal-form-card stack-form" autocomplete="off">
                            <input type="hidden" name="action" value="change_password">
                            <input type="hidden" name="redirect_section" value="password">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(swimmerPortalToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-group">
                                <label for="current_password">كلمة السر الحالية</label>
                                <input type="password" name="current_password" id="current_password" required>
                            </div>
                            <div class="form-group">
                                <label for="new_password">كلمة السر الجديدة</label>
                                <input type="password" name="new_password" id="new_password" required>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">تأكيد كلمة السر</label>
                                <input type="password" name="confirm_password" id="confirm_password" required>
                            </div>
                            <button type="submit" class="primary-btn">حفظ</button>
                        </form>
                    </section>
                <?php endif; ?>
            </main>
        </div>
    <?php endif; ?>

    <?php if ($socialLinks !== []): ?>
        <footer class="portal-footer">
            <strong>تابع <?php echo htmlspecialchars($academyName, ENT_QUOTES, 'UTF-8'); ?></strong>
            <div class="portal-footer-socials">
                <?php foreach ($socialLinks as $platformKey => $socialLink): ?>
                    <a href="<?php echo htmlspecialchars($socialLink['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="portal-social-link portal-social-link-<?php echo htmlspecialchars($platformKey, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($socialLink['label'], ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="portal-social-mark"><?php echo htmlspecialchars($socialLink['mark'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span><?php echo htmlspecialchars($socialLink['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </footer>
    <?php endif; ?>
</div>
<script src="assets/js/swimmer-portal.js?v=<?php echo $swimmerPortalJsVersion; ?>"></script>
</body>
</html>
