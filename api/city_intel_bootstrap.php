<?php
/**
 * Shared auth + authority scope for City Intelligence APIs.
 * @return array{uid:int,role:string,isAdmin:bool,authorityIds:list<int>,requestedAid:int}
 */
function city_intel_require_gov(): array
{
    start_secure_session();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $role = current_user_role() ?: '';
    $isAdmin = in_array($role, ['admin', 'superadmin'], true);
    if ($uid <= 0 || (!$isAdmin && $role !== 'govuser')) {
        json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
    $requestedAid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : (isset($_POST['authority_id']) ? (int)$_POST['authority_id'] : 0);
    $authorityIds = [];
    if ($isAdmin) {
        if ($requestedAid > 0) {
            $authorityIds = [$requestedAid];
        } else {
            try {
                $authorityIds = array_map('intval', db()->query('SELECT id FROM authorities ORDER BY name')->fetchAll(PDO::FETCH_COLUMN) ?: []);
            } catch (Throwable $e) {
                $authorityIds = [];
            }
        }
    } else {
        try {
            $st = db()->prepare('SELECT authority_id FROM authority_users WHERE user_id = ?');
            $st->execute([$uid]);
            $authorityIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if ($requestedAid > 0 && !in_array($requestedAid, $authorityIds, true)) {
                json_response(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            if ($requestedAid > 0) {
                $authorityIds = [$requestedAid];
            }
        } catch (Throwable $e) {
            $authorityIds = [];
        }
    }
    return [
        'uid' => $uid,
        'role' => $role,
        'isAdmin' => $isAdmin,
        'authorityIds' => $authorityIds,
        'requestedAid' => $requestedAid,
    ];
}

function city_intel_primary_authority(array $scope): ?int
{
    if ($scope['requestedAid'] > 0) {
        return $scope['requestedAid'];
    }
    if (!empty($scope['authorityIds'])) {
        return (int)$scope['authorityIds'][0];
    }
    return null;
}
