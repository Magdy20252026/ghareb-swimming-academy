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

function fetchCloseCycleRows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $exception) {
        error_log('Failed to load close cycle data: ' . $exception->getMessage());
        return [];
    }
}

function sumCloseCycleColumn(array $rows, string $key): float
{
    $total = 0.0;

    foreach ($rows as $row) {
        $total += (float) ($row[$key] ?? 0);
    }

    return $total;
}

function countDistinctCloseCycleColumn(array $rows, string $key): int
{
    $values = [];

    foreach ($rows as $row) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
            $values[$value] = true;
        }
    }

    return count($values);
}

function sortCloseCycleRowsByEvent(array $rows): array
{
    usort($rows, static function (array $left, array $right): int {
        $leftTimestamp = strtotime((string) ($left['event_at'] ?? '')) ?: 0;
        $rightTimestamp = strtotime((string) ($right['event_at'] ?? '')) ?: 0;

        return $rightTimestamp <=> $leftTimestamp;
    });

    return $rows;
}

function formatCloseCycleMoney(float $value): string
{
    return number_format($value, 2, '.', ',');
}

function formatCloseCycleDate(string $value): string
{
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '-';
    }

    return date('Y-m-d', $timestamp);
}

function formatCloseCyclePeriodRange(?string $startDate, ?string $endDate): string
{
    $startDate = $startDate !== null ? trim($startDate) : '';
    $endDate = $endDate !== null ? trim($endDate) : '';

    if ($startDate === '' && $endDate === '') {
        return '-';
    }

    if ($startDate !== '' && $endDate !== '' && $startDate !== $endDate) {
        return formatCloseCycleDate($startDate) . ' - ' . formatCloseCycleDate($endDate);
    }

    return formatCloseCycleDate($endDate !== '' ? $endDate : $startDate);
}

function formatCloseCycleCellValue(array $row, array $column): string
{
    $value = $row[$column['key']] ?? '';
    $type = $column['type'] ?? 'text';

    if ($type === 'money') {
        return formatCloseCycleMoney((float) $value) . ' ج.م';
    }

    if ($type === 'period') {
        return formatCloseCyclePeriodRange(
            isset($row['period_start']) ? (string) $row['period_start'] : null,
            isset($row['period_end']) ? (string) $row['period_end'] : null
        );
    }

    $text = trim((string) $value);
    return $text !== '' ? $text : '-';
}

function buildCloseCycleMoneySummaries(array $rows, array $columns): array
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
            'total' => sumCloseCycleColumn($rows, $columnKey),
        ];
    }

    return $summaries;
}

function excludeCloseCycleSummaryByKey(array $summaries, string $summaryKey): array
{
    if ($summaryKey === '') {
        return $summaries;
    }

    return array_values(array_filter(
        $summaries,
        static fn(array $summary): bool => ($summary['key'] ?? '') !== $summaryKey
    ));
}

function parseCloseCycleDay(?string $value, DateTimeZone $timezone, DateTimeImmutable $today): DateTimeImmutable
{
    $value = trim((string) $value);
    if ($value !== '') {
        $selectedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $isValidDate = $selectedDate instanceof DateTimeImmutable && $selectedDate->format('Y-m-d') === $value;
        $isPastOrToday = $selectedDate instanceof DateTimeImmutable && $selectedDate <= $today;

        if ($isValidDate && $isPastOrToday) {
            return $selectedDate;
        }
    }

    return $today;
}

function parseCloseCycleWeek(?string $value, DateTimeZone $timezone, DateTimeImmutable $today): array
{
    $currentWeekValue = $today->format('o') . '-W' . $today->format('W');
    $weekValue = trim((string) $value);

    if (!preg_match('/^(\d{4})-W(\d{2})$/', $weekValue, $matches)) {
        $weekValue = $currentWeekValue;
        preg_match('/^(\d{4})-W(\d{2})$/', $weekValue, $matches);
    }

    $year = isset($matches[1]) ? (int) $matches[1] : (int) $today->format('o');
    $week = isset($matches[2]) ? (int) $matches[2] : (int) $today->format('W');

    $weekStart = (new DateTimeImmutable('now', $timezone))->setISODate($year, $week, 1)->setTime(0, 0, 0);
    if ($weekStart > $today) {
        $weekValue = $currentWeekValue;
        $year = (int) $today->format('o');
        $week = (int) $today->format('W');
        $weekStart = (new DateTimeImmutable('now', $timezone))->setISODate($year, $week, 1)->setTime(0, 0, 0);
    }

    $weekEnd = $weekValue === $currentWeekValue
        ? $today
        : $weekStart->modify('+6 days');

    return [
        'value' => $weekValue,
        'start' => $weekStart,
        'end' => $weekEnd,
    ];
}

function parseCloseCycleMonth(?string $value, DateTimeZone $timezone, DateTimeImmutable $today): array
{
    $currentMonthValue = $today->format('Y-m');
    $monthValue = trim((string) $value);
    $monthStart = null;

    if ($monthValue !== '') {
        $monthStart = DateTimeImmutable::createFromFormat('!Y-m', $monthValue, $timezone);
        if (!($monthStart instanceof DateTimeImmutable) || $monthStart->format('Y-m') !== $monthValue) {
            $monthStart = null;
        }
    }

    if (!($monthStart instanceof DateTimeImmutable) || $monthStart > $today) {
        $monthValue = $currentMonthValue;
        $monthStart = DateTimeImmutable::createFromFormat('!Y-m', $monthValue, $timezone);
    }

    $monthEnd = $monthValue === $currentMonthValue
        ? $today
        : $monthStart->modify('last day of this month');

    return [
        'value' => $monthValue,
        'start' => $monthStart,
        'end' => $monthEnd,
    ];
}

$cycleDefinitions = [
    'daily' => [
        'key' => 'daily_close',
        'title' => 'التقفيل اليومي',
        'period_label' => 'اليوم',
        'input_name' => 'date',
        'input_type' => 'date',
    ],
    'weekly' => [
        'key' => 'weekly_close',
        'title' => 'التقفيل الأسبوعي',
        'period_label' => 'الأسبوع',
        'input_name' => 'week',
        'input_type' => 'week',
    ],
    'monthly' => [
        'key' => 'monthly_close',
        'title' => 'التقفيل الشهري',
        'period_label' => 'الشهر',
        'input_name' => 'month',
        'input_type' => 'month',
    ],
];

$requestedCycle = strtolower(trim((string) ($_GET['cycle'] ?? 'daily')));
if (!isset($cycleDefinitions[$requestedCycle])) {
    $requestedCycle = 'daily';
}

$cycle = $cycleDefinitions[$requestedCycle];

if (!userCanAccess($currentUser, $cycle['key'])) {
    header('Location: dashboard.php?access=denied');
    exit;
}

$timezoneName = date_default_timezone_get();
if (!is_string($timezoneName) || $timezoneName === '') {
    $timezoneName = 'UTC';
}

$timezone = new DateTimeZone($timezoneName);
$today = new DateTimeImmutable('today', $timezone);
$currentWeekValue = $today->format('o') . '-W' . $today->format('W');
$currentMonthValue = $today->format('Y-m');
$filterValue = $today->format('Y-m-d');
$filterMax = $today->format('Y-m-d');
$periodStartDate = $today;
$periodEndDate = $today;

if ($requestedCycle === 'weekly') {
    $weekSelection = parseCloseCycleWeek($_GET['week'] ?? null, $timezone, $today);
    $filterValue = $weekSelection['value'];
    $filterMax = $currentWeekValue;
    $periodStartDate = $weekSelection['start'];
    $periodEndDate = $weekSelection['end'];
} elseif ($requestedCycle === 'monthly') {
    $monthSelection = parseCloseCycleMonth($_GET['month'] ?? null, $timezone, $today);
    $filterValue = $monthSelection['value'];
    $filterMax = $currentMonthValue;
    $periodStartDate = $monthSelection['start'];
    $periodEndDate = $monthSelection['end'];
} else {
    $selectedDate = parseCloseCycleDay($_GET['date'] ?? null, $timezone, $today);
    $filterValue = $selectedDate->format('Y-m-d');
    $periodStartDate = $selectedDate;
    $periodEndDate = $selectedDate;
}

$periodStartDateTime = $periodStartDate->setTime(0, 0, 0)->format('Y-m-d H:i:s');
$periodEndDateTime = $periodEndDate->setTime(23, 59, 59)->format('Y-m-d H:i:s');
$periodStartValue = $periodStartDate->format('Y-m-d');
$periodEndValue = $periodEndDate->format('Y-m-d');
$displayRange = formatCloseCyclePeriodRange($periodStartValue, $periodEndValue);

$siteSettings = getSiteSettings($pdo);
$academyName = $siteSettings['academy_name'];

$regularRegistrationRows = fetchCloseCycleRows(
    $pdo,
    "SELECT
        ap.id,
        ap.player_name,
        COALESCE(NULLIF(reg.subscription_name_snapshot, ''), NULLIF(ap.subscription_name, ''), '-') AS subscription_name,
        COALESCE(reg.subscription_amount_snapshot, ap.subscription_amount, 0) AS subscription_amount,
        COALESCE(reg.amount, ap.paid_amount, 0) AS paid_amount,
        COALESCE(
            reg.remaining_amount_after_snapshot,
            GREATEST(COALESCE(reg.subscription_amount_snapshot, ap.subscription_amount, 0) - COALESCE(reg.amount, ap.paid_amount, 0), 0)
        ) AS remaining_amount,
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
     WHERE ap.academy_id = ? AND ap.created_at BETWEEN ? AND ?
     ORDER BY ap.created_at DESC, ap.id DESC",
    [REGULAR_ACADEMY_ID, $periodStartDateTime, $periodEndDateTime]
);

$regularSettlementRows = fetchCloseCycleRows(
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
     WHERE ap.academy_id = ?
       AND pay.payment_type = 'settlement'
       AND pay.created_at BETWEEN ? AND ?
     ORDER BY pay.created_at DESC, pay.id DESC",
    [REGULAR_ACADEMY_ID, $periodStartDateTime, $periodEndDateTime]
);

$regularRenewalPaymentRows = fetchCloseCycleRows(
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
     WHERE ap.academy_id = ?
       AND pay.payment_type = 'renewal'
       AND pay.created_at BETWEEN ? AND ?",
    [REGULAR_ACADEMY_ID, $periodStartDateTime, $periodEndDateTime]
);

$regularRenewalFallbackRows = fetchCloseCycleRows(
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
     WHERE ap.academy_id = ?
       AND ap.last_renewed_at BETWEEN ? AND ?
       AND NOT EXISTS (
            SELECT 1
            FROM academy_player_payments pay
            WHERE pay.player_id = ap.id
              AND pay.payment_type = 'renewal'
              AND pay.created_at BETWEEN ? AND ?
       )",
    [REGULAR_ACADEMY_ID, $periodStartDateTime, $periodEndDateTime, $periodStartDateTime, $periodEndDateTime]
);

$academyRegistrationRows = fetchCloseCycleRows(
    $pdo,
    "SELECT
        ap.id,
        ap.player_name,
        COALESCE(NULLIF(reg.subscription_name_snapshot, ''), NULLIF(a.academy_name, ''), NULLIF(ap.subscription_name, ''), '-') AS academy_name,
        COALESCE(reg.subscription_amount_snapshot, ap.subscription_amount, 0) AS subscription_amount,
        COALESCE(reg.amount, ap.paid_amount, 0) AS paid_amount,
        COALESCE(
            reg.remaining_amount_after_snapshot,
            GREATEST(COALESCE(reg.subscription_amount_snapshot, ap.subscription_amount, 0) - COALESCE(reg.amount, ap.paid_amount, 0), 0)
        ) AS remaining_amount,
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

$academySettlementRows = fetchCloseCycleRows(
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

$academyRenewalPaymentRows = fetchCloseCycleRows(
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

$academyRenewalFallbackRows = fetchCloseCycleRows(
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

$coachSalaryRows = fetchCloseCycleRows(
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

$adminSalaryRows = fetchCloseCycleRows(
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

$expenseRows = fetchCloseCycleRows(
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

$salesInvoiceRows = fetchCloseCycleRows(
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
$salesInvoiceRows = attachSalesInvoiceDetails($pdo, $salesInvoiceRows, 'formatCloseCycleMoney');

$salesSettlementRows = fetchCloseCycleRows(
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

$regularRenewalRows = sortCloseCycleRowsByEvent(array_merge($regularRenewalPaymentRows, $regularRenewalFallbackRows));
$academyRenewalRows = sortCloseCycleRowsByEvent(array_merge($academyRenewalPaymentRows, $academyRenewalFallbackRows));

$sections = [
    [
        'key' => 'regular-registration',
        'title' => 'السباحين الجدد',
        'icon' => '🏊',
        'count_label' => 'عدد السباحين',
        'amount_label' => 'مجموع المبالغ',
        'total_key' => 'paid_amount',
        'count' => count($regularRegistrationRows),
        'total' => sumCloseCycleColumn($regularRegistrationRows, 'paid_amount'),
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
        'total' => sumCloseCycleColumn($regularSettlementRows, 'paid_amount'),
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
        'total' => sumCloseCycleColumn($regularRenewalRows, 'paid_amount'),
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
        'title' => 'لاعبي الأكاديميات الجدد',
        'icon' => '🌊',
        'count_label' => 'عدد السباحين',
        'amount_label' => 'مجموع المبالغ',
        'total_key' => 'paid_amount',
        'count' => count($academyRegistrationRows),
        'total' => sumCloseCycleColumn($academyRegistrationRows, 'paid_amount'),
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
        'total' => sumCloseCycleColumn($academySettlementRows, 'paid_amount'),
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
        'total' => sumCloseCycleColumn($academyRenewalRows, 'paid_amount'),
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
        'total' => sumCloseCycleColumn($salesInvoiceRows, 'total_amount'),
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
        'total' => sumCloseCycleColumn($salesSettlementRows, 'paid_amount'),
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
        'count' => countDistinctCloseCycleColumn($coachSalaryRows, 'coach_id'),
        'total' => sumCloseCycleColumn($coachSalaryRows, 'net_amount'),
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
        'count' => countDistinctCloseCycleColumn($adminSalaryRows, 'administrator_id'),
        'total' => sumCloseCycleColumn($adminSalaryRows, 'net_amount'),
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
        'total' => sumCloseCycleColumn($expenseRows, 'amount'),
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
    $moneySummaries = buildCloseCycleMoneySummaries($section['rows'], $section['columns']);
    $sections[$sectionIndex]['money_summaries'] = $moneySummaries;
    $sections[$sectionIndex]['secondary_money_summaries'] = excludeCloseCycleSummaryByKey(
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
    <title><?php echo htmlspecialchars($cycle['title'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars($academyName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="assets/css/close-cycle.css">
</head>
<body class="light-mode">
<div class="reports-page close-cycle-page">
    <header class="page-header">
        <div class="header-text">
            <h1><?php echo htmlspecialchars($cycle['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
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

    <section class="period-toolbar close-cycle-toolbar">
        <div class="close-cycle-controls">
            <form method="get" class="cycle-filter-form">
                <input type="hidden" name="cycle" value="<?php echo htmlspecialchars($requestedCycle, ENT_QUOTES, 'UTF-8'); ?>">
                <input
                    type="<?php echo htmlspecialchars($cycle['input_type'], ENT_QUOTES, 'UTF-8'); ?>"
                    name="<?php echo htmlspecialchars($cycle['input_name'], ENT_QUOTES, 'UTF-8'); ?>"
                    value="<?php echo htmlspecialchars($filterValue, ENT_QUOTES, 'UTF-8'); ?>"
                    max="<?php echo htmlspecialchars($filterMax, ENT_QUOTES, 'UTF-8'); ?>"
                    class="cycle-filter-input"
                >
                <button type="submit" class="apply-btn">عرض</button>
            </form>
        </div>
        <div class="period-range"><?php echo htmlspecialchars($displayRange, ENT_QUOTES, 'UTF-8'); ?></div>
    </section>

    <section class="hero-grid">
        <article class="hero-card hero-card-main">
            <span>الإجمالي</span>
            <strong><?php echo htmlspecialchars(formatCloseCycleMoney($netTotal), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
        </article>
        <article class="hero-card">
            <span>الإيرادات</span>
            <strong><?php echo htmlspecialchars(formatCloseCycleMoney($revenueTotal), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
        </article>
        <article class="hero-card">
            <span>المصروفات</span>
            <strong><?php echo htmlspecialchars(formatCloseCycleMoney($expenseTotal), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
        </article>
        <article class="hero-card">
            <span><?php echo htmlspecialchars($cycle['period_label'], ENT_QUOTES, 'UTF-8'); ?></span>
            <strong><?php echo htmlspecialchars($displayRange, ENT_QUOTES, 'UTF-8'); ?></strong>
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
                        <h3><?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    </div>
                    <div class="metric-values">
                        <div class="value-box">
                            <span><?php echo htmlspecialchars($section['count_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <strong><?php echo (int) $section['count']; ?></strong>
                        </div>
                        <div class="value-box value-box-money">
                            <span><?php echo htmlspecialchars($section['amount_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <strong><?php echo htmlspecialchars(formatCloseCycleMoney((float) $section['total']), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
                        </div>
                    </div>
                    <?php if (!empty($section['secondary_money_summaries'])): ?>
                        <div class="money-breakdown-grid">
                            <?php foreach ($section['secondary_money_summaries'] as $summary): ?>
                                <div class="money-breakdown-box">
                                    <span><?php echo htmlspecialchars('إجمالي ' . $summary['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <strong><?php echo htmlspecialchars(formatCloseCycleMoney((float) $summary['total']), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <button
                        type="button"
                        class="details-btn"
                        data-modal-target="modal-<?php echo htmlspecialchars($section['key'], ENT_QUOTES, 'UTF-8'); ?>"
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
                        <h3><?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    </div>
                    <div class="metric-values">
                        <div class="value-box">
                            <span><?php echo htmlspecialchars($section['count_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <strong><?php echo (int) $section['count']; ?></strong>
                        </div>
                        <div class="value-box value-box-money">
                            <span><?php echo htmlspecialchars($section['amount_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <strong><?php echo htmlspecialchars(formatCloseCycleMoney((float) $section['total']), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
                        </div>
                    </div>
                    <?php if (!empty($section['secondary_money_summaries'])): ?>
                        <div class="money-breakdown-grid">
                            <?php foreach ($section['secondary_money_summaries'] as $summary): ?>
                                <div class="money-breakdown-box">
                                    <span><?php echo htmlspecialchars('إجمالي ' . $summary['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <strong><?php echo htmlspecialchars(formatCloseCycleMoney((float) $summary['total']), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <button
                        type="button"
                        class="details-btn expense-btn"
                        data-modal-target="modal-<?php echo htmlspecialchars($section['key'], ENT_QUOTES, 'UTF-8'); ?>"
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
    <div class="details-modal" id="modal-<?php echo htmlspecialchars($section['key'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2><?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <button type="button" class="modal-close" data-close-modal>✕</button>
            </div>
            <?php if (!empty($section['money_summaries'])): ?>
                <div class="modal-summary-grid">
                    <?php foreach ($section['money_summaries'] as $summary): ?>
                        <div class="modal-summary-card">
                            <span><?php echo htmlspecialchars('إجمالي ' . $summary['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <strong><?php echo htmlspecialchars(formatCloseCycleMoney((float) $summary['total']), ENT_QUOTES, 'UTF-8'); ?> ج.م</strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="modal-table-wrap">
                <table class="details-table">
                    <thead>
                    <tr>
                        <?php foreach ($section['columns'] as $column): ?>
                            <th><?php echo htmlspecialchars($column['label'], ENT_QUOTES, 'UTF-8'); ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($section['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($section['columns'] as $column): ?>
                                <td data-label="<?php echo htmlspecialchars($column['label'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars(formatCloseCycleCellValue($row, $column), ENT_QUOTES, 'UTF-8'); ?>
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
