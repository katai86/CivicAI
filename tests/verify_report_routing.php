<?php
/**
 * Verify CIV case numbers, routing decisions, schema.
 * Run: php tests/verify_report_routing.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/ReportRoutingService.php';
require_once __DIR__ . '/../services/ProfileAuthorityLinkService.php';

$fail = 0;

function ok(string $msg): void {
    echo "OK  $msg\n";
}

function bad(string $msg): void {
    global $fail;
    echo "FAIL $msg\n";
    $fail++;
}

// --- Schema ---
$requiredTables = ['case_serials', 'report_routing_log', 'authority_join_requests'];
foreach ($requiredTables as $t) {
    try {
        $n = (int)db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . db()->quote($t))->fetchColumn();
        if ($n > 0) {
            ok("table $t");
        } else {
            bad("missing table $t");
        }
    } catch (Throwable $e) {
        bad("table check $t: " . $e->getMessage());
    }
}

$requiredCols = [
    'reports' => ['case_no', 'routing_target', 'routed_at', 'routing_override_target', 'external_ticket_id'],
    'facilities' => ['authority_id'],
    'civil_events' => ['authority_id'],
    'users' => ['municipality_city'],
];
foreach ($requiredCols as $table => $cols) {
    foreach ($cols as $col) {
        try {
            $n = (int)db()->query("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . db()->quote($table) . " AND COLUMN_NAME = " . db()->quote($col)
            )->fetchColumn();
            if ($n > 0) {
                ok("column $table.$col");
            } else {
                bad("missing column $table.$col");
            }
        } catch (Throwable $e) {
            bad("column $table.$col: " . $e->getMessage());
        }
    }
}

// --- case_city_prefix ---
$p = case_city_prefix('Budaörs');
if ($p === 'BU') {
    ok('case_city_prefix Budaörs → BU');
} else {
    bad("case_city_prefix Budaörs expected BU got $p");
}

// --- generate_case_number ---
try {
    $pdo = db();
    $cn1 = generate_case_number($pdo, 'Orosháza');
    $cn2 = generate_case_number($pdo, 'Orosháza');
    if (preg_match('/^CIV-OR-\d{8}-\d{4}$/', $cn1)) {
        ok("generate_case_number format: $cn1");
    } else {
        bad("generate_case_number bad format: $cn1");
    }
    if ($cn1 !== $cn2) {
        ok('generate_case_number increments serial');
    } else {
        bad('generate_case_number serial did not increment');
    }
} catch (Throwable $e) {
    bad('generate_case_number: ' . $e->getMessage());
}

// --- case_number stored vs legacy ---
$legacy = case_number(42, '2024-06-01 12:00:00', null);
if ($legacy === 'OH-2024-000042') {
    ok('legacy case_number OH fallback');
} else {
    bad("legacy case_number expected OH-2024-000042 got $legacy");
}
$stored = case_number(42, '2024-06-01 12:00:00', 'CIV-BU-20240601-0007');
if ($stored === 'CIV-BU-20240601-0007') {
    ok('stored case_no preferred');
} else {
    bad('stored case_no not used');
}

// --- routing decisions ---
$d1 = ReportRoutingService::decide(['category' => 'lighting', 'road' => 'Petőfi utca']);
if (($d1['target'] ?? '') === 'mvm_lumen') {
    ok('routing lighting → mvm_lumen');
} else {
    bad('routing lighting failed');
}

$d2 = ReportRoutingService::decide(['category' => 'road', 'road' => 'M7 autópálya', 'city' => 'Budaörs']);
if (($d2['target'] ?? '') === 'state_road') {
    ok('routing M7 → state_road');
} else {
    bad('routing state road failed');
}

$d3 = ReportRoutingService::decide(['category' => 'road', 'road' => 'Szabadság utca', 'city' => 'Budaörs']);
if (($d3['target'] ?? '') === 'municipal_clerk') {
    ok('routing local road → municipal_clerk');
} else {
    bad('routing municipal failed');
}

if (ReportRoutingService::isStateRoad('51-es főút')) {
    ok('isStateRoad 51-es főút');
} else {
    bad('isStateRoad heuristic');
}

// --- end-to-end routing log (no mail required) ---
try {
    $pdo = db();
    $pdo->prepare("INSERT INTO reports (category, title, description, lat, lng, road, city, status) VALUES ('road','T','D',47.46,18.95,'M1','Budaörs','new')")->execute();
    $rid = (int)$pdo->lastInsertId();
    $caseNo = generate_case_number($pdo, 'Budaörs');
    $pdo->prepare('UPDATE reports SET case_no = ? WHERE id = ?')->execute([$caseNo, $rid]);
    $res = ReportRoutingService::routeAfterCreate($rid);
    if (!empty($res['ok']) && ($res['target'] ?? '') === 'state_road') {
        ok('routeAfterCreate state_road for M1 report #' . $rid);
    } else {
        bad('routeAfterCreate M1: ' . json_encode($res));
    }
    $log = ReportRoutingService::logForReport($rid);
    if (count($log) >= 1) {
        ok('report_routing_log entry created');
    } else {
        bad('report_routing_log empty');
    }
    $pdo->prepare('DELETE FROM report_routing_log WHERE report_id = ?')->execute([$rid]);
    $pdo->prepare('DELETE FROM reports WHERE id = ?')->execute([$rid]);
} catch (Throwable $e) {
    bad('routeAfterCreate e2e: ' . $e->getMessage());
}

if ($fail === 0) {
    echo "\nAll report routing checks passed.\n";
    exit(0);
}

echo "\n$fail check(s) failed.\n";
exit(1);
