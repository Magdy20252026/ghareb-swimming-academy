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

if (!userCanAccess($currentUser, 'offers')) {
    header('Location: dashboard.php?access=denied');
    exit;
}

function offersToken(): string
{
    if (!isset($_SESSION['offers_token']) || !is_string($_SESSION['offers_token']) || $_SESSION['offers_token'] === '') {
        $_SESSION['offers_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['offers_token'];
}

function offersValidToken($token): bool
{
    return is_string($token) && $token !== '' && hash_equals(offersToken(), $token);
}

function offersText(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return is_string($value) ? $value : '';
}

function offersMultilineText(string $value): string
{
    $value = trim($value);
    $value = preg_replace("/\r\n?/", "\n", $value);
    $value = preg_replace("/\n{3,}/", "\n\n", $value);
    return is_string($value) ? $value : '';
}

function offersValidDate(?string $value): bool
{
    return !is_string($value) || trim($value) === '' || strtotime($value) !== false;
}

function offersFormatDate(?string $value): string
{
    if (!is_string($value) || trim($value) === '' || strtotime($value) === false) {
        return 'مفتوح';
    }

    return date('Y-m-d', strtotime($value));
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action !== '' && !offersValidToken($_POST['csrf_token'] ?? null)) {
        $message = '❌ انتهت صلاحية الطلب.';
        $messageType = 'error';
    } elseif ($action === 'save') {
        $offerId = ctype_digit((string) ($_POST['offer_id'] ?? '')) ? (int) $_POST['offer_id'] : 0;
        $title = offersText((string) ($_POST['offer_title'] ?? ''));
        $description = offersMultilineText((string) ($_POST['offer_description'] ?? ''));
        $validFrom = trim((string) ($_POST['valid_from'] ?? ''));
        $validUntil = trim((string) ($_POST['valid_until'] ?? ''));
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        if ($title === '') {
            $message = '❌ أدخل عنوان العرض.';
            $messageType = 'error';
        } elseif ($description === '') {
            $message = '❌ أدخل تفاصيل العرض.';
            $messageType = 'error';
        } elseif (!offersValidDate($validFrom) || !offersValidDate($validUntil)) {
            $message = '❌ اختر تاريخًا صحيحًا.';
            $messageType = 'error';
        } elseif ($validFrom !== '' && $validUntil !== '' && $validUntil < $validFrom) {
            $message = '❌ تاريخ نهاية العرض يجب أن يكون بعد تاريخ البداية.';
            $messageType = 'error';
        } else {
            try {
                if ($offerId > 0) {
                    $updateStmt = $pdo->prepare('UPDATE offers SET offer_title = ?, offer_description = ?, valid_from = ?, valid_until = ?, is_active = ?, created_by_user_id = ? WHERE id = ?');
                    $updateStmt->execute([
                        $title,
                        $description,
                        $validFrom !== '' ? $validFrom : null,
                        $validUntil !== '' ? $validUntil : null,
                        $isActive,
                        (int) ($currentUser['id'] ?? 0) ?: null,
                        $offerId,
                    ]);
                    $message = '✅ تم تحديث العرض.';
                } else {
                    $insertStmt = $pdo->prepare('INSERT INTO offers (offer_title, offer_description, valid_from, valid_until, is_active, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?)');
                    $insertStmt->execute([
                        $title,
                        $description,
                        $validFrom !== '' ? $validFrom : null,
                        $validUntil !== '' ? $validUntil : null,
                        $isActive,
                        (int) ($currentUser['id'] ?? 0) ?: null,
                    ]);
                    $message = '✅ تم حفظ العرض.';
                }
            } catch (Throwable $exception) {
                $message = '❌ حدث خطأ أثناء حفظ العرض.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $offerId = ctype_digit((string) ($_POST['offer_id'] ?? '')) ? (int) $_POST['offer_id'] : 0;
        try {
            $deleteStmt = $pdo->prepare('DELETE FROM offers WHERE id = ?');
            $deleteStmt->execute([$offerId]);
            $message = '✅ تم حذف العرض.';
        } catch (Throwable $exception) {
            $message = '❌ حدث خطأ أثناء حذف العرض.';
            $messageType = 'error';
        }
    }
}

$editOffer = null;
if (isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
    $editStmt = $pdo->prepare('SELECT * FROM offers WHERE id = ? LIMIT 1');
    $editStmt->execute([(int) $_GET['edit']]);
    $editOffer = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$offersStmt = $pdo->query('SELECT * FROM offers ORDER BY is_active DESC, created_at DESC, id DESC');
$offers = $offersStmt ? ($offersStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>العروض</title>
    <link rel="stylesheet" href="assets/css/swimmer-admin.css">
</head>
<body class="light-mode" data-theme-key="swimmer-admin-theme">
<div class="admin-page-shell">
    <header class="admin-page-header">
        <div>
            <span class="page-badge">🎁 العروض</span>
            <h1>العروض</h1>
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
        <div class="message-box <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <section class="admin-content-grid notifications-layout">
        <div class="panel-card form-panel">
            <h2><?php echo $editOffer === null ? 'إضافة عرض جديد' : 'تعديل العرض'; ?></h2>
            <form method="POST" class="stack-form" autocomplete="off">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="offer_id" value="<?php echo (int) ($editOffer['id'] ?? 0); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(offersToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label for="offer_title">عنوان العرض</label>
                    <input type="text" name="offer_title" id="offer_title" value="<?php echo htmlspecialchars((string) ($editOffer['offer_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="offer_description">تفاصيل العرض</label>
                    <textarea name="offer_description" id="offer_description" rows="6" required><?php echo htmlspecialchars((string) ($editOffer['offer_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="split-grid">
                    <div class="form-group">
                        <label for="valid_from">يبدأ من</label>
                        <input type="date" name="valid_from" id="valid_from" value="<?php echo htmlspecialchars((string) ($editOffer['valid_from'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="valid_until">ينتهي في</label>
                        <input type="date" name="valid_until" id="valid_until" value="<?php echo htmlspecialchars((string) ($editOffer['valid_until'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
                <label class="toggle-line">
                    <span>العرض مفعل</span>
                    <input type="checkbox" name="is_active" value="1" <?php echo !isset($editOffer['is_active']) || !empty($editOffer['is_active']) ? 'checked' : ''; ?>>
                </label>
                <div class="form-actions">
                    <button type="submit" class="primary-btn"><?php echo $editOffer === null ? 'حفظ العرض' : 'تحديث العرض'; ?></button>
                    <a href="offers.php" class="primary-btn secondary-btn">مسح</a>
                </div>
            </form>
        </div>

        <div class="panel-card list-panel">
            <h2>العروض المسجلة</h2>
            <div class="cards-grid notification-grid">
                <?php foreach ($offers as $offer): ?>
                    <article class="item-card notification-card">
                        <div class="item-card-body">
                            <div class="split-meta">
                                <strong><?php echo htmlspecialchars((string) ($offer['offer_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span class="status-chip <?php echo !empty($offer['is_active']) ? 'success-chip' : 'muted-chip'; ?>">
                                    <?php echo !empty($offer['is_active']) ? 'مفعل' : 'غير مفعل'; ?>
                                </span>
                            </div>
                            <p class="notification-message"><?php echo nl2br(htmlspecialchars((string) ($offer['offer_description'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
                            <div class="details-grid compact-details-grid">
                                <div><span>من</span><strong><?php echo htmlspecialchars(offersFormatDate($offer['valid_from'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                <div><span>إلى</span><strong><?php echo htmlspecialchars(offersFormatDate($offer['valid_until'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            </div>
                            <span class="notification-date"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string) ($offer['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="card-actions">
                            <a href="offers.php?edit=<?php echo (int) ($offer['id'] ?? 0); ?>" class="primary-btn secondary-btn">تعديل</a>
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="offer_id" value="<?php echo (int) ($offer['id'] ?? 0); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(offersToken(), ENT_QUOTES, 'UTF-8'); ?>">
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
