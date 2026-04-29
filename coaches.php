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

if (!userCanAccess($currentUser, "coaches")) {
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

function sanitizeCoachName(string $value): string
{
    $value = trim(normalizeArabicNumbers($value));
    $sanitizedValue = preg_replace('/\s+/u', ' ', $value);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function sanitizeCoachPhone(string $value): string
{
    $value = trim(normalizeArabicNumbers($value));
    $value = preg_replace('/[^0-9+]/', '', $value);
    if ($value === null) {
        return '';
    }

    $sanitizedValue = preg_replace('/(?!^)\+/', '', $value);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function sanitizeCoachPassword(string $value): string
{
    return $value;
}

function coachPasswordLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function isValidCoachPassword(string $value): bool
{
    return coachPasswordLength($value) >= 6;
}

function hasCoachPassword($passwordHash): bool
{
    return is_string($passwordHash) && trim($passwordHash) !== '';
}

function formatHourlyRateValue(float $value): string
{
    return number_format($value, 2, '.', '');
}

function sanitizeHourlyRate(string $value): string
{
    $value = trim(normalizeArabicNumbers($value));
    return str_replace(',', '.', $value);
}

// دوال خاصة بالتحويلات
function sanitizeTransferNumber(string $value): string
{
    return trim($value);
}

function isValidTransferType(string $value): bool
{
    return in_array($value, ['wallet', 'instapay'], true);
}

function formatTransferTypeLabel(string $value): string
{
    return match ($value) {
        'wallet' => 'محفظة',
        'instapay' => 'إنستا باي',
        default => '—',
    };
}

function buildCoachPageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return 'coaches.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function generateCoachSecurityToken(): string
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

    throw new RuntimeException('تعذر إنشاء رمز أمان للمدربين');
}

function getCoachCsrfToken(): string
{
    if (
        !isset($_SESSION['coach_csrf_token'])
        || !is_string($_SESSION['coach_csrf_token'])
        || $_SESSION['coach_csrf_token'] === ''
    ) {
        try {
            $_SESSION['coach_csrf_token'] = generateCoachSecurityToken();
        } catch (Throwable $exception) {
            error_log('تعذر إنشاء رمز التحقق الخاص بإدارة المدربين');
            http_response_code(500);
            exit('❌ تعذر تهيئة أمان الصفحة، يرجى إعادة المحاولة لاحقًا');
        }
    }

    return $_SESSION['coach_csrf_token'];
}

function isValidCoachCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getCoachCsrfToken(), $submittedToken);
}

function isValidHourlyRate(string $value): bool
{
    return $value !== ''
        && preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1
        && (float) $value > 0;
}

const MYSQL_DUPLICATE_KEY_ERROR = 1062;

function decodeCoachImagePaths(?string $value): array
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
        $normalizedPath = normalizeCoachImagePublicPath($imagePath);
        if ($normalizedPath !== null) {
            $imagePaths[] = $normalizedPath;
        }
    }

    return array_values(array_unique($imagePaths));
}

function normalizeCoachImagePublicPath($imagePath): ?string
{
    if (!is_string($imagePath)) {
        return null;
    }

    $normalizedPath = trim($imagePath);
    if (strpos($normalizedPath, 'uploads/coaches/') !== 0) {
        return null;
    }

    $fileName = basename($normalizedPath);
    if ($fileName === '' || $fileName === '.' || $fileName === '..') {
        return null;
    }

    if ($normalizedPath !== 'uploads/coaches/' . $fileName) {
        return null;
    }

    if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9_-]|\.(?!\.))+$/', $fileName) !== 1) {
        return null;
    }

    return 'uploads/coaches/' . $fileName;
}

function ensureCoachUploadDirectoryExists(string $directory): bool
{
    if (is_dir($directory)) {
        return true;
    }

    return mkdir($directory, 0755, true) || is_dir($directory);
}

function generateCoachImageToken(): ?string
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

function detectCoachImageExtension(string $originalName, string $mimeType): string
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

function uploadCoachImages(array $files, int $coachId, string $uploadDirectory, string $publicDirectory): array
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

    if (!ensureCoachUploadDirectoryExists($uploadDirectory)) {
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

            $randomPart = generateCoachImageToken();
            if ($randomPart === null) {
                $errorCount++;
                continue;
            }

            $extension = detectCoachImageExtension($originalName, $mimeType);
            $fileName = sprintf('coach-%d-%s.%s', $coachId, $randomPart, $extension);
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

function deleteCoachImageFiles(array $imagePaths, string $baseDirectory): bool
{
    $allFilesDeleted = true;

    foreach ($imagePaths as $imagePath) {
        $absolutePath = resolveCoachImageAbsolutePath($imagePath, $baseDirectory);
        if ($absolutePath === null) {
            continue;
        }

        if (is_file($absolutePath) && !unlink($absolutePath)) {
            error_log('تعذر حذف صورة أحد المدربين');
            $allFilesDeleted = false;
        }
    }

    return $allFilesDeleted;
}

function resolveCoachImageAbsolutePath($imagePath, string $baseDirectory): ?string
{
    $normalizedPath = normalizeCoachImagePublicPath($imagePath);
    if ($normalizedPath === null) {
        return null;
    }

    $uploadsDirectory = realpath($baseDirectory . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'coaches');
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

function buildCoachSaveMessage(bool $isUpdate): string
{
    return $isUpdate
        ? "✏️ تم تعديل بيانات المدرب بنجاح ويمكنك إدارة الصور من زر ملفات المدرب"
        : "✅ تم إضافة المدرب بنجاح ويمكنك الآن رفع الصور من زر ملفات المدرب";
}

function buildCoachFilesSaveMessage(array $uploadResult): string
{
    $baseMessage = "✅ تم تحديث ملفات المدرب";
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

function buildCoachImageDeleteMessage(bool $wasDeleted): string
{
    return $wasDeleted
        ? "🗑️ تم حذف صورة المدرب بنجاح"
        : "❌ تعذر حذف صورة المدرب";
}

$message = "";
$messageType = "";
$messageContext = "";
$editCoach = null;
$filesCoach = null;
$coachImagesUploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'coaches';
$coachImagesPublicDirectory = 'uploads/coaches';
$selectedFilesCoachId = trim($_GET["files"] ?? "");
$isAdmin = ($currentUser["role"] ?? "") === "مدير";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action !== "" && !isValidCoachCsrfToken($_POST["csrf_token"] ?? null)) {
        $message = "❌ انتهت صلاحية الطلب، يرجى إعادة المحاولة";
        $messageType = "error";
        $action = "";
    }

    if ($action === "save") {
        $id = trim($_POST["id"] ?? "");
        $fullName = sanitizeCoachName($_POST["full_name"] ?? "");
        $phone = sanitizeCoachPhone($_POST["phone"] ?? "");
        $password = sanitizeCoachPassword($_POST["password"] ?? "");
        $hourlyRateInput = sanitizeHourlyRate($_POST["hourly_rate"] ?? "");
        // الحقول الجديدة (للمدير فقط)
        $transferNumber = $isAdmin ? sanitizeTransferNumber($_POST["transfer_number"] ?? "") : null;
        $transferType = ($isAdmin && isset($_POST["transfer_type"]) && isValidTransferType($_POST["transfer_type"]))
            ? $_POST["transfer_type"]
            : null;

        if ($fullName !== "" && $phone !== "" && $password !== "" && isValidHourlyRate($hourlyRateInput)) {
            try {
                $hourlyRate = formatHourlyRateValue((float) $hourlyRateInput);

                if ($id === "") {
                    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM coaches WHERE phone = ?");
                    $checkStmt->execute([$phone]);

                    if ($checkStmt->fetchColumn() > 0) {
                        $message = "❌ رقم هاتف المدرب مسجل بالفعل";
                        $messageType = "error";
                    } elseif (!isValidCoachPassword($password)) {
                        $message = "❌ كلمة مرور المدرب يجب أن تكون 6 رموز أو أكثر";
                        $messageType = "error";
                    } else {
                        $pdo->beginTransaction();
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                        $stmt = $pdo->prepare("INSERT INTO coaches (full_name, phone, password_hash, hourly_rate, transfer_number, transfer_type) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$fullName, $phone, $hashedPassword, $hourlyRate, $transferNumber, $transferType]);
                        $pdo->commit();

                        $message = buildCoachSaveMessage(false);
                        $messageType = "success";
                        $messageContext = "save";
                    }
                } else {
                    $existingCoachStmt = $pdo->prepare("SELECT id FROM coaches WHERE id = ?");
                    $existingCoachStmt->execute([$id]);
                    $existingCoach = $existingCoachStmt->fetch(PDO::FETCH_ASSOC);

                    $duplicatePhoneStmt = $pdo->prepare("SELECT COUNT(*) FROM coaches WHERE phone = ? AND id != ?");
                    $duplicatePhoneStmt->execute([$phone, $id]);
                    $duplicateCount = (int) $duplicatePhoneStmt->fetchColumn();

                    if (!$existingCoach) {
                        $message = "❌ المدرب المطلوب غير موجود";
                        $messageType = "error";
                    } elseif ($duplicateCount > 0) {
                        $message = "❌ رقم الهاتف مستخدم لمدرب آخر";
                        $messageType = "error";
                    } elseif (!isValidCoachPassword($password)) {
                        $message = "❌ كلمة مرور المدرب يجب أن تكون 6 رموز أو أكثر";
                        $messageType = "error";
                    } else {
                        $pdo->beginTransaction();
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE coaches SET full_name = ?, phone = ?, password_hash = ?, hourly_rate = ?, transfer_number = ?, transfer_type = ? WHERE id = ?");
                        $stmt->execute([$fullName, $phone, $hashedPassword, $hourlyRate, $transferNumber, $transferType, $id]);
                        $pdo->commit();

                        $message = buildCoachSaveMessage(true);
                        $messageType = "success";
                        $messageContext = "save";
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === MYSQL_DUPLICATE_KEY_ERROR) {
                    $message = "❌ رقم هاتف المدرب مستخدم بالفعل";
                    $messageType = "error";
                } else {
                    $message = "❌ حدث خطأ أثناء حفظ بيانات المدرب";
                    $messageType = "error";
                }
            }
        } else {
            $message = "❌ يرجى إدخال اسم المدرب ورقم الهاتف وكلمة المرور وسعر الساعة بشكل صحيح";
            $messageType = "error";
        }
    }

    if ($action === "upload_images") {
        $coachId = trim($_POST["coach_id"] ?? "");
        $selectedFilesCoachId = $coachId;

        if ($coachId === "") {
            $message = "❌ المدرب المطلوب غير موجود";
            $messageType = "error";
        } else {
            $uploadResult = [
                'paths' => [],
                'attempted_count' => 0,
                'error_count' => 0,
            ];

            try {
                $existingCoachStmt = $pdo->prepare("SELECT id, full_name, image_files FROM coaches WHERE id = ? LIMIT 1");
                $existingCoachStmt->execute([$coachId]);
                $existingCoach = $existingCoachStmt->fetch(PDO::FETCH_ASSOC);

                if (!$existingCoach) {
                    $message = "❌ المدرب المطلوب غير موجود";
                    $messageType = "error";
                } else {
                    $existingImagePaths = decodeCoachImagePaths($existingCoach['image_files'] ?? null);
                    $uploadResult = uploadCoachImages($_FILES['coach_images'] ?? [], (int) $coachId, $coachImagesUploadDirectory, $coachImagesPublicDirectory);

                    if ((int) ($uploadResult['attempted_count'] ?? 0) === 0) {
                        $message = "⚠️ لم يتم رفع أي صور";
                        $messageType = "error";
                    } else {
                        $pdo->beginTransaction();

                        if (!empty($uploadResult['paths'])) {
                            $allImagePaths = array_values(array_unique(array_merge($existingImagePaths, $uploadResult['paths'])));
                            $updateImagesStmt = $pdo->prepare("UPDATE coaches SET image_files = ? WHERE id = ?");
                            $updateImagesStmt->execute([
                                json_encode($allImagePaths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                $coachId,
                            ]);
                        }

                        $pdo->commit();

                        $message = buildCoachFilesSaveMessage($uploadResult);
                        $messageType = !empty($uploadResult['paths']) ? "success" : "error";
                        $messageContext = "upload_images";
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if (!empty($uploadResult['paths'])) {
                    deleteCoachImageFiles($uploadResult['paths'], __DIR__);
                }

                $message = "❌ حدث خطأ أثناء حفظ ملفات المدرب";
                $messageType = "error";
            }
        }
    }

    if ($action === "delete_image") {
        $coachId = trim($_POST["coach_id"] ?? "");
        $selectedFilesCoachId = $coachId;
        $imagePath = normalizeCoachImagePublicPath($_POST["image_path"] ?? null);

        if ($coachId === "" || $imagePath === null) {
            $message = "❌ صورة المدرب المطلوبة غير موجودة";
            $messageType = "error";
        } else {
            try {
                $existingCoachStmt = $pdo->prepare("SELECT id, image_files FROM coaches WHERE id = ? LIMIT 1");
                $existingCoachStmt->execute([$coachId]);
                $existingCoach = $existingCoachStmt->fetch(PDO::FETCH_ASSOC);

                if (!$existingCoach) {
                    $message = "❌ المدرب المطلوب غير موجود";
                    $messageType = "error";
                } else {
                    $existingImagePaths = decodeCoachImagePaths($existingCoach['image_files'] ?? null);
                    if (!in_array($imagePath, $existingImagePaths, true)) {
                        $message = "❌ صورة المدرب المطلوبة غير موجودة";
                        $messageType = "error";
                    } else {
                        $remainingImagePaths = array_values(array_filter(
                            $existingImagePaths,
                            static fn (string $currentImagePath): bool => $currentImagePath !== $imagePath
                        ));

                        $pdo->beginTransaction();
                        $updateImagesStmt = $pdo->prepare("UPDATE coaches SET image_files = ? WHERE id = ?");
                        $updateImagesStmt->execute([
                            empty($remainingImagePaths)
                                ? null
                                : json_encode($remainingImagePaths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            $coachId,
                        ]);

                        if (!deleteCoachImageFiles([$imagePath], __DIR__)) {
                            throw new RuntimeException('تعذر حذف ملف صورة المدرب');
                        }

                        $pdo->commit();

                        $message = buildCoachImageDeleteMessage(true);
                        $messageType = "success";
                        $messageContext = "delete_image";
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = buildCoachImageDeleteMessage(false);
                $messageType = "error";
            }
        }
    }

    if ($action === "delete") {
        $id = trim($_POST["id"] ?? "");

        if ($id !== "") {
            try {
                $existingCoachStmt = $pdo->prepare("SELECT image_files FROM coaches WHERE id = ? LIMIT 1");
                $existingCoachStmt->execute([$id]);
                $existingCoach = $existingCoachStmt->fetch(PDO::FETCH_ASSOC);
                $existingImagePaths = decodeCoachImagePaths($existingCoach['image_files'] ?? null);

                $stmt = $pdo->prepare("DELETE FROM coaches WHERE id = ?");
                $pdo->beginTransaction();
                $deleteAttendanceStmt = $pdo->prepare("DELETE FROM coach_attendance WHERE coach_id = ?");
                $deleteAttendanceStmt->execute([$id]);
                $stmt->execute([$id]);

                if ($stmt->rowCount() > 0) {
                    $pdo->commit();
                    deleteCoachImageFiles($existingImagePaths, __DIR__);
                    $message = "🗑️ تم حذف المدرب بنجاح";
                    $messageType = "success";
                    $messageContext = "delete";
                } else {
                    $pdo->rollBack();
                    $message = "❌ المدرب المطلوب غير موجود";
                    $messageType = "error";
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = "❌ حدث خطأ أثناء حذف المدرب";
                $messageType = "error";
            }
        }
    }
}

if (isset($_GET["edit"])) {
    $editId = trim($_GET["edit"]);
    $stmt = $pdo->prepare("SELECT id, full_name, phone, password_hash, hourly_rate, image_files, transfer_number, transfer_type FROM coaches WHERE id = ?");
    $stmt->execute([$editId]);
    $editCoach = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($selectedFilesCoachId !== '') {
    $filesCoachStmt = $pdo->prepare("SELECT id, full_name, image_files FROM coaches WHERE id = ? LIMIT 1");
    $filesCoachStmt->execute([$selectedFilesCoachId]);
    $filesCoach = $filesCoachStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($filesCoach === null) {
        $message = "❌ المدرب المطلوب غير موجود";
        $messageType = "error";
    }
}

$stmt = $pdo->query("SELECT id, full_name, phone, password_hash, hourly_rate, image_files, transfer_number, transfer_type, created_at FROM coaches ORDER BY id DESC");
$coaches = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($coaches as &$coach) {
    $coach['image_paths'] = decodeCoachImagePaths($coach['image_files'] ?? null);
    $coach['has_password'] = hasCoachPassword($coach['password_hash'] ?? null);
    if ($isAdmin) {
        $coach['transfer_type_label'] = formatTransferTypeLabel((string) ($coach['transfer_type'] ?? ''));
    }
}
unset($coach);

$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_coaches,
        COALESCE(AVG(hourly_rate), 0) AS average_rate,
        COALESCE(MAX(hourly_rate), 0) AS highest_rate
    FROM coaches
");
$coachStats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    "total_coaches" => 0,
    "average_rate" => 0,
    "highest_rate" => 0,
];
$editHourlyRateValue = '';
if (isset($editCoach["hourly_rate"])) {
    $editHourlyRateValue = formatHourlyRateValue((float) $editCoach["hourly_rate"]);
}
$coachCsrfToken = getCoachCsrfToken();
$filesCoachImagePaths = $filesCoach ? decodeCoachImagePaths($filesCoach['image_files'] ?? null) : [];
$activeEditCoachId = $editCoach !== null ? (string) $editCoach['id'] : null;
$shouldResetForm = $messageType === 'success' && $messageContext === 'save';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المدربين</title>
    <link rel="stylesheet" href="assets/css/coaches.css">
</head>
<body
    class="light-mode"
    data-coach-images-base-path="<?php echo htmlspecialchars($coachImagesPublicDirectory); ?>"
    data-default-confirm-message="هل أنت متأكد؟"
    data-is-admin="<?php echo $isAdmin ? '1' : '0'; ?>"
>

<div class="coaches-page">
    <div class="page-header">
        <div class="header-text">
            <h1>إدارة المدربين</h1>
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
    </div>

    <?php if (!empty($message)): ?>
        <div
            class="message-box <?php echo htmlspecialchars($messageType); ?>"
            data-message-context="<?php echo htmlspecialchars($messageContext); ?>"
            data-should-reset-form="<?php echo $shouldResetForm ? 'true' : 'false'; ?>"
        >
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <section class="stats-grid">
        <article class="stat-card total-card">
            <div class="stat-icon">👥</div>
            <div>
                <h2><?php echo (int) ($coachStats["total_coaches"] ?? 0); ?></h2>
                <p>إجمالي المدربين المسجلين</p>
            </div>
        </article>
        <article class="stat-card average-card">
            <div class="stat-icon">⏱️</div>
            <div>
                <h2><?php echo number_format((float) ($coachStats["average_rate"] ?? 0), 2); ?></h2>
                <p>متوسط سعر الساعة</p>
            </div>
        </article>
        <article class="stat-card top-card">
            <div class="stat-icon">💰</div>
            <div>
                <h2><?php echo number_format((float) ($coachStats["highest_rate"] ?? 0), 2); ?></h2>
                <p>أعلى سعر ساعة مسجل</p>
            </div>
        </article>
    </section>

    <section class="form-card">
        <div class="card-head">
            <h2><?php echo $editCoach ? "✏️ تعديل بيانات المدرب" : "➕ إضافة مدرب جديد"; ?></h2>
        </div>

        <form method="POST" id="coachForm" class="coach-form" autocomplete="off" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="id" value="<?php echo htmlspecialchars($editCoach["id"] ?? ''); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($coachCsrfToken); ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="full_name">👤 اسم المدرب</label>
                    <input
                        type="text"
                        name="full_name"
                        id="full_name"
                        value="<?php echo htmlspecialchars($editCoach["full_name"] ?? ''); ?>"
                        placeholder="أدخل اسم المدرب بالكامل"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="phone">📞 رقم الهاتف</label>
                    <input
                        type="text"
                        name="phone"
                        id="phone"
                        value="<?php echo htmlspecialchars($editCoach["phone"] ?? ''); ?>"
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
                        placeholder="أدخل كلمة مرور المدرب"
                        minlength="6"
                        aria-required="true"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="hourly_rate">💵 سعر الساعة</label>
                    <input
                        type="text"
                        name="hourly_rate"
                        id="hourly_rate"
                        value="<?php echo htmlspecialchars($editHourlyRateValue); ?>"
                        placeholder="مثال: 150"
                        inputmode="decimal"
                        required
                    >
                </div>
            </div>

            <?php if ($isAdmin): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="transfer_number">🏦 رقم التحويل (محفظة / إنستا باي)</label>
                        <input
                            type="text"
                            name="transfer_number"
                            id="transfer_number"
                            value="<?php echo htmlspecialchars($editCoach["transfer_number"] ?? ''); ?>"
                            placeholder="رقم المحفظة أو رقم التحويل"
                        >
                    </div>
                    <div class="form-group">
                        <label for="transfer_type">نوع التحويل</label>
                        <select name="transfer_type" id="transfer_type">
                            <option value="">-- اختر --</option>
                            <option value="wallet" <?php echo (($editCoach["transfer_type"] ?? '') === 'wallet') ? 'selected' : ''; ?>>محفظة</option>
                            <option value="instapay" <?php echo (($editCoach["transfer_type"] ?? '') === 'instapay') ? 'selected' : ''; ?>>إنستا باي</option>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-actions">
                <button type="submit" class="save-btn">💾 حفظ البيانات</button>
                <button type="button" class="clear-btn" id="clearBtn">🧹 تصفية الحقول</button>
            </div>
        </form>
    </section>

    <?php if ($filesCoach): ?>
        <section class="files-modal" id="coachFilesModal">
            <a
                href="<?php echo htmlspecialchars(buildCoachPageUrl(['edit' => $activeEditCoachId])); ?>"
                class="files-modal-backdrop"
                aria-label="إغلاق ملفات المدرب"
            ></a>

            <div class="files-modal-dialog">
                <div class="files-modal-header">
                    <div>
                        <h2>📁 ملفات المدرب: <?php echo htmlspecialchars($filesCoach["full_name"]); ?></h2>
                    </div>

                    <a
                        href="<?php echo htmlspecialchars(buildCoachPageUrl(['edit' => $activeEditCoachId])); ?>"
                        class="files-modal-close"
                        aria-label="إغلاق ملفات المدرب"
                    >✕</a>
                </div>

                <form method="POST" class="coach-files-form" enctype="multipart/form-data" autocomplete="off">
                    <input type="hidden" name="action" value="upload_images">
                    <input type="hidden" name="coach_id" value="<?php echo htmlspecialchars((string) $filesCoach["id"]); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($coachCsrfToken); ?>">

                    <div class="form-group form-group-full">
                        <label for="coach_images">🖼️ إضافة صور جديدة</label>
                        <input
                            type="file"
                            name="coach_images[]"
                            id="coach_images"
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

                    <?php if (!empty($filesCoachImagePaths)): ?>
                        <div class="coach-gallery files-gallery">
                            <?php foreach ($filesCoachImagePaths as $imageIndex => $imagePath): ?>
                                <div class="gallery-image-card">
                                    <button
                                        type="button"
                                        class="gallery-image-button"
                                        data-full-image="<?php echo htmlspecialchars($imagePath); ?>"
                                        data-image-title="<?php echo htmlspecialchars($filesCoach["full_name"] . ' - صورة ' . ($imageIndex + 1)); ?>"
                                    >
                                        <img src="<?php echo htmlspecialchars($imagePath); ?>" alt="صورة المدرب" class="coach-preview-image">
                                    </button>

                                    <div class="gallery-image-actions">
                                        <span class="gallery-image-label"><?php echo htmlspecialchars('صورة ' . ($imageIndex + 1)); ?></span>

                                        <form method="POST" class="inline-form js-confirm-submit" data-confirm-message="هل أنت متأكد من حذف هذه الصورة؟">
                                            <input type="hidden" name="action" value="delete_image">
                                            <input type="hidden" name="coach_id" value="<?php echo htmlspecialchars((string) $filesCoach["id"]); ?>">
                                            <input type="hidden" name="image_path" value="<?php echo htmlspecialchars($imagePath); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($coachCsrfToken); ?>">
                                            <button type="submit" class="delete-image-btn">🗑️ حذف الصورة</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="empty-files-state">لا توجد صور مرفوعة لهذا المدرب حتى الآن.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="table-card">
        <div class="card-head">
            <h2>📋 جدول المدربين المسجلين</h2>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم المدرب</th>
                        <th>رقم الهاتف</th>
                        <th>الدخول للبوابة</th>
                        <th>سعر الساعة</th>
                        <?php if ($isAdmin): ?>
                            <th>رقم التحويل</th>
                            <th>نوع التحويل</th>
                        <?php endif; ?>
                        <th>ملفات المدرب</th>
                        <th>تاريخ الإضافة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($coaches)): ?>
                        <?php foreach ($coaches as $index => $coach): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <div class="coach-cell">
                                        <span class="coach-avatar">🏊</span>
                                        <span><?php echo htmlspecialchars($coach["full_name"]); ?></span>
                                    </div>
                                </td>
                                <td dir="ltr"><?php echo htmlspecialchars($coach["phone"]); ?></td>
                                <td>
                                    <span class="login-status-badge <?php echo !empty($coach['has_password']) ? 'enabled' : 'disabled'; ?>">
                                        <?php echo !empty($coach['has_password']) ? '🔐 مفعلة' : '⚠️ غير مضبوطة'; ?>
                                    </span>
                                </td>
                                <td><?php echo number_format((float) $coach["hourly_rate"], 2); ?> ج.م</td>
                                <?php if ($isAdmin): ?>
                                    <td><?php echo htmlspecialchars($coach["transfer_number"] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($coach["transfer_type_label"] ?? '—'); ?></td>
                                <?php endif; ?>
                                <td>
                                    <a
                                        href="<?php echo htmlspecialchars(buildCoachPageUrl(['files' => $coach["id"], 'edit' => $activeEditCoachId])); ?>"
                                        class="files-btn"
                                    >
                                        <span>📁 ملفات المدرب</span>
                                        <span class="files-count"><?php echo count($coach['image_paths']); ?> صورة</span>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($coach["created_at"]); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="<?php echo htmlspecialchars(buildCoachPageUrl(['edit' => $coach["id"]])); ?>" class="edit-btn">✏️ تعديل</a>

                                        <form method="POST" class="inline-form js-confirm-submit" data-confirm-message="هل أنت متأكد من حذف المدرب؟">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars((string) $coach["id"]); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($coachCsrfToken); ?>">
                                            <button type="submit" class="delete-btn">🗑️ حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $isAdmin ? '11' : '8'; ?>" class="empty-row">لا يوجد مدربون مسجلون حاليًا داخل قاعدة البيانات</td>
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

<script src="assets/js/coaches.js"></script>
</body>
</html>