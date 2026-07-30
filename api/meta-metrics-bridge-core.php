<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-schema-core.php';
require_once __DIR__.'/meta-oauth-core.php';

const P50_META_METRICS_BRIDGE_VERSION='1.0.0';

function p50mm_schema_ready(PDO $pdo): bool {
    return p50_metrics_table_exists($pdo,'p50_meta_oauth_assets')
        && p50_metrics_table_exists($pdo,'p50_meta_oauth_connections');
}

function p50mm_platform(string $platform): string {
    return match(strtolower(trim($platform))){'facebook'=>'Facebook','instagram'=>'Instagram',default=>''};
}

function p50mm_scopes(string $value): array {
    $parts=preg_split('/[\s,]+/',strtolower(trim($value)))?:[];
    return array_values(array_unique(array_filter($parts,static fn($scope): bool=>$scope!=='')));
}

function p50mm_has_scope(string $scopes,string $scope): bool {
    return in_array(strtolower($scope),p50mm_scopes($scopes),true);
}

function p50mm_asset_for_profile(PDO $pdo,string $platform,string $profileId): ?array {
    $platform=p50mm_platform($platform);$profileId=trim($profileId);
    if($platform===''||$profileId===''||!p50mm_schema_ready($pdo))return null;
    $stmt=$pdo->prepare("SELECT a.user_id,a.platform,a.asset_id,a.profile_id,a.asset_name,a.username,a.profile_url,a.parent_page_id,a.access_token_encrypted,a.tasks,a.last_checked_at,a.last_error,a.updated_at,c.scopes,c.status connection_status,c.token_expires_at
      FROM p50_meta_oauth_assets a JOIN p50_meta_oauth_connections c ON BINARY c.user_id=BINARY a.user_id
      WHERE BINARY a.profile_id=BINARY ? AND a.platform=? AND a.status='active' AND c.status='active'
      ORDER BY a.updated_at DESC,a.id DESC LIMIT 1");
    $stmt->execute([$profileId,$platform]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function p50mm_official_profile(PDO $pdo,string $profileId,string $platform): ?array {
    $asset=p50mm_asset_for_profile($pdo,$platform,$profileId);if(!$asset)return null;
    $name='';
    if(p50_metrics_table_exists($pdo,'p50_profile_registry')){
        $stmt=$pdo->prepare('SELECT public_name FROM p50_profile_registry WHERE BINARY profile_id=BINARY ? AND alive=1 LIMIT 1');
        $stmt->execute([$profileId]);$name=trim((string)($stmt->fetchColumn()?:''));
    }
    $url=trim((string)($asset['profile_url']??''));
    if($url===''){
        if($platform==='Instagram'){
            $username=trim((string)($asset['username']??''));
            $url='https://www.instagram.com/'.rawurlencode($username!==''?$username:(string)$asset['asset_id']).'/';
        }else $url='https://www.facebook.com/'.rawurlencode((string)$asset['asset_id']);
    }
    return ['profile_id'=>$profileId,'public_name'=>$name!==''?$name:(string)$asset['asset_name'],'normalized_url'=>$url,'confidence'=>99,'source_type'=>'meta_oauth_mapping'];
}

function p50mm_credentials(string $platform,string $profileId): ?array {
    $platform=p50mm_platform($platform);if($platform==='')return null;
    try{
        $pdo=db();$asset=p50mm_asset_for_profile($pdo,$platform,$profileId);if(!$asset)return null;
        $secret=p50mo_decrypt((string)$asset['access_token_encrypted']);$scopes=(string)($asset['scopes']??'');
        $insights=$platform==='Facebook'?p50mm_has_scope($scopes,'read_insights'):p50mm_has_scope($scopes,'instagram_manage_insights');
        $cfg=p50mo_config();
        return [
            'configured'=>true,'authorized'=>$secret!=='','mode'=>$platform==='Facebook'?'page_authorized_oauth':'professional_authorized_oauth',
            'authorizationRequired'=>$secret==='','secret'=>$secret,'accountId'=>$platform==='Instagram'?(string)$asset['asset_id']:'',
            'pageId'=>$platform==='Facebook'?(string)$asset['asset_id']:(string)($asset['parent_page_id']??''),'discoveryAccountId'=>'',
            'storiesAuthorized'=>$platform==='Instagram'&&$insights,'insightsAuthorized'=>$insights,'graphVersion'=>(string)$cfg['graph_version'],
            'assetId'=>(string)$asset['asset_id'],'sourceType'=>'meta_oauth_asset',
        ];
    }catch(Throwable){return null;}
}

function p50mm_public_access(string $platform,string $profileId=''): array {
    $platform=p50mm_platform($platform);if($platform==='')return ['configured'=>false,'authorized'=>false,'mode'=>'unsupported','authorizationRequired'=>false];
    try{
        $pdo=db();if(!p50mm_schema_ready($pdo))return ['configured'=>false,'authorized'=>false,'mode'=>'mapping_required','authorizationRequired'=>true];
        if(trim($profileId)===''){
            $stmt=$pdo->prepare("SELECT COUNT(*) FROM p50_meta_oauth_assets a JOIN p50_meta_oauth_connections c ON BINARY c.user_id=BINARY a.user_id WHERE a.platform=? AND a.profile_id IS NOT NULL AND a.status='active' AND c.status='active' AND a.access_token_encrypted<>''");
            $stmt->execute([$platform]);$mapped=(int)$stmt->fetchColumn();
            return ['configured'=>$mapped>0,'authorized'=>$mapped>0,'mode'=>$mapped>0?'authorized_oauth':'mapping_required','authorizationRequired'=>$mapped===0];
        }
        $asset=p50mm_asset_for_profile($pdo,$platform,$profileId);$authorized=$asset&&trim((string)$asset['access_token_encrypted'])!=='';
        return ['configured'=>$asset!==null,'authorized'=>(bool)$authorized,'mode'=>$asset?($platform==='Facebook'?'page_authorized_oauth':'professional_authorized_oauth'):'mapping_required','authorizationRequired'=>!$authorized];
    }catch(Throwable){return ['configured'=>false,'authorized'=>false,'mode'=>'mapping_required','authorizationRequired'=>true];}
}

function p50mm_orchestrator_rows(PDO $pdo,array $profileIds): array {
    if(!$profileIds||!p50mm_schema_ready($pdo))return [];
    $placeholders=implode(',',array_fill(0,count($profileIds),'?'));
    $stmt=$pdo->prepare("SELECT DISTINCT r.profile_id,a.platform FROM p50_profile_registry r JOIN p50_meta_oauth_assets a ON BINARY a.profile_id=BINARY r.profile_id JOIN p50_meta_oauth_connections c ON BINARY c.user_id=BINARY a.user_id WHERE r.alive=1 AND r.profile_id IN ($placeholders) AND a.profile_id IS NOT NULL AND a.platform IN ('Facebook','Instagram') AND a.status='active' AND c.status='active' AND a.access_token_encrypted<>''");
    $stmt->execute($profileIds);return $stmt->fetchAll();
}

function p50mm_authorized_profile_ids(PDO $pdo): array {
    $ids=[];
    if(p50mm_schema_ready($pdo)&&p50_metrics_table_exists($pdo,'p50_profile_registry')){
        $stmt=$pdo->query("SELECT DISTINCT a.profile_id FROM p50_meta_oauth_assets a JOIN p50_meta_oauth_connections c ON BINARY c.user_id=BINARY a.user_id JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY a.profile_id WHERE a.profile_id IS NOT NULL AND a.platform IN ('Facebook','Instagram') AND a.status='active' AND c.status='active' AND r.alive=1 AND a.access_token_encrypted<>'' LIMIT 100");
        $ids=array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    if(function_exists('p50tm_authorized_profile_ids'))$ids=array_merge($ids,p50tm_authorized_profile_ids($pdo));
    return array_values(array_unique(array_filter($ids)));
}

function p50mm_safe_status(PDO $pdo): array {
    $empty=['schemaReady'=>false,'assets'=>[],'summary'=>['total'=>0,'mapped'=>0,'unmapped'=>0,'facebookMapped'=>0,'instagramMapped'=>0,'insightsAuthorized'=>0]];
    if(!p50mm_schema_ready($pdo))return $empty;
    $join=p50_metrics_table_exists($pdo,'p50_profile_registry')?'LEFT JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY a.profile_id':'';
    $profileName=$join!==''?'r.public_name':'NULL';
    $rows=$pdo->query("SELECT a.platform,a.asset_id,a.asset_name,a.username,a.profile_id,a.last_checked_at,a.last_error,a.updated_at,c.scopes,$profileName profile_name FROM p50_meta_oauth_assets a JOIN p50_meta_oauth_connections c ON BINARY c.user_id=BINARY a.user_id $join WHERE a.status='active' AND c.status='active' AND a.platform IN ('Facebook','Instagram') ORDER BY a.platform,a.asset_name LIMIT 100")->fetchAll();
    $assets=[];$summary=$empty['summary'];
    foreach($rows as $row){
        $platform=(string)$row['platform'];$profileId=trim((string)($row['profile_id']??''));$summary['total']++;
        if($profileId!==''){$summary['mapped']++;$summary[strtolower($platform).'Mapped']++;}else $summary['unmapped']++;
        $scope=$platform==='Facebook'?'read_insights':'instagram_manage_insights';$insights=p50mm_has_scope((string)$row['scopes'],$scope);if($insights)$summary['insightsAuthorized']++;
        $assets[]=['platform'=>$platform,'assetId'=>(string)$row['asset_id'],'assetName'=>(string)$row['asset_name'],'username'=>(string)$row['username'],'profileId'=>$profileId!==''?$profileId:null,'profileName'=>trim((string)($row['profile_name']??''))?:null,'insightsAuthorized'=>$insights,'lastCheckedAt'=>$row['last_checked_at']?gmdate('c',strtotime((string)$row['last_checked_at'])):null,'lastError'=>p50_metrics_safe_error($row['last_error']??null),'updatedAt'=>$row['updated_at']?gmdate('c',strtotime((string)$row['updated_at'])):null];
    }
    return ['schemaReady'=>true,'assets'=>$assets,'summary'=>$summary];
}
