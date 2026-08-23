<?php
declare(strict_types=1);

const P50_LIVE_V4_PLATFORMS = ['TikTok','YouTube','Instagram','Facebook'];
const P50_LIVE_V4_OFFICIAL_STATUSES = ['verified','owner_verified','manual_verified','ok','blocked_but_exists'];
/**
 * RÈGLE FIGÉE — autonomie radar LIVE (ne pas affaiblir).
 * Détection serveur 24/7, tick 1 s, jamais conditionnée à l’app ouverte.
 */
const P50_LIVE_RADAR_AUTONOMY_REVISION = 'PASS50_LIVE_RADAR_AUTONOMY_V1';
const P50_LIVE_RADAR_REQUIRES_APP_OPEN = false;
const P50_LIVE_RADAR_CONTINUOUS_TICK_SECONDS = 1;
const P50_LIVE_RADAR_DETECTION_OWNER = 'server';
/** Couverture rolling (scan récent) — distincte du trust gate anti-ghost. */
const P50_LIVE_V4_COVERAGE_REVISION = 'LIVE-COVERAGE-ROLLING-2026-08-12-3';
const P50_LIVE_V4_COVERAGE_WINDOW_SECONDS = 7200;
/** TikTok à rescanner toutes les ~2 min. */
const P50_LIVE_V4_P0_TIKTOK = [
    'apoutchou',
    'general-camille-makosso',
    'census-no-limit',
    'census-amour-ruth-poopy',
    'census-jordan-evraa',
    'dbz',
    'maabio',
    'census-el-profesor',
    'census-adjinaya-el-professor',
    'p_1785175190809',
    'census-sarara-messan',
    'louissette',
    'aya-robert',
    'hamondchic',
    'coachhamond',
    'coachhamondchic',
    'dez-cocrane225',
    'census-roseline-layo',
    'census-rach-makosso',
    'census-jp-nda',
    'census-cahie-kunta',
    'census-lise-akrassi',
    'census-lexes',
    'census-ange-morel',
    'census-laguepe',
    'census-rosemark-marcel',
    'census-jiaan-wu',
    'census-samuella-kouassi',
    'oustaz-diane',
    'census-daniel-m',
    'census-akalajoie',
    'ennemi-des-djandjou',
    'census-isouch',
    'census-bb-sans-os-de-man',
    'hassan',
    'census-le-grand-bicongo',
    'census-chocolat-show-officiel',
    'census-la-legende',
];
/** YouTube à rescanner au même rythme P0. */
const P50_LIVE_V4_P0_YOUTUBE = [
    'census-observateur-ebene',
    'census-rosemark-marcel',
    'census-jiaan-wu',
    'oustaz-diane',
    'census-daniel-m',
];
/** Délai minimum entre deux sondes TikTok vérifié (secondes). */
const P50_LIVE_V4_P0_RESCAN_SECONDS = 120;
const P50_LIVE_V4_VERIFIED_RESCAN_SECONDS = 300;
/** TikTok unknown (probe bloqué / incomplet) : même rythme que le P0. */
const P50_LIVE_V4_UNKNOWN_RESCAN_SECONDS = 120;
/** @deprecated Utiliser p50_live_v4_reconfirm_grace_map() — conservé pour compat tests/clients. */
const P50_LIVE_V4_GRACE_MINUTES = ['TikTok'=>40,'YouTube'=>25,'Instagram'=>15,'Facebook'=>15];
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

/** Un handle TikTok officiel ne doit alimenter qu’une seule fiche. */
function p50_live_v4_tiktok_handle_canonicals(): array {
    return [
        'samuellakouassiofficiel'=>'census-samuella-kouassi',
        'ennemidesdjandjou'=>'ennemi-des-djandjou',
        'prince_du_pays'=>'census-isouch',
        'bebe.sans.os.de.m'=>'census-bb-sans-os-de-man',
        'bebe_sans_os'=>'census-bb-sans-os-de-man',
        'hassanhayekofficiel'=>'hassan',
        'hassanhayek'=>'hassan',
        'legrandbicongo'=>'census-le-grand-bicongo',
        'chocolat.show.officiel'=>'census-chocolat-show-officiel',
        'lalegende777'=>'census-la-legende',
    ];
}

function p50_live_v4_canonical_profile_id(string $profileId,string $platform,string $handleOrUrl=''): string {
    if(strcasecmp($platform,'TikTok')!==0)return $profileId;
    $handle=strtolower(trim($handleOrUrl,'@'));
    if($handle!==''&&(str_contains($handle,'tiktok.com')||str_starts_with($handle,'http'))){
        $identity=p50_live_v4_identity('TikTok',$handleOrUrl);
        $handle=strtolower(trim((string)($identity['handle']??''),'@'));
    }
    $handle=strtolower(trim($handle,'@'));
    $map=p50_live_v4_tiktok_handle_canonicals();
    return $map[$handle]??$profileId;
}

function p50_live_v4_prefer_source(array $a,array $b): array {
    $aP0=p50_live_v4_is_p0_source($a)?1:0;
    $bP0=p50_live_v4_is_p0_source($b)?1:0;
    if($aP0!==$bP0)return $aP0>$bP0?$a:$b;
    $generic=static function(array $source): bool {
        $name=trim((string)($source['public_name']??''));
        return $name===''||strcasecmp($name,'Influenceur')===0;
    };
    $aGeneric=$generic($a);$bGeneric=$generic($b);
    if($aGeneric!==$bGeneric)return $aGeneric?$b:$a;
    return ((int)($b['confidence']??0))>((int)($a['confidence']??0))?$b:$a;
}

function p50_live_v4_collapse_identity_sources(array $sources): array {
    $kept=[];
    foreach($sources as $source){
        if(!is_array($source))continue;
        $platform=(string)($source['platform']??'');
        $url=(string)($source['url']??'');
        $id=(string)($source['profile_id']??'');
        $identity=p50_live_v4_identity($platform,$url);
        $handle=strtolower(trim((string)($identity['handle']??''),'@'));
        if($handle!==''){
            $canonical=p50_live_v4_canonical_profile_id($id,$platform,$handle);
            if($canonical!==$id){
                $source['profile_id']=$canonical;
                if($canonical==='census-samuella-kouassi'){
                    $source['public_name']='Samuella Kouassi';
                    $source['handle']='@samuellakouassiofficiel';
                }
                if($canonical==='census-bb-sans-os-de-man'){
                    $source['public_name']='BB Sans Os de Man';
                    $source['handle']='@bebe.sans.os.de.m';
                }
                if($canonical==='hassan'){
                    $source['public_name']='Hassan Hayek';
                    $source['handle']='@hassanhayekofficiel';
                }
                if($canonical==='census-le-grand-bicongo'){
                    $source['public_name']='Le grand Bicongo';
                    $source['handle']='@legrandbicongo';
                }
                if($canonical==='census-chocolat-show-officiel'){
                    $source['public_name']='Chocolat show officiel';
                    $source['handle']='@chocolat.show.officiel';
                }
                if($canonical==='census-la-legende'){
                    $source['public_name']='La légende';
                    $source['handle']='@lalegende777';
                }
                $id=$canonical;
            }
        }
        $key=$handle!==''?strtolower($platform).'|h:'.$handle:strtolower($platform).'|id:'.strtolower($id);
        if(!isset($kept[$key])){$kept[$key]=$source;continue;}
        $kept[$key]=p50_live_v4_prefer_source($kept[$key],$source);
    }
    return array_values($kept);
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
        'census-no-limit|tiktok'=>'https://www.tiktok.com/@nolimit_vousdv',
        'census-amour-ruth-poopy|tiktok'=>'https://www.tiktok.com/@amourruth0',
        'census-jordan-evraa|tiktok'=>'https://www.tiktok.com/@realjordanevraa',
        'dbz|tiktok'=>'https://www.tiktok.com/@dbz.07',
        'maabio|tiktok'=>'https://www.tiktok.com/@biodetoxminceur',
        'census-el-profesor|tiktok'=>'https://www.tiktok.com/@elprofesor_off',
        'census-adjinaya-el-professor|tiktok'=>'https://www.tiktok.com/@elprofesor.off',
        'p_1785175190809|tiktok'=>'https://www.tiktok.com/@ulrich_jordan30',
        'census-sarara-messan|tiktok'=>'https://www.tiktok.com/@sarra_messan',
        'louissette|tiktok'=>'https://www.tiktok.com/@misscadic',
        'aya-robert|tiktok'=>'https://www.tiktok.com/@aya.robert27',
        'hamondchic|tiktok'=>'https://www.tiktok.com/@coachhamond',
        'coachhamond|tiktok'=>'https://www.tiktok.com/@coachhamond',
        'coachhamondchic|tiktok'=>'https://www.tiktok.com/@coachhamond',
        'coach-hamond|tiktok'=>'https://www.tiktok.com/@coachhamond',
        'dez-cocrane225|tiktok'=>'https://www.tiktok.com/@dezcocrane.225',
        'census-roseline-layo|tiktok'=>'https://www.tiktok.com/@roselinelayoofficiel',
        'census-rach-makosso|tiktok'=>'https://www.tiktok.com/@rach_makosso1',
        'census-jp-nda|tiktok'=>'https://www.tiktok.com/@jpnda_1',
        'census-cahie-kunta|tiktok'=>'https://www.tiktok.com/@cahiekunta',
        'census-lise-akrassi|tiktok'=>'https://www.tiktok.com/@lise.akrassi.offi',
        'census-lexes|tiktok'=>'https://www.tiktok.com/@stephanesacre',
        'census-ange-morel|tiktok'=>'https://www.tiktok.com/@angemorel4',
        'census-laguepe|tiktok'=>'https://www.tiktok.com/@laguepe03',
        'census-rosemark-marcel|tiktok'=>'https://www.tiktok.com/@rosemarkmarcel',
        'census-rosemark-marcel|youtube'=>'https://www.youtube.com/@RosemarkMarcelOfficiel',
        'census-rosemark-marcel|facebook'=>'https://www.facebook.com/p/Rosemark-Marcel-100064043561730/',
        'census-jiaan-wu|tiktok'=>'https://www.tiktok.com/@jiaaan.wu',
        'census-jiaan-wu|youtube'=>'https://www.youtube.com/@jiaaanwu',
        'census-jiaan-wu|facebook'=>'https://www.facebook.com/jiaan.wu.203389/',
        'census-jiaan-wu|instagram'=>'https://www.instagram.com/jiaaan.wu/',
        'census-samuella-kouassi|tiktok'=>'https://www.tiktok.com/@samuellakouassiofficiel',
        'census-samuella-kouassi|instagram'=>'https://www.instagram.com/samuellakouassiofficiel/',
        'census-observateur-ebene|youtube'=>'https://www.youtube.com/@Observateur',
        'oustaz-diane|tiktok'=>'https://www.tiktok.com/@oustazdianeofficiel1',
        'oustaz-diane|youtube'=>'https://www.youtube.com/@OustazDianeofficiel',
        'census-daniel-m|tiktok'=>'https://www.tiktok.com/@_michael_daniel',
        'census-daniel-m|youtube'=>'https://www.youtube.com/@wisdombydaniel.m',
        'census-akalajoie|tiktok'=>'https://www.tiktok.com/@akalajoie',
        'ennemi-des-djandjou|tiktok'=>'https://www.tiktok.com/@ennemidesdjandjou',
        'ennemi-des-djandjou|facebook'=>'https://www.facebook.com/profile.php?id=61582125968813',
        'census-isouch|tiktok'=>'https://www.tiktok.com/@prince_du_pays',
        'census-bb-sans-os-de-man|tiktok'=>'https://www.tiktok.com/@bebe.sans.os.de.m',
        'hassan|tiktok'=>'https://www.tiktok.com/@hassanhayekofficiel',
        'census-le-grand-bicongo|tiktok'=>'https://www.tiktok.com/@legrandbicongo',
        'census-chocolat-show-officiel|tiktok'=>'https://www.tiktok.com/@chocolat.show.officiel',
        'census-la-legende|tiktok'=>'https://www.tiktok.com/@lalegende777',
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
                'verification_priority'=>(string)($profile['verificationPriority']??''),
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
    foreach([
        ['id'=>'census-no-limit','name'=>'No Limit','handle'=>'nolimit_vousdv'],
        ['id'=>'census-amour-ruth-poopy','name'=>'Amour Ruth & Poopy','handle'=>'amourruth0'],
        ['id'=>'census-jordan-evraa','name'=>'Jordan Evraa','handle'=>'realjordanevraa'],
        ['id'=>'dbz','name'=>'DBZ','handle'=>'dbz.07'],
        ['id'=>'maabio','name'=>'Maabio','handle'=>'biodetoxminceur'],
        ['id'=>'p_1785175190809','name'=>'Ulrich Jordan','handle'=>'ulrich_jordan30'],
        ['id'=>'census-sarara-messan','name'=>'Sara','handle'=>'sarra_messan'],
        ['id'=>'louissette','name'=>'Cadic N’Guessan','handle'=>'misscadic'],
        ['id'=>'aya-robert','name'=>'Aya Robert','handle'=>'aya.robert27'],
        ['id'=>'hamondchic','name'=>'Coach Hamond Chic','handle'=>'coachhamond'],
        ['id'=>'dez-cocrane225','name'=>'Dez Cocrane 225','handle'=>'dezcocrane.225'],
        ['id'=>'census-roseline-layo','name'=>'Roseline Layo','handle'=>'roselinelayoofficiel'],
        ['id'=>'census-rach-makosso','name'=>'Rach Makosso','handle'=>'rach_makosso1'],
        ['id'=>'census-jp-nda','name'=>'JP N\'da','handle'=>'jpnda_1'],
        ['id'=>'census-cahie-kunta','name'=>'Cahié kunta','handle'=>'cahiekunta'],
        ['id'=>'census-lise-akrassi','name'=>'Lise Akrassi','handle'=>'lise.akrassi.offi'],
        ['id'=>'census-lexes','name'=>'L\'Exès','handle'=>'stephanesacre'],
        ['id'=>'census-ange-morel','name'=>'Ange-Morel Your Eyes','handle'=>'angemorel4'],
        ['id'=>'census-laguepe','name'=>'Laguepe','handle'=>'laguepe03'],
        ['id'=>'census-rosemark-marcel','name'=>'Rosemark Marcel','handle'=>'rosemarkmarcel'],
        ['id'=>'census-jiaan-wu','name'=>'Jiaan Wu','handle'=>'jiaaan.wu'],
        ['id'=>'census-samuella-kouassi','name'=>'Samuella Kouassi','handle'=>'samuellakouassiofficiel'],
        ['id'=>'oustaz-diane','name'=>'Oustaz Diané','handle'=>'oustazdianeofficiel1'],
        ['id'=>'census-daniel-m','name'=>'DANIEL.M','handle'=>'_michael_daniel'],
        ['id'=>'census-akalajoie','name'=>'Miss akalajoie','handle'=>'akalajoie'],
        ['id'=>'ennemi-des-djandjou','name'=>'Ennemi des Djandjou','handle'=>'ennemidesdjandjou'],
        ['id'=>'census-isouch','name'=>'Isouch','handle'=>'prince_du_pays'],
        ['id'=>'census-bb-sans-os-de-man','name'=>'BB Sans Os de Man','handle'=>'bebe.sans.os.de.m'],
        ['id'=>'hassan','name'=>'Hassan Hayek','handle'=>'hassanhayekofficiel'],
        ['id'=>'census-le-grand-bicongo','name'=>'Le grand Bicongo','handle'=>'legrandbicongo'],
        ['id'=>'census-chocolat-show-officiel','name'=>'Chocolat show officiel','handle'=>'chocolat.show.officiel'],
        ['id'=>'census-la-legende','name'=>'La légende','handle'=>'lalegende777'],
    ] as $forced){
        $forcedKey='TikTok|'.$forced['id'];
        if(isset($seen[$forcedKey]))continue;
        $seen[$forcedKey]=true;$out[]=[
            'profile_id'=>$forced['id'],'public_name'=>$forced['name'],'handle'=>'@'.$forced['handle'],
            'platform'=>'TikTok','url'=>'https://www.tiktok.com/@'.$forced['handle'],'confidence'=>100,
            'verification_status'=>'manual_verified',
            'verification_priority'=>'P0',
        ];
    }
    $ennemiFbKey='Facebook|ennemi-des-djandjou';
    if(!isset($seen[$ennemiFbKey])){
        $seen[$ennemiFbKey]=true;$out[]=[
            'profile_id'=>'ennemi-des-djandjou','public_name'=>'Ennemi des Djandjou','handle'=>'@ennemidesdjandjou',
            'platform'=>'Facebook','url'=>'https://www.facebook.com/profile.php?id=61582125968813','confidence'=>100,
            'verification_status'=>'owner_verified',
            'verification_priority'=>'P0',
        ];
    }
    $observateurKey='YouTube|census-observateur-ebene';
    if(!isset($seen[$observateurKey])){
        $seen[$observateurKey]=true;$out[]=[
            'profile_id'=>'census-observateur-ebene','public_name'=>'Observateur Ébène','handle'=>'@Observateur',
            'platform'=>'YouTube','url'=>'https://www.youtube.com/@Observateur','confidence'=>100,
            'verification_status'=>'manual_verified',
        ];
    }
    $rosemarkYtKey='YouTube|census-rosemark-marcel';
    if(!isset($seen[$rosemarkYtKey])){
        $seen[$rosemarkYtKey]=true;$out[]=[
            'profile_id'=>'census-rosemark-marcel','public_name'=>'Rosemark Marcel','handle'=>'@RosemarkMarcelOfficiel',
            'platform'=>'YouTube','url'=>'https://www.youtube.com/@RosemarkMarcelOfficiel','confidence'=>100,
            'verification_status'=>'manual_verified',
        ];
    }
    $rosemarkFbKey='Facebook|census-rosemark-marcel';
    if(!isset($seen[$rosemarkFbKey])){
        $seen[$rosemarkFbKey]=true;$out[]=[
            'profile_id'=>'census-rosemark-marcel','public_name'=>'Rosemark Marcel','handle'=>'Rosemark Marcel',
            'platform'=>'Facebook','url'=>'https://www.facebook.com/p/Rosemark-Marcel-100064043561730/','confidence'=>100,
            'verification_status'=>'manual_verified',
        ];
    }
    $jiaanYtKey='YouTube|census-jiaan-wu';
    if(!isset($seen[$jiaanYtKey])){
        $seen[$jiaanYtKey]=true;$out[]=[
            'profile_id'=>'census-jiaan-wu','public_name'=>'Jiaan Wu','handle'=>'@jiaaanwu',
            'platform'=>'YouTube','url'=>'https://www.youtube.com/@jiaaanwu','confidence'=>100,
            'verification_status'=>'manual_verified',
        ];
    }
    $jiaanFbKey='Facebook|census-jiaan-wu';
    if(!isset($seen[$jiaanFbKey])){
        $seen[$jiaanFbKey]=true;$out[]=[
            'profile_id'=>'census-jiaan-wu','public_name'=>'Jiaan Wu','handle'=>'jiaan.wu.203389',
            'platform'=>'Facebook','url'=>'https://www.facebook.com/jiaan.wu.203389/','confidence'=>100,
            'verification_status'=>'manual_verified',
        ];
    }
    $jiaanIgKey='Instagram|census-jiaan-wu';
    if(!isset($seen[$jiaanIgKey])){
        $seen[$jiaanIgKey]=true;$out[]=[
            'profile_id'=>'census-jiaan-wu','public_name'=>'Jiaan Wu','handle'=>'@jiaaan.wu',
            'platform'=>'Instagram','url'=>'https://www.instagram.com/jiaaan.wu/','confidence'=>100,
            'verification_status'=>'manual_verified',
        ];
    }
    $samuellaIgKey='Instagram|census-samuella-kouassi';
    if(!isset($seen[$samuellaIgKey])){
        $seen[$samuellaIgKey]=true;$out[]=[
            'profile_id'=>'census-samuella-kouassi','public_name'=>'Samuella Kouassi','handle'=>'@samuellakouassiofficiel',
            'platform'=>'Instagram','url'=>'https://www.instagram.com/samuellakouassiofficiel/','confidence'=>100,
            'verification_status'=>'manual_verified',
        ];
    }
    $oustazYtKey='YouTube|oustaz-diane';
    if(!isset($seen[$oustazYtKey])){
        $seen[$oustazYtKey]=true;$out[]=[
            'profile_id'=>'oustaz-diane','public_name'=>'Oustaz Diané','handle'=>'@OustazDianeofficiel',
            'platform'=>'YouTube','url'=>'https://www.youtube.com/@OustazDianeofficiel','confidence'=>100,
            'verification_status'=>'manual_verified',
        ];
    }
    $danielYtKey='YouTube|census-daniel-m';
    if(!isset($seen[$danielYtKey])){
        $seen[$danielYtKey]=true;$out[]=[
            'profile_id'=>'census-daniel-m','public_name'=>'DANIEL.M','handle'=>'@wisdombydaniel.m',
            'platform'=>'YouTube','url'=>'https://www.youtube.com/@wisdombydaniel.m','confidence'=>100,
            'verification_status'=>'manual_verified',
        ];
    }
    $out=p50_live_v4_collapse_identity_sources($out);
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

const P50_LIVE_V4_P0_WATCH_SETTING = 'live_radar_v4_p0_watch';
const P50_LIVE_V4_UNKNOWN_AUDIT_ENABLED_SETTING = 'live_radar_v4_unknown_audit_enabled';

function p50_live_v4_p0_key(string $profileId,string $platform): string {
    return strtolower(trim($profileId)).'|'.strtolower(trim($platform));
}

function p50_live_v4_normalize_p0_entry(array $row): ?array {
    $profileId=trim((string)($row['profileId']??$row['profile_id']??''));
    $platform=trim((string)($row['platform']??'TikTok'));
    if($profileId===''||!in_array($platform,['TikTok','YouTube','Facebook'],true))return null;
    return [
        'profileId'=>$profileId,
        'platform'=>$platform,
        'handle'=>trim((string)($row['handle']??'')),
        'addedAt'=>(string)($row['addedAt']??''),
        'reason'=>(string)($row['reason']??''),
    ];
}

/** Fusionne la watchlist P0 sans doublon (profileId+plateforme). */
function p50_live_v4_merge_p0_watch(array $current,array $additions): array {
    $out=[];$seen=[];
    foreach(array_merge($current,$additions) as $row){
        if(!is_array($row))continue;
        $entry=p50_live_v4_normalize_p0_entry($row);
        if($entry===null)continue;
        $key=p50_live_v4_p0_key($entry['profileId'],$entry['platform']);
        if(isset($seen[$key]))continue;
        $seen[$key]=true;
        if($entry['addedAt']==='')$entry['addedAt']=gmdate(DATE_ATOM);
        $out[]=$entry;
    }
    return $out;
}

function p50_live_v4_dynamic_p0_watch(): array {
    if(!function_exists('p50_de_get_setting'))return [];
    try{$raw=p50_de_get_setting(P50_LIVE_V4_P0_WATCH_SETTING,[]);}
    catch(Throwable){return [];}
    return is_array($raw)?p50_live_v4_merge_p0_watch([],$raw):[];
}

function p50_live_v4_is_dynamic_p0(array $source): bool {
    $key=p50_live_v4_p0_key((string)($source['profile_id']??''),(string)($source['platform']??''));
    if($key==='|')return false;
    foreach(p50_live_v4_dynamic_p0_watch() as $row){
        if(p50_live_v4_p0_key($row['profileId'],$row['platform'])===$key)return true;
    }
    return false;
}

function p50_live_v4_is_p0_tiktok(array $source): bool {
    if((string)($source['platform']??'')!=='TikTok')return false;
    if(strtoupper(trim((string)($source['verification_priority']??'')))==='P0')return true;
    if(in_array((string)($source['profile_id']??''),P50_LIVE_V4_P0_TIKTOK,true))return true;
    return p50_live_v4_is_dynamic_p0($source);
}

function p50_live_v4_is_p0_youtube(array $source): bool {
    if((string)($source['platform']??'')!=='YouTube')return false;
    if(in_array((string)($source['profile_id']??''),P50_LIVE_V4_P0_YOUTUBE,true))return true;
    return p50_live_v4_is_dynamic_p0($source);
}

function p50_live_v4_is_p0_source(array $source): bool {
    $platform=(string)($source['platform']??'');
    if($platform==='TikTok')return p50_live_v4_is_p0_tiktok($source);
    if($platform==='YouTube')return p50_live_v4_is_p0_youtube($source);
    if($platform!=='Facebook')return false;
    return p50_live_v4_is_dynamic_p0($source);
}

function p50_live_v4_is_unknown_tiktok(array $source): bool {
    if((string)($source['platform']??'')!=='TikTok')return false;
    $state=strtolower(trim((string)($source['last_state']??'')));
    return in_array($state,['unknown','','never_checked'],true);
}

/** TikTok : rescan P0 ~2 min, vérifié offline ~5 min. */
function p50_live_v4_needs_tiktok_rescan(array $source,?int $minStaleSeconds=null): bool {
    if((string)($source['platform']??'')!=='TikTok')return false;
    $state=strtolower(trim((string)($source['last_state']??'')));
    if($state==='live')return true;
    $isP0=p50_live_v4_is_p0_tiktok($source);
    $isVerified=p50_live_v4_is_verified_tiktok($source);
    if(!$isP0&&!$isVerified)return false;
    $stale=$minStaleSeconds??($isP0?P50_LIVE_V4_P0_RESCAN_SECONDS:P50_LIVE_V4_VERIFIED_RESCAN_SECONDS);
    $checkedTs=p50_live_v4_health_ts((string)($source['last_checked_at']??''));
    return $checkedTs<=0||(time()-$checkedTs)>=$stale;
}

/** P0 YouTube/Facebook : même rythme que le P0 TikTok. */
function p50_live_v4_needs_p0_rescan(array $source,?int $minStaleSeconds=null): bool {
    if((string)($source['platform']??'')==='TikTok')return p50_live_v4_needs_tiktok_rescan($source,$minStaleSeconds);
    if(!p50_live_v4_is_p0_source($source))return false;
    $state=strtolower(trim((string)($source['last_state']??'')));
    if($state==='live')return true;
    $stale=$minStaleSeconds??P50_LIVE_V4_P0_RESCAN_SECONDS;
    $checkedTs=p50_live_v4_health_ts((string)($source['last_checked_at']??''));
    return $checkedTs<=0||(time()-$checkedTs)>=$stale;
}

/** Compte live récemment (72 h) : rescan prioritaire même si marqué offline. */
function p50_live_v4_is_warm_watch(array $source,int $maxAgeSeconds=259200): bool {
    if(!p50_live_v4_is_verified_tiktok($source))return false;
    $lastLiveTs=p50_live_v4_health_ts((string)($source['last_live_at']??''));
    return $lastLiveTs>0&&(time()-$lastLiveTs)<=$maxAgeSeconds;
}

/** Ordre de découverte : never_checked → P0 / unknown TikTok / warm → unknown Meta → offline. */
function p50_live_v4_discovery_rank(array $source): array {
    $state=strtolower(trim((string)($source['last_state']??'never_checked')));
    $checked=(string)($source['last_checked_at']??'');
    $platform=(string)($source['platform']??'');
    $tiktokFirst=$platform==='TikTok'?0:1;
    if($checked===''||$state===''||$state==='never_checked')return [0,$tiktokFirst,''];
    if(p50_live_v4_is_p0_source($source)||p50_live_v4_is_warm_watch($source)||p50_live_v4_needs_p0_rescan($source))return [1,0,$checked];
    if($state==='unknown')return [2,$tiktokFirst,$checked];
    if(p50_live_v4_is_verified_tiktok($source))return [3,0,$checked];
    return [4,$tiktokFirst,$checked];
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
        $webcastHeaders=[
            'Referer: '.$identity['profileUrl'],
            'Origin: https://www.tiktok.com',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: cross-site',
        ];
        return [
            'api'=>['url'=>'https://www.tiktok.com/api-live/user/room/?aid=1988&sourceType=54&uniqueId='.$handle,'accept'=>'application/json,text/plain,*/*','headers'=>$tiktokApiHeaders],
            'api_basic'=>['url'=>'https://www.tiktok.com/api-live/user/room/?aid=1988&uniqueId='.$handle,'accept'=>'application/json,text/plain,*/*','headers'=>$tiktokApiHeaders],
            // Domaine distinct : IONOS est souvent en 403 sur www.tiktok.com/api-live, pas forcément sur webcast.
            'api_webcast'=>['url'=>'https://webcast.tiktok.com/webcast/room/info_by_user/?aid=1988&unique_id='.$handle,'accept'=>'application/json,text/plain,*/*','headers'=>$webcastHeaders],
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
