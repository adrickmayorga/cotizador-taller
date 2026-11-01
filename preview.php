<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/bbva_pyme.php';

$id = (int)($_GET['id'] ?? 0);
$tipo = (($_GET['tipo'] ?? 'factura') === 'sin_factura') ? 'sin_factura' : 'factura';
if ($id <= 0) { http_response_code(400); exit('ID de cotización inválido.'); }
$cotizacion = srnu_obtener_cotizacion($id);
if (!$cotizacion) { http_response_code(404); exit('Cotización no encontrada.'); }
$bbva_pyme = srnu_cotizacion_usa_bbva($cotizacion, $tipo === 'factura');
if ($bbva_pyme) $tipo = 'factura';
$etiqueta = $tipo === 'factura' ? 'CON FACTURA' : 'SIN FACTURA';
$filename = srnu_pdf_filename((string)$cotizacion['cliente'], (string)$cotizacion['vehiculo'], $tipo === 'factura' ? 'si' : 'no');
$subtotalInterno = round((float)($cotizacion['subtotal'] ?? 0), 2);
$ivaInterno = $tipo === 'factura' ? round($subtotalInterno * 0.16, 2) : 0.00;
$resumenInterno = srnu_resumen_interno(
    $cotizacion['items'] ?? [],
    $subtotalInterno,
    $ivaInterno,
    !empty($cotizacion['dividir_anticipo']),
    (float)($cotizacion['anticipo_sergio'] ?? 0),
    (float)($cotizacion['anticipo_ricardo'] ?? 0),
    (float)($cotizacion['anticipo_tomas'] ?? 0)
);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(trim((string)$cotizacion['cliente']) . ' - ' . trim((string)$cotizacion['vehiculo']), ENT_QUOTES, 'UTF-8') ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="favicon.png?v=<?= (int)(@filemtime(__DIR__ . '/favicon.png') ?: 1) ?>">
<link rel="shortcut icon" type="image/png" href="favicon.png?v=<?= (int)(@filemtime(__DIR__ . '/favicon.png') ?: 1) ?>">
<style>
*{box-sizing:border-box}body{margin:0;background:#202124;font-family:Arial,Helvetica,sans-serif;color:#fff}.toolbar{position:sticky;top:0;z-index:50;min-height:68px;background:#101010;border-bottom:3px solid #1769ff;display:flex;align-items:center;justify-content:center;gap:24px;padding:12px 22px;box-shadow:0 3px 15px rgba(0,0,0,.35)}.toolbar>div:first-child{flex:1;max-width:650px}.title{font-weight:800;font-size:16px}.meta{font-size:12px;color:#bbb;margin-top:4px}.actions{display:flex;gap:12px;flex-wrap:wrap;justify-content:center;align-items:center}.btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:0;border-radius:8px;min-width:170px;padding:11px 17px;font-weight:800;font-size:13px;cursor:pointer}.back{background:#333;color:#fff}.download{background:#1769ff;color:#fff}.interno{width:min(1100px,calc(100% - 44px));margin:18px auto 0;background:#f7f9ff;color:#111;border:2px solid #1769ff;border-radius:12px;padding:14px}.interno-head{display:flex;justify-content:space-between;gap:14px;margin-bottom:10px}.interno-head strong{color:#173f91}.interno-head span{font-size:11px;color:#666;text-align:right}.interno-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}.interno-card{background:#fff;border:1px solid #d9dde5;border-radius:9px;padding:10px}.interno-card.iva{border-style:dashed;background:#fffdf5}.interno-card .name{font-weight:800;margin-bottom:7px}.interno-card .line{display:flex;justify-content:space-between;gap:8px;font-size:11px;margin:5px 0;color:#555}.interno-card .line b{color:#111}.interno-card .remaining{border-top:1px solid #e5e7eb;padding-top:7px;margin-top:7px;font-weight:800}.warn{margin-top:9px;padding:8px 10px;border-radius:7px;background:#fff4e5;color:#7a4a00;font-size:11px}.note{font-size:10px;color:#666;margin-top:9px}.frame-wrap{padding:18px 22px 22px;display:flex;justify-content:center}.frame{width:min(1100px,100%);height:calc(100vh - 315px);min-height:520px;border:0;background:#fff;box-shadow:0 4px 30px rgba(0,0,0,.5);border-radius:4px}@media(max-width:900px){.interno-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.toolbar{align-items:flex-start;flex-direction:column}.actions{width:100%}.btn{flex:1}.interno{width:calc(100% - 16px);margin-top:8px}.interno-grid{grid-template-columns:1fr}.interno-head{flex-direction:column}.interno-head span{text-align:left}.frame-wrap{padding:8px}.frame{height:65vh;min-height:430px}}
</style>
</head>
<body>
<div class="toolbar">
  <div><div class="title">VISTA PREVIA · <?= $etiqueta ?></div><div class="meta"><?= htmlspecialchars((string)$cotizacion['cliente'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)$cotizacion['vehiculo'], ENT_QUOTES, 'UTF-8') ?></div></div>
  <div class="actions">
    <a class="btn back" href="cotizador.php?id=<?= $id ?>">← Volver / Editar</a>
    <a class="btn download" href="generar_pdf.php?id=<?= $id ?>&tipo=<?= urlencode($tipo) ?>&accion=descargar">Descargar PDF</a>
  </div>
</div>
<div class="interno">
  <div class="interno-head"><strong>CONTROL INTERNO · SALDOS RESTANTES</strong><span>Solo SRNU CUSTOM · No se incluye en el PDF descargado</span></div>
  <div class="interno-grid">
    <?php foreach (['sergio'=>'Sergio','ricardo'=>'Ricardo','tomas'=>'Tomás'] as $k=>$n): $d=$resumenInterno['personas'][$k]; ?>
    <div class="interno-card">
      <div class="name"><?= htmlspecialchars($n, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="line"><span>Le corresponde</span><b>$<?= number_format($d['corresponde'],2) ?></b></div>
      <div class="line"><span>Anticipo recibido</span><b>$<?= number_format($d['anticipo'],2) ?></b></div>
      <div class="line remaining"><span>Falta por recibir</span><b>$<?= number_format($d['restante'],2) ?></b></div>
      <?php if ($d['excedente'] > 0): ?><div class="line" style="color:#a33"><span>Excedente recibido</span><b>$<?= number_format($d['excedente'],2) ?></b></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if ($tipo === 'factura'): $d=$resumenInterno['iva']; ?>
    <div class="interno-card iva">
      <div class="name">IVA</div>
      <div class="line"><span>Le corresponde</span><b>$<?= number_format($d['corresponde'],2) ?></b></div>
      <div class="line"><span>Anticipo recibido</span><b>$0.00</b></div>
      <div class="line remaining"><span>Falta por recibir</span><b>$<?= number_format($d['restante'],2) ?></b></div>
      <div class="line"><span>Participación</span><b>Común</b></div>
    </div>
    <?php endif; ?>
  </div>
  <?php if (($resumenInterno['sin_asignar'] ?? 0) > 0): ?><div class="warn">Conceptos sin responsable asignado: <strong>$<?= number_format($resumenInterno['sin_asignar'],2) ?></strong>.</div><?php endif; ?>
  <?php if (empty($cotizacion['dividir_anticipo'])): ?><div class="warn">El anticipo no tiene distribución por integrante; por eso el resumen no descuenta adelantos individuales.</div><?php endif; ?>
  <div class="note">Los conceptos compartidos se dividen por igual entre sus responsables. <?= $tipo === 'factura' ? 'El IVA se trata como una cuarta participación interna común y queda fuera del anticipo.' : 'Esta modalidad no incluye IVA.' ?></div>
</div>
<div class="frame-wrap"><iframe class="frame" title="Vista previa de cotización" src="cotizador.php?id=<?= $id ?>&modo_pdf=1&preview=1&tipo=<?= urlencode($tipo) ?>"></iframe></div>
</body></html>
