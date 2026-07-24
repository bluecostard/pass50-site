<?php
declare(strict_types=1);

function p50m_ensure_schema(): void {
    static $done=false;
    if($done)return;
    $done=true;
    $sql=file_get_contents(dirname(__DIR__).'/migration-metrics-v1.sql');
    if($sql===false)throw new RuntimeException('Migration métriques introuvable.');
    foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[])) as $statement){
        if($statement!=='')db()->exec($statement);
    }
}

function p50m_uuid(): string {
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&0x0f)|0x40);
    $d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

function p50m_http_json(string $url,array $headers=[]): array {
    $ch=curl_init($url);
    if($ch===false)throw new RuntimeException('cURL indisponible.');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>4,
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_USERAGENT=>'PASS50-Metrics/1.0',
        CURLOPT_HTTPHEADER=>$headers,
    ]);
    $body=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $error=curl_error($ch);
    curl_close($ch);
    if($body===false||$status<200||$status>=300){
        throw new RuntimeException('HTTP '.$status.($error!==''?' · '.$error:''));
    }
    $data=json_decode((string)$body,true);
    if(!is_array($data))throw new RuntimeException('Réponse JSON invalide.');
    return $data;
}

function p50m_state_profiles(): array {
    $row=db()->query("SELECT state_json FROM app_state WHERE id=1")->fetch();
    if(!$row)return [];
    $state=json_decode((string)$row['state_json'],true);
    return is_array($state['profiles']??null)?$state['profiles']:[];
}

function p50m_sync_accounts_from_state(): int {
    $stmt=db()->prepare(
        "INSERT INTO p50_metric_accounts(profile_id,platform,profile_url,status)
         VALUES(?,?,?,'pending')
         ON DUPLICATE KEY UPDATE profile_url=VALUES(profile_url),updated_at=NOW()"
    );
    $count=0;
    foreach(p50m_state_profiles() as $profile){
        $profileId=trim((string)($profile['id']??''));
        if($profileId==='')continue;
        foreach((array)($profile['links']??[]) as $platform=>$url){
            $platform=trim((string)$platform);
            $url=trim((string)$url);
            if($url===''||!filter_var($url,FILTER_VALIDATE_URL))continue;
            if(!in_array($platform,['YouTube','X','TikTok','Instagram','Facebook'],true))continue;
            $stmt->execute([$profileId,$platform,$url]);
            $count++;
        }
    }
    return $count;
}

function p50m_youtube_key(): string {
    if(defined('PASS50_YOUTUBE_API_KEY'))return trim((string)PASS50_YOUTUBE_API_KEY);
    return trim((string)(getenv('PASS50_YOUTUBE_API_KEY')?:''));
}

function p50m_x_token(): string {
    if(defined('PASS50_X_BEARER_TOKEN'))return trim((string)PASS50_X_BEARER_TOKEN);
    return trim((string)(getenv('PASS50_X_BEARER_TOKEN')?:''));
}

function p50m_youtube_identifier(string $url): array {
    $host=strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));
    $path=trim((string)(parse_url($url,PHP_URL_PATH)?:''),'/');
    if(!str_contains($host,'youtube.com'))return ['', ''];
    if(preg_match('#^channel/([A-Za-z0-9_-]+)$#',$path,$m))return ['id',$m[1]];
    if(preg_match('#^@([A-Za-z0-9._-]+)$#',$path,$m))return ['handle',$m[1]];
    if(preg_match('#^(?:c|user)/([A-Za-z0-9._-]+)$#',$path,$m))return ['legacy',$m[1]];
    return ['', ''];
}

function p50m_collect_youtube(array $account): array {
    $key=p50m_youtube_key();
    if($key==='')throw new RuntimeException('PASS50_YOUTUBE_API_KEY non configurée.');
    [$kind,$identifier]=p50m_youtube_identifier((string)$account['profile_url']);
    if($identifier==='')throw new RuntimeException('URL YouTube directe invalide.');

    $params=['part'=>'snippet,statistics,contentDetails','key'=>$key];
    if($kind==='id')$params['id']=$identifier;
    elseif($kind==='handle')$params['forHandle']=$identifier;
    else $params['forUsername']=$identifier;

    $channelData=p50m_http_json('https://www.googleapis.com/youtube/v3/channels?'.http_build_query($params));
    $channel=$channelData['items'][0]??null;
    if(!is_array($channel))throw new RuntimeException('Chaîne YouTube introuvable.');

    $channelId=(string)$channel['id'];
    $uploads=(string)($channel['contentDetails']['relatedPlaylists']['uploads']??'');
    $statistics=(array)($channel['statistics']??[]);
    $title=(string)($channel['snippet']['title']??'');

    $upsert=db()->prepare(
        "UPDATE p50_metric_accounts SET external_id=?,username=?,status='active',
         last_error=NULL,last_resolved_at=NOW(),last_collected_at=NOW()
         WHERE profile_id=? AND platform='YouTube'"
    );
    $upsert->execute([$channelId,$title,$account['profile_id']]);

    p50m_insert_snapshot([
        'profile_id'=>$account['profile_id'],'platform'=>'YouTube',
        'external_account_id'=>$channelId,'content_id'=>'',
        'content_url'=>$account['profile_url'],'content_title'=>$title,
        'followers'=>$statistics['subscriberCount']??null,
        'total_views'=>$statistics['viewCount']??null,
        'content_count'=>$statistics['videoCount']??null,
        'source_reliability'=>0.98,'raw_payload'=>$channel
    ]);

    $contentCount=0;
    if($uploads!==''){
        $playlist=p50m_http_json('https://www.googleapis.com/youtube/v3/playlistItems?'.http_build_query([
            'part'=>'snippet,contentDetails','playlistId'=>$uploads,'maxResults'=>12,'key'=>$key
        ]));
        $ids=[];
        foreach((array)($playlist['items']??[]) as $item){
            $id=(string)($item['contentDetails']['videoId']??$item['snippet']['resourceId']['videoId']??'');
            if($id!=='')$ids[]=$id;
        }
        if($ids){
            $videos=p50m_http_json('https://www.googleapis.com/youtube/v3/videos?'.http_build_query([
                'part'=>'snippet,statistics,liveStreamingDetails','id'=>implode(',',$ids),'key'=>$key
            ]));
            foreach((array)($videos['items']??[]) as $video){
                $id=(string)($video['id']??'');
                if($id==='')continue;
                $stats=(array)($video['statistics']??[]);
                p50m_insert_snapshot([
                    'profile_id'=>$account['profile_id'],'platform'=>'YouTube',
                    'external_account_id'=>$channelId,'content_id'=>$id,
                    'content_url'=>'https://www.youtube.com/watch?v='.$id,
                    'content_title'=>(string)($video['snippet']['title']??''),
                    'published_at'=>(string)($video['snippet']['publishedAt']??''),
                    'views'=>$stats['viewCount']??null,'likes'=>$stats['likeCount']??null,
                    'comments'=>$stats['commentCount']??null,'source_reliability'=>0.98,
                    'raw_payload'=>$video
                ]);
                $contentCount++;
            }
        }
    }
    return ['account'=>$channelId,'contents'=>$contentCount];
}

function p50m_x_username(string $url): string {
    $host=strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));
    if(!in_array($host,['x.com','www.x.com','twitter.com','www.twitter.com'],true))return '';
    $path=trim((string)(parse_url($url,PHP_URL_PATH)?:''),'/');
    return preg_match('/^[A-Za-z0-9_]{1,15}$/',$path)?$path:'';
}

function p50m_collect_x(array $account): array {
    $token=p50m_x_token();
    if($token==='')throw new RuntimeException('PASS50_X_BEARER_TOKEN non configuré.');
    $username=p50m_x_username((string)$account['profile_url']);
    if($username==='')throw new RuntimeException('URL X directe invalide.');
    $headers=['Authorization: Bearer '.$token];
    $user=p50m_http_json('https://api.x.com/2/users/by/username/'.rawurlencode($username).'?user.fields=public_metrics,created_at',$headers);
    $data=$user['data']??null;
    if(!is_array($data))throw new RuntimeException('Compte X introuvable.');
    $id=(string)$data['id']; $metrics=(array)($data['public_metrics']??[]);
    db()->prepare("UPDATE p50_metric_accounts SET external_id=?,username=?,status='active',last_error=NULL,last_resolved_at=NOW(),last_collected_at=NOW() WHERE profile_id=? AND platform='X'")
        ->execute([$id,$username,$account['profile_id']]);

    p50m_insert_snapshot([
        'profile_id'=>$account['profile_id'],'platform'=>'X','external_account_id'=>$id,
        'content_id'=>'','content_url'=>$account['profile_url'],'content_title'=>'@'.$username,
        'followers'=>$metrics['followers_count']??null,'content_count'=>$metrics['tweet_count']??null,
        'source_reliability'=>0.96,'raw_payload'=>$data
    ]);

    $tweets=p50m_http_json('https://api.x.com/2/users/'.rawurlencode($id).'/tweets?'.http_build_query([
        'max_results'=>10,'exclude'=>'retweets,replies','tweet.fields'=>'created_at,public_metrics'
    ]),$headers);
    $count=0;
    foreach((array)($tweets['data']??[]) as $tweet){
        $tm=(array)($tweet['public_metrics']??[]);
        $tid=(string)($tweet['id']??'');
        if($tid==='')continue;
        p50m_insert_snapshot([
            'profile_id'=>$account['profile_id'],'platform'=>'X','external_account_id'=>$id,
            'content_id'=>$tid,'content_url'=>'https://x.com/'.$username.'/status/'.$tid,
            'content_title'=>mb_substr((string)($tweet['text']??''),0,490),
            'published_at'=>(string)($tweet['created_at']??''),
            'likes'=>$tm['like_count']??null,'comments'=>$tm['reply_count']??null,
            'reposts'=>$tm['retweet_count']??null,'quotes'=>$tm['quote_count']??null,
            'source_reliability'=>0.96,'raw_payload'=>$tweet
        ]);
        $count++;
    }
    return ['account'=>$id,'contents'=>$count];
}

function p50m_insert_snapshot(array $r): void {
    $sql="INSERT INTO p50_metric_snapshots
      (profile_id,platform,external_account_id,content_id,content_url,content_title,published_at,captured_at,
       followers,total_views,content_count,views,likes,comments,shares,saves,reposts,quotes,source_reliability,raw_payload)
      VALUES(?,?,?,?,?,?,?,NOW(),?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmt=db()->prepare($sql);
    $published=null;
    if(!empty($r['published_at'])){
        $ts=strtotime((string)$r['published_at']);
        if($ts!==false)$published=gmdate('Y-m-d H:i:s',$ts);
    }
    $stmt->execute([
        $r['profile_id'],$r['platform'],$r['external_account_id']??null,$r['content_id']??'',
        $r['content_url']??null,$r['content_title']??null,$published,
        $r['followers']??null,$r['total_views']??null,$r['content_count']??null,
        $r['views']??null,$r['likes']??null,$r['comments']??null,$r['shares']??null,
        $r['saves']??null,$r['reposts']??null,$r['quotes']??null,
        $r['source_reliability']??0.9,json_encode($r['raw_payload']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
    ]);
}

function p50m_collect_account(array $account): array {
    try{
        $result=match((string)$account['platform']){
            'YouTube'=>p50m_collect_youtube($account),
            'X'=>p50m_collect_x($account),
            default=>throw new RuntimeException('Collecteur non activé pour cette plateforme.')
        };
        return ['ok'=>true,'profileId'=>$account['profile_id'],'platform'=>$account['platform']]+$result;
    }catch(Throwable $e){
        db()->prepare("UPDATE p50_metric_accounts SET status='error',last_error=?,updated_at=NOW() WHERE profile_id=? AND platform=?")
            ->execute([$e->getMessage(),$account['profile_id'],$account['platform']]);
        return ['ok'=>false,'profileId'=>$account['profile_id'],'platform'=>$account['platform'],'error'=>$e->getMessage()];
    }
}

function p50m_period_seconds(string $period): int {
    return match($period){'2H'=>7200,'24H'=>86400,'48H'=>172800,'7J'=>604800,'15J'=>1296000,default=>86400};
}

function p50m_clamp(float $v,float $min=0,float $max=100): float {
    return max($min,min($max,$v));
}

function p50m_percentile(float $value,array $population): ?float {
    $p=array_values(array_filter(array_map('floatval',$population),fn($x)=>is_finite($x)));
    if(!$p)return null;
    sort($p,SORT_NUMERIC);$below=0;$equal=0;
    foreach($p as $x){if($x<$value)$below++;elseif($x==$value)$equal++;}
    return p50m_clamp((($below+0.5*$equal)/count($p))*100);
}

function p50m_ratio_score(float $value,array $history): ?float {
    $h=array_values(array_filter(array_map('floatval',$history),fn($x)=>is_finite($x)));
    if(!$h)return null;
    sort($h,SORT_NUMERIC);$n=count($h);$m=$n%2?$h[intdiv($n,2)]:($h[$n/2-1]+$h[$n/2])/2;
    if($m<=0)return $value<=0?50:p50m_clamp(50+25*log(1+$value,2));
    return p50m_clamp(50+20*log(max($value/$m,0.125),2));
}

function p50m_score_raw(float $raw,array $history,array $peers,array $global): float {
    $parts=[];
    $a=p50m_ratio_score($raw,$history);if($a!==null)$parts[]=[$a,.50];
    $b=p50m_percentile($raw,$peers);if($b!==null)$parts[]=[$b,.35];
    $c=p50m_percentile($raw,$global);if($c!==null)$parts[]=[$c,.15];
    if(!$parts)return 0;
    $num=0;$den=0;foreach($parts as [$v,$w]){$num+=$v*$w;$den+=$w;}
    return p50m_clamp($num/$den);
}

function p50m_profile_metric_rows(string $profileId,string $period): array {
    $seconds=p50m_period_seconds($period);
    $stmt=db()->prepare("SELECT * FROM p50_metric_snapshots WHERE profile_id=? AND captured_at>=DATE_SUB(NOW(),INTERVAL ? SECOND) ORDER BY captured_at");
    $stmt->execute([$profileId,$seconds]);
    return $stmt->fetchAll();
}

function p50m_derive_raw(string $profileId,string $period): array {
    $rows=p50m_profile_metric_rows($profileId,$period);
    if(!$rows)return [];
    $accounts=array_values(array_filter($rows,fn($r)=>(string)$r['content_id']===''));
    $contents=array_values(array_filter($rows,fn($r)=>(string)$r['content_id']!==''));
    $latestByPlatform=[];
    foreach($accounts as $r)$latestByPlatform[$r['platform']]=$r;
    $followers=array_sum(array_map(fn($r)=>(int)($r['followers']??0),$latestByPlatform));
    $views=array_sum(array_map(fn($r)=>(int)($r['views']??0),$contents));
    $likes=array_sum(array_map(fn($r)=>(int)($r['likes']??0),$contents));
    $comments=array_sum(array_map(fn($r)=>(int)($r['comments']??0),$contents));
    $shares=array_sum(array_map(fn($r)=>(int)($r['shares']??0)+(int)($r['reposts']??0)+(int)($r['quotes']??0),$contents));
    $engagement=$views>0?($likes+3*$comments+5*$shares)/$views:0;
    $shareRate=$views>0?$shares/$views:0;

    $growth=null;
    foreach(array_unique(array_column($accounts,'platform')) as $platform){
        $platformRows=array_values(array_filter($accounts,fn($r)=>$r['platform']===$platform&&$r['followers']!==null));
        if(count($platformRows)>=2){
            $first=(int)$platformRows[0]['followers'];$last=(int)$platformRows[count($platformRows)-1]['followers'];
            if($first>0)$growth=($growth??0)+(($last-$first)/$first);
        }
    }

    $velocity=0;
    $byContent=[];
    foreach($contents as $r)$byContent[$r['platform'].'|'.$r['content_id']][]=$r;
    foreach($byContent as $series){
        if(count($series)<2)continue;
        $a=$series[0];$b=$series[count($series)-1];
        $dt=max(1,strtotime($b['captured_at'])-strtotime($a['captured_at']));
        $dv=max(0,(int)$b['views']-(int)$a['views']);
        $velocity+=$dv/($dt/3600);
    }

    $verifiedPlatforms=(int)db()->prepare("SELECT COUNT(*) FROM p50_social_links WHERE profile_id=? AND status='verified'");
    $s=db()->prepare("SELECT COUNT(*) FROM p50_social_links WHERE profile_id=? AND status='verified'");
    $s->execute([$profileId]);$platformCount=(int)$s->fetchColumn();

    return [
        'c1'=>$followers>0?log10(1+$followers):null,
        'c2'=>$views>0?$views:null,
        'c3'=>$growth,
        'c4'=>$engagement>0?$engagement:null,
        'c5'=>$shareRate>0?$shareRate:null,
        'c6'=>$comments>0?log10(1+$comments):null,
        'c7'=>$velocity>0?$velocity:null,
        'c8'=>$platformCount>0?$platformCount:null,
        'c13'=>count($contents)>0?count($contents):null,
        'c14'=>($views>0&&$followers>0)?p50m_clamp(100-abs(log10(max($views/$followers,0.001)))*15):null,
    ];
}

function p50m_reference_values(string $criterion,string $period): array {
    $values=[];
    $profiles=db()->query("SELECT profile_id FROM p50_profile_registry WHERE alive=1")->fetchAll(PDO::FETCH_COLUMN);
    foreach($profiles as $id){
        $r=p50m_derive_raw((string)$id,$period);
        if(isset($r[$criterion])&&$r[$criterion]!==null&&is_numeric($r[$criterion]))$values[]=(float)$r[$criterion];
    }
    return $values;
}

function p50m_history_values(string $profileId,string $criterion,string $period): array {
    $stmt=db()->prepare("SELECT details FROM p50_metric_criteria WHERE profile_id=? AND period_key=?");
    $stmt->execute([$profileId,$period]);
    $row=$stmt->fetch();
    if(!$row)return [];
    $d=json_decode((string)$row['details'],true);
    $old=$d['raw'][$criterion]??null;
    return is_numeric($old)?[(float)$old]:[];
}

function p50m_calculate_profile(string $profileId): array {
    $weights=['c1'=>.06,'c2'=>.08,'c3'=>.07,'c4'=>.08,'c5'=>.09,'c6'=>.05,'c7'=>.10,'c8'=>.08,'c9'=>.06,'c10'=>.06,'c11'=>.05,'c12'=>.04,'c13'=>.04,'c14'=>.07,'c15'=>.07];
    $periodScores=[];$details=[];
    foreach(['2H','24H','48H','7J','15J'] as $period){
        $raw=p50m_derive_raw($profileId,$period);$scores=[];$sum=0;$availableWeight=0;
        foreach($weights as $key=>$weight){
            if(!isset($raw[$key])||$raw[$key]===null)continue;
            $refs=p50m_reference_values($key,$period);
            $history=p50m_history_values($profileId,$key,$period);
            $score=p50m_score_raw((float)$raw[$key],$history,$refs,$refs);
            $scores[$key]=$score;$sum+=$score*$weight;$availableWeight+=$weight;
        }
        $base=$availableWeight>0?$sum/$availableWeight:0;
        $coverage=$availableWeight*100;
        $freshness=(float)(db()->prepare("SELECT TIMESTAMPDIFF(MINUTE,MAX(captured_at),NOW()) FROM p50_metric_snapshots WHERE profile_id=?")->execute([$profileId])?:0);
        $fstmt=db()->prepare("SELECT TIMESTAMPDIFF(MINUTE,MAX(captured_at),NOW()) FROM p50_metric_snapshots WHERE profile_id=?");
        $fstmt->execute([$profileId]);$minutes=(int)$fstmt->fetchColumn();
        $fresh=max(0,100-min(100,$minutes/14.4));
        $confidence=.5*($coverage/100)+.3*($fresh/100)+.2*.95;
        $final=p50m_clamp($base*(.75+.25*$confidence));
        $periodScores[$period]=round($final,2);
        $details[$period]=['raw'=>$raw,'criteria'=>$scores,'coverage'=>round($coverage,2),'confidence'=>round($confidence*100,2),'score'=>$periodScores[$period]];
        $columns=[];$params=[];
        foreach(range(1,15) as $i){$columns[]='c'.$i.'=?';$params[]=$scores['c'.$i]??null;}
        $params=array_merge($params,[round($confidence*100,2),round($coverage,2),0,$periodScores[$period],json_encode($details[$period],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$profileId,$period]);
        db()->prepare("INSERT INTO p50_metric_criteria(profile_id,period_key,calculated_at,c1,c2,c3,c4,c5,c6,c7,c8,c9,c10,c11,c12,c13,c14,c15,confidence,coverage,penalties,score,details)
            VALUES(?,?,NOW(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE calculated_at=NOW(),c1=VALUES(c1),c2=VALUES(c2),c3=VALUES(c3),c4=VALUES(c4),c5=VALUES(c5),c6=VALUES(c6),c7=VALUES(c7),c8=VALUES(c8),c9=VALUES(c9),c10=VALUES(c10),c11=VALUES(c11),c12=VALUES(c12),c13=VALUES(c13),c14=VALUES(c14),c15=VALUES(c15),confidence=VALUES(confidence),coverage=VALUES(coverage),penalties=VALUES(penalties),score=VALUES(score),details=VALUES(details)")
          ->execute(array_merge([$profileId,$period],array_slice($params,0,-2)));
    }
    $general=($periodScores['2H']*.25+$periodScores['24H']*.30+$periodScores['48H']*.20+$periodScores['7J']*.15+$periodScores['15J']*.055)/.955;
    return ['profileId'=>$profileId,'score'=>round($general,2),'windows'=>$periodScores,'details'=>$details];
}

function p50m_publish_scores_to_state(array $results): int {
    $row=db()->query("SELECT state_json FROM app_state WHERE id=1 FOR UPDATE")->fetch();
    if(!$row)return 0;
    $state=json_decode((string)$row['state_json'],true);
    if(!is_array($state))return 0;
    $byId=[];foreach($results as $r)$byId[$r['profileId']]=$r;
    $count=0;
    foreach((array)($state['profiles']??[]) as &$profile){
        $id=(string)($profile['id']??'');
        if(!isset($byId[$id]))continue;
        $r=$byId[$id];
        $profile['scores']=$profile['scores']??[];
        foreach($r['windows'] as $period=>$score)$profile['scores'][$period]=$score;
        $coverage=(float)($r['details']['24H']['coverage']??0);
        $confidence=(float)($r['details']['24H']['confidence']??0);
        $profile['dataConfidence']=$confidence;
        $profile['measuredCoverage']=$coverage;
        $profile['algorithmVersion']='15C-v1';
        $profile['lastMetricCalculationAt']=gmdate('c');
        if($coverage>=60&&$confidence>=65){$profile['classable']=true;$profile['eligible']=true;}
        $count++;
    }
    unset($profile);
    $state['metricEngine']=['version'=>'15C-v1','publishedAt'=>gmdate('c'),'profilesUpdated'=>$count];
    db()->prepare("UPDATE app_state SET state_json=?,updated_at=NOW() WHERE id=1")
      ->execute([json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    return $count;
}
