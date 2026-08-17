<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';

const P50_STATE_LINK_PROTECTION_VERSION='PASS50-STATE-LINK-PROTECTION-V4.1';

function p50_state_v4_confirmed_status(string $status): bool {
    return in_array($status,['verified','owner_verified','manual_verified','ok'],true);
}

function p50_state_v4_direct_link(string $platform,string $url): string {
    $normalized=p50_de_normalize_social_url($platform,$url);
    if($normalized==='')return '';
    if(!p50_platform_host_ok($platform,$normalized))return '';
    if(!p50_de_direct_social_path($platform,$normalized))return '';
    return $normalized;
}

function p50_state_v4_authoritative_links(PDO $pdo): array {
    $byProfile=[];
    try {
        $stmt=$pdo->query("SELECT profile_id,platform,normalized_url,source_types,confidence
            FROM p50_social_links
            WHERE status='verified' AND normalized_url<>''
            ORDER BY confidence DESC,profile_id ASC,platform ASC");
        foreach($stmt->fetchAll() as $row){
            $profileId=(string)($row['profile_id']??'');
            $platform=(string)($row['platform']??'');
            $url=p50_state_v4_direct_link($platform,(string)($row['normalized_url']??''));
            if($profileId===''||$platform===''||$url==='')continue;
            if(isset($byProfile[$profileId][$platform]))continue;
            $types=json_decode((string)($row['source_types']??'[]'),true);
            if(!is_array($types))$types=[];
            $owner=in_array('manual_owner',$types,true);
            $byProfile[$profileId][$platform]=[
                'url'=>$url,
                'status'=>$owner?'owner_verified':'manual_verified',
                'message'=>$owner
                    ?'Compte officiel protégé après validation du propriétaire PASS50'
                    :'Compte officiel protégé après validation administrative PASS50',
            ];
        }
    } catch(Throwable $error) {
        error_log('PASS50 state link protection: '.$error->getMessage());
    }
    return $byProfile;
}

function p50_state_v4_profile_map(array $state): array {
    $map=[];
    foreach((array)($state['profiles']??[]) as $profile){
        if(is_array($profile)&&!empty($profile['id']))$map[(string)$profile['id']]=$profile;
    }
    return $map;
}

function p50_state_v4_protect_links(array &$state,array $current=[]): array {
    $authoritative=p50_state_v4_authoritative_links(db());
    $currentProfiles=p50_state_v4_profile_map($current);
    $removed=0;$restored=0;$protected=0;

    foreach((array)($state['profiles']??[]) as $index=>$profile){
        if(!is_array($profile)||empty($profile['id']))continue;
        $profileId=(string)$profile['id'];
        $links=is_array($profile['links']??null)?$profile['links']:[];
        $checks=is_array($profile['linkChecks']??null)?$profile['linkChecks']:[];
        $cleanLinks=[];$cleanChecks=[];

        foreach($links as $platform=>$url){
            $platform=(string)$platform;
            $normalized=p50_state_v4_direct_link($platform,(string)$url);
            if($normalized===''){$removed++;continue;}
            $cleanLinks[$platform]=$normalized;
            if(isset($checks[$platform])&&is_array($checks[$platform]))$cleanChecks[$platform]=$checks[$platform];
        }

        $currentProfile=$currentProfiles[$profileId]??null;
        if(is_array($currentProfile)){
            $currentLinks=is_array($currentProfile['links']??null)?$currentProfile['links']:[];
            $currentChecks=is_array($currentProfile['linkChecks']??null)?$currentProfile['linkChecks']:[];
            foreach($currentLinks as $platform=>$url){
                $platform=(string)$platform;
                $normalized=p50_state_v4_direct_link($platform,(string)$url);
                if($normalized==='')continue;
                $check=is_array($currentChecks[$platform]??null)?$currentChecks[$platform]:[];
                $serverPersisted=!empty($check['persistedServerSide']);
                $confirmed=p50_state_v4_confirmed_status((string)($check['status']??''));
                if(!$serverPersisted&&!$confirmed)continue;
                if(($cleanLinks[$platform]??'')!==$normalized)$restored++;
                $cleanLinks[$platform]=$normalized;
                $cleanChecks[$platform]=array_merge($check,[
                    'persistedServerSide'=>true,
                    'protectedBy'=>P50_STATE_LINK_PROTECTION_VERSION,
                ]);
                $protected++;
            }
        }

        foreach((array)($authoritative[$profileId]??[]) as $platform=>$item){
            $platform=(string)$platform;
            $url=(string)($item['url']??'');
            if($url==='')continue;
            if(($cleanLinks[$platform]??'')!==$url)$restored++;
            $existingCheck=is_array($cleanChecks[$platform]??null)?$cleanChecks[$platform]:[];
            $cleanLinks[$platform]=$url;
            $cleanChecks[$platform]=array_merge($existingCheck,[
                'status'=>(string)($item['status']??'manual_verified'),
                'message'=>(string)($item['message']??'Compte officiel protégé par PASS50'),
                'persistedServerSide'=>true,
                'protectedBy'=>P50_STATE_LINK_PROTECTION_VERSION,
            ]);
            $protected++;
        }

        $profile['links']=$cleanLinks;
        $profile['linkChecks']=$cleanChecks;
        $profile['platforms']=array_values(array_unique(array_merge(
            array_values(array_filter(array_map('strval',(array)($profile['platforms']??[])))),
            array_keys($cleanLinks)
        )));
        $state['profiles'][$index]=$profile;
    }

    $state['officialLinksProtection']=[
        'version'=>4,
        'runtime'=>P50_STATE_LINK_PROTECTION_VERSION,
    ];
    return ['removed'=>$removed,'restored'=>$restored,'protected'=>$protected];
}

if ($_SERVER['REQUEST_METHOD']==='GET') {
    $stmt=db()->query("SELECT data,updated_at FROM app_state WHERE id='public' LIMIT 1");
    $row=$stmt->fetch();
    $data=$row?json_decode((string)$row['data'],true):null;
    $protection=['removed'=>0,'restored'=>0,'protected'=>0];
    $classabilityRestored=0;
    if(is_array($data)){
        $protection=p50_state_v4_protect_links($data,$data);
        $classabilityRestored=p50_de_restore_scored_classability($data);
        if($classabilityRestored>0){
            try{
                $data['stateRevision']=max(0,(int)($data['stateRevision']??0))+1;
                $write=db()->prepare("UPDATE app_state SET data=?,updated_at=NOW() WHERE id='public'");
                $write->execute([json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            }catch(Throwable $error){
                error_log('PASS50 scored classability restore: '.$error->getMessage());
            }
        }
    }
    json_response([
        'ok'=>true,
        'data'=>$data,
        'stateRevision'=>(int)($data['stateRevision']??0),
        'updatedAt'=>$row['updated_at']??null,
        'linkProtectionVersion'=>P50_STATE_LINK_PROTECTION_VERSION,
        'linkProtection'=>$protection,
        'scoredClassabilityRestored'=>$classabilityRestored,
    ]);
}

require_method('POST');
$u=auth_user();
require_role($u,'owner','admin');
$in=json_input();
$data=$in['data']??null;
if(!is_array($data))json_response(['error'=>'État invalide.'],422);

$baseRevision=max(0,(int)($in['baseRevision']??0));
$pdo=db();
$pdo->beginTransaction();
try {
    $stmt=$pdo->query("SELECT data FROM app_state WHERE id='public' LIMIT 1 FOR UPDATE");
    $raw=$stmt->fetchColumn();
    $current=$raw?json_decode((string)$raw,true):[];
    if(!is_array($current))$current=[];
    $currentRevision=max(0,(int)($current['stateRevision']??0));
    if($raw&&$baseRevision<$currentRevision){
        $pdo->rollBack();
        json_response([
            'error'=>'État obsolète : rechargez la version publique avant de synchroniser.',
            'code'=>'stale_state',
            'stateRevision'=>$currentRevision,
        ],409);
    }

    $protection=p50_state_v4_protect_links($data,$current);
    $incoming=$data;
    $incoming['stateRevision']=$currentRevision;
    $currentComparable=$current;
    p50_state_v4_protect_links($currentComparable,$current);
    $currentComparable['stateRevision']=$currentRevision;

    if($raw&&json_encode($incoming,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)===json_encode($currentComparable,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)){
        $pdo->commit();
        json_response([
            'ok'=>true,
            'unchanged'=>true,
            'stateRevision'=>$currentRevision,
            'linkProtectionVersion'=>P50_STATE_LINK_PROTECTION_VERSION,
            'linkProtection'=>$protection,
        ]);
    }

    $nextRevision=$currentRevision+1;
    $data['stateRevision']=$nextRevision;
    $stmt=$pdo->prepare("INSERT INTO app_state(id,data,updated_by) VALUES('public',?,?) ON DUPLICATE KEY UPDATE data=VALUES(data),updated_by=VALUES(updated_by),updated_at=NOW()");
    $stmt->execute([
        json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        $u['id'],
    ]);
    $pdo->commit();
    json_response([
        'ok'=>true,
        'stateRevision'=>$nextRevision,
        'linkProtectionVersion'=>P50_STATE_LINK_PROTECTION_VERSION,
        'linkProtection'=>$protection,
    ]);
} catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    throw $e;
}
