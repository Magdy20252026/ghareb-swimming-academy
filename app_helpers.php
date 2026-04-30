<?php

const SWIMMER_ACCOUNT_MIN_PASSWORD_LENGTH = 6;
const ACADEMY_XLSX_CREATOR = 'ghareb-swimming-academy';

function normalizeAcademyHelperText(string $value): string
{
    $value = trim($value);
    $sanitizedValue = preg_replace('/\s+/u', ' ', $value);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function normalizeAcademyHelperDigits(string $value): string
{
    return strtr($value, [
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9',
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
    ]);
}

function sanitizeAcademyPhoneNumber(string $value): string
{
    $normalizedValue = trim(normalizeAcademyHelperDigits($value));
    $sanitizedValue = preg_replace('/[^0-9+]/', '', $normalizedValue);
    return $sanitizedValue === null ? '' : $sanitizedValue;
}

function formatAcademyWhatsappPhone(string $value): string
{
    $phone = sanitizeAcademyPhoneNumber($value);
    if ($phone === '') {
        return '';
    }

    if (str_starts_with($phone, '+')) {
        return substr($phone, 1);
    }

    if (str_starts_with($phone, '00')) {
        return substr($phone, 2);
    }

    if (str_starts_with($phone, '0')) {
        return '20' . ltrim($phone, '0');
    }

    return $phone;
}

function normalizeAcademyTimeMeridiem(string $value): string
{
    $normalizedValue = normalizeAcademyHelperText(normalizeAcademyHelperDigits($value));
    if ($normalizedValue === '') {
        return '';
    }

    $normalizedValue = preg_replace('/\s*(صباحًا|صباحا|ص)\s*$/u', ' AM', $normalizedValue) ?? $normalizedValue;
    $normalizedValue = preg_replace('/\s*(مساءً|مساء|م)\s*$/u', ' PM', $normalizedValue) ?? $normalizedValue;
    $normalizedValue = preg_replace('/\s*(a\.?m\.?)\s*$/iu', ' AM', $normalizedValue) ?? $normalizedValue;
    $normalizedValue = preg_replace('/\s*(p\.?m\.?)\s*$/iu', ' PM', $normalizedValue) ?? $normalizedValue;
    $normalizedValue = preg_replace('/\s*(AM|PM)\s*$/i', ' $1', $normalizedValue) ?? $normalizedValue;

    return normalizeAcademyHelperText($normalizedValue);
}

function normalizeAcademyTimeInputValue(string $value): string
{
    $normalizedValue = normalizeAcademyTimeMeridiem($value);
    if ($normalizedValue === '') {
        return '';
    }

    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $normalizedValue) === 1) {
        return $normalizedValue;
    }

    if (preg_match('/^(0?[1-9]|1[0-2]):[0-5]\d (AM|PM)$/i', $normalizedValue) !== 1) {
        return '';
    }

    $dateTime = DateTime::createFromFormat('!g:i A', strtoupper($normalizedValue));
    return $dateTime instanceof DateTime ? $dateTime->format('H:i') : '';
}

function formatAcademyTimeTo12Hour(string $value): string
{
    $inputValue = normalizeAcademyTimeInputValue($value);
    if ($inputValue === '') {
        return normalizeAcademyHelperText($value);
    }

    $dateTime = DateTime::createFromFormat('!H:i', $inputValue);
    if (!$dateTime instanceof DateTime) {
        return normalizeAcademyHelperText($value);
    }

    return $dateTime->format('h:i') . ' ' . ($dateTime->format('A') === 'AM' ? 'ص' : 'م');
}

function buildAcademySubscriptionName(
    string $category,
    string $coachName,
    string $scheduleSummary,
    string $branch,
    string $fallbackName = ''
): string {
    $parts = [];

    foreach ([$category, $coachName, $scheduleSummary, $branch] as $part) {
        $sanitizedPart = normalizeAcademyHelperText($part);
        if ($sanitizedPart !== '' && $sanitizedPart !== '—') {
            $parts[] = $sanitizedPart;
        }
    }

    if ($parts !== []) {
        return implode(' ', $parts);
    }

    return normalizeAcademyHelperText($fallbackName);
}

function academyXlsxXmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function academyXlsxColumnName(int $index): string
{
    $columnName = '';
    $index++;

    while ($index > 0) {
        $modulo = ($index - 1) % 26;
        $columnName = chr(65 + $modulo) . $columnName;
        $index = (int) floor(($index - $modulo) / 26);
    }

    return $columnName;
}

function createAcademyXlsxFile(array $headers, array $rows, string $sheetName = 'البيانات', string $documentTitle = 'البيانات'): string
{
    $sheetRows = array_merge([$headers], $rows);
    $sheetXmlRows = [];

    foreach ($sheetRows as $rowIndex => $row) {
        $cellsXml = [];
        foreach (array_values($row) as $columnIndex => $value) {
            $cellReference = academyXlsxColumnName($columnIndex) . ($rowIndex + 1);
            $cellsXml[] = sprintf(
                '<c r="%s" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
                $cellReference,
                academyXlsxXmlEscape((string) $value)
            );
        }

        $sheetXmlRows[] = sprintf('<row r="%d">%s</row>', $rowIndex + 1, implode('', $cellsXml));
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<sheetData>' . implode('', $sheetXmlRows) . '</sheetData>'
        . '</worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . academyXlsxXmlEscape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Tahoma"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $createdAt = gmdate('Y-m-d\TH:i:s\Z');
    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . academyXlsxXmlEscape($documentTitle) . '</dc:title>'
        . '<dc:creator>' . academyXlsxXmlEscape(ACADEMY_XLSX_CREATOR) . '</dc:creator>'
        . '<cp:lastModifiedBy>' . academyXlsxXmlEscape(ACADEMY_XLSX_CREATOR) . '</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>Microsoft Excel</Application>'
        . '</Properties>';

    $temporaryFile = tempnam(sys_get_temp_dir(), 'academy_xlsx_');
    if ($temporaryFile === false) {
        throw new RuntimeException('تعذر إنشاء ملف التصدير');
    }

    $zip = new ZipArchive();
    if ($zip->open($temporaryFile, ZipArchive::OVERWRITE) !== true) {
        @unlink($temporaryFile);
        throw new RuntimeException('تعذر إنشاء ملف الإكسل');
    }

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $rootRelsXml);
    $zip->addFromString('docProps/core.xml', $coreXml);
    $zip->addFromString('docProps/app.xml', $appXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    return $temporaryFile;
}

function outputAcademyXlsxDownload(string $temporaryFile, string $downloadFileName): void
{
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $downloadFileName . '"');
    header('Content-Length: ' . (string) filesize($temporaryFile));
    header('Cache-Control: max-age=0');

    readfile($temporaryFile);
    @unlink($temporaryFile);
    exit;
}

function getAcademyMenuItems(): array
{
    return [
        [
            "key" => "dashboard",
            "title" => "لوحة التحكم",
            "icon" => "🏠",
            "type" => "button",
            "accent_start" => "#1d4ed8",
            "accent_end" => "#3b82f6",
            "description" => "عرض المؤشرات العامة للنظام",
            "always_visible" => true,
            "editable_permission" => false,
        ],
        [
            "key" => "users",
            "title" => "المستخدمين",
            "icon" => "👥",
            "type" => "link",
            "href" => "users.php",
            "accent_start" => "#2563eb",
            "accent_end" => "#60a5fa",
            "description" => "إدارة الحسابات والمستخدمين",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "user_permissions",
            "title" => "صلاحيات المستخدمين",
            "icon" => "🛡️",
            "type" => "link",
            "href" => "user_permissions.php",
            "accent_start" => "#0f766e",
            "accent_end" => "#14b8a6",
            "description" => "تخصيص الأزرار والإحصائيات الظاهرة لكل مشرف",
            "always_visible" => false,
            "editable_permission" => false,
        ],
        [
            "key" => "subscriptions",
            "title" => "المجموعات",
            "icon" => "📋",
            "type" => "link",
            "href" => "subscriptions.php",
            "accent_start" => "#7c3aed",
            "accent_end" => "#8b5cf6",
            "description" => "إدارة مجموعات السباحة",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "academy_players",
            "title" => "السباحين",
            "icon" => "🏊",
            "type" => "link",
            "href" => "academy_players.php",
            "accent_start" => "#0891b2",
            "accent_end" => "#06b6d4",
            "description" => "إدارة ملفات السباحين",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "academy_players_academies",
            "title" => "لاعبين الأكاديميات",
            "icon" => "🌊",
            "type" => "link",
            "href" => "academies_players.php",
            "accent_start" => "#0369a1",
            "accent_end" => "#38bdf8",
            "description" => "إدارة ملفات لاعبي الأكاديميات",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "player_attendance",
            "title" => "حضور السباحين",
            "icon" => "✅",
            "type" => "link",
            "href" => "player_attendance.php",
            "accent_start" => "#059669",
            "accent_end" => "#10b981",
            "description" => "تسجيل حضور السباحين اليومي",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "renew_subscription",
            "title" => "تجديد الاشتراك",
            "icon" => "🔄",
            "type" => "link",
            "href" => "renew_subscription.php",
            "accent_start" => "#0f766e",
            "accent_end" => "#14b8a6",
            "description" => "تجديد الاشتراكات المنتهية",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "settle_remaining",
            "title" => "تسديد الباق",
            "icon" => "💳",
            "type" => "link",
            "href" => "settle_remaining.php",
            "accent_start" => "#15803d",
            "accent_end" => "#22c55e",
            "description" => "سداد المبالغ المتبقية على السباحين",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "coaches",
            "title" => "المدربين",
            "icon" => "🏅",
            "type" => "link",
            "href" => "coaches.php",
            "accent_start" => "#ea580c",
            "accent_end" => "#f97316",
            "description" => "إدارة بيانات المدربين",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "coach_attendance",
            "title" => "حضور المدربين",
            "icon" => "🕒",
            "type" => "link",
            "href" => "coach_attendance.php",
            "accent_start" => "#f97316",
            "accent_end" => "#fb923c",
            "description" => "متابعة حضور وانصراف المدربين",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "coach_advances",
            "title" => "سلف المدربين",
            "icon" => "💵",
            "type" => "link",
            "href" => "coach_advances.php",
            "accent_start" => "#ca8a04",
            "accent_end" => "#eab308",
            "description" => "إدارة السلف المالية للمدربين",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "coach_salaries",
            "title" => "قبض رواتب المدربين",
            "icon" => "💰",
            "type" => "link",
            "href" => "coach_salaries.php",
            "accent_start" => "#b91c1c",
            "accent_end" => "#ef4444",
            "description" => "متابعة صرف رواتب المدربين",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "administrators",
            "title" => "الإداريين",
            "icon" => "🗂️",
            "type" => "link",
            "href" => "administrators.php",
            "accent_start" => "#374151",
            "accent_end" => "#64748b",
            "description" => "إدارة الطاقم الإداري",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "admin_attendance",
            "title" => "حضور الإداريين",
            "icon" => "📍",
            "type" => "link",
            "href" => "admin_attendance.php",
            "accent_start" => "#475569",
            "accent_end" => "#94a3b8",
            "description" => "تسجيل حضور الإداريين",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "admin_advances",
            "title" => "سلف الإداريين",
            "icon" => "💸",
            "type" => "link",
            "href" => "admin_advances.php",
            "accent_start" => "#a16207",
            "accent_end" => "#f59e0b",
            "description" => "إدارة السلف المالية للإداريين",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "admin_salaries",
            "title" => "قبض مرتبات الإداريين",
            "icon" => "💳",
            "type" => "link",
            "href" => "admin_salaries.php",
            "accent_start" => "#7c2d12",
            "accent_end" => "#c2410c",
            "description" => "متابعة صرف مرتبات الإداريين",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "inventory",
            "title" => "الأصناف",
            "icon" => "📦",
            "type" => "link",
            "href" => "inventory.php",
            "accent_start" => "#4338ca",
            "accent_end" => "#6366f1",
            "description" => "إدارة المخزون والأصناف",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "sales",
            "title" => "المبيعات",
            "icon" => "🛒",
            "type" => "link",
            "href" => "sales.php",
            "accent_start" => "#c026d3",
            "accent_end" => "#d946ef",
            "description" => "متابعة عمليات البيع",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "swimmer_store",
            "title" => "المتجر",
            "icon" => "🛍️",
            "type" => "link",
            "href" => "swimmer_store.php",
            "accent_start" => "#7c3aed",
            "accent_end" => "#c084fc",
            "description" => "إدارة منتجات متجر السباحين",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "swimmer_card_requests",
            "title" => "طلبات",
            "icon" => "🪪",
            "type" => "link",
            "href" => "swimmer_card_requests.php",
            "accent_start" => "#0f766e",
            "accent_end" => "#2dd4bf",
            "description" => "متابعة طلبات إنشاء الكارنية",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "swimmer_notifications",
            "title" => "اشعارات السباح",
            "icon" => "🔔",
            "type" => "link",
            "href" => "swimmer_notifications.php",
            "accent_start" => "#b45309",
            "accent_end" => "#f59e0b",
            "description" => "إدارة إشعارات السباحين",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "offers",
            "title" => "العروض",
            "icon" => "🎁",
            "type" => "link",
            "href" => "offers.php",
            "accent_start" => "#be185d",
            "accent_end" => "#ec4899",
            "description" => "إدارة العروض الظاهرة للسباحين",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "group_evaluations",
            "title" => "تقييم المجموعات",
            "icon" => "📝",
            "type" => "link",
            "href" => "group_evaluations.php",
            "accent_start" => "#1d4ed8",
            "accent_end" => "#60a5fa",
            "description" => "متابعة تقييم المجموعات شهريًا",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "coach_notifications",
            "title" => "اشعارات المدربين",
            "icon" => "📣",
            "type" => "link",
            "href" => "coach_notifications.php",
            "accent_start" => "#7c2d12",
            "accent_end" => "#f97316",
            "description" => "إدارة إشعارات المدربين",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "expenses",
            "title" => "المصروفات",
            "icon" => "🧾",
            "type" => "link",
            "href" => "expenses.php",
            "accent_start" => "#be123c",
            "accent_end" => "#fb7185",
            "description" => "تسجيل ومتابعة المصروفات اليومية",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "academies",
            "title" => "الأكاديميات",
            "icon" => "🌊",
            "type" => "link",
            "href" => "academies.php",
            "accent_start" => "#0369a1",
            "accent_end" => "#0ea5e9",
            "description" => "إدارة بيانات الأكاديميات",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "reports",
            "title" => "الإحصائيات",
            "icon" => "📊",
            "type" => "link",
            "href" => "reports.php",
            "accent_start" => "#7c3aed",
            "accent_end" => "#a855f7",
            "description" => "عرض الإحصائيات والتقارير",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "dashboard_statistics",
            "title" => "إحصائيات لوحة التحكم",
            "icon" => "📈",
            "type" => "permission",
            "accent_start" => "#4f46e5",
            "accent_end" => "#818cf8",
            "description" => "عرض بطاقات الإحصائيات داخل لوحة التحكم",
            "always_visible" => false,
            "editable_permission" => true,
            "show_in_menu" => false,
        ],
        [
            "key" => "daily_close",
            "title" => "التقفيل اليومي",
            "icon" => "📅",
            "type" => "link",
            "href" => "close_cycle.php?cycle=daily",
            "accent_start" => "#047857",
            "accent_end" => "#22c55e",
            "description" => "مراجعة وإغلاق العمليات اليومية",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "weekly_close",
            "title" => "التقفيل الأسبوعي",
            "icon" => "📆",
            "type" => "link",
            "href" => "close_cycle.php?cycle=weekly",
            "accent_start" => "#0f766e",
            "accent_end" => "#14b8a6",
            "description" => "مراجعة وإغلاق العمليات الأسبوعية",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "monthly_close",
            "title" => "التقفيل الشهري",
            "icon" => "🗓️",
            "type" => "link",
            "href" => "close_cycle.php?cycle=monthly",
            "accent_start" => "#8b5cf6",
            "accent_end" => "#6366f1",
            "description" => "إغلاق الدورة الشهرية للنظام",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "settings",
            "title" => "إعدادات الموقع",
            "icon" => "⚙️",
            "type" => "link",
            "href" => "settings.php",
            "accent_start" => "#334155",
            "accent_end" => "#64748b",
            "description" => "ضبط إعدادات النظام العامة",
            "always_visible" => false,
            "editable_permission" => true,
        ],
        [
            "key" => "logout",
            "title" => "تسجيل الخروج",
            "icon" => "🚪",
            "type" => "link",
            "href" => "logout.php",
            "accent_start" => "#dc2626",
            "accent_end" => "#ef4444",
            "description" => "الخروج الآمن من النظام",
            "always_visible" => true,
            "editable_permission" => false,
        ],
    ];
}

function getEditablePermissionItems(): array
{
    return array_values(array_filter(
        getAcademyMenuItems(),
        static fn(array $item): bool => !empty($item["editable_permission"])
    ));
}

function getEditableMenuPermissionItems(): array
{
    return array_values(array_filter(
        getEditablePermissionItems(),
        static fn(array $item): bool => ($item["show_in_menu"] ?? true) === true
    ));
}

function getDashboardStatisticItems(): array
{
    return [
        [
            "key" => "total_users",
            "title" => "إجمالي السباحين",
            "icon" => "👥",
            "description" => "عرض إجمالي عدد السباحين المسجلين فعليًا في صفحة السباحين",
            "card_class" => "users-card",
        ],
        [
            "key" => "new_players_today",
            "title" => "السباحين الجدد اليوم",
            "icon" => "🌟",
            "description" => "عرض عدد السباحين الجدد الذين تمت إضافتهم اليوم",
            "card_class" => "today-card",
        ],
        [
            "key" => "new_players_week",
            "title" => "السباحين الجدد خلال الأسبوع",
            "icon" => "📅",
            "description" => "عرض عدد السباحين الجدد خلال آخر أسبوع",
            "card_class" => "week-card",
        ],
        [
            "key" => "new_players_month",
            "title" => "السباحين الجدد خلال الشهر",
            "icon" => "📈",
            "description" => "عرض عدد السباحين الجدد خلال الشهر الحالي",
            "card_class" => "month-card",
        ],
    ];
}

function getDashboardStatisticKeys(): array
{
    return array_column(getDashboardStatisticItems(), "key");
}

function normalizeDashboardStatisticPermissions($permissions): array
{
    if (!is_array($permissions)) {
        return [];
    }

    $availableStatisticKeys = getDashboardStatisticKeys();
    $cleanPermissions = [];

    foreach ($permissions as $permissionKey) {
        if (is_string($permissionKey) && in_array($permissionKey, $availableStatisticKeys, true)) {
            $cleanPermissions[] = $permissionKey;
        }
    }

    return array_values(array_unique($cleanPermissions));
}

function parsePermissionSettings($permissions): array
{
    if (is_string($permissions) && $permissions !== "") {
        $decoded = json_decode($permissions, true);
        $permissions = is_array($decoded) ? $decoded : [];
    }

    $menuPermissionKeys = array_column(getEditableMenuPermissionItems(), "key");
    $dashboardStatisticKeys = getDashboardStatisticKeys();

    $normalizeMenuPermissions = static function ($items) use ($menuPermissionKeys): array {
        if (!is_array($items)) {
            return [];
        }

        $cleanPermissions = [];
        foreach ($items as $permissionKey) {
            if ($permissionKey === "players") {
                $permissionKey = "academy_players";
            }

            if (is_string($permissionKey) && in_array($permissionKey, $menuPermissionKeys, true)) {
                $cleanPermissions[] = $permissionKey;
            }
        }

        return array_values(array_unique($cleanPermissions));
    };

    if (is_array($permissions) && array_key_exists("menu_items", $permissions)) {
        $normalizedDashboardStatistics = normalizeDashboardStatisticPermissions($permissions["dashboard_cards"] ?? []);
        $hasDashboardCards = array_key_exists("dashboard_cards", $permissions);
        $dashboardStatisticsEnabled = !empty($normalizedDashboardStatistics);

        if (!$hasDashboardCards && !empty($permissions["dashboard_statistics"])) {
            $dashboardStatisticsEnabled = true;
            $normalizedDashboardStatistics = $dashboardStatisticKeys;
        }

        return [
            "menu_permissions" => $normalizeMenuPermissions($permissions["menu_items"] ?? []),
            "dashboard_statistics" => $dashboardStatisticsEnabled,
            "dashboard_statistic_permissions" => $normalizedDashboardStatistics,
        ];
    }

    $legacyPermissions = $normalizeMenuPermissions($permissions);
    $legacyDashboardStatisticsEnabled = usesLegacyReportsStatisticsAccess($legacyPermissions);

    return [
        "menu_permissions" => $legacyPermissions,
        "dashboard_statistics" => $legacyDashboardStatisticsEnabled,
        "dashboard_statistic_permissions" => $legacyDashboardStatisticsEnabled ? $dashboardStatisticKeys : [],
    ];
}

function normalizePermissions($permissions): array
{
    return parsePermissionSettings($permissions)["menu_permissions"];
}

function canViewStoredDashboardStatistics($permissions): bool
{
    return parsePermissionSettings($permissions)["dashboard_statistics"];
}

function getStoredDashboardStatisticPermissions($permissions): array
{
    return parsePermissionSettings($permissions)["dashboard_statistic_permissions"];
}

function usesLegacyReportsStatisticsAccess(array $menuPermissions): bool
{
    return in_array("reports", $menuPermissions, true);
}

function encodePermissions(
    array|string|null $permissions,
    ?bool $canViewDashboardStatistics = null,
    array|string|null $dashboardStatisticPermissions = null
): string
{
    $menuPermissions = normalizePermissions($permissions);
    $normalizedDashboardStatistics = $dashboardStatisticPermissions === null
        ? []
        : normalizeDashboardStatisticPermissions($dashboardStatisticPermissions);
    $dashboardStatisticsEnabled = $dashboardStatisticPermissions === null
        ? ($canViewDashboardStatistics ?? usesLegacyReportsStatisticsAccess($menuPermissions))
        : ($canViewDashboardStatistics ?? !empty($normalizedDashboardStatistics));

    if ($dashboardStatisticPermissions === null && $dashboardStatisticsEnabled) {
        $normalizedDashboardStatistics = getDashboardStatisticKeys();
    }

    if (!$dashboardStatisticsEnabled) {
        $normalizedDashboardStatistics = [];
    }

    return json_encode([
        "menu_items" => $menuPermissions,
        "dashboard_statistics" => $dashboardStatisticsEnabled,
        "dashboard_cards" => $normalizedDashboardStatistics,
    ], JSON_UNESCAPED_UNICODE);
}

function loadCurrentUser(PDO $pdo): ?array
{
    if (!isset($_SESSION["user_id"])) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id, username, role, permissions FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION["user_id"]]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return null;
    }

    $permissionSettings = parsePermissionSettings($user["permissions"] ?? []);
    $user["permissions"] = $permissionSettings["menu_permissions"];
    $user["can_view_dashboard_statistics"] = $permissionSettings["dashboard_statistics"];
    $user["dashboard_statistics_permissions"] = $permissionSettings["dashboard_statistic_permissions"];

    $_SESSION["user"] = $user["username"];
    $_SESSION["role"] = $user["role"];
    $_SESSION["permissions"] = $user["permissions"];
    $_SESSION["can_view_dashboard_statistics"] = $user["can_view_dashboard_statistics"];
    $_SESSION["dashboard_statistics_permissions"] = $user["dashboard_statistics_permissions"];

    return $user;
}

function userCanAccess(array $user, string $menuKey): bool
{
    if (($user["role"] ?? "") === "مدير") {
        return true;
    }

    if (in_array($menuKey, ["dashboard", "logout"], true)) {
        return true;
    }

    $permissions = $user["permissions"] ?? [];

    if (in_array($menuKey, $permissions, true)) {
        return true;
    }

    return $menuKey === "academy_players" && in_array("players", $permissions, true);
}

function getVisibleMenuItems(array $user): array
{
    return array_values(array_filter(
        getAcademyMenuItems(),
        static fn(array $item): bool => ($item["show_in_menu"] ?? true) === true
            && userCanAccess($user, $item["key"])
    ));
}

function canViewDashboardStatistics(array $user): bool
{
    if (($user["role"] ?? "") === "مدير") {
        return true;
    }

    return !empty($user["can_view_dashboard_statistics"])
        && !empty($user["dashboard_statistics_permissions"]);
}

function canUserViewDashboardStatistic(array $user, string $statisticKey): bool
{
    if (($user["role"] ?? "") === "مدير") {
        return true;
    }

    return canViewDashboardStatistics($user)
        && in_array($statisticKey, $user["dashboard_statistics_permissions"] ?? [], true);
}

function countEnabledCapabilities(
    array $permissions,
    bool $canViewDashboardStatistics,
    array $dashboardStatisticPermissions = []
): int
{
    if ($canViewDashboardStatistics && !empty($dashboardStatisticPermissions)) {
        return count($permissions) + count($dashboardStatisticPermissions);
    }

    return count($permissions) + ($canViewDashboardStatistics ? 1 : 0);
}

function permissionCountLabel(array|int $countOrPermissions): string
{
    $count = is_int($countOrPermissions) ? $countOrPermissions : count($countOrPermissions);

    if ($count === 0) {
        return "بدون صلاحيات تشغيلية";
    }

    if ($count === 1) {
        return "صلاحية واحدة مفعّلة";
    }

    if ($count === 2) {
        return "صلاحيتان مفعّلتان";
    }

    if ($count >= 3 && $count <= 10) {
        return $count . " صلاحيات مفعّلة";
    }

    return $count . " صلاحية مفعّلة";
}

function isValidSwimmerAccountPassword(string $password): bool
{
    $passwordLength = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
    return $passwordLength >= SWIMMER_ACCOUNT_MIN_PASSWORD_LENGTH;
}

function getArabicWeekdayLabels(): array
{
    return [
        'Saturday' => 'السبت',
        'Sunday' => 'الأحد',
        'Monday' => 'الاثنين',
        'Tuesday' => 'الثلاثاء',
        'Wednesday' => 'الأربعاء',
        'Thursday' => 'الخميس',
        'Friday' => 'الجمعة',
    ];
}

function getAcademyScheduleWeekdayLabels(): array
{
    return [
        'saturday' => 'السبت',
        'sunday' => 'الأحد',
        'monday' => 'الاثنين',
        'tuesday' => 'الثلاثاء',
        'wednesday' => 'الأربعاء',
        'thursday' => 'الخميس',
        'friday' => 'الجمعة',
    ];
}

function decodeAcademyTrainingSchedule(?string $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decodedValue = json_decode($value, true);
    if (!is_array($decodedValue)) {
        return [];
    }

    $dayLabels = getAcademyScheduleWeekdayLabels();
    $schedule = [];
    $additionalScheduleItems = [];

    foreach ($decodedValue as $item) {
        if (!is_array($item)) {
            continue;
        }

        $dayKey = strtolower(trim((string) ($item['key'] ?? '')));
        $label = normalizeAcademyHelperText((string) ($item['label'] ?? ($dayLabels[$dayKey] ?? '')));
        $time = formatAcademyTimeTo12Hour((string) ($item['time'] ?? ''));
        if ($label === '' || $time === '') {
            continue;
        }

        $normalizedItem = [
            'label' => $label,
            'time' => $time,
        ];

        if ($dayKey !== '' && isset($dayLabels[$dayKey])) {
            $schedule[$dayKey] = $normalizedItem;
            continue;
        }

        $additionalScheduleItems[] = $normalizedItem;
    }

    $orderedSchedule = [];
    foreach ($dayLabels as $dayKey => $label) {
        if (isset($schedule[$dayKey])) {
            $orderedSchedule[] = $schedule[$dayKey];
        }
    }

    return array_merge($orderedSchedule, $additionalScheduleItems);
}

function formatAcademyTrainingSchedule(?string $value): string
{
    $rawValue = trim((string) $value);
    $schedule = decodeAcademyTrainingSchedule($value);
    $normalizedValue = normalizeAcademyHelperText($rawValue);
    if ($schedule === []) {
        if ($normalizedValue === '') {
            return '—';
        }

        if ($rawValue !== '' && ($rawValue[0] === '[' || $rawValue[0] === '{')) {
            return '—';
        }

        return $normalizedValue;
    }

    $parts = [];
    foreach ($schedule as $item) {
        $label = trim((string) ($item['label'] ?? ''));
        $time = trim((string) ($item['time'] ?? ''));
        if ($label === '' || $time === '') {
            continue;
        }

        $parts[] = $label . ' - ' . $time;
    }

    return $parts !== [] ? implode(' • ', $parts) : '—';
}

function decodeStoredWeekdays(?string $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decodedValue = json_decode($value, true);
    if (!is_array($decodedValue)) {
        return [];
    }

    $allowedDays = array_keys(getArabicWeekdayLabels());
    $storedDays = [];

    foreach ($decodedValue as $dayName) {
        if (is_string($dayName) && in_array($dayName, $allowedDays, true)) {
            $storedDays[] = $dayName;
        }
    }

    return array_values(array_unique($storedDays));
}

function isStoredWeekdaySelected(?string $value, string $dayName): bool
{
    return in_array($dayName, decodeStoredWeekdays($value), true);
}

function getDescendingDailyRecordBounds(array $dailyRecords): array
{
    if ($dailyRecords === []) {
        return [
            'period_start' => null,
            'period_end' => null,
        ];
    }

    $dailyRecordsCount = count($dailyRecords);

    return [
        'period_start' => (string) ($dailyRecords[$dailyRecordsCount - 1]['date'] ?? null),
        'period_end' => (string) ($dailyRecords[0]['date'] ?? null),
    ];
}

function getDefaultSiteSettings(): array
{
    return [
        'id' => null,
        'academy_name' => 'megz Academy',
        'academy_logo_path' => null,
        'facebook_url' => null,
        'whatsapp_url' => null,
        'youtube_url' => null,
        'tiktok_url' => null,
        'instagram_url' => null,
    ];
}

function getSiteSettings(PDO $pdo): array
{
    $defaultSettings = getDefaultSiteSettings();

    try {
        $settingsStmt = $pdo->query("
            SELECT id, academy_name, academy_logo_path, facebook_url, whatsapp_url, youtube_url, tiktok_url, instagram_url
            FROM site_settings
            ORDER BY id ASC
            LIMIT 1
        ");
        $settings = $settingsStmt ? $settingsStmt->fetch(PDO::FETCH_ASSOC) : false;
    } catch (PDOException $exception) {
        return $defaultSettings;
    }

    if (!is_array($settings)) {
        return $defaultSettings;
    }

    $academyName = trim((string) ($settings['academy_name'] ?? ''));
    $academyLogoPath = trim((string) ($settings['academy_logo_path'] ?? ''));
    $facebookUrl = trim((string) ($settings['facebook_url'] ?? ''));
    $whatsappUrl = trim((string) ($settings['whatsapp_url'] ?? ''));
    $youtubeUrl = trim((string) ($settings['youtube_url'] ?? ''));
    $tiktokUrl = trim((string) ($settings['tiktok_url'] ?? ''));
    $instagramUrl = trim((string) ($settings['instagram_url'] ?? ''));

    return [
        'id' => isset($settings['id']) ? (int) $settings['id'] : null,
        'academy_name' => $academyName !== '' ? $academyName : $defaultSettings['academy_name'],
        'academy_logo_path' => $academyLogoPath !== '' ? $academyLogoPath : null,
        'facebook_url' => $facebookUrl !== '' ? $facebookUrl : null,
        'whatsapp_url' => $whatsappUrl !== '' ? $whatsappUrl : null,
        'youtube_url' => $youtubeUrl !== '' ? $youtubeUrl : null,
        'tiktok_url' => $tiktokUrl !== '' ? $tiktokUrl : null,
        'instagram_url' => $instagramUrl !== '' ? $instagramUrl : null,
    ];
}

function normalizeSiteSettingsUrl(string $value): ?string
{
    $value = normalizeAcademyHelperText($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('~^[a-z][a-z0-9+\-.]*://~i', $value) !== 1) {
        $value = 'https://' . ltrim($value, '/');
    }

    $sanitizedUrl = filter_var($value, FILTER_SANITIZE_URL);
    if (!is_string($sanitizedUrl) || $sanitizedUrl === '') {
        return null;
    }

    $validatedUrl = filter_var($sanitizedUrl, FILTER_VALIDATE_URL);
    if (!is_string($validatedUrl) || $validatedUrl === '') {
        return null;
    }

    $parsedUrl = parse_url($validatedUrl);
    if (!is_array($parsedUrl) || empty($parsedUrl['scheme']) || empty($parsedUrl['host'])) {
        return null;
    }

    $scheme = strtolower((string) $parsedUrl['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    return $validatedUrl;
}

function normalizeSiteSettingsWhatsappUrl(string $value): ?string
{
    $value = normalizeAcademyHelperText($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('~^(?:https?:)?//~i', $value) === 1) {
        return normalizeSiteSettingsUrl($value);
    }

    $phone = formatAcademyWhatsappPhone($value);
    if ($phone === '') {
        return null;
    }

    return 'https://wa.me/' . $phone;
}

function normalizeSiteSettingsSocialLink(string $platform, string $value): ?string
{
    return $platform === 'whatsapp'
        ? normalizeSiteSettingsWhatsappUrl($value)
        : normalizeSiteSettingsUrl($value);
}

function getAcademyLogoInitial(string $academyName): string
{
    $academyName = trim($academyName);
    if ($academyName === '') {
        return 'A';
    }

    if (function_exists('mb_substr')) {
        $firstCharacter = mb_substr($academyName, 0, 1, 'UTF-8');
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($firstCharacter, 'UTF-8');
        }

        if (function_exists('mb_convert_case')) {
            return mb_convert_case($firstCharacter, MB_CASE_UPPER, 'UTF-8');
        }

        return preg_match('/^[a-z]$/i', $firstCharacter) === 1
            ? strtoupper($firstCharacter)
            : $firstCharacter;
    }

    return strtoupper(substr($academyName, 0, 1));
}

function formatPaymentSnapshotAmount($value): string
{
    return number_format((float) $value, 2, '.', '');
}

function recordAcademyPlayerPayment(PDO $pdo, array $paymentData): void
{
    $playerId = (int) ($paymentData['player_id'] ?? 0);
    $paymentType = trim((string) ($paymentData['payment_type'] ?? ''));

    if ($playerId <= 0 || $paymentType === '') {
        throw new InvalidArgumentException('بيانات حركة السداد غير مكتملة: player_id أو payment_type');
    }

    $receiptNumber = isset($paymentData['receipt_number']) ? trim((string) $paymentData['receipt_number']) : '';
    $playerNameSnapshot = isset($paymentData['player_name_snapshot']) ? trim((string) $paymentData['player_name_snapshot']) : '';
    $subscriptionNameSnapshot = isset($paymentData['subscription_name_snapshot']) ? trim((string) $paymentData['subscription_name_snapshot']) : '';

    $insertStmt = $pdo->prepare(
        'INSERT INTO academy_player_payments (
            player_id,
            payment_type,
            amount,
            receipt_number,
            created_by_user_id,
            player_name_snapshot,
            subscription_name_snapshot,
            subscription_amount_snapshot,
            paid_amount_before_snapshot,
            paid_amount_after_snapshot,
            remaining_amount_before_snapshot,
            remaining_amount_after_snapshot
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $insertStmt->execute([
        $playerId,
        $paymentType,
        formatPaymentSnapshotAmount($paymentData['amount'] ?? 0),
        $receiptNumber !== '' ? $receiptNumber : null,
        (int) ($paymentData['created_by_user_id'] ?? 0) ?: null,
        $playerNameSnapshot !== '' ? $playerNameSnapshot : null,
        $subscriptionNameSnapshot !== '' ? $subscriptionNameSnapshot : null,
        formatPaymentSnapshotAmount($paymentData['subscription_amount_snapshot'] ?? 0),
        formatPaymentSnapshotAmount($paymentData['paid_amount_before_snapshot'] ?? 0),
        formatPaymentSnapshotAmount($paymentData['paid_amount_after_snapshot'] ?? 0),
        formatPaymentSnapshotAmount($paymentData['remaining_amount_before_snapshot'] ?? 0),
        formatPaymentSnapshotAmount($paymentData['remaining_amount_after_snapshot'] ?? 0),
    ]);
}

function getSalesInvoiceSettlementPaymentNote(): string
{
    return 'سداد رصيد الفاتورة';
}

function attachSalesInvoiceDetails(PDO $pdo, array $rows, callable $moneyFormatter): array
{
    if ($rows === []) {
        return [];
    }

    $invoiceIds = [];

    foreach ($rows as $row) {
        if (!isset($row['id'])) {
            continue;
        }

        $invoiceId = (int) $row['id'];
        if ($invoiceId > 0) {
            $invoiceIds[] = $invoiceId;
        }
    }

    if ($invoiceIds === []) {
        return $rows;
    }

    $placeholders = implode(', ', array_fill(0, count($invoiceIds), '?'));
    $detailsStmt = $pdo->prepare("
        SELECT invoice_id, item_name, quantity, unit_sale_price
        FROM sales_invoice_items
        WHERE invoice_id IN ($placeholders)
        ORDER BY invoice_id ASC, id ASC
    ");
    $detailsStmt->execute($invoiceIds);

    $detailsMap = [];

    foreach ($detailsStmt->fetchAll(PDO::FETCH_ASSOC) as $detailRow) {
        $invoiceId = (int) ($detailRow['invoice_id'] ?? 0);
        if (!isset($detailsMap[$invoiceId])) {
            $detailsMap[$invoiceId] = [
                'names' => [],
                'quantities' => [],
                'prices' => [],
            ];
        }

        $detailsMap[$invoiceId]['names'][] = trim((string) ($detailRow['item_name'] ?? '')) ?: '-';
        $detailsMap[$invoiceId]['quantities'][] = (string) ((int) ($detailRow['quantity'] ?? 0));
        $detailsMap[$invoiceId]['prices'][] = call_user_func($moneyFormatter, (float) ($detailRow['unit_sale_price'] ?? 0)) . ' ج.م';
    }

    foreach ($rows as &$row) {
        $invoiceId = (int) ($row['id'] ?? 0);
        $details = $detailsMap[$invoiceId] ?? null;
        $row['item_names'] = $details !== null && $details['names'] !== []
            ? implode('، ', $details['names'])
            : '-';
        $row['item_quantities'] = $details !== null && $details['quantities'] !== []
            ? implode('، ', $details['quantities'])
            : '-';
        $row['item_sale_prices'] = $details !== null && $details['prices'] !== []
            ? implode('، ', $details['prices'])
            : '-';
    }
    unset($row);

    return $rows;
}
