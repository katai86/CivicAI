<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/ReportRoutingService.php';
require_once __DIR__ . '/../services/routing/RoutingRecipientRegistry.php';

require_admin();
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $reportId = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
    if ($reportId <= 0) {
        json_response(['ok' => false, 'error' => t('api.invalid_id')], 400);
    }
    $stmt = db()->prepare('
        SELECT r.id, r.case_no, r.authority_id, r.routing_target, r.routing_override_target,
               r.external_ticket_id, r.routed_at, r.category, r.road, r.city, r.status,
               a.name AS authority_name
        FROM reports r
        LEFT JOIN authorities a ON a.id = r.authority_id
        WHERE r.id = ?
        LIMIT 1
    ');
    $stmt->execute([$reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) {
        json_response(['ok' => false, 'error' => t('gov.report_not_found')], 404);
    }
    json_response([
        'ok' => true,
        'report' => $report,
        'log' => ReportRoutingService::logForReport($reportId),
        'targets' => RoutingRecipientRegistry::catalog(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$body = read_json_body();
$action = safe_str($body['action'] ?? null, 32);
$reportId = (int)($body['report_id'] ?? $body['id'] ?? 0);

if ($reportId <= 0) {
    json_response(['ok' => false, 'error' => t('api.invalid_id')], 400);
}

if ($action === 'set_authority') {
    $authorityId = isset($body['authority_id']) ? (int)$body['authority_id'] : 0;
    if (!ReportRoutingService::setAuthority($reportId, $authorityId)) {
        json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
    }
    json_response(['ok' => true, 'authority_id' => $authorityId > 0 ? $authorityId : null]);
}

if ($action === 'set_override') {
    $target = isset($body['routing_override_target']) ? trim((string)$body['routing_override_target']) : '';
    if ($target === '__auto__' || $target === '') {
        $target = null;
    }
    if ($target !== null && !in_array($target, RoutingRecipientRegistry::TARGETS, true)) {
        json_response(['ok' => false, 'error' => t('api.invalid_data')], 400);
    }
    if (!ReportRoutingService::setOverrideTarget($reportId, $target)) {
        json_response(['ok' => false, 'error' => t('common.error_save_failed')], 500);
    }
    if (isset($body['authority_id'])) {
        ReportRoutingService::setAuthority($reportId, (int)$body['authority_id']);
    }
    json_response(['ok' => true, 'routing_override_target' => $target]);
}

if ($action === 'reroute' || $action === 'save_and_reroute') {
    $opts = [];
    if (isset($body['authority_id'])) {
        $opts['authority_id'] = (int)$body['authority_id'];
    }
    if (!empty($body['force_target'])) {
        $opts['force_target'] = trim((string)$body['force_target']);
    }
    if ($action === 'save_and_reroute') {
        if (isset($body['routing_override_target'])) {
            $ov = trim((string)$body['routing_override_target']);
            if ($ov === '' || $ov === '__auto__') {
                ReportRoutingService::setOverrideTarget($reportId, null);
            } else {
                ReportRoutingService::setOverrideTarget($reportId, $ov);
                $opts['force_target'] = $ov;
            }
        }
        if (isset($body['authority_id'])) {
            ReportRoutingService::setAuthority($reportId, (int)$body['authority_id']);
        }
    }
    $result = ReportRoutingService::routeReport($reportId, $opts);
    json_response($result, empty($result['ok']) ? 500 : 200);
}

if ($action === 'reassign_geo') {
    $result = reassign_report_authority_by_location($reportId);
    json_response($result);
}

json_response(['ok' => false, 'error' => 'Unknown action'], 400);
