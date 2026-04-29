<?php
require_once 'config.php';
require_once 'app_helpers.php';

$siteSettings = getSiteSettings($pdo);
$academyName = (string) ($siteSettings['academy_name'] ?? 'الأكاديمية');
$academyLogoInitial = htmlspecialchars(getAcademyLogoInitial($academyName), ENT_QUOTES, 'UTF-8');

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: public, max-age=86400');

echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" role="img" aria-label="بوابة المدربين">
    <defs>
        <linearGradient id="portalGradient" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#f97316" />
            <stop offset="100%" stop-color="#2563eb" />
        </linearGradient>
    </defs>
    <rect width="512" height="512" rx="120" fill="url(#portalGradient)" />
    <circle cx="256" cy="256" r="164" fill="rgba(255,255,255,0.12)" />
    <text x="50%" y="47%" text-anchor="middle" dominant-baseline="middle" font-size="190" font-family="Tahoma, Arial, sans-serif" font-weight="700" fill="#ffffff">{$academyLogoInitial}</text>
    <text x="50%" y="78%" text-anchor="middle" dominant-baseline="middle" font-size="76" font-family="Tahoma, Arial, sans-serif" font-weight="700" fill="#ffffff">🏊</text>
</svg>
SVG;
