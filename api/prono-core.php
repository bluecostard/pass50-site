<?php
declare(strict_types=1);

const P50_PRONO_VERSION = 'PRONO-V1.3';
const P50_PRONO_POINTS_CORRECT = 100; // mise nominale (gain = mise × cote)
const P50_PRONO_STARTING_BALANCE = 1000;
const P50_PRONO_BALANCE_FLOOR = 100; // jamais en dessous — on peut toujours jouer
const P50_PRONO_POINTS_DAILY_FIRST = 50;
const P50_PRONO_POINTS_STREAK_3 = 200;
const P50_PRONO_POINTS_STREAK_7 = 600;
const P50_PRONO_POINTS_STATUS_LIKE = 0.25;
const P50_PRONO_STATUS_LIKE_CAP = 200; // max likes counted per status (50 pts max)
const P50_PRONO_STATUS_DURATIONS = [12, 24, 48];
const P50_PRONO_VOTE_HOURS = [6, 12, 24]; // fenêtre de vote courte → action
const P50_PRONO_ODD_MIN = 1.10;
const P50_PRONO_ODD_MAX = 25.00;

function p50_prono_ensure_schema(): void {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS p50_prono_questions (
        id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
        title VARCHAR(220) NOT NULL,
        context_text VARCHAR(500) NOT NULL DEFAULT '',
        profile_id VARCHAR(100) NOT NULL DEFAULT '',
        options_json JSON NOT NULL,
        metric_type VARCHAR(40) NOT NULL DEFAULT 'manual',
        metric_config_json JSON NULL,
        opens_at DATETIME NOT NULL,
        closes_at DATETIME NOT NULL,
        measure_at DATETIME NULL,
        points_correct INT UNSIGNED NOT NULL DEFAULT 100,
        status VARCHAR(24) NOT NULL DEFAULT 'draft',
        winning_option_key VARCHAR(40) NOT NULL DEFAULT '',
        evidence_json JSON NULL,
        created_by CHAR(36) NOT NULL DEFAULT '',
        resolved_at DATETIME NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        INDEX idx_p50_prono_q_status(status,closes_at),
        INDEX idx_p50_prono_q_open(status,opens_at,closes_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS p50_prono_votes (
        id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
        question_id CHAR(36) CHARACTER SET ascii NOT NULL,
        user_id CHAR(36) NOT NULL,
        option_key VARCHAR(40) NOT NULL,
        odd_locked DECIMAL(8,2) NOT NULL DEFAULT 1.00,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        UNIQUE KEY uq_p50_prono_vote(question_id,user_id),
        INDEX idx_p50_prono_vote_user(user_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS p50_prono_balances (
        user_id CHAR(36) PRIMARY KEY,
        balance DECIMAL(12,2) NOT NULL DEFAULT 0,
        streak INT UNSIGNED NOT NULL DEFAULT 0,
        last_play_date DATE NULL,
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS p50_prono_points_ledger (
        id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
        user_id CHAR(36) NOT NULL,
        delta DECIMAL(12,2) NOT NULL,
        reason VARCHAR(60) NOT NULL,
        ref_id VARCHAR(80) NOT NULL DEFAULT '',
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        INDEX idx_p50_prono_ledger_user(user_id,created_at),
        INDEX idx_p50_prono_ledger_reason(reason,ref_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS p50_prono_statuses (
        id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
        user_id CHAR(36) NOT NULL,
        question_id CHAR(36) CHARACTER SET ascii NOT NULL,
        vote_id CHAR(36) CHARACTER SET ascii NOT NULL,
        option_key VARCHAR(40) NOT NULL,
        duration_hours SMALLINT UNSIGNED NOT NULL,
        like_count INT UNSIGNED NOT NULL DEFAULT 0,
        like_points_awarded DECIMAL(12,2) NOT NULL DEFAULT 0,
        status VARCHAR(24) NOT NULL DEFAULT 'live',
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        expires_at DATETIME NOT NULL,
        INDEX idx_p50_prono_status_live(status,expires_at,created_at),
        INDEX idx_p50_prono_status_user(user_id,created_at),
        UNIQUE KEY uq_p50_prono_status_vote(vote_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS p50_prono_status_likes (
        status_id CHAR(36) CHARACTER SET ascii NOT NULL,
        user_id CHAR(36) NOT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (status_id,user_id),
        INDEX idx_p50_prono_status_likes_user(user_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    p50_prono_ensure_column($pdo, 'p50_prono_questions', 'measure_at', 'DATETIME NULL AFTER closes_at');
    p50_prono_ensure_column($pdo, 'p50_prono_votes', 'odd_locked', 'DECIMAL(8,2) NOT NULL DEFAULT 1.00 AFTER option_key');
    p50_prono_ensure_column($pdo, 'p50_prono_votes', 'stake_locked', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER odd_locked');
}

function p50_prono_ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    if ((int)$stmt->fetchColumn() > 0) return;
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

/** Vote window ended → locked (awaiting measure / resolve). */
function p50_prono_lock_closed(PDO $pdo): void {
    $pdo->exec("UPDATE p50_prono_questions SET status='locked'
      WHERE status='open' AND closes_at<=UTC_TIMESTAMP()");
}

function p50_prono_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function p50_prono_now(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function p50_prono_normalize_odd(mixed $value, ?float $fallback = null): float {
    $odd = is_numeric($value) ? (float)$value : ($fallback ?? 2.0);
    if ($odd < P50_PRONO_ODD_MIN) $odd = P50_PRONO_ODD_MIN;
    if ($odd > P50_PRONO_ODD_MAX) $odd = P50_PRONO_ODD_MAX;
    return round($odd, 2);
}

function p50_prono_default_odd(int $optionCount): float {
    if ($optionCount <= 1) return 2.0;
    return p50_prono_normalize_odd(round(max(1.2, min(8.0, $optionCount * 0.85)), 2));
}

function p50_prono_options(mixed $json): array {
    $rows = is_array($json) ? $json : decode_json_column(is_string($json) ? $json : null, []);
    if (!is_array($rows)) return [];
    $raw = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $key = trim((string)($row['key'] ?? ''));
        $label = trim((string)($row['label'] ?? ''));
        if ($key === '' || $label === '') continue;
        $raw[] = [
            'key' => mb_substr($key, 0, 40),
            'label' => mb_substr($label, 0, 160),
            'odd' => $row['odd'] ?? $row['cote'] ?? null,
        ];
    }
    $raw = array_slice($raw, 0, 4);
    $fallback = p50_prono_default_odd(count($raw));
    $out = [];
    foreach ($raw as $row) {
        $out[] = [
            'key' => $row['key'],
            'label' => $row['label'],
            'odd' => p50_prono_normalize_odd($row['odd'], $fallback),
        ];
    }
    return $out;
}

function p50_prono_option_odd(array $options, string $optionKey): float {
    foreach ($options as $opt) {
        if (($opt['key'] ?? '') === $optionKey) {
            return p50_prono_normalize_odd($opt['odd'] ?? null);
        }
    }
    return p50_prono_normalize_odd(null);
}

function p50_prono_payout(int $stake, float $odd): int {
    return max(1, (int)round($stake * $odd));
}

function p50_prono_vote_tallies(PDO $pdo, string $questionId, array $options): array {
    $counts = [];
    foreach ($options as $opt) {
        $counts[(string)$opt['key']] = 0;
    }
    $stmt = $pdo->prepare('SELECT option_key, COUNT(*) AS c FROM p50_prono_votes WHERE question_id=? GROUP BY option_key');
    $stmt->execute([$questionId]);
    $total = 0;
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $key = (string)$row['option_key'];
        $c = (int)$row['c'];
        if (!array_key_exists($key, $counts)) $counts[$key] = 0;
        $counts[$key] = $c;
        $total += $c;
    }
    $tallies = [];
    foreach ($options as $opt) {
        $key = (string)$opt['key'];
        $count = (int)($counts[$key] ?? 0);
        $pct = $total > 0 ? round(100 * $count / $total, 1) : 0.0;
        $tallies[] = [
            'key' => $key,
            'count' => $count,
            'percent' => $pct,
        ];
    }
    return ['totalVotes' => $total, 'tallies' => $tallies];
}

function p50_prono_expire_statuses(PDO $pdo): void {
    $pdo->exec("UPDATE p50_prono_statuses SET status='expired' WHERE status='live' AND expires_at<=UTC_TIMESTAMP()");
}

function p50_prono_balance(PDO $pdo, string $userId): array {
    p50_prono_ensure_balance($pdo, $userId);
    $stmt = $pdo->prepare('SELECT balance,streak,last_play_date FROM p50_prono_balances WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['balance' => (float)P50_PRONO_STARTING_BALANCE, 'streak' => 0, 'lastPlayDate' => null, 'floor' => P50_PRONO_BALANCE_FLOOR];
    }
    return [
        'balance' => round((float)$row['balance'], 2),
        'streak' => (int)$row['streak'],
        'lastPlayDate' => $row['last_play_date'] !== null ? (string)$row['last_play_date'] : null,
        'floor' => P50_PRONO_BALANCE_FLOOR,
    ];
}

/** Crée le solde de départ (1000) si le joueur n’a pas encore de ligne. */
function p50_prono_ensure_balance(PDO $pdo, string $userId): void {
    $pdo->prepare('INSERT IGNORE INTO p50_prono_balances(user_id,balance,streak) VALUES(?,?,0)')
        ->execute([$userId, P50_PRONO_STARTING_BALANCE]);
}

/**
 * Débite une mise sans jamais passer sous le plancher.
 * @return int montant réellement débité
 */
function p50_prono_debit_stake(PDO $pdo, string $userId, int $desiredStake, string $refId = ''): int {
    if ($desiredStake <= 0) return 0;
    $bal = p50_prono_balance($pdo, $userId);
    $maxDebitable = (int)max(0, floor($bal['balance'] - P50_PRONO_BALANCE_FLOOR));
    $take = min($desiredStake, $maxDebitable);
    if ($take <= 0) return 0;
    p50_prono_credit($pdo, $userId, -1 * $take, 'prono_stake', $refId);
    return $take;
}

function p50_prono_credit(PDO $pdo, string $userId, float $delta, string $reason, string $refId = ''): float {
    p50_prono_ensure_balance($pdo, $userId);
    if (abs($delta) < 0.0001) return p50_prono_balance($pdo, $userId)['balance'];
    $pdo->prepare('INSERT INTO p50_prono_balances(user_id,balance) VALUES(?,?) ON DUPLICATE KEY UPDATE balance=balance+VALUES(balance)')
        ->execute([$userId, $delta]);
    // Filet de sécurité plancher
    $pdo->prepare('UPDATE p50_prono_balances SET balance=? WHERE user_id=? AND balance<?')
        ->execute([P50_PRONO_BALANCE_FLOOR, $userId, P50_PRONO_BALANCE_FLOOR]);
    $pdo->prepare('INSERT INTO p50_prono_points_ledger(id,user_id,delta,reason,ref_id) VALUES(?,?,?,?,?)')
        ->execute([p50_prono_uuid(), $userId, $delta, mb_substr($reason, 0, 60), mb_substr($refId, 0, 80)]);
    return p50_prono_balance($pdo, $userId)['balance'];
}

function p50_prono_touch_streak(PDO $pdo, string $userId): array {
    $today = p50_prono_now()->format('Y-m-d');
    $bal = p50_prono_balance($pdo, $userId);
    $last = $bal['lastPlayDate'];
    $streak = (int)$bal['streak'];
    $bonus = 0.0;
    $bonusReason = '';

    if ($last === $today) {
        return ['streak' => $streak, 'dailyFirst' => false, 'bonus' => 0.0, 'bonusReason' => ''];
    }

    $yesterday = p50_prono_now()->modify('-1 day')->format('Y-m-d');
    $streak = ($last === $yesterday) ? $streak + 1 : 1;

    $pdo->prepare('INSERT INTO p50_prono_balances(user_id,balance,streak,last_play_date) VALUES(?,?,?,?)
        ON DUPLICATE KEY UPDATE streak=VALUES(streak), last_play_date=VALUES(last_play_date)')
        ->execute([$userId, P50_PRONO_STARTING_BALANCE, $streak, $today]);

    p50_prono_credit($pdo, $userId, P50_PRONO_POINTS_DAILY_FIRST, 'daily_first', $today);

    if ($streak === 3) {
        $bonus = P50_PRONO_POINTS_STREAK_3;
        $bonusReason = 'streak_3';
        p50_prono_credit($pdo, $userId, $bonus, $bonusReason, $today);
    } elseif ($streak === 7) {
        $bonus = P50_PRONO_POINTS_STREAK_7;
        $bonusReason = 'streak_7';
        p50_prono_credit($pdo, $userId, $bonus, $bonusReason, $today);
    }

    return ['streak' => $streak, 'dailyFirst' => true, 'bonus' => $bonus, 'bonusReason' => $bonusReason];
}

function p50_prono_question_public(array $row, ?array $vote = null, ?array $tallyBundle = null): array {
    $options = p50_prono_options($row['options_json'] ?? []);
    $stake = (int)($row['points_correct'] ?? P50_PRONO_POINTS_CORRECT);
    foreach ($options as &$opt) {
        $opt['payout'] = p50_prono_payout($stake, (float)$opt['odd']);
    }
    unset($opt);
    $item = [
        'id' => (string)$row['id'],
        'title' => (string)$row['title'],
        'context' => (string)($row['context_text'] ?? ''),
        'profileId' => (string)($row['profile_id'] ?? ''),
        'options' => $options,
        'metricType' => (string)($row['metric_type'] ?? 'manual'),
        'opensAt' => gmdate('c', strtotime((string)$row['opens_at'] . ' UTC')),
        'closesAt' => gmdate('c', strtotime((string)$row['closes_at'] . ' UTC')),
        'measureAt' => !empty($row['measure_at'])
            ? gmdate('c', strtotime((string)$row['measure_at'] . ' UTC'))
            : null,
        'status' => (string)$row['status'],
        'stake' => $stake,
        'pointsCorrect' => $stake, // alias rétrocompat
        'myVote' => null,
        'totalVotes' => 0,
        'tallies' => [],
    ];
    if ($tallyBundle) {
        $item['totalVotes'] = (int)($tallyBundle['totalVotes'] ?? 0);
        $item['tallies'] = $tallyBundle['tallies'] ?? [];
        $byKey = [];
        foreach ($item['tallies'] as $t) {
            $byKey[(string)$t['key']] = $t;
        }
        foreach ($item['options'] as &$opt) {
            $t = $byKey[(string)$opt['key']] ?? null;
            $opt['voteCount'] = $t ? (int)$t['count'] : 0;
            $opt['votePercent'] = $t ? (float)$t['percent'] : 0.0;
        }
        unset($opt);
    }
    if ($vote) {
        $locked = isset($vote['odd_locked']) ? p50_prono_normalize_odd($vote['odd_locked']) : p50_prono_option_odd($options, (string)$vote['option_key']);
        $stakeLocked = isset($vote['stake_locked']) ? (int)$vote['stake_locked'] : 0;
        $effectiveStake = $stakeLocked > 0 ? $stakeLocked : $stake;
        $item['myVote'] = [
            'optionKey' => (string)$vote['option_key'],
            'oddLocked' => $locked,
            'stakeLocked' => $stakeLocked,
            'potentialPayout' => p50_prono_payout($effectiveStake, $locked),
            'updatedAt' => gmdate('c', strtotime((string)$vote['updated_at'] . ' UTC')),
        ];
    }
    if (($row['status'] ?? '') === 'resolved') {
        $item['winningOptionKey'] = (string)($row['winning_option_key'] ?? '');
        $item['resolvedAt'] = !empty($row['resolved_at']) ? gmdate('c', strtotime((string)$row['resolved_at'] . ' UTC')) : null;
    }
    return $item;
}

function p50_prono_status_public(array $row, bool $likedByMe = false): array {
    $options = p50_prono_options($row['options_json'] ?? []);
    $optionKey = (string)$row['option_key'];
    $odd = isset($row['odd_locked']) && (float)$row['odd_locked'] > 0
        ? p50_prono_normalize_odd($row['odd_locked'])
        : p50_prono_option_odd($options, $optionKey);
    $stake = isset($row['stake_locked']) && (int)$row['stake_locked'] > 0
        ? (int)$row['stake_locked']
        : (int)($row['points_correct'] ?? P50_PRONO_POINTS_CORRECT);
    return [
        'id' => (string)$row['id'],
        'feedType' => 'prono_status',
        'questionId' => (string)$row['question_id'],
        'questionTitle' => (string)($row['question_title'] ?? ''),
        'profileId' => (string)($row['profile_id'] ?? ''),
        'optionKey' => $optionKey,
        'optionLabel' => (string)($row['option_label'] ?? $optionKey),
        'odd' => $odd,
        'stake' => $stake,
        'potentialPayout' => p50_prono_payout($stake, $odd),
        'authorPseudo' => (string)($row['author_display_name'] ?? 'Membre PASS50'),
        'authorUserId' => (string)$row['user_id'],
        'likeCount' => (int)$row['like_count'],
        'likedByMe' => $likedByMe,
        'durationHours' => (int)$row['duration_hours'],
        'publishedAt' => gmdate('c', strtotime((string)$row['created_at'] . ' UTC')),
        'expiresAt' => gmdate('c', strtotime((string)$row['expires_at'] . ' UTC')),
        'status' => (string)$row['status'],
    ];
}

function p50_prono_option_label(array $questionRow, string $optionKey): string {
    foreach (p50_prono_options($questionRow['options_json'] ?? []) as $opt) {
        if ($opt['key'] === $optionKey) return $opt['label'];
    }
    return $optionKey;
}
