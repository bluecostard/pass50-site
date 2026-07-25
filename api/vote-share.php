<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');
$user=auth_user();

function p50_share_ensure_schema(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS p50_vote_share_sessions (
        id CHAR(64) CHARACTER SET ascii PRIMARY KEY,
        user_id CHAR(36) NOT NULL,
        poll_key VARCHAR(190) NOT NULL,
        profile_id VARCHAR(100) NOT NULL,
        vote_updated_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vote_share_user_date(user_id,created_at),
        INDEX idx_vote_share_expiry(expires_at),
        CONSTRAINT fk_vote_share_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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

function p50_share_public_state(): array {
    $raw=db()->query("SELECT data FROM app_state WHERE id='public' LIMIT 1")->fetchColumn();
    $state=is_string($raw)?json_decode($raw,true):[];
    return is_array($state)?$state:[];
}

function p50_share_profile_payload(string $profileId,string $voteDate): array {
    global $config;
    $state=p50_share_public_state();$profiles=(array)($state['profiles']??[]);
    $profile=null;
    foreach($profiles as $candidate)if(is_array($candidate)&&(string)($candidate['id']??'')===$profileId){$profile=$candidate;break;}
    if(!$profile)json_response(['error'=>'Profil public introuvable.'],404);
    $ranked=array_values(array_filter($profiles,static fn($p)=>is_array($p)&&!empty($p['alive'])&&!empty($p['eligible'])&&($p['classable']??true)!==false));
    usort($ranked,static fn($a,$b)=>((float)($b['scores']['2H']??$b['score']??0))<=>((float)($a['scores']['2H']??$a['score']??0)));
    $rank=0;foreach($ranked as $index=>$candidate)if((string)($candidate['id']??'')===$profileId){$rank=$index+1;break;}
    $score=max(0,min(100,(int)round((float)($profile['scores']['2H']??$profile['score']??0))));
    $photo=($profile['photoStatus']??'')==='validated'?(string)($profile['photoUrl']??$profile['photoCandidateUrl']??''):'';
    $base=rtrim((string)$config['app']['base_url'],'/');
    $campaign=$base.'/?'.http_build_query(['profile'=>$profileId,'source'=>'vote_share','medium'=>'social']);
    return [
        'profileId'=>$profileId,'name'=>(string)($profile['name']??$profileId),
        'initials'=>(string)($profile['initials']??''),'photoUrl'=>$photo,
        'rank'=>$rank,'score'=>$score,'voteDate'=>gmdate('c',strtotime($voteDate)),
        'pass50Url'=>$base,'campaignUrl'=>$campaign,
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
    db()->prepare('INSERT INTO p50_vote_share_sessions(id,user_id,poll_key,profile_id,vote_updated_at,expires_at) VALUES(?,?,?,?,?,?)')
        ->execute([$id,$user['id'],$poll,$profile,$row['updated_at'],$expires]);
    $payload=p50_share_profile_payload($profile,(string)$row['updated_at']);
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
