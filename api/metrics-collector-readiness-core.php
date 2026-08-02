<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-orchestrator-core.php';

const P50_METRICS_COLLECTOR_READINESS_VERSION='COLLECTOR-READINESS-V1.0';

function p50_mcr_verified_rows(PDO $pdo): array {
    $threshold=p50_mc_threshold();
    $stmt=$pdo->prepare("SELECT DISTINCT r.profile_id,r.public_name,s.platform FROM p50_profile_registry r JOIN p50_social_links s ON BINARY s.profile_id=BINARY r.profile_id WHERE r.alive=1 AND s.status='verified' AND s.confidence>=? AND s.platform IN ('YouTube','X','TikTok','Instagram','Facebook','Snapchat') ORDER BY s.platform,r.public_name,r.profile_id LIMIT 3000");
    $stmt->execute([$threshold]);
    return $stmt->fetchAll();
}

function p50_mcr_safe_access(string $platform,string $profileId): array {
    try{$access=p50_mc_public_access($platform,$profileId);}
    catch(Throwable){$access=['configured'=>false,'authorized'=>false,'mode'=>'unavailable','authorizationRequired'=>false];}
    return [
      'configured'=>(bool)($access['configured']??false),'authorized'=>(bool)($access['authorized']??false),
      'mode'=>(string)($access['mode']??'unknown'),'authorizationRequired'=>(bool)($access['authorizationRequired']??false),
    ];
}

function p50_mcr_status(PDO $pdo): array {
    p50_metrics_ensure_schema($pdo);
    $platforms=['YouTube','X','TikTok','Instagram','Facebook','Snapchat'];
    $summary=[];foreach($platforms as $platform)$summary[$platform]=['verifiedLinks'=>0,'operational'=>0,'configurationMissing'=>0,'authorizationRequired'=>0,'modes'=>[]];
    $missing=[];
    foreach(p50_mcr_verified_rows($pdo) as $row){
        $platform=(string)$row['platform'];if(!isset($summary[$platform]))continue;
        $profileId=(string)$row['profile_id'];$access=p50_mcr_safe_access($platform,$profileId);$summary[$platform]['verifiedLinks']++;
        $mode=$access['mode']!==''?$access['mode']:'unknown';$summary[$platform]['modes'][$mode]=($summary[$platform]['modes'][$mode]??0)+1;
        if($access['configured']&&$access['authorized']){$summary[$platform]['operational']++;continue;}
        $reason=$access['configured']?'authorization_required':'configuration_missing';$summary[$platform][$access['configured']?'authorizationRequired':'configurationMissing']++;
        if(count($missing)<200)$missing[]=['profileId'=>$profileId,'name'=>(string)$row['public_name'],'platform'=>$platform,'reason'=>$reason,'mode'=>$mode];
    }
    foreach($summary as &$row){ksort($row['modes']);$row['coveragePercent']=$row['verifiedLinks']>0?round($row['operational']/$row['verifiedLinks']*100,1):100.0;}$row=null;
    $requirements=[
      'X'=>['state'=>$summary['X']['operational']>0?'ready':'action_required','action'=>'Configurer PASS50_X_BEARER_TOKEN côté serveur.'],
      'Instagram'=>['state'=>$summary['Instagram']['operational']>0?'ready':'action_required','action'=>'Connecter les actifs Meta ou configurer Business Discovery avec un jeton et PASS50_INSTAGRAM_DISCOVERY_ACCOUNT_ID.'],
      'Facebook'=>['state'=>$summary['Facebook']['operational']>0?'ready':'action_required','action'=>'Connecter les Pages Meta ou fournir un jeton Page valide.'],
      'TikTok'=>['state'=>$summary['TikTok']['operational']>0?'partial_or_ready':'action_required','action'=>'Associer chaque profil à TikTok OAuth, ou utiliser Research API uniquement après approbation officielle.'],
    ];
    return ['version'=>P50_METRICS_COLLECTOR_READINESS_VERSION,'generatedAt'=>gmdate('c'),'platforms'=>$summary,'requirements'=>$requirements,'missing'=>$missing,'secretsExposed'=>false,'publicStateWrites'=>0];
}
