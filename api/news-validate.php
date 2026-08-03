<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/content-intelligence-core.php';

$user=auth_user();
require_role($user,'owner','admin');
require_method('POST');
$input=json_input();
if(($input['confirmed']??false)!==true)json_response(['error'=>'Confirmation humaine obligatoire.'],422);

$profileId=trim((string)($input['profileId']??''));
$title=p50_ci_trim((string)($input['title']??''),500);
$url=trim((string)($input['url']??''));
$image=trim((string)($input['image']??''));
$publishedAt=trim((string)($input['publishedAt']??$input['date']??''));
if($profileId===''||$title===''||$url==='')json_response(['error'=>'Profil, titre et lien original requis.'],422);
if(strlen($profileId)>100||!preg_match('/^[A-Za-z0-9._-]+$/',$profileId))json_response(['error'=>'Profil invalide.'],422);
if(!filter_var($url,FILTER_VALIDATE_URL)||!p50_public_http_url($url))json_response(['error'=>'Lien original invalide.'],422);
if($image!==''&&(!filter_var($image,FILTER_VALIDATE_URL)||!p50_public_http_url($image)))$image='';

$pdo=db();p50_ci_ensure_schema($pdo);
$profileStmt=$pdo->prepare("SELECT profile_id,public_name FROM p50_profile_registry WHERE BINARY profile_id=BINARY ? AND alive=1 LIMIT 1");
$profileStmt->execute([$profileId]);$profile=$profileStmt->fetch();
if(!$profile)json_response(['error'=>'Influenceur actif introuvable.'],404);

$platform=p50_platform($url);if($platform==='')$platform='Web';
$isSocial=$platform!=='Web'&&p50_metrics_is_content_url($platform,p50_metrics_normalize_url($url));
$contentId=null;$metricBridge=['upserted'=>false,'reason'=>$isSocial?'account_missing':'not_social_content'];
if($isSocial){
    $accountStmt=$pdo->prepare("SELECT id FROM p50_metric_accounts WHERE BINARY profile_id=BINARY ? AND platform=? AND status='active' LIMIT 1");
    $accountStmt->execute([$profileId,$platform]);$accountId=$accountStmt->fetchColumn();
    if($accountId){
        try{
            $content=p50_metrics_upsert_content($pdo,[
                'accountId'=>(int)$accountId,'platformContentId'=>null,'canonicalUrl'=>$url,
                'contentType'=>match($platform){'YouTube','TikTok'=>'video','Instagram','Facebook'=>'post',default=>'unknown'},
                'title'=>$title,'publishedAt'=>$publishedAt!==''?$publishedAt:null,'confidence'=>92,
                'sourceType'=>'actualite_validated','observedAt'=>gmdate('c'),
                'metadata'=>['originalLinkValidated'=>true,'validatedByRole'=>(string)($user['role']??'admin')],
                'provenance'=>['collectorVersion'=>P50_CONTENT_INTELLIGENCE_VERSION,'platform'=>$platform,'sourceType'=>'actualite_validated',
                    'endpoint'=>'news-validate.php','officialLink'=>$url,'profileId'=>$profileId,'fetchedAt'=>gmdate('c'),'httpStatus'=>200,'accessMode'=>'human_validation'],
            ]);
            $contentId=(int)$content['id'];$metricBridge=['upserted'=>true,'contentId'=>$contentId];
        }catch(Throwable $error){$metricBridge=['upserted'=>false,'reason'=>'canonical_upsert_rejected'];}
    }
}

try{$published=$publishedAt!==''?p50_metrics_timestamp($publishedAt):gmdate('Y-m-d H:i:s');}
catch(Throwable){$published=gmdate('Y-m-d H:i:s');}
$item=p50_ci_upsert_news($pdo,[
    'profileId'=>$profileId,'contentId'=>$contentId,'platform'=>$platform,'itemType'=>$isSocial?'validated_social':'article',
    'url'=>$url,'title'=>$title,'thumbnailUrl'=>$image?:null,'publishedAt'=>$published,
    'sourceType'=>$isSocial?'validated_social_source':'validated_external_news','confidence'=>92,
    'validationStatus'=>'published','isOfficial'=>false,'observedAt'=>gmdate('Y-m-d H:i:s'),
    'expiresAt'=>gmdate('Y-m-d H:i:s',time()+45*86400),
    'metadata'=>['originalLinkValidated'=>true,'humanValidation'=>true,'validatedByRole'=>(string)($user['role']??'admin'),
        'sourceLabel'=>p50_ci_trim((string)($input['source']??$input['domain']??''),120)],
]);
json_response(['ok'=>true,'version'=>P50_CONTENT_INTELLIGENCE_VERSION,'newsItemId'=>(int)$item['id'],
    'profileId'=>$profileId,'platform'=>$platform,'metricBridge'=>$metricBridge,'publicStateWrites'=>0]);
