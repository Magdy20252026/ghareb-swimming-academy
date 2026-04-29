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

if (!userCanAccess($currentUser, 'group_evaluations')) {
    header('Location: dashboard.php?access=denied');
    exit;
}

function groupEvaluationsToken(): string
{
    if (!isset($_SESSION['group_evaluations_token']) || !is_string($_SESSION['group_evaluations_token']) || $_SESSION['group_evaluations_token'] === '') {
        $_SESSION['group_evaluations_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['group_evaluations_token'];
}

function groupEvaluationsValidToken($token): bool
{
    return is_string($token) && $token !== '' && hash_equals(groupEvaluationsToken(), $token);
}

function groupEvaluationsNormalizeMonth(string $value): string
{
    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? $value : date('Y-m');
}

function groupEvaluationsText(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return is_string($value) ? $value : '';
}

function groupEvaluationStars(int $score): string
{
    return $score > 0 ? str_repeat('★', $score) : '—';
}

$selectedMonth = groupEvaluationsNormalizeMonth((string) ($_GET['month'] ?? date('Y-m')));
$previousMonth = date('Y-m', strtotime($selectedMonth . '-01 -1 month'));
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action !== '' && !groupEvaluationsValidToken($_POST['csrf_token'] ?? null)) {
        $message = '❌ انتهت صلاحية الطلب.';
        $messageType = 'error';
    } elseif ($action === 'save') {
        $subscriptionId = ctype_digit((string) ($_POST['subscription_id'] ?? '')) ? (int) $_POST['subscription_id'] : 0;
        $evaluationMonth = groupEvaluationsNormalizeMonth((string) ($_POST['evaluation_month'] ?? $selectedMonth));
        $evaluationScore = ctype_digit((string) ($_POST['evaluation_score'] ?? '')) ? (int) $_POST['evaluation_score'] : 0;
        $evaluationNotes = groupEvaluationsText((string) ($_POST['evaluation_notes'] ?? ''));

        if ($subscriptionId <= 0) {
            $message = '❌ اختر مجموعة صحيحة.';
            $messageType = 'error';
        } elseif ($evaluationScore < 1 || $evaluationScore > 5) {
            $message = '❌ اختر تقييمًا من 1 إلى 5.';
            $messageType = 'error';
        } else {
            try {
                $upsertStmt = $pdo->prepare(
                    'INSERT INTO group_evaluations (subscription_id, evaluation_month, evaluation_score, evaluation_notes, created_by_user_id)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE evaluation_score = VALUES(evaluation_score), evaluation_notes = VALUES(evaluation_notes), created_by_user_id = VALUES(created_by_user_id)'
                );
                $upsertStmt->execute([
                    $subscriptionId,
                    $evaluationMonth,
                    $evaluationScore,
                    $evaluationNotes !== '' ? $evaluationNotes : null,
                    (int) ($currentUser['id'] ?? 0) ?: null,
                ]);
                header('Location: group_evaluations.php?month=' . urlencode($evaluationMonth) . '&saved=1');
                exit;
            } catch (Throwable $exception) {
                $message = '❌ حدث خطأ أثناء حفظ التقييم.';
                $messageType = 'error';
            }
        }
    }
}

if (isset($_GET['saved'])) {
    $message = '✅ تم حفظ تقييم المجموعة.';
    $messageType = 'success';
}

$subscriptionsStmt = $pdo->prepare(
    'SELECT
        s.*,
        c.full_name AS coach_name,
        current_eval.evaluation_score AS current_score,
        current_eval.evaluation_notes AS current_notes,
        previous_eval.evaluation_score AS previous_score,
        previous_eval.evaluation_notes AS previous_notes
     FROM subscriptions s
     LEFT JOIN coaches c ON c.id = s.coach_id
     LEFT JOIN group_evaluations current_eval ON current_eval.subscription_id = s.id AND current_eval.evaluation_month = ?
     LEFT JOIN group_evaluations previous_eval ON previous_eval.subscription_id = s.id AND previous_eval.evaluation_month = ?
     ORDER BY s.subscription_branch ASC, s.subscription_category ASC, s.subscription_name ASC, s.id ASC'
);
$subscriptionsStmt->execute([$selectedMonth, $previousMonth]);
$subscriptions = $subscriptionsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($subscriptions as &$subscription) {
    $subscription['schedule_summary'] = formatAcademyTrainingSchedule((string) ($subscription['training_schedule'] ?? ''));
    $subscription['subscription_name'] = buildAcademySubscriptionName(
        (string) ($subscription['subscription_category'] ?? ''),
        (string) ($subscription['coach_name'] ?? ''),
        (string) ($subscription['schedule_summary'] ?? ''),
        (string) ($subscription['subscription_branch'] ?? ''),
        (string) ($subscription['subscription_name'] ?? '')
    );
}
unset($subscription);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقييم المجموعات</title>
    <link rel="stylesheet" href="assets/css/swimmer-admin.css">
</head>
<body class="light-mode" data-theme-key="swimmer-admin-theme">
<div class="admin-page-shell">
    <header class="admin-page-header">
        <div>
            <span class="page-badge">📝 تقييم المجموعات</span>
            <h1>تقييم المجموعات</h1>
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

    <section class="panel-card full-width-panel">
        <form method="GET" class="stack-form compact-filter-form" autocomplete="off">
            <div class="split-grid">
                <div class="form-group">
                    <label for="month">شهر التقييم</label>
                    <input type="month" name="month" id="month" value="<?php echo htmlspecialchars($selectedMonth, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-actions">
                    <button type="submit" class="primary-btn">عرض</button>
                </div>
            </div>
        </form>
    </section>

    <section class="panel-card list-panel full-width-panel">
        <h2>المجموعات</h2>
        <div class="cards-grid evaluation-grid">
            <?php foreach ($subscriptions as $subscription): ?>
                <article class="item-card evaluation-card">
                    <div class="item-card-body">
                        <div class="split-meta">
                            <strong><?php echo htmlspecialchars((string) ($subscription['subscription_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span class="status-chip info-chip"><?php echo htmlspecialchars((string) ($subscription['subscription_branch'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="details-grid compact-details-grid">
                            <div><span>المستوى</span><strong><?php echo htmlspecialchars((string) ($subscription['subscription_category'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div><span>المدرب</span><strong><?php echo htmlspecialchars((string) ($subscription['coach_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div><span>الجدول</span><strong><?php echo htmlspecialchars((string) ($subscription['schedule_summary'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        </div>
                        <div class="details-grid compact-details-grid">
                            <div><span><?php echo htmlspecialchars($selectedMonth, ENT_QUOTES, 'UTF-8'); ?></span><strong><?php echo htmlspecialchars(groupEvaluationStars((int) ($subscription['current_score'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div><span><?php echo htmlspecialchars($previousMonth, ENT_QUOTES, 'UTF-8'); ?></span><strong><?php echo htmlspecialchars(groupEvaluationStars((int) ($subscription['previous_score'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        </div>
                        <?php if (!empty($subscription['previous_notes'])): ?>
                            <p class="notification-message">الشهر السابق: <?php echo nl2br(htmlspecialchars((string) $subscription['previous_notes'], ENT_QUOTES, 'UTF-8')); ?></p>
                        <?php endif; ?>
                    </div>
                    <form method="POST" class="stack-form evaluation-form" autocomplete="off">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="subscription_id" value="<?php echo (int) ($subscription['id'] ?? 0); ?>">
                        <input type="hidden" name="evaluation_month" value="<?php echo htmlspecialchars($selectedMonth, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(groupEvaluationsToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="form-group">
                            <label for="evaluation_score_<?php echo (int) ($subscription['id'] ?? 0); ?>">تقييم الشهر</label>
                            <select name="evaluation_score" id="evaluation_score_<?php echo (int) ($subscription['id'] ?? 0); ?>" required>
                                <option value="">اختر التقييم</option>
                                <?php for ($score = 1; $score <= 5; $score++): ?>
                                    <option value="<?php echo $score; ?>" <?php echo (int) ($subscription['current_score'] ?? 0) === $score ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($score . ' - ' . groupEvaluationStars($score), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="evaluation_notes_<?php echo (int) ($subscription['id'] ?? 0); ?>">ملاحظات التقييم</label>
                            <textarea name="evaluation_notes" id="evaluation_notes_<?php echo (int) ($subscription['id'] ?? 0); ?>" rows="4"><?php echo htmlspecialchars((string) ($subscription['current_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <button type="submit" class="primary-btn">حفظ التقييم</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</div>
<script src="assets/js/swimmer-admin.js"></script>
</body>
</html>
