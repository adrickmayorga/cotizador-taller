<?php
declare(strict_types=1);
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/config/ui.php';
require_once __DIR__.'/config/web.php';
$id=(int)($_GET['id']??0);
$c=srnu_obtener_cotizacion($id);
if(!$c){http_response_code(404);exit('No encontrada');}
$tel=preg_replace('/\D+/','',(string)$c['telefono']);if(strlen($tel)===10)$tel='52'.$tel;
$firma=srnu_preparar_firma_digital($id,false);
$firmaToken=(string)($firma['token']??'');
$baseCompartir=srnu_share_base_url((string)($_SERVER['HTTP_HOST']??'localhost'));
$firmaLink=$firmaToken!==''?rtrim($baseCompartir,'/').'/firmar.php?token='.rawurlencode($firmaToken):'';
$mensaje="Hola {$c['cliente']}, te compartimos tu cotización {$c['folio']} para {$c['vehiculo']}. Total: $".number_format((float)$c['total'],2).". SRNU CUSTOM.";
if($firmaLink!=='')$mensaje.="\n\nPuedes revisar y firmar aquí:\n".$firmaLink;
$url='https://wa.me/'.$tel.'?text='.rawurlencode($mensaje);
$tipo=$c['requiere_factura']==='si'?'factura':'sin_factura';
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta charset="utf-8"><title>WhatsApp · <?=h($c['cliente'])?></title><link rel="icon" href="favicon.png"><style>body{font-family:Arial;background:#f4f5f7}.w{max-width:700px;margin:50px auto;background:#fff;padding:25px;border-radius:12px}a{display:inline-block;background:#111;color:#fff;padding:11px 14px;border-radius:8px;text-decoration:none;margin:4px}.wa{background:#128c4a}</style><link rel="stylesheet" href="assets/app.css"><script defer src="assets/app.js"></script></head><body><?php srnu_appbar('cotizaciones'); ?><div class="w"><h1>Enviar por WhatsApp</h1><p style="white-space:pre-line"><?=h($mensaje)?></p><?php if($firmaLink):?><p><small>Enlace de firma usado: <code><?=h($firmaLink)?></code></small></p><?php endif;?><a class="wa" target="_blank" href="<?=h($url)?>">Abrir WhatsApp</a><a href="generar_pdf.php?id=<?=$id?>&tipo=<?=$tipo?>&accion=descargar">Descargar PDF para adjuntar</a><a href="cotizaciones.php">Volver</a><p><small>Por seguridad del navegador, WhatsApp no permite adjuntar automáticamente un archivo local; el botón descarga el PDF para que lo adjuntes en el chat abierto.</small></p></div></body></html>
