<?php
declare(strict_types=1);
require_once __DIR__ . '/config/erp.php';
$pdo = srnu_db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$c = srnu_obtener_cotizacion($id);
if (!$c) { http_response_code(404); exit('Cotización no encontrada.'); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    if ($accion === 'reiniciar') {
        srnu_preparar_firma_taller($id, true);
        header('Location: firma_taller.php?id='.$id.'&ok=reiniciada'); exit;
    }
    if ($accion === 'anular') {
        $pdo->prepare("UPDATE firmas_taller SET estado='anulada', updated_at=NOW() WHERE cotizacion_id=?")->execute([$id]);
        header('Location: firma_taller.php?id='.$id.'&ok=anulada'); exit;
    }
    if ($accion === 'firmar') {
        $firma = srnu_preparar_firma_taller($id, false);
        $currentHash = srnu_hash_cotizacion_para_firma($c);
        if (!$firma) {
            $error = 'No fue posible preparar la firma del taller.';
        } elseif (!hash_equals((string)$firma['snapshot_hash'], $currentHash)) {
            $error = 'La cotización cambió. Recarga esta página antes de firmar.';
        } else {
            $persona = trim((string)($_POST['persona'] ?? ''));
            $otro = trim((string)($_POST['otro_nombre'] ?? ''));
            $permitidos = ['Sergio Núñez','Ricardo','Tomás','Otro'];
            if (!in_array($persona, $permitidos, true)) $persona = '';
            $nombre = $persona === 'Otro' ? $otro : $persona;
            $data = (string)($_POST['firma_data'] ?? '');
            if ($nombre === '') {
                $error = 'Selecciona o escribe quién firma por el taller.';
            } elseif (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $data, $m)) {
                $error = 'La firma no pudo leerse. Vuelve a dibujarla.';
            } else {
                $raw = base64_decode($m[1], true);
                if ($raw === false || strlen($raw) < 150 || strlen($raw) > 1500000) {
                    $error = 'La firma está vacía o es demasiado grande.';
                } else {
                    $at = date('Y-m-d H:i:s');
                    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
                    $verify = hash('sha256', $currentHash.'|TALLER|'.$nombre.'|'.$at.'|'.$data);
                    $upd = $pdo->prepare("UPDATE firmas_taller SET estado='firmada', nombre_firmante=?, firma_data=?, firmado_at=?, ip_firmante=?, user_agent=?, hash_verificacion=?, updated_at=NOW() WHERE cotizacion_id=?");
                    $upd->execute([$nombre,$data,$at,$ip,$ua,$verify,$id]);
                    header('Location: firma_taller.php?id='.$id.'&ok=firmada'); exit;
                }
            }
        }
    }
}
$firma = srnu_preparar_firma_taller($id, false);
$valida = $firma ? srnu_firma_taller_es_valida($c, $firma) : false;
$status = (string)($firma['estado'] ?? 'pendiente');
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Firma taller · <?=eh($c['cliente'])?> · <?=eh($c['vehiculo'])?></title><link rel="icon" href="favicon.png"><?=srnu_ui_head()?>
<style>
.sig-grid{display:grid;grid-template-columns:1fr .9fr;gap:16px}.sig-card{background:#fff;border:1px solid var(--line,#e2e8f0);border-radius:18px;padding:20px}.signbox{border:2px dashed #94a3b8;border-radius:14px;background:#fff;overflow:hidden;touch-action:none}.signbox canvas{width:100%;height:220px;display:block;touch-action:none}.sig-img{max-width:360px;width:100%;height:140px;object-fit:contain;background:#fff;border:1px solid #e2e8f0;border-radius:12px}.field{display:block;margin:13px 0 6px;font-size:12px;font-weight:900}.input{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px;font:inherit}.audit{font-size:12px;line-height:1.55;color:#64748b;word-break:break-word}.status{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:900}.status.firmada{background:#dcfce7;color:#166534}.status.pendiente{background:#dbeafe;color:#1d4ed8}.status.anulada{background:#fee2e2;color:#991b1b}@media(max-width:800px){.sig-grid{grid-template-columns:1fr}}
</style></head><body><?php srnu_appbar('cotizaciones');?><main class="w"><div class="srnu-page-heading"><div><h1>Firma del taller</h1><p><?=eh($c['folio'])?> · <?=eh($c['cliente'])?> · <?=eh($c['vehiculo'])?></p></div><div class="toolbar"><a class="btn light" href="cotizaciones.php">← Cotizaciones</a><a class="btn light" href="trabajo.php?id=<?=$id?>">Panel del trabajo</a><a class="btn blue" target="_blank" href="ver_pdf.php?id=<?=$id?>&tipo=<?=$c['requiere_factura']==='si'?'factura':'sin_factura'?>">Ver PDF</a></div></div>
<?php if($error):?><div class="srnu-security-note" style="margin-top:14px"><?=eh($error)?></div><?php endif;?>
<?php if(($_GET['ok']??'')==='firmada'):?><div class="srnu-ok" style="margin-top:14px">Firma del taller guardada correctamente.</div><?php endif;?>
<?php if(($_GET['ok']??'')==='reiniciada'):?><div class="srnu-ok" style="margin-top:14px">La firma del taller quedó lista para capturarse nuevamente.</div><?php endif;?>
<?php if(($_GET['ok']??'')==='anulada'):?><div class="srnu-security-note" style="margin-top:14px">La firma del taller fue anulada.</div><?php endif;?>
<div class="sig-grid" style="margin-top:16px"><section class="sig-card"><div class="toolbar" style="justify-content:space-between"><div><h2 style="margin:0 0 6px">Firmar por SRNU CUSTOM</h2><span class="status <?=eh($status)?>"><?=strtoupper(eh($status))?></span></div></div>
<?php if($status==='firmada' && $valida):?><div class="srnu-ok" style="margin-top:16px"><b>Firma válida para la versión actual.</b></div><?php endif;?>
<?php if($status==='firmada' && !$valida):?><div class="srnu-security-note" style="margin-top:16px"><b>Atención:</b> la cotización cambió después de esta firma. Debe firmarse nuevamente.</div><?php endif;?>
<?php if($status==='pendiente'):?><form method="post" id="taller-form"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="accion" value="firmar"><input type="hidden" name="firma_data" id="firma-data"><label class="field">Responsable que firma</label><select class="input" name="persona" id="persona" required><option value="">Seleccionar…</option><option>Sergio Núñez</option><option>Ricardo</option><option>Tomás</option><option>Otro</option></select><div id="otro-wrap" style="display:none"><label class="field">Nombre del responsable</label><input class="input" name="otro_nombre" id="otro-nombre" maxlength="180"></div><label class="field">Firma del taller</label><div class="signbox"><canvas id="pad"></canvas></div><div class="toolbar" style="margin-top:10px"><button class="btn light" type="button" id="clear">Borrar firma</button><button class="btn green" type="submit">Guardar firma del taller</button></div></form><?php endif;?>
<?php if(in_array($status,['firmada','anulada'],true)):?><div class="toolbar" style="margin-top:18px"><form method="post" onsubmit="return confirm('¿Capturar nuevamente la firma del taller?')"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="accion" value="reiniciar"><button class="btn" type="submit">Firmar nuevamente</button></form><form method="post" onsubmit="return confirm('¿Anular la firma del taller?')"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="accion" value="anular"><button class="btn danger" type="submit">Anular firma</button></form></div><?php endif;?></section>
<section class="sig-card"><h2 style="margin-top:0">Estado de la firma del taller</h2><?php if($status==='firmada'):?><?php if(!empty($firma['firma_data'])):?><img class="sig-img" src="<?=eh($firma['firma_data'])?>" alt="Firma del taller"><?php endif;?><h3><?=eh($firma['nombre_firmante']??'')?></h3><div class="audit">Firmado: <?=eh($firma['firmado_at']??'')?><?php if(!empty($firma['ip_firmante'])):?><br>IP: <?=eh($firma['ip_firmante'])?><?php endif;?><br>Verificación: <?=eh($firma['hash_verificacion']??'')?><br>Documento: <?=eh($firma['snapshot_hash']??'')?></div><?php elseif($status==='anulada'):?><p class="muted">La firma fue anulada. Usa “Firmar nuevamente” para capturar otra.</p><?php else:?><p class="muted">Aún no existe una firma del taller para esta cotización.</p><?php endif;?></section></div></main>
<?php if($status!=='firmada' || !$valida):?><script>
(function(){const c=document.getElementById('pad'),ctx=c.getContext('2d'),form=document.getElementById('taller-form'),out=document.getElementById('firma-data'),persona=document.getElementById('persona'),otro=document.getElementById('otro-wrap'),otroNombre=document.getElementById('otro-nombre');let drawing=false,hasInk=false,last=null;
persona.addEventListener('change',()=>{const on=persona.value==='Otro';otro.style.display=on?'block':'none';otroNombre.required=on});
function resize(){const r=c.getBoundingClientRect(),d=Math.max(1,window.devicePixelRatio||1),old=hasInk?c.toDataURL('image/png'):null;c.width=Math.round(r.width*d);c.height=Math.round(r.height*d);ctx.setTransform(d,0,0,d,0,0);ctx.lineWidth=2.3;ctx.lineCap='round';ctx.lineJoin='round';ctx.strokeStyle='#0f172a';if(old){const im=new Image();im.onload=()=>ctx.drawImage(im,0,0,r.width,r.height);im.src=old}}
function pt(e){const r=c.getBoundingClientRect(),p=e.touches?e.touches[0]:e;return{x:p.clientX-r.left,y:p.clientY-r.top}}
function start(e){e.preventDefault();drawing=true;last=pt(e)}function move(e){if(!drawing)return;e.preventDefault();const p=pt(e);ctx.beginPath();ctx.moveTo(last.x,last.y);ctx.lineTo(p.x,p.y);ctx.stroke();last=p;hasInk=true}function end(){drawing=false;last=null}
['mousedown','touchstart'].forEach(x=>c.addEventListener(x,start,{passive:false}));['mousemove','touchmove'].forEach(x=>c.addEventListener(x,move,{passive:false}));['mouseup','mouseleave','touchend','touchcancel'].forEach(x=>c.addEventListener(x,end));document.getElementById('clear').onclick=()=>{ctx.clearRect(0,0,c.width,c.height);hasInk=false};form.addEventListener('submit',e=>{if(!hasInk){e.preventDefault();alert('Dibuja la firma del taller antes de continuar.');return}out.value=c.toDataURL('image/png')});window.addEventListener('resize',resize);resize()})();
</script><?php endif;?></body></html>
