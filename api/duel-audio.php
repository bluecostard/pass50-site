<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/duel-history-core.php';

const P50_DUEL_AUDIO_VERSION='DUEL-AUDIO-V1.1';
const P50_DUEL_AUDIO_MAX_BYTES=3145728;
const P50_DUEL_AUDIO_MAX_DURATION_MS=15000;
const P50_DUEL_AUDIO_RETENTION_DAYS=30;

function p50_duel_audio_ensure_schema(): void {
    p50_duel_history_ensure_schema();
    db()->exec("CREATE TABLE IF NOT EXISTS p50_duel_audio_posts (
        id CHAR(64) CHARACTER SET ascii PRIMARY KEY,
        share_id CHAR(64) CHARACTER SET ascii NOT NULL,
        user_id CHAR(36) NOT NULL,
        poll_key VARCHAR(190) NOT NULL,
        history_id CHAR(64) CHARACTER SET ascii NULL,
        selected_profile_id VARCHAR(100) NOT NULL,
        candidate_a_id VARCHAR(100) NOT NULL,
        candidate_b_id VARCHAR(100) NOT NULL,
        candidate_a_name VARCHAR(190) NOT NULL,
        candidate_b_name VARCHAR(190) NOT NULL,
        file_name VARCHAR(190) NOT NULL,
        mime_type VARCHAR(80) NOT NULL,
        duration_ms SMALLINT UNSIGNED NOT NULL,
        bytes_size INT UNSIGNED NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'published',
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        expires_at DATETIME NOT NULL,
        UNIQUE KEY uq_p50_duel_audio_share(share_id),
        INDEX idx_p50_duel_audio_poll(poll_key,status,created_at),
        INDEX idx_p50_duel_audio_a(candidate_a_id,status,created_at),
        INDEX idx_p50_duel_audio_b(candidate_b_id,status,created_at),
        INDEX idx_p50_duel_audio_user(user_id,created_at),
        INDEX idx_p50_duel_audio_expiry(expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function p50_duel_audio_dir(): string {
    return dirname(__DIR__).'/uploads/duel-audio';
}

function p50_duel_audio_url(string $fileName): string {
    global $config;
    return rtrim((string)$config['app']['base_url'],'/').'/uploads/duel-audio/'.rawurlencode(basename($fileName));
}

function p50_duel_audio_cleanup(PDO $pdo): void {
    $stmt=$pdo->query("SELECT id,file_name FROM p50_duel_audio_posts WHERE expires_at<=UTC_TIMESTAMP() LIMIT 50");
    $rows=$stmt?$stmt->fetchAll():[];
    if(!$rows)return;
    $delete=$pdo->prepare('DELETE FROM p50_duel_audio_posts WHERE id=?');
    foreach($rows as $row){
        $path=p50_duel_audio_dir().'/'.basename((string)$row['file_name']);
        if(is_file($path))@unlink($path);
        $delete->execute([(string)$row['id']]);
    }
}

function p50_duel_audio_item(array $row): array {
    $authorPseudo=trim((string)($row['author_display_name']??''));
    if($authorPseudo==='')$authorPseudo='Membre PASS50';
    return [
        'id'=>(string)$row['id'],
        'pollKey'=>(string)$row['poll_key'],
        'selectedProfileId'=>(string)$row['selected_profile_id'],
        'candidateA'=>['profileId'=>(string)$row['candidate_a_id'],'name'=>(string)$row['candidate_a_name']],
        'candidateB'=>['profileId'=>(string)$row['candidate_b_id'],'name'=>(string)$row['candidate_b_name']],
        'audioUrl'=>p50_duel_audio_url((string)$row['file_name']),
        'durationMs'=>(int)$row['duration_ms'],
        'publishedAt'=>gmdate('c',strtotime((string)$row['created_at'].' UTC')),
        'expiresAt'=>gmdate('c',strtotime((string)$row['expires_at'].' UTC')),
        'authorPseudo'=>$authorPseudo,
    ];
}

function p50_duel_audio_candidates(array $session): array {
    if(!empty($session['history_id'])&&!empty($session['candidate_a_id'])&&!empty($session['candidate_b_id'])){
        return [
            'aId'=>(string)$session['candidate_a_id'],
            'bId'=>(string)$session['candidate_b_id'],
            'aName'=>(string)$session['candidate_a_name'],
            'bName'=>(string)$session['candidate_b_name'],
        ];
    }
    $ids=p50_duel_candidate_ids((string)$session['poll_key']);
    if(!$ids)json_response(['error'=>'Duel introuvable.'],422);
    $snapshot=p50_duel_state_snapshot();
    $profiles=p50_duel_public_candidates($ids,$snapshot);
    if(count($profiles)!==2)json_response(['error'=>'Influenceurs du duel introuvables.'],404);
    return [
        'aId'=>$ids[0],'bId'=>$ids[1],
        'aName'=>(string)$profiles[$ids[0]]['name'],
        'bName'=>(string)$profiles[$ids[1]]['name'],
    ];
}

p50_duel_audio_ensure_schema();
$pdo=db();
p50_duel_audio_cleanup($pdo);
$method=$_SERVER['REQUEST_METHOD']??'GET';

if($method==='GET'){
    $pollKey=trim((string)($_GET['pollKey']??''));
    $profileIdsRaw=trim((string)($_GET['profileIds']??''));
    $limit=max(1,min(20,(int)($_GET['limit']??12)));
    if($pollKey!==''){
        if(!preg_match('/^[A-Za-z0-9._:-]{1,100}__[A-Za-z0-9._:-]{1,100}$/',$pollKey))json_response(['error'=>'Duel invalide.'],422);
        $stmt=$pdo->prepare("SELECT p.*,u.display_name author_display_name
          FROM p50_duel_audio_posts p
          JOIN users u ON u.id=p.user_id AND u.deleted_at IS NULL
          WHERE p.poll_key=? AND p.status='published' AND p.expires_at>UTC_TIMESTAMP()
          ORDER BY p.created_at DESC LIMIT 3");
        $stmt->execute([$pollKey]);
    }else{
        $ids=array_values(array_unique(array_filter(array_map('trim',explode(',',$profileIdsRaw)),static fn($id)=>$id!==''&&preg_match('/^[A-Za-z0-9._:-]{1,100}$/',$id))));
        $ids=array_slice($ids,0,5);
        if($ids){
            $placeholders=implode(',',array_fill(0,count($ids),'?'));
            $stmt=$pdo->prepare("SELECT p.*,u.display_name author_display_name
              FROM p50_duel_audio_posts p
              JOIN users u ON u.id=p.user_id AND u.deleted_at IS NULL
              WHERE p.status='published' AND p.expires_at>UTC_TIMESTAMP()
                AND (p.candidate_a_id IN ($placeholders) OR p.candidate_b_id IN ($placeholders))
              ORDER BY p.created_at DESC LIMIT ".$limit);
            $stmt->execute(array_merge($ids,$ids));
        }else{
            // Fil communauté : tous les audios Coulés récents, sans filtre suivis.
            $stmt=$pdo->prepare("SELECT p.*,u.display_name author_display_name
              FROM p50_duel_audio_posts p
              JOIN users u ON u.id=p.user_id AND u.deleted_at IS NULL
              WHERE p.status='published' AND p.expires_at>UTC_TIMESTAMP()
              ORDER BY p.created_at DESC LIMIT ".$limit);
            $stmt->execute();
        }
    }
    $items=array_map('p50_duel_audio_item',$stmt->fetchAll());
    json_response(['ok'=>true,'version'=>P50_DUEL_AUDIO_VERSION,'items'=>$items,'rules'=>['lastPerDuel'=>3,'retentionDays'=>P50_DUEL_AUDIO_RETENTION_DAYS,'anonymousAuthor'=>false,'authorIdentity'=>'account_display_name'],'generatedAt'=>gmdate('c')]);
}

if($method!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$user=auth_user();
if((string)($_POST['publishConsent']??'')!=='1')json_response(['error'=>'Confirmation de publication obligatoire.'],422);
$shareId=trim((string)($_POST['shareId']??''));
if(!preg_match('/^[a-f0-9]{64}$/',$shareId))json_response(['error'=>'Session de partage invalide.'],422);
$durationMs=(int)($_POST['durationMs']??0);
if($durationMs<250||$durationMs>P50_DUEL_AUDIO_MAX_DURATION_MS)json_response(['error'=>'Durée audio invalide.'],422);
if(!isset($_FILES['audio'])||($_FILES['audio']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)json_response(['error'=>'Audio manquant.'],422);
$file=$_FILES['audio'];
if((int)$file['size']<=0||(int)$file['size']>P50_DUEL_AUDIO_MAX_BYTES)json_response(['error'=>'Audio trop volumineux.'],413);

$sessionStmt=$pdo->prepare("SELECT s.id,s.user_id,s.poll_key,s.profile_id,s.history_id,s.expires_at,
 h.candidate_a_id,h.candidate_b_id,h.candidate_a_name,h.candidate_b_name
 FROM p50_vote_share_sessions s
 LEFT JOIN p50_duel_vote_history h ON h.id=s.history_id
 WHERE s.id=? AND s.user_id=? AND s.expires_at>UTC_TIMESTAMP() LIMIT 1");
$sessionStmt->execute([$shareId,(string)$user['id']]);
$session=$sessionStmt->fetch();
if(!$session)json_response(['error'=>'Session de partage expirée.'],403);

$rate=$pdo->prepare('SELECT COUNT(*) FROM p50_duel_audio_posts WHERE user_id=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)');
$rate->execute([(string)$user['id']]);
if((int)$rate->fetchColumn()>=10)json_response(['error'=>'Limite de publications audio atteinte.'],429);

$finfo=new finfo(FILEINFO_MIME_TYPE);
$mime=(string)$finfo->file((string)$file['tmp_name']);
$extensions=[
    'audio/webm'=>'webm','video/webm'=>'webm',
    'audio/ogg'=>'ogg','application/ogg'=>'ogg',
    'audio/mp4'=>'m4a','video/mp4'=>'m4a','audio/x-m4a'=>'m4a',
];
if(!isset($extensions[$mime]))json_response(['error'=>'Format audio non autorisé.'],422);

$candidates=p50_duel_audio_candidates($session);
$selected=(string)$session['profile_id'];
if(!in_array($selected,[$candidates['aId'],$candidates['bId']],true))json_response(['error'=>'Vote du duel incohérent.'],422);

$dir=p50_duel_audio_dir();
if(!is_dir($dir)&&!mkdir($dir,0755,true))json_response(['error'=>'Dossier audio inaccessible.'],500);
$newFile=bin2hex(random_bytes(24)).'.'.$extensions[$mime];
$destination=$dir.'/'.$newFile;
if(!move_uploaded_file((string)$file['tmp_name'],$destination))json_response(['error'=>'Téléversement audio impossible.'],500);

$existingStmt=$pdo->prepare('SELECT id,file_name FROM p50_duel_audio_posts WHERE share_id=? AND user_id=? LIMIT 1');
$existingStmt->execute([$shareId,(string)$user['id']]);
$existing=$existingStmt->fetch();
$id=$existing?(string)$existing['id']:bin2hex(random_bytes(32));
$expires=gmdate('Y-m-d H:i:s',time()+P50_DUEL_AUDIO_RETENTION_DAYS*86400);
try{
    $pdo->beginTransaction();
    if($existing){
        $stmt=$pdo->prepare("UPDATE p50_duel_audio_posts SET poll_key=?,history_id=?,selected_profile_id=?,candidate_a_id=?,candidate_b_id=?,candidate_a_name=?,candidate_b_name=?,file_name=?,mime_type=?,duration_ms=?,bytes_size=?,status='published',created_at=UTC_TIMESTAMP(6),expires_at=? WHERE id=? AND user_id=?");
        $stmt->execute([(string)$session['poll_key'],$session['history_id']?:null,$selected,$candidates['aId'],$candidates['bId'],$candidates['aName'],$candidates['bName'],$newFile,$mime,$durationMs,(int)$file['size'],$expires,$id,(string)$user['id']]);
    }else{
        $stmt=$pdo->prepare("INSERT INTO p50_duel_audio_posts(id,share_id,user_id,poll_key,history_id,selected_profile_id,candidate_a_id,candidate_b_id,candidate_a_name,candidate_b_name,file_name,mime_type,duration_ms,bytes_size,status,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'published',?)");
        $stmt->execute([$id,$shareId,(string)$user['id'],(string)$session['poll_key'],$session['history_id']?:null,$selected,$candidates['aId'],$candidates['bId'],$candidates['aName'],$candidates['bName'],$newFile,$mime,$durationMs,(int)$file['size'],$expires]);
    }
    $pdo->commit();
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    @unlink($destination);
    throw $error;
}
if($existing&&!empty($existing['file_name'])&&(string)$existing['file_name']!==$newFile){
    $old=$dir.'/'.basename((string)$existing['file_name']);
    if(is_file($old))@unlink($old);
}
$rowStmt=$pdo->prepare("SELECT p.*,u.display_name author_display_name
  FROM p50_duel_audio_posts p
  JOIN users u ON u.id=p.user_id AND u.deleted_at IS NULL
  WHERE p.id=? LIMIT 1");
$rowStmt->execute([$id]);
$row=$rowStmt->fetch();
json_response(['ok'=>true,'version'=>P50_DUEL_AUDIO_VERSION,'item'=>p50_duel_audio_item($row),'message'=>'Audio publié avec votre pseudo dans le duel et Mon fil.'],201);
