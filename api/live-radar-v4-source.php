<?php
declare(strict_types=1);

const P50_LIVE_V4_PLATFORMS = ['TikTok','YouTube','Instagram','Facebook'];
const P50_LIVE_V4_OFFICIAL_STATUSES = ['verified','owner_verified','manual_verified','ok','blocked_but_exists'];
/** Couverture rolling (scan récent) — distincte du trust gate anti-ghost. */
const P50_LIVE_V4_COVERAGE_REVISION = 'LIVE-COVERAGE-ROLLING-2026-08-12-3';
const P50_LIVE_V4_COVERAGE_WINDOW_SECONDS = 7200;
/** @deprecated Utiliser p50_live_v4_reconfirm_grace_map() — conservé pour compat tests/clients. */
const P50_LIVE_V4_GRACE_MINUTES = ['TikTok'=>12,'YouTube'=>18,'Instagram'=>15,'Facebook'=>15];
const P50_LIVE_V4_CANDIDATE_TTL_MINUTES = 30;
const P50_LIVE_V4_BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const P50_LIVE_V4_MOBILE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

function p50_live_v4_ensure_schema(): void {
    p50_de_ensure_schema();
    db()->exec("CREATE TABLE IF NOT EXISTS p50_live_streams (
        stream_key CHAR(64) CHARACTER SET ascii PRIMARY KEY,
        profile_id VARCHAR(100) NOT NULL,
        platform VARCHAR(32) NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        url TEXT NOT NULL,
        thumbnail_url TEXT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'live',
        source VARCHAR(32) NOT NULL DEFAULT 'automatic',
        confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
        viewers INT UNSIGNED NULL,
        started_at DATETIME NULL,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ended_at DATETIME NULL,
        metadata LONGTEXT NULL,
        INDEX idx_p50_live_active (status,platform,last_seen_at),
        INDEX idx_p50_live_profile (profile_id,platform,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS p50_live_source_health (
        profile_id VARCHAR(100) NOT NULL,
        platform VARCHAR(32) NOT NULL,
        url_hash CHAR(64) CHARACTER SET ascii NOT NULL,
        official_url TEXT NOT NULL,
        last_state VARCHAR(24) NOT NULL DEFAULT 'never_checked',
        consecutive_offline SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        consecutive_unknown SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        last_checked_at DATETIME NULL,
        last_live_at DATETIME NULL,
        response_ms INT UNSIGNED NULL,
        last_error VARCHAR(255) NOT NULL DEFAULT '',
        metadata LONGTEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (profile_id,platform),
        INDEX idx_p50_live_health_state (last_state,last_checked_at),
        INDEX idx_p50_live_health_platform (platform,last_checked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function p50_live_v4_iso(?string $mysql): ?string {
    if (!$mysql) return null;
    try { return (new DateTimeImmutable($mysql,new DateTimeZone('UTC')))->format(DATE_ATOM); }
    catch (Throwable) { return null; }
}

function p50_live_v4_bool_query(string $key): bool {
    return isset($_GET[$key]) && in_array(strtolower((string)$_GET[$key]),['1','true','yes','on'],true);
}

function p50_live_v4_direct_url(string $platform,string $url): bool {
    $url=trim($url);
    if($url===''||p50_platform($url)!==$platform)return false;
    $parts=parse_url($url);$path=(string)($parts['path']??'');
    return match($platform){
        'TikTok'=>(bool)preg_match('#/@[A-Za-z0-9._-]+(?:/live)?/?$#',$path),
        'YouTube'=>!preg_match('#/(results|search)(?:/|$)#i',$path)&&(bool)preg_match('#/(?:@[^/]+|channel/[^/]+|c/[^/]+|user/[^/]+|watch|live)(?:/|$)#i',$path),
        'Instagram'=>(bool)preg_match('#^/[A-Za-z0-9._-]+/?$#',$path)&&!preg_match('#^/(explore|accounts|reels?|stories|direct|developer|about|privacy|legal)(?:/|$)#i',$path),
        'Facebook'=>!preg_match('#^/(login|watch|groups|marketplace|gaming|events|reels?|share|sharer)(?:/|$)#i',$path)&&trim($path,'/')!=='',
        default=>false,
    };
}

function p50_live_v4_identity(string $platform,string $url): array {
    $parts=parse_url(trim($url));$path=(string)($parts['path']??'');
    if($platform==='TikTok'&&preg_match('#/@([A-Za-z0-9._-]+)#',$path,$m)){
        $handle=$m[1];
        // Comptes TikTok morts / remplacés connus.
        $aliases=[
            'generalmakossocamille1'=>'generalmakossocamille79',
            'generalcamillemakosso'=>'generalmakossocamille79',
            'apoutchou.225'=>'apoutchou_national1',
            'apoutchounational'=>'apoutchou_national1',
        ];
        $handle=$aliases[strtolower($handle)]??$handle;
        $profile='https://www.tiktok.com/@'.$handle;
        return ['handle'=>$handle,'profileUrl'=>$profile,'liveUrl'=>$profile.'/live'];
    }
    if($platform==='Instagram'&&preg_match('#^/([A-Za-z0-9._-]+)/?#',$path,$m)){
        $handle=$m[1];$profile='https://www.instagram.com/'.$handle.'/';
        return ['handle'=>$handle,'profileUrl'=>$profile,'liveUrl'=>$profile.'live/'];
    }
    if($platform==='Facebook'){
        $base='https://www.facebook.com/'.trim($path,'/');
        if(!empty($parts['query']))$base.='?'.$parts['query'];
        return ['handle'=>'','profileUrl'=>$base,'liveUrl'=>rtrim($base,'/').'/live/'];
    }
    return ['handle'=>'','profileUrl'=>$url,'liveUrl'=>$url];
}

function p50_live_v4_youtube_live_url(string $url): string {
    $parts=parse_url($url);
    if(!$parts||empty($parts['host']))return '';
    $scheme=(string)($parts['scheme']??'https');$host=(string)$parts['host'];$path=rtrim((string)($parts['path']??''),'/');
    if(str_contains(strtolower($host),'youtu.be'))return $url;
    if(preg_match('#/(watch|shorts|embed|live)(?:/|$)#i',$path)||!empty($parts['query']))return $url;
    $path=preg_replace('#/(featured|videos|shorts|streams|about|community)$#i','',$path)??$path;
    return $path===''?'':$scheme.'://'.$host.rtrim($path,'/').'/live';
}

function p50_live_v4_active_auto_ids(): array {
    $out=[];
    try{
        // Uniquement les directs live : les unconfirmed ne doivent pas saturer le quick scan.
        $stmt=db()->query("SELECT profile_id,platform FROM p50_live_streams WHERE source='automatic' AND status='live'");
        foreach($stmt->fetchAll() as $row)$out[(string)$row['platform'].'|'.(string)$row['profile_id']]=true;
    }catch(Throwable){}
    return $out;
}

function p50_live_v4_health_map(): array {
    $out=[];
    try{
        $stmt=db()->query('SELECT profile_id,platform,last_state,last_checked_at,last_live_at,consecutive_offline,consecutive_unknown,metadata FROM p50_live_source_health');
        foreach($stmt->fetchAll() as $row)$out[(string)$row['platform'].'|'.(string)$row['profile_id']]=$row;
    }catch(Throwable){}
    return $out;
}

function p50_live_v4_manual_priority_ids(array $state): array {
    $ids=[];$now=time();
    foreach((array)($state['liveStreams']??[]) as $live){
        if(!is_array($live)||($live['source']??'')!=='manual'||str_starts_with((string)($live['id']??''),'auto_')||($live['status']??'')!=='live'||empty($live['profileId']))continue;
        $ends=strtotime((string)($live['endsAt']??''));if($ends===false||$ends<=$now)continue;
        $ids[(string)$live['profileId']]=true;
    }
    return $ids;
}

function p50_live_v4_official_url_override(string $profileId,string $platform,string $url): string {
    $key=strtolower(trim($profileId)).'|'.strtolower(trim($platform));
    $overrides=[
        'apoutchou|tiktok'=>'https://www.tiktok.com/@apoutchou_national1',
        'general-camille-makosso|tiktok'=>'https://www.tiktok.com/@generalmakossocamille79',
    ];
    return $overrides[$key]??$url;
}

function p50_live_v4_sources(array $state): array {
    $threshold=p50_de_threshold();$seen=[];$out=[];
    try{
        $stmt=db()->prepare("SELECT r.profile_id,r.public_name,r.handle,s.platform,s.normalized_url url,s.confidence,'verified' verification_status
            FROM p50_profile_registry r JOIN p50_social_links s ON s.profile_id=r.profile_id
            WHERE r.alive=1 AND s.platform IN ('TikTok','YouTube','Instagram','Facebook') AND s.status='verified' AND s.confidence>=?");
        $stmt->execute([$threshold]);
        foreach($stmt->fetchAll() as $row){
            $platform=(string)$row['platform'];$id=(string)$row['profile_id'];
            $row['url']=p50_live_v4_official_url_override($id,$platform,trim((string)$row['url']));
            $url=trim((string)$row['url']);$key=$platform.'|'.$id;
            if(isset($seen[$key])||!p50_live_v4_direct_url($platform,$url))continue;
            $seen[$key]=true;$out[]=$row;
        }
    }catch(Throwable){}
    foreach((array)($state['profiles']??[]) as $profile){
        if(!is_array($profile)||empty($profile['id'])||(array_key_exists('alive',$profile)&&empty($profile['alive'])))continue;
        foreach(P50_LIVE_V4_PLATFORMS as $platform){
            $id=(string)$profile['id'];$key=$platform.'|'.$id;if(isset($seen[$key]))continue;
            $url=trim((string)(($profile['links']??[])[$platform]??''));
            $url=p50_live_v4_official_url_override($id,$platform,$url);
            $status=(string)(($profile['linkChecks']??[])[$platform]['status']??'');
            if(!in_array($status,P50_LIVE_V4_OFFICIAL_STATUSES,true)||!p50_live_v4_direct_url($platform,$url))continue;
            $seen[$key]=true;$out[]=[
                'profile_id'=>$id,'public_name'=>(string)($profile['name']??$id),'handle'=>(string)($profile['handle']??''),
                'platform'=>$platform,'url'=>$url,'confidence'=>in_array($status,['owner_verified','manual_verified','verified'],true)?98:94,
                'verification_status'=>$status,
            ];
        }
    }
    // Lien officiel confirmé manuellement par PASS50 : El Profesor.
    // Ce fallback évite qu'un retard de synchronisation du registre empêche le radar de sonder son TikTok.
    $elProfesorId='census-el-profesor';$elProfesorName='El Profesor';
    foreach((array)($state['profiles']??[]) as $profile){
        if(!is_array($profile)||empty($profile['id']))continue;
        $name=strtolower(trim((string)($profile['name']??'')));
        $handle=strtolower(trim((string)($profile['handle']??'')));
        $tt=strtolower(trim((string)(($profile['links']??[])['TikTok']??'')));
        if($name==='el profesor'||str_contains($handle,'elprofesor_off')||str_contains($tt,'@elprofesor_off')){
            $elProfesorId=(string)$profile['id'];$elProfesorName=(string)($profile['name']??'El Profesor');break;
        }
    }
    $elProfesorKey='TikTok|'.$elProfesorId;
    if(!isset($seen[$elProfesorKey])){
        $seen[$elProfesorKey]=true;$out[]=[
            'profile_id'=>$elProfesorId,'public_name'=>$elProfesorName,'handle'=>'@elprofesor_off',
            'platform'=>'TikTok','url'=>'https://www.tiktok.com/@elprofesor_off','confidence'=>100,
            'verification_status'=>'manual_verified',
        ];
    }
    $manual=p50_live_v4_manual_priority_ids($state);$automatic=p50_live_v4_active_auto_ids();$health=p50_live_v4_health_map();
    $platformOrder=['TikTok'=>0,'YouTube'=>1,'Instagram'=>2,'Facebook'=>3];
    foreach($out as &$source){
        $id=(string)$source['profile_id'];$platform=(string)$source['platform'];$key=$platform.'|'.$id;$status=(string)($source['verification_status']??'');
        $source['source_key']=$key;
        $source['priority']=isset($manual[$id])?0:(isset($automatic[$key])?1:(in_array($status,['owner_verified','manual_verified','verified'],true)?2:3));
        $source['last_checked_at']=(string)($health[$key]['last_checked_at']??'');
        $source['last_live_at']=(string)($health[$key]['last_live_at']??'');
        $source['last_state']=(string)($health[$key]['last_state']??'never_checked');
        $metaJson=json_decode((string)($health[$key]['metadata']??''),true);
        $probe='';
        if(is_array($metaJson)){
            $probe=(string)(($metaJson['evidence']['probe']??'')?:'');
            if($probe===''&&isset($metaJson['probes']['meta_graph']))$probe='meta_graph';
        }
        $source['health_probe']=$probe;
        $source['platform_order']=$platformOrder[$platform]??9;
    }
    unset($source);
    usort($out,static function(array $a,array $b): int {
        $cmp=((int)$a['priority'])<=>((int)$b['priority']);if($cmp!==0)return $cmp;
        $rankCmp=p50_live_v4_discovery_rank($a)<=>p50_live_v4_discovery_rank($b);
        if($rankCmp!==0)return $rankCmp;
        $ad=(string)$a['last_checked_at'];$bd=(string)$b['last_checked_at'];
        if($ad!==$bd){if($ad==='')return -1;if($bd==='')return 1;return strcmp($ad,$bd);}
        $cmp=((int)$a['platform_order'])<=>((int)$b['platform_order']);
        return $cmp!==0?$cmp:strnatcasecmp((string)$a['public_name'],(string)$b['public_name']);
    });
    return $out;
}

function p50_live_v4_health_ts(?string $mysql): int {
    $value=trim((string)$mysql);
    if($value==='')return 0;
    try{return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->getTimestamp();}
    catch(Throwable){return strtotime($value.' UTC')?:0;}
}

function p50_live_v4_is_verified_tiktok(array $source): bool {
    if((string)($source['platform']??'')!=='TikTok')return false;
    return in_array((string)($source['verification_status']??''),['owner_verified','manual_verified','verified'],true);
}

/** TikTok vérifié : rescanner si offline depuis >20 min (évite les trous de 2 h). */
function p50_live_v4_needs_tiktok_rescan(array $source,int $minStaleSeconds=1200): bool {
    if(!p50_live_v4_is_verified_tiktok($source))return false;
    $state=strtolower(trim((string)($source['last_state']??'')));
    if($state==='live')return true;
    $checkedTs=p50_live_v4_health_ts((string)($source['last_checked_at']??''));
    return $checkedTs<=0||(time()-$checkedTs)>=$minStaleSeconds;
}

/** Compte live récemment (72 h) : rescan prioritaire même si marqué offline. */
function p50_live_v4_is_warm_watch(array $source,int $maxAgeSeconds=259200): bool {
    if(!p50_live_v4_is_verified_tiktok($source))return false;
    $lastLiveTs=p50_live_v4_health_ts((string)($source['last_live_at']??''));
    return $lastLiveTs>0&&(time()-$lastLiveTs)<=$maxAgeSeconds;
}

/** Ordre de découverte : never_checked → TikTok vérifié / warm → unknown → offline générique. */
function p50_live_v4_discovery_rank(array $source): array {
    $state=strtolower(trim((string)($source['last_state']??'never_checked')));
    $checked=(string)($source['last_checked_at']??'');
    $platform=(string)($source['platform']??'');
    $meta=in_array($platform,['Instagram','Facebook'],true)?0:1;
    if($checked===''||$state===''||$state==='never_checked')return [0,$meta,''];
    if(p50_live_v4_is_warm_watch($source)||p50_live_v4_needs_tiktok_rescan($source))return [1,0,$checked];
    if($state==='unknown')return [2,$meta,$checked];
    if(p50_live_v4_is_verified_tiktok($source))return [3,0,$checked];
    return [4,$meta,$checked];
}

/** Source Meta déjà classifiée récemment via Graph OAuth — inutile de rescraper. */
function p50_live_v4_is_graph_fresh(array $source,int $maxAgeSeconds=1200): bool {
    if(!in_array((string)($source['platform']??''),['Instagram','Facebook'],true))return false;
    if((string)($source['health_probe']??'')!=='meta_graph')return false;
    $state=strtolower(trim((string)($source['last_state']??'')));
    if(!in_array($state,['live','offline'],true))return false;
    $checkedAt=trim((string)($source['last_checked_at']??''));
    if($checkedAt==='')return false;
    try{$ts=(new DateTimeImmutable($checkedAt,new DateTimeZone('UTC')))->getTimestamp();}
    catch(Throwable){$ts=strtotime($checkedAt.' UTC')?:0;}
    return $ts>0&&(time()-$ts)<=$maxAgeSeconds;
}

/** Couverture rolling : sources sondées dans la fenêtre / total officiel. */
function p50_live_v4_coverage_stats(array $sources,int $windowSeconds=P50_LIVE_V4_COVERAGE_WINDOW_SECONDS): array {
    $now=time();$checkedRecent=0;$classifiedRecent=0;$unknownRecent=0;$neverChecked=0;
    foreach($sources as $source){
        $state=strtolower(trim((string)($source['last_state']??'never_checked')));
        $checkedAt=trim((string)($source['last_checked_at']??''));
        $ts=0;
        if($checkedAt!==''){
            try{$ts=(new DateTimeImmutable($checkedAt,new DateTimeZone('UTC')))->getTimestamp();}
            catch(Throwable){$ts=strtotime($checkedAt.' UTC')?:0;}
        }
        if($ts<=0||($now-$ts)>$windowSeconds){
            if($checkedAt===''||$state==='never_checked')$neverChecked++;
            continue;
        }
        $checkedRecent++;
        if($state==='unknown')$unknownRecent++;
        elseif(in_array($state,['live','offline','replay','probable'],true))$classifiedRecent++;
    }
    $total=count($sources);
    return [
        'windowSeconds'=>$windowSeconds,
        'checkedRecent'=>$checkedRecent,
        'classifiedRecent'=>$classifiedRecent,
        'unknownRecent'=>$unknownRecent,
        'neverChecked'=>$neverChecked,
        'coveragePercent'=>$total>0?(int)round($checkedRecent*100/$total):100,
        'classifiedPercent'=>$total>0?(int)round($classifiedRecent*100/$total):100,
    ];
}

function p50_live_v4_probe_requests(array $source): array {
    $platform=(string)$source['platform'];$identity=p50_live_v4_identity($platform,(string)$source['url']);
    if($platform==='YouTube'){
        $live=p50_live_v4_youtube_live_url((string)$source['url']);
        return $live!==''?['live'=>['url'=>$live,'accept'=>'text/html,*/*;q=0.7']]:[];
    }
    if($platform==='TikTok'&&$identity['handle']!==''){
        $handle=rawurlencode($identity['handle']);
        $tiktokApiHeaders=[
            'Referer: '.$identity['profileUrl'],
            'Origin: https://www.tiktok.com',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
        ];
        return [
            'api'=>['url'=>'https://www.tiktok.com/api-live/user/room/?aid=1988&sourceType=54&uniqueId='.$handle,'accept'=>'application/json,text/plain,*/*','headers'=>$tiktokApiHeaders],
            'api_basic'=>['url'=>'https://www.tiktok.com/api-live/user/room/?aid=1988&uniqueId='.$handle,'accept'=>'application/json,text/plain,*/*','headers'=>$tiktokApiHeaders],
            'mobile_live'=>['url'=>'https://m.tiktok.com/@'.$handle.'/live','accept'=>'text/html,application/xhtml+xml,*/*;q=0.7','userAgent'=>P50_LIVE_V4_MOBILE_UA,'headers'=>['Referer: https://www.tiktok.com/']],
            'live'=>['url'=>$identity['liveUrl'].'?lang=fr','accept'=>'text/html,application/xhtml+xml,*/*;q=0.7','headers'=>['Referer: '.$identity['profileUrl']]],
            'embed'=>['url'=>'https://www.tiktok.com/embed/live/@'.$handle.'?autoplay=0&muted=1&controls=1&embed_domain=pass50.store','accept'=>'text/html,application/xhtml+xml,*/*;q=0.7','headers'=>['Referer: https://www.tiktok.com/']],
            'profile'=>['url'=>$identity['profileUrl'].'?lang=fr','accept'=>'text/html,application/xhtml+xml,*/*;q=0.7','headers'=>['Referer: https://www.tiktok.com/']],
        ];
    }
    if($platform==='Instagram'&&$identity['handle']!==''){
        $handle=rawurlencode($identity['handle']);
        return [
            'web_profile'=>[
                'url'=>'https://www.instagram.com/api/v1/users/web_profile_info/?username='.$handle,
                'accept'=>'application/json,text/plain,*/*',
                'headers'=>[
                    'X-IG-App-ID: 936619743392459',
                    'X-Requested-With: XMLHttpRequest',
                    'Referer: '.$identity['profileUrl'],
                    'Origin: https://www.instagram.com',
                ],
            ],
            'profile'=>['url'=>$identity['profileUrl'].'?hl=fr','accept'=>'text/html,application/xhtml+xml,*/*;q=0.7'],
            'profile_mobile'=>['url'=>$identity['profileUrl'].'?hl=fr','accept'=>'text/html,application/xhtml+xml,*/*;q=0.7','userAgent'=>P50_LIVE_V4_MOBILE_UA],
            'live'=>['url'=>$identity['liveUrl'],'accept'=>'text/html,application/xhtml+xml,*/*;q=0.7'],
        ];
    }
    if($platform==='Facebook'){
        $profile=rtrim($identity['profileUrl'],'/');
        return [
            'live'=>['url'=>$identity['liveUrl'],'accept'=>'text/html,application/xhtml+xml,*/*;q=0.7'],
            'videos'=>['url'=>$profile.'/videos/','accept'=>'text/html,application/xhtml+xml,*/*;q=0.7'],
            'live_videos'=>['url'=>$profile.'/live_videos/','accept'=>'text/html,application/xhtml+xml,*/*;q=0.7'],
            'mobile'=>['url'=>str_replace('www.facebook.com','m.facebook.com',$identity['profileUrl']),'accept'=>'text/html,application/xhtml+xml,*/*;q=0.7','userAgent'=>P50_LIVE_V4_MOBILE_UA],
            'mbasic'=>['url'=>str_replace('www.facebook.com','mbasic.facebook.com',$identity['profileUrl']),'accept'=>'text/html,application/xhtml+xml,*/*;q=0.7','userAgent'=>P50_LIVE_V4_MOBILE_UA],
        ];
    }
    return [];
}

function p50_live_v4_platform_referer(string $url): array {
    $host=strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));
    if(str_contains($host,'tiktok.com'))return ['referer'=>'https://www.tiktok.com/','origin'=>'https://www.tiktok.com'];
    if(str_contains($host,'instagram.com'))return ['referer'=>'https://www.instagram.com/','origin'=>'https://www.instagram.com'];
    if(str_contains($host,'facebook.com'))return ['referer'=>'https://www.facebook.com/','origin'=>'https://www.facebook.com'];
    if(str_contains($host,'youtube.com')||str_contains($host,'youtu.be'))return ['referer'=>'https://www.youtube.com/','origin'=>'https://www.youtube.com'];
    return ['referer'=>'https://www.google.com/','origin'=>''];
}

function p50_live_v4_parallel_fetch(array $jobs,int $timeout=8): array {
    if(!$jobs)return [];
    $multi=curl_multi_init();$handles=[];$results=[];
    if(defined('CURLMOPT_MAX_TOTAL_CONNECTIONS'))@curl_multi_setopt($multi,CURLMOPT_MAX_TOTAL_CONNECTIONS,20);
    foreach($jobs as $jobId=>$job){
        $url=(string)$job['url'];
        if(!p50_public_http_url($url)){$results[$jobId]=['ok'=>false,'status'=>0,'body'=>'','finalUrl'=>$url,'error'=>'invalid_url','timeMs'=>0];continue;}
        $accept=(string)($job['accept']??'text/html,*/*;q=0.7');
        $isJson=stripos($accept,'application/json')!==false;
        $site=p50_live_v4_platform_referer($url);
        $headers=[
            'Accept: '.$accept,
            'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache','Pragma: no-cache','DNT: 1',
            'Referer: '.$site['referer'],
            'sec-ch-ua: "Chromium";v="126", "Google Chrome";v="126", "Not.A/Brand";v="99"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'Sec-Fetch-Dest: '.($isJson?'empty':'document'),
            'Sec-Fetch-Mode: '.($isJson?'cors':'navigate'),
            'Sec-Fetch-Site: '.($isJson?'same-origin':'none'),
            'Upgrade-Insecure-Requests: 1',
        ];
        if($site['origin']!==''&&$isJson)$headers[]='Origin: '.$site['origin'];
        if(!empty($job['headers'])&&is_array($job['headers'])){
            foreach($job['headers'] as $header){
                $header=trim((string)$header);
                if($header==='')continue;
                $name=strtok($header,':');
                if($name!==false){
                    $headers=array_values(array_filter($headers,static fn($existing)=>stripos($existing,$name.':')!==0));
                }
                $headers[]=$header;
            }
        }
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>min(4,$timeout),CURLOPT_ENCODING=>'',
            CURLOPT_USERAGENT=>(string)($job['userAgent']??P50_LIVE_V4_BROWSER_UA),
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_HEADER=>false,
        ]);
        $handles[(int)$ch]=['handle'=>$ch,'id'=>$jobId,'url'=>$url,'job'=>$job];curl_multi_add_handle($multi,$ch);
    }
    do{$status=curl_multi_exec($multi,$active);if($active)curl_multi_select($multi,0.35);}while($active&&$status===CURLM_OK);
    $retryJobs=[];
    foreach($handles as $item){
        $ch=$item['handle'];$body=curl_multi_getcontent($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $ok=is_string($body)&&$http>=200&&$http<400;$text=is_string($body)?$body:'';
        $blocked=$ok&&$text!==''&&function_exists('p50_live_v4_block_page')&&p50_live_v4_block_page($text);
        $results[$item['id']]=['ok'=>$ok&&!$blocked,'status'=>$http,'body'=>$blocked?'':$text,'finalUrl'=>(string)(curl_getinfo($ch,CURLINFO_EFFECTIVE_URL)?:$item['url']),'error'=>$blocked?'blocked_or_challenged':curl_error($ch),'timeMs'=>(int)round(((float)curl_getinfo($ch,CURLINFO_TOTAL_TIME))*1000)];
        if((!$ok||$blocked)&&empty($item['job']['userAgent'])){
            $retry=$item['job'];$retry['userAgent']=P50_LIVE_V4_MOBILE_UA;$retryJobs[$item['id']]=$retry;
        }
        curl_multi_remove_handle($multi,$ch);curl_close($ch);
    }
    curl_multi_close($multi);
    if($retryJobs){
        foreach(p50_live_v4_parallel_fetch_once($retryJobs,$timeout) as $id=>$retryResult){
            if(!empty($retryResult['ok'])&&(string)($retryResult['body']??'')!=='')$results[$id]=$retryResult;
        }
    }
    return $results;
}

/** Une seule passe curl (sans retry) — utilisée par le retry mobile. */
function p50_live_v4_parallel_fetch_once(array $jobs,int $timeout=8): array {
    if(!$jobs)return [];
    $multi=curl_multi_init();$handles=[];$results=[];
    foreach($jobs as $jobId=>$job){
        $url=(string)$job['url'];
        if(!p50_public_http_url($url)){$results[$jobId]=['ok'=>false,'status'=>0,'body'=>'','finalUrl'=>$url,'error'=>'invalid_url','timeMs'=>0];continue;}
        $accept=(string)($job['accept']??'text/html,*/*;q=0.7');
        $isJson=stripos($accept,'application/json')!==false;
        $site=p50_live_v4_platform_referer($url);
        $headers=[
            'Accept: '.$accept,
            'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache','Pragma: no-cache','DNT: 1',
            'Referer: '.$site['referer'],
            'Sec-Fetch-Dest: '.($isJson?'empty':'document'),
            'Sec-Fetch-Mode: '.($isJson?'cors':'navigate'),
            'Sec-Fetch-Site: '.($isJson?'same-origin':'none'),
        ];
        if($site['origin']!==''&&$isJson)$headers[]='Origin: '.$site['origin'];
        if(!empty($job['headers'])&&is_array($job['headers'])){
            foreach($job['headers'] as $header){
                $header=trim((string)$header);if($header==='')continue;
                $name=strtok($header,':');
                if($name!==false)$headers=array_values(array_filter($headers,static fn($existing)=>stripos($existing,$name.':')!==0));
                $headers[]=$header;
            }
        }
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>min(4,$timeout),CURLOPT_ENCODING=>'',
            CURLOPT_USERAGENT=>(string)($job['userAgent']??P50_LIVE_V4_MOBILE_UA),
            CURLOPT_HTTPHEADER=>$headers,CURLOPT_HEADER=>false,
        ]);
        $handles[(int)$ch]=['handle'=>$ch,'id'=>$jobId,'url'=>$url];curl_multi_add_handle($multi,$ch);
    }
    do{$status=curl_multi_exec($multi,$active);if($active)curl_multi_select($multi,0.35);}while($active&&$status===CURLM_OK);
    foreach($handles as $item){
        $ch=$item['handle'];$body=curl_multi_getcontent($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $ok=is_string($body)&&$http>=200&&$http<400;$text=is_string($body)?$body:'';
        $blocked=$ok&&$text!==''&&function_exists('p50_live_v4_block_page')&&p50_live_v4_block_page($text);
        $results[$item['id']]=['ok'=>$ok&&!$blocked,'status'=>$http,'body'=>$blocked?'':$text,'finalUrl'=>(string)(curl_getinfo($ch,CURLINFO_EFFECTIVE_URL)?:$item['url']),'error'=>$blocked?'blocked_or_challenged':curl_error($ch),'timeMs'=>(int)round(((float)curl_getinfo($ch,CURLINFO_TOTAL_TIME))*1000)];
        curl_multi_remove_handle($multi,$ch);curl_close($ch);
    }
    curl_multi_close($multi);return $results;
}
