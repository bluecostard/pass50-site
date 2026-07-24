<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require_method('POST');
$user=auth_user();
require_role($user,'owner','admin');
p50_de_ensure_schema();
p50_de_sync_registry_from_state();
$in=json_input();
$profileId=trim((string)($in['profileId']??''));

$beforeState=p50_de_load_public_state();
$before=[];
foreach((array)($beforeState['profiles']??[]) as $profile){
    if(!is_array($profile)||empty($profile['id']))continue;
    $before[(string)$profile['id']]=(array)($profile['scores']??[]);
}

$count=$profileId!==''?(p50_de_publish_profile($profileId,$user['id'])?1:0):p50_de_publish_all($user['id']);

$afterState=p50_de_load_public_state();
$scoreChanges=[];$recalculated=0;
foreach((array)($afterState['profiles']??[]) as $profile){
    if(!is_array($profile)||empty($profile['id']))continue;
    $id=(string)$profile['id'];
    $engine=(array)($profile['dataEngine']??[]);
    if(($engine['algorithmVersion']??'')==='15C-v1'&&!empty($engine['trend']['classable']))$recalculated++;
    $old=(array)($before[$id]??[]);$new=(array)($profile['scores']??[]);
    $periodChanges=[];
    foreach(['2H','24H','48H','7J','15J'] as $period){
        $a=(int)($old[$period]??0);$b=(int)($new[$period]??0);
        if($a!==$b)$periodChanges[$period]=['before'=>$a,'after'=>$b,'delta'=>$b-$a];
    }
    if($periodChanges)$scoreChanges[]=['profileId'=>$id,'name'=>(string)($profile['name']??$id),'periods'=>$periodChanges];
}

json_response([
  'ok'=>true,
  'publishedProfiles'=>$count,
  'recalculatedProfiles'=>$recalculated,
  'scoresChanged'=>count($scoreChanges),
  'scoreChanges'=>array_slice($scoreChanges,0,50),
  'threshold'=>p50_de_threshold(),
  'hub'=>p50_de_hub_payload()
]);
