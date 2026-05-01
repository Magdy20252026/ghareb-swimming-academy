<?php
session_start();
require_once 'config.php';
require_once 'app_helpers.php';

const REGULAR_ACADEMY_ID = 0;

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

if (!userCanAccess($currentUser, 'reports')) {
    header('Location: dashboard.php?access=denied');
    exit;
}

function fetchReportsRows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $exception) {
        error_log('تعذر تحميل بيانات صفحة الإحصائيات: ' . $exception->getMessage());
        return [];
    }
}

function sumReportsColumn(array $rows, string $key): float
{
    $total = 0.0;

    foreach ($rows as $row) {
        $total += (float) ($row[$key] ?? 0);
    }

    return $total;
}

function countDistinctReportsColumn(array $rows, string $key): int
{
    $values = [];

    foreach ($rows as $row) {
        $value = (string) ($row[$key] ?? '');
        if ($value !== '') {
            $values[$value] = true;
        }
    }

    return count($values);
}

function sortReportsRowsByEvent(array $rows): array
{
    usort($rows, static function (array $left, array $right): int {
        return strcmp((string) ($right['event_at'] ?? ''), (string) ($left['event_at'] ?? ''));
    });

    return $rows;
}

function formatReportsMoney(float $value): string
{
    return number_format($value, 2, '.', ',');
}

function formatReportsDate(string $value): string
{
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('Y-m-d', $timestamp);
}

function formatReportsPeriodRange(?string $startDate, ?string $endDate): string
{
    $startDate = $startDate !== null ? trim($startDate) : '';
    $endDate = $endDate !== null ? trim($endDate) : '';

    if ($startDate === '' && $endDate === '') {
        return '-';
    }

    if ($startDate !== '' && $endDate !== '' && $startDate !== $endDate) {
        return formatReportsDate($startDate) . ' - ' . formatReportsDate($endDate);
    }

    return formatReportsDate($endDate !== '' ? $endDate : $startDate);
}

function formatReportsCellValue(array $row, array $column): string
{
    $value = $row[$column['key']] ?? '';
    $type = $column['type'] ?? 'text';

    if ($type === 'money') {
        return formatReportsMoney((float) $value) . ' ج.م';
    }

    if ($type === 'period') {
        return formatReportsPeriodRange(
            isset($row['period_start']) ? (string) $row['period_start'] : null,
            isset($row['period_end']) ? (string) $row['period_end'] : null
        );
    }

    $text = trim((string) $value);
    return $text !== '' ? $text : '-';
}

function buildReportsMoneySummaries(array $rows, array $columns): array
{
    $summaries = [];

    foreach ($columns as $column) {
        if (($column['type'] ?? '') !== 'money') {
            continue;
        }

        $columnKey = trim((string) ($column['key'] ?? ''));
        if ($columnKey === '') {
            continue;
        }

        $summaries[] = [
            'key' => $columnKey,
            'label' => (string) ($column['label'] ?? $columnKey),
            'total' => sumReportsColumn($rows, $columnKey),
        ];
    }

    return $summaries;
}

function excludeSummaryByKey(array $summaries, string $summaryKey): array
{
    if ($summaryKey === '') {
        return $summaries;
    }

    return array_values(array_filter(
        $summaries,
        static fn(array $summary): bool => ($summary['key'] ?? '') !== $summaryKey
    ));
}

$periodDefinitions = [
    'daily' => [
        'label' => 'يومية',
        'start' => static fn(DateTimeImmutable $today): DateTimeImmutable => $today,
    ],
    'weekly' => [
        'label' => 'أسبوعية',
        'start' => static fn(DateTimeImmutable $today): DateTimeImmutable => $today->modify('monday this week'),
    ],
    'monthly' => [
        'label' => 'شهرية',
        'start' => static fn(DateTimeImmutable $today): DateTimeImmutable => $today->modify('first day of this month'),
    ],
];

$selectedPeriod = strtolower(trim((string) ($_GET['period'] ?? 'daily')));
if (!isset($periodDefinitions[$selectedPeriod])) {
    $selectedPeriod = 'daily';
}

$timezoneName = date_default_timezone_get();
if (!is_string($timezoneName) || $timezoneName === '') {
    $timezoneName = 'UTC';
}

$today = new DateTimeImmutable('today', new DateTimeZone($timezoneName));
$periodStartDate = $periodDefinitions[$selectedPeriod]['start']($today);
$periodEndDate = $today;
$periodStartDateTime = $periodStartDate->setTime(0, 0, 0)->format('Y-m-d H:i:s');
$periodEndDateTime = $periodEndDate->setTime(23, 59, 59)->format('Y-m-d H:i:s');
$periodStartValue = $periodStartDate->format('Y-m-d');
$periodEndValue = $periodEndDate->format('Y-m-d');

$siteSettings = getSiteSettings($pdo);
$academyName = $siteSettings['academy_name'];

$regularRegistrationRows = fetchReportsRows(
    $pdo,
    "SELECT
        ap.id,
        ap.player_name,
        COALESCE(NULLIF(reg.subscription_name_snapshot, ''), NULLIF(ap.subscription_name, ''), '-') AS subscription_name,
        COALESCE(reg.subscription_amount_snapshot, ap.subscription_amount, 0) AS subscription_amount,
        COALESCE(reg.amount, ap.paid_amount, 0) AS paid_amount,
        COALESCE(ap.remaining_amount, 0) AS remaining_amount,
        COALESCE(u.username, '-') AS actor_name,
        ap.created_at AS event_at
     FROM academy_players ap
     LEFT JOIN academy_player_payments reg ON reg.id = (
        SELECT app.id
        FROM academy_player_payments app
        WHERE app.player_id = ap.id AND app.payment_type = 'registration'
        ORDER BY app.id ASC
        LIMIT 1
     )
     LEFT JOIN users u ON u.id = COALESCE(reg.created_by_user_id, ap.created_by_user_id)
     WHERE ap.academy_id = " . REGULAR_ACADEMY_ID . " AND ap.created_at BETWEEN ? AND ?
     ORDER BY ap.created_at DESC, ap.id DESC",
    [$periodStartDateTime, $periodEndDateTime]
);

$regularSettlementRows = fetchReportsRows(
    $pdo,
    "SELECT
        pay.id,
        COALESCE(NULLIF(pay.player_name_snapshot, ''), ap.player_name) AS player_name,
        COALESCE(pay.remaining_amount_before_snapshot, pay.amount + COALESCE(pay.remaining_amount_after_snapshot, ap.remaining_amount, 0)) AS remaining_before,
        pay.amount AS paid_amount,
        COALESCE(
            pay.remaining_amount_after_snapshot,
            GREATEST(COALESCE(pay.remaining_amount_before_snapshot, pay.amount + COALESCE(ap.remaining_amount, 0)) - pay.amount, 0)
        ) AS remaining_after,
        COALESCE(u.username, '-') AS actor_name,
        pay.created_at AS event_at
     FROM academy_player_payments pay
     INNER JOIN academy_players ap ON ap.id = pay.player_id
     LEFT JOIN users u ON u.id = pay.created_by_user_id
     WHERE ap.academy_id = " . REGULAR_ACADEMY_ID . "
       AND pay.payment_type = 'settlement'
       AND pay.created_at BETWEEN ? AND ?
     ORDER BY pay.created_at DESC, pay.id DESC",
    [$periodStartDateTime, $periodEndDateTime]
);

$regularRenewalPaymentRows = fetchReportsRows(
    $pdo,
    "SELECT
        pay.id,
        COALESCE(NULLIF(pay.player_name_snapshot, ''), ap.player_name) AS player_name,
        COALESCE(NULLIF(pay.subscription_name_snapshot, ''), NULLIF(ap.subscription_name, ''), '-') AS subscription_name,
        COALESCE(pay.subscription_amount_snapshot, ap.subscription_amount, 0) AS subscription_amount,
        pay.amount AS paid_amount,
        COALESCE(
            pay.remaining_amount_after_snapshot,
            GREATEST(COALESCE(pay.subscription_amount_snapshot, ap.subscription_amount, 0) - pay.amount, 0)
        ) AS remaining_amount,
        COALESCE(u.username, '-') AS actor_name,
        pay.created_at AS event_at
     FROM academy_player_payments pay
     INNER JOIN academy_players ap ON ap.id = pay.player_id
     LEFT JOIN users u ON u.id = COALESCE(pay.created_by_user_id, ap.last_renewed_by_user_id)
     WHERE ap.academy_id = " . REGULAR_ACADEMY_ID . "
       AND pay.payment_type = 'renewal'
       AND pay.created_at BETWEEN ? AND ?",
    [$periodStartDateTime, $periodEndDateTime]
);

$regularRenewalFallbackRows = fetchReportsRows(
    $pdo,
    "SELECT
        ap.id,
        ap.player_name,
        COALESCE(NULLIF(ap.subscription_name, ''), '-') AS subscription_name,
        ap.subscription_amount,
        ap.paid_amount,
        ap.remaining_amount,
        COALESCE(u.username, '-') AS actor_name,
        ap.last_renewed_at AS event_at
     FROM academy_players ap
     LEFT JOIN users u ON u.id = ap.last_renewed_by_user_id
     WHERE ap.academy_id = " . REGULAR_ACADEMY_ID . "
       AND ap.last_renewed_at BETWEEN ? AND ?
       AND NOT EXISTS (
            SELECT 1
            FROM academy_player_payments pay
            WHERE pay.player_id = ap.id
              AND pay.payment_type = 'renewal'
              AND pay.created_at BETWEEN ? AND ?
       )",
    [$periodStartDateTime, $periodEndDateTime, $periodStartDateTime, $periodEndDateTime]
);

$academyRegistrationRows = fetchReportsRows(
    $pdo,
    "SELECT
        ap.id,
        ap.player_name,
        COALESCE(NULLIF(reg.subscription_name_snapshot, ''), NULLIF(a.academy_name, ''), NULLIF(ap.subscription_name, ''), '-') AS academy_name,
        COALESCE(reg.subscription_amount_snapshot, ap.subscription_amount, 0) AS subscription_amount,
        COALESCE(reg.amount, ap.paid_amount, 0) AS paid_amount,
        COALESCE(ap.remaining_amount, 0) AS remaining_amount,
        COALESCE(u.username, '-') AS actor_name,
        ap.created_at AS event_at
     FROM academy_players ap
     LEFT JOIN academies a ON a.id = ap.academy_id
     LEFT JOIN academy_player_payments reg ON reg.id = (
        SELECT app.id
        FROM academy_player_payments app
        WHERE app.player_id = ap.id AND app.payment_type = 'registration'
        ORDER BY app.id ASC
        LIMIT 1
     )
     LEFT JOIN users u ON u.id = COALESCE(reg.created_by_user_id, ap.created_by_user_id)
     WHERE ap.academy_id > 0 AND ap.created_at BETWEEN ? AND ?
     ORDER BY ap.created_at DESC, ap.id DESC",
    [$periodStartDateTime, $periodEndDateTime]
);

$academySettlementRows = fetchReportsRows(
    $pdo,
    "SELECT
        pay.id,
        COALESCE(NULLIF(pay.player_name_snapshot, ''), ap.player_name) AS player_name,
        COALESCE(NULLIF(pay.subscription_name_snapshot, ''), NULLIF(a.academy_name, ''), NULLIF(ap.subscription_name, ''), '-') AS academy_name,
        COALESCE(pay.remaining_amount_before_snapshot, pay.amount + COALESCE(pay.remaining_amount_after_snapshot, ap.remaining_amount, 0)) AS remaining_before,
        pay.amount AS paid_amount,
        COALESCE(
            pay.remaining_amount_after_snapshot,
            GREATEST(COALESCE(pay.remaining_amount_before_snapshot, pay.amount + COALESCE(ap.remaining_amount, 0)) - pay.amount, 0)
        ) AS remaining_after,
        COALESCE(u.username, '-') AS actor_name,
        pay.created_at AS event_at
     FROM academy_player_payments pay
     INNER JOIN academy_players ap ON ap.id = pay.player_id
     LEFT JOIN academies a ON a.id = ap.academy_id
     LEFT JOIN users u ON u.id = pay.created_by_user_id
     WHERE ap.academy_id > 0
       AND pay.payment_type = 'settlement'
       AND pay.created_at BETWEEN ? AND ?
     ORDER BY pay.created_at DESC, pay.id DESC",
    [$periodStartDateTime, $periodEndDateTime]
);

$academyRenewalPaymentRows = fetchReportsRows(
    $pdo,
    "SELECT
        pay.id,
        COALESCE(NULLIF(pay.player_name_snapshot, ''), ap.player_name) AS player_name,
        COALESCE(NULLIF(pay.subscription_name_snapshot, ''), NULLIF(a.academy_name, ''), NULLIF(ap.subscription_name, ''), '-') AS academy_name,
        COALESCE(pay.subscription_amount_snapshot, ap.subscription_amount, 0) AS subscription_amount,
        pay.amount AS paid_amount,
        COALESCE(
            pay.remaining_amount_after_snapshot,
            GREATEST(COALESCE(pay.subscription_amount_snapshot, ap.subscription_amount, 0) - pay.amount, 0)
        ) AS remaining_amount,
        COALESCE(u.username, '-') AS actor_name,
        pay.created_at AS event_at
     FROM academy_player_payments pay
     INNER JOIN academy_players ap ON ap.id = pay.player_id
     LEFT JOIN academies a ON a.id = ap.academy_id
     LEFT JOIN users u ON u.id = COALESCE(pay.created_by_user_id, ap.last_renewed_by_user_id)
     WHERE ap.academy_id > 0
       AND pay.payment_type = 'renewal'
       AND pay.created_at BETWEEN ? AND ?",
    [$periodStartDateTime, $periodEndDateTime]
);

$academyRenewalFallbackRows = fetchReportsRows(
    $pdo,
    "SELECT
        ap.id,
        ap.player_name,
        COALESCE(NULLIF(a.academy_name, ''), NULLIF(ap.subscription_name, ''), '-') AS academy_name,
        ap.subscription_amount,
        ap.paid_amount,
        ap.remaining_amount,
        COALESCE(u.username, '-') AS actor_name,
        ap.last_renewed_at AS event_at
     FROM academy_players ap
     LEFT JOIN academies a ON a.id = ap.academy_id
     LEFT JOIN users u ON u.id = ap.last_renewed_by_user_id
     WHERE ap.academy_id > 0
       AND ap.last_renewed_at BETWEEN ? AND ?
       AND NOT EXISTS (
            SELECT 1
            FROM academy_player_payments pay
            WHERE pay.player_id = ap.id
              AND pay.payment_type = 'renewal'
              AND pay.created_at BETWEEN ? AND ?
       )",
    [$periodStartDateTime, $periodEndDateTime, $periodStartDateTime, $periodEndDateTime]
);

$coachSalaryRows = fetchReportsRows(
    $pdo,
    "SELECT
        p.id,
        p.coach_id,
        c.full_name AS coach_name,
        p.period_start,
        p.period_end,
        p.net_amount,
        COALESCE(u.username, '-') AS actor_name,
        p.paid_at AS event_at
     FROM coach_salary_payments p
     INNER JOIN coaches c ON c.id = p.coach_id
     LEFT JOIN users u ON u.id = p.paid_by_user_id
     WHERE p.payment_status = 'paid' AND p.paid_at BETWEEN ? AND ?
     ORDER BY p.paid_at DESC, p.id DESC",
    [$periodStartDateTime, $periodEndDateTime]
);

$adminSalaryRows = fetchReportsRows(
    $pdo,
    "SELECT
        p.id,
        p.administrator_id,
        a.full_name AS admin_name,
        p.period_start,
        p.period_end,
        p.net_amount,
        COALESCE(u.username, '-') AS actor_name,
        p.paid_at AS event_at
     FROM admin_salary_payments p
     INNER JOIN administrators a ON a.id = p.administrator_id
     LEFT JOIN users u ON u.id = p.paid_by_user_id
     WHERE p.paid_at BETWEEN ? AND ?
     ORDER BY p.paid_at DESC, p.id DESC",
    [$periodStartDateTime, $periodEndDateTime]
);

$expenseRows = fetchReportsRows(
    $pdo,
    "SELECT
        e.id,
        e.description,
        e.amount,
        COALESCE(u.username, '-') AS actor_name,
        CAST(e.expense_date AS DATETIME) AS event_at
     FROM expenses e
     LEFT JOIN users u ON u.id = e.created_by_user_id
     WHERE e.expense_date BETWEEN ? AND ?
     ORDER BY e.expense_date DESC, e.created_at DESC, e.id DESC",
    [$periodStartValue, $periodEndValue]
);

$salesInvoiceRows = fetchReportsRows(
    $pdo,
    "SELECT
        si.id,
        si.invoice_number,
        si.total_amount,
        si.paid_amount,
        si.remaining_amount,
        COALESCE(u.username, '-') AS actor_name,
        si.created_at AS event_at
     FROM sales_invoices si
     LEFT JOIN users u ON u.id = si.created_by_user_id
     WHERE si.invoice_type = 'sale'
       AND si.created_at BETWEEN ? AND ?
     ORDER BY si.created_at DESC, si.id DESC",
    [$periodStartDateTime, $periodEndDateTime]
);
$salesInvoiceRows = attachSalesInvoiceDetails($pdo, $salesInvoiceRows, 'formatReportsMoney');

$salesSettlementRows = fetchReportsRows(
    $pdo,
    "SELECT
        pay.id,
        inv.invoice_number,
        GREATEST(
            inv.total_amount - COALESCE((
                SELECT SUM(prev.amount)
                FROM sales_invoice_payments prev
                WHERE prev.invoice_id = pay.invoice_id
                  AND (
                      prev.created_at < pay.created_at
                      OR (prev.created_at = pay.created_at AND prev.id < pay.id)
                  )
            ), 0),
            0
        ) AS amount_before,
        pay.amount AS paid_amount,
        GREATEST(
            inv.total_amount - COALESCE((
                SELECT SUM(prev.amount)
                FROM sales_invoice_payments prev
                WHERE prev.invoice_id = pay.invoice_id
                  AND (
                      prev.created_at < pay.created_at
                      OR (prev.created_at = pay.created_at AND prev.id <= pay.id)
                  )
            ), 0),
            0
        ) AS amount_after,
        COALESCE(u.username, '-') AS actor_name,
        pay.created_at AS event_at
     FROM sales_invoice_payments pay
     INNER JOIN sales_invoices inv ON inv.id = pay.invoice_id
     LEFT JOIN users u ON u.id = pay.created_by_user_id
     WHERE inv.invoice_type = 'sale'
       AND COALESCE(pay.payment_note, '') = ?
       AND pay.created_at BETWEEN ? AND ?
     ORDER BY pay.created_at DESC, pay.id DESC",
    [getSalesInvoiceSettlementPaymentNote(), $periodStartDateTime, $periodEndDateTime]
);

$regularRenewalRows = sortReportsRowsByEvent(array_merge($regularRenewalPaymentRows, $regularRenewalFallbackRows));
$academyRenewalRows = sortReportsRowsByEvent(array_merge($academyRenewalPaymentRows, $academyRenewalFallbackRows));

$sections = [
    [
        'key' => 'regular-registration',
        'title' => 'السباحين الجدد',
        'icon' => '🏊',
        'count_label' => 'عدد السباحين',
        'amount_label' => 'مجموع المبالغ',
        'total_key' => 'paid_amount',
        'count' => count($regularRegistrationRows),
        'total' => sumReportsColumn($regularRegistrationRows, 'paid_amount'),
        'rows' => $regularRegistrationRows,
        'columns' => [
            ['key' => 'player_name', 'label' => 'اسم السباح'],
            ['key' => 'subscription_name', 'label' => 'اسم المجموعة'],
            ['key' => 'subscription_amount', 'label' => 'مبلغ الاشتراك', 'type' => 'money'],
            ['key' => 'paid_amount', 'label' => 'المدفوع', 'type' => 'money'],
            ['key' => 'remaining_amount', 'label' => 'المتبقي', 'type' => 'money'],
            ['key' => 'actor_name', 'label' => 'المستخدم'],
        ],
        'group' => 'revenue',
    ],
    [
        'key' => 'regular-settlement',
        'title' => 'تسديد بواقي السباحين',
        'icon' => '💵',
        'count_label' => 'عدد التسديدات',
        'amount_label' => 'مجموع المبالغ',
        'total_key' => 'paid_amount',
        'count' => count($regularSettlementRows),
        'total' => sumReportsColumn($regularSettlementRows, 'paid_amount'),
        'rows' => $regularSettlementRows,
        'columns' => [
            ['key' => 'player_name', 'label' => 'اسم السباح'],
            ['key' => 'remaining_before', 'label' => 'المتبقي قبل السداد', 'type' => 'money'],
            ['key' => 'paid_amount', 'label' => 'المدفوع', 'type' => 'money'],
            ['key' => 'remaining_after', 'label' => 'المتبقي بعد السداد', 'type' => 'money'],
            ['key' => 'actor_name', 'label' => 'المستخدم'],
        ],
        'group' => 'revenue',
    ],
    [
        'key' => 'regular-renewal',
        'title' => 'تجديد اشتراك السباحين',
        'icon' => '🔄',
        'count_label' => 'عدد التجديدات',
        'amount_label' => 'مجموع المبالغ',
        'total_key' => 'paid_amount',
        'count' => count($regularRenewalRows),
        'total' => sumReportsColumn($regularRenewalRows, 'paid_amount'),
        'rows' => $regularRenewalRows,
        'columns' => [
            ['key' => 'player_name', 'label' => 'اسم السباح'],
            ['key' => 'subscription_name', 'label' => 'اسم المجموعة'],
            ['key' => 'subscription_amount', 'label' => 'مبلغ الاشتراك', 'type' => 'money'],
            ['key' => 'paid_amount', 'label' => 'المدفوع', 'type' => 'money'],
            ['key' => 'remaining_amount', 'label' => 'المتبقي', 'type' => 'money'],
            ['key' => 'actor_name', 'label' => 'المستخدم'],
        ],
        'group' => 'revenue',
    ],
    [
        'key' => 'academy-registration',
        'title' => 'لاعبين الأكاديميات الجدد',
        'icon' => '🌊',
        'count_label' => 'عدد السباحين',
        'amount_label' => 'مجموع المبالغ',
        'total_key' => 'paid_amount',
        'count' => count($academyRegistrationRows),
        'total' => sumReportsColumn($academyRegistrationRows, 'paid_amount'),
        'rows' => $academyRegistrationRows,
        'columns' => [
            ['key' => 'player_name', 'label' => 'اسم السباح'],
            ['key' => 'academy_name', 'label' => 'اسم الأكاديمية'],
            ['key' => 'subscription_amount', 'label' => 'مبلغ الاشتراك', 'type' => 'money'],
            ['key' => 'paid_amount', 'label' => 'المدفوع', 'type' => 'money'],
            ['key' => 'remaining_amount', 'label' => 'المتبقي', 'type' => 'money'],
            ['key' => 'actor_name', 'label' => 'المستخدم'],
        ],
        'group' => 'revenue',
    ],
    [
        'key' => 'academy-settlement',
        'title' => 'تسديد بواقي لاعبي الأكاديميات',
        'icon' => '💳',
        'count_label' => 'عدد التسديدات',
        'amount_label' => 'مجموع المبالغ',
        'total_key' => 'paid_amount',
        'count' => count($academySettlementRows),
        'total' => sumReportsColumn($academySettlementRows, 'paid_amount'),
        'rows' => $academySettlementRows,
        'columns' => [
            ['key' => 'player_name', 'label' => 'اسم السباح'],
            ['key' => 'academy_name', 'label' => 'اسم الأكاديمية'],
            ['key' => 'remaining_before', 'label' => 'المتبقي قبل السداد', 'type' => 'money'],
            ['key' => 'paid_amount', 'label' => 'المدفوع', 'type' => 'money'],
            ['key' => 'remaining_after', 'label' => 'المتبقي بعد السداد', 'type' => 'money'],
            ['key' => 'actor_name', 'label' => 'المستخدم'],
        ],
        'group' => 'revenue',
    ],
    [
        'key' => 'academy-renewal',
        'title' => 'تجديد اشتراك لاعبي الأكاديميات',
        'icon' => '♻️',
        'count_label' => 'عدد التجديدات',
        'amount_label' => 'مجموع المبالغ',
        'total_key' => 'paid_amount',
        'count' => count($academyRenewalRows),
        'total' => sumReportsColumn($academyRenewalRows, 'paid_amount'),
        'rows' => $academyRenewalRows,
        'columns' => [
            ['key' => 'player_name', 'label' => 'اسم السباح'],
            ['key' => 'academy_name', 'label' => 'اسم الأكاديمية'],
            ['key' => 'subscription_amount', 'label' => 'مبلغ الاشتراك', 'type' => 'money'],
            ['key' => 'paid_amount', 'label' => 'المدفوع', 'type' => 'money'],
            ['key' => 'remaining_amount', 'label' => 'المتبقي', 'type' => 'money'],
            ['key' => 'actor_name', 'label' => 'المستخدم'],
        ],
        'group' => 'revenue',
    ],
    [
        'key' => 'sales-invoices',
        'title' => 'فواتير المبيعات',
        'icon' => '🛒',
        'count_label' => 'عدد الفواتير',
        'amount_label' => 'مجموع مبالغ الفواتير',
        'total_key' => 'total_amount',
        'count' => count($salesInvoiceRows),
        'total' => sumReportsColumn($salesInvoiceRows, 'total_amount'),
        'rows' => $salesInvoiceRows,
        'columns' => [
            ['key' => 'invoice_number', 'label' => 'رقم الفاتورة'],
            ['key' => 'item_names', 'label' => 'أسماء الأصناف'],
            ['key' => 'item_quantities', 'label' => 'عدد كل صنف'],
            ['key' => 'item_sale_prices', 'label' => 'سعر البيع لكل صنف'],
            ['key' => 'total_amount', 'label' => 'الإجمالي', 'type' => 'money'],
            ['key' => 'paid_amount', 'label' => 'المدفوع', 'type' => 'money'],
            ['key' => 'remaining_amount', 'label' => 'المتبقي', 'type' => 'money'],
            ['key' => 'actor_name', 'label' => 'المستخدم'],
        ],
        'group' => 'revenue',
    ],
    [
        'key' => 'sales-settlement',
        'title' => 'سداد بواقي فواتير المبيعات',
        'icon' => '💰',
        'count_label' => 'عدد التسديدات',
        'amount_label' => 'مجموع مبالغ سداد البواقي',
        'total_key' => 'paid_amount',
        'count' => count($salesSettlementRows),
        'total' => sumReportsColumn($salesSettlementRows, 'paid_amount'),
        'rows' => $salesSettlementRows,
        'columns' => [
            ['key' => 'invoice_number', 'label' => 'رقم الفاتورة'],
            ['key' => 'amount_before', 'label' => 'المبلغ قبل التسديد', 'type' => 'money'],
            ['key' => 'paid_amount', 'label' => 'المدفوع', 'type' => 'money'],
            ['key' => 'amount_after', 'label' => 'المبلغ بعد التسديد', 'type' => 'money'],
            ['key' => 'actor_name', 'label' => 'المستخدم'],
        ],
        'group' => 'revenue',
    ],
    [
        'key' => 'coach-salaries',
        'title' => 'مرتبات المدربين',
        'icon' => '🏅',
        'count_label' => 'عدد المدربين',
        'amount_label' => 'مجموع المرتبات',
        'total_key' => 'net_amount',
        'count' => countDistinctReportsColumn($coachSalaryRows, 'coach_id'),
        'total' => sumReportsColumn($coachSalaryRows, 'net_amount'),
        'rows' => $coachSalaryRows,
        'columns' => [
            ['key' => 'coach_name', 'label' => 'اسم المدرب'],
            ['key' => 'period_label', 'label' => 'الفترة', 'type' => 'period'],
            ['key' => 'net_amount', 'label' => 'المبلغ المصروف', 'type' => 'money'],
            ['key' => 'actor_name', 'label' => 'المستخدم'],
        ],
        'group' => 'expense',
    ],
    [
        'key' => 'admin-salaries',
        'title' => 'مرتبات الإداريين',
        'icon' => '🗂️',
        'count_label' => 'عدد الإداريين',
        'amount_label' => 'مجموع المرتبات',
        'total_key' => 'net_amount',
        'count' => countDistinctReportsColumn($adminSalaryRows, 'administrator_id'),
        'total' => sumReportsColumn($adminSalaryRows, 'net_amount'),
        'rows' => $adminSalaryRows,
        'columns' => [
            ['key' => 'admin_name', 'label' => 'اسم الإداري'],
            ['key' => 'period_label', 'label' => 'الفترة', 'type' => 'period'],
            ['key' => 'net_amount', 'label' => 'المبلغ المصروف', 'type' => 'money'],
            ['key' => 'actor_name', 'label' => 'المستخدم'],
        ],
        'group' => 'expense',
    ],
    [
        'key' => 'expenses',
        'title' => 'المصروفات',
        'icon' => '🧾',
        'count_label' => 'عدد المصروفات',
        'amount_label' => 'مجموع المصروفات',
        'total_key' => 'amount',
        'count' => count($expenseRows),
        'total' => sumReportsColumn($expenseRows, 'amount'),
        'rows' => $expenseRows,
        'columns' => [
            ['key' => 'description', 'label' => 'البيان'],
            ['key' => 'amount', 'label' => 'المبلغ', 'type' => 'money'],
            ['key' => 'actor_name', 'label' => 'المستخدم'],
        ],
        'group' => 'expense',
    ],
];

foreach ($sections as $sectionIndex => $section) {
    $moneySummaries = buildReportsMoneySummaries($section['rows'], $section['columns']);
    $sections[$sectionIndex]['money_summaries'] = $moneySummaries;
    $sections[$sectionIndex]['secondary_money_summaries'] = excludeSummaryByKey(
        $moneySummaries,
        (string) ($section['total_key'] ?? '')
    );
}

$revenueSections = array_values(array_filter($sections, static fn(array $section): bool => $section['group'] === 'revenue'));
$expenseSections = array_values(array_filter($sections, static fn(array $section): bool => $section['group'] === 'expense'));
$revenueTotal = 0.0;
$expenseTotal = 0.0;

foreach ($revenueSections as $section) {
    $revenueTotal += (float) ($section['total'] ?? 0);
}

foreach ($expenseSections as $section) {
    $expenseTotal += (float) ($section['total'] ?? 0);
}

$netTotal = $revenueTotal - $expenseTotal;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الإحصائيات - <?php echo academyHtmlspecialchars($academyName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="assets/css/reports.css">
</head>
<body class="light-mode">
<div class="reports-page">
    <header class="page-header">
        <div class="header-text">
            <h1>الإحصائيات</h1>
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
            <a href="dashboard.php" class="back-btn">لوحة التحكم</a>
        </div>
    </header>

    <section class="period-toolbar">
        <div class="period-switcher">
            <?php foreach ($periodDefinitions as $periodKey => $periodDefinition): ?>
                <a
                    href="reports.php?period=<?php echo academyHtmlspecialchars($periodKey, ENT_QUOTES, 'UTF-8'); ?>"
                    class="period-chip <?php echo $selectedPeriod === $periodKey ? 'active' : ''; ?>"
                >
                    <?php echo academyHtmlspecialchars($periodDefinition['label'], ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="period-range"><?php echo academyHtmlspecialchars(formatReportsPeriodRange($periodStartValue, $periodEndValue), ENT_QUOTES, 'UTF-8'); ?></div>
    </section>

    <section class="hero-grid">
        <article class="hero-card hero-card-main">
            <span>الإجمالي</span>
            <strong><?php echo academyHtmlspecialchars(formatReportsMoney($netTotal), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
        </article>
        <article class="hero-card">
            <span>الإيرادات</span>
            <strong><?php echo academyHtmlspecialchars(formatReportsMoney($revenueTotal), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
        </article>
        <article class="hero-card">
            <span>المصروفات</span>
            <strong><?php echo academyHtmlspecialchars(formatReportsMoney($expenseTotal), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
        </article>
        <article class="hero-card">
            <span>الفترة</span>
            <strong><?php echo academyHtmlspecialchars($periodDefinitions[$selectedPeriod]['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
        </article>
    </section>

    <section class="group-card">
        <div class="group-head">
            <h2>الإيرادات</h2>
        </div>
        <div class="metrics-grid">
            <?php foreach ($revenueSections as $section): ?>
                <article class="metric-card">
                    <div class="metric-head">
                        <div class="metric-icon"><?php echo $section['icon']; ?></div>
                        <h3><?php echo academyHtmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    </div>
                    <div class="metric-values">
                        <div class="value-box">
                            <span><?php echo academyHtmlspecialchars($section['count_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <strong><?php echo (int) $section['count']; ?></strong>
                        </div>
                        <div class="value-box value-box-money">
                            <span><?php echo academyHtmlspecialchars($section['amount_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <strong><?php echo academyHtmlspecialchars(formatReportsMoney((float) $section['total']), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
                        </div>
                    </div>
                    <?php if (!empty($section['secondary_money_summaries'])): ?>
                        <div class="money-breakdown-grid">
                            <?php foreach ($section['secondary_money_summaries'] as $summary): ?>
                                <div class="money-breakdown-box">
                                    <span><?php echo academyHtmlspecialchars('إجمالي ' . $summary['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <strong><?php echo academyHtmlspecialchars(formatReportsMoney((float) $summary['total']), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <button
                        type="button"
                        class="details-btn"
                        data-modal-target="modal-<?php echo academyHtmlspecialchars($section['key'], ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo empty($section['rows']) ? 'disabled' : ''; ?>
                    >
                        التفاصيل
                    </button>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="group-card">
        <div class="group-head">
            <h2>المصروفات</h2>
        </div>
        <div class="metrics-grid">
            <?php foreach ($expenseSections as $section): ?>
                <article class="metric-card expense-card">
                    <div class="metric-head">
                        <div class="metric-icon"><?php echo $section['icon']; ?></div>
                        <h3><?php echo academyHtmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    </div>
                    <div class="metric-values">
                        <div class="value-box">
                            <span><?php echo academyHtmlspecialchars($section['count_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <strong><?php echo (int) $section['count']; ?></strong>
                        </div>
                        <div class="value-box value-box-money">
                            <span><?php echo academyHtmlspecialchars($section['amount_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <strong><?php echo academyHtmlspecialchars(formatReportsMoney((float) $section['total']), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
                        </div>
                    </div>
                    <?php if (!empty($section['secondary_money_summaries'])): ?>
                        <div class="money-breakdown-grid">
                            <?php foreach ($section['secondary_money_summaries'] as $summary): ?>
                                <div class="money-breakdown-box">
                                    <span><?php echo academyHtmlspecialchars('إجمالي ' . $summary['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <strong><?php echo academyHtmlspecialchars(formatReportsMoney((float) $summary['total']), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <button
                        type="button"
                        class="details-btn expense-btn"
                        data-modal-target="modal-<?php echo academyHtmlspecialchars($section['key'], ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo empty($section['rows']) ? 'disabled' : ''; ?>
                    >
                        التفاصيل
                    </button>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<?php foreach ($sections as $section): ?>
    <div class="details-modal" id="modal-<?php echo academyHtmlspecialchars($section['key'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2><?php echo academyHtmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <button type="button" class="modal-close" data-close-modal>✕</button>
            </div>
            <?php if (!empty($section['money_summaries'])): ?>
                <div class="modal-summary-grid">
                    <?php foreach ($section['money_summaries'] as $summary): ?>
                        <div class="modal-summary-card">
                            <span><?php echo academyHtmlspecialchars('إجمالي ' . $summary['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <strong><?php echo academyHtmlspecialchars(formatReportsMoney((float) $summary['total']), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="modal-table-wrap">
                <table class="details-table">
                    <thead>
                    <tr>
                        <?php foreach ($section['columns'] as $column): ?>
                            <th><?php echo academyHtmlspecialchars($column['label'], ENT_QUOTES, 'UTF-8'); ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($section['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($section['columns'] as $column): ?>
                                <td data-label="<?php echo academyHtmlspecialchars($column['label'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo academyHtmlspecialchars(formatReportsCellValue($row, $column), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script src="assets/js/reports.js"></script>
</body>
</html>
