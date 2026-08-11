<?php
declare(strict_types=1);

require_once __DIR__.'/prono-core.php';

function p50_prono_is_artist_profile(array $profile): bool {
    $cat = mb_strtolower((string)($profile['category'] ?? ''));
    return preg_match('/musique|artiste|music|rap|afro|chanteur|chanteuse|dj/i', $cat) === 1;
}

function p50_prono_ranked_profiles(string $period = '24h'): array {
    $state = p50_prono_public_state();
    $profiles = is_array($state['profiles'] ?? null) ? $state['profiles'] : [];
    $rows = [];
    foreach ($profiles as $profile) {
        if (!is_array($profile)) continue;
        if (isset($profile['alive']) && !$profile['alive']) continue;
        $score = $profile['scores'][$period] ?? ($profile['scores']['24h'] ?? null);
        if (!is_numeric($score)) {
            $score = $profile['scores']['7d'] ?? ($profile['scores']['2h'] ?? 0);
        }
        if (!is_numeric($score)) continue;
        $cover = p50_prono_profile_photo_any($profile);
        if ($cover === '') {
            $resolved = p50_prono_resolve_cover((string)($profile['id'] ?? ''), (string)($profile['name'] ?? ''));
            $cover = $resolved['coverPhoto'];
        }
        if ($cover === '') continue;
        $rows[] = [
            'profile' => $profile,
            'profileId' => (string)($profile['id'] ?? ''),
            'name' => (string)($profile['name'] ?? ''),
            'score' => (float)$score,
            'coverPhoto' => $cover,
            'isArtist' => p50_prono_is_artist_profile($profile),
        ];
    }
    usort($rows, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']));
    return $rows;
}

/** @return list<array<string,mixed>> */
function p50_prono_daily_news_with_cover(PDO $pdo, int $limit = 24): array {
    try {
        $stmt = $pdo->query("SELECT n.id,n.profile_id,n.platform,n.item_type,n.canonical_url,n.title,n.thumbnail_url,
          n.source_published_at,r.public_name
          FROM p50_news_items n
          LEFT JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY n.profile_id
          WHERE n.validation_status='published'
            AND n.thumbnail_url<>'' AND n.thumbnail_url IS NOT NULL
            AND (n.expires_at IS NULL OR n.expires_at>UTC_TIMESTAMP())
            AND COALESCE(n.source_published_at,n.pass50_published_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)
          ORDER BY COALESCE(n.source_published_at,n.pass50_published_at) DESC,n.id DESC
          LIMIT ".$limit);
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $thumb = p50_prono_abs_media_url(trim((string)($row['thumbnail_url'] ?? '')));
        if ($thumb === '') continue;
        $out[] = [
            'newsId' => (int)$row['id'],
            'profileId' => trim((string)($row['profile_id'] ?? '')),
            'name' => trim((string)($row['public_name'] ?? '')),
            'title' => trim((string)($row['title'] ?? '')),
            'platform' => trim((string)($row['platform'] ?? '')),
            'coverPhoto' => $thumb,
            'subjectKey' => 'news:'.(int)$row['id'],
        ];
    }
    return $out;
}

/** @return list<array<string,mixed>> */
function p50_prono_daily_events_with_cover(): array {
    $state = p50_prono_public_state();
    $events = is_array($state['events'] ?? null) ? $state['events'] : [];
    $profiles = [];
    foreach (is_array($state['profiles'] ?? null) ? $state['profiles'] : [] as $p) {
        if (is_array($p) && !empty($p['id'])) $profiles[(string)$p['id']] = $p;
    }
    $out = [];
    foreach ($events as $event) {
        if (!is_array($event)) continue;
        $cover = p50_prono_event_cover($event);
        if ($cover === '') continue;
        $pid = trim((string)($event['profileId'] ?? ''));
        $profile = $profiles[$pid] ?? null;
        $out[] = [
            'profileId' => $pid,
            'name' => is_array($profile) ? (string)($profile['name'] ?? '') : '',
            'eventTitle' => trim((string)($event['title'] ?? $event['name'] ?? '')),
            'coverPhoto' => $cover,
            'subjectKey' => 'event:'.($event['id'] ?? $pid),
        ];
    }
    return $out;
}

function p50_prono_daily_build_options(array $labels, float $difficulty = 0.5, ?int $favoredIndex = null): array {
    $odds = p50_prono_compute_odds(count($labels), $difficulty, $favoredIndex);
    $options = [];
    $keys = ['y', 'n', 'a', 'b'];
    foreach ($labels as $i => $label) {
        $options[] = [
            'key' => $keys[$i] ?? ('o'.$i),
            'label' => $label,
            'odd' => $odds[$i] ?? p50_prono_default_odd(count($labels)),
        ];
    }
    return $options;
}

function p50_prono_daily_measure_days(int $days): string {
    return p50_prono_now()->modify('+'.$days.' days')->format('Y-m-d').' 23:59:59';
}

/**
 * @param list<array<string,mixed>> $influencers
 * @param list<array<string,mixed>> $artists
 * @param list<array<string,mixed>> $news
 * @param list<array<string,mixed>> $events
 * @return list<array<string,mixed>>
 */
function p50_prono_daily_templates(
    array $influencers,
    array $artists,
    array $news,
    array $events
): array {
    $templates = [];
    $usedSubjects = [];

    if (isset($influencers[0])) {
        $fi = $influencers[0];
        $templates[] = [
            'theme' => 'rank_top3',
            'sourceType' => 'influencer',
            'profileId' => $fi['profileId'],
            'subjectKey' => 'fi:'.$fi['profileId'],
            'coverPhoto' => $fi['coverPhoto'],
            'title' => $fi['name'].' reste-t-il dans le Top 3 PASS50 24H demain ?',
            'context' => 'Classement actuel · Trend Score '.number_format($fi['score'], 1, ',', ' ').'.',
            'options' => p50_prono_daily_build_options(['Oui', 'Non'], 0.35, 0),
            'metricType' => 'rank_position',
            'measureDays' => 1,
            'voteHours' => 12,
        ];
    }

    if (isset($influencers[2])) {
        $fi = $influencers[2];
        $templates[] = [
            'theme' => 'rank_climb',
            'sourceType' => 'influencer',
            'profileId' => $fi['profileId'],
            'subjectKey' => 'fi:'.$fi['profileId'],
            'coverPhoto' => $fi['coverPhoto'],
            'title' => $fi['name'].' grimpe-t-il d’au moins 3 places cette semaine ?',
            'context' => 'Mouvement au classement 24H · mesure dans 7 jours.',
            'options' => p50_prono_daily_build_options(['Oui', 'Non'], 0.55),
            'metricType' => 'rank_delta',
            'measureDays' => 7,
            'voteHours' => 24,
        ];
    }

    if (isset($influencers[4])) {
        $fi = $influencers[4];
        $templates[] = [
            'theme' => 'score_threshold',
            'sourceType' => 'influencer',
            'profileId' => $fi['profileId'],
            'subjectKey' => 'fi:'.$fi['profileId'],
            'coverPhoto' => $fi['coverPhoto'],
            'title' => $fi['name'].' dépasse-t-il 85 Trend Score en 48 h ?',
            'context' => 'Score actuel : '.number_format($fi['score'], 1, ',', ' ').'.',
            'options' => p50_prono_daily_build_options(['Oui', 'Non'], 0.48),
            'metricType' => 'score_threshold',
            'measureDays' => 2,
            'voteHours' => 12,
        ];
    }

    if (isset($influencers[1])) {
        $fi = $influencers[1];
        $templates[] = [
            'theme' => 'live_today',
            'sourceType' => 'influencer',
            'profileId' => $fi['profileId'],
            'subjectKey' => 'fi:'.$fi['profileId'],
            'coverPhoto' => $fi['coverPhoto'],
            'title' => $fi['name'].' passe-t-il en live aujourd’hui ?',
            'context' => 'TikTok, YouTube ou Facebook Live · mesure ce soir.',
            'options' => p50_prono_daily_build_options(['Oui', 'Non'], 0.62),
            'metricType' => 'live_appeared',
            'measureDays' => 1,
            'voteHours' => 6,
        ];
    }

    if (isset($influencers[0], $influencers[1]) && $influencers[0]['profileId'] !== $influencers[1]['profileId']) {
        $a = $influencers[0];
        $b = $influencers[1];
        $sk = 'duel:'.$a['profileId'].':'.$b['profileId'];
        if (!isset($usedSubjects[$sk])) {
            $usedSubjects[$sk] = true;
            $templates[] = [
                'theme' => 'influencer_duel',
                'sourceType' => 'influencer',
                'profileId' => $a['profileId'],
                'subjectKey' => $sk,
                'coverPhoto' => $a['coverPhoto'],
                'title' => $a['name'].' devance-t-il '.$b['name'].' au classement 24H cette semaine ?',
                'context' => 'Duel Trend Score · résolution dans 7 jours.',
                'options' => p50_prono_daily_build_options(['Oui', 'Non'], 0.5, 0),
                'metricType' => 'rank_position',
                'measureDays' => 7,
                'voteHours' => 24,
            ];
        }
    }

    if (isset($influencers[6])) {
        $fi = $influencers[6];
        $templates[] = [
            'theme' => 'followers_buzz',
            'sourceType' => 'influencer',
            'profileId' => $fi['profileId'],
            'subjectKey' => 'fi:'.$fi['profileId'],
            'coverPhoto' => $fi['coverPhoto'],
            'title' => $fi['name'].' gagne-t-il plus de 50 000 abonnés cette semaine ?',
            'context' => 'Buzz réseaux · mesure dans 7 jours.',
            'options' => p50_prono_daily_build_options(['Oui', 'Non'], 0.58),
            'metricType' => 'followers_delta',
            'measureDays' => 7,
            'voteHours' => 24,
        ];
    }

    foreach (array_slice($news, 0, 3) as $item) {
        $sk = (string)$item['subjectKey'];
        if (isset($usedSubjects[$sk])) continue;
        $usedSubjects[$sk] = true;
        $name = $item['name'] !== '' ? $item['name'] : 'Cette FI';
        $short = mb_strlen($item['title']) > 60 ? mb_substr($item['title'], 0, 57).'…' : $item['title'];
        $templates[] = [
            'theme' => 'news_video',
            'sourceType' => 'news',
            'profileId' => $item['profileId'],
            'subjectKey' => $sk,
            'coverPhoto' => $item['coverPhoto'],
            'title' => $name.' — ce contenu dépasse-t-il 500 000 vues en 48 h ?',
            'context' => $short.' · '.$item['platform'],
            'options' => p50_prono_daily_build_options(['Oui', 'Non'], 0.52),
            'metricType' => 'manual',
            'measureDays' => 2,
            'voteHours' => 12,
        ];
        if (count($templates) >= 10) break;
    }

    foreach (array_slice($artists, 0, 3) as $fi) {
        $sk = 'fi:'.$fi['profileId'];
        if (isset($usedSubjects[$sk])) continue;
        $usedSubjects[$sk] = true;
        $templates[] = [
            'theme' => 'artist_rank',
            'sourceType' => 'artist_influencer',
            'profileId' => $fi['profileId'],
            'subjectKey' => $sk,
            'coverPhoto' => $fi['coverPhoto'],
            'title' => $fi['name'].' finit-il dans le Top 10 PASS50 24H demain ?',
            'context' => 'Artiste influenceur · Trend Score '.number_format($fi['score'], 1, ',', ' ').'.',
            'options' => p50_prono_daily_build_options(['Oui', 'Non'], 0.45, 1),
            'metricType' => 'rank_position',
            'measureDays' => 1,
            'voteHours' => 12,
        ];
    }

    if (isset($artists[0])) {
        $fi = $artists[0];
        $sk = 'artist-release:'.$fi['profileId'];
        if (!isset($usedSubjects[$sk])) {
            $usedSubjects[$sk] = true;
            $templates[] = [
                'theme' => 'artist_release',
                'sourceType' => 'artist_influencer',
                'profileId' => $fi['profileId'],
                'subjectKey' => $sk,
                'coverPhoto' => $fi['coverPhoto'],
                'title' => $fi['name'].' annonce-t-il un nouveau titre cette semaine ?',
                'context' => 'Réseaux sociaux · mesure dans 7 jours.',
                'options' => p50_prono_daily_build_options(['Oui', 'Non'], 0.65),
                'metricType' => 'manual',
                'measureDays' => 7,
                'voteHours' => 24,
            ];
        }
    }

    foreach (array_slice($events, 0, 2) as $ev) {
        $sk = (string)$ev['subjectKey'];
        if (isset($usedSubjects[$sk])) continue;
        $usedSubjects[$sk] = true;
        $name = $ev['name'] !== '' ? $ev['name'] : 'Cette FI';
        $templates[] = [
            'theme' => 'event_buzz',
            'sourceType' => 'event',
            'profileId' => $ev['profileId'],
            'subjectKey' => $sk,
            'coverPhoto' => $ev['coverPhoto'],
            'title' => $name.' — l’événement « '.$ev['eventTitle'].' » buzz-t-il cette semaine ?',
            'context' => 'Couverture événement · mesure dans 7 jours.',
            'options' => p50_prono_daily_build_options(['Oui', 'Non'], 0.5),
            'metricType' => 'manual',
            'measureDays' => 7,
            'voteHours' => 24,
        ];
    }

    foreach ($influencers as $fi) {
        if (count($templates) >= P50_PRONO_DAILY_COUNT) break;
        $cat = mb_strtolower((string)($fi['profile']['category'] ?? ''));
        if (!str_contains($cat, 'diaspora')) continue;
        $sk = 'diaspora:'.$fi['profileId'];
        if (isset($usedSubjects[$sk])) continue;
        $usedSubjects[$sk] = true;
        $templates[] = [
            'theme' => 'diaspora_trend',
            'sourceType' => 'influencer',
            'profileId' => $fi['profileId'],
            'subjectKey' => $sk,
            'coverPhoto' => $fi['coverPhoto'],
            'title' => $fi['name'].' reste-t-il dans le Top 20 diaspora 24H ?',
            'context' => 'Profil diaspora · Trend Score '.number_format($fi['score'], 1, ',', ' ').'.',
            'options' => p50_prono_daily_build_options(['Oui', 'Non'], 0.42),
            'metricType' => 'rank_position',
            'measureDays' => 3,
            'voteHours' => 24,
        ];
    }

    // Compléter jusqu’à 12 avec des sujets uniques (même FI OK, clé différente)
    $pool = array_values(array_merge($influencers, $artists));
    $variants = [
        ['theme' => 'daily_rank', 'title' => '%s reste-t-il dans le Top 20 PASS50 24H demain ?', 'metric' => 'rank_position', 'days' => 1, 'hours' => 12, 'diff' => 0.45],
        ['theme' => 'daily_buzz', 'title' => '%s crée-t-il un buzz viral sous 48 h ?', 'metric' => 'manual', 'days' => 2, 'hours' => 12, 'diff' => 0.55],
        ['theme' => 'daily_live', 'title' => '%s passe-t-il en live cette semaine ?', 'metric' => 'live_appeared', 'days' => 7, 'hours' => 24, 'diff' => 0.6],
        ['theme' => 'daily_climb', 'title' => '%s gagne-t-il des places au classement 24H sous 3 jours ?', 'metric' => 'rank_delta', 'days' => 3, 'hours' => 24, 'diff' => 0.5],
        ['theme' => 'daily_followers', 'title' => '%s gagne-t-il plus d’abonnés que la moyenne cette semaine ?', 'metric' => 'followers_delta', 'days' => 7, 'hours' => 24, 'diff' => 0.52],
    ];
    $variantIdx = 0;
    foreach ($pool as $fi) {
        if (count($templates) >= P50_PRONO_DAILY_COUNT) break;
        if (($fi['profileId'] ?? '') === '' || ($fi['coverPhoto'] ?? '') === '') continue;
        $variant = $variants[$variantIdx % count($variants)];
        $variantIdx++;
        $sk = 'daily:'.$variant['theme'].':'.$fi['profileId'];
        if (isset($usedSubjects[$sk])) continue;
        $usedSubjects[$sk] = true;
        $templates[] = [
            'theme' => $variant['theme'],
            'sourceType' => !empty($fi['isArtist']) ? 'artist_influencer' : 'influencer',
            'profileId' => $fi['profileId'],
            'subjectKey' => $sk,
            'coverPhoto' => $fi['coverPhoto'],
            'title' => sprintf($variant['title'], $fi['name']),
            'context' => 'Batch quotidien PASS50 · Trend Score '.number_format((float)$fi['score'], 1, ',', ' ').'.',
            'options' => p50_prono_daily_build_options(['Oui', 'Non'], (float)$variant['diff']),
            'metricType' => $variant['metric'],
            'measureDays' => (int)$variant['days'],
            'voteHours' => (int)$variant['hours'],
        ];
    }

    return array_values(array_filter($templates, static fn(array $t): bool => p50_prono_is_valid_cover_url((string)($t['coverPhoto'] ?? ''))));
}

/**
 * @return array{batchId:string,batchDate:string,items:list<array>,skipped:int,message:string}
 */
function p50_prono_daily_generate(PDO $pdo, string $createdBy, ?string $batchDate = null): array {
    p50_prono_ensure_schema();
    $batchDate = $batchDate ?: p50_prono_now()->format('Y-m-d');
    $batchId = p50_prono_uuid();

    $pdo->prepare("DELETE FROM p50_prono_questions WHERE batch_date=? AND status='draft'")
        ->execute([$batchDate]);

    $ranked = p50_prono_ranked_profiles('24h');
    $influencers = array_values(array_filter($ranked, static fn(array $r): bool => !$r['isArtist']));
    $artists = array_values(array_filter($ranked, static fn(array $r): bool => $r['isArtist']));
    $news = p50_prono_daily_news_with_cover($pdo);
    $events = p50_prono_daily_events_with_cover();

    $templates = p50_prono_daily_templates($influencers, $artists, $news, $events);
    $templates = array_slice($templates, 0, P50_PRONO_DAILY_COUNT);

    if ($templates === []) {
        return [
            'batchId' => $batchId,
            'batchDate' => $batchDate,
            'items' => [],
            'skipped' => 0,
            'message' => 'Aucun sujet avec image disponible. Vérifie les photos FI / actus.',
        ];
    }
    if (count($templates) < P50_PRONO_DAILY_COUNT) {
        // On génère quand même le maximum possible (≥1) plutôt que d’échouer à vide.
        // Le message indiquera le déficit.
    }

    $now = p50_prono_now();
    $insert = $pdo->prepare('INSERT INTO p50_prono_questions
      (id,title,context_text,cover_image_url,theme,batch_id,batch_date,source_type,profile_id,subject_key,options_json,metric_type,opens_at,closes_at,measure_at,points_correct,status,created_by)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

    $items = [];
    foreach ($templates as $tpl) {
        $cover = p50_prono_assert_cover((string)$tpl['coverPhoto'], (string)$tpl['profileId'], (string)$tpl['title']);
        $theme = p50_prono_map_to_product_theme((string)($tpl['theme'] ?? ''), (string)($tpl['sourceType'] ?? ''));
        $voteHours = (int)($tpl['voteHours'] ?? 12);
        if (!in_array($voteHours, P50_PRONO_VOTE_HOURS, true)) $voteHours = 12;
        $opens = $now;
        $closes = $now->modify('+'.$voteHours.' hours');
        $measureDays = max(1, (int)($tpl['measureDays'] ?? 7));
        $measure = new DateTimeImmutable(p50_prono_daily_measure_days($measureDays), new DateTimeZone('UTC'));
        if ($measure < $closes) {
            $measure = $closes->modify('+1 day');
        }
        $id = p50_prono_uuid();
        $options = p50_prono_options($tpl['options'] ?? []);
        $insert->execute([
            $id,
            mb_substr((string)$tpl['title'], 0, 220),
            mb_substr((string)($tpl['context'] ?? ''), 0, 500),
            mb_substr($cover, 0, 500),
            mb_substr($theme, 0, 80),
            $batchId,
            $batchDate,
            mb_substr((string)($tpl['sourceType'] ?? ''), 0, 40),
            mb_substr((string)($tpl['profileId'] ?? ''), 0, 100),
            mb_substr((string)($tpl['subjectKey'] ?? ''), 0, 120),
            json_encode($options, JSON_UNESCAPED_UNICODE),
            mb_substr((string)($tpl['metricType'] ?? 'manual'), 0, 40),
            $opens->format('Y-m-d H:i:s'),
            $closes->format('Y-m-d H:i:s'),
            $measure->format('Y-m-d H:i:s'),
            P50_PRONO_POINTS_CORRECT,
            'draft',
            $createdBy,
        ]);
        $stmt = $pdo->prepare('SELECT * FROM p50_prono_questions WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $items[] = p50_prono_question_public($stmt->fetch());
    }

    return [
        'batchId' => $batchId,
        'batchDate' => $batchDate,
        'items' => $items,
        'skipped' => 0,
        'message' => count($items) >= P50_PRONO_DAILY_COUNT
            ? count($items).' pronos générés (brouillon) · image obligatoire validée.'
            : count($items).' pronos générés (brouillon) · objectif '.P50_PRONO_DAILY_COUNT.' (photos FI limitées).',
    ];
}

/**
 * @param list<string>|null $onlyIds Si fourni, ne publie que ces IDs (toujours status draft + batchDate).
 * @return array{published:int,items:list<array>,errors:list<string>}
 */
function p50_prono_daily_publish(PDO $pdo, string $batchDate, ?array $onlyIds = null): array {
    p50_prono_ensure_schema();
    $stmt = $pdo->prepare("SELECT * FROM p50_prono_questions WHERE batch_date=? AND status='draft' ORDER BY created_at ASC");
    $stmt->execute([$batchDate]);
    $rows = $stmt->fetchAll() ?: [];

    if (is_array($onlyIds)) {
        $want = [];
        foreach ($onlyIds as $id) {
            $id = trim((string)$id);
            if ($id !== '') $want[$id] = true;
        }
        if ($want === []) {
            return ['published' => 0, 'items' => [], 'errors' => ['Aucune selection a publier.']];
        }
        $rows = array_values(array_filter($rows, static fn(array $row): bool => isset($want[(string)($row['id'] ?? '')])));
        if ($rows === []) {
            return ['published' => 0, 'items' => [], 'errors' => ['Aucun brouillon selectionne pour cette date.']];
        }
    } elseif ($rows === []) {
        return ['published' => 0, 'items' => [], 'errors' => ['Aucun brouillon pour cette date.']];
    }

    $errors = [];
    $published = [];
    foreach ($rows as $row) {
        $title = (string)($row['title'] ?? '');
        if (p50_prono_question_cover($row) === '') {
            $errors[] = 'Image manquante : '.$title;
            continue;
        }
        $subjectKey = (string)($row['subject_key'] ?? '');
        if ($subjectKey !== '') {
            $openCount = p50_prono_count_open_for_subject($pdo, $subjectKey, (string)$row['id']);
            if ($openCount >= P50_PRONO_MAX_OPEN_PER_SUBJECT) {
                $errors[] = 'Plafond sujet atteint : '.$subjectKey;
                continue;
            }
        }
        $pdo->prepare("UPDATE p50_prono_questions SET status='open' WHERE id=? AND status='draft'")
            ->execute([(string)$row['id']]);
        $fresh = $pdo->prepare('SELECT * FROM p50_prono_questions WHERE id=? LIMIT 1');
        $fresh->execute([(string)$row['id']]);
        $published[] = p50_prono_question_public($fresh->fetch());
    }

    return [
        'published' => count($published),
        'items' => $published,
        'errors' => $errors,
    ];
}

/** @return list<array> */
function p50_prono_daily_list(PDO $pdo, ?string $batchDate = null): array {
    p50_prono_ensure_schema();
    $batchDate = $batchDate ?: p50_prono_now()->format('Y-m-d');
    $stmt = $pdo->prepare("SELECT * FROM p50_prono_questions WHERE batch_date=? ORDER BY created_at ASC");
    $stmt->execute([$batchDate]);
    $items = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $items[] = p50_prono_question_public($row);
    }
    return $items;
}
