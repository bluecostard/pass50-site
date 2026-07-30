from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    target = Path(path)
    text = target.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: remplacement attendu 1 fois, trouvé {count}')
    target.write_text(text.replace(old, new, 1), encoding='utf-8')


replace_once(
    'api/youtube-metrics-bridge-core.php',
    """function p50ym_connection_for_profile(PDO $pdo,string $profileId): ?array {
    $profileId=trim($profileId);
    if($profileId===''||!p50ym_schema_ready($pdo))return null;
    $stmt=$pdo->prepare("SELECT user_id,channel_id,channel_title,channel_custom_url,channel_thumbnail_url,status,access_expires_at,last_refreshed_at,updated_at FROM p50_youtube_oauth_connections WHERE profile_id=? AND status='active' LIMIT 1");
    $stmt->execute([$profileId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}
""",
    """function p50ym_connection_for_profile(PDO $pdo,string $profileId): ?array {
    $profileId=trim($profileId);
    if($profileId===''||!p50ym_schema_ready($pdo))return null;
    $stmt=$pdo->prepare("SELECT user_id,channel_id,channel_title,channel_custom_url,channel_thumbnail_url,status,access_expires_at,last_refreshed_at,updated_at FROM p50_youtube_oauth_connections WHERE profile_id=? AND status='active' LIMIT 1");
    $stmt->execute([$profileId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function p50ym_official_profile(PDO $pdo,string $profileId): ?array {
    $connection=p50ym_connection_for_profile($pdo,$profileId);
    if(!$connection)return null;
    $name='';
    if(p50_metrics_table_exists($pdo,'p50_profile_registry')){
        $stmt=$pdo->prepare('SELECT public_name FROM p50_profile_registry WHERE profile_id=? AND alive=1 LIMIT 1');
        $stmt->execute([$profileId]);
        $name=trim((string)($stmt->fetchColumn()?:''));
    }
    $channelId=trim((string)$connection['channel_id']);
    if($channelId==='')return null;
    return [
        'profile_id'=>$profileId,
        'public_name'=>$name!==''?$name:(string)$connection['channel_title'],
        'normalized_url'=>'https://www.youtube.com/channel/'.rawurlencode($channelId),
        'confidence'=>99,
        'source_type'=>'youtube_oauth_mapping',
    ];
}
""",
)

replace_once(
    'api/metrics-collectors-core.php',
    """    $stmt->execute([$profileId,$platform,$threshold]);$row=$stmt->fetch();
    if(!$row)throw new InvalidArgumentException('Profil actif ou lien officiel vérifié introuvable.');
    return $row;
""",
    """    $stmt->execute([$profileId,$platform,$threshold]);$row=$stmt->fetch();
    if(!$row&&$platform==='YouTube'&&function_exists('p50ym_official_profile'))$row=p50ym_official_profile($pdo,$profileId);
    if(!$row)throw new InvalidArgumentException('Profil actif ou source officielle vérifiée introuvable.');
    return $row;
""",
)

replace_once(
    'api/metrics-orchestrator-core.php',
    """function p50_mo_select(PDO $pdo,string $cadenceKey,array $options=[]): array {
""",
    """function p50_mo_unique_candidate_rows(array $rows): array {
    $unique=[];
    foreach($rows as $row){
        $profileId=trim((string)($row['profile_id']??''));$platform=p50_mc_platform((string)($row['platform']??''));
        if($profileId===''||$platform==='')continue;
        $unique[$profileId.'|'.$platform]=['profile_id'=>$profileId,'platform'=>$platform];
    }
    return array_values($unique);
}

function p50_mo_oauth_youtube_rows(PDO $pdo,array $profileIds): array {
    if(!$profileIds||!p50_metrics_table_exists($pdo,'p50_youtube_oauth_connections')||!p50_metrics_column_exists($pdo,'p50_youtube_oauth_connections','profile_id'))return [];
    $placeholders=implode(',',array_fill(0,count($profileIds),'?'));
    $stmt=$pdo->prepare("SELECT DISTINCT r.profile_id,'YouTube' platform FROM p50_profile_registry r JOIN p50_youtube_oauth_connections y ON BINARY y.profile_id=BINARY r.profile_id WHERE r.alive=1 AND r.profile_id IN ($placeholders) AND y.profile_id IS NOT NULL AND y.status='active'");
    $stmt->execute($profileIds);
    return $stmt->fetchAll();
}

function p50_mo_enqueue_profile(PDO $pdo,string $profileId,string $platform,string $cadenceKey='p0',array $options=[]): array {
    $profileId=trim($profileId);$platform=p50_mc_platform($platform);
    if($profileId===''||$platform==='')throw new InvalidArgumentException('Profil ou plateforme métrique invalide.');
    p50_metrics_ensure_schema($pdo);
    $cadence=p50_mo_cadence($cadenceKey);$bucket=p50_mo_bucket($cadence,$options['now']??null);
    $priority=max(0,min(1000,(int)($options['priorityOverride']??$cadence['priority'])));
    $reason=trim((string)($options['reason']??'manual'))?:'manual';
    $idempotency=hash('sha256',implode('|',[P50_METRICS_ORCHESTRATOR_VERSION,'profile_enqueue',$bucket['key'],$profileId,$platform,$reason]));
    $payload=['profileId'=>$profileId,'platform'=>$platform,'contentLimit'=>(int)($options['contentLimit']??$cadence['contentLimit']),'observedAt'=>$bucket['observedAt'],'liveConfirmed'=>false,'cadence'=>$cadenceKey,'bucket'=>$bucket['key'],'dispatchId'=>substr((string)($options['dispatchId']??$reason),0,120),'reason'=>$reason];
    return p50_metrics_enqueue_job($pdo,['idempotencyKey'=>$idempotency,'collector'=>strtolower($platform).'_v1','platform'=>$platform,'scopeType'=>'profile','scopeId'=>$profileId,'priority'=>$priority,'maxAttempts'=>3,'payload'=>$payload])+['cadence'=>$cadenceKey,'profileId'=>$profileId,'platform'=>$platform,'priority'=>$priority,'bucket'=>$bucket];
}

function p50_mo_select(PDO $pdo,string $cadenceKey,array $options=[]): array {
""",
)

replace_once(
    'api/metrics-orchestrator-core.php',
    """    $stmt->execute([...$ids,$threshold]);$rows=$stmt->fetchAll();$summary['eligibleProfiles']=count(array_unique(array_column($rows,'profile_id')));$summary['eligibleLinks']=count($rows);$candidates=[];$liveSet=array_fill_keys($live['profileIds'],true);
""",
    """    $stmt->execute([...$ids,$threshold]);$rows=p50_mo_unique_candidate_rows(array_merge($stmt->fetchAll(),p50_mo_oauth_youtube_rows($pdo,$ids)));$summary['eligibleProfiles']=count(array_unique(array_column($rows,'profile_id')));$summary['eligibleLinks']=count($rows);$candidates=[];$liveSet=array_fill_keys($live['profileIds'],true);
""",
)

replace_once(
    'api/youtube-metrics-map.php',
    """require __DIR__.'/youtube-metrics-bridge-core.php';
""",
    """require __DIR__.'/youtube-metrics-bridge-core.php';
require __DIR__.'/metrics-orchestrator-core.php';
""",
)

replace_once(
    'api/youtube-metrics-map.php',
    """try{
    $result=p50ym_map_channel(db(),$channelId,$profileId!==''?$profileId:null,(string)$user['id']);
    json_response(['ok'=>true]+$result);
""",
    """try{
    $result=p50ym_map_channel(db(),$channelId,$profileId!==''?$profileId:null,(string)$user['id']);
    $queued=null;$deferred=false;
    if($profileId!==''){
        try{$queued=p50_mo_enqueue_profile(db(),$profileId,'YouTube','p0',['reason'=>'oauth_mapping','priorityOverride'=>5,'contentLimit'=>5,'dispatchId'=>'youtube-map-'.substr(hash('sha256',$channelId),0,16)]);}
        catch(Throwable $queueError){$deferred=true;error_log('YouTube mapping queue deferred: '.p50_metrics_safe_error($queueError->getMessage()));}
    }
    json_response(['ok'=>true,'collectionQueued'=>$queued,'collectionDeferred'=>$deferred]+$result);
""",
)

print('YouTube OAuth automatic eligibility patch applied')
