<?php
declare(strict_types=1);

$dsn=getenv('P50_TEST_DSN');
if(!$dsn){fwrite(STDERR,"P50_TEST_DSN absent\n");exit(77);}
$pdo=new PDO($dsn,getenv('P50_TEST_DB_USER')?:'root',getenv('P50_TEST_DB_PASSWORD')?:'root',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
function must(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
foreach(['p50_metric_contents','p50_ranking_snapshots','p50_profile_registry'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec("CREATE TABLE p50_profile_registry(profile_id VARCHAR(100) PRIMARY KEY,alive TINYINT NOT NULL,rank_position INT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE p50_ranking_snapshots(id BIGINT AUTO_INCREMENT PRIMARY KEY,profile_id VARCHAR(100),rank_position INT,captured_at DATETIME) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE p50_metric_contents(id BIGINT AUTO_INCREMENT PRIMARY KEY,profile_id VARCHAR(100),status VARCHAR(20),last_seen_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO p50_profile_registry VALUES ('A',1,1),('B',1,2),('C',1,3),('DEAD',0,4)");
$pdo->exec("INSERT INTO p50_ranking_snapshots(profile_id,rank_position,captured_at) VALUES
 ('A',1,'2026-08-05 10:00:00'),('B',2,'2026-08-05 10:00:00'),('C',3,'2026-08-05 10:00:00'),('DEAD',4,'2026-08-05 10:00:00'),
 ('A',9,'2026-08-04 10:00:00')");
$pdo->exec("INSERT INTO p50_metric_contents(profile_id,status,last_seen_at) VALUES
 ('A','active','2026-08-05 09:00:00'),('A','active','2026-08-05 09:30:00'),('B','active','2026-08-04 09:00:00')");

$limit=70;
$snapshotSql="SELECT ordered.profile_id,ordered.rank_position,ordered.latest_content
FROM (
 SELECT ranked.profile_id,ranked.rank_position,MAX(c.last_seen_at) AS latest_content
 FROM (
  SELECT r.profile_id,MIN(s.rank_position) AS rank_position
  FROM p50_profile_registry r
  JOIN p50_ranking_snapshots s ON BINARY s.profile_id=BINARY r.profile_id
  JOIN (SELECT profile_id,MAX(captured_at) AS captured_at FROM p50_ranking_snapshots GROUP BY profile_id) latest
   ON BINARY latest.profile_id=BINARY s.profile_id AND latest.captured_at=s.captured_at
  WHERE r.alive=1 AND s.rank_position BETWEEN 1 AND 70
  GROUP BY r.profile_id
 ) ranked
 LEFT JOIN p50_metric_contents c ON BINARY c.profile_id=BINARY ranked.profile_id AND c.status='active'
 GROUP BY ranked.profile_id,ranked.rank_position
) ordered
ORDER BY CASE WHEN ordered.latest_content IS NULL THEN 0 ELSE 1 END ASC,ordered.latest_content ASC,ordered.rank_position ASC
LIMIT ".$limit;
$rows=$pdo->query($snapshotSql)->fetchAll();
must(array_column($rows,'profile_id')===['C','B','A'],'Snapshot path: sans contenu puis plus ancien puis récent');
must((int)$rows[2]['rank_position']===1,'Le snapshot le plus récent doit fournir le rang courant');

$pdo->exec('DROP TABLE p50_ranking_snapshots');
$fallbackSql="SELECT ordered.profile_id,ordered.rank_position,ordered.latest_content
FROM (
 SELECT r.profile_id,r.rank_position,MAX(c.last_seen_at) AS latest_content
 FROM p50_profile_registry r
 LEFT JOIN p50_metric_contents c ON BINARY c.profile_id=BINARY r.profile_id AND c.status='active'
 WHERE r.alive=1 AND r.rank_position BETWEEN 1 AND 70
 GROUP BY r.profile_id,r.rank_position
) ordered
ORDER BY CASE WHEN ordered.latest_content IS NULL THEN 0 ELSE 1 END ASC,ordered.latest_content ASC,ordered.rank_position ASC
LIMIT ".$limit;
$rows=$pdo->query($fallbackSql)->fetchAll();
must(array_column($rows,'profile_id')===['C','B','A'],'Fallback path: ordre de fraîcheur identique');
echo "content freshness V3 MariaDB selection ok\n";
