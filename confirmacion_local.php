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
        srnu_preparar_confirmacion_local($id, true);
        header('Location: confirmacion_local.php?id='.$id.'&ok=regenerada'); exit;
    }
    if ($accion === 'anular') {
        $pdo->prepare("UPDATE confirmaciones_locales SET estado='anulada', updated_at=NOW() WHERE cotizacion_id=?")->execute([$id]);
        header('Location: confirmacion_local.php?id='.$id.'&ok=anulada'); exit;
    }
}

$confirmacion = srnu_preparar_confirmacion_local($id, false);
$valida = $confirmacion ? srnu_confirmacion_local_es_valida($c, $confirmacion) : false;

function conf_base_url(string $host): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $dir = str_replace('\\','/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($dir === '/' || $dir === '.') $dir = '';
    $dir = str_replace(' ', '%20', rtrim($dir,'/'));
    return $scheme.'://'.$host.$dir;
}
$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$token = (string)($confirmacion['token'] ?? '');
$link = conf_base_url($host).'/confirmar_local.php?token='.rawurlencode($token);
$lanLink = '';
$lanIp = srnu_detectar_ip_lan($host);
if ($lanIp !== '') {
    $lanLink = conf_base_url($lanIp.srnu_puerto_http_actual($host)).'/confirmar_local.php?token='.rawurlencode($token);
}
$publicBase = srnu_public_base_url();
$publicLink = $publicBase !== '' ? $publicBase.'/confirmar_local.php?token='.rawurlencode($token) : '';
$status = (string)($confirmacion['estado'] ?? 'pendiente');
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Confirmación local · <?=eh($c['cliente'])?> · <?=eh($c['vehiculo'])?></title><link rel="icon" href="favicon.png"><?=srnu_ui_head()?>
<style>
.conf-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:16px}.conf-box{background:#fff;border:1px solid var(--line,#e2e8f0);border-radius:18px;padding:20px}.conf-link{display:flex;gap:8px;align-items:stretch}.conf-link input{flex:1;min-width:0;padding:12px;border:1px solid #cbd5e1;border-radius:10px;font:inherit}.conf-status{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:900}.conf-status.confirmada{background:#dcfce7;color:#166534}.conf-status.pendiente{background:#dbeafe;color:#1d4ed8}.conf-status.anulada{background:#fee2e2;color:#991b1b}.audit{font-size:12px;line-height:1.55;color:#64748b;word-break:break-word}.steps{margin:10px 0 0;padding-left:20px;color:#475569}.steps li{margin:7px 0}@media(max-width:800px){.conf-grid{grid-template-columns:1fr}.conf-link{flex-direction:column}}
html[data-theme="dark"] .conf-box{background:#0f172a;border-color:#334155}.conf-status.pendiente{color:#1d4ed8}
</style></head><body><?php srnu_appbar('cotizaciones');?><main class="w"><div class="srnu-page-heading"><div><h1>Confirmación local</h1><p><?=eh($c['folio'])?> · <?=eh($c['cliente'])?> · <?=eh($c['vehiculo'])?></p></div><div class="toolbar"><a class="btn light" href="cotizaciones.php">← Cotizaciones</a><a class="btn light" href="trabajo.php?id=<?=$id?>">Panel del trabajo</a></div></div>
<?php if(($_GET['ok']??'')==='regenerada'):?><div class="srnu-ok">Se generó un enlace nuevo de confirmación para la versión actual.</div><?php endif;?>
<?php if(($_GET['ok']??'')==='anulada'):?><div class="srnu-security-note">La confirmación fue anulada. Genera un enlace nuevo para solicitar otra aceptación.</div><?php endif;?>
<div class="conf-grid" style="margin-top:16px"><section class="panel" style="padding:20px"><div class="toolbar" style="justify-content:space-between"><div><h2 style="margin:0 0 5px">Enlace para aceptar</h2><span class="conf-status <?=eh($status)?>"><?=strtoupper(eh($status))?></span></div></div><p class="muted">El cliente revisa la cotización desde su celular y pulsa <b>Confirmo y acepto</b>. No necesita dibujar firma.</p>
<?php if($publicLink):?><div style="margin-bottom:14px"><label class="small"><b>Enlace público HTTPS · WhatsApp / fuera del taller</b></label><div class="conf-link"><input id="conf-publica" readonly value="<?=eh($publicLink)?>"><button class="btn blue" type="button" onclick="copyLink('conf-publica',this)">Copiar</button><a class="btn green" target="_blank" rel="noopener" href="<?=eh($publicLink)?>">Probar</a></div><p class="audit">Usa la URL guardada en <a href="conexion_web.php">Acceso web</a>.</p></div><?php endif;?><label class="small"><b>Enlace desde esta computadora</b></label><div class="conf-link"><input id="conf-link" readonly value="<?=eh($link)?>"><button class="btn blue" type="button" onclick="copyLink('conf-link',this)">Copiar</button><a class="btn green" href="<?=eh($link)?>">Abrir</a></div>
<?php if($lanLink):?><div style="margin-top:14px"><label class="small"><b>Enlace para celular en la misma red Wi‑Fi</b></label><div class="conf-link"><input id="conf-lan" readonly value="<?=eh($lanLink)?>"><button class="btn blue" type="button" onclick="copyLink('conf-lan',this)">Copiar</button><a class="btn green" href="<?=eh($lanLink)?>">Abrir</a></div><ol class="steps"><li>Conecta el celular al mismo Wi‑Fi que la computadora.</li><li>Abre este enlace en Chrome/Safari.</li><li>El cliente revisa la cotización, escribe su nombre y confirma.</li></ol><p class="audit">IP LAN detectada: <b><?=eh($lanIp)?></b>. Si el enlace no abre, Windows Firewall debe permitir Apache en redes privadas y la PC debe permanecer encendida.</p></div><?php else:?><div class="srnu-security-note" style="margin-top:14px"><b>No pude detectar automáticamente una IP privada de la PC.</b> Puedes consultar <code>ipconfig</code> en Windows y sustituir <code>localhost</code> por la IPv4 de la computadora.</div><?php endif;?>
<div class="toolbar" style="margin-top:18px"><form method="post" onsubmit="return confirm('¿Generar un enlace nuevo? La confirmación anterior dejará de aplicarse a este enlace.')"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="accion" value="regenerar"><button class="btn" type="submit">Regenerar enlace</button></form><?php if($status==='confirmada'):?><form method="post" onsubmit="return confirm('¿Anular esta confirmación?')"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="accion" value="anular"><button class="btn danger" type="submit">Anular confirmación</button></form><?php endif;?></div></section>
<section class="conf-box"><h2 style="margin-top:0">Estado de aceptación</h2><?php if($status==='confirmada'):?><?php if($valida):?><div class="srnu-ok"><b>✓ Confirmación válida para la versión actual.</b></div><?php else:?><div class="srnu-security-note"><b>Atención:</b> la cotización cambió después de la confirmación. Genera un enlace nuevo y solicita una nueva aceptación.</div><?php endif;?><h3><?=eh($confirmacion['nombre_confirmante'] ?? '')?></h3><div class="audit">Confirmado: <?=eh($confirmacion['confirmado_at'] ?? '')?><br>IP registrada: <?=eh($confirmacion['ip_confirmante'] ?? '')?><br>Verificación: <?=eh($confirmacion['hash_verificacion'] ?? '')?><br>Documento: <?=eh($confirmacion['snapshot_hash'] ?? '')?></div><?php elseif($status==='anulada'):?><p class="muted">La confirmación fue anulada. Usa “Regenerar enlace” para solicitar otra.</p><?php else:?><p class="muted">Aún no se ha confirmado esta cotización.</p><?php endif;?></section></div></main>
<script>async function copyLink(id,b){const el=document.getElementById(id);try{await navigator.clipboard.writeText(el.value)}catch(e){el.select();document.execCommand('copy')}const old=b.textContent;b.textContent='Copiado ✓';setTimeout(()=>b.textContent=old,1600)}</script></body></html>
