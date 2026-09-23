<?php
/**
 * Plant & Tree provider health check.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/plant/PlantTreeVisionRouter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$router = new PlantTreeVisionRouter();
json_response(['ok' => true, 'providers' => $router->providerHealth()]);
