<?php
/**
 * Önkormányzati felhasználók automatikus / jóváhagyott hatóság-kapcsolása.
 */
declare(strict_types=1);

require_once __DIR__ . '/../util.php';

final class AuthorityJoinService
{
    /**
     * Regisztráció vagy kérvény után: város alapján hatóság keresése és kapcsolás.
     *
     * @return array{ok: bool, status?: string, authority_id?: int, request_id?: int, error?: string}
     */
    public static function requestJoin(
        int $userId,
        string $municipalityCity,
        ?string $organizationName = null,
        ?string $jobTitle = null,
        ?string $message = null
    ): array {
        $city = trim($municipalityCity);
        if ($city === '') {
            return ['ok' => false, 'error' => 'city_required'];
        }

        $pdo = db();
        $authorities = self::findAuthoritiesByCity($city);
        $authorityId = count($authorities) === 1 ? (int)$authorities[0]['id'] : null;

        $autoApprove = defined('GOV_JOIN_AUTO_APPROVE') && GOV_JOIN_AUTO_APPROVE && $authorityId !== null;
        $status = $autoApprove ? 'auto_approved' : 'pending';

        try {
            $stmt = $pdo->prepare('
                INSERT INTO authority_join_requests
                  (user_id, authority_id, municipality_city, organization_name, job_title, message, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $userId,
                $authorityId,
                safe_str($city, 120),
                safe_str($organizationName, 160),
                safe_str($jobTitle, 120),
                safe_str($message, 2000),
                $status,
            ]);
            $requestId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            log_error('AuthorityJoinService insert: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'save_failed'];
        }

        if ($autoApprove && $authorityId !== null) {
            self::linkUserToAuthority($userId, $authorityId);
            return [
                'ok' => true,
                'status' => 'auto_approved',
                'authority_id' => $authorityId,
                'request_id' => $requestId,
            ];
        }

        return [
            'ok' => true,
            'status' => $status,
            'authority_id' => $authorityId,
            'request_id' => $requestId,
            'matches' => count($authorities),
        ];
    }

    public static function approve(int $requestId, int $reviewerUserId, ?int $authorityId = null, ?string $note = null): array
    {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM authority_join_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req || ($req['status'] ?? '') !== 'pending') {
            return ['ok' => false, 'error' => 'not_found_or_done'];
        }

        $aid = $authorityId ?? (int)($req['authority_id'] ?? 0);
        if ($aid <= 0) {
            return ['ok' => false, 'error' => 'authority_required'];
        }

        $pdo->prepare("
            UPDATE authority_join_requests
            SET status = 'approved', authority_id = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?
            WHERE id = ?
        ")->execute([$aid, $reviewerUserId, safe_str($note, 255), $requestId]);

        self::linkUserToAuthority((int)$req['user_id'], $aid);

        return ['ok' => true, 'authority_id' => $aid];
    }

    public static function reject(int $requestId, int $reviewerUserId, ?string $note = null): array
    {
        try {
            db()->prepare("
                UPDATE authority_join_requests
                SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
                WHERE id = ? AND status = 'pending'
            ")->execute([$reviewerUserId, safe_str($note, 255), $requestId]);
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'save_failed'];
        }
    }

    public static function linkUserToAuthority(int $userId, int $authorityId): void
    {
        try {
            db()->prepare('
                INSERT IGNORE INTO authority_users (authority_id, user_id, role)
                VALUES (?, ?, ?)
            ')->execute([$authorityId, $userId, 'member']);
        } catch (Throwable $e) {
            log_error('AuthorityJoinService link: ' . $e->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    public static function findAuthoritiesByCity(string $city): array
    {
        try {
            $stmt = db()->prepare("
                SELECT id, name, city, contact_email
                FROM authorities
                WHERE is_active = 1 AND city IS NOT NULL AND TRIM(city) <> ''
                  AND (city = ? OR city LIKE ?)
                ORDER BY CASE WHEN city = ? THEN 0 ELSE 1 END, id ASC
            ");
            $like = '%' . $city . '%';
            $stmt->execute([$city, $like, $city]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    public static function pendingRequests(): array
    {
        try {
            return db()->query("
                SELECT j.*, u.email, u.display_name
                FROM authority_join_requests j
                JOIN users u ON u.id = j.user_id
                WHERE j.status = 'pending'
                ORDER BY j.created_at ASC
                LIMIT 100
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
