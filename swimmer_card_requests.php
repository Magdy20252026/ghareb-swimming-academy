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

if (!userCanAccess($currentUser, 'swimmer_card_requests')) {
    header('Location: dashboard.php?access=denied');
    exit;
}

const SWIMMER_CARD_REQUESTS_PAGE_FILE = 'swimmer_card_requests.php';
const SWIMMER_CARD_REQUESTS_UPLOAD_PUBLIC_DIR = 'uploads/swimmer_card_requests';
const SWIMMER_CARD_REQUESTS_ALLOWED_FILENAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';
const SWIMMER_CARD_REQUESTS_EXPORT_HEADERS = [
    'باركود اللاعب',
    'اسم اللاعب',
    'المجموعة',
    'تاريخ الميلاد',
    'الفرع',
];

function swimmerCardRequestsToken(): string
{
    if (!isset($_SESSION['swimmer_card_requests_token']) || !is_string($_SESSION['swimmer_card_requests_token']) || $_SESSION['swimmer_card_requests_token'] === '') {
        $_SESSION['swimmer_card_requests_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['swimmer_card_requests_token'];
}

function swimmerCardRequestsValidToken($token): bool
{
    return is_string($token) && $token !== '' && hash_equals(swimmerCardRequestsToken(), $token);
}

function swimmerCardRequestsNormalizePath($path): ?string
{
    if (!is_string($path)) {
        return null;
    }

    $path = trim($path);
    if (strpos($path, SWIMMER_CARD_REQUESTS_UPLOAD_PUBLIC_DIR . '/') !== 0) {
        return null;
    }

    $fileName = basename($path);
    if ($fileName === '' || preg_match(SWIMMER_CARD_REQUESTS_ALLOWED_FILENAME_PATTERN, $fileName) !== 1) {
        return null;
    }

    return SWIMMER_CARD_REQUESTS_UPLOAD_PUBLIC_DIR . '/' . $fileName;
}

function swimmerCardRequestsAbsolutePath(?string $path): ?string
{
    $normalizedPath = swimmerCardRequestsNormalizePath($path);
    if ($normalizedPath === null) {
        return null;
    }

    return __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
}

function swimmerCardRequestsDownloadName(string $playerName, ?string $path, string $fallbackPrefix = 'player'): string
{
    $baseName = preg_replace('/[^\p{Arabic}A-Za-z0-9_-]+/u', '-', trim($playerName));
    if ($baseName === null || $baseName === '') {
        $baseName = $fallbackPrefix;
    }

    $extension = strtolower((string) pathinfo((string) $path, PATHINFO_EXTENSION));
    $extension = preg_replace('/[^a-z0-9]+/i', '', $extension);
    $extension = $extension !== '' ? $extension : 'jpg';

    return $baseName . '.' . $extension;
}

function swimmerCardRequestBirthYear(?string $birthDate): string
{
    $timestamp = is_string($birthDate) ? strtotime($birthDate) : false;
    if (!is_string($birthDate) || trim($birthDate) === '' || $timestamp === false || $timestamp < 0) {
        return '';
    }

    return date('Y', $timestamp);
}

function swimmerCardRequestsRows(array $requests): array
{
    $rows = [];
    foreach ($requests as $request) {
        $rows[] = [
            (string) ($request['barcode'] ?? ''),
            (string) ($request['player_name_snapshot'] ?? ''),
            (string) ($request['subscription_name'] ?? ''),
            swimmerCardRequestBirthYear($request['birth_date'] ?? null),
            (string) ($request['subscription_branch'] ?? ''),
        ];
    }

    return $rows;
}

function swimmerCardRequestsFetch(PDO $pdo, bool $approved): array
{
    $whereClause = $approved ? 'r.approved_exported_at IS NOT NULL' : 'r.approved_exported_at IS NULL';
    $orderClause = $approved
        ? 'ORDER BY r.approved_exported_at DESC, r.id DESC'
        : 'ORDER BY r.created_at DESC, r.id DESC';

    $stmt = $pdo->query(
        'SELECT
            r.*,
            ap.barcode,
            ap.subscription_branch,
            ap.subscription_name,
            ap.subscription_category,
            ap.birth_date
         FROM swimmer_card_requests r
         LEFT JOIN academy_players ap ON ap.id = r.player_id
         WHERE ' . $whereClause . '
         ' . $orderClause
    );

    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function swimmerCardRequestsDownloadAll(array $requests): void
{
    if ($requests === []) {
        throw new RuntimeException('لا توجد طلبات متاحة للتحميل.');
    }

    $temporaryFile = tempnam(sys_get_temp_dir(), 'card_requests_zip_');
    if ($temporaryFile === false) {
        throw new RuntimeException('تعذر إنشاء ملف مؤقت للتحميل.');
    }

    $zip = new ZipArchive();
    if ($zip->open($temporaryFile, ZipArchive::OVERWRITE) !== true) {
        @unlink($temporaryFile);
        throw new RuntimeException('تعذر تجهيز ملف التحميل.');
    }

    foreach ($requests as $index => $request) {
        $absolutePath = swimmerCardRequestsAbsolutePath($request['request_image_path'] ?? null);
        if ($absolutePath === null || !is_file($absolutePath)) {
            continue;
        }

            $zip->addFile(
                $absolutePath,
            ($index + 1) . '-' . swimmerCardRequestsDownloadName((string) ($request['player_name_snapshot'] ?? 'player'), $request['request_image_path'] ?? null, 'card-request')
        );
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="swimmer-card-requests-' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . (string) filesize($temporaryFile));
    header('Cache-Control: max-age=0');

    readfile($temporaryFile);
    @unlink($temporaryFile);
    exit;
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action !== '' && !swimmerCardRequestsValidToken($_POST['csrf_token'] ?? null)) {
        $message = '❌ انتهت صلاحية الطلب.';
        $messageType = 'error';
    } elseif ($action === 'delete') {
        $requestId = ctype_digit((string) ($_POST['request_id'] ?? '')) ? (int) $_POST['request_id'] : 0;
        $requestStmt = $pdo->prepare('SELECT * FROM swimmer_card_requests WHERE id = ? LIMIT 1');
        $requestStmt->execute([$requestId]);
        $request = $requestStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($request === null) {
            $message = '❌ الطلب غير موجود.';
            $messageType = 'error';
        } else {
            try {
                $deleteStmt = $pdo->prepare('DELETE FROM swimmer_card_requests WHERE id = ?');
                $deleteStmt->execute([$requestId]);
                $absolutePath = swimmerCardRequestsAbsolutePath($request['request_image_path'] ?? null);
                if ($absolutePath !== null && is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
                $message = '✅ تم حذف الطلب.';
            } catch (Throwable $exception) {
                $message = '❌ حدث خطأ أثناء الحذف.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'download_all') {
        try {
            swimmerCardRequestsDownloadAll(swimmerCardRequestsFetch($pdo, false));
        } catch (Throwable $exception) {
            $message = '❌ ' . $exception->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'export_all') {
        $pendingRequests = swimmerCardRequestsFetch($pdo, false);
        if ($pendingRequests === []) {
            $message = '❌ لا توجد طلبات جديدة لاستخراجها.';
            $messageType = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                $pendingRequestIds = array_map(static fn(array $pendingRequest): int => (int) ($pendingRequest['id'] ?? 0), $pendingRequests);
                $placeholders = implode(',', array_fill(0, count($pendingRequestIds), '?'));
                $updateStmt = $pdo->prepare(
                    'UPDATE swimmer_card_requests
                     SET approved_exported_at = NOW(), approved_by_user_id = ?
                     WHERE id IN (' . $placeholders . ')'
                );
                $updateStmt->execute(array_merge([(int) ($currentUser['id'] ?? 0) ?: null], $pendingRequestIds));
                $pdo->commit();

                $temporaryFile = createAcademyXlsxFile(
                    SWIMMER_CARD_REQUESTS_EXPORT_HEADERS,
                    swimmerCardRequestsRows($pendingRequests),
                    'طلبات الكارنية',
                    'طلبات الكارنية'
                );
                outputAcademyXlsxDownload($temporaryFile, 'swimmer-card-requests-' . date('Y-m-d') . '.xlsx');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = '❌ حدث خطأ أثناء استخراج الطلبات.';
                $messageType = 'error';
            }
        }
    }
}

$pendingRequests = swimmerCardRequestsFetch($pdo, false);
$approvedRequests = swimmerCardRequestsFetch($pdo, true);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طلبات الكارنية</title>
    <link rel="stylesheet" href="assets/css/swimmer-admin.css">
</head>
<body class="light-mode" data-theme-key="swimmer-admin-theme">
<div class="admin-page-shell">
    <header class="admin-page-header">
        <div>
            <span class="page-badge">🪪 طلبات الكارنية</span>
            <h1>طلبات الكارنية</h1>
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

    <section class="panel-card full-width-panel">
        <div class="split-meta">
            <h2>الطلبات الجديدة</h2>
            <div class="card-actions">
                <form method="POST" class="inline-form">
                    <input type="hidden" name="action" value="download_all">
                    <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars(swimmerCardRequestsToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="primary-btn secondary-btn">تحميل كل الطلبات</button>
                </form>
                <form method="POST" class="inline-form">
                    <input type="hidden" name="action" value="export_all">
                    <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars(swimmerCardRequestsToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="primary-btn">استخراج شيت إكسل</button>
                </form>
            </div>
        </div>
        <?php if ($pendingRequests === []): ?>
            <div class="empty-state">لا توجد طلبات جديدة حاليًا.</div>
        <?php else: ?>
            <div class="cards-grid request-grid">
                <?php foreach ($pendingRequests as $request): ?>
                    <?php $requestImagePath = swimmerCardRequestsNormalizePath($request['request_image_path'] ?? null); ?>
                    <article class="item-card request-card">
                        <div class="item-image-wrap">
                            <?php if ($requestImagePath !== null): ?>
                                <img src="<?php echo academyHtmlspecialchars($requestImagePath, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo academyHtmlspecialchars((string) ($request['player_name_snapshot'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php else: ?>
                                <div class="item-image-placeholder">🪪</div>
                            <?php endif; ?>
                        </div>
                        <div class="item-card-body details-grid">
                            <div><span>اللاعب</span><strong><?php echo academyHtmlspecialchars((string) ($request['player_name_snapshot'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div><span>الباركود</span><strong><?php echo academyHtmlspecialchars((string) ($request['barcode'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div><span>الفرع</span><strong><?php echo academyHtmlspecialchars((string) ($request['subscription_branch'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div><span>المجموعة</span><strong><?php echo academyHtmlspecialchars((string) ($request['subscription_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div><span>سنة الميلاد</span><strong><?php echo academyHtmlspecialchars(swimmerCardRequestBirthYear($request['birth_date'] ?? null) ?: '—', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div><span>تاريخ الطلب</span><strong><?php echo academyHtmlspecialchars(date('Y-m-d H:i', strtotime((string) ($request['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        </div>
                        <div class="card-actions">
                            <?php if ($requestImagePath !== null): ?>
                                <a href="<?php echo academyHtmlspecialchars($requestImagePath, ENT_QUOTES, 'UTF-8'); ?>" download="<?php echo academyHtmlspecialchars(swimmerCardRequestsDownloadName((string) ($request['player_name_snapshot'] ?? 'player'), $requestImagePath), ENT_QUOTES, 'UTF-8'); ?>" class="primary-btn secondary-btn">تحميل</a>
                            <?php endif; ?>
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo academyHtmlspecialchars(swimmerCardRequestsToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="primary-btn danger-btn">حذف</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel-card list-panel full-width-panel">
        <div class="split-meta">
            <h2>استخراجات تمت الموافقة عليها</h2>
            <span class="status-chip success-chip"><?php echo count($approvedRequests); ?> طلب</span>
        </div>
        <?php if ($approvedRequests === []): ?>
            <div class="empty-state">لم يتم اعتماد أي استخراج بعد.</div>
        <?php else: ?>
            <div class="table-shell">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>الباركود</th>
                        <th>اسم اللاعب</th>
                        <th>المجموعة</th>
                        <th>سنة الميلاد</th>
                        <th>الفرع</th>
                        <th>تاريخ الاعتماد</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($approvedRequests as $request): ?>
                        <tr>
                            <td><?php echo academyHtmlspecialchars((string) ($request['barcode'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo academyHtmlspecialchars((string) ($request['player_name_snapshot'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo academyHtmlspecialchars((string) ($request['subscription_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo academyHtmlspecialchars(swimmerCardRequestBirthYear($request['birth_date'] ?? null) ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo academyHtmlspecialchars((string) ($request['subscription_branch'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo academyHtmlspecialchars(date('Y-m-d H:i', strtotime((string) ($request['approved_exported_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<script src="assets/js/swimmer-admin.js"></script>
</body>
</html>
