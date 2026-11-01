<?php
declare(strict_types=1);
require_once __DIR__.'/config/database.php';
$id=(int)($_GET['id']??0); if($id<=0){http_response_code(400);exit('Foto inválida');}
$st=srnu_db()->prepare('SELECT archivo FROM recepcion_fotos WHERE id=?');$st->execute([$id]);$r=$st->fetch();
if(!$r){http_response_code(404);exit('Foto no encontrada');}
$rel=(string)$r['archivo'];
$full=realpath(__DIR__.'/'.$rel);$base=realpath(__DIR__.'/uploads/recepciones');
if(!$full||!$base||!str_starts_with($full,$base.DIRECTORY_SEPARATOR)||!is_file($full)){http_response_code(404);exit('Archivo no encontrado');}
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($full)?:'application/octet-stream';
if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){http_response_code(403);exit('Tipo no permitido');}
header('Content-Type: '.$mime);header('Content-Length: '.filesize($full));header('Cache-Control: private, max-age=300');readfile($full);exit;
