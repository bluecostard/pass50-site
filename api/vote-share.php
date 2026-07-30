<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/duel-history-core.php';
require_method('POST');
$user=auth_user();

function p50_share_ensure_schema(): void {
    p50_duel_history_ensure_schema();
    db()->exec("CREATE TABLE IF NOT EXISTS p50_vote_share_sessions (
        id CHAR(64) CHARACTER SET ascii PRIMARY KEY,
        user_id CHAR(36) NOT NULL,
        poll_key VARCHAR(190) NOT NULL,
        profile_id VARCHAR(100) NOT NULL,
        history_id CHAR(64) CHARACTER SET ascii NULL,
        vote_updated_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vote_share_user_date(user_id,created_at),
        INDEX idx_vote_share_expiry(expires_at),
        INDEX idx_vote_share_history(history_id),
        CONSTRAINT fk_vote_share_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $column=db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='p50_vote_share_sessions' AND COLUMN_NAME='history_id'")->fetchColumn();
    if((int)$column===0)db()->exec("ALTER TABLE p50_vote_share_sessions ADD COLUMN history_id CHAR(64) CHARACTER SET ascii NULL AFTER profile_id, ADD INDEX idx_vote_share_history(history_id)");
    $historyIndex=db()->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='p50_vote_share_sessions' AND INDEX_NAME='idx_vote_share_history'")->fetchColumn();
    if((int)$historyIndex===0)db()->exec("CREATE INDEX idx_vote_share_history ON p50_vote_share_sessions(history_id)");
    db()->exec("CREATE TABLE IF NOT EXISTS p50_vote_share_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        share_id CHAR(64) CHARACTER SET ascii NOT NULL,
        event_name VARCHAR(40) NOT NULL,
        platform VARCHAR(30) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vote_share_event_date(event_name,created_at),
        INDEX idx_vote_share_session(share_id),
        CONSTRAINT fk_vote_share_session FOREIGN KEY(share_id) REFERENCES p50_vote_share_sessions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function p50_share_initials(string $name): string {
    preg_match_all('/[\pL\pN]+/u',$name,$parts);
    return strtoupper(substr(implode('',array_map(static fn($part)=>substr($part,0,1),array_slice($parts[0]??[],0,2))),0,2));
}

function p50_share_duel_payload(string $pollKey,string $selectedId,string $voteDate,?array $history): array {
    global $config;
    if($history){
        $candidates=[];
        foreach(['a','b'] as $side){
            $id=(string)$history['candidate_'.$side.'_id'];$name=(string)$history['candidate_'.$side.'_name'];
            $candidate=['profileId'=>$id,'name'=>$name,'initials'=>p50_share_initials($name),'photoUrl'=>(string)($history['candidate_'.$side.'_photo']??''),'selected'=>$id===$selectedId,'rank'=>$history['candidate_'.$side.'_rank']!==null?(int)$history['candidate_'.$side.'_rank']:null,'score'=>$history['candidate_'.$side.'_score']!==null?(float)$history['candidate_'.$side.'_score']:null];
            if($history['candidate_'.$side.'_percentage']!==null)$candidate['percentage']=(int)$history['candidate_'.$side.'_percentage'];
            $candidates[]=$candidate;
        }
        $percentagesAvailable=$history['candidate_a_percentage']!==null&&$history['candidate_b_percentage']!==null;
        $voteDate=(string)$history['voted_at'];$snapshotSource='frozen_history';$stateRevision=$history['state_revision']!==null?(int)$history['state_revision']:null;
    }else{
        $ids=p50_duel_candidate_ids($pollKey);if(!$ids||!in_array($selectedId,$ids,true))json_response(['error'=>'Duel du vote invalide.'],422);
        $snapshot=p50_duel_state_snapshot();$profiles=p50_duel_public_candidates($ids,$snapshot);if(count($profiles)!==2)json_response(['error'=>'Candidats publics du duel introuvables.'],404);
        $candidates=[];foreach($ids as $id)$candidates[]=$profiles[$id]+['selected'=>$id===$selectedId];
        $percentagesAvailable=false;$snapshotSource='current_fallback';$stateRevision=$snapshot['state']['stateRevision']??null;
    }
    $base=rtrim((string)$config['app']['base_url'],'/');
    $campaign=$base.'/partage.php?'.http_build_query(['type'=>'coules','id'=>$selectedId,'choice'=>$selectedId]);
    $campaignAudio=$base.'/partage.php?'.http_build_query(['type'=>'coules-audio','id'=>$selectedId,'choice'=>$selectedId]);
    return [
        'profileId'=>$selectedId,'selectedProfileId'=>$selectedId,'candidates'=>$candidates,
        'percentagesAvailable'=>$percentagesAvailable,'voteDate'=>gmdate('c',strtotime($voteDate)),
        'snapshotSource'=>$snapshotSource,'stateRevision'=>$stateRevision,
        'fallbackReason'=>$history?null:'Historique absent : profils actuels affichés sans résultat.',
        'pass50Url'=>$base,'campaignUrl'=>$campaign,'campaignAudioUrl'=>$campaignAudio,
    ];
}

p50_share_ensure_schema();
$input=json_input();$action=(string)($input['action']??'prepare');
if($action==='prepare'){
    $poll=trim((string)($input['pollKey']??''));$profile=trim((string)($input['profileId']??''));
    if(!preg_match('/^[A-Za-z0-9._:-]{1,190}$/',$poll)||!preg_match('/^[A-Za-z0-9._:-]{1,100}$/',$profile))json_response(['error'=>'Vote invalide.'],422);
    $vote=db()->prepare('SELECT profile_id,updated_at FROM coules_votes WHERE poll_key=? AND user_id=? LIMIT 1');
    $vote->execute([$poll,$user['id']]);$row=$vote->fetch();
    if(!$row||(string)$row['profile_id']!==$profile)json_response(['error'=>'Aucun vote correspondant ne peut être partagé.'],403);
    $rate=db()->prepare('SELECT COUNT(*) FROM p50_vote_share_sessions WHERE user_id=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)');
    $rate->execute([$user['id']]);if((int)$rate->fetchColumn()>=10)json_response(['error'=>'Limite de génération atteinte. Réessayez plus tard.'],429);
    $id=bin2hex(random_bytes(32));$expires=gmdate('Y-m-d H:i:s',time()+3600);
    $history=p50_duel_history_for_share((string)$user['id'],$poll,$profile);
    db()->prepare('INSERT INTO p50_vote_share_sessions(id,user_id,poll_key,profile_id,history_id,vote_updated_at,expires_at) VALUES(?,?,?,?,?,?,?)')
        ->execute([$id,$user['id'],$poll,$profile,$history['id']??null,$row['updated_at'],$expires]);
    $payload=p50_share_duel_payload($poll,$profile,(string)$row['updated_at'],$history);
    json_response(['ok'=>true,'shareId'=>$id,'expiresAt'=>gmdate('c',strtotime($expires)),'card'=>$payload]);
}
if($action==='analytics'){
    $shareId=trim((string)($input['shareId']??''));$event=(string)($input['event']??'');$platform=trim((string)($input['platform']??''));
    $allowed=['share_opened','card_generated','audio_recorded','native_share_triggered','download','link_copied','platform_selected'];
    if(!preg_match('/^[a-f0-9]{64}$/',$shareId)||!in_array($event,$allowed,true))json_response(['error'=>'Événement invalide.'],422);
    $session=db()->prepare('SELECT COUNT(*) FROM p50_vote_share_sessions WHERE id=? AND user_id=? AND expires_at>UTC_TIMESTAMP()');
    $session->execute([$shareId,$user['id']]);if((int)$session->fetchColumn()!==1)json_response(['error'=>'Session de partage expirée.'],403);
    $eventRate=db()->prepare('SELECT COUNT(*) FROM p50_vote_share_events WHERE share_id=?');
    $eventRate->execute([$shareId]);if((int)$eventRate->fetchColumn()>=100)json_response(['error'=>'Limite analytique atteinte.'],429);
    $platform=$event==='platform_selected'?substr(preg_replace('/[^A-Za-z0-9._-]/','',$platform)??'',0,30):'';
    db()->prepare('INSERT INTO p50_vote_share_events(share_id,event_name,platform) VALUES(?,?,?)')->execute([$shareId,$event,$platform?:null]);
    json_response(['ok'=>true],201);
}
json_response(['error'=>'Action inconnue.'],422);
