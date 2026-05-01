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

if (!userCanAccess($currentUser, "administrators")) {
    header("Location: dashboard.php?access=denied");
    exit;
}

function normalizeArabicNumbers(string $value): string
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

function sanitizeAdministratorName(string $value): string
{
    $value = trim(normalizeArabicNumbers($value));
    $sanitizedValue = preg_replace('/\s+/u', ' ', $value);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function sanitizeAdministratorPhone(string $value): string
{
    $value = trim(normalizeArabicNumbers($value));
    $value = preg_replace('/[^0-9+]/', '', $value);
    if ($value === null) {
        return '';
    }

    $sanitizedValue = preg_replace('/(?!^)\+/', '', $value);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function sanitizeAdministratorBarcode(string $value): string
{
    $value = trim(normalizeArabicNumbers($value));
    $sanitizedValue = preg_replace('/\s+/u', '', $value);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function sanitizeAdministratorPassword(string $value): string
{
    return $value;
}

function getAdministratorLeaveDayOptions(): array
{
    return [
        'Saturday' => 'السبت',
        'Sunday' => 'الأحد',
        'Monday' => 'الاثنين',
        'Tuesday' => 'الثلاثاء',
        'Wednesday' => 'الأربعاء',
        'Thursday' => 'الخميس',
        'Friday' => 'الجمعة',
    ];
}

function sanitizeAdministratorLeaveDays($value): array
{
    $submittedValues = is_array($value) ? $value : [];
    $allowedValues = getAdministratorLeaveDayOptions();
    $sanitizedValues = [];

    foreach ($submittedValues as $dayValue) {
        if (!is_string($dayValue)) {
            continue;
        }

        $normalizedValue = trim($dayValue);
        if ($normalizedValue !== '' && isset($allowedValues[$normalizedValue])) {
            $sanitizedValues[] = $normalizedValue;
        }
    }

    return array_values(array_unique($sanitizedValues));
}

function encodeAdministratorLeaveDays(array $leaveDays): ?string
{
    if (empty($leaveDays)) {
        return null;
    }

    return json_encode($leaveDays, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function decodeAdministratorLeaveDays(?string $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decodedValue = json_decode($value, true);
    if (!is_array($decodedValue)) {
        return [];
    }

    return sanitizeAdministratorLeaveDays($decodedValue);
}

function buildAdministratorLeaveDaysText(array $leaveDays): string
{
    if (empty($leaveDays)) {
        return 'لا توجد إجازات محددة';
    }

    $dayOptions = getAdministratorLeaveDayOptions();
    $leaveDayLabels = [];

    foreach ($leaveDays as $leaveDay) {
        if (isset($dayOptions[$leaveDay])) {
            $leaveDayLabels[] = $dayOptions[$leaveDay];
        }
    }

    return empty($leaveDayLabels)
        ? 'لا توجد إجازات محددة'
        : implode(' - ', $leaveDayLabels);
}

function administratorPasswordLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function isValidAdministratorPassword(string $value): bool
{
    return administratorPasswordLength($value) >= 6;
}

function hasAdministratorPassword($passwordHash): bool
{
    return is_string($passwordHash) && trim($passwordHash) !== '';
}

function buildAdministratorPageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return 'administrators.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function generateAdministratorSecurityToken(): string
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

    throw new RuntimeException('تعذر إنشاء رمز أمان للإداريين');
}

function getAdministratorCsrfToken(): string
{
    if (
        !isset($_SESSION['administrator_csrf_token'])
        || !is_string($_SESSION['administrator_csrf_token'])
        || $_SESSION['administrator_csrf_token'] === ''
    ) {
        try {
            $_SESSION['administrator_csrf_token'] = generateAdministratorSecurityToken();
        } catch (Throwable $exception) {
            error_log('تعذر إنشاء رمز التحقق الخاص بإدارة الإداريين');
            http_response_code(500);
            exit('❌ تعذر تهيئة أمان الصفحة، يرجى إعادة المحاولة لاحقًا');
        }
    }

    return $_SESSION['administrator_csrf_token'];
}

function isValidAdministratorCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getAdministratorCsrfToken(), $submittedToken);
}

const MYSQL_DUPLICATE_KEY_ERROR = 1062;

function decodeAdministratorImagePaths(?string $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decodedValue = json_decode($value, true);
    if (!is_array($decodedValue)) {
        return [];
    }

    $imagePaths = [];
    foreach ($decodedValue as $imagePath) {
        $normalizedPath = normalizeAdministratorImagePublicPath($imagePath);
        if ($normalizedPath !== null) {
            $imagePaths[] = $normalizedPath;
        }
    }

    return array_values(array_unique($imagePaths));
}

function normalizeAdministratorImagePublicPath($imagePath): ?string
{
    if (!is_string($imagePath)) {
        return null;
    }

    $normalizedPath = trim($imagePath);
    if (strpos($normalizedPath, 'uploads/administrators/') !== 0) {
        return null;
    }

    $fileName = basename($normalizedPath);
    if ($fileName === '' || $fileName === '.' || $fileName === '..') {
        return null;
    }

    if ($normalizedPath !== 'uploads/administrators/' . $fileName) {
        return null;
    }

    if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9_-]|\.(?!\.))+$/', $fileName) !== 1) {
        return null;
    }

    return 'uploads/administrators/' . $fileName;
}

function ensureAdministratorUploadDirectoryExists(string $directory): bool
{
    if (is_dir($directory)) {
        return true;
    }

    return mkdir($directory, 0755, true) || is_dir($directory);
}

function generateAdministratorImageToken(): ?string
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

function detectAdministratorImageExtension(string $originalName, string $mimeType): string
{
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $extension = preg_replace('/[^a-z0-9]+/i', '', $extension);

    if ($extension !== '') {
        return $extension;
    }

    $mimeParts = explode('/', strtolower($mimeType), 2);
    $mimeSubtype = $mimeParts[1] ?? '';
    $mimeSubtype = str_replace(['svg+xml', 'x-icon', 'vnd.microsoft.icon'], ['svg', 'ico', 'ico'], $mimeSubtype);
    $mimeSubtype = preg_replace('/[^a-z0-9]+/i', '', $mimeSubtype);

    return $mimeSubtype !== '' ? $mimeSubtype : 'img';
}

function uploadAdministratorImages(array $files, int $administratorId, string $uploadDirectory, string $publicDirectory): array
{
    $storedPaths = [];
    $attemptedCount = 0;
    $errorCount = 0;

    if (!isset($files['name']) || !is_array($files['name'])) {
        return [
            'paths' => $storedPaths,
            'attempted_count' => $attemptedCount,
            'error_count' => $errorCount,
        ];
    }

    foreach ($files['name'] as $fileName) {
        if (trim((string) $fileName) !== '') {
            $attemptedCount++;
        }
    }

    if ($attemptedCount === 0) {
        return [
            'paths' => $storedPaths,
            'attempted_count' => $attemptedCount,
            'error_count' => $errorCount,
        ];
    }

    if (!ensureAdministratorUploadDirectoryExists($uploadDirectory)) {
        return [
            'paths' => $storedPaths,
            'attempted_count' => $attemptedCount,
            'error_count' => $attemptedCount,
        ];
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;

    try {
        $fileCount = count($files['name']);
        for ($index = 0; $index < $fileCount; $index++) {
            $originalName = trim((string) ($files['name'][$index] ?? ''));
            $uploadError = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);

            if ($originalName === '' || $uploadError === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($uploadError !== UPLOAD_ERR_OK) {
                $errorCount++;
                continue;
            }

            $temporaryPath = $files['tmp_name'][$index] ?? '';
            if (!is_string($temporaryPath) || $temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
                $errorCount++;
                continue;
            }

            $mimeType = '';
            if ($finfo !== false) {
                $detectedMimeType = finfo_file($finfo, $temporaryPath);
                if (is_string($detectedMimeType)) {
                    $mimeType = $detectedMimeType;
                }
            } elseif (function_exists('mime_content_type')) {
                $detectedMimeType = mime_content_type($temporaryPath);
                if (is_string($detectedMimeType)) {
                    $mimeType = $detectedMimeType;
                }
            }

            if ($mimeType === '' || strpos($mimeType, 'image/') !== 0) {
                $errorCount++;
                continue;
            }

            $randomPart = generateAdministratorImageToken();
            if ($randomPart === null) {
                $errorCount++;
                continue;
            }

            $extension = detectAdministratorImageExtension($originalName, $mimeType);
            $fileName = sprintf('administrators-%d-%s.%s', $administratorId, $randomPart, $extension);
            $destinationPath = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;

            if (!move_uploaded_file($temporaryPath, $destinationPath)) {
                $errorCount++;
                continue;
            }

            $storedPaths[] = $publicDirectory . '/' . $fileName;
        }
    } finally {
        if ($finfo !== false) {
            finfo_close($finfo);
        }
    }

    return [
        'paths' => $storedPaths,
        'attempted_count' => $attemptedCount,
        'error_count' => $errorCount,
    ];
}

function deleteAdministratorImageFiles(array $imagePaths, string $baseDirectory): bool
{
    $allFilesDeleted = true;

    foreach ($imagePaths as $imagePath) {
        $absolutePath = resolveAdministratorImageAbsolutePath($imagePath, $baseDirectory);
        if ($absolutePath === null) {
            continue;
        }

        if (is_file($absolutePath) && !unlink($absolutePath)) {
            error_log('تعذر حذف صورة أحد الإداريين');
            $allFilesDeleted = false;
        }
    }

    return $allFilesDeleted;
}

function resolveAdministratorImageAbsolutePath($imagePath, string $baseDirectory): ?string
{
    $normalizedPath = normalizeAdministratorImagePublicPath($imagePath);
    if ($normalizedPath === null) {
        return null;
    }

    $uploadsDirectory = realpath($baseDirectory . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'administrators');
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

function buildAdministratorSaveMessage(bool $isUpdate): string
{
    return $isUpdate
        ? "✏️ تم تعديل بيانات الإداري بنجاح ويمكنك إدارة الصور من زر ملفات الإداري"
        : "✅ تم إضافة الإداري بنجاح ويمكنك الآن رفع الصور من زر ملفات الإداري";
}

function buildAdministratorFilesSaveMessage(array $uploadResult): string
{
    $baseMessage = "✅ تم تحديث ملفات الإداري";
    $errorCount = (int) ($uploadResult['error_count'] ?? 0);
    $savedCount = isset($uploadResult['paths']) && is_array($uploadResult['paths']) ? count($uploadResult['paths']) : 0;

    if ($savedCount > 0 && $errorCount === 0) {
        return $baseMessage . " وتم حفظ الصور بنجاح";
    }

    if ($savedCount > 0) {
        return $baseMessage . " وتم حفظ بعض الصور";
    }

    return "❌ تعذر حفظ الصور المرفوعة";
}

function buildAdministratorImageDeleteMessage(bool $wasDeleted): string
{
    return $wasDeleted
        ? "🗑️ تم حذف صورة الإداري بنجاح"
        : "❌ تعذر حذف صورة الإداري";
}

$message = "";
$messageType = "";
$messageContext = "";
$editAdministrator = null;
$filesAdministrator = null;
$administratorImagesUploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'administrators';
$administratorImagesPublicDirectory = 'uploads/administrators';
$selectedFilesAdministratorId = trim($_GET["files"] ?? "");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action !== "" && !isValidAdministratorCsrfToken($_POST["csrf_token"] ?? null)) {
        $message = "❌ انتهت صلاحية الطلب، يرجى إعادة المحاولة";
        $messageType = "error";
        $action = "";
    }

    if ($action === "save") {
        $id = trim($_POST["id"] ?? "");
        $fullName = sanitizeAdministratorName($_POST["full_name"] ?? "");
        $phone = sanitizeAdministratorPhone($_POST["phone"] ?? "");
        $password = sanitizeAdministratorPassword($_POST["password"] ?? "");
        $barcode = sanitizeAdministratorBarcode($_POST["barcode"] ?? "");
        $leaveDays = sanitizeAdministratorLeaveDays($_POST["leave_days"] ?? []);
        $encodedLeaveDays = encodeAdministratorLeaveDays($leaveDays);

        if ($fullName !== "" && $phone !== "" && $password !== "") {
            try {
                if ($id === "") {
                    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM administrators WHERE phone = ?");
                    $checkStmt->execute([$phone]);
                    $duplicatePhoneCount = (int) $checkStmt->fetchColumn();
                    $duplicateBarcodeCount = 0;

                    if ($barcode !== "") {
                        $barcodeCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM administrators WHERE barcode = ? AND barcode IS NOT NULL");
                        $barcodeCheckStmt->execute([$barcode]);
                        $duplicateBarcodeCount = (int) $barcodeCheckStmt->fetchColumn();
                    }

                    if ($duplicatePhoneCount > 0) {
                        $message = "❌ رقم هاتف الإداري مسجل بالفعل";
                        $messageType = "error";
                    } elseif ($duplicateBarcodeCount > 0) {
                        $message = "❌ باركود الإداري مسجل بالفعل";
                        $messageType = "error";
                    }

                    if ($messageType !== "error" && !isValidAdministratorPassword($password)) {
                        $message = "❌ كلمة مرور الإداري يجب أن تكون 6 رموز أو أكثر";
                        $messageType = "error";
                    } elseif ($messageType !== "error") {
                        $pdo->beginTransaction();
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                        $stmt = $pdo->prepare("INSERT INTO administrators (full_name, phone, password_hash, barcode, leave_days, image_files) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$fullName, $phone, $hashedPassword, $barcode !== '' ? $barcode : null, $encodedLeaveDays, null]);
                        $pdo->commit();

                        $message = buildAdministratorSaveMessage(false);
                        $messageType = "success";
                        $messageContext = "save";
                    }
                } else {
                    $existingAdministratorStmt = $pdo->prepare("SELECT id FROM administrators WHERE id = ?");
                    $existingAdministratorStmt->execute([$id]);
                    $existingAdministrator = $existingAdministratorStmt->fetch(PDO::FETCH_ASSOC);

                    $duplicatePhoneStmt = $pdo->prepare("SELECT COUNT(*) FROM administrators WHERE phone = ? AND id != ?");
                    $duplicatePhoneStmt->execute([$phone, $id]);
                    $duplicateCount = (int) $duplicatePhoneStmt->fetchColumn();
                    $duplicateBarcodeCount = 0;
                    if ($barcode !== '') {
                        $duplicateBarcodeStmt = $pdo->prepare("SELECT COUNT(*) FROM administrators WHERE barcode = ? AND id != ?");
                        $duplicateBarcodeStmt->execute([$barcode, $id]);
                        $duplicateBarcodeCount = (int) $duplicateBarcodeStmt->fetchColumn();
                    }

                    if (!$existingAdministrator) {
                        $message = "❌ الإداري المطلوب غير موجود";
                        $messageType = "error";
                    } elseif ($duplicateCount > 0) {
                        $message = "❌ رقم الهاتف مستخدم لإداري آخر";
                        $messageType = "error";
                    } elseif ($duplicateBarcodeCount > 0) {
                        $message = "❌ باركود الإداري مستخدم لإداري آخر";
                        $messageType = "error";
                    } elseif (!isValidAdministratorPassword($password)) {
                        $message = "❌ كلمة مرور الإداري يجب أن تكون 6 رموز أو أكثر";
                        $messageType = "error";
                    } else {
                        $pdo->beginTransaction();
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE administrators SET full_name = ?, phone = ?, password_hash = ?, barcode = ?, leave_days = ? WHERE id = ?");
                        $stmt->execute([$fullName, $phone, $hashedPassword, $barcode !== '' ? $barcode : null, $encodedLeaveDays, $id]);
                        $pdo->commit();

                        $message = buildAdministratorSaveMessage(true);
                        $messageType = "success";
                        $messageContext = "save";
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === MYSQL_DUPLICATE_KEY_ERROR) {
                    $duplicateIndexName = (string) ($e->errorInfo[2] ?? '');
                    if (strpos($duplicateIndexName, 'administrators_barcode_unique') !== false) {
                        $message = "❌ باركود الإداري مستخدم بالفعل";
                    } else {
                        $message = "❌ رقم هاتف الإداري مستخدم بالفعل";
                    }
                    $messageType = "error";
                } else {
                    $message = "❌ حدث خطأ أثناء حفظ بيانات الإداري";
                    $messageType = "error";
                }
            }
        } else {
            $message = "❌ يرجى إدخال اسم الإداري ورقم الهاتف وكلمة المرور بشكل صحيح";
            $messageType = "error";
        }
    }

    if ($action === "upload_images") {
        $administratorId = trim($_POST["administrator_id"] ?? "");
        $selectedFilesAdministratorId = $administratorId;

        if ($administratorId === "") {
            $message = "❌ الإداري المطلوب غير موجود";
            $messageType = "error";
        } else {
            $uploadResult = [
                'paths' => [],
                'attempted_count' => 0,
                'error_count' => 0,
            ];

            try {
                $existingAdministratorStmt = $pdo->prepare("SELECT id, full_name, image_files FROM administrators WHERE id = ? LIMIT 1");
                $existingAdministratorStmt->execute([$administratorId]);
                $existingAdministrator = $existingAdministratorStmt->fetch(PDO::FETCH_ASSOC);

                if (!$existingAdministrator) {
                    $message = "❌ الإداري المطلوب غير موجود";
                    $messageType = "error";
                } else {
                    $existingImagePaths = decodeAdministratorImagePaths($existingAdministrator['image_files'] ?? null);
                    $uploadResult = uploadAdministratorImages($_FILES['administrator_images'] ?? [], (int) $administratorId, $administratorImagesUploadDirectory, $administratorImagesPublicDirectory);

                    if ((int) ($uploadResult['attempted_count'] ?? 0) === 0) {
                        $message = "⚠️ لم يتم رفع أي صور";
                        $messageType = "error";
                    } else {
                        $pdo->beginTransaction();

                        if (!empty($uploadResult['paths'])) {
                            $allImagePaths = array_values(array_unique(array_merge($existingImagePaths, $uploadResult['paths'])));
                            $updateImagesStmt = $pdo->prepare("UPDATE administrators SET image_files = ? WHERE id = ?");
                            $updateImagesStmt->execute([
                                json_encode($allImagePaths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                $administratorId,
                            ]);
                        }

                        $pdo->commit();

                        $message = buildAdministratorFilesSaveMessage($uploadResult);
                        $messageType = !empty($uploadResult['paths']) ? "success" : "error";
                        $messageContext = "upload_images";
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if (!empty($uploadResult['paths'])) {
                    deleteAdministratorImageFiles($uploadResult['paths'], __DIR__);
                }

                $message = "❌ حدث خطأ أثناء حفظ ملفات الإداري";
                $messageType = "error";
            }
        }
    }

    if ($action === "delete_image") {
        $administratorId = trim($_POST["administrator_id"] ?? "");
        $selectedFilesAdministratorId = $administratorId;
        $imagePath = normalizeAdministratorImagePublicPath($_POST["image_path"] ?? null);

        if ($administratorId === "" || $imagePath === null) {
            $message = "❌ صورة الإداري المطلوبة غير موجودة";
            $messageType = "error";
        } else {
            try {
                $existingAdministratorStmt = $pdo->prepare("SELECT id, image_files FROM administrators WHERE id = ? LIMIT 1");
                $existingAdministratorStmt->execute([$administratorId]);
                $existingAdministrator = $existingAdministratorStmt->fetch(PDO::FETCH_ASSOC);

                if (!$existingAdministrator) {
                    $message = "❌ الإداري المطلوب غير موجود";
                    $messageType = "error";
                } else {
                    $existingImagePaths = decodeAdministratorImagePaths($existingAdministrator['image_files'] ?? null);
                    if (!in_array($imagePath, $existingImagePaths, true)) {
                        $message = "❌ صورة الإداري المطلوبة غير موجودة";
                        $messageType = "error";
                    } else {
                        $remainingImagePaths = array_values(array_filter(
                            $existingImagePaths,
                            static fn (string $currentImagePath): bool => $currentImagePath !== $imagePath
                        ));

                        $pdo->beginTransaction();
                        $updateImagesStmt = $pdo->prepare("UPDATE administrators SET image_files = ? WHERE id = ?");
                        $updateImagesStmt->execute([
                            empty($remainingImagePaths)
                                ? null
                                : json_encode($remainingImagePaths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            $administratorId,
                        ]);

                        if (!deleteAdministratorImageFiles([$imagePath], __DIR__)) {
                            throw new RuntimeException('تعذر حذف ملف صورة الإداري');
                        }

                        $pdo->commit();

                        $message = buildAdministratorImageDeleteMessage(true);
                        $messageType = "success";
                        $messageContext = "delete_image";
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = buildAdministratorImageDeleteMessage(false);
                $messageType = "error";
            }
        }
    }

    if ($action === "delete") {
        $id = trim($_POST["id"] ?? "");

        if ($id !== "") {
            try {
                $existingAdministratorStmt = $pdo->prepare("SELECT image_files FROM administrators WHERE id = ? LIMIT 1");
                $existingAdministratorStmt->execute([$id]);
                $existingAdministrator = $existingAdministratorStmt->fetch(PDO::FETCH_ASSOC);
                $existingImagePaths = decodeAdministratorImagePaths($existingAdministrator['image_files'] ?? null);

                $stmt = $pdo->prepare("DELETE FROM administrators WHERE id = ?");
                $pdo->beginTransaction();
                $deleteAttendanceStmt = $pdo->prepare("DELETE FROM administrator_attendance WHERE administrator_id = ?");
                $deleteAttendanceStmt->execute([$id]);
                $deleteAdvancesStmt = $pdo->prepare("DELETE FROM admin_advances WHERE administrator_id = ?");
                $deleteAdvancesStmt->execute([$id]);
                $stmt->execute([$id]);

                if ($stmt->rowCount() > 0) {
                    $pdo->commit();
                    deleteAdministratorImageFiles($existingImagePaths, __DIR__);
                    $message = "🗑️ تم حذف الإداري بنجاح";
                    $messageType = "success";
                    $messageContext = "delete";
                } else {
                    $pdo->rollBack();
                    $message = "❌ الإداري المطلوب غير موجود";
                    $messageType = "error";
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = "❌ حدث خطأ أثناء حذف الإداري";
                $messageType = "error";
            }
        }
    }
}

if (isset($_GET["edit"])) {
    $editId = trim($_GET["edit"]);
    $stmt = $pdo->prepare("SELECT id, full_name, phone, password_hash, barcode, leave_days, image_files FROM administrators WHERE id = ?");
    $stmt->execute([$editId]);
    $editAdministrator = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($selectedFilesAdministratorId !== '') {
    $filesAdministratorStmt = $pdo->prepare("SELECT id, full_name, image_files FROM administrators WHERE id = ? LIMIT 1");
    $filesAdministratorStmt->execute([$selectedFilesAdministratorId]);
    $filesAdministrator = $filesAdministratorStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($filesAdministrator === null) {
        $message = "❌ الإداري المطلوب غير موجود";
        $messageType = "error";
    }
}

$stmt = $pdo->query("SELECT id, full_name, phone, password_hash, barcode, leave_days, image_files, created_at FROM administrators ORDER BY id DESC");
$administrators = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($administrators as &$administrator) {
    $administrator['image_paths'] = decodeAdministratorImagePaths($administrator['image_files'] ?? null);
    $administrator['leave_days_values'] = decodeAdministratorLeaveDays($administrator['leave_days'] ?? null);
    $administrator['leave_days_text'] = buildAdministratorLeaveDaysText($administrator['leave_days_values']);
    $administrator['has_password'] = hasAdministratorPassword($administrator['password_hash'] ?? null);
}
unset($administrator);

$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_administrators,
        SUM(CASE WHEN password_hash IS NOT NULL AND password_hash <> '' THEN 1 ELSE 0 END) AS portal_ready_count,
        SUM(CASE WHEN image_files IS NOT NULL AND image_files <> '' THEN 1 ELSE 0 END) AS files_ready_count
    FROM administrators
");
$administratorStats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    "total_administrators" => 0,
    "portal_ready_count" => 0,
    "files_ready_count" => 0,
];
$administratorLeaveDayOptions = getAdministratorLeaveDayOptions();
$editAdministratorLeaveDays = decodeAdministratorLeaveDays($editAdministrator['leave_days'] ?? null);
$administratorCsrfToken = getAdministratorCsrfToken();
$filesAdministratorImagePaths = $filesAdministrator ? decodeAdministratorImagePaths($filesAdministrator['image_files'] ?? null) : [];
$activeEditAdministratorId = $editAdministrator !== null ? (string) $editAdministrator['id'] : null;
$shouldResetForm = $messageType === 'success' && $messageContext === 'save';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الإداريين</title>
    <link rel="stylesheet" href="assets/css/coaches.css">
</head>
<body
    class="light-mode"
    data-administrator-images-base-path="<?php echo academyHtmlspecialchars($administratorImagesPublicDirectory); ?>"
    data-default-confirm-message="هل أنت متأكد؟"
>

<div class="coaches-page">
    <div class="page-header">
        <div class="header-text">
            <h1>إدارة الإداريين</h1>
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

            <a href="admin_portal.php" class="back-btn">🧾 بوابة الإداريين</a>
            <a href="dashboard.php" class="back-btn">⬅️ الرجوع للوحة التحكم</a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div
            class="message-box <?php echo academyHtmlspecialchars($messageType); ?>"
            data-message-context="<?php echo academyHtmlspecialchars($messageContext); ?>"
            data-should-reset-form="<?php echo $shouldResetForm ? 'true' : 'false'; ?>"
        >
            <?php echo academyHtmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <section class="stats-grid">
        <article class="stat-card total-card">
            <div class="stat-icon">👥</div>
            <div>
                <h2><?php echo (int) ($administratorStats["total_administrators"] ?? 0); ?></h2>
                <p>إجمالي الإداريين المسجلين</p>
            </div>
        </article>
        <article class="stat-card average-card">
            <div class="stat-icon">🔐</div>
            <div>
                <h2><?php echo (int) ($administratorStats["portal_ready_count"] ?? 0); ?></h2>
                <p>إداريون لديهم كلمة مرور</p>
            </div>
        </article>
        <article class="stat-card top-card">
            <div class="stat-icon">📁</div>
            <div>
                <h2><?php echo (int) ($administratorStats["files_ready_count"] ?? 0); ?></h2>
                <p>إداريون لديهم ملفات مرفوعة</p>
            </div>
        </article>
    </section>

    <section class="form-card">
        <div class="card-head">
            <h2><?php echo $editAdministrator ? "✏️ تعديل بيانات الإداري" : "➕ إضافة إداري جديد"; ?></h2>
        </div>

        <form method="POST" id="administratorForm" class="coach-form" autocomplete="off" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="id" value="<?php echo academyHtmlspecialchars($editAdministrator["id"] ?? ''); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($administratorCsrfToken); ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="full_name">👤 اسم الإداري</label>
                    <input
                        type="text"
                        name="full_name"
                        id="full_name"
                        value="<?php echo academyHtmlspecialchars($editAdministrator["full_name"] ?? ''); ?>"
                        placeholder="أدخل اسم الإداري بالكامل"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="phone">📞 رقم الهاتف</label>
                    <input
                        type="text"
                        name="phone"
                        id="phone"
                        value="<?php echo academyHtmlspecialchars($editAdministrator["phone"] ?? ''); ?>"
                        placeholder="مثال: 01000000000"
                        inputmode="tel"
                        maxlength="20"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">🔒 كلمة المرور</label>
                    <input
                        type="password"
                        name="password"
                        id="password"
                        placeholder="أدخل كلمة مرور الإداري"
                        minlength="6"
                        aria-required="true"
                        required
                    >
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="barcode">🏷️ باركود الإداري</label>
                    <input
                        type="text"
                        name="barcode"
                        id="barcode"
                        value="<?php echo academyHtmlspecialchars($editAdministrator["barcode"] ?? ''); ?>"
                        placeholder="أدخل باركود الإداري"
                        maxlength="100"
                        dir="ltr"
                    >
                </div>

                <div class="form-group form-group-full">
                    <label>📅 أيام إجازة الإداري</label>
                    <div class="leave-days-panel">
                        <div class="leave-days-summary-bar">
                            <span class="leave-days-summary-label">الأيام المختارة</span>
                            <div class="leave-days-summary" id="leaveDaysSummary"></div>
                        </div>
                        <div class="checkbox-grid leave-days-grid">
                        <?php foreach ($administratorLeaveDayOptions as $leaveDayValue => $leaveDayLabel): ?>
                            <label class="checkbox-card leave-day-card<?php echo in_array($leaveDayValue, $editAdministratorLeaveDays, true) ? ' is-selected' : ''; ?>" for="leave_day_<?php echo academyHtmlspecialchars($leaveDayValue); ?>">
                                <input
                                    type="checkbox"
                                    name="leave_days[]"
                                    id="leave_day_<?php echo academyHtmlspecialchars($leaveDayValue); ?>"
                                    value="<?php echo academyHtmlspecialchars($leaveDayValue); ?>"
                                    <?php echo in_array($leaveDayValue, $editAdministratorLeaveDays, true) ? 'checked' : ''; ?>
                                >
                                <span class="leave-day-label"><?php echo academyHtmlspecialchars($leaveDayLabel); ?></span>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="save-btn">💾 حفظ البيانات</button>
                <button type="button" class="clear-btn" id="clearBtn">🧹 تصفية الحقول</button>
            </div>
        </form>
    </section>

    <?php if ($filesAdministrator): ?>
        <section class="files-modal" id="administratorFilesModal">
            <a
                href="<?php echo academyHtmlspecialchars(buildAdministratorPageUrl(['edit' => $activeEditAdministratorId])); ?>"
                class="files-modal-backdrop"
                aria-label="إغلاق ملفات الإداري"
            ></a>

            <div class="files-modal-dialog">
                <div class="files-modal-header">
                    <div>
                        <h2>📁 ملفات الإداري: <?php echo academyHtmlspecialchars($filesAdministrator["full_name"]); ?></h2>
                    </div>

                    <a
                        href="<?php echo academyHtmlspecialchars(buildAdministratorPageUrl(['edit' => $activeEditAdministratorId])); ?>"
                        class="files-modal-close"
                        aria-label="إغلاق ملفات الإداري"
                    >✕</a>
                </div>

                <form method="POST" class="coach-files-form" enctype="multipart/form-data" autocomplete="off">
                    <input type="hidden" name="action" value="upload_images">
                    <input type="hidden" name="administrator_id" value="<?php echo academyHtmlspecialchars((string) $filesAdministrator["id"]); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($administratorCsrfToken); ?>">

                    <div class="form-group form-group-full">
                        <label for="administrator_images">🖼️ إضافة صور جديدة</label>
                        <input
                            type="file"
                            name="administrator_images[]"
                            id="administrator_images"
                            accept="image/*"
                            multiple
                        >
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="save-btn">⬆️ رفع الصور</button>
                    </div>
                </form>

                <div class="files-gallery-section">
                    <h3>الصور المرفوعة</h3>

                    <?php if (!empty($filesAdministratorImagePaths)): ?>
                        <div class="coach-gallery files-gallery">
                            <?php foreach ($filesAdministratorImagePaths as $imageIndex => $imagePath): ?>
                                <div class="gallery-image-card">
                                    <button
                                        type="button"
                                        class="gallery-image-button"
                                        data-full-image="<?php echo academyHtmlspecialchars($imagePath); ?>"
                                        data-image-title="<?php echo academyHtmlspecialchars($filesAdministrator["full_name"] . ' - صورة ' . ($imageIndex + 1)); ?>"
                                    >
                                        <img src="<?php echo academyHtmlspecialchars($imagePath); ?>" alt="صورة الإداري" class="coach-preview-image">
                                    </button>

                                    <div class="gallery-image-actions">
                                        <span class="gallery-image-label"><?php echo academyHtmlspecialchars('صورة ' . ($imageIndex + 1)); ?></span>

                                        <form method="POST" class="inline-form js-confirm-submit" data-confirm-message="هل أنت متأكد من حذف هذه الصورة؟">
                                            <input type="hidden" name="action" value="delete_image">
                                            <input type="hidden" name="administrator_id" value="<?php echo academyHtmlspecialchars((string) $filesAdministrator["id"]); ?>">
                                            <input type="hidden" name="image_path" value="<?php echo academyHtmlspecialchars($imagePath); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($administratorCsrfToken); ?>">
                                            <button type="submit" class="delete-image-btn">🗑️ حذف الصورة</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="empty-files-state">لا توجد صور مرفوعة لهذا الإداري حتى الآن.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="table-card">
        <div class="card-head">
            <h2>📋 جدول الإداريين المسجلين</h2>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم الإداري</th>
                        <th>رقم الهاتف</th>
                        <th>الباركود</th>
                        <th>أيام الإجازة</th>
                        <th>حالة كلمة المرور</th>
                        <th>ملفات الإداري</th>
                        <th>تاريخ الإضافة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($administrators)): ?>
                        <?php foreach ($administrators as $index => $administrator): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <div class="coach-cell">
                                        <span class="coach-avatar">👨‍💼</span>
                                        <span><?php echo academyHtmlspecialchars($administrator["full_name"]); ?></span>
                                    </div>
                                 </td>
                                 <td dir="ltr"><?php echo academyHtmlspecialchars($administrator["phone"]); ?></td>
                                 <td dir="ltr"><?php echo academyHtmlspecialchars($administrator["barcode"] ?? '—'); ?></td>
                                 <td><?php echo academyHtmlspecialchars($administrator["leave_days_text"]); ?></td>
                                 <td>
                                     <span class="login-status-badge <?php echo !empty($administrator['has_password']) ? 'enabled' : 'disabled'; ?>">
                                         <?php echo !empty($administrator['has_password']) ? '🔐 مفعلة' : '⚠️ غير مضبوطة'; ?>
                                     </span>
                                 </td>
                                <td>
                                    <a
                                        href="<?php echo academyHtmlspecialchars(buildAdministratorPageUrl(['files' => $administrator["id"], 'edit' => $activeEditAdministratorId])); ?>"
                                        class="files-btn"
                                    >
                                        <span>📁 ملفات الإداري</span>
                                        <span class="files-count"><?php echo count($administrator['image_paths']); ?> صورة</span>
                                    </a>
                                </td>
                                <td><?php echo academyHtmlspecialchars($administrator["created_at"]); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="<?php echo academyHtmlspecialchars(buildAdministratorPageUrl(['edit' => $administrator["id"]])); ?>" class="edit-btn">✏️ تعديل</a>

                                        <form method="POST" class="inline-form js-confirm-submit" data-confirm-message="هل أنت متأكد من حذف الإداري؟">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo academyHtmlspecialchars((string) $administrator["id"]); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars($administratorCsrfToken); ?>">
                                            <button type="submit" class="delete-btn">🗑️ حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                     <?php else: ?>
                         <tr>
                            <td colspan="9" class="empty-row">لا يوجد إداريون مسجلين حاليًا داخل قاعدة البيانات</td>
                         </tr>
                     <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="image-viewer-modal" id="imageViewerModal" hidden>
    <button type="button" class="image-viewer-backdrop" data-close-image-viewer aria-label="إغلاق معاينة الصورة"></button>
    <div class="image-viewer-dialog" role="dialog" aria-modal="true" aria-labelledby="imageViewerTitle">
        <button type="button" class="image-viewer-close" data-close-image-viewer aria-label="إغلاق معاينة الصورة">✕</button>
        <h3 id="imageViewerTitle">معاينة الصورة</h3>
        <img src="" alt="" id="imageViewerImage" class="image-viewer-image">
    </div>
</div>

<script src="assets/js/administrators.js"></script>
</body>
</html>
