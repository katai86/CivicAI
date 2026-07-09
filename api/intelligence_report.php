<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/IntelligenceReportGenerator.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$in = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (json_decode(file_get_contents('php://input') ?: '{}', true) ?: $_POST)
    : $_GET;

$type = trim((string)($in['type'] ?? 'full'));
$audience = trim((string)($in['audience'] ?? 'official'));
$aid = gov_primary_authority_id();
if (isset($in['authority_id']) && (int)$in['authority_id'] > 0) {
    $req = (int)$in['authority_id'];
    if (in_array(current_user_role() ?: '', ['admin', 'superadmin'], true)) {
        $aid = $req;
    }
}

$gen = new IntelligenceReportGenerator();
try {
    $report = $gen->generate([
        'type' => $type,
        'audience' => $audience,
        'authority_id' => $aid,
    ]);
} catch (Throwable $e) {
    if (function_exists('log_error')) {
        log_error('intelligence_report: ' . $e->getMessage());
    }
    json_response(['ok' => false, 'error' => t('common.error_load')], 500);
}

$format = trim((string)($in['format'] ?? 'json'));
if ($format === 'html') {
    header('Content-Type: text/html; charset=utf-8');
    echo $report['html'] ?? '';
    exit;
}

json_response(['ok' => true, 'data' => $report]);
