<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');
$u=auth_user();require_role($u,'owner','admin');
if(!isset($_FILES['file'])||$_FILES['file']['error']!==UPLOAD_ERR_OK)json_response(['error'=>'Fichier manquant.'],422);
$file=$_FILES['file'];
if($file['size']>(int)$config['upload']['max_bytes'])json_response(['error'=>'Image trop volumineuse.'],413);
$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($file['tmp_name']);
if(!in_array($mime,$config['upload']['allowed_mime'],true))json_response(['error'=>'Format non autorisé.'],422);
$kind=in_array($_POST['kind']??'',['profile','event'],true)?$_POST['kind']:'profile';
$id=preg_replace('/[^a-zA-Z0-9_-]/','',(string)($_POST['id']??'media'))?:'media';
$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime];
$dir=dirname(__DIR__).'/uploads/'.$kind;if(!is_dir($dir)&&!mkdir($dir,0755,true))json_response(['error'=>'Dossier média inaccessible.'],500);
$name=$id.'-'.time().'-'.bin2hex(random_bytes(4)).'.'.$ext;$dest=$dir.'/'.$name;
if(!move_uploaded_file($file['tmp_name'],$dest))json_response(['error'=>'Téléversement impossible.'],500);
$url=rtrim($config['app']['base_url'],'/').'/uploads/'.$kind.'/'.$name;
json_response(['ok'=>true,'url'=>$url],201);
