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

if (!userCanAccess($currentUser, "inventory")) {
    header("Location: dashboard.php?access=denied");
    exit;
}

const INVENTORY_DUPLICATE_KEY_ERROR = 1062;

function normalizeInventoryArabicNumbers(string $value): string
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

function sanitizeInventoryName(string $value): string
{
    $trimmedValue = trim($value);
    $value = preg_replace('/\s+/u', ' ', $trimmedValue);
    return $value === null ? $trimmedValue : $value;
}

function normalizeInventoryDecimal(string $value): string
{
    $value = trim(normalizeInventoryArabicNumbers($value));
    return str_replace(',', '.', $value);
}

function isValidInventoryDecimal(string $value): bool
{
    return $value !== ''
        && preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1
        && (float) $value >= 0;
}

function normalizeInventoryInteger(string $value): string
{
    return trim(normalizeInventoryArabicNumbers($value));
}

function isValidInventoryInteger(string $value): bool
{
    return $value !== ''
        && preg_match('/^\d+$/', $value) === 1;
}

function buildInventoryPageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return 'inventory.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function generateInventorySecurityToken(): string
{
    try {
        return bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        error_log('تعذر إنشاء رمز أمان لإدارة الأصناف عبر random_bytes');
        if (function_exists('openssl_random_pseudo_bytes')) {
            $isStrong = false;
            $randomBytes = openssl_random_pseudo_bytes(32, $isStrong);
            if ($randomBytes !== false && $isStrong) {
                return bin2hex($randomBytes);
            }
        }
    }

    throw new RuntimeException('تعذر إنشاء رمز أمان لإدارة الأصناف');
}

function getInventoryCsrfToken(): string
{
    if (
        !isset($_SESSION['inventory_csrf_token'])
        || !is_string($_SESSION['inventory_csrf_token'])
        || $_SESSION['inventory_csrf_token'] === ''
    ) {
        try {
            $_SESSION['inventory_csrf_token'] = generateInventorySecurityToken();
        } catch (Throwable $exception) {
            error_log('تعذر إنشاء رمز التحقق الخاص بإدارة الأصناف');
            http_response_code(500);
            exit('❌ تعذر تهيئة أمان الصفحة، يرجى إعادة المحاولة لاحقًا');
        }
    }

    return $_SESSION['inventory_csrf_token'];
}

function isValidInventoryCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getInventoryCsrfToken(), $submittedToken);
}

function formatInventoryMoney($value): string
{
    return number_format((float) $value, 2);
}

function formatInventoryDecimalForStorage($value): string
{
    return number_format((float) $value, 2, '.', '');
}

$message = "";
$messageType = "";
$editItem = null;
$canManagePurchasePrice = in_array(($currentUser["role"] ?? ""), ["مدير", "مشرف"], true);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $submittedToken = $_POST["csrf_token"] ?? '';

    if (!isValidInventoryCsrfToken($submittedToken)) {
        $message = "❌ تعذر التحقق من الطلب، يرجى إعادة المحاولة.";
        $messageType = "error";
    } elseif ($action === "save") {
        $id = trim($_POST["id"] ?? "");
        $itemName = sanitizeInventoryName($_POST["item_name"] ?? "");
        $trackQuantity = ($_POST["track_quantity"] ?? "1") === "1";
        $quantityInput = normalizeInventoryInteger($_POST["quantity"] ?? "");
        $salePriceInput = normalizeInventoryDecimal($_POST["sale_price"] ?? "");
        $purchasePriceInput = normalizeInventoryDecimal($_POST["purchase_price"] ?? "");

        if ($itemName === "") {
            $message = "❌ يرجى إدخال اسم الصنف.";
            $messageType = "error";
        } elseif (!isValidInventoryDecimal($salePriceInput)) {
            $message = "❌ يرجى إدخال سعر بيع صحيح.";
            $messageType = "error";
        } elseif ($trackQuantity && !isValidInventoryInteger($quantityInput)) {
            $message = "❌ يرجى إدخال عدد صحيح للصنف.";
            $messageType = "error";
        } elseif ($canManagePurchasePrice && !isValidInventoryDecimal($purchasePriceInput)) {
            $message = "❌ يرجى إدخال سعر شراء صحيح.";
            $messageType = "error";
        } else {
            $quantityValue = $trackQuantity ? (int) $quantityInput : null;
            $salePriceValue = formatInventoryDecimalForStorage($salePriceInput);

            try {
                $purchasePriceValue = '0.00';

                if ($canManagePurchasePrice) {
                    $purchasePriceValue = formatInventoryDecimalForStorage($purchasePriceInput);
                } elseif ($id !== '') {
                    $existingPriceStmt = $pdo->prepare("SELECT purchase_price FROM inventory_items WHERE id = ? LIMIT 1");
                    $existingPriceStmt->execute([$id]);
                    $existingPrice = $existingPriceStmt->fetchColumn();

                    if ($existingPrice === false) {
                        $message = "❌ الصنف المطلوب غير موجود.";
                        $messageType = "error";
                    } else {
                        $purchasePriceValue = formatInventoryDecimalForStorage($existingPrice);
                    }
                }

                if ($message === "") {
                    if ($id === "") {
                        if ($canManagePurchasePrice) {
                            $insertStmt = $pdo->prepare("
                                INSERT INTO inventory_items (item_name, track_quantity, quantity, purchase_price, sale_price)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $insertStmt->execute([
                                $itemName,
                                $trackQuantity ? 1 : 0,
                                $quantityValue,
                                $purchasePriceValue,
                                $salePriceValue,
                            ]);
                        } else {
                            $insertStmt = $pdo->prepare("
                                INSERT INTO inventory_items (item_name, track_quantity, quantity, purchase_price, sale_price)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $insertStmt->execute([
                                $itemName,
                                $trackQuantity ? 1 : 0,
                                $quantityValue,
                                $purchasePriceValue,
                                $salePriceValue,
                            ]);
                        }

                        $message = "✅ تم إضافة الصنف بنجاح.";
                        $messageType = "success";
                    } else {
                        if ($canManagePurchasePrice) {
                            $updateStmt = $pdo->prepare("
                                UPDATE inventory_items
                                SET item_name = ?, track_quantity = ?, quantity = ?, purchase_price = ?, sale_price = ?
                                WHERE id = ?
                            ");
                            $updateStmt->execute([
                                $itemName,
                                $trackQuantity ? 1 : 0,
                                $quantityValue,
                                $purchasePriceValue,
                                $salePriceValue,
                                $id,
                            ]);
                        } else {
                            $updateStmt = $pdo->prepare("
                                UPDATE inventory_items
                                SET item_name = ?, track_quantity = ?, quantity = ?, sale_price = ?
                                WHERE id = ?
                            ");
                            $updateStmt->execute([
                                $itemName,
                                $trackQuantity ? 1 : 0,
                                $quantityValue,
                                $salePriceValue,
                                $id,
                            ]);
                        }

                        if ($updateStmt->rowCount() > 0) {
                            $message = "✏️ تم تعديل بيانات الصنف بنجاح.";
                            $messageType = "success";
                        } else {
                            $existsStmt = $pdo->prepare("SELECT id FROM inventory_items WHERE id = ? LIMIT 1");
                            $existsStmt->execute([$id]);
                            if ($existsStmt->fetchColumn()) {
                                $message = "✅ لا توجد تغييرات جديدة على الصنف.";
                                $messageType = "success";
                            } else {
                                $message = "❌ الصنف المطلوب غير موجود.";
                                $messageType = "error";
                            }
                        }
                    }
                }
            } catch (PDOException $exception) {
                $sqlErrorCode = (int) ($exception->errorInfo[1] ?? 0);
                if ($sqlErrorCode === INVENTORY_DUPLICATE_KEY_ERROR) {
                    $message = "❌ اسم الصنف مسجل بالفعل، استخدم اسمًا مختلفًا.";
                } else {
                    $message = "❌ حدث خطأ أثناء حفظ الصنف.";
                }
                $messageType = "error";
            }
        }
    } elseif ($action === "delete") {
        $id = trim($_POST["id"] ?? "");

        if ($id === '') {
            $message = "❌ الصنف المطلوب غير صالح.";
            $messageType = "error";
        } else {
            try {
                $deleteStmt = $pdo->prepare("DELETE FROM inventory_items WHERE id = ?");
                $deleteStmt->execute([$id]);

                if ($deleteStmt->rowCount() > 0) {
                    $message = "🗑️ تم حذف الصنف بنجاح.";
                    $messageType = "success";
                } else {
                    $message = "❌ الصنف المطلوب غير موجود.";
                    $messageType = "error";
                }
            } catch (PDOException $exception) {
                $message = "❌ حدث خطأ أثناء حذف الصنف.";
                $messageType = "error";
            }
        }
    }
}

if (isset($_GET["edit"])) {
    $editId = trim($_GET["edit"]);
    if ($editId !== '') {
        $editStmt = $pdo->prepare("
            SELECT id, item_name, track_quantity, quantity, purchase_price, sale_price
            FROM inventory_items
            WHERE id = ?
            LIMIT 1
        ");
        $editStmt->execute([$editId]);
        $editItem = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($editItem === null && $message === "") {
            $message = "❌ الصنف المطلوب غير موجود.";
            $messageType = "error";
        }
    }
}

$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_items,
        SUM(CASE WHEN track_quantity = 1 THEN 1 ELSE 0 END) AS tracked_items,
        SUM(CASE WHEN track_quantity = 1 THEN quantity ELSE 0 END) AS total_units,
        AVG(sale_price) AS average_sale_price,
        SUM(CASE WHEN track_quantity = 1 THEN quantity * purchase_price ELSE purchase_price END) AS total_purchase_value,
        SUM(CASE WHEN track_quantity = 1 THEN quantity * sale_price ELSE sale_price END) AS total_sale_value
    FROM inventory_items
");
$inventoryStats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$itemsStmt = $pdo->query("
    SELECT id, item_name, track_quantity, quantity, purchase_price, sale_price, created_at, updated_at
    FROM inventory_items
    ORDER BY updated_at DESC, id DESC
");
$inventoryItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
$inventoryCsrfToken = getInventoryCsrfToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الأصناف</title>
    <link rel="stylesheet" href="assets/css/inventory.css">
</head>
<body class="light-mode">
<div class="inventory-page">
    <header class="page-header">
        <div class="header-text">
            <span class="eyebrow">📦 إدارة المخزون</span>
            <h1>إدارة الأصناف</h1>
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

    <?php if (!empty($message)): ?>
        <div class="message-box <?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <section class="hero-grid">
        <article class="hero-card">
            <span>إجمالي الأصناف</span>
            <strong><?php echo (int) ($inventoryStats["total_items"] ?? 0); ?></strong>
        </article>

        <article class="hero-card">
            <span>أصناف لها عدد</span>
            <strong><?php echo (int) ($inventoryStats["tracked_items"] ?? 0); ?></strong>
        </article>

        <article class="hero-card">
            <span>إجمالي الكميات</span>
            <strong><?php echo (int) ($inventoryStats["total_units"] ?? 0); ?></strong>
        </article>

        <article class="hero-card">
            <span>متوسط سعر البيع</span>
            <strong><?php echo formatInventoryMoney($inventoryStats["average_sale_price"] ?? 0); ?> ج.م</strong>
        </article>

        <?php if ($canManagePurchasePrice): ?>
            <article class="hero-card">
                <span>إجمالي قيمة الشراء</span>
                <strong><?php echo formatInventoryMoney($inventoryStats["total_purchase_value"] ?? 0); ?> ج.م</strong>
            </article>
        <?php endif; ?>
    </section>

    <section class="content-grid">
        <div class="form-card">
            <div class="card-head">
                <h2><?php echo $editItem ? "✏️ تعديل صنف" : "➕ إضافة صنف جديد"; ?></h2>
            </div>

            <form method="POST" id="inventoryForm" class="inventory-form" autocomplete="off">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="id" value="<?php echo htmlspecialchars($editItem["id"] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($inventoryCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-group">
                    <label for="item_name">🏷️ اسم الصنف</label>
                    <input
                        type="text"
                        name="item_name"
                        id="item_name"
                        value="<?php echo htmlspecialchars($editItem["item_name"] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="مثال: نظارة سباحة / اشتراك خاص"
                        required
                    >
                </div>

                <?php $trackQuantityValue = !isset($editItem["track_quantity"]) || (int) $editItem["track_quantity"] === 1; ?>
                <div class="form-group">
                    <label>🧭 نوع إدارة الصنف</label>
                    <div class="option-cards" id="trackQuantityOptions">
                        <label class="option-card <?php echo $trackQuantityValue ? 'active' : ''; ?>">
                            <input type="radio" name="track_quantity" value="1" <?php echo $trackQuantityValue ? 'checked' : ''; ?>>
                            <span class="option-icon">🔢</span>
                            <span class="option-title">له عدد</span>
                        </label>

                        <label class="option-card <?php echo !$trackQuantityValue ? 'active' : ''; ?>">
                            <input type="radio" name="track_quantity" value="0" <?php echo !$trackQuantityValue ? 'checked' : ''; ?>>
                            <span class="option-icon">💵</span>
                            <span class="option-title">سعر فقط</span>
                        </label>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group quantity-group" id="quantityGroup">
                        <label for="quantity">📦 عدد الصنف</label>
                        <input
                            type="number"
                            min="0"
                            step="1"
                            name="quantity"
                            id="quantity"
                            value="<?php echo htmlspecialchars((string) ($editItem["quantity"] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="أدخل الكمية الحالية"
                        >
                    </div>

                    <?php if ($canManagePurchasePrice): ?>
                        <div class="form-group">
                            <label for="purchase_price">🧾 سعر الشراء</label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                name="purchase_price"
                                id="purchase_price"
                                value="<?php echo htmlspecialchars(isset($editItem["purchase_price"]) ? formatInventoryDecimalForStorage($editItem["purchase_price"]) : '', ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="أدخل سعر الشراء"
                                required
                            >
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="sale_price">💰 سعر البيع</label>
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            name="sale_price"
                            id="sale_price"
                            value="<?php echo htmlspecialchars(isset($editItem["sale_price"]) ? formatInventoryDecimalForStorage($editItem["sale_price"]) : '', ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="أدخل سعر البيع"
                            required
                        >
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="save-btn"><?php echo $editItem ? "💾 حفظ التعديل" : "✅ إضافة الصنف"; ?></button>
                    <button type="button" class="clear-btn" id="clearBtn">🧹 تصفية الحقول</button>
                </div>
            </form>
        </div>

        <aside class="side-panel">
            <div class="side-card">
                <h3>⚙️ حالة المخزون</h3>
                <div class="mini-stats">
                    <div class="mini-stat">
                        <span>قيمة البيع الحالية</span>
                        <strong><?php echo formatInventoryMoney($inventoryStats["total_sale_value"] ?? 0); ?> ج.م</strong>
                    </div>
                    <div class="mini-stat">
                        <span>عدد الأصناف السعرية فقط</span>
                        <strong><?php echo max(0, (int) ($inventoryStats["total_items"] ?? 0) - (int) ($inventoryStats["tracked_items"] ?? 0)); ?></strong>
                    </div>
                </div>
            </div>
        </aside>
    </section>

    <section class="table-card">
        <div class="card-head table-head">
            <div>
                <h2>📋 قائمة الأصناف</h2>
            </div>
            <span class="table-count"><?php echo count($inventoryItems); ?> صنف</span>
        </div>

        <div class="table-wrapper">
            <table>
                <caption class="sr-only">جدول الأصناف المسجلة داخل النظام مع النوع والأسعار وحالة المخزون والإجراءات المتاحة.</caption>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم الصنف</th>
                        <th>النوع</th>
                        <th>الكمية / الحالة</th>
                        <?php if ($canManagePurchasePrice): ?>
                            <th>سعر الشراء</th>
                        <?php endif; ?>
                        <th>سعر البيع</th>
                        <th>آخر تحديث</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($inventoryItems)): ?>
                        <?php foreach ($inventoryItems as $index => $item): ?>
                            <?php
                                $tracksQuantity = (int) ($item["track_quantity"] ?? 0) === 1;
                                $quantity = (int) ($item["quantity"] ?? 0);
                                $stockBadgeClass = !$tracksQuantity
                                    ? 'stock-static'
                                    : ($quantity > 0 ? 'stock-available' : 'stock-empty');
                                $stockText = !$tracksQuantity
                                    ? 'سعر فقط'
                                    : ($quantity > 0 ? 'متوفر' : 'نفد المخزون');
                            ?>
                            <tr>
                                <td data-label="#">
                                    <?php echo $index + 1; ?>
                                </td>
                                <td data-label="اسم الصنف">
                                    <div class="item-cell">
                                        <span class="item-avatar"><?php echo $tracksQuantity ? '📦' : '🧾'; ?></span>
                                        <div>
                                            <strong><?php echo htmlspecialchars($item["item_name"], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="النوع">
                                    <span class="type-badge <?php echo $tracksQuantity ? 'tracked-type' : 'static-type'; ?>">
                                        <?php echo $tracksQuantity ? 'له عدد' : 'سعر فقط'; ?>
                                    </span>
                                </td>
                                <td data-label="الكمية / الحالة">
                                    <div class="stock-cell">
                                        <strong><?php echo $tracksQuantity ? $quantity . ' قطعة' : '—'; ?></strong>
                                        <span class="stock-badge <?php echo $stockBadgeClass; ?>"><?php echo $stockText; ?></span>
                                    </div>
                                </td>
                                <?php if ($canManagePurchasePrice): ?>
                                    <td data-label="سعر الشراء"><?php echo formatInventoryMoney($item["purchase_price"] ?? 0); ?> ج.م</td>
                                <?php endif; ?>
                                <td data-label="سعر البيع"><?php echo formatInventoryMoney($item["sale_price"] ?? 0); ?> ج.م</td>
                                <td data-label="آخر تحديث"><?php echo htmlspecialchars((string) ($item["updated_at"] ?? $item["created_at"] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="الإجراءات">
                                    <div class="action-buttons">
                                        <a href="<?php echo buildInventoryPageUrl(["edit" => $item["id"]]); ?>" class="edit-btn">✏️ تعديل</a>

                                        <form method="POST" class="inline-form delete-item-form">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars((string) $item["id"], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($inventoryCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" class="delete-btn">🗑️ حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $canManagePurchasePrice ? '8' : '7'; ?>" class="empty-row">لا توجد أصناف مسجلة حاليًا، ابدأ بإضافة أول صنف من النموذج أعلاه.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script src="assets/js/inventory.js"></script>
</body>
</html>
