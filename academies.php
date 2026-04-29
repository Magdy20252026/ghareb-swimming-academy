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

if (!userCanAccess($currentUser, "academies")) {
    header("Location: dashboard.php?access=denied");
    exit;
}

const ACADEMIES_MYSQL_DUPLICATE_KEY_ERROR = 1062;
const ACADEMIES_PAGE_FILE = 'academies.php';
const ACADEMIES_ALLOWED_PLAYER_TABLES = ['academy_players', 'academies_players'];
const ACADEMIES_ALLOWED_PLAYER_NAME_COLUMNS = ['player_name', 'full_name', 'name'];
const ACADEMIES_ALLOWED_PLAYER_ACADEMY_ID_COLUMNS = ['academy_id', 'academies_id'];
const ACADEMIES_ALLOWED_PLAYER_ACADEMY_NAME_COLUMNS = ['academy_name', 'academy'];
const ACADEMIES_DEFAULT_TRAINING_DAYS = 1;
const ACADEMIES_DEFAULT_TRAINING_SESSIONS = 1;

function normalizeAcademiesArabicNumbers(string $value): string
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

function sanitizeAcademyName(string $value): string
{
    $value = trim($value);
    $sanitizedValue = preg_replace('/\s+/u', ' ', $value);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function normalizeAcademiesDecimal(string $value): string
{
    $value = trim(normalizeAcademiesArabicNumbers($value));
    return str_replace(',', '.', $value);
}

function isValidAcademiesDecimal(string $value): bool
{
    return $value !== ''
        && preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1
        && (float) $value > 0;
}

function formatAcademiesAmount($value): string
{
    return number_format((float) $value, 2);
}

function formatAcademiesDecimalForStorage($value): string
{
    return number_format((float) $value, 2, '.', '');
}

function buildAcademiesPageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return ACADEMIES_PAGE_FILE . ($queryString !== '' ? '?' . $queryString : '');
}

function generateAcademiesSecurityToken(): string
{
    try {
        return bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        error_log(sprintf('تعذر إنشاء رمز أمان لإدارة الأكاديميات [%s:%s]', get_class($exception), (string) $exception->getCode()));
        if (function_exists('openssl_random_pseudo_bytes')) {
            $isStrong = false;
            $randomBytes = openssl_random_pseudo_bytes(32, $isStrong);
            if ($randomBytes !== false && $isStrong) {
                return bin2hex($randomBytes);
            }
        }
    }

    throw new RuntimeException('تعذر إنشاء رمز أمان لإدارة الأكاديميات');
}

function getAcademiesCsrfToken(): string
{
    if (
        !isset($_SESSION['academies_csrf_token'])
        || !is_string($_SESSION['academies_csrf_token'])
        || $_SESSION['academies_csrf_token'] === ''
    ) {
        try {
            $_SESSION['academies_csrf_token'] = generateAcademiesSecurityToken();
        } catch (Throwable $exception) {
            error_log(sprintf('تعذر إنشاء رمز التحقق الخاص بإدارة الأكاديميات [%s:%s]', get_class($exception), (string) $exception->getCode()));
            http_response_code(500);
            exit('❌ تعذر تهيئة أمان الصفحة، يرجى إعادة المحاولة لاحقًا');
        }
    }

    return $_SESSION['academies_csrf_token'];
}

function isValidAcademiesCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getAcademiesCsrfToken(), $submittedToken);
}

function quoteAcademiesIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function findFirstAllowedAcademiesColumn(array $availableColumns, array $allowedColumns): ?string
{
    foreach ($allowedColumns as $candidate) {
        if (in_array($candidate, $availableColumns, true)) {
            return $candidate;
        }
    }

    return null;
}

function normalizeAcademyPlayersLookup(array $lookup): array
{
    foreach ($lookup as $academyKey => $playerNames) {
        $normalizedNames = [];

        foreach ($playerNames as $playerName) {
            $normalizedName = sanitizeAcademyName((string) $playerName);
            if ($normalizedName !== '') {
                $normalizedNames[$normalizedName] = $normalizedName;
            }
        }

        $lookup[$academyKey] = array_values($normalizedNames);
    }

    return $lookup;
}

function getAcademiesMysqlDriverErrorCode(PDOException $exception): int
{
    $errorInfo = $exception->errorInfo;

    if (is_array($errorInfo) && isset($errorInfo[1]) && is_numeric($errorInfo[1])) {
        return (int) $errorInfo[1];
    }

    return is_numeric($exception->getCode()) ? (int) $exception->getCode() : 0;
}

function detectAcademyPlayersSource(PDO $pdo): ?array
{
    foreach (ACADEMIES_ALLOWED_PLAYER_TABLES as $tableName) {
        $tableExistsStmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $tableExistsStmt->execute([$tableName]);

        if ($tableExistsStmt->rowCount() === 0) {
            continue;
        }

        $columnsStmt = $pdo->query('SHOW COLUMNS FROM ' . quoteAcademiesIdentifier($tableName));
        $columns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columnNames = [];

        foreach ($columns as $column) {
            if (isset($column['Field']) && is_string($column['Field'])) {
                $columnNames[] = $column['Field'];
            }
        }

        $nameColumn = findFirstAllowedAcademiesColumn($columnNames, ACADEMIES_ALLOWED_PLAYER_NAME_COLUMNS);

        if ($nameColumn === null) {
            continue;
        }

        $academyIdColumn = findFirstAllowedAcademiesColumn($columnNames, ACADEMIES_ALLOWED_PLAYER_ACADEMY_ID_COLUMNS);
        $academyNameColumn = findFirstAllowedAcademiesColumn($columnNames, ACADEMIES_ALLOWED_PLAYER_ACADEMY_NAME_COLUMNS);

        if ($academyIdColumn === null && $academyNameColumn === null) {
            continue;
        }

        $orderColumn = in_array('id', $columnNames, true) ? 'id' : $nameColumn;

        return [
            'table' => $tableName,
            'name_column' => $nameColumn,
            'academy_id_column' => $academyIdColumn,
            'academy_name_column' => $academyNameColumn,
            'order_column' => $orderColumn,
        ];
    }

    return null;
}

function fetchAcademyPlayersMap(PDO $pdo): array
{
    $source = detectAcademyPlayersSource($pdo);
    $playersByAcademyId = [];
    $playersByAcademyName = [];

    if ($source === null) {
        return [
            'by_id' => $playersByAcademyId,
            'by_name' => $playersByAcademyName,
        ];
    }

    $table = quoteAcademiesIdentifier($source['table']);
    $nameColumn = quoteAcademiesIdentifier($source['name_column']);
    $orderColumn = quoteAcademiesIdentifier($source['order_column']);

    if ($source['academy_id_column'] !== null) {
        $academyIdColumn = quoteAcademiesIdentifier($source['academy_id_column']);
        $playersStmt = $pdo->query(
            'SELECT ' . $academyIdColumn . ' AS academy_key, ' . $nameColumn . ' AS player_name '
            . 'FROM ' . $table . ' '
            . 'WHERE ' . $academyIdColumn . ' IS NOT NULL '
            . 'AND TRIM(' . $nameColumn . ') <> "" '
            . 'ORDER BY ' . $academyIdColumn . ' ASC, ' . $orderColumn . ' ASC'
        );

        foreach ($playersStmt ? $playersStmt->fetchAll(PDO::FETCH_ASSOC) : [] as $playerRow) {
            $academyKey = (int) ($playerRow['academy_key'] ?? 0);
            $playerName = trim((string) ($playerRow['player_name'] ?? ''));
            if ($academyKey <= 0 || $playerName === '') {
                continue;
            }

            $playersByAcademyId[$academyKey][] = $playerName;
        }
    }

    if ($source['academy_name_column'] !== null) {
        $academyNameColumn = quoteAcademiesIdentifier($source['academy_name_column']);
        $playersStmt = $pdo->query(
            'SELECT ' . $academyNameColumn . ' AS academy_key, ' . $nameColumn . ' AS player_name '
            . 'FROM ' . $table . ' '
            . 'WHERE TRIM(' . $academyNameColumn . ') <> "" '
            . 'AND TRIM(' . $nameColumn . ') <> "" '
            . 'ORDER BY ' . $academyNameColumn . ' ASC, ' . $orderColumn . ' ASC'
        );

        foreach ($playersStmt ? $playersStmt->fetchAll(PDO::FETCH_ASSOC) : [] as $playerRow) {
            $academyKey = sanitizeAcademyName((string) ($playerRow['academy_key'] ?? ''));
            $playerName = trim((string) ($playerRow['player_name'] ?? ''));
            if ($academyKey === '' || $playerName === '') {
                continue;
            }

            $playersByAcademyName[$academyKey][] = $playerName;
        }
    }

    return [
        'by_id' => normalizeAcademyPlayersLookup($playersByAcademyId),
        'by_name' => normalizeAcademyPlayersLookup($playersByAcademyName),
    ];
}

$message = '';
$messageType = '';
$editAcademy = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!isValidAcademiesCsrfToken($submittedToken)) {
        $message = '❌ تعذر التحقق من الطلب، يرجى إعادة المحاولة.';
        $messageType = 'error';
    } elseif ($action === 'save') {
        $id = trim($_POST['id'] ?? '');
        $academyName = sanitizeAcademyName($_POST['academy_name'] ?? '');
        $subscriptionPriceInput = normalizeAcademiesDecimal($_POST['subscription_price'] ?? '');

        if ($academyName === '') {
            $message = '❌ يرجى إدخال اسم الأكاديمية.';
            $messageType = 'error';
        } elseif (!isValidAcademiesDecimal($subscriptionPriceInput)) {
            $message = '❌ يرجى إدخال سعر اشتراك صحيح.';
            $messageType = 'error';
        } else {
            try {
                $academyPayload = [
                    $academyName,
                    ACADEMIES_DEFAULT_TRAINING_DAYS,
                    ACADEMIES_DEFAULT_TRAINING_SESSIONS,
                    formatAcademiesDecimalForStorage($subscriptionPriceInput),
                ];

                if ($id === '') {
                    $insertStmt = $pdo->prepare(
                        'INSERT INTO academies (academy_name, training_days_count, training_sessions_count, subscription_price) VALUES (?, ?, ?, ?)'
                    );
                    $insertStmt->execute($academyPayload);
                    $message = '✅ تم إضافة الأكاديمية بنجاح.';
                    $messageType = 'success';
                } else {
                    $updateStmt = $pdo->prepare(
                        'UPDATE academies SET academy_name = ?, training_days_count = ?, training_sessions_count = ?, subscription_price = ? WHERE id = ?'
                    );
                    $academyPayload[] = $id;
                    $updateStmt->execute($academyPayload);

                    if ($updateStmt->rowCount() > 0) {
                        $message = '✏️ تم تحديث بيانات الأكاديمية بنجاح.';
                        $messageType = 'success';
                    } else {
                        $existsStmt = $pdo->prepare('SELECT id FROM academies WHERE id = ? LIMIT 1');
                        $existsStmt->execute([$id]);
                        if ($existsStmt->fetchColumn()) {
                            $message = '✅ لا توجد تغييرات جديدة على بيانات الأكاديمية.';
                            $messageType = 'success';
                        } else {
                            $message = '❌ الأكاديمية المطلوبة غير موجودة.';
                            $messageType = 'error';
                        }
                    }
                }
            } catch (PDOException $exception) {
                $sqlErrorCode = getAcademiesMysqlDriverErrorCode($exception);
                if ($sqlErrorCode === ACADEMIES_MYSQL_DUPLICATE_KEY_ERROR) {
                    $message = '❌ اسم الأكاديمية مسجل بالفعل، اختر اسمًا مختلفًا.';
                } else {
                    $message = '❌ حدث خطأ أثناء حفظ بيانات الأكاديمية.';
                }
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $id = trim($_POST['id'] ?? '');

        if ($id === '') {
            $message = '❌ الأكاديمية المطلوبة غير صالحة.';
            $messageType = 'error';
        } else {
            try {
                $deleteStmt = $pdo->prepare('DELETE FROM academies WHERE id = ?');
                $deleteStmt->execute([$id]);

                if ($deleteStmt->rowCount() > 0) {
                    $message = '🗑️ تم حذف الأكاديمية بنجاح.';
                    $messageType = 'success';
                } else {
                    $message = '❌ الأكاديمية المطلوبة غير موجودة.';
                    $messageType = 'error';
                }
            } catch (PDOException $exception) {
                $message = '❌ حدث خطأ أثناء حذف الأكاديمية.';
                $messageType = 'error';
            }
        }
    }
}

if (isset($_GET['edit'])) {
    $editId = trim($_GET['edit']);
    if ($editId !== '') {
        $editStmt = $pdo->prepare('
            SELECT id, academy_name, subscription_price
            FROM academies
            WHERE id = ?
            LIMIT 1
        ');
        $editStmt->execute([$editId]);
        $editAcademy = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($editAcademy === null && $message === '') {
            $message = '❌ الأكاديمية المطلوبة غير موجودة.';
            $messageType = 'error';
        }
    }
}

$statsStmt = $pdo->query('
    SELECT
        COUNT(*) AS total_academies,
        COALESCE(AVG(subscription_price), 0) AS average_subscription_price,
        COALESCE(MAX(subscription_price), 0) AS highest_subscription_price
    FROM academies
');
$academiesStats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$academiesStmt = $pdo->query('
    SELECT id, academy_name, subscription_price, created_at, updated_at
    FROM academies
    ORDER BY updated_at DESC, id DESC
');
$academies = $academiesStmt->fetchAll(PDO::FETCH_ASSOC);
$academyPlayersMap = fetchAcademyPlayersMap($pdo);
$totalPlayers = 0;

$academiesWithoutPlayers = 0;

foreach ($academies as &$academyRow) {
    $academyId = (int) ($academyRow['id'] ?? 0);
    $academyName = sanitizeAcademyName((string) ($academyRow['academy_name'] ?? ''));
    $academyRow['player_names'] = $academyPlayersMap['by_id'][$academyId] ?? $academyPlayersMap['by_name'][$academyName] ?? [];
    $academyRow['player_count'] = count($academyRow['player_names']);
    $totalPlayers += $academyRow['player_count'];

    if ($academyRow['player_count'] === 0) {
        $academiesWithoutPlayers++;
    }
}
unset($academyRow);

$subscriptionPriceFieldValue = isset($editAcademy['subscription_price'])
    ? formatAcademiesDecimalForStorage($editAcademy['subscription_price'])
    : '';
$academiesCsrfToken = getAcademiesCsrfToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الأكاديميات</title>
    <link rel="stylesheet" href="assets/css/academies.css">
</head>
<body class="light-mode">
<div class="academies-page">
    <header class="page-header">
        <div class="header-text">
            <span class="eyebrow">🌊 إدارة الأكاديميات</span>
            <h1>إدارة الأكاديميات</h1>
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

    <?php if ($message !== ''): ?>
        <div class="message-box <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <section class="hero-grid">
        <article class="hero-card hero-card-main">
            <span class="hero-icon">🏊</span>
            <h2>قاعدة الأكاديميات</h2>
        </article>

        <article class="hero-card">
            <span>إجمالي الأكاديميات</span>
            <strong><?php echo (int) ($academiesStats['total_academies'] ?? 0); ?></strong>
        </article>

        <article class="hero-card">
            <span>إجمالي اللاعبين</span>
            <strong><?php echo $totalPlayers; ?></strong>
        </article>

        <article class="hero-card">
            <span>متوسط الاشتراك</span>
            <strong><?php echo formatAcademiesAmount($academiesStats['average_subscription_price'] ?? 0); ?> ج.م</strong>
        </article>

        <article class="hero-card">
            <span>أعلى اشتراك</span>
            <strong><?php echo formatAcademiesAmount($academiesStats['highest_subscription_price'] ?? 0); ?> ج.م</strong>
        </article>
    </section>

    <section class="content-grid">
        <div class="form-card">
            <div class="card-head">
                <h2><?php echo $editAcademy ? '✏️ تعديل أكاديمية' : '➕ إضافة أكاديمية'; ?></h2>
            </div>

            <form method="POST" id="academiesForm" class="academies-form" autocomplete="off">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="id" value="<?php echo htmlspecialchars((string) ($editAcademy['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($academiesCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-group form-group-full">
                    <label for="academy_name">🏷️ اسم الأكاديمية</label>
                    <input
                        type="text"
                        name="academy_name"
                        id="academy_name"
                        value="<?php echo htmlspecialchars((string) ($editAcademy['academy_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        required
                    >
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="subscription_price">💰 سعر الاشتراك</label>
                        <input
                            type="number"
                            min="0.01"
                            step="0.01"
                            name="subscription_price"
                            id="subscription_price"
                            value="<?php echo htmlspecialchars($subscriptionPriceFieldValue, ENT_QUOTES, 'UTF-8'); ?>"
                            aria-label="بالجنيه المصري. أدخل رقمًا صحيحًا أو عشريًا"
                            required
                        >
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="save-btn"><?php echo $editAcademy ? '💾 حفظ التعديل' : '✅ إضافة الأكاديمية'; ?></button>
                    <button type="button" class="clear-btn" id="clearBtn">🧹 تصفية الحقول</button>
                </div>
            </form>
        </div>

        <aside class="side-panel">
            <div class="side-card">
                    <h3>📌 ملخص سريع</h3>
                    <div class="mini-stats">
                        <div class="mini-stat accent-stat">
                            <span>أكاديميات بدون لاعبين</span>
                            <strong><?php echo $academiesWithoutPlayers; ?></strong>
                        </div>
                    </div>
                </div>
                <div class="side-card">
                    <h3>لاعبين الأكاديميات</h3>
                    <div class="form-actions">
                        <a href="academies_players.php" class="back-btn">فتح الصفحة</a>
                    </div>
                </div>
            </aside>
    </section>

    <section class="table-card">
        <div class="card-head table-head">
            <div>
                <h2>📋 الأكاديميات المسجلة</h2>
            </div>
            <span class="table-count"><?php echo count($academies); ?> أكاديمية</span>
        </div>

        <div class="table-wrapper">
            <table>
                <caption class="sr-only">جدول الأكاديميات المسجلة مع سعر الاشتراك وعدد اللاعبين والإجراءات.</caption>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الأكاديمية</th>
                        <th>الاشتراك</th>
                        <th>عدد اللاعبين</th>
                        <th>اللاعبين</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($academies !== []): ?>
                        <?php foreach ($academies as $index => $academy): ?>
                            <?php $detailRowId = 'academy-players-' . (int) $academy['id']; ?>
                            <tr>
                                <td data-label="#"><?php echo $index + 1; ?></td>
                                <td data-label="الأكاديمية">
                                    <div class="academy-cell">
                                        <span class="academy-avatar">🌊</span>
                                        <div>
                                            <strong><?php echo htmlspecialchars((string) $academy['academy_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <span><?php echo htmlspecialchars((string) ($academy['updated_at'] ?? $academy['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="الاشتراك"><?php echo formatAcademiesAmount($academy['subscription_price'] ?? 0); ?> ج.م</td>
                                <td data-label="عدد اللاعبين">
                                    <span class="players-count <?php echo (int) ($academy['player_count'] ?? 0) > 0 ? 'has-players' : 'no-players'; ?>">
                                        <?php echo (int) ($academy['player_count'] ?? 0); ?> لاعب
                                    </span>
                                </td>
                                <td data-label="اللاعبين">
                                    <button
                                        type="button"
                                        class="players-toggle"
                                        data-target="<?php echo htmlspecialchars($detailRowId, ENT_QUOTES, 'UTF-8'); ?>"
                                        aria-expanded="false"
                                    >عرض اللاعبين</button>
                                </td>
                                <td data-label="الإجراءات">
                                    <div class="action-buttons">
                                        <a href="<?php echo buildAcademiesPageUrl(['edit' => $academy['id']]); ?>" class="edit-btn">✏️ تعديل</a>
                                        <form method="POST" class="inline-form delete-academy-form">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars((string) $academy['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($academiesCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" class="delete-btn">🗑️ حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr id="<?php echo htmlspecialchars($detailRowId, ENT_QUOTES, 'UTF-8'); ?>" class="players-detail-row" hidden>
                                <td colspan="6">
                                    <div class="players-panel">
                                        <div class="players-panel-head">
                                            <strong>لاعبو <?php echo htmlspecialchars((string) $academy['academy_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <span><?php echo (int) ($academy['player_count'] ?? 0); ?> لاعب</span>
                                        </div>
                                        <?php if (!empty($academy['player_names'])): ?>
                                            <div class="players-list">
                                                <?php foreach ($academy['player_names'] as $playerName): ?>
                                                    <span class="player-chip"><?php echo htmlspecialchars((string) $playerName, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="empty-players-state">لا توجد أسماء لاعبين مرتبطة بهذه الأكاديمية حتى الآن.</div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-row">لا توجد أكاديميات مسجلة حاليًا، ابدأ بإضافة أول أكاديمية من النموذج أعلاه.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<script src="assets/js/academies.js"></script>
</body>
</html>
