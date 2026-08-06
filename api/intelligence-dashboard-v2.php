<?php
declare(strict_types=1);

const P50_INTELLIGENCE_DASHBOARD_V2='PASS50-INTELLIGENCE-DASHBOARD-V2.0';

function p50_intelligence_current_profile_ids(): array {
    $state=p50_de_load_public_state();$ids=[];
    foreach((array)($state['profiles']??[]) as $profile){
        if(is_array($profile)&&!empty($profile['id']))$ids[(string)$profile['id']]=true;
    }
    return $ids;
}

function p50_intelligence_sync_removed_profiles(array $ids): int {
    if(!$ids)return 0;
    $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $stmt=db()->prepare("UPDATE p50_profile_registry SET alive=0,eligible=0 WHERE profile_id NOT IN ($placeholders) AND (alive<>0 OR eligible<>0)");
    $stmt->execute(array_keys($ids));
    return $stmt->rowCount();
}

function p50_intelligence_item_is_fresh(array $item,int $maxAgeHours=6): bool {
    $end=strtotime((string)($item['periodEnd']??''));
    return $end!==false&&$end>=time()-$maxAgeHours*3600;
}

function p50_intelligence_item_is_sufficient(array $item): bool {
    return p50_intelligence_item_is_fresh($item)
        && !empty($item['recentData'])
        && ($item['comparisonStatus']??'')==='comparable'
        && in_array((string)($item['confidenceLevel']??''),['moyenne','élevée'],true);
}

function p50_intelligence_sanitize_item(array $item): array {
    $sufficient=p50_intelligence_item_is_sufficient($item);
    if(!$sufficient){
        $item['growthIndex']=0;$item['buzzIndex']=0;$item['globalVariation']=0.0;
        $item['mainSignal']='insufficient_data';$item['mainVariation']='Données insuffisantes';
        $item['explanation']=p50_intelligence_item_is_fresh($item)
            ?'Données récentes insuffisantes pour mesurer une progression ou un recul fiable.'
            :'Analyse obsolète : une nouvelle collecte est nécessaire.';
        $item['comparisonStatus']='insufficient_history';
    }
    $item['fresh']=p50_intelligence_item_is_fresh($item);
    $item['sufficientData']=$sufficient;
    return $item;
}

function p50_intelligence_dashboard_v2(): array {
    p50_de_sync_registry_from_state();
    $currentIds=p50_intelligence_current_profile_ids();
    $deactivated=p50_intelligence_sync_removed_profiles($currentIds);
    $raw=p50_intelligence_dashboard();$unique=[];
    foreach(['strongTrends','buzzDetected','declines','buildingSignals'] as $section){
        foreach((array)($raw[$section]??[]) as $item){
            if(!is_array($item)||empty($item['profileId'])||!isset($currentIds[(string)$item['profileId']]))continue;
            $unique[(string)$item['profileId']]=p50_intelligence_sanitize_item($item);
        }
    }
    $items=array_values($unique);
    $trends=array_values(array_filter($items,static fn($i)=>!empty($i['sufficientData'])&&(int)$i['growthIndex']>=55));
    $buzz=array_values(array_filter($items,static fn($i)=>!empty($i['sufficientData'])&&(int)$i['buzzIndex']>=60));
    $declines=array_values(array_filter($items,static fn($i)=>!empty($i['sufficientData'])&&(float)$i['globalVariation']<=-15));
    usort($trends,static fn($a,$b)=>(int)$b['growthIndex']<=>(int)$a['growthIndex']);
    usort($buzz,static fn($a,$b)=>(int)$b['buzzIndex']<=>(int)$a['buzzIndex']);
    usort($declines,static fn($a,$b)=>(float)$a['globalVariation']<=>(float)$b['globalVariation']);
    $shown=[];foreach(array_merge($trends,$buzz,$declines) as $item)$shown[$item['profileId']]=true;
    $building=array_values(array_filter($items,static fn($i)=>empty($shown[$i['profileId']])));
    usort($building,static fn($a,$b)=>strcmp((string)$a['name'],(string)$b['name']));
    $fresh=count(array_filter($items,static fn($i)=>!empty($i['fresh'])));
    $sufficient=count(array_filter($items,static fn($i)=>!empty($i['sufficientData'])));
    return [
        'version'=>P50_INTELLIGENCE_DASHBOARD_V2,
        'generatedAt'=>gmdate(DATE_ATOM),
        'periodLabel'=>'Dernières 24 heures comparées aux 24 heures précédentes',
        'stale'=>$fresh===0,
        'summary'=>['profilesAnalyzed'=>count($items),'profilesFresh'=>$fresh,'profilesTrusted'=>$sufficient,'profilesLowConfidence'=>count($items)-$sufficient,'registryProfilesDeactivated'=>$deactivated],
        'strongTrends'=>array_slice($trends,0,10),'buzzDetected'=>array_slice($buzz,0,10),'declines'=>array_slice($declines,0,10),'buildingSignals'=>array_slice($building,0,20),
    ];
}
