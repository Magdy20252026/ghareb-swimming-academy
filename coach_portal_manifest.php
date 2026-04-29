<?php
require_once 'config.php';
require_once 'app_helpers.php';

$siteSettings = getSiteSettings($pdo);
$academyName = (string) ($siteSettings['academy_name'] ?? 'الأكاديمية');
$installationKey = trim((string) ($_GET['i'] ?? ''));
$portalQueryString = $installationKey === '' ? '' : ('?i=' . rawurlencode($installationKey));

header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

echo json_encode([
    'id' => '/coach_portal.php' . $portalQueryString,
    'name' => 'بوابة المدربين - ' . $academyName,
    'short_name' => 'بوابة المدربين',
    'description' => 'تطبيق ويب لفتح بوابة المدربين واستقبال إشعارات الإدارة والرواتب.',
    'lang' => 'ar',
    'dir' => 'rtl',
    'start_url' => 'coach_portal.php' . $portalQueryString,
    'scope' => './',
    'display' => 'standalone',
    'background_color' => '#eff6ff',
    'theme_color' => '#2563eb',
    'icons' => [
        [
            'src' => 'coach_portal_icon.php',
            'sizes' => 'any',
            'type' => 'image/svg+xml',
            'purpose' => 'any maskable',
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
