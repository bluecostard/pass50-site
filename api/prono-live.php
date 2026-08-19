<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

p50_prono_ensure_schema();
p50_prono_lock_closed(db());

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    p50_prono_live_get();
}
require_method('POST');
p50_prono_live_post();

function p50_prono_live_get(): never {
    $pdo = db();
    $admin = isset($_GET['admin']) && (string)$_GET['admin'] !== '' && (string)$_GET['admin'] !== '0';
    $user = auth_user(false);
    if ($admin) {
        if (!$user) json_response(['error' => 'Connexion requise.'], 401);
        require_role($user, 'owner', 'admin');
        $session = p50_prono_live_latest_session($pdo);
        $rows = $session ? p50_prono_live_questions($pdo, (string)$session['id'], false) : [];
        json_response(p50_prono_live_payload($pdo, $session, $rows, $user, true));
    }

    $session = p50_prono_live_active_session($pdo);
    $rows = $session ? p50_prono_live_questions($pdo, (string)$session['id'], true) : [];
    json_response(p50_prono_live_payload($pdo, $session, $rows, $user, false));
}

function p50_prono_live_payload(PDO $pdo, ?array $session, array $rows, ?array $user, bool $admin): array {
    $items = [];
    foreach ($rows as $row) {
        $qid = (string)$row['id'];
        $options = p50_prono_options($row['options_json'] ?? []);
        $tallies = p50_prono_vote_tallies($pdo, $qid, $options);
        $vote = null;
        if ($user) {
            $stmt = $pdo->prepare('SELECT * FROM p50_prono_votes WHERE question_id=? AND user_id=? ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$qid, (string)$user['id']]);
            $vote = $stmt->fetch() ?: null;
        }
        $item = p50_prono_question_public($row, $vote ?: null, $tallies);
        if ($user) {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM p50_prono_votes WHERE question_id=? AND user_id=?');
            $countStmt->execute([$qid, (string)$user['id']]);
            $item['myPlayCount'] = (int)$countStmt->fetchColumn();
        } else {
            $item['myPlayCount'] = 0;
        }
        $items[] = $item;
    }

    $balance = $user
        ? p50_prono_balance($pdo, (string)$user['id'])
        : ['balance' => 0, 'streak' => 0, 'lastPlayDate' => null, 'floor' => P50_PRONO_BALANCE_FLOOR];

    $publicSession = p50_prono_live_session_public($session);
    $active = $publicSession !== null && ($publicSession['active'] ?? false);

    return [
        'ok' => true,
        'version' => P50_PRONO_VERSION,
        'active' => $active,
        'livePayoutMultiplier' => P50_PRONO_LIVE_PAYOUT_MULTIPLIER,
        'session' => $admin ? $publicSession : ($active ? $publicSession : null),
        'items' => $active || $admin ? $items : [],
        'balance' => $balance,
        'auth' => $user !== null,
        'stakeDefault' => P50_PRONO_POINTS_CORRECT,
        'disclaimer' => 'Sans argent réel — gains doublés tant que Prono50 live est actif.',
    ];
}

function p50_prono_live_post(): never {
    $user = auth_user();
    require_role($user, 'owner', 'admin');
    $input = json_input();
    $action = trim((string)($input['action'] ?? ''));
    $pdo = db();

    if ($action === 'saveSession') {
        p50_prono_live_save_session($pdo, $user, $input);
    }
    if ($action === 'activate') {
        p50_prono_live_activate($pdo, $user, $input);
    }
    if ($action === 'deactivate') {
        p50_prono_live_deactivate($pdo, $user);
    }
    if ($action === 'saveQuestion') {
        p50_prono_live_save_question($pdo, $user, $input);
    }
    if ($action === 'deleteQuestion') {
        p50_prono_live_delete_question($pdo, $input);
    }
    json_response(['error' => 'Action invalide.'], 400);
}

function p50_prono_live_write_session_meta(PDO $pdo, string $id, array $meta): void {
    $pdo->prepare('UPDATE p50_prono_live_sessions
      SET title=?, context_text=?, event_url=?, gift_kind=?, gift_photo_url=?, gift_url=?, gift_text=?
      WHERE id=?')
        ->execute([
            $meta['title'],
            $meta['context'],
            $meta['event_url'],
            $meta['gift_kind'],
            $meta['gift_photo_url'],
            $meta['gift_url'],
            $meta['gift_text'],
            $id,
        ]);
}

function p50_prono_live_ensure_session(PDO $pdo, array $user, array $input): array {
    $current = p50_prono_live_latest_session($pdo);
    $meta = p50_prono_live_session_meta($input, $current);
    if ($current && in_array((string)$current['status'], ['draft', 'active'], true)) {
        p50_prono_live_write_session_meta($pdo, (string)$current['id'], $meta);
        $fresh = $pdo->prepare('SELECT * FROM p50_prono_live_sessions WHERE id=? LIMIT 1');
        $fresh->execute([(string)$current['id']]);
        $row = $fresh->fetch();
        return $row ?: $current;
    }
    $id = p50_prono_uuid();
    $pdo->prepare('INSERT INTO p50_prono_live_sessions
      (id,title,context_text,event_url,gift_kind,gift_photo_url,gift_url,gift_text,status,activated_by)
      VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            $id,
            $meta['title'],
            $meta['context'],
            $meta['event_url'],
            $meta['gift_kind'],
            $meta['gift_photo_url'],
            $meta['gift_url'],
            $meta['gift_text'],
            'draft',
            (string)$user['id'],
        ]);
    $stmt = $pdo->prepare('SELECT * FROM p50_prono_live_sessions WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_response(['error' => 'Session Prono50 live introuvable.'], 500);
    return $row;
}

function p50_prono_live_save_session(PDO $pdo, array $user, array $input): never {
    $session = p50_prono_live_ensure_session($pdo, $user, $input);
    $rows = p50_prono_live_questions($pdo, (string)$session['id'], false);
    json_response(p50_prono_live_payload($pdo, $session, $rows, $user, true) + ['message' => 'Événement et cadeau enregistrés.']);
}

function p50_prono_live_activate(PDO $pdo, array $user, array $input): never {
    $session = p50_prono_live_ensure_session($pdo, $user, $input);
    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE p50_prono_live_sessions SET status='closed', closed_at=UTC_TIMESTAMP() WHERE status='active' AND id<>".$pdo->quote((string)$session['id']));
        $pdo->prepare("UPDATE p50_prono_live_sessions SET status='active', activated_by=?, activated_at=UTC_TIMESTAMP(), closed_at=NULL WHERE id=?")
            ->execute([(string)$user['id'], (string)$session['id']]);
        $now = p50_prono_now();
        $closes = $now->modify('+7 days');
        $pdo->prepare("UPDATE p50_prono_questions
          SET status='open', opens_at=?, closes_at=?, measure_at=?
          WHERE live_session_id=? AND source_type=? AND status IN ('draft','open','locked')")
            ->execute([
                $now->format('Y-m-d H:i:s'),
                $closes->format('Y-m-d H:i:s'),
                $closes->format('Y-m-d H:i:s'),
                (string)$session['id'],
                P50_PRONO_LIVE_SOURCE,
            ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('PASS50 prono-live activate: '.$e->getMessage());
        json_response(['error' => 'Impossible d’activer Prono50 live.'], 500);
    }
    $fresh = $pdo->prepare('SELECT * FROM p50_prono_live_sessions WHERE id=? LIMIT 1');
    $fresh->execute([(string)$session['id']]);
    $session = $fresh->fetch() ?: $session;
    $rows = p50_prono_live_questions($pdo, (string)$session['id'], false);
    json_response(p50_prono_live_payload($pdo, $session, $rows, $user, true) + ['message' => 'Prono50 live activé.']);
}

function p50_prono_live_deactivate(PDO $pdo, array $user): never {
    $session = p50_prono_live_active_session($pdo);
    if (!$session) {
        $latest = p50_prono_live_latest_session($pdo);
        $rows = $latest ? p50_prono_live_questions($pdo, (string)$latest['id'], false) : [];
        json_response(p50_prono_live_payload($pdo, $latest, $rows, $user, true) + ['message' => 'Prono50 live déjà coupé.']);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE p50_prono_live_sessions SET status='closed', closed_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([(string)$session['id']]);
        $pdo->prepare("UPDATE p50_prono_questions SET status='locked', closes_at=UTC_TIMESTAMP()
          WHERE live_session_id=? AND source_type=? AND status='open'")
            ->execute([(string)$session['id'], P50_PRONO_LIVE_SOURCE]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('PASS50 prono-live deactivate: '.$e->getMessage());
        json_response(['error' => 'Impossible de couper Prono50 live.'], 500);
    }
    $fresh = $pdo->prepare('SELECT * FROM p50_prono_live_sessions WHERE id=? LIMIT 1');
    $fresh->execute([(string)$session['id']]);
    $session = $fresh->fetch() ?: $session;
    $rows = p50_prono_live_questions($pdo, (string)$session['id'], false);
    json_response(p50_prono_live_payload($pdo, $session, $rows, $user, true) + ['message' => 'Prono50 live coupé.']);
}

function p50_prono_live_save_question(PDO $pdo, array $user, array $input): never {
    $session = p50_prono_live_ensure_session($pdo, $user, $input);
    $id = trim((string)($input['id'] ?? ''));
    $title = trim((string)($input['title'] ?? ''));
    $context = trim((string)($input['context'] ?? ''));
    $profileId = trim((string)($input['profileId'] ?? ''));
    $coverImageUrl = trim((string)($input['coverImageUrl'] ?? ''));
    $options = p50_prono_options($input['options'] ?? []);
    if ($title === '' || count($options) < 2) {
        json_response(['error' => 'Titre et au moins 2 options requis.'], 400);
    }
    try {
        $coverImageUrl = p50_prono_assert_cover($coverImageUrl, $profileId, $title);
    } catch (InvalidArgumentException $e) {
        json_response(['error' => $e->getMessage()], 400);
    }

    $now = p50_prono_now();
    $closes = $now->modify('+7 days');
    $sessionActive = (string)$session['status'] === 'active';
    $status = $sessionActive ? 'open' : 'draft';
    $subjectKey = 'live:'.(string)$session['id'];
    $theme = p50_prono_normalize_theme((string)($input['theme'] ?? '')) ?: 'people_influenceurs';

    if ($id === '') {
        $id = p50_prono_uuid();
        $pdo->prepare('INSERT INTO p50_prono_questions
          (id,title,context_text,cover_image_url,theme,profile_id,subject_key,options_json,metric_type,opens_at,closes_at,measure_at,points_correct,status,created_by,source_type,live_session_id)
          VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $id,
                mb_substr($title, 0, 220),
                mb_substr($context, 0, 500),
                mb_substr($coverImageUrl, 0, 500),
                $theme,
                mb_substr($profileId, 0, 100),
                $subjectKey,
                json_encode($options, JSON_UNESCAPED_UNICODE),
                'manual',
                $now->format('Y-m-d H:i:s'),
                $closes->format('Y-m-d H:i:s'),
                $closes->format('Y-m-d H:i:s'),
                P50_PRONO_POINTS_CORRECT,
                $status,
                (string)$user['id'],
                P50_PRONO_LIVE_SOURCE,
                (string)$session['id'],
            ]);
    } else {
        $exists = $pdo->prepare('SELECT id,status FROM p50_prono_questions WHERE id=? AND live_session_id=? LIMIT 1');
        $exists->execute([$id, (string)$session['id']]);
        $existing = $exists->fetch();
        if (!$existing) json_response(['error' => 'Prono live introuvable.'], 404);
        $keepStatus = (string)$existing['status'] === 'resolved' ? 'resolved' : $status;
        $pdo->prepare('UPDATE p50_prono_questions
          SET title=?,context_text=?,cover_image_url=?,theme=?,profile_id=?,subject_key=?,options_json=?,opens_at=?,closes_at=?,measure_at=?,status=?,source_type=?,live_session_id=?
          WHERE id=?')
            ->execute([
                mb_substr($title, 0, 220),
                mb_substr($context, 0, 500),
                mb_substr($coverImageUrl, 0, 500),
                $theme,
                mb_substr($profileId, 0, 100),
                $subjectKey,
                json_encode($options, JSON_UNESCAPED_UNICODE),
                $now->format('Y-m-d H:i:s'),
                $closes->format('Y-m-d H:i:s'),
                $closes->format('Y-m-d H:i:s'),
                $keepStatus,
                P50_PRONO_LIVE_SOURCE,
                (string)$session['id'],
                $id,
            ]);
    }

    $rows = p50_prono_live_questions($pdo, (string)$session['id'], false);
    json_response(p50_prono_live_payload($pdo, $session, $rows, $user, true) + [
        'questionId' => $id,
        'message' => 'Prono live enregistré.',
    ]);
}

function p50_prono_live_delete_question(PDO $pdo, array $input): never {
    $id = trim((string)($input['id'] ?? $input['questionId'] ?? ''));
    if ($id === '') json_response(['error' => 'id requis.'], 400);
    $stmt = $pdo->prepare('SELECT id,status FROM p50_prono_questions WHERE id=? AND source_type=? LIMIT 1');
    $stmt->execute([$id, P50_PRONO_LIVE_SOURCE]);
    $row = $stmt->fetch();
    if (!$row) json_response(['error' => 'Prono live introuvable.'], 404);
    $votes = $pdo->prepare('SELECT COUNT(*) FROM p50_prono_votes WHERE question_id=?');
    $votes->execute([$id]);
    if ((int)$votes->fetchColumn() > 0) {
        json_response(['error' => 'Impossible de supprimer : des participations existent déjà.'], 409);
    }
    $pdo->prepare('DELETE FROM p50_prono_questions WHERE id=?')->execute([$id]);
    $user = auth_user();
    $session = p50_prono_live_latest_session($pdo);
    $rows = $session ? p50_prono_live_questions($pdo, (string)$session['id'], false) : [];
    json_response(p50_prono_live_payload($pdo, $session, $rows, $user, true) + ['message' => 'Prono live supprimé.']);
}
