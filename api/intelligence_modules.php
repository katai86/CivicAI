<?php
/**
 * Intelligence Platform modulok státusza (gov/admin).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/IntelligenceModuleRegistry.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$modules = IntelligenceModuleRegistry::listWithStatus();
$active = 0;
$errors = 0;
foreach ($modules as $m) {
    if (!empty($m['enabled'])) {
        $active++;
    }
    if (!empty($m['errorMessage'])) {
        $errors++;
    }
}

json_response([
    'ok' => true,
    'data' => [
        'modules' => $modules,
        'summary' => [
            'active_modules' => $active,
            'error_modules' => $errors,
            'total_modules' => count($modules),
        ],
    ],
]);
