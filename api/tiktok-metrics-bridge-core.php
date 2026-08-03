<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-schema-core.php';
require_once __DIR__.'/tiktok-oauth-core.php';

const P50_TIKTOK_METRICS_BRIDGE_VERSION='1.0.0';

function p50tm_schema_ready(PDO $pdo): bool {
    return p50_metrics_table_exists($pdo,'p50_tiktok_oauth_connections')
        && p50_metrics_table_exists($pdo,'p50_social_links')
        && p50_metrics_table_exists($pdo,'p50_profile_registry');
}

function p50tm_username_from_url(string $url): string {
    $host=strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));
    $path=trim((string)(parse_url($url,PHP_URL_PATH)?:''),'/');
    if(!str_contains($host,'tiktok.com'))return '';
    return preg_match('#^@([A-Za-z0-9._-]{2,32})$#',$path,$match)?strtolower($match[1]):'';
}

function p50tm_official_profile(PDO $pdo,string $profileId): ?array {
    $profileId=trim($profileId);
    if($profileId===''||!p50tm_schema_ready($pdo))return null;
    $threshold=function_exists('p50_mc_threshold')?p50_mc_threshold():60;
    $stmt=$pdo->prepare("SELECT r.profile_id,r.public_name,s.normalized_url,s.confidence
      FROM p50_profile_registry r JOIN p50_social_links s ON BINARY s.profile_id=BINARY r.profile_id
      WHERE BINARY r.profile_id=BINARY ? AND r.alive=1 AND s.platform='TikTok'
      AND s.status='verified' AND s.confidence>=? ORDER BY s.confidence DESC,s.normalized_url ASC LIMIT 1");
    $stmt->execute([$profileId,$threshold]);
    $row=$stmt->fetch();
    if(!is_array($row)||p50tm_username_from_url((string)$row['normalized_url'])==='')return null;
    return $row+['source_type'=>'tiktok_verified_official_link'];
}

function p50tm_connection_for_profile(PDO $pdo,string $profileId,bool $includeReauthorization=true): ?array {
    $official=p50tm_official_profile($pdo,$profileId);
    if(!$official)return null;
    $username=p50tm_username_from_url((string)$official['normalized_url']);
    $statuses=$includeReauthorization?"('active','reauthorization_required')":"('active')";
    $stmt=$pdo->prepare("SELECT user_id,open_id,union_id,display_name,username,profile_deep_link,status,
      access_token_encrypted,refresh_token_encrypted,access_expires_at,refresh_expires_at,last_refreshed_at,last_synced_at,updated_at
      FROM p50_tiktok_oauth_connections
      WHERE status IN $statuses AND LOWER(username)=? ORDER BY status='active' DESC,updated_at DESC LIMIT 2");
    $stmt->execute([$username]);
    $rows=$stmt->fetchAll();
    if(count($rows)!==1)return null;
    $row=$rows[0];
    if(strtolower(trim((string)$row['username']))!==$username)return null;
    return $row+['official'=>$official,'profile_id'=>$profileId];
}

function p50tm_public_access(string $profileId=''): array {
    $empty=['configured'=>false,'authorized'=>false,'mode'=>'mapping_required','authorizationRequired'=>true];
    try{
        $pdo=db();
        if(!p50tm_schema_ready($pdo))return $empty;
        if(trim($profileId)!==''){
            $connection=p50tm_connection_for_profile($pdo,$profileId,true);
            if(!$connection)return $empty;
            $authorized=(string)$connection['status']==='active'
                && trim((string)$connection['access_token_encrypted'])!==''
                && trim((string)$connection['refresh_token_encrypted'])!=='';
            return ['configured'=>true,'authorized'=>$authorized,'mode'=>'authorized_display','authorizationRequired'=>!$authorized];
        }
        $stmt=$pdo->query("SELECT DISTINCT r.profile_id FROM p50_profile_registry r JOIN p50_social_links s ON BINARY s.profile_id=BINARY r.profile_id
          WHERE r.alive=1 AND s.platform='TikTok' AND s.status='verified' AND s.confidence>=60 ORDER BY r.profile_id LIMIT 100");
        $mapped=0;$authorized=0;
        foreach(array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)) as $candidate){
            $connection=p50tm_connection_for_profile($pdo,$candidate,true);
            if(!$connection)continue;
            $mapped++;
            if((string)$connection['status']==='active'&&trim((string)$connection['access_token_encrypted'])!==''&&trim((string)$connection['refresh_token_encrypted'])!=='')$authorized++;
        }
        return ['configured'=>$mapped>0,'authorized'=>$authorized>0,'mode'=>$mapped>0?'authorized_display':'mapping_required','authorizationRequired'=>$mapped>$authorized];
    }catch(Throwable){return $empty;}
}

function p50tm_authorized_profile_ids(PDO $pdo): array {
    if(!p50tm_schema_ready($pdo))return [];
    $stmt=$pdo->query("SELECT DISTINCT r.profile_id FROM p50_profile_registry r JOIN p50_social_links s ON BINARY s.profile_id=BINARY r.profile_id
      WHERE r.alive=1 AND s.platform='TikTok' AND s.status='verified' AND s.confidence>=60 ORDER BY r.profile_id LIMIT 100");
    $ids=[];
    foreach(array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)) as $profileId){
        $connection=p50tm_connection_for_profile($pdo,$profileId,false);
        if($connection&&trim((string)$connection['access_token_encrypted'])!==''&&trim((string)$connection['refresh_token_encrypted'])!=='')$ids[]=$profileId;
    }
    return array_values(array_unique($ids));
}

function p50tm_collect(PDO $pdo,array $official,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result): void {
    $profileId=(string)($official['profile_id']??'');
    $connection=p50tm_connection_for_profile($pdo,$profileId,true);
    if(!$connection)throw new RuntimeException('Association TikTok OAuth introuvable.');
    if((string)$connection['status']!=='active'){
        $result['status']='authorization_required';
        return;
    }
    $token=p50tk_refresh_access_token((string)$connection['user_id']);
    if($token===''){
        $result['status']='authorization_required';
        return;
    }
    p50_mc_tiktok_display(
        $pdo,$official,$limit,$observedAt,$runUuid,$fetch,$result,
        ['configured'=>true,'authorized'=>true,'mode'=>'authorized_display','authorizationRequired'=>false,'secret'=>$token],
        $connection
    );
}
