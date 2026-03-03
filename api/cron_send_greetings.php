<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

// Optional token protection
if (defined('ADMIN_TOKEN') && ADMIN_TOKEN) {
  $token = $_GET['token'] ?? '';
  if (!hash_equals((string)ADMIN_TOKEN, (string)$token)) {
    json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
  }
}

$today = date('Y-m-d');
$md = date('m-d');
$sent = ['birthday' => 0, 'nameday' => 0];

// Birthday greetings
try {
  $stmt = db()->prepare("
    SELECT id, email, first_name, last_name
    FROM users
    WHERE consent_marketing = 1
      AND marketing_greetings_optout = 0
      AND birthdate IS NOT NULL
      AND DATE_FORMAT(birthdate, '%m-%d') = :md
      AND (last_birthday_sent IS NULL OR last_birthday_sent < :today)
  ");
  $stmt->execute([':md' => $md, ':today' => $today]);
  $rows = $stmt->fetchAll() ?: [];
  foreach ($rows as $u) {
    $to = (string)$u['email'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
    $name = trim((string)($u['first_name'] ?? '')) ?: 'Kedves';
    $subject = "Boldog születésnapot kíván a Köz.Tér csapata!";
    $body = "Szia {$name}!\n\nBoldog születésnapot kíván a Köz.Tér csapata!\n\n— Köz.Tér";
    if (send_mail($to, $subject, $body)) {
      $sent['birthday']++;
      $up = db()->prepare("UPDATE users SET last_birthday_sent = :today WHERE id = :id");
      $up->execute([':today' => $today, ':id' => (int)$u['id']]);
    }
  }
} catch (Throwable $e) {
  // ignore
}

// Name day greetings
try {
  $nameList = names_for_today();
  if ($nameList) {
    $stmt = db()->prepare("
      SELECT id, email, first_name
      FROM users
      WHERE consent_marketing = 1
        AND marketing_greetings_optout = 0
        AND first_name IS NOT NULL
        AND first_name <> ''
        AND (last_nameday_sent IS NULL OR last_nameday_sent < :today)
    ");
    $stmt->execute([':today' => $today]);
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as $u) {
      $to = (string)$u['email'];
      if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
      $first = (string)($u['first_name'] ?? '');
      $variants = normalize_name_variants($first);
      if (!$variants) continue;
      $matched = false;
      foreach ($variants as $v) {
        if (in_array($v, $nameList, true)) {
          $matched = true;
          break;
        }
      }
      if (!$matched) continue;
      $subject = "Boldog névnapot kíván a Köz.Tér csapata!";
      $body = "Szia {$first}!\n\nBoldog névnapot kíván a Köz.Tér csapata!\n\n— Köz.Tér";
      if (send_mail($to, $subject, $body)) {
        $sent['nameday']++;
        $up = db()->prepare("UPDATE users SET last_nameday_sent = :today WHERE id = :id");
        $up->execute([':today' => $today, ':id' => (int)$u['id']]);
      }
    }
  }
} catch (Throwable $e) {
  // ignore
}

json_response(['ok' => true, 'sent' => $sent]);
