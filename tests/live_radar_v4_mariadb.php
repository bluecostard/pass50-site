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

$live=['profileId'=>'tiktok-test','platform'=>'TikTok','title'=>'Test est en direct','url'=>'https://www.tiktok.com/@test/live','thumbnail'=>'','confidence'=>99,'startedAt'=>null,'viewers'=>42,'metadata'=>['roomId'=>'741234567890']];
p50_live_v4_store_live($live);
$pdo->prepare("INSERT INTO p50_live_source_health(profile_id,platform,url_hash,official_url,last_state,last_checked_at,last_live_at,metadata) VALUES(?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),'{}')")->execute(['tiktok-test','TikTok',hash('sha256','x'),'https://www.tiktok.com/@test','live']);
$active=p50_live_v4_active_rows();
must(count($active)===1,'Une confirmation live récente doit publier le flux.');

$pdo->prepare("UPDATE p50_live_source_health SET last_state='unknown',last_checked_at=UTC_TIMESTAMP() WHERE profile_id=? AND platform=?")->execute(['tiktok-test','TikTok']);
$active=p50_live_v4_active_rows();
must(count($active)===0,'Trust Gate : un unknown retire immédiatement le LIVE public.');
$status=$pdo->query("SELECT status FROM p50_live_streams WHERE profile_id='tiktok-test'")->fetchColumn();
must($status==='live','Le flux peut rester live en base pour reconfirmation.');

$pdo->prepare("UPDATE p50_live_source_health SET last_state='offline',last_checked_at=UTC_TIMESTAMP() WHERE profile_id=? AND platform=?")->execute(['tiktok-test','TikTok']);
$active=p50_live_v4_active_rows();
must(count($active)===0,'Un offline explicite retire immédiatement le live public.');
$status=$pdo->query("SELECT status FROM p50_live_streams WHERE profile_id='tiktok-test'")->fetchColumn();
must($status==='unconfirmed','Le flux retiré reste en historique à confirmer.');

p50_live_v4_store_live($live);
$pdo->prepare("UPDATE p50_live_source_health SET last_state='live',last_checked_at=UTC_TIMESTAMP(),last_live_at=UTC_TIMESTAMP() WHERE profile_id=? AND platform=?")->execute(['tiktok-test','TikTok']);
$active=p50_live_v4_active_rows();
must(count($active)===1,'Une nouvelle confirmation live peut republier le flux.');
$pdo->exec("UPDATE p50_live_streams SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE) WHERE profile_id='tiktok-test'");
$pdo->exec("UPDATE p50_live_source_health SET last_checked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE) WHERE profile_id='tiktok-test'");
$active=p50_live_v4_active_rows();
must(count($active)===1,'TikTok reste public 2 minutes après confirmation (fenêtre 12 min).');
$pdo->exec("UPDATE p50_live_streams SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 13 MINUTE) WHERE profile_id='tiktok-test'");
$pdo->exec("UPDATE p50_live_source_health SET last_checked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 13 MINUTE),last_state='live' WHERE profile_id='tiktok-test'");
$active=p50_live_v4_active_rows();
must(count($active)===0,'TikTok sort du public après 12 minutes sans reconfirmation.');

p50_live_v4_store_live($live);
$pdo->prepare("UPDATE p50_live_source_health SET last_state='live',last_checked_at=UTC_TIMESTAMP(),last_live_at=UTC_TIMESTAMP() WHERE profile_id=? AND platform=?")->execute(['tiktok-test','TikTok']);
$pdo->exec("UPDATE p50_live_streams SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 21 MINUTE) WHERE profile_id='tiktok-test'");
$active=p50_live_v4_active_rows();
must(count($active)===0,'TikTok expire aussi après la grâce de reconfirmation.');

p50_live_v4_store_live($live);
p50_live_v4_mark_ended('tiktok-test','TikTok','replay',['url'=>'https://example.test/replay']);
$row=$pdo->query("SELECT status,metadata FROM p50_live_streams WHERE profile_id='tiktok-test'")->fetch();
must($row['status']==='ended','Une preuve de fin clôt le direct.');
$metadata=json_decode((string)$row['metadata'],true);
must(($metadata['endReason']??'')==='replay','Le motif de fin est conservé.');

$false=['profileId'=>'youtube-false','platform'=>'YouTube','title'=>'Image figée','url'=>'https://www.youtube.com/watch?v=falseLive123','thumbnail'=>'','confidence'=>99,'startedAt'=>null,'viewers'=>null,'metadata'=>['videoId'=>'falseLive123']];
p50_live_v4_store_live($false);
$pdo->prepare("INSERT INTO p50_live_source_health(profile_id,platform,url_hash,official_url,last_state,last_checked_at,last_live_at,metadata) VALUES(?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),'{}')")->execute(['youtube-false','YouTube',hash('sha256','y'),'https://www.youtube.com/@false','live']);
$key=p50_live_v4_stream_key($false);
$pdo->prepare("INSERT INTO p50_live_dismissals(stream_key,profile_id,platform,url_hash,dismissed_by,reason,dismissed_at) VALUES(?,?,?,?,?,'false_positive',UTC_TIMESTAMP())")->execute([$key,'youtube-false','YouTube',hash('sha256',strtolower(rtrim($false['url'],'/'))),'owner-test']);
p50_live_v4_store_live($false);
$active=p50_live_v4_active_rows();
must(!array_filter($active,static fn($item)=>(string)$item['profileId']==='youtube-false'),'Le même faux direct supprimé ne doit jamais revenir.');
$status=$pdo->query("SELECT status FROM p50_live_streams WHERE profile_id='youtube-false'")->fetchColumn();
must($status==='ended','Le faux direct supprimé est clôturé en base.');

$future=$false;$future['url']='https://www.youtube.com/watch?v=realFuture456';$future['metadata']=['videoId'=>'realFuture456'];p50_live_v4_store_live($future);
$active=p50_live_v4_active_rows();
must((bool)array_filter($active,static fn($item)=>(string)$item['profileId']==='youtube-false'),'Un futur live avec une autre URL reste détectable.');

echo json_encode(['ok'=>true,'trustGate'=>true,'unknownHidesPublic'=>true,'publicMaxAge'=>true,'dismissalPersistent'=>true,'futureLiveAllowed'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL;
