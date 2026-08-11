<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';
require_once __DIR__.'/member-profile-core.php';

require_method('GET');
p50_prono_ensure_schema();
p50_member_ensure_schema();
p50_prono_expire_statuses(db());

$user = auth_user(false);
$pdo = db();
$limit = max(1, min(40, (int)($_GET['limit'] ?? 20)));

// Fetch a bit more so we can collapse multi-leg slips into one story chip.
$fetchLimit = min(120, max(40, $limit * 4));
$stmt = $pdo->query("SELECT s.*, q.title AS question_title, q.options_json, q.profile_id, q.points_correct,
    v.odd_locked, v.stake_locked, v.slip_id AS vote_slip_id,
    sl.combined_odd AS slip_combined_odd, sl.stake AS slip_stake, sl.stake_locked AS slip_stake_locked,
    u.display_name AS author_display_name, u.avatar_url AS author_avatar_url
  FROM p50_prono_statuses s
  JOIN p50_prono_questions q ON q.id=s.question_id
  JOIN p50_prono_votes v ON v.id=s.vote_id
  JOIN users u ON u.id=s.user_id AND u.deleted_at IS NULL
  LEFT JOIN p50_prono_slips sl ON sl.id=COALESCE(NULLIF(s.slip_id,''), NULLIF(v.slip_id,''))
  WHERE s.status='live' AND s.expires_at>UTC_TIMESTAMP()
  ORDER BY s.created_at DESC
  LIMIT {$fetchLimit}");
$rows = $stmt ? ($stmt->fetchAll() ?: []) : [];

$liked = [];
if ($user && $rows) {
    $ids = array_map(static fn($r) => (string)$r['id'], $rows);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $likeStmt = $pdo->prepare("SELECT status_id FROM p50_prono_status_likes WHERE user_id=? AND status_id IN ($ph)");
    $likeStmt->execute(array_merge([(string)$user['id']], $ids));
    foreach ($likeStmt->fetchAll() ?: [] as $like) {
        $liked[(string)$like['status_id']] = true;
    }
}

// Collapse: one chip per slip (legacy 4 statuses) OR use stored legs_json
$groups = [];
foreach ($rows as $row) {
    $statusSlip = trim((string)($row['slip_id'] ?? ''));
    $voteSlip = trim((string)($row['vote_slip_id'] ?? ''));
    $slipKey = $statusSlip !== '' ? $statusSlip : $voteSlip;
    $key = $slipKey !== '' ? ('slip:'.$slipKey) : ('status:'.(string)$row['id']);
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'primary' => $row,
            'legs' => [],
            'statusIds' => [],
            'likeCount' => 0,
            'likedByMe' => false,
        ];
    }
    $groups[$key]['statusIds'][] = (string)$row['id'];
    $groups[$key]['likeCount'] += (int)$row['like_count'];
    if (!empty($liked[(string)$row['id']])) $groups[$key]['likedByMe'] = true;

    $storedLegs = p50_prono_status_legs_from_json($row['legs_json'] ?? null);
    if ($storedLegs !== []) {
        $groups[$key]['legs'] = $storedLegs;
        if ($statusSlip !== '') {
            $groups[$key]['primary'] = $row; // prefer dedicated grille status
        }
        continue;
    }

    $optionKey = (string)$row['option_key'];
    if ($optionKey === 'grille') continue;
    $groups[$key]['legs'][] = [
        'questionId' => (string)$row['question_id'],
        'questionTitle' => (string)$row['question_title'],
        'optionKey' => $optionKey,
        'optionLabel' => p50_prono_option_label($row, $optionKey),
        'odd' => p50_prono_normalize_odd($row['odd_locked'] ?? null, p50_prono_option_odd(p50_prono_options($row['options_json'] ?? []), $optionKey)),
        'coverPhoto' => p50_prono_resolve_cover((string)$row['profile_id'], (string)$row['question_title'])['coverPhoto'],
        'profileId' => (string)$row['profile_id'],
    ];
}

$items = [];
foreach ($groups as $group) {
    $row = $group['primary'];
    $legs = $group['legs'];
    if (count($legs) >= 2) {
        $row['legs_json'] = json_encode($legs, JSON_UNESCAPED_UNICODE);
        $row['slip_id'] = trim((string)($row['slip_id'] ?? '')) ?: trim((string)($row['vote_slip_id'] ?? ''));
        if (empty($row['combined_odd']) && !empty($row['slip_combined_odd'])) {
            $row['combined_odd'] = $row['slip_combined_odd'];
        }
        $row['slip_stake'] = $row['slip_stake'] ?? null;
        $row['slip_stake_locked'] = $row['slip_stake_locked'] ?? null;
        $row['like_count'] = (int)$group['likeCount'];
        $row['option_key'] = 'grille';
    } else {
        $row['option_label'] = p50_prono_option_label($row, (string)$row['option_key']);
    }
    $items[] = p50_prono_status_public($row, (bool)$group['likedByMe']);
    if (count($items) >= $limit) break;
}

json_response([
    'ok' => true,
    'version' => P50_PRONO_VERSION,
    'items' => $items,
]);
