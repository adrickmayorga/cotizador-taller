<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/bbva_pyme.php';

$id = (int)($_GET['id'] ?? 0);
$tipo = (($_GET['tipo'] ?? 'factura') === 'sin_factura') ? 'sin_factura' : 'factura';
if ($id <= 0) { http_response_code(400); exit('ID de cotización inválido.'); }
$cotizacion = srnu_obtener_cotizacion($id);
if (!$cotizacion) { http_response_code(404); exit('Cotización no encontrada.'); }
if (srnu_cotizacion_usa_bbva($cotizacion, $tipo === 'factura')) $tipo = 'factura';

$tabTitle = trim((string)$cotizacion['cliente']) . ' - ' . trim((string)$cotizacion['vehiculo']);
$faviconVersion = @filemtime(__DIR__ . '/favicon.png') ?: 1;
$pdfUrl = 'generar_pdf.php?' . http_build_query(['id'=>$id,'tipo'=>$tipo,'accion'=>'ver']);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($tabTitle, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="favicon.png?v=<?= (int)$faviconVersion ?>">
<link rel="shortcut icon" type="image/png" href="favicon.png?v=<?= (int)$faviconVersion ?>">
<style>
html,body{height:100%;margin:0;background:#202124;font-family:Arial,Helvetica,sans-serif}.bar{height:48px;background:#111;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 14px;box-sizing:border-box;border-bottom:2px solid #1e5fd9}.bar strong{font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.bar a{color:#fff;text-decoration:none;background:#1e5fd9;border-radius:6px;padding:8px 12px;font-size:12px;font-weight:700}.viewer{display:block;width:100%;height:calc(100% - 50px);border:0;background:#fff}
</style>
</head>
<body>
<div class="bar"><strong><?= htmlspecialchars($tabTitle, ENT_QUOTES, 'UTF-8') ?></strong><a href="cotizaciones.php">← Cotizaciones</a></div>
<iframe class="viewer" title="PDF <?= htmlspecialchars($tabTitle, ENT_QUOTES, 'UTF-8') ?>" src="<?= htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8') ?>"></iframe>
</body>
</html>
