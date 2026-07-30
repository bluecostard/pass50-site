<?php
declare(strict_types=1);
require __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/duel-history-core.php';

$base=rtrim((string)$config['app']['base_url'],'/');
$shareId=trim((string)($_GET['shareId']??''));
if(!preg_match('/^[a-f0-9]{64}$/',$shareId)){
    header('Location: '.$base.'/',true,302);
    exit;
}

$stmt=db()->prepare('SELECT s.poll_key,s.profile_id,s.history_id,s.vote_updated_at,h.*
    FROM p50_vote_share_sessions s
    LEFT JOIN p50_duel_vote_history h ON h.id=s.history_id
    WHERE s.id=? LIMIT 1');
$stmt->execute([$shareId]);
$row=$stmt->fetch();
if(!$row){
    header('Location: '.$base.'/',true,302);
    exit;
}

$selectedId=(string)$row['profile_id'];
$candidates=[];
if(!empty($row['history_id'])&&!empty($row['candidate_a_id'])&&!empty($row['candidate_b_id'])){
    foreach(['a','b'] as $side){
        $id=(string)$row['candidate_'.$side.'_id'];
        $candidates[]=[
            'id'=>$id,
            'name'=>(string)$row['candidate_'.$side.'_name'],
            'photo'=>(string)($row['candidate_'.$side.'_photo']??''),
            'percentage'=>$row['candidate_'.$side.'_percentage']!==null?(int)$row['candidate_'.$side.'_percentage']:null,
            'selected'=>$id===$selectedId,
        ];
    }
}else{
    $ids=p50_duel_candidate_ids((string)$row['poll_key']);
    $snapshot=p50_duel_state_snapshot();
    $profiles=$ids?p50_duel_public_candidates($ids,$snapshot):[];
    foreach($ids as $id)if(isset($profiles[$id]))$candidates[]=[
        'id'=>$id,'name'=>(string)$profiles[$id]['name'],'photo'=>(string)$profiles[$id]['photoUrl'],
        'percentage'=>null,'selected'=>$id===$selectedId,
    ];
}
if(count($candidates)!==2){
    header('Location: '.$base.'/',true,302);
    exit;
}

header_remove('Content-Type');
header_remove('Cache-Control');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Robots-Tag: noindex,follow');

function duel_e(string $value): string {
    return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function duel_initials(string $name): string {
    preg_match_all('/[\pL\pN]+/u',$name,$parts);
    return strtoupper(substr(implode('',array_map(static fn($part)=>substr($part,0,1),array_slice($parts[0]??[],0,2))),0,2));
}

$voteUrl=$base.'/?'.http_build_query(['duel'=>$shareId,'source'=>'vote_share','medium'=>'social']).'#coules';
$ogImage=$base.'/assets/pass50-og.png';
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>PASS50 — Le duel partagé</title>
  <meta name="description" content="Découvre ce duel PASS50 et vote à ton tour.">
  <meta property="og:title" content="PASS50 — Qui est le plus coulé des 2 ?">
  <meta property="og:description" content="Découvre le duel et vote sur PASS50.">
  <meta property="og:image" content="<?=duel_e($ogImage)?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?=duel_e($base.'/d/'.$shareId)?>">
  <style>
    *{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(circle at 15% 5%,#304414 0,#0b120c 34%,#030604 100%);color:#fff;font-family:Arial,sans-serif;display:grid;place-items:center;padding:24px}.page{width:min(880px,100%)}.brand{color:#b7ff00;font-size:42px;font-weight:1000}.question{text-align:center;font-size:clamp(28px,6vw,52px);margin:24px 0}.duel{display:grid;grid-template-columns:1fr 84px 1fr;align-items:center;gap:14px}.candidate{position:relative;overflow:hidden;min-width:0;background:linear-gradient(145deg,#1b271d,#0a0f0b);border:4px solid #667064;border-radius:28px;padding:14px;text-align:center}.candidate.selected{border-color:#b7ff00;box-shadow:0 0 34px #b7ff0045}.photo{width:100%;aspect-ratio:4/5;object-fit:cover;border-radius:19px;background:linear-gradient(145deg,#2f4420,#101711)}.initials{display:grid;place-items:center;font-size:72px;font-weight:1000}.badge{position:absolute;top:26px;left:26px;background:#b7ff00;color:#071000;border-radius:14px;padding:9px 12px;font-weight:1000}.name{font-size:clamp(21px,4vw,34px);font-weight:1000;margin-top:14px}.percent{font-size:clamp(34px,6vw,58px);font-weight:1000;color:#b7ff00}.vs{width:78px;height:78px;border-radius:50%;border:4px solid #b7ff00;background:#081008;display:grid;place-items:center;font-size:27px;font-weight:1000}.cta{display:block;width:max-content;margin:28px auto 0;background:#b7ff00;color:#050705;text-decoration:none;border-radius:16px;padding:16px 30px;font-size:22px;font-weight:1000}@media(max-width:620px){.duel{grid-template-columns:1fr 52px 1fr;gap:6px}.vs{width:50px;height:50px;font-size:18px}.candidate{padding:7px;border-radius:18px}.badge{top:14px;left:14px;font-size:10px;padding:6px}.photo{border-radius:13px}}
  </style>
</head>
<body>
<main class="page">
  <div class="brand">PASS50</div>
  <h1 class="question">Qui est le plus coulé des 2 ?</h1>
  <section class="duel">
    <?php foreach($candidates as $index=>$candidate): ?>
      <?php if($index===1): ?><div class="vs">VS</div><?php endif; ?>
      <article class="candidate<?=$candidate['selected']?' selected':''?>">
        <?php if($candidate['selected']): ?><div class="badge">✓ MON VOTE</div><?php endif; ?>
        <?php if($candidate['photo']!==''): ?>
          <img class="photo" src="<?=duel_e($candidate['photo'])?>" alt="<?=duel_e($candidate['name'])?>">
        <?php else: ?>
          <div class="photo initials"><?=duel_e(duel_initials($candidate['name']))?></div>
        <?php endif; ?>
        <div class="name"><?=duel_e($candidate['name'])?></div>
        <?php if($candidate['percentage']!==null): ?><div class="percent"><?=$candidate['percentage']?> %</div><?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>
  <a class="cta" href="<?=duel_e($voteUrl)?>">JE VOTE</a>
</main>
</body>
</html>
