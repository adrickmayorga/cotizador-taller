<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
$pdo = srnu_db();
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/i', $token)) { http_response_code(404); exit('Enlace de confirmación no válido.'); }
$stmt = $pdo->prepare('SELECT * FROM confirmaciones_locales WHERE token=? LIMIT 1');
$stmt->execute([$token]);
$confirmacion = $stmt->fetch();
if (!$confirmacion) { http_response_code(404); exit('Enlace de confirmación no encontrado.'); }
$c = srnu_obtener_cotizacion((int)$confirmacion['cotizacion_id']);
if (!$c) { http_response_code(404); exit('Cotización no encontrada.'); }
$currentHash = srnu_hash_cotizacion_para_firma($c);
$enlaceVigente = hash_equals((string)$confirmacion['snapshot_hash'], $currentHash);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($confirmacion['estado'] ?? '') === 'pendiente') {
    if (!$enlaceVigente) {
        $error = 'La cotización cambió después de generar este enlace. Solicita un enlace nuevo al taller.';
    } else {
        $nombre = trim((string)($_POST['nombre_confirmante'] ?? ''));
        $acepto = !empty($_POST['acepto']);
        if ($nombre === '') $error = 'Escribe el nombre de la persona que confirma.';
        elseif (!$acepto) $error = 'Debes confirmar que revisaste y aceptas la cotización.';
        else {
            $at = date('Y-m-d H:i:s');
            $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
            $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
            $verify = hash('sha256', $currentHash.'|'.$nombre.'|'.$at.'|'.$token.'|'.$ip);
            $upd = $pdo->prepare("UPDATE confirmaciones_locales SET estado='confirmada', nombre_confirmante=?, acepto=1, confirmado_at=?, ip_confirmante=?, user_agent=?, hash_verificacion=?, updated_at=NOW() WHERE id=? AND estado='pendiente'");
            $upd->execute([$nombre,$at,$ip,$ua,$verify,(int)$confirmacion['id']]);
            $pdo->prepare("UPDATE cotizaciones SET aceptada_at=COALESCE(aceptada_at,NOW()), estado=CASE WHEN estado='Cotización' THEN 'Aprobada' ELSE estado END WHERE id=?")->execute([(int)$c['id']]);
            $stmt->execute([$token]);
            $confirmacion = $stmt->fetch();
        }
    }
}
$confirmada = ($confirmacion['estado'] ?? '') === 'confirmada';
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Confirmar · <?=htmlspecialchars($c['cliente'].' · '.$c['vehiculo'],ENT_QUOTES,'UTF-8')?></title><link rel="icon" href="favicon.png"><style>
*{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Arial,sans-serif;background:#f3f6fb;color:#0f172a}.top{background:#080b12;color:#fff;padding:16px}.topin{max-width:820px;margin:auto;display:flex;align-items:center;gap:12px}.top img{width:38px;height:38px;object-fit:contain}.top b{font-size:16px}.top span{display:block;font-size:12px;color:#94a3b8;margin-top:2px}.wrap{max-width:820px;margin:24px auto;padding:0 14px 40px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:20px;box-shadow:0 12px 40px rgba(15,23,42,.08);padding:22px;margin-bottom:14px}h1{font-size:28px;margin:0 0 5px}h2{font-size:18px;margin:0 0 14px}.muted{color:#64748b;font-size:13px}.facts{display:grid;grid-template-columns:1fr 1fr;gap:10px}.fact{padding:12px;background:#f8fafc;border-radius:12px}.fact small{display:block;color:#64748b;font-size:11px}.fact strong{display:block;margin-top:4px}.items{width:100%;border-collapse:collapse}.items td{padding:10px 5px;border-bottom:1px solid #eef2f7;font-size:13px}.items td:last-child{text-align:right;font-weight:800}.total{font-size:26px;font-weight:900;text-align:right;margin-top:14px}.field{display:block;margin:14px 0 6px;font-size:12px;font-weight:800}.input{width:100%;padding:13px;border:1px solid #cbd5e1;border-radius:11px;font:inherit}.accept{display:flex;gap:10px;align-items:flex-start;margin:15px 0;padding:13px;border-radius:12px;background:#f8fafc}.accept input{width:22px;height:22px;accent-color:#16a34a;margin-top:1px}.btn{width:100%;border:0;border-radius:12px;padding:14px 16px;font-weight:900;font-size:16px;cursor:pointer;background:#16a34a;color:#fff}.ok{background:#dcfce7;color:#166534;border:1px solid #86efac;padding:16px;border-radius:14px}.bad{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:14px;border-radius:12px}.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#475569;word-break:break-all}@media(max-width:600px){.facts{grid-template-columns:1fr}.card{padding:17px}h1{font-size:23px}}
</style></head><body><header class="top"><div class="topin"><img src="favicon.png" alt="SRNU"><div><b>SRNU CUSTOM</b><span>Confirmación local de cotización</span></div></div></header><main class="wrap">
<section class="card"><h1><?=htmlspecialchars($c['cliente'],ENT_QUOTES,'UTF-8')?></h1><p class="muted">Revisa la información antes de confirmar.</p><div class="facts"><div class="fact"><small>Folio</small><strong><?=htmlspecialchars($c['folio'],ENT_QUOTES,'UTF-8')?></strong></div><div class="fact"><small>Vehículo</small><strong><?=htmlspecialchars($c['vehiculo'],ENT_QUOTES,'UTF-8')?></strong></div><div class="fact"><small>Fecha</small><strong><?=htmlspecialchars($c['fecha'],ENT_QUOTES,'UTF-8')?></strong></div><div class="fact"><small>Modalidad</small><strong><?=$c['requiere_factura']==='si'?'Con factura':'Sin factura'?></strong></div></div></section>
<?php $tgMin=(int)($c['dias_trabajo_general_min']??0);$tgMax=(int)($c['dias_trabajo_general_max']??0);$tienePedido=false;foreach(($c['items']??[]) as $ti){if(($ti['tipo_entrega']??'inmediata')==='sobre_pedido'){$tienePedido=true;break;}}if($tgMin>0||$tgMax>0):?>
<section class="card"><h2>Tiempo estimado de trabajo</h2><p style="margin:0"><strong><?=$tgMin>0&&$tgMax>0?($tgMin.' a '.$tgMax):($tgMax?:$tgMin)?> días</strong><?php if($tienePedido):?>, contados una vez recibidas <strong>todas las piezas necesarias</strong> en el taller.<?php else:?> de trabajo total estimado para el vehículo.<?php endif;?></p><p class="muted" style="margin-bottom:0">El plazo puede variar por el alcance final del trabajo y las condiciones del vehículo.</p></section>
<?php endif;?>
<section class="card"><h2>Conceptos</h2><table class="items"><?php foreach($c['items'] as $it):?><tr><td><?=htmlspecialchars((string)$it['concepto'],ENT_QUOTES,'UTF-8')?><div class="muted"><?=number_format((float)$it['cantidad'],2)?> × $<?=number_format((float)$it['precio'],2)?></div></td><td>$<?=number_format((float)$it['total'],2)?></td></tr><?php endforeach;?></table><div class="total">Total: $<?=number_format((float)$c['total'],2)?></div></section>
<?php if(!$enlaceVigente):?><section class="card"><div class="bad"><b>Este enlace ya no corresponde a la versión actual de la cotización.</b><br>Solicita al taller un enlace nuevo.</div></section>
<?php elseif(($confirmacion['estado']??'')==='anulada'):?><section class="card"><div class="bad"><b>Esta confirmación fue anulada por el taller.</b><br>Solicita un enlace nuevo antes de aceptar.</div></section>
<?php elseif($confirmada):?><section class="card"><div class="ok"><b>✓ Cotización confirmada correctamente</b><br>La aceptación quedó registrada el <?=htmlspecialchars((string)$confirmacion['confirmado_at'],ENT_QUOTES,'UTF-8')?>.</div><h3><?=htmlspecialchars((string)$confirmacion['nombre_confirmante'],ENT_QUOTES,'UTF-8')?></h3><p class="muted">Puedes cerrar esta ventana. El taller ya puede ver la confirmación.</p><div class="code">Verificación: <?=htmlspecialchars((string)$confirmacion['hash_verificacion'],ENT_QUOTES,'UTF-8')?></div></section>
<?php else:?><section class="card"><h2>Confirmar aceptación</h2><?php if($error):?><div class="bad"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif;?><form method="post"><input type="hidden" name="token" value="<?=htmlspecialchars($token,ENT_QUOTES,'UTF-8')?>"><label class="field">Nombre completo</label><input class="input" name="nombre_confirmante" required maxlength="180" value="<?=htmlspecialchars((string)($_POST['nombre_confirmante']??$c['cliente']),ENT_QUOTES,'UTF-8')?>"><label class="accept"><input type="checkbox" name="acepto" value="1" required><span>He revisado esta cotización y <b>confirmo y acepto los conceptos, importes y condiciones indicadas</b>.</span></label><button class="btn" type="submit">✓ Confirmo y acepto</button></form></section><?php endif;?>
<p class="muted" style="text-align:center">SRNU Custom · Modificación Automotriz · Confirmación dentro de la red local del taller</p></main></body></html>
