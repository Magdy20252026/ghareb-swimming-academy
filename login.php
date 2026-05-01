<?php
session_start();
require_once "config.php";
require_once "app_helpers.php";

if (isset($_SESSION["user"])) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
$siteSettings = getSiteSettings($pdo);
$academyName = $siteSettings["academy_name"];
$academyLogoPath = $siteSettings["academy_logo_path"];
$academyLogoInitial = getAcademyLogoInitial($academyName);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? '');
    $password = trim($_POST["password"] ?? '');

    if ($username !== '' && $password !== '') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $dbPassword = $user["password"];
            $loginSuccess = false;

            // الحالة الصحيحة: كلمة المرور مخزنة هاش
            if (password_verify($password, $dbPassword)) {
                $loginSuccess = true;

                // إعادة هاش إذا احتاجت الخوارزمية تحديث
                if (password_needs_rehash($dbPassword, PASSWORD_DEFAULT)) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $updateStmt->execute([$newHash, $user["id"]]);
                }
            }
            // دعم مؤقت للمستخدمين القدامى إذا كانت كلمة المرور مخزنة نص عادي
            elseif ($password === $dbPassword) {
                $loginSuccess = true;

                // ترحيل تلقائي من النص العادي إلى هاش
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $updateStmt->execute([$newHash, $user["id"]]);
            }

            if ($loginSuccess) {
                $permissionSettings = parsePermissionSettings($user["permissions"] ?? []);

                $_SESSION["user"] = $user["username"];
                $_SESSION["role"] = $user["role"];
                $_SESSION["user_id"] = $user["id"];
                $_SESSION["permissions"] = $permissionSettings["menu_permissions"];
                $_SESSION["can_view_dashboard_statistics"] = $permissionSettings["dashboard_statistics"];
                $_SESSION["dashboard_statistics_permissions"] = $permissionSettings["dashboard_statistic_permissions"];

                header("Location: dashboard.php");
                exit;
            } else {
                $error = "اسم المستخدم أو كلمة المرور غير صحيحة";
            }
        } else {
            $error = "اسم المستخدم أو كلمة المرور غير صحيحة";
        }
    } else {
        $error = "يرجى إدخال اسم المستخدم وكلمة المرور";
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - <?php echo academyHtmlspecialchars($academyName); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="light-mode">

<div class="login-page">
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-top">
                <div class="theme-switch-box">
                    <span>☀️</span>
                    <label class="switch">
                        <input type="checkbox" id="themeToggle">
                        <span class="slider"></span>
                    </label>
                    <span>🌙</span>
                </div>
            </div>

            <div class="academy-header">
                <div class="academy-logo">
                    <?php if ($academyLogoPath !== null): ?>
                        <img src="<?php echo academyHtmlspecialchars($academyLogoPath); ?>" alt="شعار الأكاديمية">
                    <?php else: ?>
                        <span><?php echo academyHtmlspecialchars($academyLogoInitial); ?></span>
                    <?php endif; ?>
                </div>
                <h1><?php echo academyHtmlspecialchars($academyName); ?></h1>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert error-alert"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST" class="login-form" autocomplete="off">
                <div class="form-group">
                    <label for="username">👤 اسم المستخدم</label>
                    <input type="text" id="username" name="username" placeholder="أدخل اسم المستخدم" required>
                </div>

                <div class="form-group">
                    <label for="password">🔒 كلمة المرور</label>
                    <input type="password" id="password" name="password" placeholder="أدخل كلمة المرور" required>
                </div>

                <button type="submit" class="login-btn">🚀 تسجيل الدخول</button>
            </form>
        </div>
    </div>
</div>

<script src="assets/js/script.js"></script>
</body>
</html>
