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

$username = $currentUser["username"];
$userRole = $currentUser["role"];
$menuItems = getVisibleMenuItems($currentUser);
$siteSettings = getSiteSettings($pdo);
$academyName = $siteSettings["academy_name"];
$academyLogoPath = $siteSettings["academy_logo_path"];
$academyLogoInitial = getAcademyLogoInitial($academyName);
$accessDenied = isset($_GET["access"]) && $_GET["access"] === "denied";
$activeMenuKey = "dashboard";
$activeDashboardView = trim((string) ($_GET["view"] ?? ""));

function buildDashboardPageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === "") {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return "dashboard.php" . ($queryString !== "" ? "?" . $queryString : "");
}

function dashboardParseAttendanceSnapshot($snapshot): array
{
    if (!is_string($snapshot) || trim($snapshot) === "") {
        return [];
    }

    $decodedSnapshot = json_decode($snapshot, true);
    return is_array($decodedSnapshot) ? $decodedSnapshot : [];
}

function dashboardFormatDate(?string $value): string
{
    if (!is_string($value) || trim($value) === "" || strtotime($value) === false) {
        return "—";
    }

    return date("Y-m-d", strtotime($value));
}

function dashboardFormatDateTime(?string $value): string
{
    if (!is_string($value) || trim($value) === "" || strtotime($value) === false) {
        return "—";
    }

    return date("Y-m-d H:i", strtotime($value));
}

function dashboardCalculateAge(?string $birthDate): string
{
    if (!is_string($birthDate) || trim($birthDate) === "" || strtotime($birthDate) === false) {
        return "—";
    }

    try {
        $birth = new DateTimeImmutable($birthDate);
        $today = new DateTimeImmutable(date("Y-m-d"));
    } catch (Throwable $exception) {
        return "—";
    }

    return (string) $birth->diff($today)->y;
}

function fetchDashboardTodayAbsentSwimmers(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT
            sar.id AS record_id,
            sar.player_id,
            sar.note,
            sar.player_snapshot,
            sar.marked_at,
            sas.id AS session_id,
            sas.attendance_date,
            sas.opened_at,
            s.subscription_name AS session_subscription_name,
            s.subscription_branch AS session_subscription_branch,
            s.subscription_category AS session_subscription_category,
            s.training_schedule AS session_training_schedule,
            c.full_name AS session_coach_name,
            ap.barcode AS live_barcode,
            ap.player_name AS live_player_name,
            ap.phone AS live_phone,
            ap.guardian_phone AS live_guardian_phone,
            ap.birth_date AS live_birth_date,
            ap.available_exercises_count AS live_available_exercises_count
         FROM swimmer_attendance_records sar
         INNER JOIN swimmer_attendance_sessions sas ON sas.id = sar.session_id
         INNER JOIN subscriptions s ON s.id = sas.subscription_id
         LEFT JOIN coaches c ON c.id = s.coach_id
         LEFT JOIN academy_players ap ON ap.id = sar.player_id
         WHERE sas.attendance_date = CURDATE()
           AND sas.status = 'open'
           AND sar.attendance_status = 'absent'
         ORDER BY s.subscription_name ASC, ap.player_name ASC, sar.id DESC"
    );

    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $records = [];

    foreach ($rows as $row) {
        $snapshot = dashboardParseAttendanceSnapshot($row["player_snapshot"] ?? null);
        $guardianPhone = (string) (($row["live_guardian_phone"] ?? null) ?: ($snapshot["guardian_phone"] ?? ""));

        $records[] = [
            "record_id" => (int) ($row["record_id"] ?? 0),
            "session_id" => (int) ($row["session_id"] ?? 0),
            "attendance_date" => (string) ($row["attendance_date"] ?? ""),
            "opened_at" => (string) ($row["opened_at"] ?? ""),
            "barcode" => (string) (($row["live_barcode"] ?? null) ?: ($snapshot["barcode"] ?? "—")),
            "player_name" => (string) (($row["live_player_name"] ?? null) ?: ($snapshot["player_name"] ?? "—")),
            "phone" => (string) (($row["live_phone"] ?? null) ?: ($snapshot["phone"] ?? "")),
            "guardian_phone" => $guardianPhone,
            "birth_date" => (string) (($row["live_birth_date"] ?? null) ?: ($snapshot["birth_date"] ?? "")),
            "player_age" => dashboardCalculateAge((string) (($row["live_birth_date"] ?? null) ?: ($snapshot["birth_date"] ?? ""))),
            "subscription_name" => (string) (($row["session_subscription_name"] ?? null) ?: ($snapshot["subscription_name"] ?? "—")),
            "subscription_branch" => (string) (($row["session_subscription_branch"] ?? null) ?: ($snapshot["subscription_branch"] ?? "—")),
            "subscription_category" => (string) (($row["session_subscription_category"] ?? null) ?: ($snapshot["subscription_category"] ?? "—")),
            "training_schedule" => formatAcademyTrainingSchedule((string) (($row["session_training_schedule"] ?? "") ?: ($snapshot["subscription_training_schedule"] ?? ""))),
            "coach_name" => (string) (($row["session_coach_name"] ?? null) ?: ($snapshot["subscription_coach_name"] ?? "—")),
            "available_exercises_count" => (int) (($row["live_available_exercises_count"] ?? null) !== null ? $row["live_available_exercises_count"] : ($snapshot["available_exercises_count"] ?? 0)),
            "note" => (string) ($row["note"] ?? ""),
            "tel_phone" => sanitizeAcademyPhoneNumber($guardianPhone),
            "whatsapp_phone" => formatAcademyWhatsappPhone($guardianPhone),
        ];
    }

    return $records;
}

/* إحصائيات السباحين الفعلية من قاعدة البيانات */
try {
    $stmtPlayersStatistics = $pdo->query(
        "SELECT
            COUNT(*) AS total_players,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS new_players_today,
            SUM(CASE WHEN YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1) THEN 1 ELSE 0 END) AS new_players_week,
            SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) THEN 1 ELSE 0 END) AS new_players_month
         FROM academy_players
         WHERE academy_id = 0"
    );
    $playersStatistics = $stmtPlayersStatistics ? ($stmtPlayersStatistics->fetch(PDO::FETCH_ASSOC) ?: []) : [];
} catch (PDOException $e) {
    $playersStatistics = [];
}

$dashboardStatisticValues = [
    "total_users" => (int) ($playersStatistics["total_players"] ?? 0),
    "new_players_today" => (int) ($playersStatistics["new_players_today"] ?? 0),
    "new_players_week" => (int) ($playersStatistics["new_players_week"] ?? 0),
    "new_players_month" => (int) ($playersStatistics["new_players_month"] ?? 0),
];
$visibleDashboardStatistics = array_values(array_filter(
    getDashboardStatisticItems(),
    static fn(array $item): bool => canUserViewDashboardStatistic($currentUser, $item["key"])
));
$canViewStatistics = !empty($visibleDashboardStatistics);
$enabledCapabilityCount = countEnabledCapabilities(
    $currentUser["permissions"],
    $canViewStatistics,
    $currentUser["dashboard_statistics_permissions"] ?? []
);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - <?php echo academyHtmlspecialchars($academyName); ?></title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body class="light-mode">

<div class="mobile-overlay" id="mobileOverlay"></div>

<div class="dashboard-layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-inner">
            <div class="sidebar-top">
                <button class="sidebar-toggle" id="sidebarToggle" title="طي / فرد القائمة">☰</button>
            </div>

            <div class="academy-box">
                <div class="academy-logo">
                    <?php if ($academyLogoPath !== null): ?>
                        <img src="<?php echo academyHtmlspecialchars($academyLogoPath); ?>" alt="شعار الأكاديمية">
                    <?php else: ?>
                        <span><?php echo academyHtmlspecialchars($academyLogoInitial); ?></span>
                    <?php endif; ?>
                </div>
                <h2 class="academy-name"><?php echo academyHtmlspecialchars($academyName); ?></h2>

                <div class="user-info">
                    <div class="user-item">
                        <span class="label">اسم المستخدم</span>
                        <strong><?php echo academyHtmlspecialchars($username); ?></strong>
                    </div>
                    <div class="user-item">
                        <span class="label">الصلاحية</span>
                        <strong><?php echo academyHtmlspecialchars($userRole); ?></strong>
                    </div>
                </div>
            </div>

            <nav class="sidebar-menu">
                <?php foreach ($menuItems as $item): ?>
                    <?php
                        $buttonClasses = ["menu-btn"];
                        if ($item["key"] === "logout") {
                            $buttonClasses[] = "logout-btn";
                        }
                        if ($item["key"] === $activeMenuKey) {
                            $buttonClasses[] = "active";
                        }
                        $buttonStyle = academyHtmlspecialchars(
                            "--menu-accent-start: " . $item["accent_start"] . "; --menu-accent-end: " . $item["accent_end"] . ";",
                            ENT_QUOTES,
                            "UTF-8"
                        );
                    ?>
                        <?php if ($item["type"] === "link"): ?>
                        <a href="<?php echo $item["href"]; ?>" class="<?php echo implode(" ", $buttonClasses); ?>" style="<?php echo $buttonStyle; ?>">
                            <span class="menu-icon"><?php echo $item["icon"]; ?></span>
                            <span class="menu-content">
                                <span class="menu-text"><?php echo $item["title"]; ?></span>
                            </span>
                        </a>
                    <?php else: ?>
                        <button type="button" class="<?php echo implode(" ", $buttonClasses); ?>" style="<?php echo $buttonStyle; ?>">
                            <span class="menu-icon"><?php echo $item["icon"]; ?></span>
                            <span class="menu-content">
                                <span class="menu-text"><?php echo $item["title"]; ?></span>
                            </span>
                        </button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-right">
                <button class="mobile-menu-btn" id="mobileMenuBtn" title="فتح القائمة">☰</button>
                <div class="page-title">
                    <h1>لوحة التحكم</h1>
                </div>
            </div>

            <div class="topbar-left">
                <div class="theme-switch-box">
                    <span>☀️</span>
                    <label class="switch">
                        <input type="checkbox" id="themeToggle">
                        <span class="slider"></span>
                    </label>
                    <span>🌙</span>
                </div>
            </div>
        </header>

        <?php if ($accessDenied): ?>
            <div class="page-alert error-alert">🚫 لا تملك الصلاحية الكافية لفتح هذه الصفحة.</div>
        <?php endif; ?>

        <section class="welcome-panel">
            <div>
                <span class="welcome-badge"><?php echo $userRole === "مدير" ? "👑 مدير النظام" : "🧭 مشرف مخصص"; ?></span>
                <h2>مرحبًا <?php echo academyHtmlspecialchars($username); ?> 👋</h2>
            </div>
            <div class="welcome-summary">
                <span class="summary-chip">🎯 <?php echo $userRole === "مدير" ? "وصول كامل" : permissionCountLabel($enabledCapabilityCount); ?></span>
            </div>
        </section>

        <?php if ($canViewStatistics): ?>
            <section class="stats-grid">
                <?php foreach ($visibleDashboardStatistics as $item): ?>
                    <?php
                    $statisticValue = (int) ($dashboardStatisticValues[$item["key"]] ?? 0);
                    $detailUrl = null;
                    ?>
                    <?php if ($detailUrl !== null): ?>
                        <a href="<?php echo academyHtmlspecialchars($detailUrl, ENT_QUOTES, "UTF-8"); ?>" class="stat-card stat-card-link <?php echo academyHtmlspecialchars($item["card_class"]); ?>">
                            <div class="stat-icon"><?php echo $item["icon"]; ?></div>
                            <div class="stat-details">
                                <h3><?php echo $item["title"]; ?></h3>
                                <p><?php echo $statisticValue; ?></p>
                            </div>
                        </a>
                    <?php else: ?>
                        <div class="stat-card <?php echo academyHtmlspecialchars($item["card_class"]); ?>">
                            <div class="stat-icon"><?php echo $item["icon"]; ?></div>
                            <div class="stat-details">
                                <h3><?php echo $item["title"]; ?></h3>
                                <p><?php echo $statisticValue; ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

    </main>
</div>

<script src="assets/js/dashboard.js"></script>
</body>
</html>
