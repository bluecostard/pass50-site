<?php
declare(strict_types=1);

$dsn=getenv('P50_TEST_DSN');
if(!$dsn){fwrite(STDERR,"P50_TEST_DSN absent\n");exit(77);}
$pdo=new PDO($dsn,getenv('P50_TEST_DB_USER')?:'root',getenv('P50_TEST_DB_PASSWORD')?:'root',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
function yom_must(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}
function yom_apply(PDO $pdo): void {
    $sql=file_get_contents(dirname(__DIR__).'/migration-youtube-oauth-v1.sql');
    if($sql===false)throw new RuntimeException('Migration absente.');
    foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[] as $statement){
        $statement=trim($statement);
        if($statement!=='')$pdo->exec($statement);
    }
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->exec('DROP TABLE IF EXISTS p50_youtube_oauth_connections');
$pdo->exec('DROP TABLE IF EXISTS p50_youtube_oauth_states');
$pdo->exec('DROP TABLE IF EXISTS users');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE users (
  id CHAR(36) PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

yom_apply($pdo);
yom_apply($pdo);

$tables=$pdo->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('p50_youtube_oauth_states','p50_youtube_oauth_connections')
  ORDER BY TABLE_NAME")->fetchAll();
yom_must(count($tables)===2,'Les deux tables OAuth existent.');
foreach($tables as $table)yom_must(strtoupper((string)$table['ENGINE'])==='INNODB','Les tables OAuth utilisent InnoDB.');

$indexes=$pdo->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='p50_youtube_oauth_connections'")->fetchAll(PDO::FETCH_COLUMN);
yom_must(in_array('idx_p50_youtube_oauth_channel',$indexes,true),'Index chaîne présent.');
yom_must(in_array('idx_p50_youtube_oauth_status',$indexes,true),'Index statut présent.');

$foreignKeys=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('p50_youtube_oauth_states','p50_youtube_oauth_connections')
  AND REFERENCED_TABLE_NAME='users'")->fetchColumn();
yom_must($foreignKeys===2,'Les deux clés étrangères utilisateurs sont présentes.');

echo json_encode(['ok'=>true,'tables'=>2,'foreignKeys'=>$foreignKeys],JSON_UNESCAPED_SLASHES).PHP_EOL;
