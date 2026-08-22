<?php
declare(strict_types=1);

$dsn=getenv('P50_TEST_DSN');
if(!$dsn){fwrite(STDERR,"P50_TEST_DSN absent\n");exit(77);}
$pdo=new PDO($dsn,getenv('P50_TEST_DB_USER')?:'root',getenv('P50_TEST_DB_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO {global $pdo;return $pdo;}
function p50_de_ensure_schema(): void {}
function p50_de_threshold(): int {return 90;}
function p50_platform(string $url): string {return str_contains($url,'tiktok.com')?'TikTok':(str_contains($url,'youtube.com')?'YouTube':'');}
function p50_public_http_url(string $url): bool {return str_starts_with($url,'https://');}
function p50_page_metadata(string $html,string $url): array {return ['title'=>'','image'=>'','canonical'=>$url];}
require dirname(__DIR__).'/api/live-radar-v4-core.php';
function must(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}

$pdo->exec('DROP TABLE IF EXISTS p50_live_dismissals');
$pdo->exec('DROP TABLE IF EXISTS p50_live_source_health');
$pdo->exec('DROP TABLE IF EXISTS p50_live_streams');
p50_live_v4_ensure_schema();
p50_live_v4_ensure_dismissals();

        $live=['profileId'=>'tiktok-test','platform'=>'TikTok','title'=>'Test est en direct','url'=>'https://www.tiktok.com/@test/live','thumbnail'=>'','confidence'=>99,'startedAt'=>null,'viewers'=>42,'metadata'=>['roomId'=>'741234567890','strictApiLabels'=>['api_webcast']]];
p50_live_v4_store_live($live);
$pdo->prepare("INSERT INTO p50_live_source_health(profile_id,platform,url_hash,official_url,last_state,last_checked_at,last_live_at,metadata) VALUES(?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),'{}')")->execute(['tiktok-test','TikTok',hash('sha256','x'),'https://www.tiktok.com/@test','live']);
$active=p50_live_v4_active_rows();
must(count($active)===1,'Une confirmation live récente doit publier le flux.');

$pdo->prepare("UPDATE p50_live_source_health SET last_state='unknown',last_checked_at=UTC_TIMESTAMP() WHERE profile_id=? AND platform=?")->execute(['tiktok-test','TikTok']);
$active=p50_live_v4_active_rows();
must(count($active)===1,'Trust Gate : un unknown IONOS ne retire pas un LIVE TikTok détecté.');
$status=$pdo->query("SELECT status FROM p50_live_streams WHERE profile_id='tiktok-test'")->fetchColumn();
must($status==='live','Le flux reste live en base.');

p50_live_v4_store_live($live);
$pdo->prepare("UPDATE p50_live_source_health SET last_state='live',last_checked_at=UTC_TIMESTAMP(),last_live_at=UTC_TIMESTAMP() WHERE profile_id=? AND platform=?")->execute(['tiktok-test','TikTok']);
p50_live_v4_health_update(
    ['profile_id'=>'tiktok-test','platform'=>'TikTok','url'=>'https://www.tiktok.com/@test'],
    ['state'=>'unknown','error'=>'tiktok_embed_uninformative','confidence'=>0,'responseMs'=>12,'probes'=>[],'evidence'=>[]]
);
$kept=$pdo->query("SELECT last_state FROM p50_live_source_health WHERE profile_id='tiktok-test' AND platform='TikTok'")->fetchColumn();
must($kept==='live','Un unknown IONOS après un LIVE frais conserve last_state=live.');
$active=p50_live_v4_active_rows();
must(count($active)===1,'Le LIVE public reste visible pendant la fenêtre si la sonde est unknown.');

$pdo->prepare("UPDATE p50_live_source_health SET last_state='unknown',last_checked_at=UTC_TIMESTAMP() WHERE profile_id=? AND platform=?")->execute(['tiktok-test','TikTok']);
$active=p50_live_v4_active_rows();
must(count($active)===1,'Un unknown IONOS laisse le LIVE TikTok public.');
$status=$pdo->query("SELECT status FROM p50_live_streams WHERE profile_id='tiktok-test'")->fetchColumn();
must($status==='live','Le flux TikTok unknown reste live, pas unconfirmé.');

p50_live_v4_store_live($live);
$pdo->prepare("UPDATE p50_live_source_health SET last_state='live',last_checked_at=UTC_TIMESTAMP(),last_live_at=UTC_TIMESTAMP() WHERE profile_id=? AND platform=?")->execute(['tiktok-test','TikTok']);
$active=p50_live_v4_active_rows();
must(count($active)===1,'Une nouvelle confirmation live peut republier le flux.');
$pdo->exec("UPDATE p50_live_streams SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE) WHERE profile_id='tiktok-test'");
$pdo->exec("UPDATE p50_live_source_health SET last_checked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE) WHERE profile_id='tiktok-test'");
$active=p50_live_v4_active_rows();
must(count($active)===1,'TikTok reste public 2 minutes après confirmation.');
$pdo->exec("UPDATE p50_live_streams SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 13 MINUTE) WHERE profile_id='tiktok-test'");
$pdo->exec("UPDATE p50_live_source_health SET last_checked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 13 MINUTE),last_state='live' WHERE profile_id='tiktok-test'");
$active=p50_live_v4_active_rows();
must(count($active)===1,'TikTok reste public 13 minutes après confirmation.');
$pdo->exec("UPDATE p50_live_streams SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 31 MINUTE) WHERE profile_id='tiktok-test'");
$pdo->exec("UPDATE p50_live_source_health SET last_checked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 31 MINUTE),last_state='live' WHERE profile_id='tiktok-test'");
$active=p50_live_v4_active_rows();
must(count($active)===1,'TikTok reste public 31 minutes après confirmation — pas de limite de temps.');

p50_live_v4_store_live($live);
$pdo->prepare("UPDATE p50_live_source_health SET last_state='live',last_checked_at=UTC_TIMESTAMP(),last_live_at=UTC_TIMESTAMP() WHERE profile_id=? AND platform=?")->execute(['tiktok-test','TikTok']);
p50_live_v4_mark_ended('tiktok-test','TikTok','tiktok_no_live_signal');
$status=$pdo->query("SELECT status FROM p50_live_streams WHERE profile_id='tiktok-test'")->fetchColumn();
must($status==='live','Un HTML IONOS sans JSON ne clôture pas un LIVE TikTok GitHub.');
$pdo->exec("UPDATE p50_live_streams SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 41 MINUTE) WHERE profile_id='tiktok-test'");
$active=p50_live_v4_active_rows();
must(count($active)===1,'TikTok reste public après 41 min — la grâce d’âge ne s’applique plus.');
$pdo->exec("UPDATE p50_live_streams SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR) WHERE profile_id='tiktok-test'");
$active=p50_live_v4_active_rows();
must(count($active)===1,'TikTok reste public 2 heures après détection.');
$pdo->exec("UPDATE p50_live_streams SET status='unconfirmed',metadata=JSON_SET(COALESCE(metadata,'{}'),'$.withdrawalReason','confirmation_grace_expired') WHERE profile_id='tiktok-test'");
$active=p50_live_v4_active_rows();
must(count($active)===1,'Un live retiré seulement pour âge est republié.');

p50_live_v4_store_live($live);
p50_live_v4_mark_ended('tiktok-test','TikTok','replay',['url'=>'https://example.test/replay']);
$row=$pdo->query("SELECT status,metadata FROM p50_live_streams WHERE profile_id='tiktok-test'")->fetch();
must($row['status']==='ended','Une preuve de fin clôt le direct.');
$metadata=json_decode((string)$row['metadata'],true);
must(($metadata['endReason']??'')==='replay','Le motif de fin est conservé.');

        $false=['profileId'=>'youtube-false','platform'=>'YouTube','title'=>'Image figée','url'=>'https://www.youtube.com/watch?v=falseLive123','thumbnail'=>'','confidence'=>99,'startedAt'=>null,'viewers'=>null,'metadata'=>['videoId'=>'falseLive123','liveSignal'=>'isLiveNow']];
p50_live_v4_store_live($false);
$pdo->prepare("INSERT INTO p50_live_source_health(profile_id,platform,url_hash,official_url,last_state,last_checked_at,last_live_at,metadata) VALUES(?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),'{}')")->execute(['youtube-false','YouTube',hash('sha256','y'),'https://www.youtube.com/@false','live']);
$key=p50_live_v4_stream_key($false);
$pdo->prepare("INSERT INTO p50_live_dismissals(stream_key,profile_id,platform,url_hash,dismissed_by,reason,dismissed_at) VALUES(?,?,?,?,?,'false_positive',UTC_TIMESTAMP())")->execute([$key,'youtube-false','YouTube',hash('sha256',strtolower(rtrim($false['url'],'/'))),'owner-test']);
p50_live_v4_store_live($false);
$active=p50_live_v4_active_rows();
must(!array_filter($active,static fn($item)=>(string)$item['profileId']==='youtube-false'),'Le même faux direct supprimé ne doit jamais revenir.');
$status=$pdo->query("SELECT status FROM p50_live_streams WHERE profile_id='youtube-false'")->fetchColumn();
must($status==='ended','Le faux direct supprimé est clôturé en base.');

        $future=$false;$future['url']='https://www.youtube.com/watch?v=realFuture456';$future['metadata']=['videoId'=>'realFuture456','liveSignal'=>'isLiveNow'];p50_live_v4_store_live($future);
$active=p50_live_v4_active_rows();
must((bool)array_filter($active,static fn($item)=>(string)$item['profileId']==='youtube-false'),'Un futur live avec une autre URL reste détectable.');

        $isouch=['profileId'=>'census-isouch','platform'=>'TikTok','title'=>'Isouch est en direct','url'=>'https://www.tiktok.com/@prince_du_pays/live','thumbnail'=>'','confidence'=>99,'startedAt'=>null,'viewers'=>42,'metadata'=>['roomId'=>'7676641654631107360','strictApiLabels'=>['api_webcast']]];
        $isouchKey=p50_live_v4_stream_key($isouch);
        $isouchProfileKey=p50_live_v4_profile_dismiss_key('census-isouch','TikTok');
        p50_live_v4_store_live($isouch);
        $pdo->prepare("INSERT INTO p50_live_source_health(profile_id,platform,url_hash,official_url,last_state,last_checked_at,last_live_at,metadata) VALUES(?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),'{}')")->execute(['census-isouch','TikTok',hash('sha256','z'),'https://www.tiktok.com/@prince_du_pays','live']);
        $pdo->prepare("INSERT INTO p50_live_dismissals(stream_key,profile_id,platform,url_hash,dismissed_by,reason,dismissed_at) VALUES(?,?,?,?,?,'false_positive',UTC_TIMESTAMP())")->execute([$isouchProfileKey,'census-isouch','TikTok',hash('sha256',strtolower(rtrim($isouch['url'],'/'))),'owner-test']);
        $isouch['metadata']['roomId']='9999999999999999999';
        $isouch['url']='https://www.tiktok.com/@prince_du_pays/live?room_id=9999999999999999999';
        p50_live_v4_store_live($isouch);
        $active=p50_live_v4_active_rows();
        must(!array_filter($active,static fn($item)=>(string)$item['profileId']==='census-isouch'),'Isouch supprimé ne revient pas avec un nouveau roomId TikTok.');
        $status=$pdo->query("SELECT status FROM p50_live_streams WHERE profile_id='census-isouch' ORDER BY last_seen_at DESC LIMIT 1")->fetchColumn();
        must($status==='ended','Le faux direct Isouch est clôturé en base.');

echo json_encode(['ok'=>true,'trustGate'=>true,'unknownKeepsTikTok'=>true,'detectedStays'=>true,'dismissalPersistent'=>true,'futureLiveAllowed'=>true,'profileSuppressBlocksRotatingRoomId'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL;
