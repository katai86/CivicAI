<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/IntelligenceHub.php';

require_gov_or_admin();

$aid = gov_primary_authority_id();
if (isset($_GET['authority_id']) && (int)$_GET['authority_id'] > 0) {
    $req = (int)$_GET['authority_id'];
    if (in_array(current_user_role() ?: '', ['admin', 'superadmin'], true)) {
        $aid = $req;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (!isset($_GET['layer']) || $_GET['layer'] === '')) {
    json_response(['ok' => true, 'data' => ['layers' => (new IntelligenceHub())->availableMapLayers()]]);
}

$layer = trim((string)($_GET['layer'] ?? 'gbif'));
$hub = new IntelligenceHub();
$geo = $hub->mapLayer($layer, $aid);
json_response(['ok' => true, 'data' => $geo, 'meta' => $geo['meta'] ?? []]);
