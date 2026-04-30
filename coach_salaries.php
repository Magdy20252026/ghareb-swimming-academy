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

if (!userCanAccess($currentUser, "coach_salaries")) {
    header("Location: dashboard.php?access=denied");
    exit;
}

const COACH_SALARY_PAYMENT_CYCLES = [
    'weekly' => 'أسبوعي',
    'monthly' => 'شهري',
];
const COACH_SALARY_PAYMENT_STATUSES = [
    'pending' => 'مستحق محفوظ',
    'paid' => 'تم الصرف',
];

function buildCoachSalariesPageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return 'coach_salaries.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function generateCoachSalarySecurityToken(): string
{
    try {
        return bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $isStrong = false;
            $randomBytes = openssl_random_pseudo_bytes(32, $isStrong);
            if ($randomBytes !== false && $isStrong) {
                return bin2hex($randomBytes);
            }
        }
    }

    throw new RuntimeException('تعذر إنشاء رمز أمان لقبض رواتب المدربين');
}

function getCoachSalaryCsrfToken(): string
{
    if (
        !isset($_SESSION['coach_salary_csrf_token'])
        || !is_string($_SESSION['coach_salary_csrf_token'])
        || $_SESSION['coach_salary_csrf_token'] === ''
    ) {
        try {
            $_SESSION['coach_salary_csrf_token'] = generateCoachSalarySecurityToken();
        } catch (Throwable $exception) {
            error_log('تعذر إنشاء رمز التحقق الخاص بقبض رواتب المدربين');
            http_response_code(500);
            exit('❌ تعذر تهيئة أمان الصفحة، يرجى إعادة المحاولة لاحقًا');
        }
    }

    return $_SESSION['coach_salary_csrf_token'];
}

function isValidCoachSalaryCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getCoachSalaryCsrfToken(), $submittedToken);
}

function setCoachSalaryFlash(string $message, string $type): void
{
    $_SESSION['coach_salary_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumeCoachSalaryFlash(): array
{
    $flash = $_SESSION['coach_salary_flash'] ?? null;
    unset($_SESSION['coach_salary_flash']);

    if (!is_array($flash)) {
        return [
            'message' => '',
            'type' => '',
        ];
    }

    return [
        'message' => (string) ($flash['message'] ?? ''),
        'type' => (string) ($flash['type'] ?? ''),
    ];
}

function formatCoachSalaryAmount(float $value): string
{
    return number_format($value, 2, '.', '');
}

function applyCoachSalaryPeriodBounds(array $totals, ?string $periodStart, ?string $periodEnd): array
{
    if ($periodStart !== null && $periodEnd !== null) {
        $totals['period_start'] = $periodStart;
        $totals['period_end'] = $periodEnd;
    }

    return $totals;
}

function formatCoachSalaryTimestamp(?string $value): string
{
    $normalizedValue = trim((string) $value);
    if ($normalizedValue === '') {
        return '—';
    }

    $timestamp = strtotime($normalizedValue);
    if ($timestamp === false) {
        return '—';
    }

    return date('Y-m-d H:i', $timestamp);
}

function isValidCoachSalaryDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function normalizeCoachSalaryDateRange($startDate, $endDate): array
{
    $normalizedStartDate = trim((string) $startDate);
    $normalizedEndDate = trim((string) $endDate);

    if ($normalizedStartDate === '' && $normalizedEndDate === '') {
        return [
            'start' => null,
            'end' => null,
            'error' => '',
        ];
    }

    if ($normalizedStartDate === '' || $normalizedEndDate === '') {
        return [
            'start' => null,
            'end' => null,
            'error' => '❌ يرجى اختيار تاريخ البداية والنهاية معًا',
        ];
    }

    if (!isValidCoachSalaryDate($normalizedStartDate) || !isValidCoachSalaryDate($normalizedEndDate)) {
        return [
            'start' => null,
            'end' => null,
            'error' => '❌ يرجى إدخال فترة صحيحة بصيغة التاريخ المعتمدة',
        ];
    }

    if ($normalizedStartDate > $normalizedEndDate) {
        return [
            'start' => null,
            'end' => null,
            'error' => '❌ يجب أن يكون تاريخ البداية أقدم من أو يساوي تاريخ النهاية',
        ];
    }

    return [
        'start' => $normalizedStartDate,
        'end' => $normalizedEndDate,
        'error' => '',
    ];
}

function encodeCoachSalarySnapshot(array $rows): string
{
    $encodedRows = json_encode($rows, JSON_UNESCAPED_UNICODE);
    return is_string($encodedRows) && $encodedRows !== '' ? $encodedRows : '[]';
}

function fetchCoachSalaryAttendanceRows(PDO $pdo, int $coachId, ?string $periodStart = null, ?string $periodEnd = null): array
{
    $sql = "SELECT attendance_date, work_hours
            FROM coach_attendance
            WHERE coach_id = ?";
    $params = [$coachId];

    if ($periodStart !== null && $periodEnd !== null) {
        $sql .= " AND attendance_date BETWEEN ? AND ?";
        $params[] = $periodStart;
        $params[] = $periodEnd;
    }

    $sql .= " ORDER BY attendance_date ASC, id ASC";

    $attendanceStmt = $pdo->prepare($sql);
    $attendanceStmt->execute($params);

    return $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchCoachSalaryAdvanceRows(PDO $pdo, int $coachId, ?string $periodStart = null, ?string $periodEnd = null): array
{
    $sql = "SELECT advance_date, amount, created_at
            FROM coach_advances
            WHERE coach_id = ?";
    $params = [$coachId];

    if ($periodStart !== null && $periodEnd !== null) {
        $sql .= " AND advance_date BETWEEN ? AND ?";
        $params[] = $periodStart;
        $params[] = $periodEnd;
    }

    $sql .= " ORDER BY advance_date ASC, id ASC";

    $advancesStmt = $pdo->prepare($sql);
    $advancesStmt->execute($params);

    return $advancesStmt->fetchAll(PDO::FETCH_ASSOC);
}

function deleteCoachSalaryAttendanceRows(PDO $pdo, int $coachId, ?string $periodStart = null, ?string $periodEnd = null): void
{
    $sql = "DELETE FROM coach_attendance WHERE coach_id = ?";
    $params = [$coachId];

    if ($periodStart !== null && $periodEnd !== null) {
        $sql .= " AND attendance_date BETWEEN ? AND ?";
        $params[] = $periodStart;
        $params[] = $periodEnd;
    }

    $deleteStmt = $pdo->prepare($sql);
    $deleteStmt->execute($params);
}

function deleteCoachSalaryAdvanceRows(PDO $pdo, int $coachId, ?string $periodStart = null, ?string $periodEnd = null): void
{
    $sql = "DELETE FROM coach_advances WHERE coach_id = ?";
    $params = [$coachId];

    if ($periodStart !== null && $periodEnd !== null) {
        $sql .= " AND advance_date BETWEEN ? AND ?";
        $params[] = $periodStart;
        $params[] = $periodEnd;
    }

    $deleteStmt = $pdo->prepare($sql);
    $deleteStmt->execute($params);
}

function insertCoachSalaryPaymentRecord(
    PDO $pdo,
    int $coachId,
    string $paymentCycle,
    array $totals,
    float $hourlyRate,
    string $attendanceSnapshot,
    string $advancesSnapshot,
    string $paymentStatus,
    ?int $paidByUserId = null
): void {
    $insertStmt = $pdo->prepare(
        "INSERT INTO coach_salary_payments (
            coach_id,
            payment_cycle,
            period_start,
            period_end,
            total_hours,
            hourly_rate,
            gross_amount,
            total_advances,
            net_amount,
            attendance_days,
            advance_records_count,
            payment_status,
            paid_by_user_id,
            attendance_snapshot,
            advances_snapshot,
            reserved_at,
            paid_at
        ) VALUES (
            :coach_id,
            :payment_cycle,
            :period_start,
            :period_end,
            :total_hours,
            :hourly_rate,
            :gross_amount,
            :total_advances,
            :net_amount,
            :attendance_days,
            :advance_records_count,
            :payment_status,
            :paid_by_user_id,
            :attendance_snapshot,
            :advances_snapshot,
            CURRENT_TIMESTAMP,
            CASE WHEN :paid_status = 'paid' THEN CURRENT_TIMESTAMP ELSE NULL END
        )"
    );
    $insertStmt->execute([
        ':coach_id' => $coachId,
        ':payment_cycle' => $paymentCycle,
        ':period_start' => $totals['period_start'],
        ':period_end' => $totals['period_end'],
        ':total_hours' => formatCoachSalaryAmount((float) $totals['total_hours']),
        ':hourly_rate' => formatCoachSalaryAmount($hourlyRate),
        ':gross_amount' => formatCoachSalaryAmount((float) $totals['gross_amount']),
        ':total_advances' => formatCoachSalaryAmount((float) $totals['total_advances']),
        ':net_amount' => formatCoachSalaryAmount((float) $totals['net_amount']),
        ':attendance_days' => (int) $totals['attendance_days'],
        ':advance_records_count' => (int) $totals['advance_records_count'],
        ':payment_status' => $paymentStatus,
        ':paid_by_user_id' => $paidByUserId,
        ':attendance_snapshot' => $attendanceSnapshot,
        ':advances_snapshot' => $advancesSnapshot,
        ':paid_status' => $paymentStatus,
    ]);
}

function calculateCoachSalaryTotals(array $attendanceRows, array $advanceRows, float $hourlyRate): array
{
    $totalHours = 0.0;
    $totalAdvances = 0.0;
    $periodDates = [];

    foreach ($attendanceRows as $attendanceRow) {
        $totalHours += (float) ($attendanceRow['work_hours'] ?? 0);
        $attendanceDate = (string) ($attendanceRow['attendance_date'] ?? '');
        if ($attendanceDate !== '') {
            $periodDates[] = $attendanceDate;
        }
    }

    foreach ($advanceRows as $advanceRow) {
        $totalAdvances += (float) ($advanceRow['amount'] ?? 0);
        $advanceDate = (string) ($advanceRow['advance_date'] ?? '');
        if ($advanceDate !== '') {
            $periodDates[] = $advanceDate;
        }
    }

    sort($periodDates);
    $grossAmount = $totalHours * $hourlyRate;
    $lastPeriodDateIndex = $periodDates === [] ? null : count($periodDates) - 1;

    return [
        'total_hours' => $totalHours,
        'total_advances' => $totalAdvances,
        'gross_amount' => $grossAmount,
        'net_amount' => $grossAmount - $totalAdvances,
        'attendance_days' => count($attendanceRows),
        'advance_records_count' => count($advanceRows),
        'period_start' => $periodDates[0] ?? null,
        'period_end' => $lastPeriodDateIndex === null ? null : $periodDates[$lastPeriodDateIndex],
    ];
}

function buildCoachSalaryDailyRecords(array $attendanceRows, array $advanceRows, float $hourlyRate): array
{
    $dailyRecords = [];

    foreach ($attendanceRows as $attendanceRow) {
        $date = (string) ($attendanceRow['attendance_date'] ?? '');
        if ($date === '') {
            continue;
        }

        if (!isset($dailyRecords[$date])) {
            $dailyRecords[$date] = [
                'date' => $date,
                'work_hours' => 0.0,
                'advance_amount' => 0.0,
                'attendance_status' => false,
                'daily_total' => 0.0,
                'daily_net_amount' => 0.0,
            ];
        }

        $dailyRecords[$date]['attendance_status'] = true;
        $dailyRecords[$date]['work_hours'] += (float) ($attendanceRow['work_hours'] ?? 0);
    }

    foreach ($advanceRows as $advanceRow) {
        $date = (string) ($advanceRow['advance_date'] ?? '');
        if ($date === '') {
            continue;
        }

        if (!isset($dailyRecords[$date])) {
            $dailyRecords[$date] = [
                'date' => $date,
                'work_hours' => 0.0,
                'advance_amount' => 0.0,
                'attendance_status' => false,
                'daily_total' => 0.0,
                'daily_net_amount' => 0.0,
            ];
        }

        $dailyRecords[$date]['advance_amount'] += (float) ($advanceRow['amount'] ?? 0);
    }

    foreach ($dailyRecords as $date => $dailyRecord) {
        $dailyRecords[$date]['daily_total'] = $dailyRecord['work_hours'] * $hourlyRate;
        $dailyRecords[$date]['daily_net_amount'] = $dailyRecords[$date]['daily_total'] - $dailyRecord['advance_amount'];
    }

    krsort($dailyRecords);
    return array_values($dailyRecords);
}

$flashMessage = consumeCoachSalaryFlash();
$message = $flashMessage['message'];
$messageType = $flashMessage['type'];
$selectedCoachId = trim($_GET['coach_id'] ?? '');
$selectedPeriodStartInput = trim($_GET['period_start'] ?? '');
$selectedPeriodEndInput = trim($_GET['period_end'] ?? '');
$formPaymentCycle = 'monthly';
$dateRange = normalizeCoachSalaryDateRange($selectedPeriodStartInput, $selectedPeriodEndInput);
$selectedPeriodStart = $dateRange['start'];
$selectedPeriodEnd = $dateRange['end'];

if ($dateRange['error'] !== '' && $message === '') {
    $message = $dateRange['error'];
    $messageType = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $selectedCoachId = trim($_POST['coach_id'] ?? $selectedCoachId);
    $formPaymentCycle = trim($_POST['payment_cycle'] ?? $formPaymentCycle);
    $selectedPeriodStartInput = trim($_POST['period_start'] ?? $selectedPeriodStartInput);
    $selectedPeriodEndInput = trim($_POST['period_end'] ?? $selectedPeriodEndInput);
    $dateRange = normalizeCoachSalaryDateRange($selectedPeriodStartInput, $selectedPeriodEndInput);
    $selectedPeriodStart = $dateRange['start'];
    $selectedPeriodEnd = $dateRange['end'];

    if ($action !== '' && !isValidCoachSalaryCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = '❌ انتهت صلاحية الطلب، يرجى إعادة المحاولة';
        $messageType = 'error';
        $action = '';
    }

    if ($action === 'reserve' || $action === 'pay_current') {
        if ($selectedCoachId === '' || ctype_digit($selectedCoachId) === false) {
            $message = '❌ يرجى اختيار المدرب من القائمة';
            $messageType = 'error';
        } elseif (!array_key_exists($formPaymentCycle, COACH_SALARY_PAYMENT_CYCLES)) {
            $message = '❌ يرجى اختيار نوع القبض';
            $messageType = 'error';
        } elseif ($dateRange['error'] !== '') {
            $message = $dateRange['error'];
            $messageType = 'error';
        } elseif ($selectedPeriodStart === null || $selectedPeriodEnd === null) {
            $message = '❌ اختر الفترة المطلوب حجزها أو صرفها أولاً';
            $messageType = 'error';
        } else {
            try {
                $coachStmt = $pdo->prepare(
                    "SELECT id, full_name, phone, hourly_rate
                     FROM coaches
                     WHERE id = ?
                     LIMIT 1"
                );
                $coachStmt->execute([(int) $selectedCoachId]);
                $selectedCoach = $coachStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                if ($selectedCoach === null) {
                    $message = '❌ المدرب المطلوب غير موجود';
                    $messageType = 'error';
                } else {
                    $attendanceRows = fetchCoachSalaryAttendanceRows($pdo, (int) $selectedCoachId, $selectedPeriodStart, $selectedPeriodEnd);
                    $advanceRows = fetchCoachSalaryAdvanceRows($pdo, (int) $selectedCoachId, $selectedPeriodStart, $selectedPeriodEnd);

                    if ($attendanceRows === [] && $advanceRows === []) {
                        $message = '❌ لا توجد بيانات حالية لهذا المدرب داخل الفترة المحددة';
                        $messageType = 'error';
                    } else {
                        $hourlyRate = (float) ($selectedCoach['hourly_rate'] ?? 0);
                        $totals = applyCoachSalaryPeriodBounds(
                            calculateCoachSalaryTotals($attendanceRows, $advanceRows, $hourlyRate),
                            $selectedPeriodStart,
                            $selectedPeriodEnd
                        );
                        $attendanceSnapshot = encodeCoachSalarySnapshot($attendanceRows);
                        $advancesSnapshot = encodeCoachSalarySnapshot($advanceRows);
                        $paymentStatus = $action === 'reserve' ? 'pending' : 'paid';
                        $paidByUserId = $paymentStatus === 'paid' ? (int) ($currentUser['id'] ?? 0) : null;

                        $pdo->beginTransaction();

                        insertCoachSalaryPaymentRecord(
                            $pdo,
                            (int) $selectedCoachId,
                            $formPaymentCycle,
                            $totals,
                            $hourlyRate,
                            $attendanceSnapshot,
                            $advancesSnapshot,
                            $paymentStatus,
                            $paidByUserId
                        );

                        deleteCoachSalaryAttendanceRows($pdo, (int) $selectedCoachId, $selectedPeriodStart, $selectedPeriodEnd);
                        deleteCoachSalaryAdvanceRows($pdo, (int) $selectedCoachId, $selectedPeriodStart, $selectedPeriodEnd);

                        $pdo->commit();

                        $successMessage = $paymentStatus === 'pending'
                            ? '✅ تم حجز مستحق المدرب وحفظه للصرف لاحقًا مع تصفية بيانات الفترة المحددة'
                            : '✅ تم صرف مستحق المدرب عن الفترة المحددة وتصفية بياناتها بنجاح';
                        setCoachSalaryFlash($successMessage, 'success');
                        header('Location: ' . buildCoachSalariesPageUrl(['coach_id' => $selectedCoachId]));
                        exit;
                    }
                }
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = $action === 'reserve'
                    ? '❌ حدث خطأ أثناء حجز مستحق المدرب'
                    : '❌ حدث خطأ أثناء صرف مستحق المدرب';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'pay_pending') {
        $paymentId = trim($_POST['payment_id'] ?? '');

        if ($selectedCoachId === '' || ctype_digit($selectedCoachId) === false || $paymentId === '' || ctype_digit($paymentId) === false) {
            $message = '❌ تعذر تحديد المستحق المطلوب صرفه';
            $messageType = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $pendingPaymentStmt = $pdo->prepare(
                    "SELECT id
                     FROM coach_salary_payments
                     WHERE id = ? AND coach_id = ? AND payment_status = 'pending'
                     LIMIT 1
                     FOR UPDATE"
                );
                $pendingPaymentStmt->execute([(int) $paymentId, (int) $selectedCoachId]);
                $pendingPayment = $pendingPaymentStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                if ($pendingPayment === null) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $message = '❌ المستحق المطلوب غير موجود أو تم صرفه بالفعل';
                    $messageType = 'error';
                } else {
                    $payPendingStmt = $pdo->prepare(
                        "UPDATE coach_salary_payments
                         SET payment_status = 'paid',
                             paid_by_user_id = ?,
                             paid_at = CURRENT_TIMESTAMP
                         WHERE id = ?"
                    );
                    $payPendingStmt->execute([(int) ($currentUser['id'] ?? 0), (int) $paymentId]);

                    $pdo->commit();

                    setCoachSalaryFlash('✅ تم صرف المستحق المحجوز للمدرب بنجاح', 'success');
                    header('Location: ' . buildCoachSalariesPageUrl(['coach_id' => $selectedCoachId]));
                    exit;
                }
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = '❌ حدث خطأ أثناء صرف المستحق المحجوز';
                $messageType = 'error';
            }
        }
    }
}

$coachesStmt = $pdo->query(
    "SELECT
        c.id,
        c.full_name,
        c.phone,
        c.hourly_rate,
        COALESCE(att.total_hours, 0) AS total_hours,
        COALESCE(att.attendance_days, 0) AS attendance_days,
        COALESCE(att.last_attendance_date, '') AS last_attendance_date,
        COALESCE(adv.total_advances, 0) AS total_advances,
        COALESCE(adv.advance_records_count, 0) AS advance_records_count,
        COALESCE(adv.last_advance_date, '') AS last_advance_date
     FROM coaches c
     LEFT JOIN (
         SELECT
             coach_id,
             SUM(work_hours) AS total_hours,
             COUNT(*) AS attendance_days,
             MAX(attendance_date) AS last_attendance_date
         FROM coach_attendance
         GROUP BY coach_id
     ) att ON att.coach_id = c.id
     LEFT JOIN (
         SELECT
             coach_id,
             SUM(amount) AS total_advances,
             COUNT(*) AS advance_records_count,
             MAX(advance_date) AS last_advance_date
         FROM coach_advances
         GROUP BY coach_id
     ) adv ON adv.coach_id = c.id
     ORDER BY
         (COALESCE(att.total_hours, 0) > 0 OR COALESCE(adv.total_advances, 0) > 0) DESC,
         c.full_name ASC"
);
$coaches = $coachesStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCoaches = count($coaches);
$coachesReadyForPayment = 0;
$totalOutstandingHours = 0.0;
$totalOutstandingAdvances = 0.0;
$totalPayableSalariesAfterAdvances = 0.0;

foreach ($coaches as $coachRow) {
    $coachHours = (float) ($coachRow['total_hours'] ?? 0);
    $coachAdvances = (float) ($coachRow['total_advances'] ?? 0);
    $coachHourlyRate = (float) ($coachRow['hourly_rate'] ?? 0);
    $coachNetSalary = ($coachHours * $coachHourlyRate) - $coachAdvances;
    $totalOutstandingHours += $coachHours;
    $totalOutstandingAdvances += $coachAdvances;
    $totalPayableSalariesAfterAdvances += max($coachNetSalary, 0);

    if ($coachHours > 0 || $coachAdvances > 0) {
        $coachesReadyForPayment++;
    }
}

$selectedCoach = null;
foreach ($coaches as $coachRow) {
    if ((string) $coachRow['id'] === $selectedCoachId) {
        $selectedCoach = $coachRow;
        break;
    }
}

if ($selectedCoach === null && $coaches !== []) {
    foreach ($coaches as $coachRow) {
        if ((float) ($coachRow['total_hours'] ?? 0) > 0 || (float) ($coachRow['total_advances'] ?? 0) > 0) {
            $selectedCoach = $coachRow;
            break;
        }
    }

    if ($selectedCoach === null) {
        $selectedCoach = $coaches[0];
    }

    $selectedCoachId = (string) ($selectedCoach['id'] ?? '');
}

$selectedCoachAttendanceRows = [];
$selectedCoachAdvanceRows = [];
$selectedCoachDailyRecords = [];
$selectedCoachPendingPayments = [];
$selectedCoachHistory = [];
$selectedCoachPendingNetAmount = 0.0;
$selectedCoachTotals = [
    'total_hours' => 0.0,
    'total_advances' => 0.0,
    'gross_amount' => 0.0,
    'net_amount' => 0.0,
    'attendance_days' => 0,
    'advance_records_count' => 0,
    'period_start' => null,
    'period_end' => null,
];

if ($selectedCoach !== null) {
    $selectedCoachAttendanceRows = fetchCoachSalaryAttendanceRows($pdo, (int) $selectedCoach['id'], $selectedPeriodStart, $selectedPeriodEnd);
    $selectedCoachAdvanceRows = fetchCoachSalaryAdvanceRows($pdo, (int) $selectedCoach['id'], $selectedPeriodStart, $selectedPeriodEnd);
    $selectedCoachHasFilteredData = $selectedCoachAttendanceRows !== [] || $selectedCoachAdvanceRows !== [];
    $selectedCoachTotals = applyCoachSalaryPeriodBounds(
        calculateCoachSalaryTotals(
            $selectedCoachAttendanceRows,
            $selectedCoachAdvanceRows,
            (float) ($selectedCoach['hourly_rate'] ?? 0)
        ),
        $selectedCoachHasFilteredData ? $selectedPeriodStart : null,
        $selectedCoachHasFilteredData ? $selectedPeriodEnd : null
    );
    $selectedCoachDailyRecords = buildCoachSalaryDailyRecords(
        $selectedCoachAttendanceRows,
        $selectedCoachAdvanceRows,
        (float) ($selectedCoach['hourly_rate'] ?? 0)
    );

    $pendingStmt = $pdo->prepare(
        "SELECT
            p.id,
            p.payment_cycle,
            p.period_start,
            p.period_end,
            p.total_hours,
            p.hourly_rate,
            p.gross_amount,
            p.total_advances,
            p.net_amount,
            p.attendance_days,
            p.advance_records_count,
            p.reserved_at
         FROM coach_salary_payments p
         WHERE p.coach_id = ? AND p.payment_status = 'pending'
         ORDER BY COALESCE(p.period_end, p.period_start) DESC, p.id DESC"
    );
    $pendingStmt->execute([(int) $selectedCoach['id']]);
    $selectedCoachPendingPayments = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($selectedCoachPendingPayments as $pendingPayment) {
        $selectedCoachPendingNetAmount += (float) ($pendingPayment['net_amount'] ?? 0);
    }

    $historyStmt = $pdo->prepare(
        "SELECT
            p.id,
            p.payment_cycle,
            p.period_start,
            p.period_end,
            p.total_hours,
            p.hourly_rate,
            p.gross_amount,
            p.total_advances,
            p.net_amount,
            p.attendance_days,
            p.advance_records_count,
            p.payment_status,
            p.reserved_at,
            p.paid_at,
            u.username AS paid_by_username
         FROM coach_salary_payments p
         LEFT JOIN users u ON u.id = p.paid_by_user_id
         WHERE p.coach_id = ? AND p.payment_status = 'paid'
         ORDER BY p.paid_at DESC, p.id DESC"
    );
    $historyStmt->execute([(int) $selectedCoach['id']]);
    $selectedCoachHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
}

$coachSalaryCsrfToken = getCoachSalaryCsrfToken();
$canViewSalaryTotals = userCanAccess($currentUser, 'coach_salaries');
$canViewHourlyRate = (($currentUser['role'] ?? '') === 'مدير');
$hasCoaches = !empty($coaches);
$hasSelectedCoachData = $selectedCoach !== null && ($selectedCoachAttendanceRows !== [] || $selectedCoachAdvanceRows !== []);
$historyColspan = 8 + ($canViewSalaryTotals ? 2 : 0) + ($canViewHourlyRate ? 1 : 0);
$pendingColspan = 8 + ($canViewSalaryTotals ? 2 : 0) + ($canViewHourlyRate ? 1 : 0);
$dailyColspan = $canViewSalaryTotals ? 7 : 5;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>قبض رواتب المدربين</title>
    <link rel="stylesheet" href="assets/css/coach-salaries.css">
</head>
<body
    class="light-mode"
    data-reset-url="<?php echo htmlspecialchars(buildCoachSalariesPageUrl(), ENT_QUOTES, 'UTF-8'); ?>"
    data-default-confirm-message="هل أنت متأكد من تنفيذ القبض الآن؟"
>
<div class="coach-salaries-page">
    <header class="page-header">
        <div class="header-text">
            <span class="section-badge">💰 قبض رواتب المدربين</span>
            <h1>قبض رواتب المدربين</h1>
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

            <a href="dashboard.php" class="back-btn">⬅️ الرجوع للوحة التحكم</a>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div class="message-box <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <section class="stats-grid">
        <article class="stat-card total-card">
            <div class="stat-icon">👥</div>
            <div>
                <h2><?php echo $totalCoaches; ?></h2>
                <p>إجمالي المدربين</p>
            </div>
        </article>

        <article class="stat-card present-card">
            <div class="stat-icon">✅</div>
            <div>
                <h2><?php echo $coachesReadyForPayment; ?></h2>
                <p>مدربون جاهزون للقبض</p>
            </div>
        </article>

        <article class="stat-card absent-card salary-stat-card">
            <div class="stat-icon">⏱️</div>
            <div>
                <h2><?php echo htmlspecialchars(number_format($totalOutstandingHours, 2), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p>إجمالي الساعات الحالية</p>
            </div>
        </article>

        <article class="stat-card hours-card salary-stat-card">
            <div class="stat-icon">💵</div>
            <div>
                <h2><?php echo htmlspecialchars(number_format($totalOutstandingAdvances, 2), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p>إجمالي السلف الحالية</p>
            </div>
        </article>

        <article class="stat-card salary-total-card">
            <div class="stat-icon">🧾</div>
            <div>
                <h2><?php echo htmlspecialchars(number_format($totalPayableSalariesAfterAdvances, 2), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p>إجمالي المرتبات المطلوبة بعد خصم السلف</p>
            </div>
        </article>
    </section>

    <section class="content-grid coach-salaries-content-grid">
        <article class="form-card salary-control-card">
            <div class="card-head">
                <h2>👤 بيانات القبض</h2>
            </div>

            <form method="GET" class="attendance-form salary-filter-form" autocomplete="off">
                <div class="form-row form-row-single">
                    <div class="form-group">
                        <label>اختيار المدرب</label>
                        <input type="hidden" name="coach_id" id="coach_id_filter" value="<?php echo htmlspecialchars($selectedCoachId, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="professional-select" id="coachSelect" data-placeholder="اختر المدرب من القائمة">
                            <button type="button" class="select-trigger" id="coachSelectTrigger" aria-expanded="false">
                                <span class="select-trigger-text" id="coachSelectText">
                                    <?php echo $selectedCoach ? htmlspecialchars($selectedCoach['full_name'], ENT_QUOTES, 'UTF-8') : 'اختر المدرب من القائمة'; ?>
                                </span>
                                <span class="select-trigger-icon">▾</span>
                            </button>

                            <div class="select-dropdown" id="coachSelectDropdown" hidden>
                                <div class="select-search-box">
                                    <input type="search" id="coachSearchInput" placeholder="ابحث باسم المدرب أو الهاتف">
                                </div>

                                <div class="select-options" id="coachOptionsList">
                                    <?php foreach ($coaches as $coach): ?>
                                        <?php
                                            $coachHasData = (float) ($coach['total_hours'] ?? 0) > 0 || (float) ($coach['total_advances'] ?? 0) > 0;
                                        ?>
                                        <button
                                            type="button"
                                            class="select-option<?php echo (string) $coach['id'] === $selectedCoachId ? ' is-selected' : ''; ?>"
                                            data-value="<?php echo htmlspecialchars((string) $coach['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-name="<?php echo htmlspecialchars($coach['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-phone="<?php echo htmlspecialchars($coach['phone'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-hours="<?php echo htmlspecialchars(number_format((float) $coach['total_hours'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-advances="<?php echo htmlspecialchars(number_format((float) $coach['total_advances'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-status="<?php echo $coachHasData ? 'جاهز للقبض' : 'لا توجد بيانات حالية'; ?>"
                                        >
                                            <span class="option-name"><?php echo htmlspecialchars($coach['full_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="option-meta">
                                                📞 <?php echo htmlspecialchars($coach['phone'], ENT_QUOTES, 'UTF-8'); ?>
                                                • ⏱️ <?php echo htmlspecialchars(number_format((float) $coach['total_hours'], 2), ENT_QUOTES, 'UTF-8'); ?>
                                                • 💵 <?php echo htmlspecialchars(number_format((float) $coach['total_advances'], 2), ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>

                                <p class="select-empty" id="coachSelectEmpty"<?php echo $hasCoaches ? ' hidden' : ''; ?>>لا يوجد مدربون مسجلون حالياً.</p>
                            </div>
                        </div>

                        <div class="selected-coach-meta salary-selected-meta" id="selectedCoachMeta">
                            <?php if ($selectedCoach): ?>
                                <span>📞 <?php echo htmlspecialchars($selectedCoach['phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span>⏱️ <?php echo htmlspecialchars(number_format((float) $selectedCoach['total_hours'], 2), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span>💵 <?php echo htmlspecialchars(number_format((float) $selectedCoach['total_advances'], 2), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="form-row salary-date-range-row">
                    <div class="form-group">
                        <label for="period_start">من تاريخ</label>
                        <input type="date" id="period_start" name="period_start" aria-label="تاريخ بداية فترة الحجز أو الصرف" value="<?php echo htmlspecialchars((string) $selectedPeriodStart, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="form-group">
                        <label for="period_end">إلى تاريخ</label>
                        <input type="date" id="period_end" name="period_end" aria-label="تاريخ نهاية فترة الحجز أو الصرف" value="<?php echo htmlspecialchars((string) $selectedPeriodEnd, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <div class="form-actions salary-filter-actions">
                    <button type="submit" class="filter-btn"<?php echo !$hasCoaches ? ' disabled' : ''; ?>>عرض البيانات</button>
                    <a href="<?php echo htmlspecialchars(buildCoachSalariesPageUrl(), ENT_QUOTES, 'UTF-8'); ?>" class="clear-btn" id="clearBtn">تحديث</a>
                </div>
            </form>

            <?php if ($selectedCoach !== null): ?>
                <div class="salary-summary-grid">
                    <article class="salary-summary-box">
                        <span>الحضور</span>
                        <strong><?php echo (int) $selectedCoachTotals['attendance_days']; ?> يوم</strong>
                    </article>
                    <article class="salary-summary-box">
                        <span>الساعات</span>
                        <strong><?php echo htmlspecialchars(number_format((float) $selectedCoachTotals['total_hours'], 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </article>
                    <article class="salary-summary-box">
                        <span>السلف</span>
                        <strong><?php echo htmlspecialchars(number_format((float) $selectedCoachTotals['total_advances'], 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </article>
                    <article class="salary-summary-box">
                        <span>سجلات السلف</span>
                        <strong><?php echo (int) $selectedCoachTotals['advance_records_count']; ?></strong>
                    </article>
                    <?php if ($canViewHourlyRate): ?>
                        <article class="salary-summary-box salary-summary-highlight">
                            <span>سعر الساعة</span>
                            <strong><?php echo htmlspecialchars(number_format((float) $selectedCoach['hourly_rate'], 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </article>
                    <?php endif; ?>
                    <?php if ($canViewSalaryTotals): ?>
                        <article class="salary-summary-box salary-summary-highlight">
                            <span>إجمالي الأجر</span>
                            <strong><?php echo htmlspecialchars(number_format((float) $selectedCoachTotals['gross_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </article>
                        <article class="salary-summary-box salary-summary-net <?php echo (float) $selectedCoachTotals['net_amount'] >= 0 ? 'is-positive' : 'is-negative'; ?>">
                            <span>صافي القبض</span>
                            <strong><?php echo htmlspecialchars(number_format((float) $selectedCoachTotals['net_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </article>
                        <article class="salary-summary-box salary-summary-highlight">
                            <span>إجمالي المستحقات المحجوزة</span>
                            <strong><?php echo htmlspecialchars(number_format($selectedCoachPendingNetAmount, 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </article>
                    <?php endif; ?>
                </div>

                <form method="POST" class="attendance-form salary-payment-form">
                    <input type="hidden" name="coach_id" value="<?php echo htmlspecialchars((string) $selectedCoach['id'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($coachSalaryCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="period_start" value="<?php echo htmlspecialchars((string) $selectedPeriodStart, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="period_end" value="<?php echo htmlspecialchars((string) $selectedPeriodEnd, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-group">
                        <label>نوع القبض</label>
                        <div class="payment-cycle-grid">
                            <?php foreach (COACH_SALARY_PAYMENT_CYCLES as $cycleKey => $cycleLabel): ?>
                                <label class="payment-cycle-option<?php echo $formPaymentCycle === $cycleKey ? ' is-active' : ''; ?>">
                                    <input type="radio" name="payment_cycle" value="<?php echo htmlspecialchars($cycleKey, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $formPaymentCycle === $cycleKey ? ' checked' : ''; ?>>
                                    <span><?php echo htmlspecialchars($cycleLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="salary-period-row">
                        <span class="table-summary-chip">من <?php echo htmlspecialchars((string) ($selectedCoachTotals['period_start'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="table-summary-chip">إلى <?php echo htmlspecialchars((string) ($selectedCoachTotals['period_end'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>

                    <div class="salary-period-note">
                        <?php if ($selectedPeriodStart !== null && $selectedPeriodEnd !== null): ?>
                            سيتم تنفيذ الإجراء على البيانات داخل الفترة المحددة فقط.
                        <?php else: ?>
                            اختر فترة من الأعلى لحجز المستحق أو صرفه على فترة محددة.
                        <?php endif; ?>
                    </div>

                    <div class="form-actions salary-action-buttons">
                        <button
                            type="submit"
                            name="action"
                            value="reserve"
                            class="save-btn js-confirm-submit"
                            data-confirm-message="هل تريد حجز مستحق هذا المدرب للفترة المحددة وتصفية بياناتها الحالية؟"
                            <?php echo !$hasSelectedCoachData || $selectedPeriodStart === null || $selectedPeriodEnd === null ? ' disabled' : ''; ?>
                        >
                            📌 حجز المستحق
                        </button>
                        <button
                            type="submit"
                            name="action"
                            value="pay_current"
                            class="filter-btn js-confirm-submit"
                            data-confirm-message="هل تريد صرف هذا المستحق الآن وتصفية بيانات الفترة المحددة؟"
                            <?php echo !$hasSelectedCoachData || $selectedPeriodStart === null || $selectedPeriodEnd === null ? ' disabled' : ''; ?>
                        >
                            💰 صرف الآن
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="empty-state">لا يوجد مدربون لعرضهم.</div>
            <?php endif; ?>
        </article>

        <article class="absences-card salary-history-preview-card">
            <div class="card-head">
                <h2>🧾 المستحقات المحجوزة وآخر المدفوعات</h2>
            </div>

            <?php if (!empty($selectedCoachPendingPayments)): ?>
                <div class="salary-history-preview-list salary-pending-preview-list">
                    <?php foreach (array_slice($selectedCoachPendingPayments, 0, 5) as $pendingItem): ?>
                        <div class="salary-history-preview-item salary-pending-preview-item">
                            <div>
                                <strong>مستحق محفوظ • <?php echo htmlspecialchars(COACH_SALARY_PAYMENT_CYCLES[$pendingItem['payment_cycle']] ?? '—', ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span><?php echo htmlspecialchars((string) $pendingItem['period_start'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars((string) $pendingItem['period_end'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="salary-history-preview-meta">
                                <span>حُجز في <?php echo htmlspecialchars(formatCoachSalaryTimestamp((string) ($pendingItem['reserved_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($canViewSalaryTotals): ?>
                                    <strong><?php echo htmlspecialchars(number_format((float) $pendingItem['net_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif (!empty($selectedCoachHistory)): ?>
                <div class="salary-history-preview-list">
                    <?php foreach (array_slice($selectedCoachHistory, 0, 5) as $historyItem): ?>
                        <div class="salary-history-preview-item">
                            <div>
                                <strong><?php echo htmlspecialchars(COACH_SALARY_PAYMENT_CYCLES[$historyItem['payment_cycle']] ?? '—', ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span><?php echo htmlspecialchars((string) $historyItem['period_start'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars((string) $historyItem['period_end'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="salary-history-preview-meta">
                                <span><?php echo htmlspecialchars(formatCoachSalaryTimestamp((string) ($historyItem['paid_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($canViewSalaryTotals): ?>
                                    <strong><?php echo htmlspecialchars(number_format((float) $historyItem['net_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($selectedCoach !== null): ?>
                <div class="empty-state">لا توجد مستحقات محجوزة أو سجل صرف لهذا المدرب حتى الآن.</div>
            <?php else: ?>
                <div class="empty-state">اختر مدربًا لعرض المستحقات أو السجل.</div>
            <?php endif; ?>
        </article>
    </section>

    <section class="table-card">
        <div class="card-head card-head-inline">
            <div>
                <h2>📅 تفاصيل الأيام الحالية</h2>
            </div>
            <?php if ($selectedCoach !== null): ?>
                <div class="table-summary-chip"><?php echo htmlspecialchars($selectedCoach['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>التاريخ</th>
                        <th>الحضور</th>
                        <th>عدد الساعات</th>
                        <th>سلف اليوم</th>
                        <?php if ($canViewSalaryTotals): ?>
                            <th>إجمالي اليوم</th>
                            <th>صافي اليوم</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($selectedCoachDailyRecords)): ?>
                        <?php foreach ($selectedCoachDailyRecords as $index => $dailyRecord): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($dailyRecord['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <span class="status-badge <?php echo !empty($dailyRecord['attendance_status']) ? 'present-badge' : 'absent-badge'; ?>">
                                        <?php echo !empty($dailyRecord['attendance_status']) ? 'حاضر' : 'بدون حضور'; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars(number_format((float) $dailyRecord['work_hours'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(number_format((float) $dailyRecord['advance_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php if ($canViewSalaryTotals): ?>
                                    <td><?php echo htmlspecialchars(number_format((float) $dailyRecord['daily_total'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="<?php echo (float) $dailyRecord['daily_net_amount'] >= 0 ? 'positive-text' : 'negative-text'; ?>"><?php echo htmlspecialchars(number_format((float) $dailyRecord['daily_net_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $dailyColspan; ?>" class="empty-row">لا توجد بيانات حالية لهذا المدرب.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="table-card">
        <div class="card-head card-head-inline">
            <div>
                <h2>📌 المستحقات المحجوزة</h2>
            </div>
            <?php if ($selectedCoach !== null): ?>
                <div class="table-summary-chip"><?php echo htmlspecialchars($selectedCoach['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>تاريخ الحجز</th>
                        <th>الحالة</th>
                        <th>نوع القبض</th>
                        <th>فترة الاستحقاق</th>
                        <th>إجمالي الساعات</th>
                        <th>إجمالي السلف</th>
                        <?php if ($canViewHourlyRate): ?>
                            <th>سعر الساعة</th>
                        <?php endif; ?>
                        <?php if ($canViewSalaryTotals): ?>
                            <th>إجمالي الأجر</th>
                            <th>صافي المستحق</th>
                        <?php endif; ?>
                        <th>الإجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($selectedCoachPendingPayments)): ?>
                        <?php foreach ($selectedCoachPendingPayments as $index => $pendingItem): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars(formatCoachSalaryTimestamp((string) ($pendingItem['reserved_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(COACH_SALARY_PAYMENT_STATUSES['pending'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(COACH_SALARY_PAYMENT_CYCLES[$pendingItem['payment_cycle']] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) $pendingItem['period_start'], ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string) $pendingItem['period_end'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(number_format((float) $pendingItem['total_hours'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(number_format((float) $pendingItem['total_advances'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php if ($canViewHourlyRate): ?>
                                    <td><?php echo htmlspecialchars(number_format((float) $pendingItem['hourly_rate'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php endif; ?>
                                <?php if ($canViewSalaryTotals): ?>
                                    <td><?php echo htmlspecialchars(number_format((float) $pendingItem['gross_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="<?php echo (float) $pendingItem['net_amount'] >= 0 ? 'positive-text' : 'negative-text'; ?>"><?php echo htmlspecialchars(number_format((float) $pendingItem['net_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php endif; ?>
                                <td>
                                    <form method="POST" class="salary-inline-form js-confirm-submit" data-confirm-message="هل تريد صرف هذا المستحق المحجوز الآن؟">
                                        <input type="hidden" name="action" value="pay_pending">
                                        <input type="hidden" name="coach_id" value="<?php echo htmlspecialchars((string) $selectedCoach['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="payment_id" value="<?php echo htmlspecialchars((string) $pendingItem['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($coachSalaryCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="table-action-btn">💵 صرف المستحق</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $pendingColspan; ?>" class="empty-row">لا توجد مستحقات محجوزة لهذا المدرب حالياً.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="table-card">
        <div class="card-head card-head-inline">
            <div>
                <h2>🗂️ سجل القبض الكامل</h2>
            </div>
            <?php if ($selectedCoach !== null): ?>
                <div class="table-summary-chip"><?php echo htmlspecialchars($selectedCoach['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>تاريخ القبض</th>
                        <th>الحالة</th>
                        <th>نوع القبض</th>
                        <th>فترة الحساب</th>
                        <th>إجمالي الساعات</th>
                        <th>إجمالي السلف</th>
                        <?php if ($canViewHourlyRate): ?>
                            <th>سعر الساعة</th>
                        <?php endif; ?>
                        <?php if ($canViewSalaryTotals): ?>
                            <th>إجمالي الأجر</th>
                            <th>صافي القبض</th>
                        <?php endif; ?>
                        <th>المستخدم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($selectedCoachHistory)): ?>
                        <?php foreach ($selectedCoachHistory as $index => $historyItem): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars(formatCoachSalaryTimestamp((string) ($historyItem['paid_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(COACH_SALARY_PAYMENT_STATUSES['paid'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(COACH_SALARY_PAYMENT_CYCLES[$historyItem['payment_cycle']] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) $historyItem['period_start'], ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string) $historyItem['period_end'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(number_format((float) $historyItem['total_hours'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(number_format((float) $historyItem['total_advances'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php if ($canViewHourlyRate): ?>
                                    <td><?php echo htmlspecialchars(number_format((float) $historyItem['hourly_rate'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php endif; ?>
                                <?php if ($canViewSalaryTotals): ?>
                                    <td><?php echo htmlspecialchars(number_format((float) $historyItem['gross_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="<?php echo (float) $historyItem['net_amount'] >= 0 ? 'positive-text' : 'negative-text'; ?>"><?php echo htmlspecialchars(number_format((float) $historyItem['net_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php endif; ?>
                                <td><?php echo htmlspecialchars((string) ($historyItem['paid_by_username'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $historyColspan; ?>" class="empty-row">لا يوجد سجل قبض لهذا المدرب حتى الآن.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script src="assets/js/coach-salaries.js"></script>
</body>
</html>
