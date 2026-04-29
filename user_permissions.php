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

if (($currentUser["role"] ?? "") !== "مدير") {
    header("Location: dashboard.php?access=denied");
    exit;
}

$message = "";
$messageType = "";
$menuPermissionItems = getEditableMenuPermissionItems();
$dashboardStatisticItems = getDashboardStatisticItems();
$selectedSupervisorId = isset($_GET["supervisor"]) ? (int) $_GET["supervisor"] : 0;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $selectedSupervisorId = (int) ($_POST["supervisor_id"] ?? 0);

    if ($action === "save") {
        if ($selectedSupervisorId <= 0) {
            $message = "❌ اختر مشرفًا أولًا لتحديث الصلاحيات.";
            $messageType = "error";
        } else {
            $selectedPermissions = normalizePermissions($_POST["permissions"] ?? []);
            $selectedDashboardStatistics = normalizeDashboardStatisticPermissions($_POST["dashboard_statistics"] ?? []);

            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'مشرف' LIMIT 1");
            $checkStmt->execute([$selectedSupervisorId]);
            $supervisorExists = $checkStmt->fetchColumn();

            if (!$supervisorExists) {
                $message = "❌ المشرف المحدد غير موجود.";
                $messageType = "error";
                $selectedSupervisorId = 0;
            } else {
                $saveStmt = $pdo->prepare("UPDATE users SET permissions = ? WHERE id = ? AND role = 'مشرف'");
                $saveStmt->execute([
                    encodePermissions($selectedPermissions, !empty($selectedDashboardStatistics), $selectedDashboardStatistics),
                    $selectedSupervisorId
                ]);

                $message = "✅ تم حفظ صلاحيات المشرف بنجاح.";
                $messageType = "success";
            }
        }
    }
}

$supervisorsStmt = $pdo->query("SELECT id, username, permissions, created_at FROM users WHERE role = 'مشرف' ORDER BY username ASC");
$supervisors = $supervisorsStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedSupervisor = null;
foreach ($supervisors as &$supervisor) {
    $permissionSettings = parsePermissionSettings($supervisor["permissions"] ?? []);
    $supervisor["permissions"] = $permissionSettings["menu_permissions"];
    $supervisor["can_view_dashboard_statistics"] = $permissionSettings["dashboard_statistics"];
    $supervisor["dashboard_statistics_permissions"] = $permissionSettings["dashboard_statistic_permissions"];
    if ($selectedSupervisorId > 0 && (int) $supervisor["id"] === $selectedSupervisorId) {
        $selectedSupervisor = $supervisor;
    }
}
unset($supervisor);

$selectedPermissions = $selectedSupervisor["permissions"] ?? [];
$selectedCanViewDashboardStatistics = !empty($selectedSupervisor["can_view_dashboard_statistics"]);
$selectedDashboardStatistics = $selectedSupervisor["dashboard_statistics_permissions"] ?? [];
$enabledCount = countEnabledCapabilities(
    $selectedPermissions,
    $selectedCanViewDashboardStatistics,
    $selectedDashboardStatistics
);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>صلاحيات المستخدمين</title>
    <link rel="stylesheet" href="assets/css/user-permissions.css">
</head>
<body class="light-mode">

<div class="permissions-page">
    <div class="page-header">
        <div class="header-text">
            <span class="eyebrow">🛡️ لوحة تحكم الصلاحيات</span>
            <h1>صلاحيات المستخدمين</h1>
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

            <a href="dashboard.php" class="back-btn back-btn-accent">⬅️ الرجوع للوحة التحكم</a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="message-box <?php echo $messageType; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <section class="hero-grid">
        <div class="hero-card hero-main">
            <div class="hero-icon">🎯</div>
            <div>
                <h2>تخصيص دقيق لكل مشرف</h2>
            </div>
        </div>

        <div class="hero-card">
            <span>👥 عدد المشرفين</span>
            <strong><?php echo count($supervisors); ?></strong>
        </div>

        <div class="hero-card">
            <span>✅ العناصر المفعلة</span>
            <strong id="enabledCount"><?php echo $enabledCount; ?></strong>
        </div>
    </section>

    <section class="selector-card">
        <div class="card-head">
            <h2>🧭 اختيار المشرف</h2>
        </div>

        <form method="GET" class="selector-form">
            <div class="select-wrapper">
                <label for="supervisorSelect">👤 أسماء المشرفين</label>
                <select name="supervisor" id="supervisorSelect" aria-label="اختر اسم المشرف" required>
                    <option value="">اختر اسم المشرف</option>
                    <?php foreach ($supervisors as $supervisor): ?>
                        <option value="<?php echo $supervisor["id"]; ?>" <?php echo $selectedSupervisorId === (int) $supervisor["id"] ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($supervisor["username"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="select-btn select-btn-accent">✨ عرض الصلاحيات</button>
        </form>

        <?php if ($selectedSupervisor): ?>
            <div class="supervisor-preview">
                <div class="preview-avatar">👨‍💼</div>
                <div>
                    <strong><?php echo htmlspecialchars($selectedSupervisor["username"]); ?></strong>
                </div>
                <span class="preview-chip"><?php echo permissionCountLabel($enabledCount); ?></span>
            </div>
        <?php endif; ?>
    </section>

    <section class="permissions-card">
        <div class="card-head">
            <h2>🎛️ العناصر والصلاحيات الظاهرة</h2>
        </div>

        <?php if ($selectedSupervisor): ?>
            <form method="POST" id="permissionsForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="supervisor_id" value="<?php echo $selectedSupervisor["id"]; ?>">

                <div class="permission-section">
                    <div class="section-intro">
                        <div>
                            <h3>أزرار القائمة الجانبية</h3>
                        </div>
                        <span class="section-chip"><?php echo count($menuPermissionItems); ?> زر</span>
                    </div>

                    <div class="permissions-grid">
                        <?php foreach ($menuPermissionItems as $item): ?>
                            <?php $isChecked = in_array($item["key"], $selectedPermissions, true); ?>
                            <label class="permission-option <?php echo $isChecked ? "checked" : ""; ?>">
                                <span class="permission-main">
                                    <span class="permission-icon"><?php echo $item["icon"]; ?></span>
                                    <span class="permission-content">
                                        <strong><?php echo $item["title"]; ?></strong>
                                    </span>
                                </span>
                                <span class="permission-actions">
                                    <span class="permission-state"><?php echo $isChecked ? "مفعّل" : "غير مفعّل"; ?></span>
                                    <span class="permission-toggle">
                                        <input
                                            type="checkbox"
                                            name="permissions[]"
                                            value="<?php echo $item["key"]; ?>"
                                            <?php echo $isChecked ? "checked" : ""; ?>
                                        >
                                        <span class="permission-toggle-slider"></span>
                                    </span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="permission-section">
                    <div class="section-intro">
                        <div>
                            <h3>إحصائيات لوحة التحكم</h3>
                        </div>
                        <span class="section-chip"><?php echo count($dashboardStatisticItems); ?> بطاقة</span>
                    </div>

                    <div class="permissions-grid dashboard-grid">
                        <?php foreach ($dashboardStatisticItems as $item): ?>
                            <?php $isChecked = in_array($item["key"], $selectedDashboardStatistics, true); ?>
                            <label class="permission-option <?php echo $isChecked ? "checked" : ""; ?>">
                                <span class="permission-main">
                                    <span class="permission-icon"><?php echo $item["icon"]; ?></span>
                                    <span class="permission-content">
                                        <strong><?php echo $item["title"]; ?></strong>
                                    </span>
                                </span>
                                <span class="permission-actions">
                                    <span class="permission-state"><?php echo $isChecked ? "مفعّل" : "غير مفعّل"; ?></span>
                                    <span class="permission-toggle">
                                        <input
                                            type="checkbox"
                                            name="dashboard_statistics[]"
                                            value="<?php echo $item["key"]; ?>"
                                            <?php echo $isChecked ? "checked" : ""; ?>
                                        >
                                        <span class="permission-toggle-slider"></span>
                                    </span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="save-btn save-btn-accent">💾 حفظ الصلاحيات</button>
                    <button type="button" class="secondary-btn secondary-btn-all" id="selectAllBtn">🌈 تحديد الكل</button>
                    <button type="button" class="secondary-btn secondary-btn-clear" id="clearAllBtn">🧹 إلغاء الكل</button>
                </div>
            </form>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">🫧</div>
                <h3>اختر مشرفًا من الأعلى</h3>
            </div>
        <?php endif; ?>
    </section>
</div>

<script src="assets/js/user-permissions.js"></script>
</body>
</html>
