<?php
declare(strict_types=1);

function p50tk_scope_list(string $raw): array {
    return array_values(array_unique(array_filter(
        preg_split('/[\s,]+/', trim($raw)) ?: [],
        static fn(string $scope): bool => $scope !== ''
    )));
}

function p50tk_store_snapshot_v2(
    string $userId,
    array $tokens,
    array $profile,
    array $videos
): void {
    p50tk_ensure_schema();

    $openId = trim((string)($profile['open_id'] ?? ''));
    $accessToken = trim((string)($tokens['access_token'] ?? ''));
    $refreshToken = trim((string)($tokens['refresh_token'] ?? ''));
    if ($userId === '' || $openId === '' || $accessToken === '' || $refreshToken === '') {
        throw new RuntimeException('Données de connexion TikTok incomplètes.');
    }

    $accessExpiresAt = gmdate('Y-m-d H:i:s', time() + max(60, (int)($tokens['expires_in'] ?? 86400)));
    $refreshExpiresAt = gmdate('Y-m-d H:i:s', time() + max(3600, (int)($tokens['refresh_expires_in'] ?? 31536000)));
    $scopes = implode(' ', p50tk_scope_list((string)($tokens['scope'] ?? '')));
    $connectionValues = [
        $openId,
        (string)($profile['union_id'] ?? ''),
        (string)($profile['display_name'] ?? ''),
        (string)($profile['username'] ?? ''),
        (string)($profile['avatar_large_url'] ?? $profile['avatar_url'] ?? ''),
        (string)($profile['profile_deep_link'] ?? ''),
        (string)($profile['bio_description'] ?? ''),
        !empty($profile['is_verified']) ? 1 : 0,
        isset($profile['follower_count']) ? (int)$profile['follower_count'] : null,
        isset($profile['following_count']) ? (int)$profile['following_count'] : null,
        isset($profile['likes_count']) ? (int)$profile['likes_count'] : null,
        isset($profile['video_count']) ? (int)$profile['video_count'] : null,
        p50tk_encrypt($accessToken),
        p50tk_encrypt($refreshToken),
        (string)($tokens['token_type'] ?? 'Bearer'),
        $scopes,
        $accessExpiresAt,
        $refreshExpiresAt,
    ];

    $db = db();
    $db->beginTransaction();
    try {
        // Le verrou sur l’index unique open_id empêche deux callbacks simultanés
        // d’associer ou d’écraser silencieusement le même compte TikTok.
        $ownerStmt = $db->prepare('SELECT user_id FROM p50_tiktok_oauth_connections WHERE open_id=? FOR UPDATE');
        $ownerStmt->execute([$openId]);
        $ownerId = trim((string)($ownerStmt->fetchColumn() ?: ''));
        if ($ownerId !== '' && !hash_equals($ownerId, $userId)) {
            throw new RuntimeException('Ce compte TikTok est déjà lié à un autre compte PASS50.');
        }

        $userStmt = $db->prepare('SELECT user_id FROM p50_tiktok_oauth_connections WHERE user_id=? FOR UPDATE');
        $userStmt->execute([$userId]);
        $hasConnection = (bool)$userStmt->fetchColumn();

        if ($hasConnection) {
            $update = $db->prepare(
                "UPDATE p50_tiktok_oauth_connections SET
                 open_id=?,union_id=?,display_name=?,username=?,avatar_url=?,profile_deep_link=?,bio_description=?,is_verified=?,
                 follower_count=?,following_count=?,likes_count=?,video_count=?,access_token_encrypted=?,refresh_token_encrypted=?,
                 token_type=?,scopes=?,access_expires_at=?,refresh_expires_at=?,status='active',last_error=NULL,
                 connected_at=UTC_TIMESTAMP(),last_refreshed_at=UTC_TIMESTAMP(),last_synced_at=UTC_TIMESTAMP()
                 WHERE user_id=?"
            );
            $update->execute([...$connectionValues, $userId]);
        } else {
            $insert = $db->prepare(
                "INSERT INTO p50_tiktok_oauth_connections
                 (user_id,open_id,union_id,display_name,username,avatar_url,profile_deep_link,bio_description,is_verified,
                  follower_count,following_count,likes_count,video_count,access_token_encrypted,refresh_token_encrypted,
                  token_type,scopes,access_expires_at,refresh_expires_at,status,last_error,connected_at,last_refreshed_at,last_synced_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())"
            );
            $insert->execute([$userId, ...$connectionValues]);
        }

        $db->prepare('DELETE FROM p50_tiktok_oauth_videos WHERE user_id=?')->execute([$userId]);
        $videoStmt = $db->prepare(
            "INSERT INTO p50_tiktok_oauth_videos
             (user_id,video_id,title,video_description,cover_image_url,share_url,embed_link,duration_seconds,published_at,
              view_count,like_count,comment_count,share_count,fetched_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())"
        );
        foreach ($videos as $video) {
            if (!is_array($video)) continue;
            $videoId = trim((string)($video['id'] ?? ''));
            if ($videoId === '') continue;
            $created = isset($video['create_time']) && is_numeric($video['create_time'])
                ? gmdate('Y-m-d H:i:s', (int)$video['create_time'])
                : null;
            $videoStmt->execute([
                $userId,
                $videoId,
                (string)($video['title'] ?? ''),
                (string)($video['video_description'] ?? ''),
                (string)($video['cover_image_url'] ?? ''),
                (string)($video['share_url'] ?? ''),
                (string)($video['embed_link'] ?? ''),
                isset($video['duration']) ? (int)$video['duration'] : null,
                $created,
                isset($video['view_count']) ? (int)$video['view_count'] : null,
                isset($video['like_count']) ? (int)$video['like_count'] : null,
                isset($video['comment_count']) ? (int)$video['comment_count'] : null,
                isset($video['share_count']) ? (int)$video['share_count'] : null,
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
