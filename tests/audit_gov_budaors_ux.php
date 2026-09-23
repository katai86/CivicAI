<?php
/**
 * Local gov UX audit as Budaörs govuser (no password needed — session bootstrap).
 * Run: php tests/audit_gov_budaors_ux.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

header('Content-Type: text/plain; charset=utf-8');

$email = 'budaors@civicai.hu';
$st = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
$st->execute([$email]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "FAIL: user $email not found\n");
    exit(1);
}

$uid = (int)$user['id'];
$link = db()->prepare('SELECT authority_id, role FROM authority_users WHERE user_id = ?');
$link->execute([$uid]);
$auths = $link->fetchAll(PDO::FETCH_ASSOC);
$aid = (int)($auths[0]['authority_id'] ?? 0);

echo "=== USER ===\n";
echo "id=$uid email={$user['email']} role={$user['role']}\n";
echo "authorities=" . json_encode($auths, JSON_UNESCAPED_UNICODE) . "\n";
echo "primary_aid=$aid\n\n";

$_SESSION = [];
$_SESSION['user_id'] = $uid;
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_role'] = $user['role'];
$_SESSION['user_name'] = $user['display_name'] ?? $user['email'];

function hit(string $path, array $q = []): array
{
    $qs = $q ? ('?' . http_build_query($q)) : '';
    $url = rtrim(APP_BASE_URL, '/') . $path . $qs;
    // Internal include simulation via curl to web
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_COOKIE => session_name() . '=' . session_id(),
    ]);
    // We can't easily share PHP session with curl — call services directly instead.
    curl_close($ch);
    return ['url' => $url];
}

// Direct service / API logic checks
require_once __DIR__ . '/../services/ClimateIndexService.php';
require_once __DIR__ . '/../services/cityintel/CitySituationEngine.php';
require_once __DIR__ . '/../services/cityintel/CityIntelligenceOrchestrator.php';
require_once __DIR__ . '/../services/IntelligenceModuleRegistry.php';

$ok = 0;
$fail = 0;
function check(string $name, bool $pass, string $detail = ''): void
{
    global $ok, $fail;
    if ($pass) {
        echo "OK   $name" . ($detail !== '' ? " — $detail" : '') . "\n";
        $ok++;
    } else {
        echo "FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
        $fail++;
    }
}

echo "=== MODULE SETTINGS COLUMN ===\n";
$col = module_settings_value_column();
check('module_settings column', in_array($col, ['value', 'setting_value'], true), $col);
$sample = get_module_setting('climate_gfw', 'enabled');
echo "climate_gfw.enabled=" . var_export($sample, true) . "\n\n";

echo "=== CLIMATE INDEX ===\n";
$ci = (new ClimateIndexService())->compute($aid);
check('climate score', isset($ci['score']), 'score=' . ($ci['score'] ?? '?'));
$engSlug = false;
foreach ($ci['components'] ?? [] as $k => $c) {
    $lab = (string)($c['label'] ?? '');
    if ($lab === $k || str_contains($lab, '_')) {
        $engSlug = true;
        echo "  slug-ish: $k => $lab\n";
    } else {
        echo "  label: $k => $lab\n";
    }
}
check('climate component labels localized', !$engSlug);

echo "\n=== CITY SITUATION ===\n";
$sit = (new CitySituationEngine())->build($aid);
$markers = $sit['markers'] ?? [];
check('situation markers > 0', count($markers) > 0, 'count=' . count($markers));
$rawSlug = false;
$withCoords = 0;
foreach ($markers as $m) {
    $lab = (string)($m['label'] ?? '');
    echo "  [{$m['type']}] $lab score=" . ($m['score'] ?? '') . (isset($m['lat']) ? " @{$m['lat']},{$m['lng']}" : '') . "\n";
    if (preg_match('/\b(road|observation|report_category|grid_l?_)\b/i', $lab)) {
        $rawSlug = true;
    }
    if (isset($m['lat'], $m['lng'])) {
        $withCoords++;
    }
}
check('situation labels human', !$rawSlug);
check('situation has mappable points', $withCoords > 0, "coords=$withCoords");

echo "\n=== CITY INTEL DASHBOARD ===\n";
try {
    $orch = new CityIntelligenceOrchestrator();
    $dash = $orch->dashboard($aid);
    check('ci dashboard ok', !empty($dash) || is_array($dash), 'keys=' . implode(',', array_keys($dash ?: [])));
    $insights = $dash['insights'] ?? [];
    $sources = $dash['sources'] ?? ($dash['source_status'] ?? []);
    echo "insights=" . (is_array($insights) ? count($insights) : 0) . "\n";
    if (is_array($sources)) {
        $okSrc = 0; $errSrc = 0; $stale = 0;
        foreach ($sources as $s) {
            $st = is_array($s) ? ($s['status'] ?? '') : '';
            if ($st === 'ok') $okSrc++;
            elseif ($st === 'error') $errSrc++;
            elseif ($st === 'stale') $stale++;
        }
        echo "sources ok=$okSrc err=$errSrc stale=$stale total=" . count($sources) . "\n";
    }
} catch (Throwable $e) {
    check('ci dashboard ok', false, $e->getMessage());
}

echo "\n=== REPORTS FOR AUTHORITY ===\n";
$rc = (int)db()->prepare('SELECT COUNT(*) FROM reports WHERE authority_id=?')->execute([$aid]) ?: 0;
$st = db()->prepare('SELECT COUNT(*) FROM reports WHERE authority_id=?');
$st->execute([$aid]);
$rc = (int)$st->fetchColumn();
$st = db()->prepare("SELECT category, COUNT(*) c FROM reports WHERE authority_id=? GROUP BY category");
$st->execute([$aid]);
$byCat = $st->fetchAll(PDO::FETCH_ASSOC);
check('reports for Budaörs', $rc > 0, "count=$rc");
foreach ($byCat as $r) {
    echo "  cat={$r['category']} n={$r['c']}\n";
}

echo "\n=== PREDICTIONS API LOGIC ===\n";
$predFile = __DIR__ . '/../api/predictions.php';
if (!is_file($predFile)) {
    $predFile = __DIR__ . '/../api/citybrain_predictions.php';
}
// Find predictions endpoint
$candidates = [
    __DIR__ . '/../api/predictions.php',
    __DIR__ . '/../api/gov_predictions.php',
    __DIR__ . '/../api/citybrain_dashboard.php',
];
foreach (glob(__DIR__ . '/../api/*predict*') ?: [] as $f) {
    $candidates[] = $f;
}
$candidates = array_unique($candidates);
echo "prediction endpoints: " . implode(', ', array_map('basename', $candidates)) . "\n";

echo "\n=== INTEL MODULES ===\n";
try {
    $mods = IntelligenceModuleRegistry::listWithStatus();
    $active = 0; $inactive = 0; $nodata = 0;
    foreach ($mods as $m) {
        $en = !empty($m['enabled']);
        $okm = !empty($m['ok']);
        if ($en && $okm) $active++;
        elseif ($en) $nodata++;
        else $inactive++;
        $name = $m['id'] ?? $m['key'] ?? '?';
        $status = ($m['status'] ?? '') . (empty($m['ok']) ? ' no_live' : ' live');
        if (!$okm && $en) {
            echo "  WEAK $name — " . ($m['message'] ?? $status) . "\n";
        }
    }
    echo "active_live≈$active enabled_no_data≈$nodata inactive≈$inactive total=" . count($mods) . "\n";
    check('has some modules', count($mods) > 0);
} catch (Throwable $e) {
    check('intel modules', false, $e->getMessage());
}

echo "\n=== GOV HTML CHECKS ===\n";
$html = file_get_contents(__DIR__ . '/../gov/index.php');
check('City Brain before Ügyek in source',
    (bool)preg_match('/nav_section_legacy[\s\S]{0,800}?nav_section_work/', $html)
);
check('predictive uses govFormatPredictedIssueLine', str_contains($html, 'govFormatPredictedIssueLine'));
check('climate chart uses labelFor', str_contains($html, 'labelFor'));
check('env_no_iot message wired', str_contains($html, 'env_no_iot'));

echo "\n=== SUMMARY ok=$ok fail=$fail ===\n";
exit($fail > 0 ? 1 : 0);
