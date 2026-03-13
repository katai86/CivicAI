<?php
/**
 * M7 – Öntözendő fák lista (last_watered + tree_species_care alapján).
 * GET: limit. Jog: admin vagy gov user (mindig teljes lista – nincs authority scope a fáknál).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

start_secure_session();
$uid = current_user_id();
$role = current_user_role() ?: '';
if ($uid <= 0 || (!$role || !in_array($role, ['admin', 'superadmin', 'govuser'], true))) {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$limit = (int)($_GET['limit'] ?? 50);
if ($limit < 1 || $limit > 200) $limit = 50;

$rows = [];
try {
  $pdo = db();
  // Ugyanaz a szabály, mint a dashboard számánál: 7 napnál régebben öntözött vagy még soha
  $sql = "
    SELECT t.id, t.lat, t.lng, t.species, t.last_watered, t.adopted_by_user_id,
           u.display_name AS adopter_name
    FROM trees t
    LEFT JOIN users u ON u.id = t.adopted_by_user_id
    WHERE t.public_visible = 1
      AND (t.last_watered IS NULL OR t.last_watered < DATE_SUB(CURDATE(), INTERVAL 7 DAY))
    ORDER BY t.last_watered IS NULL ASC, t.last_watered ASC
    LIMIT ?
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$limit]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  // Opcionálisan fajta alapú ajánlás (tree_species_care), ha létezik – csak megjelenítéshez
  $hasSpeciesCare = false;
  try {
    $pdo->query("SELECT 1 FROM tree_species_care LIMIT 1");
    $hasSpeciesCare = true;
  } catch (Throwable $e) { /* tábla még nincs */ }
  foreach ($rows as &$r) {
    $r['watering_interval_days'] = 7;
    $r['watering_volume_liters'] = null;
    if ($hasSpeciesCare && !empty($r['species'])) {
      try {
        $sc = $pdo->prepare("SELECT watering_interval_days, watering_volume_liters FROM tree_species_care WHERE LOWER(TRIM(species_name)) = LOWER(TRIM(?)) AND watering_interval_days > 0 LIMIT 1");
        $sc->execute([$r['species']]);
        $row = $sc->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          $r['watering_interval_days'] = (int)($row['watering_interval_days'] ?? 7);
          $r['watering_volume_liters'] = isset($row['watering_volume_liters']) ? (float)$row['watering_volume_liters'] : null;
        }
      } catch (Throwable $e) { /* ignore */ }
    }
  }
  unset($r);
} catch (Throwable $e) {
  $rows = [];
}

json_response(['ok' => true, 'data' => $rows]);
