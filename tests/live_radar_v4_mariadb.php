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

$pdo->exec('DROP TABLE IF EXISTS p50_live_source_health');
$pdo->exec('DROP TABLE IF EXISTS p50_live_streams');
p50_live_v4_ensure_schema();

$live=['profileId'=>'tiktok-test','platform'=>'TikTok','title'=>'Test est en direct','url'=>'https://www.tiktok.com/@test/live','thumbnail'=>'','confidence'=>99,'startedAt'=>null,'viewers'=>42,'metadata'=>['roomId'=>'741234567890']];
p50_live_v4_store_live($live);
$pdo->prepare("INSERT INTO p50_live_source_health(profile_id,platform,url_hash,official_url,last_state,last_checked_at,last_live_at,metadata) VALUES(?,?,?,?,?,NOW(),NOW(),'{}')")->execute(['tiktok-test','TikTok',hash('sha256','x'),'https://www.tiktok.com/@test','unknown']);
$active=p50_live_v4_active_rows();
must(count($active)===1,'Un état unknown temporaire ne retire pas un live TikTok récemment confirmé.');
must($active[0]['lastCheckState']==='unknown','Le dernier état de contrôle reste observable.');

$pdo->exec("UPDATE p50_live_streams SET last_seen_at=DATE_SUB(NOW(),INTERVAL 21 MINUTE) WHERE profile_id='tiktok-test'");
$active=p50_live_v4_active_rows();
must(count($active)===0,'Le live sort du public après expiration de la grâce.');
$status=$pdo->query("SELECT status FROM p50_live_streams WHERE profile_id='tiktok-test'")->fetchColumn();
must($status==='unconfirmed','Le live expiré reste en historique à confirmer.');

p50_live_v4_store_live($live);
p50_live_v4_mark_ended('tiktok-test','TikTok','replay',['url'=>'https://example.test/replay']);
$row=$pdo->query("SELECT status,metadata FROM p50_live_streams WHERE profile_id='tiktok-test'")->fetch();
must($row['status']==='ended','Une preuve de fin clôt le direct.');
$metadata=json_decode((string)$row['metadata'],true);
must(($metadata['endReason']??'')==='replay','Le motif de fin est conservé.');

echo json_encode(['ok'=>true,'activeGrace'=>true,'replayHistory'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL;
