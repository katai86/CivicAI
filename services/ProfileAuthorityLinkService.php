<?php
/**
 * Közület / civil profil automatikus hatóság-kapcsolása város + GPS alapján.
 */
declare(strict_types=1);

require_once __DIR__ . '/../util.php';

final class ProfileAuthorityLinkService
{
    /**
     * Regisztrációkor: város mentése users.municipality_city + opcionális join kérelem.
     */
    public static function onRegister(int $userId, string $role, string $municipalityCity, ?string $organizationName = null): void
    {
        $city = trim($municipalityCity);
        if ($city === '') {
            return;
        }
        try {
            db()->prepare('UPDATE users SET municipality_city = ? WHERE id = ?')
                ->execute([safe_str($city, 120), $userId]);
        } catch (Throwable $e) {
            log_error('ProfileAuthorityLinkService municipality_city: ' . $e->getMessage());
        }

        if ($role === 'govuser') {
            require_once __DIR__ . '/AuthorityJoinService.php';
            AuthorityJoinService::requestJoin($userId, $city, $organizationName);
            return;
        }

        if (!in_array($role, ['communityuser', 'civiluser', 'civil'], true)) {
            return;
        }

        $authorities = self::findAuthoritiesByCity($city);
        if (count($authorities) === 1) {
            self::linkUserProfileAuthority($userId, (int)$authorities[0]['id'], $role);
        }
    }

    /**
     * Facility / civil esemény mentésekor: authority_id geo alapján.
     */
    public static function resolveAuthorityId(?float $lat, ?float $lng, ?string $city): ?int
    {
        $id = resolve_authority_for_location($lat, $lng, $city, null);
        return ($id !== null && $id > 0) ? (int)$id : null;
    }

    public static function linkUserProfileAuthority(int $userId, int $authorityId, ?string $role = null): void
    {
        if ($authorityId <= 0) {
            return;
        }
        $role = $role ?: (current_user_role() ?: 'member');
        if (in_array($role, ['communityuser'], true)) {
            try {
                db()->prepare('UPDATE facilities SET authority_id = ? WHERE user_id = ? AND (authority_id IS NULL OR authority_id = 0)')
                    ->execute([$authorityId, $userId]);
            } catch (Throwable $e) {
            }
        }
        if (in_array($role, ['civiluser', 'civil'], true)) {
            try {
                db()->prepare('UPDATE civil_events SET authority_id = ? WHERE user_id = ? AND (authority_id IS NULL OR authority_id = 0)')
                    ->execute([$authorityId, $userId]);
            } catch (Throwable $e) {
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private static function findAuthoritiesByCity(string $city): array
    {
        try {
            $stmt = db()->prepare("
                SELECT id, name, city FROM authorities
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
}
