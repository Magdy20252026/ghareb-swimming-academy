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

if (!userCanAccess($currentUser, 'swimmer_notifications')) {
    header('Location: dashboard.php?access=denied');
    exit;
}

function swimmerNotificationsToken(): string
{
    if (!isset($_SESSION['swimmer_notifications_token']) || !is_string($_SESSION['swimmer_notifications_token']) || $_SESSION['swimmer_notifications_token'] === '') {
        $_SESSION['swimmer_notifications_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['swimmer_notifications_token'];
}

function swimmerNotificationsValidToken($token): bool
{
    return is_string($token) && $token !== '' && hash_equals(swimmerNotificationsToken(), $token);
}

function swimmerNotificationsText(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return is_string($value) ? $value : '';
}

function swimmerNotificationsOptions(PDO $pdo, string $field): array
{
    $allowedFields = [
        'branch' => 'subscription_branch',
        'subscription' => 'subscription_name',
        'level' => 'subscription_category',
    ];
    $column = $allowedFields[$field] ?? null;
    if ($column === null) {
        return [];
    }

    $stmt = $pdo->query('SELECT DISTINCT ' . $column . ' FROM academy_players WHERE ' . $column . ' IS NOT NULL AND ' . $column . ' <> "" ORDER BY ' . $column . ' ASC');
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
}

function swimmerNotificationAudience(array $notification): string
{
    $parts = [];
    if (!empty($notification['target_branch'])) {
        $parts[] = (string) $notification['target_branch'];
    }
    if (!empty($notification['target_subscription'])) {
        $parts[] = (string) $notification['target_subscription'];
    }
    if (!empty($notification['target_level'])) {
        $parts[] = (string) $notification['target_level'];
    }

    return $parts === [] ? 'عام' : implode(' / ', $parts);
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action !== '' && !swimmerNotificationsValidToken($_POST['csrf_token'] ?? null)) {
        $message = '❌ انتهت صلاحية الطلب.';
        $messageType = 'error';
    } elseif ($action === 'save') {
        $notificationId = ctype_digit((string) ($_POST['notification_id'] ?? '')) ? (int) $_POST['notification_id'] : 0;
        $notificationMessage = trim((string) ($_POST['notification_message'] ?? ''));
        $targetBranch = swimmerNotificationsText((string) ($_POST['target_branch'] ?? ''));
        $targetSubscription = swimmerNotificationsText((string) ($_POST['target_subscription'] ?? ''));
        $targetLevel = swimmerNotificationsText((string) ($_POST['target_level'] ?? ''));

        if ($notificationMessage === '') {
            $message = '❌ أدخل الإشعار.';
            $messageType = 'error';
        } else {
            try {
                if ($notificationId > 0) {
                    $updateStmt = $pdo->prepare('UPDATE swimmer_notifications SET notification_message = ?, target_branch = ?, target_subscription = ?, target_level = ?, created_by_user_id = ? WHERE id = ?');
                    $updateStmt->execute([$notificationMessage, $targetBranch !== '' ? $targetBranch : null, $targetSubscription !== '' ? $targetSubscription : null, $targetLevel !== '' ? $targetLevel : null, (int) ($currentUser['id'] ?? 0) ?: null, $notificationId]);
                    $message = '✅ تم تحديث الإشعار.';
                } else {
                    $insertStmt = $pdo->prepare('INSERT INTO swimmer_notifications (notification_message, target_branch, target_subscription, target_level, created_by_user_id) VALUES (?, ?, ?, ?, ?)');
                    $insertStmt->execute([$notificationMessage, $targetBranch !== '' ? $targetBranch : null, $targetSubscription !== '' ? $targetSubscription : null, $targetLevel !== '' ? $targetLevel : null, (int) ($currentUser['id'] ?? 0) ?: null]);
                    $message = '✅ تم حفظ الإشعار.';
                }
            } catch (Throwable $exception) {
                $message = '❌ حدث خطأ أثناء الحفظ.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $notificationId = ctype_digit((string) ($_POST['notification_id'] ?? '')) ? (int) $_POST['notification_id'] : 0;
        try {
            $deleteStmt = $pdo->prepare('DELETE FROM swimmer_notifications WHERE id = ?');
            $deleteStmt->execute([$notificationId]);
            $message = '✅ تم حذف الإشعار.';
        } catch (Throwable $exception) {
            $message = '❌ حدث خطأ أثناء الحذف.';
            $messageType = 'error';
        }
    }
}

$editNotification = null;
if (isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
    $editStmt = $pdo->prepare('SELECT * FROM swimmer_notifications WHERE id = ? LIMIT 1');
    $editStmt->execute([(int) $_GET['edit']]);
    $editNotification = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$branchOptions = swimmerNotificationsOptions($pdo, 'branch');
$subscriptionOptions = swimmerNotificationsOptions($pdo, 'subscription');
$levelOptions = swimmerNotificationsOptions($pdo, 'level');
$notificationsStmt = $pdo->query('SELECT * FROM swimmer_notifications ORDER BY created_at DESC, id DESC');
$notifications = $notificationsStmt ? ($notificationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اشعارات السباح</title>
    <link rel="stylesheet" href="assets/css/swimmer-admin.css">
</head>
<body class="light-mode" data-theme-key="swimmer-admin-theme">
<div class="admin-page-shell">
    <header class="admin-page-header">
        <div>
            <span class="page-badge">🔔 اشعارات السباح</span>
            <h1>اشعارات السباح</h1>
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
            <h2><?php echo $editNotification === null ? 'إضافة إشعار' : 'تعديل إشعار'; ?></h2>
            <form method="POST" class="stack-form" autocomplete="off">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="notification_id" value="<?php echo (int) ($editNotification['id'] ?? 0); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(swimmerNotificationsToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label for="notification_message">الإشعار</label>
                    <textarea name="notification_message" id="notification_message" rows="5" required><?php echo htmlspecialchars((string) ($editNotification['notification_message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="target_branch">الفرع</label>
                    <select name="target_branch" id="target_branch">
                        <option value="">الكل</option>
                        <?php foreach ($branchOptions as $branchOption): ?>
                            <option value="<?php echo htmlspecialchars((string) $branchOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (string) ($editNotification['target_branch'] ?? '') === (string) $branchOption ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $branchOption, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group subscription-select-group">
                    <label for="target_subscription">المجموعة</label>
                    <select name="target_subscription" id="target_subscription">
                        <option value="">الكل</option>
                        <?php foreach ($subscriptionOptions as $subscriptionOption): ?>
                            <option value="<?php echo htmlspecialchars((string) $subscriptionOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (string) ($editNotification['target_subscription'] ?? '') === (string) $subscriptionOption ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $subscriptionOption, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="target_level">المستوى</label>
                    <select name="target_level" id="target_level">
                        <option value="">الكل</option>
                        <?php foreach ($levelOptions as $levelOption): ?>
                            <option value="<?php echo htmlspecialchars((string) $levelOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (string) ($editNotification['target_level'] ?? '') === (string) $levelOption ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $levelOption, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="primary-btn"><?php echo $editNotification === null ? 'حفظ' : 'تحديث'; ?></button>
                    <a href="swimmer_notifications.php" class="primary-btn secondary-btn">مسح</a>
                </div>
            </form>
        </div>

        <div class="panel-card list-panel">
            <h2>الإشعارات</h2>
            <div class="cards-grid notification-grid">
                <?php foreach ($notifications as $notification): ?>
                    <article class="item-card notification-card">
                        <div class="item-card-body">
                            <strong class="notification-audience"><?php echo htmlspecialchars(swimmerNotificationAudience($notification), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <p class="notification-message"><?php echo nl2br(htmlspecialchars((string) ($notification['notification_message'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
                            <span class="notification-date"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string) ($notification['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="card-actions">
                            <a href="swimmer_notifications.php?edit=<?php echo (int) ($notification['id'] ?? 0); ?>" class="primary-btn secondary-btn">تعديل</a>
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="notification_id" value="<?php echo (int) ($notification['id'] ?? 0); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(swimmerNotificationsToken(), ENT_QUOTES, 'UTF-8'); ?>">
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
