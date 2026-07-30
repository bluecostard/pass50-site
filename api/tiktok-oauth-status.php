<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/tiktok-oauth-core.php';

require_method('GET');
$user = auth_user();
p50tk_ensure_schema();
$userId = (string)$user['id'];
$connection = p50tk_connection_for_user($userId);
if (!$connection) json_response(['ok' => true, 'connected' => false]);

$videos = array_map(static function(array $video): array {
    return [
        'id' => (string)$video['video_id'],
        'title' => (string)$video['title'],
        'description' => (string)($video['video_description'] ?? ''),
        'coverImageUrl' => (string)($video['cover_image_url'] ?? ''),
        'shareUrl' => (string)($video['share_url'] ?? ''),
        'embedLink' => (string)($video['embed_link'] ?? ''),
        'durationSeconds' => $video['duration_seconds'] === null ? null : (int)$video['duration_seconds'],
        'publishedAt' => $video['published_at'] ? (string)$video['published_at'] . 'Z' : null,
        'viewCount' => $video['view_count'] === null ? null : (int)$video['view_count'],
        'likeCount' => $video['like_count'] === null ? null : (int)$video['like_count'],
        'commentCount' => $video['comment_count'] === null ? null : (int)$video['comment_count'],
        'shareCount' => $video['share_count'] === null ? null : (int)$video['share_count'],
    ];
}, p50tk_videos_for_user($userId, 10));

json_response([
    'ok' => true,
    'connected' => in_array((string)$connection['status'], ['active','reauthorization_required'], true),
    'status' => (string)$connection['status'],
    'environment' => p50tk_config()['environment'],
    'profile' => [
        'displayName' => (string)$connection['display_name'],
        'username' => (string)$connection['username'],
        'avatarUrl' => (string)($connection['avatar_url'] ?? ''),
        'profileUrl' => (string)($connection['profile_deep_link'] ?? ''),
        'bio' => (string)($connection['bio_description'] ?? ''),
        'verified' => !empty($connection['is_verified']),
        'followerCount' => $connection['follower_count'] === null ? null : (int)$connection['follower_count'],
        'followingCount' => $connection['following_count'] === null ? null : (int)$connection['following_count'],
        'likesCount' => $connection['likes_count'] === null ? null : (int)$connection['likes_count'],
        'videoCount' => $connection['video_count'] === null ? null : (int)$connection['video_count'],
    ],
    'videos' => $videos,
    'scopes' => preg_split('/\s+/', trim((string)$connection['scopes'])) ?: [],
    'accessExpiresAt' => (string)$connection['access_expires_at'] . 'Z',
    'refreshExpiresAt' => (string)$connection['refresh_expires_at'] . 'Z',
    'connectedAt' => (string)$connection['connected_at'] . 'Z',
    'lastSyncedAt' => $connection['last_synced_at'] ? (string)$connection['last_synced_at'] . 'Z' : null,
    'requiresReauthorization' => (string)$connection['status'] === 'reauthorization_required',
]);
