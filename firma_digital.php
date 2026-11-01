<?php
declare(strict_types=1);
require_once __DIR__ . '/config/erp.php';
require_once __DIR__ . '/config/lan.php';
require_once __DIR__ . '/config/web.php';
$pdo = srnu_db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$c = srnu_obtener_cotizacion($id);
if (!$c) { http_response_code(404); exit('Cotización no encontrada.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    if ($accion === 'regenerar') {
        srnu_preparar_firma_digital($id, true);
        header('Location: firma_digital.php?id='.$id.'&ok=regenerada'); exit;
    }
    if ($accion === 'anular') {
        $pdo->prepare("UPDATE firmas_digitales SET estado='anulada', updated_at=NOW() WHERE cotizacion_id=?")->execute([$id]);
        header('Location: firma_digital.php?id='.$id.'&ok=anulada'); exit;
    }
}
$firma = srnu_preparar_firma_digital($id, false);
$valida = $firma ? srnu_firma_digital_es_valida($c, $firma) : false;

function firma_base_url(string $host): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $dir = str_replace('\\','/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($dir === '/' || $dir === '.') $dir = '';
    $dir = str_replace(' ', '%20', rtrim($dir,'/'));
    return $scheme.'://'.$host.$dir;
}
$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$token = (string)($firma['token'] ?? '');
$link = firma_base_url($host).'/firmar.php?token='.rawurlencode($token);
$lanLink = '';
$lanIp = srnu_detectar_ip_lan($host);
if ($lanIp !== '') {
    $lanLink = firma_base_url($lanIp.srnu_puerto_http_actual($host)).'/firmar.php?token='.rawurlencode($token);
}
$publicBase = srnu_public_base_url();
$publicLink = $publicBase !== '' ? $publicBase.'/firmar.php?token='.rawurlencode($token) : '';
$status = (string)($firma['estado'] ?? 'pendiente');
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Firma digital · <?=eh($c['cliente'])?> · <?=eh($c['vehiculo'])?></title><link rel="icon" href="favicon.png"><?=srnu_ui_head()?>
<style>
.sig-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:16px}.sig-preview{background:#fff;border:1px solid var(--line,#e2e8f0);border-radius:18px;padding:20px}.sig-link{display:flex;gap:8px;align-items:stretch}.sig-link input{flex:1;min-width:0;padding:12px;border:1px solid #cbd5e1;border-radius:10px;font:inherit}.sig-link .btn{text-decoration:none!important;color:#fff!important;white-space:nowrap}.sig-link .btn.light{color:#0f172a!important}.sig-status{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:900}.sig-status.firmada{background:#dcfce7;color:#166534}.sig-status.pendiente{background:#dbeafe;color:#1d4ed8}.sig-status.anulada{background:#fee2e2;color:#991b1b}.sig-img{max-width:360px;width:100%;height:140px;object-fit:contain;background:#fff;border:1px solid #e2e8f0;border-radius:12px}.audit{font-size:12px;line-height:1.55;color:#64748b;word-break:break-word}@media(max-width:800px){.sig-grid{grid-template-columns:1fr}.sig-link{flex-direction:column}}
</style></head><body><?php srnu_appbar('cotizaciones');?><main class="w"><div class="srnu-page-heading"><div><h1>Firma digital</h1><p><?=eh($c['folio'])?> · <?=eh($c['cliente'])?> · <?=eh($c['vehiculo'])?></p></div><div class="toolbar"><a class="btn light" href="cotizaciones.php">← Cotizaciones</a><a class="btn light" href="trabajo.php?id=<?=$id?>">Panel del trabajo</a></div></div>
<?php if(($_GET['ok']??'')==='regenerada'):?><div class="srnu-ok">Se generó un enlace nuevo. La firma anterior, si existía, dejó de ser válida para este enlace.</div><?php endif;?>
<?php if(($_GET['ok']??'')==='anulada'):?><div class="srnu-security-note">La firma fue anulada. Genera un enlace nuevo para volver a solicitarla.</div><?php endif;?>
<div class="sig-grid" style="margin-top:16px"><section class="panel" style="padding:20px"><div class="toolbar" style="justify-content:space-between"><div><h2 style="margin:0 0 5px">Enlace para el cliente</h2><span class="sig-status <?=eh($status)?>"><?=strtoupper(eh($status))?></span></div></div><p class="muted">El cliente abre este enlace, revisa los datos principales de la cotización y firma con el dedo o mouse.</p>
<?php if($publicLink):?><div style="margin-bottom:14px"><label class="small"><b>Enlace público HTTPS · WhatsApp / fuera del taller</b></label><div class="sig-link"><input id="firma-publica" readonly value="<?=eh($publicLink)?>"><button class="btn blue" type="button" onclick="copyLink('firma-publica',this)">Copiar</button><button class="btn green" type="button" onclick="openFirma('firma-publica', true)">Probar</button></div><p class="audit">Este enlace usa la URL configurada en <a href="conexion_web.php">Acceso web</a> y funciona fuera del Wi‑Fi mientras el túnel esté activo.</p></div><?php endif;?><label class="small"><b>Enlace desde esta computadora</b></label><div class="sig-link"><input id="firma-link" readonly value="<?=eh($link)?>"><button class="btn blue" type="button" onclick="copyLink('firma-link',this)">Copiar</button><button class="btn green" type="button" onclick="openFirma('firma-link', false)">Abrir firma</button><button class="btn light" type="button" onclick="openFirma('firma-link', true)">Nueva pestaña</button></div>
<?php if($lanLink):?><div style="margin-top:14px"><label class="small"><b>Enlace para celular en la misma red Wi‑Fi</b></label><div class="sig-link"><input id="firma-lan" readonly value="<?=eh($lanLink)?>"><button class="btn blue" type="button" onclick="copyLink('firma-lan',this)">Copiar</button><button class="btn green" type="button" onclick="openFirma('firma-lan', false)">Probar enlace LAN</button><button class="btn light" type="button" onclick="openFirma('firma-lan', true)">Nueva pestaña</button></div><p class="audit">IP LAN detectada: <b><?=eh($lanIp)?></b>. El celular debe estar en la misma red que esta PC y Windows/Apache deben permitir conexiones locales.</p></div><?php endif;?>
<div class="toolbar" style="margin-top:18px"><form method="post" onsubmit="return confirm('¿Generar un enlace nuevo? La firma anterior dejará de aplicarse a este enlace.')"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="accion" value="regenerar"><button class="btn" type="submit">Regenerar enlace</button></form><?php if($status==='firmada'):?><form method="post" onsubmit="return confirm('¿Anular esta firma?')"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="accion" value="anular"><button class="btn danger" type="submit">Anular firma</button></form><?php endif;?></div></section>
<section class="sig-preview"><h2 style="margin-top:0">Estado de la aceptación</h2><?php if($status==='firmada'):?><?php if($valida):?><div class="srnu-ok"><b>Firma válida para la versión actual.</b></div><?php else:?><div class="srnu-security-note"><b>Atención:</b> la cotización cambió después de ser firmada. Genera un enlace nuevo y solicita una nueva firma.</div><?php endif;?><?php if(!empty($firma['firma_data'])):?><img class="sig-img" src="<?=eh($firma['firma_data'])?>" alt="Firma cliente"><?php endif;?><h3><?=eh($firma['nombre_firmante'] ?? '')?></h3><div class="audit">Firmado: <?=eh($firma['firmado_at'] ?? '')?><br>IP registrada: <?=eh($firma['ip_firmante'] ?? '')?><br>Verificación: <?=eh($firma['hash_verificacion'] ?? '')?><br>Documento: <?=eh($firma['snapshot_hash'] ?? '')?></div><?php elseif($status==='anulada'):?><p class="muted">La firma fue anulada. Usa “Regenerar enlace” para solicitar otra.</p><?php else:?><p class="muted">Aún no se ha firmado esta cotización.</p><?php endif;?></section></div></main>
<script>
async function copyLink(id,b){const el=document.getElementById(id);try{await navigator.clipboard.writeText(el.value)}catch(e){el.select();document.execCommand('copy')}const old=b.textContent;b.textContent='Copiado ✓';setTimeout(()=>b.textContent=old,1600)}
function openFirma(id,nueva){const el=document.getElementById(id);if(!el)return;const url=(el.value||'').trim();if(!url)return;if(nueva){const w=window.open(url,'_blank');if(w){try{w.opener=null}catch(e){}return}}window.location.href=url}
</script></body></html>
