<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';

function p50_claim_ensure_schema(): void {
    static $done=false;if($done)return;$done=true;
    db()->exec("CREATE TABLE IF NOT EXISTS p50_profile_claims (
      id CHAR(36) PRIMARY KEY,user_id CHAR(36) NOT NULL,profile_id VARCHAR(100) NOT NULL,
      platform VARCHAR(32) NOT NULL,network_account_id VARCHAR(191) NOT NULL DEFAULT '',
      network_username VARCHAR(255) NOT NULL DEFAULT '',network_profile_url TEXT NULL,
      expected_url TEXT NULL,match_status VARCHAR(32) NOT NULL DEFAULT 'unverified',
      match_reason VARCHAR(255) NOT NULL DEFAULT '',status VARCHAR(32) NOT NULL DEFAULT 'pending',
      evidence_json LONGTEXT NULL,review_note TEXT NULL,reviewed_by CHAR(36) NULL,
      submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,reviewed_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_p50_claim_user_profile (user_id,profile_id),
      INDEX idx_p50_claim_queue (status,submitted_at),INDEX idx_p50_claim_profile (profile_id,status),
      CONSTRAINT fk_p50_claim_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS p50_profile_claim_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,claim_id CHAR(36) NOT NULL,
      actor_user_id CHAR(36) NULL,event_type VARCHAR(40) NOT NULL,event_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_p50_claim_event (claim_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function p50_claim_event(string $claimId,?string $actor,string $type,array $data=[]): void {
    $stmt=db()->prepare('INSERT INTO p50_profile_claim_events(claim_id,actor_user_id,event_type,event_json) VALUES(?,?,?,?)');
    $stmt->execute([$claimId,$actor,$type,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}

function p50_claim_profile(string $profileId): ?array {
    $state=p50_de_load_public_state();
    foreach((array)($state['profiles']??[]) as $profile)if(is_array($profile)&&hash_equals((string)($profile['id']??''),$profileId))return $profile;
    return null;
}

function p50_claim_platform(array $profile,string $wanted): array {
    foreach((array)($profile['links']??[]) as $platform=>$url)if(strcasecmp((string)$platform,$wanted)===0)return [(string)$platform,trim((string)$url)];
    return [$wanted,''];
}

function p50_claim_token(string $value): string {
    $value=rawurldecode(strtolower(trim($value)));
    $value=preg_replace('/^@/','',$value)??$value;
    return preg_replace('/[^a-z0-9._-]+/','',$value)??'';
}

function p50_claim_url_identity(string $platform,string $url): array {
    $parts=parse_url(trim($url));$path=trim((string)($parts['path']??''),'/');$query=[];
    parse_str((string)($parts['query']??''),$query);$segments=array_values(array_filter(explode('/',$path),'strlen'));
    $p=strtolower($platform);$id='';$username='';
    if($p==='facebook'&&!empty($query['id']))$id=p50_claim_token((string)$query['id']);
    if($p==='youtube'&&count($segments)>1&&strtolower($segments[0])==='channel')$id=p50_claim_token($segments[1]);
    if($segments){
        $candidate=end($segments);
        if(in_array(strtolower((string)$candidate),['videos','posts','reels','featured'],true)&&count($segments)>1)$candidate=$segments[count($segments)-2];
        $username=p50_claim_token((string)$candidate);
    }
    return ['id'=>$id,'username'=>$username,'host'=>strtolower((string)($parts['host']??''))];
}

function p50_claim_candidates(string $userId,string $platform): array {
    $p=strtolower($platform);$rows=[];
    if($p==='tiktok'){
        $stmt=db()->prepare("SELECT open_id account_id,username,display_name account_name,profile_deep_link profile_url,connected_at FROM p50_tiktok_oauth_connections WHERE user_id=? AND status IN ('active','reauthorization_required')");
        try{$stmt->execute([$userId]);$rows=$stmt->fetchAll();}catch(Throwable){}
    }elseif($p==='youtube'){
        $stmt=db()->prepare("SELECT channel_id account_id,channel_custom_url username,channel_title account_name,CONCAT('https://www.youtube.com/channel/',channel_id) profile_url,connected_at FROM p50_youtube_oauth_connections WHERE user_id=? AND status IN ('active','reauthorization_required')");
        try{$stmt->execute([$userId]);$rows=$stmt->fetchAll();}catch(Throwable){}
    }elseif(in_array($p,['facebook','instagram'],true)){
        $stmt=db()->prepare("SELECT asset_id account_id,username,asset_name account_name,profile_url,connected_at FROM p50_meta_oauth_assets WHERE user_id=? AND LOWER(platform)=? AND status='active'");
        try{$stmt->execute([$userId,$p]);$rows=$stmt->fetchAll();}catch(Throwable){}
    }
    return array_values(array_filter($rows,'is_array'));
}

function p50_claim_compare(string $platform,string $expected,array $candidate): array {
    $want=p50_claim_url_identity($platform,$expected);
    $gotUrl=p50_claim_url_identity($platform,(string)($candidate['profile_url']??''));
    $gotId=p50_claim_token((string)($candidate['account_id']??''));
    $gotUser=p50_claim_token((string)($candidate['username']??''));
    $exact=false;$reason='Le compte connecté ne correspond pas au lien officiel de la fiche.';
    if($want['id']!==''&&($want['id']===$gotId||$want['id']===$gotUrl['id'])){$exact=true;$reason='Identifiant technique identique au compte officiel.';}
    elseif($want['username']!==''&&in_array($want['username'],array_filter([$gotUser,$gotUrl['username']]),true)){$exact=true;$reason='Identifiant public identique au compte officiel.';}
    return ['exact'=>$exact,'reason'=>$reason,'expectedIdentity'=>$want,'connectedIdentity'=>['id'=>$gotId,'username'=>$gotUser,'urlIdentity'=>$gotUrl]];
}

function p50_claim_public_status(string $profileId): array {
    $stmt=db()->prepare("SELECT platform,reviewed_at FROM p50_profile_claims WHERE profile_id=? AND status='approved' ORDER BY reviewed_at DESC LIMIT 1");
    $stmt->execute([$profileId]);$row=$stmt->fetch();
    return $row?['claimed'=>true,'platform'=>(string)$row['platform'],'verifiedAt'=>(string)$row['reviewed_at'].'Z']:['claimed'=>false];
}

p50_claim_ensure_schema();

if($_SERVER['REQUEST_METHOD']==='GET'){
    $profileId=trim((string)($_GET['profileId']??''));
    if($profileId!==''&&empty($_GET['admin'])&&empty($_GET['mine']))json_response(['ok'=>true]+p50_claim_public_status($profileId));
    $user=auth_user();
    if(!empty($_GET['admin'])){
        require_role($user,'owner','admin');
        $status=trim((string)($_GET['status']??''));
        $sql="SELECT c.*,u.email,u.display_name,p.display_name reviewer_name FROM p50_profile_claims c JOIN users u ON u.id=c.user_id LEFT JOIN users p ON p.id=c.reviewed_by";
        $args=[];if($status!==''){$sql.=' WHERE c.status=?';$args[]=$status;}$sql.=' ORDER BY c.submitted_at DESC LIMIT 500';
        $stmt=db()->prepare($sql);$stmt->execute($args);$claims=$stmt->fetchAll();
    }else{
        $stmt=db()->prepare('SELECT * FROM p50_profile_claims WHERE user_id=? ORDER BY submitted_at DESC');
        $stmt->execute([(string)$user['id']]);$claims=$stmt->fetchAll();
    }
    foreach($claims as &$claim){$claim['evidence']=decode_json_column($claim['evidence_json']??null,[]);unset($claim['evidence_json']);}
    json_response(['ok'=>true,'claims'=>$claims]);
}

require_method('POST');$user=auth_user();$in=json_input();$action=(string)($in['action']??'submit');
if($action==='review'){
    require_role($user,'owner','admin');$claimId=trim((string)($in['claimId']??''));$decision=(string)($in['decision']??'');
    if($claimId===''||!in_array($decision,['approve','reject'],true))json_response(['error'=>'Décision invalide.'],422);
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT * FROM p50_profile_claims WHERE id=? FOR UPDATE');$stmt->execute([$claimId]);$claim=$stmt->fetch();
        if(!$claim){$pdo->rollBack();json_response(['error'=>'Revendication introuvable.'],404);}
        if($claim['status']!=='pending'){$pdo->rollBack();json_response(['error'=>'Cette demande a déjà été traitée.'],409);}
        $note=trim((string)($in['note']??''));$status=$decision==='approve'?'approved':'rejected';
        if($decision==='approve'&&$claim['match_status']!=='exact'&&$note===''){$pdo->rollBack();json_response(['error'=>'Une justification est obligatoire si les identifiants diffèrent.'],422);}
        $stmt=$pdo->prepare('UPDATE p50_profile_claims SET status=?,review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?');
        $stmt->execute([$status,$note,(string)$user['id'],$claimId]);
        if($decision==='approve'){
            $stmt=$pdo->prepare("UPDATE p50_profile_claims SET status='rejected',review_note='Une autre revendication a été approuvée.',reviewed_by=?,reviewed_at=NOW() WHERE profile_id=? AND id<>? AND status='pending'");
            $stmt->execute([(string)$user['id'],$claim['profile_id'],$claimId]);
        }
        p50_claim_event($claimId,(string)$user['id'],'reviewed',['decision'=>$decision,'note'=>$note,'matchStatus'=>$claim['match_status']]);
        $pdo->commit();
        if($decision==='approve'&&!empty($claim['expected_url'])){
            p50_de_ensure_schema();p50_de_sync_registry_from_state();
            $validation=p50_de_validate_social_url((string)$claim['platform'],(string)$claim['expected_url'],'','');
            p50_de_add_social_evidence((string)$claim['profile_id'],(string)$claim['platform'],(string)$claim['expected_url'],'manual_owner','Revendication OAuth PASS50',(string)$claim['network_account_id'],100,$validation);
            p50_de_publish_profile((string)$claim['profile_id'],(string)$user['id']);
        }
        json_response(['ok'=>true,'status'=>$status]);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

if($action!=='submit')json_response(['error'=>'Action inconnue.'],422);
$profileId=trim((string)($in['profileId']??''));$requested=trim((string)($in['platform']??''));
if($profileId===''||$requested==='')json_response(['error'=>'Fiche et réseau requis.'],422);
$profile=p50_claim_profile($profileId);if(!$profile)json_response(['error'=>'Fiche introuvable.'],404);
[$platform,$expected]=p50_claim_platform($profile,$requested);
if($expected==='')json_response(['error'=>'Aucun compte officiel de ce réseau n’est enregistré sur cette fiche.'],422);
$candidates=p50_claim_candidates((string)$user['id'],$platform);
if(!$candidates)json_response(['error'=>'Connectez d’abord ce réseau à votre compte PASS50.','code'=>'connection_required'],422);
$best=null;$bestCompare=null;
foreach($candidates as $candidate){$comparison=p50_claim_compare($platform,$expected,$candidate);if($best===null||$comparison['exact']){$best=$candidate;$bestCompare=$comparison;}if($comparison['exact'])break;}
$claimId=uuid_v4();$existing=db()->prepare('SELECT id,status FROM p50_profile_claims WHERE user_id=? AND profile_id=?');
$existing->execute([(string)$user['id'],$profileId]);$old=$existing->fetch();if($old)$claimId=(string)$old['id'];
$evidence=['provider'=>'oauth','platform'=>$platform,'connectedAt'=>$best['connected_at']??null,'comparison'=>$bestCompare];
$stmt=db()->prepare("INSERT INTO p50_profile_claims(id,user_id,profile_id,platform,network_account_id,network_username,network_profile_url,expected_url,match_status,match_reason,status,evidence_json,submitted_at,reviewed_by,reviewed_at,review_note)
 VALUES(?,?,?,?,?,?,?,?,?,?, 'pending',?,NOW(),NULL,NULL,NULL)
 ON DUPLICATE KEY UPDATE platform=VALUES(platform),network_account_id=VALUES(network_account_id),network_username=VALUES(network_username),network_profile_url=VALUES(network_profile_url),expected_url=VALUES(expected_url),match_status=VALUES(match_status),match_reason=VALUES(match_reason),status='pending',evidence_json=VALUES(evidence_json),submitted_at=NOW(),reviewed_by=NULL,reviewed_at=NULL,review_note=NULL");
$stmt->execute([$claimId,(string)$user['id'],$profileId,$platform,(string)($best['account_id']??''),(string)($best['username']??''),(string)($best['profile_url']??''),$expected,!empty($bestCompare['exact'])?'exact':'mismatch',(string)$bestCompare['reason'],json_encode($evidence,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
p50_claim_event($claimId,(string)$user['id'],'submitted',['platform'=>$platform,'matchStatus'=>!empty($bestCompare['exact'])?'exact':'mismatch']);
json_response(['ok'=>true,'claimId'=>$claimId,'status'=>'pending','matchStatus'=>!empty($bestCompare['exact'])?'exact':'mismatch','message'=>!empty($bestCompare['exact'])?'Compte officiel reconnu. La demande attend la validation PASS50.':'Demande enregistrée pour contrôle manuel.']);
