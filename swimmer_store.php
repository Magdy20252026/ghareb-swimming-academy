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

if (!userCanAccess($currentUser, 'swimmer_store')) {
    header('Location: dashboard.php?access=denied');
    exit;
}

const SWIMMER_STORE_UPLOAD_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'swimmer_store';
const SWIMMER_STORE_UPLOAD_PUBLIC_DIR = 'uploads/swimmer_store';
const SWIMMER_STORE_PAGE_FILE = 'swimmer_store.php';

function swimmerStoreToken(): string
{
    if (!isset($_SESSION['swimmer_store_token']) || !is_string($_SESSION['swimmer_store_token']) || $_SESSION['swimmer_store_token'] === '') {
        $_SESSION['swimmer_store_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['swimmer_store_token'];
}

function swimmerStoreValidToken($token): bool
{
    return is_string($token) && $token !== '' && hash_equals(swimmerStoreToken(), $token);
}

function swimmerStoreNormalizePath($path): ?string
{
    if (!is_string($path)) {
        return null;
    }

    $path = trim($path);
    if (strpos($path, SWIMMER_STORE_UPLOAD_PUBLIC_DIR . '/') !== 0) {
        return null;
    }

    $fileName = basename($path);
    if ($fileName === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $fileName) !== 1) {
        return null;
    }

    return SWIMMER_STORE_UPLOAD_PUBLIC_DIR . '/' . $fileName;
}

function swimmerStoreAbsolutePath(?string $path): ?string
{
    $normalizedPath = swimmerStoreNormalizePath($path);
    if ($normalizedPath === null) {
        return null;
    }

    $uploadDirectory = realpath(SWIMMER_STORE_UPLOAD_DIR);
    if ($uploadDirectory === false) {
        return null;
    }

    return $uploadDirectory . DIRECTORY_SEPARATOR . basename($normalizedPath);
}

function swimmerStoreDeleteImage(?string $path): void
{
    $absolutePath = swimmerStoreAbsolutePath($path);
    if ($absolutePath !== null && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function swimmerStoreEnsureDir(): bool
{
    return is_dir(SWIMMER_STORE_UPLOAD_DIR) || mkdir(SWIMMER_STORE_UPLOAD_DIR, 0755, true);
}

function swimmerStoreUploadImage(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || trim((string) ($file['name'] ?? '')) === '') {
        return ['path' => null, 'error' => false];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !swimmerStoreEnsureDir()) {
        return ['path' => null, 'error' => true];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['path' => null, 'error' => true];
    }

    $mime = '';
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    if ($finfo !== false) {
        $mime = (string) finfo_file($finfo, $tmpName);
        finfo_close($finfo);
    }

    if ($mime === '' || strpos($mime, 'image/') !== 0) {
        return ['path' => null, 'error' => true];
    }

    $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $extension = preg_replace('/[^a-z0-9]+/i', '', $extension);
    $extension = $extension !== '' ? $extension : 'jpg';
    $fileName = 'store-' . bin2hex(random_bytes(12)) . '.' . $extension;
    $destination = SWIMMER_STORE_UPLOAD_DIR . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $destination)) {
        return ['path' => null, 'error' => true];
    }

    return ['path' => SWIMMER_STORE_UPLOAD_PUBLIC_DIR . '/' . $fileName, 'error' => false];
}

function swimmerStoreText(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return is_string($value) ? $value : '';
}

function swimmerStoreDecimal(string $value): string
{
    return str_replace(',', '.', trim($value));
}

function swimmerStoreFormatPrice($value): string
{
    return number_format((float) $value, 2);
}

function swimmerStoreItems(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM academy_store_products ORDER BY updated_at DESC, id DESC');
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function swimmerStoreItem(PDO $pdo, int $itemId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM academy_store_products WHERE id = ? LIMIT 1');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    return $item ?: null;
}

$siteSettings = getSiteSettings($pdo);
$editItem = null;
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action !== '' && !swimmerStoreValidToken($_POST['csrf_token'] ?? null)) {
        $message = '❌ انتهت صلاحية الطلب.';
        $messageType = 'error';
    } elseif ($action === 'save') {
        $itemId = ctype_digit((string) ($_POST['item_id'] ?? '')) ? (int) $_POST['item_id'] : 0;
        $editItem = $itemId > 0 ? swimmerStoreItem($pdo, $itemId) : null;
        $productName = swimmerStoreText((string) ($_POST['product_name'] ?? ''));
        $productPriceInput = swimmerStoreDecimal((string) ($_POST['product_price'] ?? ''));

        if ($productName === '') {
            $message = '❌ أدخل اسم المنتج.';
            $messageType = 'error';
        } elseif ($productPriceInput === '' || preg_match('/^\d+(?:\.\d{1,2})?$/', $productPriceInput) !== 1) {
            $message = '❌ أدخل السعر.';
            $messageType = 'error';
        } else {
            $upload = swimmerStoreUploadImage($_FILES['product_image'] ?? []);
            if (!empty($upload['error'])) {
                $message = '❌ تعذر رفع الصورة.';
                $messageType = 'error';
            } else {
                try {
                    if ($editItem === null) {
                        $insertStmt = $pdo->prepare('INSERT INTO academy_store_products (product_name, product_price, product_image_path, created_by_user_id) VALUES (?, ?, ?, ?)');
                        $insertStmt->execute([
                            $productName,
                            $productPriceInput,
                            $upload['path'],
                            (int) ($currentUser['id'] ?? 0) ?: null,
                        ]);
                        $message = '✅ تم حفظ المنتج.';
                    } else {
                        $productImagePath = $upload['path'] ?? ($editItem['product_image_path'] ?? null);
                        $updateStmt = $pdo->prepare('UPDATE academy_store_products SET product_name = ?, product_price = ?, product_image_path = ? WHERE id = ?');
                        $updateStmt->execute([$productName, $productPriceInput, $productImagePath, $itemId]);
                        if (!empty($upload['path']) && !empty($editItem['product_image_path'])) {
                            swimmerStoreDeleteImage((string) $editItem['product_image_path']);
                        }
                        $message = '✅ تم تحديث المنتج.';
                    }
                } catch (Throwable $exception) {
                    if (!empty($upload['path'])) {
                        swimmerStoreDeleteImage((string) $upload['path']);
                    }
                    $message = '❌ حدث خطأ أثناء الحفظ.';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'delete') {
        $itemId = ctype_digit((string) ($_POST['item_id'] ?? '')) ? (int) $_POST['item_id'] : 0;
        $deleteItem = swimmerStoreItem($pdo, $itemId);
        if ($deleteItem === null) {
            $message = '❌ المنتج غير موجود.';
            $messageType = 'error';
        } else {
            try {
                $deleteStmt = $pdo->prepare('DELETE FROM academy_store_products WHERE id = ?');
                $deleteStmt->execute([$itemId]);
                swimmerStoreDeleteImage((string) ($deleteItem['product_image_path'] ?? ''));
                $message = '✅ تم حذف المنتج.';
            } catch (Throwable $exception) {
                $message = '❌ حدث خطأ أثناء الحذف.';
                $messageType = 'error';
            }
        }
    }
}

if (isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
    $editItem = swimmerStoreItem($pdo, (int) $_GET['edit']);
}

$formData = [
    'item_id' => (string) ($editItem['id'] ?? ''),
    'product_name' => (string) ($editItem['product_name'] ?? ''),
    'product_price' => isset($editItem['product_price']) ? (string) $editItem['product_price'] : '',
];

$products = swimmerStoreItems($pdo);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المتجر</title>
    <link rel="stylesheet" href="assets/css/swimmer-admin.css">
</head>
<body class="light-mode" data-theme-key="swimmer-admin-theme">
<div class="admin-page-shell">
    <header class="admin-page-header">
        <div>
            <span class="page-badge">🛍️ المتجر</span>
            <h1>المتجر</h1>
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
            <a href="dashboard.php" class="primary-btn secondary-btn">الرجوع</a>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div class="message-box <?php echo academyHtmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo academyHtmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <section class="admin-content-grid">
        <div class="panel-card form-panel">
            <h2><?php echo $editItem === null ? 'إضافة منتج' : 'تعديل منتج'; ?></h2>
            <form method="POST" enctype="multipart/form-data" class="stack-form" autocomplete="off">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="item_id" value="<?php echo academyHtmlspecialchars($formData['item_id'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars(swimmerStoreToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label for="product_name">اسم المنتج</label>
                    <input type="text" name="product_name" id="product_name" value="<?php echo academyHtmlspecialchars($formData['product_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="product_price">السعر بالجنيه المصري</label>
                    <input type="number" min="0" step="0.01" name="product_price" id="product_price" value="<?php echo academyHtmlspecialchars($formData['product_price'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="product_image">صورة المنتج</label>
                    <input type="file" name="product_image" id="product_image" accept="image/*" <?php echo $editItem === null ? 'required' : ''; ?>>
                </div>
                <div class="form-actions">
                    <button type="submit" class="primary-btn"><?php echo $editItem === null ? 'حفظ' : 'تحديث'; ?></button>
                    <a href="<?php echo SWIMMER_STORE_PAGE_FILE; ?>" class="primary-btn secondary-btn">مسح</a>
                </div>
            </form>
        </div>

        <div class="panel-card list-panel">
            <h2>المنتجات</h2>
            <div class="cards-grid product-grid">
                <?php foreach ($products as $product): ?>
                    <?php $productImagePath = swimmerStoreNormalizePath($product['product_image_path'] ?? null); ?>
                    <article class="item-card product-card">
                        <div class="item-image-wrap">
                            <?php if ($productImagePath !== null): ?>
                                <img src="<?php echo academyHtmlspecialchars($productImagePath, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo academyHtmlspecialchars((string) ($product['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php else: ?>
                                <div class="item-image-placeholder">🛍️</div>
                            <?php endif; ?>
                        </div>
                        <div class="item-card-body">
                            <strong><?php echo academyHtmlspecialchars((string) ($product['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span><?php echo swimmerStoreFormatPrice($product['product_price'] ?? 0); ?> ج.م</span>
                        </div>
                        <div class="card-actions">
                            <a href="swimmer_store.php?edit=<?php echo (int) ($product['id'] ?? 0); ?>" class="primary-btn secondary-btn">تعديل</a>
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="item_id" value="<?php echo (int) ($product['id'] ?? 0); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars(swimmerStoreToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="primary-btn danger-btn">حذف</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</div>
<script src="assets/js/swimmer-admin.js"></script>
</body>
</html>
