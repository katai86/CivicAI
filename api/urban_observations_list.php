<?php
/**
 * GET urban observations – authority-scoped City Brain vision history.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/UrbanObservationService.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$role = current_user_role() ?: '';
$uid = current_user_id() ? (int)current_user_id() : 0;
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
$requestedAid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;

$authorityIds = [];
if ($isAdmin) {
    if ($requestedAid > 0) {
        $authorityIds = [$requestedAid];
    } else {
        try {
            $authorityIds = array_map('intval', db()->query('SELECT id FROM authorities ORDER BY name')->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
        }
    }
} else {
    try {
        $st = db()->prepare('SELECT authority_id FROM authority_users WHERE user_id = ?');
        $st->execute([$uid]);
        $authorityIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($requestedAid > 0) {
            if (!in_array($requestedAid, $authorityIds, true)) {
                json_response(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $authorityIds = [$requestedAid];
        }
    } catch (Throwable $e) {
    }
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 40;
$rows = (new UrbanObservationService())->listRecent($authorityIds, $limit);

json_response([
    'ok' => true,
    'data' => [
        'observations' => $rows,
        'count' => count($rows),
        'authority_ids' => $authorityIds,
    ],
]);
