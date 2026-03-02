<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$body = read_json_body();
$id   = isset($body['id']) ? (int)$body['id'] : 0;
$new  = isset($body['status']) ? trim((string)$body['status']) : '';
$note = safe_str($body['note'] ?? null, 255);

if ($id <= 0) json_response(['ok'=>false,'error'=>'Invalid id'], 400);

$allowed = [
  'pending', 'approved', 'rejected',
  'new', 'needs_info', 'forwarded', 'waiting_reply', 'in_progress', 'solved', 'closed'
];
if (!in_array($new, $allowed, true)) {
  json_response(['ok'=>false,'error'=>'Invalid status'], 400);
}

$statusLabel = [
  'pending' => 'Ellenőrzés alatt',
  'approved' => 'Publikálva',
  'rejected' => 'Elutasítva',
  'new' => 'Új',
  'needs_info' => 'Kiegészítésre vár',
  'forwarded' => 'Továbbítva',
  'waiting_reply' => 'Válaszra vár',
  'in_progress' => 'Folyamatban',
  'solved' => 'Megoldva',
  'closed' => 'Lezárva',
];

function build_status_email(
  $case, $id, $old, $new, $labels,
  $title, $address, $note,
  $trackUrl, $unsubscribeUrl
) {
  $oldL = $labels[$old] ?? $old;
  $newL = $labels[$new] ?? $new;

  $subject = "[{$case}] Problématérkép – {$newL}";

  $lines = [];
  $lines[] = "Szia!";
  $lines[] = "";
  $lines[] = "Frissítés érkezett az alábbi bejelentésedhez:";
  $lines[] = "Ügyszám: {$case}";
  $lines[] = "Bejelentés ID: #{$id}";
  if ($title)   $lines[] = "Rövid cím: {$title}";
  if ($address) $lines[] = "Helyszín (közelítő): {$address}";
  $lines[] = "";
  $lines[] = "Státusz változás:";
  $lines[] = "- Előző: {$oldL}";
  $lines[] = "- Új: {$newL}";

  if ($note) {
    $lines[] = "";
    $lines[] = "Megjegyzés az adminisztrátortól:";
    $lines[] = $note;
  }

  $lines[] = "";
  if ($new === 'needs_info') {
    $lines[] = "Mit tudsz segíteni?";
    $lines[] = "- Válaszolj erre az e-mailre és írd le pontosabban a helyzetet.";
    $lines[] = "- Ha van fotód/videód, azt is csatolhatod.";
  } elseif ($new === 'forwarded') {
    $lines[] = "Továbbítottuk az ügyet az illetékes felé. Amint érdemi válasz érkezik, frissítjük.";
  } elseif ($new === 'waiting_reply') {
    $lines[] = "Jelenleg választ várunk az illetékestől. Amint megjön, frissítjük a státuszt.";
  } elseif ($new === 'in_progress') {
    $lines[] = "Az ügy intézés alatt van. Ha új infód van, válaszolj nyugodtan erre az e-mailre.";
  } elseif ($new === 'solved') {
    $lines[] = "Megoldottnak jelöltük. Ha szerinted mégsem rendeződött, válaszolj erre az e-mailre.";
  } elseif ($new === 'closed') {
    $lines[] = "Az ügyet lezártuk. Ha további részlet kell, válaszolj erre az e-mailre.";
  } elseif ($new === 'rejected') {
    $lines[] = "A bejelentést elutasítottuk. Ha szerinted tévedés, válaszolj erre az e-mailre és pontosíts.";
  } else {
    $lines[] = "Köszönjük, hogy segítesz jobbá tenni Orosházát!";
  }

  $lines[] = "";
  $lines[] = "Ügy követése (privát link):";
  $lines[] = $trackUrl;

  $lines[] = "";
  $lines[] = "Leiratkozás az értesítésekről (ehhez a bejelentéshez):";
  $lines[] = $unsubscribeUrl;

  $lines[] = "";
  $lines[] = "— Problématérkép (Orosháza)";

  return [$subject, implode("\n", $lines)];
}

$pdo = db();
$pdo->beginTransaction();

try {
  $stmt = $pdo->prepare("
    SELECT id, status, title, address_approx, created_at,
           reporter_email, notify_enabled, notify_token
    FROM reports
    WHERE id=:id
    FOR UPDATE
  ");
  $stmt->execute([':id' => $id]);
  $r = $stmt->fetch();

  if (!$r) {
    $pdo->rollBack();
    json_response(['ok'=>false,'error'=>'Report not found'], 404);
  }

  $oldStr = (string)$r['status'];

  if ($oldStr === $new) {
    $pdo->commit();
    json_response(['ok'=>true,'old'=>$oldStr,'new'=>$new,'changed'=>false]);
  }

  $stmt = $pdo->prepare("UPDATE reports SET status=:st WHERE id=:id");
  $stmt->execute([':st'=>$new, ':id'=>$id]);

  $stmt = $pdo->prepare("
    INSERT INTO report_status_log (report_id, old_status, new_status, note, changed_by)
    VALUES (:rid, :old, :new, :note, :by)
  ");
  $stmt->execute([
    ':rid' => $id,
    ':old' => $oldStr,
    ':new' => $new,
    ':note'=> $note,
    ':by'  => 'admin'
  ]);

  $pdo->commit();

  // ===== EMAIL (best-effort) =====
  $to = (string)($r['reporter_email'] ?? '');
  $notifyEnabled = (int)($r['notify_enabled'] ?? 0);
  $token = (string)($r['notify_token'] ?? '');

  if ($notifyEnabled === 1 && $to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) && $token !== '') {
    $case = case_number((int)$r['id'], (string)$r['created_at']);

    $trackUrl = app_url('/case.php?token=' . rawurlencode($token));
    $unsubscribeUrl = app_url('/api/notify_unsubscribe.php?token=' . rawurlencode($token));

    [$subject, $bodyText] = build_status_email(
      $case,
      (int)$r['id'],
      $oldStr,
      $new,
      $statusLabel,
      $r['title'] ? (string)$r['title'] : null,
      $r['address_approx'] ? (string)$r['address_approx'] : null,
      $note,
      $trackUrl,
      $unsubscribeUrl
    );

    send_mail($to, $subject, $bodyText);
  }
  // ===== /EMAIL =====

  json_response(['ok'=>true,'old'=>$oldStr,'new'=>$new,'changed'=>true]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(['ok'=>false,'error'=>'DB error'], 500);
}