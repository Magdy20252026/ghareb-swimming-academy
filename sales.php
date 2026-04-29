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

if (!userCanAccess($currentUser, "sales")) {
    header("Location: dashboard.php?access=denied");
    exit;
}

const SALES_INVOICE_TYPES = ["sale", "return"];
const SALES_PAYMENT_FILTERS = ["all", "remaining"];
const SALES_PAYMENT_TOLERANCE = 0.01;

function normalizeSalesArabicNumbers(string $value): string
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

function normalizeSalesInteger(string $value): string
{
    return trim(normalizeSalesArabicNumbers($value));
}

function normalizeSalesDecimalInput(string $value): string
{
    $value = trim(normalizeSalesArabicNumbers($value));
    return str_replace(',', '.', $value);
}

function sanitizeSalesText(string $value): string
{
    $trimmedValue = trim($value);
    $value = preg_replace('/\s+/u', ' ', $trimmedValue);
    return $value === null ? $trimmedValue : $value;
}

function sanitizeSalesPhone(string $value): string
{
    return sanitizeSalesText(normalizeSalesArabicNumbers($value));
}

function isValidPositiveSalesInteger(string $value): bool
{
    return $value !== '' && preg_match('/^\d+$/', $value) === 1 && (int) $value > 0;
}

function isValidSalesDecimal(string $value): bool
{
    return $value !== ''
        && preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1
        && (float) $value >= 0;
}

function formatSalesMoney($value): string
{
    return number_format((float) $value, 2);
}

function formatSalesDecimalForStorage($value): string
{
    return number_format((float) $value, 2, '.', '');
}

function buildSalesPageUrl(array $params = []): string
{
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = (string) $value;
    }

    $queryString = http_build_query($filteredParams);
    return 'sales.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function generateSalesSecurityToken(): string
{
    try {
        return bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        error_log('تعذر إنشاء رمز أمان لصفحة المبيعات: ' . $exception->getMessage());
        if (function_exists('openssl_random_pseudo_bytes')) {
            $isStrong = false;
            $randomBytes = openssl_random_pseudo_bytes(32, $isStrong);
            if ($randomBytes !== false && $isStrong) {
                return bin2hex($randomBytes);
            }
        }
    }

    throw new RuntimeException('تعذر إنشاء رمز أمان لصفحة المبيعات');
}

function getSalesCsrfToken(): string
{
    if (
        !isset($_SESSION['sales_csrf_token'])
        || !is_string($_SESSION['sales_csrf_token'])
        || $_SESSION['sales_csrf_token'] === ''
    ) {
        try {
            $_SESSION['sales_csrf_token'] = generateSalesSecurityToken();
        } catch (Throwable $exception) {
            error_log('تعذر تهيئة رمز التحقق الخاص بصفحة المبيعات: ' . $exception->getMessage());
            http_response_code(500);
            exit('تعذر تهيئة أمان الصفحة، يرجى إعادة المحاولة لاحقًا');
        }
    }

    return $_SESSION['sales_csrf_token'];
}

function isValidSalesCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals(getSalesCsrfToken(), $submittedToken);
}

function setSalesFlashMessage(string $message, string $type): void
{
    $_SESSION['sales_flash_message'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function popSalesFlashMessage(): ?array
{
    if (!isset($_SESSION['sales_flash_message']) || !is_array($_SESSION['sales_flash_message'])) {
        return null;
    }

    $flashMessage = $_SESSION['sales_flash_message'];
    unset($_SESSION['sales_flash_message']);

    return $flashMessage;
}

function generateSalesInvoiceNumber(string $invoiceType): string
{
    $prefix = $invoiceType === 'return' ? 'RET' : 'SAL';

    try {
        $suffix = strtoupper(bin2hex(random_bytes(3)));
    } catch (Throwable $exception) {
        $suffix = strtoupper(substr(sha1(uniqid((string) mt_rand(), true)), 0, 6));
    }

    return $prefix . '-' . date('Ymd-His') . '-' . $suffix;
}

function fetchSalesInventoryItems(PDO $pdo, array $itemIds, bool $lockRows = false): array
{
    if ($itemIds === []) {
        return [];
    }

    $cleanIds = array_values(array_unique(array_map('intval', $itemIds)));
    $placeholders = implode(', ', array_fill(0, count($cleanIds), '?'));
    $query = "
        SELECT id, item_name, track_quantity, quantity, purchase_price, sale_price
        FROM inventory_items
        WHERE id IN ($placeholders)
    ";

    if ($lockRows) {
        $query .= " FOR UPDATE";
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($cleanIds);
    $items = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $items[(int) $item['id']] = $item;
    }

    return $items;
}

function attachSalesInvoiceItemSummaries(PDO $pdo, array $invoices): array
{
    if ($invoices === []) {
        return [];
    }

    $invoiceIds = array_map(
        static fn(array $invoice): int => (int) ($invoice['id'] ?? 0),
        $invoices
    );
    $invoiceIds = array_values(array_filter(array_unique($invoiceIds), static fn(int $id): bool => $id > 0));

    if ($invoiceIds === []) {
        return $invoices;
    }

    $placeholders = implode(', ', array_fill(0, count($invoiceIds), '?'));
    $summaryStmt = $pdo->prepare("
        SELECT invoice_id, item_name, quantity
        FROM sales_invoice_items
        WHERE invoice_id IN ($placeholders)
        ORDER BY invoice_id ASC, id ASC
    ");
    $summaryStmt->execute($invoiceIds);

    $summaryMap = [];

    foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $summaryRow) {
        $invoiceId = (int) ($summaryRow['invoice_id'] ?? 0);
        if (!isset($summaryMap[$invoiceId])) {
            $summaryMap[$invoiceId] = [];
        }

        $summaryMap[$invoiceId][] = (string) ($summaryRow['item_name'] ?? '') . ' × ' . (int) ($summaryRow['quantity'] ?? 0);
    }

    foreach ($invoices as &$invoice) {
        $invoiceId = (int) ($invoice['id'] ?? 0);
        $invoice['items_summary'] = isset($summaryMap[$invoiceId]) && $summaryMap[$invoiceId] !== []
            ? implode('، ', $summaryMap[$invoiceId])
            : '-';
    }
    unset($invoice);

    return $invoices;
}

$message = '';
$messageType = '';
$flashMessage = popSalesFlashMessage();
$canViewProfitMargins = (($currentUser['role'] ?? '') === 'مدير');
$paymentFilter = $_GET['payment_filter'] ?? 'all';

if (!in_array($paymentFilter, SALES_PAYMENT_FILTERS, true)) {
    $paymentFilter = 'all';
}

if (is_array($flashMessage)) {
    $message = (string) ($flashMessage['message'] ?? '');
    $messageType = (string) ($flashMessage['type'] ?? 'success');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!isValidSalesCsrfToken($submittedToken)) {
        $message = 'تعذر التحقق من الطلب، يرجى إعادة المحاولة.';
        $messageType = 'error';
    } elseif ($action === 'save_invoice') {
        $invoiceType = $_POST['invoice_type'] ?? 'sale';
        $submittedItemIds = $_POST['item_id'] ?? [];
        $submittedQuantities = $_POST['quantity'] ?? [];
        $submittedUnitSalePrices = $_POST['sale_price'] ?? [];
        $paidAmountInput = normalizeSalesDecimalInput((string) ($_POST['paid_amount'] ?? ''));
        $guardianName = sanitizeSalesText((string) ($_POST['guardian_name'] ?? ''));
        $guardianPhone = sanitizeSalesPhone((string) ($_POST['guardian_phone'] ?? ''));

        if (!in_array($invoiceType, SALES_INVOICE_TYPES, true)) {
            $message = 'نوع الفاتورة غير صالح.';
            $messageType = 'error';
        } elseif (!is_array($submittedItemIds) || !is_array($submittedQuantities) || !is_array($submittedUnitSalePrices)) {
            $message = 'تعذر قراءة بيانات الفاتورة.';
            $messageType = 'error';
        } elseif (count($submittedItemIds) !== count($submittedQuantities) || count($submittedItemIds) !== count($submittedUnitSalePrices)) {
            $message = 'بيانات الفاتورة غير مكتملة، يرجى إعادة إدخال الأصناف.';
            $messageType = 'error';
        } else {
            $preparedRows = [];
            $totalQuantitiesByItemId = [];
            $rowCount = count($submittedItemIds);

            for ($index = 0; $index < $rowCount; $index++) {
                $itemIdInput = trim((string) ($submittedItemIds[$index] ?? ''));
                $quantityInput = normalizeSalesInteger((string) ($submittedQuantities[$index] ?? ''));
                $salePriceInput = normalizeSalesDecimalInput((string) ($submittedUnitSalePrices[$index] ?? ''));

                if ($itemIdInput === '' && $quantityInput === '' && $salePriceInput === '') {
                    continue;
                }

                if ($itemIdInput === '' || !ctype_digit($itemIdInput) || (int) $itemIdInput <= 0) {
                    $message = 'يرجى اختيار صنف صحيح لكل سطر.';
                    $messageType = 'error';
                    break;
                }

                if (!isValidPositiveSalesInteger($quantityInput)) {
                    $message = 'يرجى إدخال عدد صحيح أكبر من صفر لكل صنف.';
                    $messageType = 'error';
                    break;
                }

                if (!isValidSalesDecimal($salePriceInput)) {
                    $message = 'يرجى إدخال سعر بيع صحيح لكل صنف.';
                    $messageType = 'error';
                    break;
                }

                $itemId = (int) $itemIdInput;
                $quantity = (int) $quantityInput;
                $unitSalePrice = (float) $salePriceInput;

                $preparedRows[] = [
                    'item_id' => $itemId,
                    'quantity' => $quantity,
                    'unit_sale_price' => $unitSalePrice,
                ];

                if (!isset($totalQuantitiesByItemId[$itemId])) {
                    $totalQuantitiesByItemId[$itemId] = 0;
                }

                $totalQuantitiesByItemId[$itemId] += $quantity;
            }

            if ($message === '' && $preparedRows === []) {
                $message = 'أضف صنفًا واحدًا على الأقل داخل الفاتورة.';
                $messageType = 'error';
            }

            if ($message === '') {
                $invoiceItems = [];
                $invoiceTotal = 0.0;
                $invoiceProfit = 0.0;
                $invoiceItemCount = 0;
                $stockUpdates = [];

                try {
                    $pdo->beginTransaction();
                    $inventoryItemsMap = fetchSalesInventoryItems($pdo, array_keys($totalQuantitiesByItemId), true);

                    if (count($inventoryItemsMap) !== count($totalQuantitiesByItemId)) {
                        $missingItemIds = array_diff(array_keys($totalQuantitiesByItemId), array_keys($inventoryItemsMap));
                        throw new RuntimeException(
                            'بعض الأصناف المحددة لم تعد متاحة داخل المخزون: ' . implode('، ', array_map('strval', $missingItemIds)) . '.'
                        );
                    }

                    foreach ($totalQuantitiesByItemId as $itemId => $requestedQuantity) {
                        $inventoryItem = $inventoryItemsMap[$itemId] ?? null;

                        if ($inventoryItem === null) {
                            throw new RuntimeException('تعذر تحميل بيانات أحد الأصناف المحددة.');
                        }

                        $tracksQuantity = (int) ($inventoryItem['track_quantity'] ?? 0) === 1;
                        $availableQuantity = $inventoryItem['quantity'] !== null ? (int) $inventoryItem['quantity'] : 0;

                        if ($invoiceType === 'sale' && $tracksQuantity && $availableQuantity < $requestedQuantity) {
                            throw new RuntimeException(
                                'الكمية المتاحة غير كافية للصنف '
                                . $inventoryItem['item_name']
                                . '. المتاح '
                                . $availableQuantity
                                . ' والمطلوب '
                                . $requestedQuantity
                                . '.'
                            );
                        }
                    }

                    foreach ($preparedRows as $preparedRow) {
                        $itemId = (int) $preparedRow['item_id'];
                        $quantity = (int) $preparedRow['quantity'];
                        $inventoryItem = $inventoryItemsMap[$itemId] ?? null;

                        if ($inventoryItem === null) {
                            throw new RuntimeException('تعذر تحميل بيانات أحد الأصناف المحددة.');
                        }

                        $unitSalePrice = (float) $preparedRow['unit_sale_price'];
                        $unitPurchasePrice = (float) ($inventoryItem['purchase_price'] ?? 0);
                        $lineTotal = $unitSalePrice * $quantity;
                        $lineProfit = ($unitSalePrice - $unitPurchasePrice) * $quantity;

                        $invoiceItems[] = [
                            'inventory_item_id' => $itemId,
                            'item_name' => (string) $inventoryItem['item_name'],
                            'quantity' => $quantity,
                            'unit_purchase_price' => formatSalesDecimalForStorage($unitPurchasePrice),
                            'unit_sale_price' => formatSalesDecimalForStorage($unitSalePrice),
                            'line_total' => formatSalesDecimalForStorage($lineTotal),
                            'line_profit' => formatSalesDecimalForStorage($lineProfit),
                        ];

                        $invoiceTotal += $lineTotal;
                        $invoiceProfit += $lineProfit;
                        $invoiceItemCount += $quantity;
                    }

                    foreach ($totalQuantitiesByItemId as $itemId => $requestedQuantity) {
                        $inventoryItem = $inventoryItemsMap[$itemId];
                        $tracksQuantity = (int) ($inventoryItem['track_quantity'] ?? 0) === 1;
                        $availableQuantity = $inventoryItem['quantity'] !== null ? (int) $inventoryItem['quantity'] : 0;

                        if ($tracksQuantity) {
                            $stockUpdates[] = [
                                'item_id' => $itemId,
                                'quantity' => $invoiceType === 'sale'
                                    ? $availableQuantity - $requestedQuantity
                                    : $availableQuantity + $requestedQuantity,
                            ];
                        }
                    }

                    $paidAmountValue = $invoiceTotal;
                    $remainingAmountValue = 0.0;
                    $paymentStatus = 'paid';
                    $guardianNameValue = null;
                    $guardianPhoneValue = null;

                    if ($invoiceType === 'sale') {
                        if ($paidAmountInput !== '') {
                            if (!isValidSalesDecimal($paidAmountInput)) {
                                throw new RuntimeException('يرجى إدخال مبلغ مدفوع صحيح.');
                            }

                            $paidAmountValue = (float) $paidAmountInput;
                        }

                        if ($paidAmountValue < 0) {
                            throw new RuntimeException('المبلغ المدفوع يجب أن يكون صفرًا أو أكثر.');
                        }

                        if ($paidAmountValue > $invoiceTotal + SALES_PAYMENT_TOLERANCE) {
                            throw new RuntimeException('المبلغ المدفوع لا يمكن أن يكون أكبر من إجمالي الفاتورة.');
                        }

                        if (abs($invoiceTotal - $paidAmountValue) < SALES_PAYMENT_TOLERANCE) {
                            $paidAmountValue = $invoiceTotal;
                        }

                        $remainingAmountValue = max($invoiceTotal - $paidAmountValue, 0.0);
                        if (abs($remainingAmountValue) < SALES_PAYMENT_TOLERANCE) {
                            $remainingAmountValue = 0.0;
                        }

                        if ($remainingAmountValue > 0) {
                            if ($guardianName === '' || $guardianPhone === '') {
                                throw new RuntimeException('يرجى تسجيل اسم ولي الأمر ورقم الهاتف عند وجود مبلغ متبقٍ.');
                            }

                            if (strlen($guardianName) > 150 || strlen($guardianPhone) > 25) {
                                throw new RuntimeException('بيانات ولي الأمر طويلة أكثر من المسموح.');
                            }

                            $guardianNameValue = $guardianName;
                            $guardianPhoneValue = $guardianPhone;
                            $paymentStatus = 'partial';
                        }
                    }

                    $invoiceNumber = generateSalesInvoiceNumber($invoiceType);
                    $insertInvoiceStmt = $pdo->prepare('
                        INSERT INTO sales_invoices (
                            invoice_number,
                            invoice_type,
                            total_amount,
                            paid_amount,
                            remaining_amount,
                            guardian_name,
                            guardian_phone,
                            payment_status,
                            total_profit,
                            items_count,
                            created_by_user_id
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    $insertInvoiceStmt->execute([
                        $invoiceNumber,
                        $invoiceType,
                        formatSalesDecimalForStorage($invoiceTotal),
                        formatSalesDecimalForStorage($paidAmountValue),
                        formatSalesDecimalForStorage($remainingAmountValue),
                        $guardianNameValue,
                        $guardianPhoneValue,
                        $paymentStatus,
                        formatSalesDecimalForStorage($invoiceProfit),
                        $invoiceItemCount,
                        $currentUser['id'] ?? null,
                    ]);

                    $invoiceId = (int) $pdo->lastInsertId();
                    $insertItemStmt = $pdo->prepare('
                        INSERT INTO sales_invoice_items (
                            invoice_id,
                            inventory_item_id,
                            item_name,
                            quantity,
                            unit_purchase_price,
                            unit_sale_price,
                            line_total,
                            line_profit
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ');

                    foreach ($invoiceItems as $invoiceItem) {
                        $insertItemStmt->execute([
                            $invoiceId,
                            $invoiceItem['inventory_item_id'],
                            $invoiceItem['item_name'],
                            $invoiceItem['quantity'],
                            $invoiceItem['unit_purchase_price'],
                            $invoiceItem['unit_sale_price'],
                            $invoiceItem['line_total'],
                            $invoiceItem['line_profit'],
                        ]);
                    }

                    if ($invoiceType === 'sale' && $paidAmountValue > 0) {
                        $insertPaymentStmt = $pdo->prepare('
                            INSERT INTO sales_invoice_payments (
                                invoice_id,
                                amount,
                                payment_note,
                                created_by_user_id
                            )
                            VALUES (?, ?, ?, ?)
                        ');
                        $insertPaymentStmt->execute([
                            $invoiceId,
                            formatSalesDecimalForStorage($paidAmountValue),
                            'دفعة عند إنشاء الفاتورة',
                            $currentUser['id'] ?? null,
                        ]);
                    }

                    if ($stockUpdates !== []) {
                        $updateStockStmt = $pdo->prepare('UPDATE inventory_items SET quantity = ? WHERE id = ?');

                        foreach ($stockUpdates as $stockUpdate) {
                            $updateStockStmt->execute([
                                $stockUpdate['quantity'],
                                $stockUpdate['item_id'],
                            ]);
                        }
                    }

                    $pdo->commit();
                    setSalesFlashMessage(
                        $invoiceType === 'return'
                            ? 'تم حفظ فاتورة المرتجع وتحديث المخزون بنجاح.'
                            : ($remainingAmountValue > 0
                                ? 'تم حفظ فاتورة البيع وتسجيل المتبقي على العميل بنجاح.'
                                : 'تم حفظ فاتورة البيع وتحديث المخزون بنجاح.'),
                        'success'
                    );
                    header('Location: sales.php');
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $message = $exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'حدث خطأ أثناء حفظ الفاتورة.';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'settle_balance') {
        $invoiceIdInput = trim((string) ($_POST['invoice_id'] ?? ''));
        $paymentAmountInput = normalizeSalesDecimalInput((string) ($_POST['payment_amount'] ?? ''));
        $redirectFilter = $_POST['redirect_filter'] ?? $paymentFilter;
        $redirectFilter = in_array($redirectFilter, SALES_PAYMENT_FILTERS, true) ? $redirectFilter : 'all';

        if ($invoiceIdInput === '' || !ctype_digit($invoiceIdInput) || (int) $invoiceIdInput <= 0) {
            $message = 'الفاتورة المحددة غير صالحة.';
            $messageType = 'error';
        } elseif (!isValidSalesDecimal($paymentAmountInput) || (float) $paymentAmountInput <= 0) {
            $message = 'يرجى إدخال مبلغ سداد صحيح أكبر من صفر.';
            $messageType = 'error';
        } else {
            $invoiceId = (int) $invoiceIdInput;
            $paymentAmountValue = (float) $paymentAmountInput;

            try {
                $pdo->beginTransaction();

                $invoiceStmt = $pdo->prepare('
                    SELECT id, invoice_number, invoice_type, total_amount, paid_amount, remaining_amount
                    FROM sales_invoices
                    WHERE id = ?
                    FOR UPDATE
                ');
                $invoiceStmt->execute([$invoiceId]);
                $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

                if (!$invoice) {
                    throw new RuntimeException('الفاتورة المطلوب سدادها غير موجودة.');
                }

                if (($invoice['invoice_type'] ?? '') !== 'sale') {
                    throw new RuntimeException('السداد المتأخر متاح لفواتير البيع فقط.');
                }

                $currentPaidAmount = (float) ($invoice['paid_amount'] ?? 0);
                $currentRemainingAmount = (float) ($invoice['remaining_amount'] ?? 0);
                $invoiceTotalAmount = (float) ($invoice['total_amount'] ?? 0);

                if ($currentRemainingAmount <= 0) {
                    throw new RuntimeException('هذه الفاتورة مسددة بالكامل بالفعل.');
                }

                if ($paymentAmountValue > $currentRemainingAmount + SALES_PAYMENT_TOLERANCE) {
                    throw new RuntimeException('مبلغ السداد أكبر من المبلغ المتبقي على الفاتورة.');
                }

                $newPaidAmount = $currentPaidAmount + $paymentAmountValue;
                $newRemainingAmount = max($invoiceTotalAmount - $newPaidAmount, 0.0);

                if (abs($newRemainingAmount) < SALES_PAYMENT_TOLERANCE) {
                    $newRemainingAmount = 0.0;
                    $newPaidAmount = $invoiceTotalAmount;
                }

                $newPaymentStatus = $newRemainingAmount > 0 ? 'partial' : 'paid';

                $updateInvoiceStmt = $pdo->prepare('
                    UPDATE sales_invoices
                    SET paid_amount = ?, remaining_amount = ?, payment_status = ?
                    WHERE id = ?
                ');
                $updateInvoiceStmt->execute([
                    formatSalesDecimalForStorage($newPaidAmount),
                    formatSalesDecimalForStorage($newRemainingAmount),
                    $newPaymentStatus,
                    $invoiceId,
                ]);

                $insertPaymentStmt = $pdo->prepare('
                    INSERT INTO sales_invoice_payments (
                        invoice_id,
                        amount,
                        payment_note,
                        created_by_user_id
                    )
                    VALUES (?, ?, ?, ?)
                ');
                $insertPaymentStmt->execute([
                    $invoiceId,
                    formatSalesDecimalForStorage($paymentAmountValue),
                    'سداد رصيد الفاتورة',
                    $currentUser['id'] ?? null,
                ]);

                $pdo->commit();
                setSalesFlashMessage(
                    $newRemainingAmount > 0
                        ? 'تم تسجيل دفعة جديدة للفاتورة بنجاح.'
                        : 'تم تسديد الفاتورة بالكامل بنجاح.',
                    'success'
                );
                header('Location: ' . buildSalesPageUrl([
                    'payment_filter' => $redirectFilter !== 'all' ? $redirectFilter : null,
                ]));
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'حدث خطأ أثناء تسجيل السداد.';
                $messageType = 'error';
            }
        }
    }
}

$inventoryItemsStmt = $pdo->query('
    SELECT id, item_name, track_quantity, quantity, purchase_price, sale_price, updated_at
    FROM inventory_items
    ORDER BY item_name ASC, id ASC
');
$inventoryItems = $inventoryItemsStmt->fetchAll(PDO::FETCH_ASSOC);
$hasInventoryItems = !empty($inventoryItems);

$dailySummaryStmt = $pdo->query('
    SELECT
        COUNT(*) AS total_invoices,
        SUM(CASE WHEN invoice_type = "sale" THEN total_amount ELSE 0 END) AS total_sales,
        SUM(CASE WHEN invoice_type = "sale" THEN paid_amount ELSE 0 END) AS total_collected,
        SUM(CASE WHEN invoice_type = "sale" THEN remaining_amount ELSE 0 END) AS total_remaining,
        SUM(CASE WHEN invoice_type = "return" THEN total_amount ELSE 0 END) AS total_returns,
        SUM(CASE WHEN invoice_type = "sale" THEN total_profit ELSE -total_profit END) AS total_profit,
        SUM(CASE WHEN invoice_type = "sale" THEN items_count ELSE 0 END) AS sold_items,
        SUM(CASE WHEN invoice_type = "return" THEN items_count ELSE 0 END) AS returned_items
    FROM sales_invoices
    WHERE DATE(created_at) = CURDATE()
');
$dailySummary = $dailySummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$dailyNetSales = (float) ($dailySummary['total_sales'] ?? 0) - (float) ($dailySummary['total_returns'] ?? 0);

$overallSalesSummaryStmt = $pdo->query('
    SELECT
        COUNT(*) AS total_invoices,
        SUM(CASE WHEN invoice_type = "sale" AND remaining_amount > 0 THEN 1 ELSE 0 END) AS pending_invoices,
        SUM(CASE WHEN invoice_type = "sale" THEN remaining_amount ELSE 0 END) AS total_remaining_amount
    FROM sales_invoices
');
$overallSalesSummary = $overallSalesSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalSavedInvoices = (int) ($overallSalesSummary['total_invoices'] ?? 0);
$pendingInvoicesCount = (int) ($overallSalesSummary['pending_invoices'] ?? 0);
$totalRemainingAmount = (float) ($overallSalesSummary['total_remaining_amount'] ?? 0);

$invoiceConditions = [];
if ($paymentFilter === 'remaining') {
    $invoiceConditions[] = "si.invoice_type = 'sale'";
    $invoiceConditions[] = 'si.remaining_amount > 0';
}
$invoiceWhereSql = $invoiceConditions === [] ? '' : 'WHERE ' . implode(' AND ', $invoiceConditions);

$allInvoicesStmt = $pdo->query('
    SELECT
        si.id,
        si.invoice_number,
        si.invoice_type,
        si.total_amount,
        si.paid_amount,
        si.remaining_amount,
        si.guardian_name,
        si.guardian_phone,
        si.payment_status,
        si.total_profit,
        si.items_count,
        si.created_at,
        COALESCE(u.username, "-") AS created_by
    FROM sales_invoices si
    LEFT JOIN users u ON u.id = si.created_by_user_id
    ' . $invoiceWhereSql . '
    ORDER BY si.created_at DESC, si.id DESC
');
$allInvoices = attachSalesInvoiceItemSummaries($pdo, $allInvoicesStmt->fetchAll(PDO::FETCH_ASSOC));

$todayInvoicesStmt = $pdo->query('
    SELECT
        si.id,
        si.invoice_number,
        si.invoice_type,
        si.total_amount,
        si.paid_amount,
        si.remaining_amount,
        si.payment_status,
        si.total_profit,
        si.items_count,
        si.created_at,
        COALESCE(u.username, "-") AS created_by
    FROM sales_invoices si
    LEFT JOIN users u ON u.id = si.created_by_user_id
    WHERE DATE(si.created_at) = CURDATE()
    ORDER BY si.created_at DESC, si.id DESC
');
$todayInvoices = attachSalesInvoiceItemSummaries($pdo, $todayInvoicesStmt->fetchAll(PDO::FETCH_ASSOC));

$displayedInvoicesCount = count($allInvoices);
$availableTrackedItems = 0;
$todayInvoicesColspan = $canViewProfitMargins ? 12 : 11;
$allInvoicesColspan = $canViewProfitMargins ? 15 : 14;

foreach ($inventoryItems as $inventoryItem) {
    if ((int) ($inventoryItem['track_quantity'] ?? 0) === 1 && (int) ($inventoryItem['quantity'] ?? 0) > 0) {
        $availableTrackedItems++;
    }
}

$salesCsrfToken = getSalesCsrfToken();
$inventoryItemsJson = [];

foreach ($inventoryItems as $inventoryItem) {
    $inventoryItemsJson[(string) $inventoryItem['id']] = [
        'id' => (int) $inventoryItem['id'],
        'name' => (string) $inventoryItem['item_name'],
        'track_quantity' => (int) ($inventoryItem['track_quantity'] ?? 0),
        'quantity' => $inventoryItem['quantity'] === null ? null : (int) $inventoryItem['quantity'],
        'sale_price' => (float) ($inventoryItem['sale_price'] ?? 0),
    ];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المبيعات</title>
    <link rel="stylesheet" href="assets/css/sales.css">
</head>
<body class="light-mode">
<div class="sales-page">
    <header class="page-header">
        <div class="header-text">
            <span class="eyebrow">نظام المبيعات</span>
            <h1>المبيعات والفواتير</h1>
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
            <a href="dashboard.php" class="back-btn">الرجوع للوحة التحكم</a>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div class="message-box <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <section class="hero-grid">
        <article class="hero-card hero-card-main">
            <span>صافي اليوم</span>
            <strong><?php echo formatSalesMoney($dailyNetSales); ?> ج.م</strong>
        </article>
        <article class="hero-card">
            <span>بيع اليوم</span>
            <strong><?php echo formatSalesMoney($dailySummary['total_sales'] ?? 0); ?> ج.م</strong>
        </article>
        <article class="hero-card">
            <span>المحصّل اليوم</span>
            <strong><?php echo formatSalesMoney($dailySummary['total_collected'] ?? 0); ?> ج.م</strong>
        </article>
        <article class="hero-card">
            <span>متبقي اليوم</span>
            <strong><?php echo formatSalesMoney($dailySummary['total_remaining'] ?? 0); ?> ج.م</strong>
        </article>
        <?php if ($canViewProfitMargins): ?>
            <article class="hero-card">
                <span>هامش الربح اليوم</span>
                <strong><?php echo formatSalesMoney($dailySummary['total_profit'] ?? 0); ?> ج.م</strong>
            </article>
        <?php endif; ?>
        <article class="hero-card">
            <span>فواتير غير مسددة</span>
            <strong><?php echo $pendingInvoicesCount; ?></strong>
        </article>
    </section>

    <section class="content-grid">
        <div class="form-card">
            <div class="card-head">
                <h2>إنشاء فاتورة</h2>
            </div>

            <form method="POST" id="salesForm" class="sales-form" autocomplete="off">
                <input type="hidden" name="action" value="save_invoice">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($salesCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="invoice-type-grid" id="invoiceTypeGroup">
                    <label class="invoice-type-card active" data-type="sale">
                        <input type="radio" name="invoice_type" value="sale" checked>
                        <span class="invoice-type-title">بيع</span>
                    </label>
                    <label class="invoice-type-card" data-type="return">
                        <input type="radio" name="invoice_type" value="return">
                        <span class="invoice-type-title">مرتجع</span>
                    </label>
                </div>

                <div class="invoice-toolbar">
                    <button type="button" class="secondary-btn" id="addRowBtn" <?php echo $hasInventoryItems ? '' : 'disabled'; ?>>إضافة صنف</button>
                </div>

                <div class="invoice-rows" id="invoiceRows">
                    <div class="invoice-row">
                        <div class="field-group item-field">
                            <label>الصنف</label>
                            <select name="item_id[]" class="item-select" <?php echo $hasInventoryItems ? '' : 'disabled'; ?>>
                                <option value="">اختر الصنف</option>
                                <?php foreach ($inventoryItems as $inventoryItem): ?>
                                    <option value="<?php echo (int) $inventoryItem['id']; ?>"><?php echo htmlspecialchars($inventoryItem['item_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field-group quantity-field">
                            <label>العدد</label>
                            <input type="number" name="quantity[]" class="quantity-input" min="1" step="1" placeholder="0" <?php echo $hasInventoryItems ? '' : 'disabled'; ?>>
                        </div>
                        <div class="field-group price-field">
                            <label>سعر البيع</label>
                            <input type="number" name="sale_price[]" class="sale-price-input" min="0" step="0.01" inputmode="decimal" placeholder="0.00" <?php echo $hasInventoryItems ? '' : 'disabled'; ?>>
                        </div>
                        <div class="field-group info-field">
                            <label>الإجمالي</label>
                            <div class="value-box line-total-value">0.00 ج.م</div>
                        </div>
                        <div class="field-group status-field">
                            <label>الحالة</label>
                            <div class="stock-state">اختر الصنف</div>
                        </div>
                        <div class="row-actions">
                            <button type="button" class="remove-row-btn">حذف</button>
                        </div>
                    </div>
                </div>

                <div class="invoice-summary-grid">
                    <div class="summary-card">
                        <span>عدد الأصناف</span>
                        <strong id="invoiceItemsCount">0</strong>
                    </div>
                    <div class="summary-card summary-total-card">
                        <span>إجمالي الفاتورة</span>
                        <strong id="invoiceGrandTotal">0.00 ج.م</strong>
                    </div>
                    <div class="summary-card">
                        <span>المدفوع</span>
                        <strong id="invoicePaidPreview">0.00 ج.م</strong>
                    </div>
                    <div class="summary-card">
                        <span>المتبقي</span>
                        <strong id="invoiceRemainingPreview">0.00 ج.م</strong>
                    </div>
                </div>

                <div class="payment-section" id="paymentSection">
                    <div class="payment-grid">
                        <div class="field-group">
                            <label for="paidAmountInput">المبلغ المدفوع</label>
                            <input type="number" id="paidAmountInput" name="paid_amount" class="paid-amount-input" min="0" step="0.01" inputmode="decimal" placeholder="0.00" <?php echo $hasInventoryItems ? '' : 'disabled'; ?>>
                        </div>
                        <div class="payment-note-box" id="paymentHint">يتم اعتبار الفاتورة مسددة بالكامل تلقائيًا ما لم يتم تعديل المبلغ المدفوع.</div>
                    </div>

                    <div class="guardian-grid is-hidden" id="guardianFields">
                        <div class="field-group">
                            <label for="guardianNameInput">اسم ولي الأمر</label>
                            <input type="text" id="guardianNameInput" name="guardian_name" maxlength="150" placeholder="اكتب اسم ولي الأمر">
                        </div>
                        <div class="field-group">
                            <label for="guardianPhoneInput">رقم الهاتف</label>
                            <input type="text" id="guardianPhoneInput" name="guardian_phone" maxlength="25" inputmode="tel" placeholder="اكتب رقم الهاتف">
                        </div>
                    </div>
                </div>

                <button type="submit" class="save-btn" id="saveInvoiceBtn" <?php echo $hasInventoryItems ? '' : 'disabled'; ?>>حفظ فاتورة البيع</button>
            </form>
        </div>

        <aside class="side-panel">
            <div class="side-card">
                <h3>ملخص سريع</h3>
                <div class="mini-stats">
                    <div class="mini-stat">
                        <span>الأصناف المتاحة</span>
                        <strong><?php echo count($inventoryItems); ?></strong>
                    </div>
                    <div class="mini-stat">
                        <span>أصناف لها مخزون</span>
                        <strong><?php echo $availableTrackedItems; ?></strong>
                    </div>
                    <div class="mini-stat">
                        <span>إجمالي الفواتير</span>
                        <strong><?php echo $totalSavedInvoices; ?></strong>
                    </div>
                    <div class="mini-stat">
                        <span>إجمالي المتبقي</span>
                        <strong><?php echo formatSalesMoney($totalRemainingAmount); ?> ج.م</strong>
                    </div>
                    <div class="mini-stat">
                        <span>فواتير لم تُسدد</span>
                        <strong><?php echo $pendingInvoicesCount; ?></strong>
                    </div>
                    <div class="mini-stat">
                        <span>أصناف مباعة اليوم</span>
                        <strong><?php echo (int) ($dailySummary['sold_items'] ?? 0); ?></strong>
                    </div>
                </div>
            </div>
        </aside>
    </section>

    <section class="table-card">
        <div class="card-head table-head">
            <div>
                <h2>سجل بيع اليوم</h2>
            </div>
            <span class="table-count"><?php echo count($todayInvoices); ?> فاتورة</span>
        </div>

        <div class="table-wrapper">
            <table>
                <caption class="sr-only">سجل الفواتير المسجلة اليوم.</caption>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>رقم الفاتورة</th>
                        <th>النوع</th>
                        <th>الأصناف</th>
                        <th>الكمية</th>
                        <th>الإجمالي</th>
                        <th>المدفوع</th>
                        <th>المتبقي</th>
                        <?php if ($canViewProfitMargins): ?>
                            <th>هامش الربح</th>
                        <?php endif; ?>
                        <th>الحالة</th>
                        <th>المستخدم</th>
                        <th>الوقت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($todayInvoices !== []): ?>
                        <?php foreach ($todayInvoices as $index => $invoice): ?>
                            <?php
                            $isReturnInvoice = ($invoice['invoice_type'] ?? '') === 'return';
                            $hasRemainingAmount = !$isReturnInvoice && (float) ($invoice['remaining_amount'] ?? 0) > 0;
                            ?>
                            <tr>
                                <td data-label="#"><?php echo $index + 1; ?></td>
                                <td data-label="رقم الفاتورة"><?php echo htmlspecialchars((string) $invoice['invoice_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="النوع">
                                    <span class="type-badge <?php echo $isReturnInvoice ? 'return-type' : 'sale-type'; ?>">
                                        <?php echo $isReturnInvoice ? 'مرتجع' : 'بيع'; ?>
                                    </span>
                                </td>
                                <td data-label="الأصناف" class="items-cell"><?php echo htmlspecialchars((string) ($invoice['items_summary'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="الكمية"><?php echo (int) ($invoice['items_count'] ?? 0); ?></td>
                                <td data-label="الإجمالي" class="amount-cell <?php echo $isReturnInvoice ? 'negative-cell' : 'positive-cell'; ?>">
                                    <?php echo $isReturnInvoice ? '-' : '+'; ?><?php echo formatSalesMoney($invoice['total_amount'] ?? 0); ?> ج.م
                                </td>
                                <td data-label="المدفوع"><?php echo formatSalesMoney($invoice['paid_amount'] ?? 0); ?> ج.م</td>
                                <td data-label="المتبقي" class="amount-cell <?php echo $hasRemainingAmount ? 'negative-cell' : 'positive-cell'; ?>">
                                    <?php echo formatSalesMoney($invoice['remaining_amount'] ?? 0); ?> ج.م
                                </td>
                                <?php if ($canViewProfitMargins): ?>
                                    <td data-label="هامش الربح" class="amount-cell <?php echo $isReturnInvoice ? 'negative-cell' : 'positive-cell'; ?>">
                                        <?php echo $isReturnInvoice ? '-' : '+'; ?><?php echo formatSalesMoney($invoice['total_profit'] ?? 0); ?> ج.م
                                    </td>
                                <?php endif; ?>
                                <td data-label="الحالة">
                                    <span class="status-badge <?php echo $hasRemainingAmount ? 'partial-status' : 'paid-status'; ?>">
                                        <?php echo $hasRemainingAmount ? 'متبقي' : 'مسددة'; ?>
                                    </span>
                                </td>
                                <td data-label="المستخدم"><?php echo htmlspecialchars((string) ($invoice['created_by'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="الوقت"><?php echo htmlspecialchars((string) ($invoice['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $todayInvoicesColspan; ?>" class="empty-row">لا توجد فواتير مسجلة اليوم.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="table-card">
        <div class="card-head table-head table-head-stack">
            <div>
                <h2>الفواتير المحفوظة</h2>
                <p class="table-subtitle">يمكن تصفية الفواتير المتبقية فقط وتسديد الرصيد من نفس الصفحة.</p>
            </div>
            <div class="table-tools">
                <span class="table-count"><?php echo $displayedInvoicesCount; ?> فاتورة</span>
                <form method="GET" class="filter-form">
                    <label for="paymentFilterSelect" class="sr-only">تصفية الفواتير</label>
                    <select id="paymentFilterSelect" name="payment_filter">
                        <option value="all" <?php echo $paymentFilter === 'all' ? 'selected' : ''; ?>>كل الفواتير</option>
                        <option value="remaining" <?php echo $paymentFilter === 'remaining' ? 'selected' : ''; ?>>الفواتير المتبقية فقط</option>
                    </select>
                    <button type="submit" class="secondary-btn">تطبيق</button>
                    <?php if ($paymentFilter !== 'all'): ?>
                        <a href="sales.php" class="filter-reset-link">إلغاء التصفية</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <caption class="sr-only">الفواتير المحفوظة داخل النظام.</caption>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>رقم الفاتورة</th>
                        <th>النوع</th>
                        <th>الأصناف</th>
                        <th>الكمية</th>
                        <th>الإجمالي</th>
                        <th>المدفوع</th>
                        <th>المتبقي</th>
                        <?php if ($canViewProfitMargins): ?>
                            <th>هامش الربح</th>
                        <?php endif; ?>
                        <th>الحالة</th>
                        <th>ولي الأمر</th>
                        <th>الهاتف</th>
                        <th>المستخدم</th>
                        <th>التاريخ</th>
                        <th>سداد الباقي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($allInvoices !== []): ?>
                        <?php foreach ($allInvoices as $index => $invoice): ?>
                            <?php
                            $isReturnInvoice = ($invoice['invoice_type'] ?? '') === 'return';
                            $remainingAmount = (float) ($invoice['remaining_amount'] ?? 0);
                            $hasRemainingAmount = !$isReturnInvoice && $remainingAmount > 0;
                            $guardianPhoneText = (string) ($invoice['guardian_phone'] ?? '');
                            $guardianPhone = sanitizeAcademyPhoneNumber($guardianPhoneText);
                            $guardianWhatsappPhone = formatAcademyWhatsappPhone($guardianPhoneText);
                            ?>
                            <tr>
                                <td data-label="#"><?php echo $index + 1; ?></td>
                                <td data-label="رقم الفاتورة"><?php echo htmlspecialchars((string) $invoice['invoice_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="النوع">
                                    <span class="type-badge <?php echo $isReturnInvoice ? 'return-type' : 'sale-type'; ?>">
                                        <?php echo $isReturnInvoice ? 'مرتجع' : 'بيع'; ?>
                                    </span>
                                </td>
                                <td data-label="الأصناف" class="items-cell"><?php echo htmlspecialchars((string) ($invoice['items_summary'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="الكمية"><?php echo (int) ($invoice['items_count'] ?? 0); ?></td>
                                <td data-label="الإجمالي" class="amount-cell <?php echo $isReturnInvoice ? 'negative-cell' : 'positive-cell'; ?>">
                                    <?php echo $isReturnInvoice ? '-' : '+'; ?><?php echo formatSalesMoney($invoice['total_amount'] ?? 0); ?> ج.م
                                </td>
                                <td data-label="المدفوع"><?php echo formatSalesMoney($invoice['paid_amount'] ?? 0); ?> ج.م</td>
                                <td data-label="المتبقي" class="amount-cell <?php echo $hasRemainingAmount ? 'negative-cell' : 'positive-cell'; ?>">
                                    <?php echo formatSalesMoney($remainingAmount); ?> ج.م
                                </td>
                                <?php if ($canViewProfitMargins): ?>
                                    <td data-label="هامش الربح" class="amount-cell <?php echo $isReturnInvoice ? 'negative-cell' : 'positive-cell'; ?>">
                                        <?php echo $isReturnInvoice ? '-' : '+'; ?><?php echo formatSalesMoney($invoice['total_profit'] ?? 0); ?> ج.م
                                    </td>
                                <?php endif; ?>
                                <td data-label="الحالة">
                                    <span class="status-badge <?php echo $hasRemainingAmount ? 'partial-status' : 'paid-status'; ?>">
                                        <?php echo $hasRemainingAmount ? 'متبقي' : 'مسددة'; ?>
                                    </span>
                                </td>
                                <td data-label="ولي الأمر"><?php echo htmlspecialchars((string) ($invoice['guardian_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="الهاتف">
                                    <div class="phone-cell">
                                        <span><?php echo htmlspecialchars($guardianPhoneText !== '' ? $guardianPhoneText : '-', ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if ($hasRemainingAmount): ?>
                                            <div class="phone-actions">
                                                <a href="<?php echo $guardianPhone !== '' ? 'tel:' . htmlspecialchars($guardianPhone, ENT_QUOTES, 'UTF-8') : '#'; ?>" class="phone-action-btn <?php echo $guardianPhone === '' ? 'is-disabled' : ''; ?>">اتصال</a>
                                                <a href="<?php echo $guardianWhatsappPhone !== '' ? 'https://wa.me/' . htmlspecialchars($guardianWhatsappPhone, ENT_QUOTES, 'UTF-8') : '#'; ?>" target="_blank" rel="noopener noreferrer" class="phone-action-btn whatsapp-action <?php echo $guardianWhatsappPhone === '' ? 'is-disabled' : ''; ?>">واتساب</a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="المستخدم"><?php echo htmlspecialchars((string) ($invoice['created_by'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="التاريخ"><?php echo htmlspecialchars((string) ($invoice['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="سداد الباقي">
                                    <?php if ($hasRemainingAmount): ?>
                                        <form method="POST" class="settlement-form">
                                            <input type="hidden" name="action" value="settle_balance">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($salesCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="invoice_id" value="<?php echo (int) ($invoice['id'] ?? 0); ?>">
                                            <input type="hidden" name="redirect_filter" value="<?php echo htmlspecialchars($paymentFilter, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input
                                                type="number"
                                                name="payment_amount"
                                                class="settlement-input"
                                                min="0.01"
                                                step="0.01"
                                                max="<?php echo htmlspecialchars(formatSalesDecimalForStorage($remainingAmount), ENT_QUOTES, 'UTF-8'); ?>"
                                                placeholder="0.00"
                                                required
                                            >
                                            <button type="submit" class="settle-btn">تسديد</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="soft-note">لا يوجد متبقي</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $allInvoicesColspan; ?>" class="empty-row">لا توجد فواتير مطابقة للتصفية الحالية.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<template id="invoiceRowTemplate">
    <div class="invoice-row">
        <div class="field-group item-field">
            <label>الصنف</label>
            <select name="item_id[]" class="item-select">
                <option value="">اختر الصنف</option>
                <?php foreach ($inventoryItems as $inventoryItem): ?>
                    <option value="<?php echo (int) $inventoryItem['id']; ?>"><?php echo htmlspecialchars($inventoryItem['item_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field-group quantity-field">
            <label>العدد</label>
            <input type="number" name="quantity[]" class="quantity-input" min="1" step="1" placeholder="0">
        </div>
        <div class="field-group price-field">
            <label>سعر البيع</label>
            <input type="number" name="sale_price[]" class="sale-price-input" min="0" step="0.01" inputmode="decimal" placeholder="0.00">
        </div>
        <div class="field-group info-field">
            <label>الإجمالي</label>
            <div class="value-box line-total-value">0.00 ج.م</div>
        </div>
        <div class="field-group status-field">
            <label>الحالة</label>
            <div class="stock-state">اختر الصنف</div>
        </div>
        <div class="row-actions">
            <button type="button" class="remove-row-btn">حذف</button>
        </div>
    </div>
</template>

<script>
window.salesInventoryData = <?php echo json_encode($inventoryItemsJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/js/sales.js"></script>
</body>
</html>
