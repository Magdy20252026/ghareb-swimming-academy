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

if (!userCanAccess($currentUser, "settings")) {
    header("Location: dashboard.php?access=denied");
    exit;
}

function ensureSiteLogoUploadDirectoryExists(string $directory): bool
{
    if (is_dir($directory)) {
        return true;
    }

    return mkdir($directory, 0755, true);
}

function generateSiteLogoToken(): ?string
{
    try {
        return bin2hex(random_bytes(12));
    } catch (Throwable $exception) {
        error_log('تعذر إنشاء رمز شعار الأكاديمية');
        return null;
    }
}

function detectSiteLogoExtension(string $originalName, string $mimeType): string
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension !== '' && preg_match('/^[a-z0-9]{2,10}$/', $extension) === 1) {
        return $extension;
    }

    $mimeMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/bmp' => 'bmp',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'image/tiff' => 'tiff',
        'image/avif' => 'avif',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    if (isset($mimeMap[$mimeType])) {
        return $mimeMap[$mimeType];
    }

    if (strpos($mimeType, 'image/') === 0) {
        $mimeExtension = strtolower(substr($mimeType, 6));
        $mimeExtension = preg_replace('/[^a-z0-9]+/', '', $mimeExtension);
        if ($mimeExtension === null) {
            return 'img';
        }
        if ($mimeExtension !== null && $mimeExtension !== '') {
            return $mimeExtension;
        }
    }

    return 'img';
}

function getSiteLogoLogIdentifier(string $path): string
{
    return substr(sha1($path), 0, 12);
}

function getSiteLogoLogUserContext(): string
{
    return isset($_SESSION['user_id']) ? 'user:' . (int) $_SESSION['user_id'] : 'user:guest';
}

function deleteStoredSiteLogo(string $relativePath, string $baseDirectory): bool
{
    $relativePath = trim($relativePath);
    if ($relativePath === '') {
        return false;
    }

    $normalizedPath = str_replace('\\', '/', rawurldecode($relativePath));
    $normalizedPath = ltrim($normalizedPath, '/');
    $uploadDirectory = realpath(
        $baseDirectory . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'site'
    );
    if ($uploadDirectory === false) {
        return false;
    }

    $absolutePath = rtrim($baseDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath), DIRECTORY_SEPARATOR);
    $resolvedDirectory = realpath(dirname($absolutePath));
    if (
        strpos($normalizedPath, 'assets/uploads/site/') !== 0
        || $resolvedDirectory === false
        || strpos($resolvedDirectory, $uploadDirectory) !== 0
    ) {
        error_log(
            'تم رفض حذف ملف شعار خارج مجلد الشعارات: '
            . getSiteLogoLogUserContext()
            . ' ref:'
            . getSiteLogoLogIdentifier($normalizedPath)
        );
        return false;
    }

    if (!is_file($absolutePath)) {
        return true;
    }

    if (unlink($absolutePath)) {
        return true;
    }

    error_log('تعذر حذف ملف شعار الأكاديمية: ' . getSiteLogoLogIdentifier($normalizedPath));
    return false;
}

function storeSiteLogoUpload(array $file, string $baseDirectory): array
{
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return ['path' => null, 'error' => null];
    }

    if ($uploadError !== UPLOAD_ERR_OK) {
        return ['path' => null, 'error' => '❌ تعذر رفع الشعار، يرجى إعادة المحاولة'];
    }

    $temporaryPath = $file['tmp_name'] ?? '';
    $originalName = trim((string) ($file['name'] ?? ''));

    if (!is_string($temporaryPath) || $temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        return ['path' => null, 'error' => '❌ ملف الشعار المرفوع غير صالح'];
    }

    $mimeType = '';
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;

    try {
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
    } finally {
        if ($finfo !== false) {
            finfo_close($finfo);
        }
    }

    if ($mimeType === '' || strpos($mimeType, 'image/') !== 0) {
        return ['path' => null, 'error' => '❌ يجب اختيار ملف صورة صالح للشعار'];
    }

    $uploadDirectory = $baseDirectory . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'site';
    $publicDirectory = 'assets/uploads/site';

    if (!ensureSiteLogoUploadDirectoryExists($uploadDirectory)) {
        return ['path' => null, 'error' => '❌ تعذر تجهيز مجلد حفظ شعار الأكاديمية'];
    }

    $randomToken = generateSiteLogoToken();
    if ($randomToken === null) {
        return ['path' => null, 'error' => '❌ تعذر إنشاء اسم آمن لملف الشعار'];
    }

    $extension = detectSiteLogoExtension($originalName, $mimeType);
    $fileName = 'academy-logo-current-' . date('YmdHis') . '-' . $randomToken . '.' . $extension;
    $destinationPath = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($temporaryPath, $destinationPath)) {
        return ['path' => null, 'error' => '❌ تعذر حفظ ملف الشعار'];
    }

    return ['path' => $publicDirectory . '/' . $fileName, 'error' => null];
}

$message = '';
$messageType = '';
$siteSettings = getSiteSettings($pdo);
$academyName = $siteSettings['academy_name'];
$academyLogoPath = $siteSettings['academy_logo_path'];
$academyLogoInitial = getAcademyLogoInitial($academyName);
$baseDirectory = __DIR__;
$socialPlatforms = [
    'facebook' => ['field' => 'facebook_url', 'label' => 'فيسبوك'],
    'whatsapp' => ['field' => 'whatsapp_url', 'label' => 'واتساب'],
    'youtube' => ['field' => 'youtube_url', 'label' => 'يوتيوب'],
    'tiktok' => ['field' => 'tiktok_url', 'label' => 'تيك توك'],
    'instagram' => ['field' => 'instagram_url', 'label' => 'إنستاجرام'],
];
$socialLinksInput = [];

foreach ($socialPlatforms as $platformKey => $platform) {
    $fieldName = $platform['field'];
    $socialLinksInput[$fieldName] = (string) ($siteSettings[$fieldName] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $academyNameInput = trim((string) ($_POST['academy_name'] ?? ''));
    $academyName = $academyNameInput;
    $academyLogoInitial = getAcademyLogoInitial($academyName);

    if ($academyNameInput === '') {
        $message = '❌ يرجى إدخال اسم الأكاديمية';
        $messageType = 'error';
    } else {
        $normalizedSocialLinks = [];

        foreach ($socialPlatforms as $platformKey => $platform) {
            $fieldName = $platform['field'];
            $submittedValue = trim((string) ($_POST[$fieldName] ?? ''));
            $socialLinksInput[$fieldName] = $submittedValue;
            $normalizedValue = normalizeSiteSettingsSocialLink($platformKey, $submittedValue);

            if ($submittedValue !== '' && $normalizedValue === null) {
                $message = '❌ رابط ' . $platform['label'] . ' غير صالح';
                $messageType = 'error';
                break;
            }

            $normalizedSocialLinks[$fieldName] = $normalizedValue;
        }

        $updatedLogoPath = $siteSettings['academy_logo_path'];
        $uploadedLogo = $_FILES['academy_logo'] ?? null;

        if ($messageType !== 'error' && is_array($uploadedLogo)) {
            $uploadResult = storeSiteLogoUpload($uploadedLogo, $baseDirectory);

            if (is_string($uploadResult['error'] ?? null) && $uploadResult['error'] !== '') {
                $message = $uploadResult['error'];
                $messageType = 'error';
            } elseif (is_string($uploadResult['path'] ?? null) && $uploadResult['path'] !== '') {
                if (is_string($updatedLogoPath) && $updatedLogoPath !== '') {
                    if (!deleteStoredSiteLogo($updatedLogoPath, $baseDirectory)) {
                        error_log('تعذر حذف شعار الأكاديمية السابق: ' . getSiteLogoLogIdentifier($updatedLogoPath));
                    }
                }
                $updatedLogoPath = $uploadResult['path'];
            }
        }

        if ($messageType !== 'error') {
            try {
                if ($siteSettings['id'] !== null) {
                    $updateStmt = $pdo->prepare('UPDATE site_settings SET academy_name = ?, academy_logo_path = ?, facebook_url = ?, whatsapp_url = ?, youtube_url = ?, tiktok_url = ?, instagram_url = ? WHERE id = ?');
                    $updateStmt->execute([
                        $academyNameInput,
                        $updatedLogoPath,
                        $normalizedSocialLinks['facebook_url'] ?? null,
                        $normalizedSocialLinks['whatsapp_url'] ?? null,
                        $normalizedSocialLinks['youtube_url'] ?? null,
                        $normalizedSocialLinks['tiktok_url'] ?? null,
                        $normalizedSocialLinks['instagram_url'] ?? null,
                        $siteSettings['id'],
                    ]);
                } else {
                    $insertStmt = $pdo->prepare('INSERT INTO site_settings (academy_name, academy_logo_path, facebook_url, whatsapp_url, youtube_url, tiktok_url, instagram_url) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $insertStmt->execute([
                        $academyNameInput,
                        $updatedLogoPath,
                        $normalizedSocialLinks['facebook_url'] ?? null,
                        $normalizedSocialLinks['whatsapp_url'] ?? null,
                        $normalizedSocialLinks['youtube_url'] ?? null,
                        $normalizedSocialLinks['tiktok_url'] ?? null,
                        $normalizedSocialLinks['instagram_url'] ?? null,
                    ]);
                }

                $siteSettings = getSiteSettings($pdo);
                $academyName = $siteSettings['academy_name'];
                $academyLogoPath = $siteSettings['academy_logo_path'];
                $academyLogoInitial = getAcademyLogoInitial($academyName);
                foreach ($socialPlatforms as $platform) {
                    $fieldName = $platform['field'];
                    $socialLinksInput[$fieldName] = (string) ($siteSettings[$fieldName] ?? '');
                }
                $message = '✅ تم حفظ إعدادات الموقع بنجاح';
                $messageType = 'success';
            } catch (PDOException $exception) {
                error_log('تعذر حفظ إعدادات الموقع: ' . $exception->getMessage());
                $message = '❌ حدث خطأ أثناء حفظ إعدادات الموقع';
                $messageType = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعدادات الموقع - <?php echo htmlspecialchars($academyName); ?></title>
    <link rel="stylesheet" href="assets/css/site-settings.css">
</head>
<body class="light-mode">
<div class="settings-page">
    <div class="page-header">
        <div class="header-text">
            <h1>⚙️ إعدادات الموقع</h1>
            <p>تحديث اسم الأكاديمية وشعارها وروابط السوشيال ميديا الظاهرة في بوابة السباحين.</p>
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
    </div>

    <?php if ($message !== ''): ?>
        <div class="message-box <?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="settings-grid">
        <section class="form-card">
            <div class="card-head">
                <h2>🏫 بيانات الأكاديمية</h2>
            </div>

            <form method="POST" enctype="multipart/form-data" class="settings-form" autocomplete="off">
                <div class="form-group">
                    <label for="academy_name">اسم الأكاديمية</label>
                    <input
                        type="text"
                        id="academy_name"
                        name="academy_name"
                        value="<?php echo htmlspecialchars($academyName); ?>"
                        placeholder="أدخل اسم الأكاديمية"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="academy_logo">شعار الأكاديمية</label>
                    <input type="file" id="academy_logo" name="academy_logo" accept="image/*">
                    <small>يمكنك رفع أي ملف صورة، وسيتم عرضه تلقائيًا بمقاس مناسب داخل النظام.</small>
                </div>

                <div class="card-head social-links-head">
                    <h2>📱 روابط السوشيال ميديا</h2>
                </div>

                <div class="form-group">
                    <label for="facebook_url">رابط فيسبوك</label>
                    <input type="text" id="facebook_url" name="facebook_url" value="<?php echo htmlspecialchars($socialLinksInput['facebook_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://facebook.com/...">
                </div>

                <div class="form-group">
                    <label for="whatsapp_url">رابط أو رقم واتساب</label>
                    <input type="text" id="whatsapp_url" name="whatsapp_url" value="<?php echo htmlspecialchars($socialLinksInput['whatsapp_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://wa.me/... أو 01000000000">
                    <small>يمكن إدخال رابط واتساب الكامل أو رقم الهاتف وسيتم تحويله تلقائيًا.</small>
                </div>

                <div class="form-group">
                    <label for="youtube_url">رابط يوتيوب</label>
                    <input type="text" id="youtube_url" name="youtube_url" value="<?php echo htmlspecialchars($socialLinksInput['youtube_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://youtube.com/...">
                </div>

                <div class="form-group">
                    <label for="tiktok_url">رابط تيك توك</label>
                    <input type="text" id="tiktok_url" name="tiktok_url" value="<?php echo htmlspecialchars($socialLinksInput['tiktok_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://tiktok.com/...">
                </div>

                <div class="form-group">
                    <label for="instagram_url">رابط إنستاجرام</label>
                    <input type="text" id="instagram_url" name="instagram_url" value="<?php echo htmlspecialchars($socialLinksInput['instagram_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://instagram.com/...">
                </div>

                <div class="form-actions">
                    <button type="submit" class="save-btn">💾 حفظ الإعدادات</button>
                </div>
            </form>
        </section>

        <aside class="preview-card">
            <div class="card-head">
                <h2>🖼️ معاينة الشعار</h2>
            </div>

            <div class="academy-preview">
                <div class="academy-logo-preview">
                    <?php if ($academyLogoPath !== null): ?>
                        <img src="<?php echo htmlspecialchars($academyLogoPath); ?>" alt="شعار الأكاديمية">
                    <?php else: ?>
                        <span><?php echo htmlspecialchars($academyLogoInitial); ?></span>
                    <?php endif; ?>
                </div>
                <h3><?php echo htmlspecialchars($academyName); ?></h3>
                <p>ستظهر هذه البيانات في شاشة تسجيل الدخول ولوحة التحكم وفوتر بوابة السباحين.</p>

                <div class="social-preview-list">
                    <?php foreach ($socialPlatforms as $platform): ?>
                        <?php $fieldName = $platform['field']; ?>
                        <?php if (!empty($siteSettings[$fieldName])): ?>
                            <a href="<?php echo htmlspecialchars((string) $siteSettings[$fieldName], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="social-preview-link">
                                <?php echo htmlspecialchars($platform['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </aside>
    </div>
</div>

<script src="assets/js/script.js"></script>
</body>
</html>
