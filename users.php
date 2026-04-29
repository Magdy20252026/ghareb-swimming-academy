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

if (!userCanAccess($currentUser, "users")) {
    header("Location: dashboard.php?access=denied");
    exit;
}

$message = "";
$messageType = "";
$editUser = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "save") {
        $id = trim($_POST["id"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $password = trim($_POST["password"] ?? "");
        $role = trim($_POST["role"] ?? "");

        if ($username !== "" && $role !== "" && ($id !== "" || $password !== "")) {
            try {
                if ($id === "") {
                    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                    $checkStmt->execute([$username]);

                    if ($checkStmt->fetchColumn() > 0) {
                        $message = "❌ اسم المستخدم موجود بالفعل";
                        $messageType = "error";
                    } else {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $permissions = $role === "مشرف" ? encodePermissions([]) : null;

                        $stmt = $pdo->prepare("INSERT INTO users (username, password, role, permissions) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$username, $hashedPassword, $role, $permissions]);

                        $message = "✅ تم إضافة المستخدم بنجاح";
                        $messageType = "success";
                    }
                } else {
                    $existingUserStmt = $pdo->prepare("
                        SELECT
                            permissions,
                            (SELECT COUNT(*) FROM users WHERE username = ? AND id != ?) AS duplicate_count
                        FROM users
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $existingUserStmt->execute([$username, $id, $id]);
                    $existingUser = $existingUserStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$existingUser) {
                        $message = "❌ المستخدم المطلوب غير موجود";
                        $messageType = "error";
                    } elseif ((int) ($existingUser["duplicate_count"] ?? 0) > 0) {
                        $message = "❌ اسم المستخدم مستخدم لمستخدم آخر";
                        $messageType = "error";
                    } else {
                        $permissions = $role === "مشرف"
                            ? encodePermissions(
                                $existingUser["permissions"] ?? [],
                                canViewStoredDashboardStatistics($existingUser["permissions"] ?? []),
                                getStoredDashboardStatisticPermissions($existingUser["permissions"] ?? [])
                            )
                            : null;

                        if ($password !== "") {
                            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                            $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, role = ?, permissions = ? WHERE id = ?");
                            $stmt->execute([$username, $hashedPassword, $role, $permissions, $id]);
                        } else {
                            $stmt = $pdo->prepare("UPDATE users SET username = ?, role = ?, permissions = ? WHERE id = ?");
                            $stmt->execute([$username, $role, $permissions, $id]);
                        }

                        $message = "✏️ تم تعديل المستخدم بنجاح";
                        $messageType = "success";
                    }
                }
            } catch (PDOException $e) {
                $message = "❌ حدث خطأ أثناء حفظ البيانات";
                $messageType = "error";
            }
        } else {
            $message = "❌ يرجى ملء الحقول المطلوبة";
            $messageType = "error";
        }
    }

    if ($action === "delete") {
        $id = $_POST["id"] ?? "";

        if (!empty($id)) {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);

                $message = "🗑️ تم حذف المستخدم بنجاح";
                $messageType = "success";
            } catch (PDOException $e) {
                $message = "❌ حدث خطأ أثناء حذف المستخدم";
                $messageType = "error";
            }
        }
    }
}

if (isset($_GET["edit"])) {
    $editId = $_GET["edit"];
    $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

$stmt = $pdo->query("SELECT id, username, role, created_at FROM users ORDER BY id DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المستخدمين</title>
    <link rel="stylesheet" href="assets/css/users.css">
</head>
<body class="light-mode">

<div class="users-page">
    <div class="page-header">
        <div class="header-text">
            <h1>👥 إدارة المستخدمين</h1>
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

    <?php if (!empty($message)): ?>
        <div class="message-box <?php echo $messageType; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <div class="card-head">
            <h2><?php echo $editUser ? "✏️ تعديل مستخدم" : "➕ إضافة مستخدم جديد"; ?></h2>
        </div>

        <form method="POST" id="userForm" class="user-form" autocomplete="off">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="id" value="<?php echo $editUser["id"] ?? ''; ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="username">👤 اسم المستخدم</label>
                    <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($editUser["username"] ?? ''); ?>" placeholder="أدخل اسم المستخدم" required>
                </div>

                <div class="form-group">
                    <label for="password">🔒 كلمة المرور</label>
                    <input type="password" name="password" id="password" placeholder="<?php echo $editUser ? 'أدخل كلمة مرور جديدة' : 'أدخل كلمة المرور'; ?>" <?php echo $editUser ? '' : 'required'; ?>>
                </div>

                <div class="form-group">
                    <label for="role">🛡️ الصلاحية</label>
                    <select name="role" id="role" required>
                        <option value="">اختر الصلاحية</option>
                        <option value="مدير" <?php echo (($editUser["role"] ?? '') === "مدير") ? "selected" : ""; ?>>مدير</option>
                        <option value="مشرف" <?php echo (($editUser["role"] ?? '') === "مشرف") ? "selected" : ""; ?>>مشرف</option>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="save-btn">💾 حفظ</button>
                <button type="button" class="clear-btn" id="clearBtn">🧹 تصفية الحقول</button>
            </div>
        </form>
    </div>

    <div class="table-card">
        <div class="card-head">
            <h2>📋 جدول المستخدمين</h2>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم المستخدم</th>
                        <th>الصلاحية</th>
                        <th>تاريخ الإضافة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($users)): ?>
                        <?php foreach ($users as $index => $user): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <div class="user-cell">
                                        <span class="user-avatar">👤</span>
                                        <span><?php echo htmlspecialchars($user["username"]); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="role-badge <?php echo $user["role"] === "مدير" ? "admin-role" : "supervisor-role"; ?>">
                                        <?php echo htmlspecialchars($user["role"]); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($user["created_at"]); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="users.php?edit=<?php echo $user["id"]; ?>" class="edit-btn">✏️ تعديل</a>

                                        <form method="POST" class="inline-form" onsubmit="return confirm('هل أنت متأكد من حذف المستخدم؟');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $user["id"]; ?>">
                                            <button type="submit" class="delete-btn">🗑️ حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="empty-row">لا يوجد مستخدمون مسجلون حاليًا داخل قاعدة البيانات</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="assets/js/users.js"></script>
</body>
</html>
