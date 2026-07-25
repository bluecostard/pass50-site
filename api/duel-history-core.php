<?php
declare(strict_types=1);

function p50_duel_history_ensure_schema(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS p50_duel_vote_history (
        id CHAR(64) CHARACTER SET ascii PRIMARY KEY,
        user_id CHAR(36) NOT NULL,
        poll_key VARCHAR(190) NOT NULL,
        candidate_a_id VARCHAR(100) NOT NULL,
        candidate_b_id VARCHAR(100) NOT NULL,
        candidate_a_name VARCHAR(190) NOT NULL,
        candidate_b_name VARCHAR(190) NOT NULL,
        candidate_a_photo TEXT NULL,
        candidate_b_photo TEXT NULL,
        selected_profile_id VARCHAR(100) NOT NULL,
        candidate_a_percentage SMALLINT UNSIGNED NULL,
        candidate_b_percentage SMALLINT UNSIGNED NULL,
        total_votes INT UNSIGNED NOT NULL DEFAULT 0,
        candidate_a_rank SMALLINT UNSIGNED NULL,
        candidate_b_rank SMALLINT UNSIGNED NULL,
        candidate_a_score DECIMAL(6,2) NULL,
        candidate_b_score DECIMAL(6,2) NULL,
        state_revision BIGINT UNSIGNED NULL,
        state_updated_at DATETIME NULL,
        voted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        INDEX idx_duel_history_user(user_id),
        INDEX idx_duel_history_poll(poll_key),
        INDEX idx_duel_history_voted(voted_at),
        INDEX idx_duel_history_selected(selected_profile_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function p50_duel_candidate_ids(string $pollKey): array {
    $ids=array_values(array_filter(explode('__',$pollKey),static fn($id)=>$id!==''&&preg_match('/^[A-Za-z0-9._:-]{1,100}$/',$id)));
    return count($ids)===2&&$ids[0]!==$ids[1]?$ids:[];
}

function p50_duel_state_snapshot(): array {
    $row=db()->query("SELECT data,updated_at FROM app_state WHERE id='public' LIMIT 1")->fetch();
    $state=$row&&is_string($row['data']??null)?json_decode((string)$row['data'],true):[];
    return ['state'=>is_array($state)?$state:[],'updatedAt'=>$row['updated_at']??null];
}

function p50_duel_public_candidates(array $candidateIds,array $snapshot): array {
    $profiles=(array)($snapshot['state']['profiles']??[]);$byId=[];
    foreach($profiles as $profile)if(is_array($profile)&&in_array((string)($profile['id']??''),$candidateIds,true))$byId[(string)$profile['id']]=$profile;
    if(count($byId)!==2)return [];
    $ranked=array_values(array_filter($profiles,static fn($profile)=>is_array($profile)&&!empty($profile['alive'])&&!empty($profile['eligible'])&&($profile['classable']??true)!==false));
    usort($ranked,static fn($a,$b)=>((float)($b['scores']['2H']??$b['score']??0))<=>((float)($a['scores']['2H']??$a['score']??0)));
    $ranks=[];foreach($ranked as $index=>$profile)$ranks[(string)($profile['id']??'')]=$index+1;
    $out=[];
    foreach($candidateIds as $id){
        $profile=$byId[$id];$photo=($profile['photoStatus']??'')==='validated'?(string)($profile['photoUrl']??$profile['photoCandidateUrl']??''):'';
        $out[$id]=[
            'profileId'=>$id,'name'=>(string)($profile['name']??$id),'initials'=>(string)($profile['initials']??''),
            'photoUrl'=>$photo,'rank'=>$ranks[$id]??null,
            'score'=>isset($profile['scores']['2H'])||isset($profile['score'])?(float)($profile['scores']['2H']??$profile['score']):null,
        ];
    }
    return $out;
}

function p50_duel_capture_vote_history(string $userId,string $pollKey,string $selectedId): ?string {
    $ids=p50_duel_candidate_ids($pollKey);if(!$ids||!in_array($selectedId,$ids,true))return null;
    $snapshot=p50_duel_state_snapshot();$profiles=p50_duel_public_candidates($ids,$snapshot);if(count($profiles)!==2)return null;
    $totals=db()->prepare('SELECT profile_id,COUNT(*) AS vote_count FROM coules_votes WHERE poll_key=? AND profile_id IN (?,?) GROUP BY profile_id');
    $totals->execute([$pollKey,$ids[0],$ids[1]]);$counts=array_fill_keys($ids,0);
    foreach($totals->fetchAll() as $row)$counts[(string)$row['profile_id']]=(int)$row['vote_count'];
    $total=array_sum($counts);$a=$profiles[$ids[0]];$b=$profiles[$ids[1]];$id=bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO p50_duel_vote_history(
        id,user_id,poll_key,candidate_a_id,candidate_b_id,candidate_a_name,candidate_b_name,candidate_a_photo,candidate_b_photo,
        selected_profile_id,candidate_a_percentage,candidate_b_percentage,total_votes,candidate_a_rank,candidate_b_rank,
        candidate_a_score,candidate_b_score,state_revision,state_updated_at
    ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        $id,$userId,$pollKey,$ids[0],$ids[1],$a['name'],$b['name'],$a['photoUrl']?:null,$b['photoUrl']?:null,$selectedId,
        $total>0?(int)round($counts[$ids[0]]*100/$total):null,$total>0?(int)round($counts[$ids[1]]*100/$total):null,$total,
        $a['rank'],$b['rank'],$a['score'],$b['score'],$snapshot['state']['stateRevision']??null,$snapshot['updatedAt'],
    ]);
    return $id;
}

function p50_duel_history_for_share(string $userId,string $pollKey,string $selectedId): ?array {
    $stmt=db()->prepare('SELECT * FROM p50_duel_vote_history WHERE user_id=? AND poll_key=? AND selected_profile_id=? ORDER BY voted_at DESC LIMIT 1');
    $stmt->execute([$userId,$pollKey,$selectedId]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}
