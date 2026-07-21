<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$u = auth_user();
if ($_SERVER['REQUEST_METHOD']==='GET') json_response(['ok'=>true,'user'=>user_payload($u)]);
require_method('POST');
$in=json_input();
$favorites=array_values(array_unique(array_map('strval',$in['favorites']??[])));
$following=array_values(array_unique(array_map('strval',$in['following']??[])));
if(count($favorites)>3) json_response(['error'=>'Maximum 3 favoris.'],422);
if(count($following)>5) json_response(['error'=>'Maximum 5 suivis.'],422);
$alerts=is_array($in['followAlerts']??null)?$in['followAlerts']:[];
$mode=in_array($in['notificationMode']??'daily',['instant','daily','off'],true)?$in['notificationMode']:'daily';
$stmt=db()->prepare('INSERT INTO user_preferences(user_id,favorites,following,follow_alerts,notification_mode) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE favorites=VALUES(favorites),following=VALUES(following),follow_alerts=VALUES(follow_alerts),notification_mode=VALUES(notification_mode),updated_at=NOW()');
$stmt->execute([$u['id'],json_encode($favorites),json_encode($following),json_encode($alerts),$mode]);
json_response(['ok'=>true]);
