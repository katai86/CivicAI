<?php
/**
 * XP, szint, badge és toplista logika – service réteg.
 * A util.php include-olja; db() a hívó kontextusból (db.php) érkezik.
 */
if (!function_exists('db')) {
  require_once __DIR__ . '/../db.php';
}

function level_from_xp(int $xp): array {
    $mins = [1 => 0, 2 => 100, 3 => 250, 4 => 500, 5 => 800, 6 => 1200, 7 => 1700, 8 => 2300, 9 => 3000, 10 => 4000];
    $current = 1;
    foreach ($mins as $lvl => $min) {
        if ($xp >= $min) {
            $current = $lvl;
        }
    }
    $name = 'Level ' . $current;
    if (function_exists('t')) {
        $tk = 'level.lvl' . $current;
        $tr = t($tk);
        if ($tr !== $tk) {
            $name = $tr;
        }
    }
    return ['level' => $current, 'name' => $name];
}

function add_user_xp(int $userId, int $points, string $reason, ?int $reportId = null): void {
    if ($points === 0) return;
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET total_xp = total_xp + :p WHERE id = :id")
            ->execute([':p' => $points, ':id' => $userId]);
        try {
            $pdo->prepare("INSERT INTO user_xp_log (user_id, points, reason, report_id) VALUES (:uid,:p,:r,:rid)")
                ->execute([':uid' => $userId, ':p' => $points, ':r' => $reason, ':rid' => $reportId]);
        } catch (Throwable $e) { /* ignore */ }
        $stmt = $pdo->prepare("SELECT total_xp FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $xp = (int)$stmt->fetchColumn();
        $lvl = level_from_xp($xp);
        $pdo->prepare("UPDATE users SET level = :lvl WHERE id = :id")
            ->execute([':lvl' => $lvl['level'], ':id' => $userId]);
        award_badge($userId, 'level_' . $lvl['level']);
        $pdo->commit();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    }
}

function add_user_xp_once(int $userId, int $points, string $eventKey, string $reason, ?int $reportId = null): void {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT 1 FROM user_xp_events WHERE user_id = :uid AND event_key = :ek LIMIT 1");
        $stmt->execute([':uid' => $userId, ':ek' => $eventKey]);
        if ($stmt->fetchColumn()) return;
        $pdo->prepare("INSERT INTO user_xp_events (user_id, event_key) VALUES (:uid, :ek)")
            ->execute([':uid' => $userId, ':ek' => $eventKey]);
        add_user_xp($userId, $points, $reason, $reportId);
    } catch (Throwable $e) {
        add_user_xp($userId, $points, $reason, $reportId);
    }
}

function update_user_streak(int $userId): int {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT last_active_date, streak_days FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
        if (!$row) return 0;
        $last = $row['last_active_date'] ? (string)$row['last_active_date'] : null;
        $streak = (int)($row['streak_days'] ?? 0);
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if ($last === $today) return $streak;
        if ($last === $yesterday) $streak += 1;
        else $streak = 1;
        $pdo->prepare("UPDATE users SET streak_days = :s, last_active_date = :d WHERE id = :id")
            ->execute([':s' => $streak, ':d' => $today, ':id' => $userId]);
        return $streak;
    } catch (Throwable $e) {
        return 0;
    }
}

function award_badge(int $userId, string $badgeCode): void {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id FROM badges WHERE code = :c LIMIT 1");
        $stmt->execute([':c' => $badgeCode]);
        $bid = $stmt->fetchColumn();
        if (!$bid) return;
        $stmt = $pdo->prepare("SELECT 1 FROM user_badges WHERE user_id = :uid AND badge_id = :bid LIMIT 1");
        $stmt->execute([':uid' => $userId, ':bid' => $bid]);
        if ($stmt->fetchColumn()) return;
        $pdo->prepare("INSERT INTO user_badges (user_id, badge_id) VALUES (:uid,:bid)")
            ->execute([':uid' => $userId, ':bid' => $bid]);
    } catch (Throwable $e) { /* ignore */ }
}

function ensure_level_badge(int $userId, int $level): void {
    if ($level < 1) return;
    award_badge($userId, 'level_' . $level);
}

function get_leaderboard(string $period, int $limit = 10): array {
    $limit = max(1, min(50, $limit));
    $where = $period === 'week' ? "AND x.created_at >= (NOW() - INTERVAL 7 DAY)" : ($period === 'month' ? "AND x.created_at >= (NOW() - INTERVAL 1 MONTH)" : '');
    try {
        $sql = "SELECT u.id, u.display_name, u.level, u.avatar_filename, SUM(x.points) AS points
            FROM user_xp_log x JOIN users u ON u.id = x.user_id
            WHERE COALESCE(u.profile_public, 1) = 1 $where
            GROUP BY u.id, u.display_name, u.level, u.avatar_filename HAVING points > 0
            ORDER BY points DESC LIMIT $limit";
        return db()->query($sql)->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function get_category_leaderboard(string $period, string $category, int $limit = 10): array {
    $limit = max(1, min(50, $limit));
    if ($category === '') return [];
    $where = $period === 'week' ? "AND r.created_at >= (NOW() - INTERVAL 7 DAY)" : ($period === 'month' ? "AND r.created_at >= (NOW() - INTERVAL 1 MONTH)" : '');
    try {
        $sql = "SELECT u.id, u.display_name, u.level, u.avatar_filename, COUNT(*) AS count
            FROM reports r JOIN users u ON u.id = r.user_id
            WHERE COALESCE(u.profile_public, 1) = 1 AND r.category = :cat $where
            GROUP BY u.id, u.display_name, u.level, u.avatar_filename HAVING count > 0
            ORDER BY count DESC LIMIT $limit";
        $stmt = db()->prepare($sql);
        $stmt->execute([':cat' => $category]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function get_user_rank(string $period, int $userId): ?array {
    if ($userId <= 0) return null;
    $where = $period === 'week' ? "AND x.created_at >= (NOW() - INTERVAL 7 DAY)" : ($period === 'month' ? "AND x.created_at >= (NOW() - INTERVAL 1 MONTH)" : '');
    try {
        $stmt = db()->prepare("SELECT SUM(x.points) AS points FROM user_xp_log x JOIN users u ON u.id = x.user_id WHERE x.user_id = :uid AND (COALESCE(u.profile_public, 1) = 1 OR u.id = :uid2) $where");
        $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
        $points = (int)($stmt->fetchColumn() ?: 0);
        if ($points <= 0) return null;
        $stmt = db()->prepare("SELECT COUNT(*) + 1 AS rank FROM (SELECT u.id, SUM(x.points) AS points FROM user_xp_log x JOIN users u ON u.id = x.user_id WHERE (COALESCE(u.profile_public, 1) = 1 OR u.id = :uid) $where GROUP BY u.id HAVING points > :p) t");
        $stmt->execute([':uid' => $userId, ':p' => $points]);
        $rank = (int)($stmt->fetchColumn() ?: 0);
        return $rank <= 0 ? null : ['rank' => $rank, 'points' => $points];
    } catch (Throwable $e) {
        return null;
    }
}

function get_user_category_rank(string $period, int $userId, string $category): ?array {
    if ($userId <= 0 || $category === '') return null;
    $where = $period === 'week' ? "AND r.created_at >= (NOW() - INTERVAL 7 DAY)" : ($period === 'month' ? "AND r.created_at >= (NOW() - INTERVAL 1 MONTH)" : '');
    try {
        $stmt = db()->prepare("SELECT COUNT(*) AS count FROM reports r JOIN users u ON u.id = r.user_id WHERE r.user_id = :uid AND r.category = :cat AND (COALESCE(u.profile_public, 1) = 1 OR u.id = :uid2) $where");
        $stmt->execute([':uid' => $userId, ':cat' => $category, ':uid2' => $userId]);
        $count = (int)($stmt->fetchColumn() ?: 0);
        if ($count <= 0) return null;
        $stmt = db()->prepare("SELECT COUNT(*) + 1 AS rank FROM (SELECT u.id, COUNT(*) AS count FROM reports r JOIN users u ON u.id = r.user_id WHERE (COALESCE(u.profile_public, 1) = 1 OR u.id = :uid) AND r.category = :cat $where GROUP BY u.id HAVING count > :c) t");
        $stmt->execute([':uid' => $userId, ':cat' => $category, ':c' => $count]);
        $rank = (int)($stmt->fetchColumn() ?: 0);
        return $rank <= 0 ? null : ['rank' => $rank, 'count' => $count];
    } catch (Throwable $e) {
        return null;
    }
}

function check_category_badges(int $userId, string $category): void {
    $map = ['road' => ['code' => 'bad_katyuvadasz', 'need' => 10], 'trash' => ['code' => 'bad_szemet_szemle', 'need' => 15], 'lighting' => ['code' => 'bad_lampas_ember', 'need' => 5], 'green' => ['code' => 'bad_zold_ujju', 'need' => 10]];
    if (!isset($map[$category])) return;
    $need = (int)$map[$category]['need'];
    $code = (string)$map[$category]['code'];
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM reports WHERE user_id = :uid AND category = :cat");
        $stmt->execute([':uid' => $userId, ':cat' => $category]);
        if ((int)$stmt->fetchColumn() >= $need) award_badge($userId, $code);
    } catch (Throwable $e) { /* ignore */ }
}

function check_description_badge(int $userId, int $descLen): void {
    if ($descLen < 300) return;
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM reports WHERE user_id = :uid AND CHAR_LENGTH(description) >= 300");
        $stmt->execute([':uid' => $userId]);
        if ((int)$stmt->fetchColumn() >= 5) award_badge($userId, 'bad_diktalas');
    } catch (Throwable $e) { /* ignore */ }
}

function check_gps_badge(int $userId, bool $isPrecise): void {
    if (!$isPrecise) return;
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM reports WHERE user_id = :uid AND (lat REGEXP '\\.[0-9]{5,}$' AND lng REGEXP '\\.[0-9]{5,}$')");
        $stmt->execute([':uid' => $userId]);
        if ((int)$stmt->fetchColumn() >= 10) award_badge($userId, 'bad_terkepesz');
    } catch (Throwable $e) { /* ignore */ }
}
