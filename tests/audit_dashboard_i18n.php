<?php
/**
 * Regression scan for hardcoded user-visible strings in dashboard UIs.
 * Run: php tests/audit_dashboard_i18n.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$targets = [
    'gov/index.php',
    'assets/app.js',
    'admin/admin.js',
    'admin/login.php',
    'admin/index.php',
];

$patterns = [
    'HU literal (report modal)' => '/bejelentés ellenőrzés/i',
    'HU trees unit' => "/\\+ '\\s*fa'/",
    'EN hotspot fallback' => "/\\|\\|\\s*'Hotspot'/",
    'EN cross badge' => "/>cross<\\/span>/",
    'HU KSH green' => '/KSH zöldterület/',
    'HU Ismeretlen hiba' => "/'Ismeretlen hiba'/",
    'HU szenzor literal' => "/' szenzor'/",
    'EN Avg health hardcoded' => "/'Avg health: '/",
    'HU title suffix' => '/– rövid cím/',
    'HU Leírás label' => '/<label id="mDescLabel">Leírás<\\/label>/',
    'Admin brand hardcoded' => '/CivicAI – Admin<\\/b>/',
];

$fail = 0;
foreach ($targets as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        echo "SKIP missing $rel\n";
        continue;
    }
    $content = file_get_contents($path);
    foreach ($patterns as $label => $regex) {
        if (preg_match($regex, $content)) {
            echo "FAIL [$rel] $label\n";
            $fail++;
        }
    }
}

if ($fail === 0) {
    echo "OK: no known hardcoded dashboard strings found.\n";
    exit(0);
}

echo "\n$fail issue(s) found.\n";
exit(1);
