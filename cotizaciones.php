<?php
declare(strict_types=1);
require_once __DIR__ . '/config/erp.php';
$pdo = srnu_db();

$estados = ['Cotización', 'Aprobada', 'Anticipo recibido', 'Vehículo recibido', 'En proceso', 'Terminado', 'Entregado', 'Cancelado'];

// Liquidación rápida desde el listado.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['liquidar_rapido'])) {
    $id = (int)($_POST['id'] ?? 0);
    $metodo = trim((string)($_POST['metodo'] ?? 'Transferencia'));
    $destinatario = trim((string)($_POST['destinatario'] ?? 'General'));
    $entregar = !empty($_POST['marcar_entregado']);
    $metodosPermitidos = ['Transferencia', 'Efectivo', 'Tarjeta', 'Otro'];
    $destinatariosPermitidos = ['General', 'Sergio', 'Ricardo', 'Tomás', 'IVA'];
    if (!in_array($metodo, $metodosPermitidos, true)) $metodo = 'Transferencia';
    if (!in_array($destinatario, $destinatariosPermitidos, true)) $destinatario = 'General';

    $c = $id > 0 ? srnu_obtener_cotizacion($id) : null;
    if ($c) {
        $pagadoReal = srnu_total_pagado($id);

        if ($pagadoReal <= 0.009 && !empty($c['conservar_anticipo_recibido']) && (float)$c['anticipo_recibido_monto'] > 0.009) {
            $insAnt = $pdo->prepare('INSERT INTO pagos(cotizacion_id,fecha,metodo,monto,destinatario,notas,created_at) VALUES(?,?,?,?,?,?,NOW())');
            if (!empty($c['dividir_anticipo'])) {
                $partes = [
                    ['Sergio', (float)($c['anticipo_sergio'] ?? 0)],
                    ['Ricardo', (float)($c['anticipo_ricardo'] ?? 0)],
                    ['Tomás', (float)($c['anticipo_tomas'] ?? 0)],
                ];
                foreach ($partes as [$persona, $monto]) {
                    if ($monto > 0.009) $insAnt->execute([$id, $c['fecha'] ?: date('Y-m-d'), 'Transferencia', $monto, $persona, 'Importado desde anticipo ya recibido']);
                }
            } else {
                $insAnt->execute([$id, $c['fecha'] ?: date('Y-m-d'), 'Transferencia', (float)$c['anticipo_recibido_monto'], 'General', 'Importado desde anticipo ya recibido']);
            }
            $pagadoReal = srnu_total_pagado($id);
        }

        $saldo = round(max(0, (float)$c['total'] - $pagadoReal), 2);
        if ($saldo > 0.009) {
            $pdo->prepare('INSERT INTO pagos(cotizacion_id,fecha,metodo,monto,destinatario,notas,created_at) VALUES(?,?,?,?,?,?,NOW())')
                ->execute([$id, date('Y-m-d'), $metodo, $saldo, $destinatario, 'Liquidación rápida desde cotizaciones']);
        }

        if ($entregar) {
            $pdo->prepare("UPDATE cotizaciones SET estado='Entregado', updated_at=NOW() WHERE id=?")->execute([$id]);
            srnu_consumir_inventario_cotizacion($id);
            srnu_generar_postventa_entrega($id);
        }
    }
    $qs = $_GET;
    $qs['liquidado'] = '1';
    header('Location: cotizaciones.php?' . http_build_query($qs));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_estado'])) {
    $id = (int)($_POST['id'] ?? 0);
    $estado = (string)($_POST['estado'] ?? 'Cotización');
    if ($id > 0 && in_array($estado, $estados, true)) {
        if ($estado === 'Aprobada') {
            $pdo->prepare("UPDATE cotizaciones SET estado=?, aceptada_at=COALESCE(aceptada_at,NOW()), updated_at=NOW() WHERE id=?")->execute([$estado, $id]);
        } else {
            $pdo->prepare("UPDATE cotizaciones SET estado=?, updated_at=NOW() WHERE id=?")->execute([$estado, $id]);
        }
        if (in_array($estado, ['En proceso', 'Terminado', 'Entregado'], true)) srnu_consumir_inventario_cotizacion($id);
        if ($estado === 'Entregado') srnu_generar_postventa_entrega($id);
    }
    header('Location: cotizaciones.php?' . http_build_query($_GET));
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$estadoFiltro = trim((string)($_GET['estado'] ?? ''));
$factura = trim((string)($_GET['factura'] ?? ''));
$responsable = trim((string)($_GET['responsable'] ?? ''));
$desde = trim((string)($_GET['desde'] ?? ''));
$hasta = trim((string)($_GET['hasta'] ?? ''));
$min = trim((string)($_GET['min'] ?? ''));
$max = trim((string)($_GET['max'] ?? ''));

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(c.cliente LIKE ? OR c.telefono LIKE ? OR c.vehiculo LIKE ? OR c.folio LIKE ? OR EXISTS(SELECT 1 FROM recepciones r WHERE r.cotizacion_id=c.id AND r.placas LIKE ?) OR EXISTS(SELECT 1 FROM vehiculos v WHERE v.id=c.vehiculo_id AND (v.placas LIKE ? OR v.vin LIKE ?)))';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}
if (in_array($estadoFiltro, $estados, true)) {
    $where[] = 'c.estado=?';
    $params[] = $estadoFiltro;
}
if (in_array($factura, ['si', 'no'], true)) {
    $where[] = 'c.requiere_factura=?';
    $params[] = $factura;
}
if (in_array($responsable, ['sergio', 'ricardo', 'tomas'], true)) {
    $where[] = "EXISTS(SELECT 1 FROM cotizacion_items ci WHERE ci.cotizacion_id=c.id AND ci.responsable_{$responsable}=1)";
}
if ($desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
    $where[] = 'c.fecha>=?';
    $params[] = $desde;
}
if ($hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
    $where[] = 'c.fecha<=?';
    $params[] = $hasta;
}
if ($min !== '' && is_numeric($min)) {
    $where[] = 'c.total>=?';
    $params[] = (float)$min;
}
if ($max !== '' && is_numeric($max)) {
    $where[] = 'c.total<=?';
    $params[] = (float)$max;
}

$sql = "SELECT c.*,
        COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.cotizacion_id=c.id), 0) AS pagado,
        (SELECT fd.estado FROM firmas_digitales fd WHERE fd.cotizacion_id=c.id LIMIT 1) AS firma_estado,
        (SELECT fd.firmado_at FROM firmas_digitales fd WHERE fd.cotizacion_id=c.id LIMIT 1) AS firma_firmado_at,
        (SELECT ft.estado FROM firmas_taller ft WHERE ft.cotizacion_id=c.id LIMIT 1) AS firma_taller_estado,
        (SELECT ft.firmado_at FROM firmas_taller ft WHERE ft.cotizacion_id=c.id LIMIT 1) AS firma_taller_firmado_at,
        (SELECT cl.estado FROM confirmaciones_locales cl WHERE cl.cotizacion_id=c.id LIMIT 1) AS confirmacion_estado,
        (SELECT cl.confirmado_at FROM confirmaciones_locales cl WHERE cl.cotizacion_id=c.id LIMIT 1) AS confirmacion_confirmado_at
        FROM cotizaciones c" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY c.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cotizaciones = $stmt->fetchAll();

// Conteo rápido por estados para los segmented tabs
$countsByState = [];
$countsRaw = $pdo->query("SELECT estado, COUNT(*) as c FROM cotizaciones GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalGeneral = array_sum($countsRaw ?: []);

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es" data-theme="dark">
<head>
  <meta charset="utf-8">
  <title>Cotizaciones · Taller Pro</title>
  <?= srnu_ui_head('Cotizaciones · Taller Pro') ?>
  <style>
    /* Estilos específicos para la vista de Cotizaciones estilo iOS */
    .ios-segmented-scroll {
      display: flex;
      gap: 6px;
      overflow-x: auto;
      padding: 4px 2px 14px 2px;
      scrollbar-width: none;
      -webkit-overflow-scrolling: touch;
    }
    .ios-segmented-scroll::-webkit-scrollbar { display: none; }
    
    .ios-segment-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 14px;
      border-radius: 999px;
      background: var(--ios-card-bg);
      border: 1px solid var(--ios-card-border);
      color: var(--ios-muted);
      text-decoration: none;
      font-size: 13px;
      font-weight: 600;
      white-space: nowrap;
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .ios-segment-pill:hover {
      color: var(--ios-text);
      background: var(--ios-hover-bg);
    }
    .ios-segment-pill.active {
      background: var(--ios-primary);
      color: #ffffff;
      border-color: var(--ios-primary);
      box-shadow: 0 4px 14px rgba(10, 132, 255, 0.35);
    }
    .ios-segment-pill .pill-count {
      font-size: 11px;
      padding: 2px 6px;
      border-radius: 10px;
      background: rgba(255, 255, 255, 0.15);
    }
    .ios-segment-pill.active .pill-count {
      background: rgba(0, 0, 0, 0.25);
    }

    /* Barra de búsqueda y filtros rápidos */
    .filter-bar {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
      margin-bottom: 18px;
    }
    .search-input-wrap {
      flex: 1;
      min-width: 240px;
      position: relative;
    }
    .search-input-wrap svg {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--ios-muted);
      pointer-events: none;
    }
    .search-input-wrap input {
      width: 100%;
      background: var(--ios-input-bg);
      border: 1px solid var(--ios-card-border);
      border-radius: 12px;
      padding: 10px 14px 10px 36px;
      font-size: 14px;
      color: var(--ios-text);
      outline: none;
      transition: border-color 0.2s;
    }
    .search-input-wrap input:focus {
      border-color: var(--ios-primary);
      box-shadow: 0 0 0 3px rgba(10, 132, 255, 0.2);
    }

    /* Menú contextual dropdown estilo Apple */
    .ios-action-menu {
      position: relative;
      display: inline-block;
    }
    .ios-dots-btn {
      background: var(--ios-input-bg);
      border: 1px solid var(--ios-card-border);
      color: var(--ios-text);
      width: 34px;
      height: 34px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 16px;
      transition: background 0.15s;
    }
    .ios-dots-btn:hover {
      background: var(--ios-hover-bg);
    }
    .ios-popover {
      display: none;
      position: absolute;
      right: 0;
      top: 100%;
      margin-top: 6px;
      width: 220px;
      background: rgba(30, 30, 32, 0.95);
      backdrop-filter: blur(25px);
      -webkit-backdrop-filter: blur(25px);
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 16px;
      padding: 6px;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
      z-index: 100;
      animation: popIn 0.15s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .ios-popover.open { display: block; }
    @keyframes popIn {
      from { opacity: 0; transform: scale(0.95) translateY(-4px); }
      to { opacity: 1; transform: scale(1) translateY(0); }
    }
    .ios-popover-group {
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      padding: 4px 0;
      margin-bottom: 4px;
    }
    .ios-popover-group:last-child {
      border-bottom: none;
      margin-bottom: 0;
    }
    .ios-popover a, .ios-popover button {
      display: flex;
      align-items: center;
      gap: 10px;
      width: 100%;
      padding: 8px 10px;
      border-radius: 8px;
      color: var(--ios-text);
      text-decoration: none;
      font-size: 13px;
      font-weight: 500;
      background: none;
      border: none;
      text-align: left;
      cursor: pointer;
      transition: background 0.15s;
    }
    .ios-popover a:hover, .ios-popover button:hover {
      background: var(--ios-primary);
      color: #fff;
    }
    .ios-popover a.danger:hover {
      background: var(--ios-danger);
    }

    /* Modal Liquidación */
    .liq-modal {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.7);
      backdrop-filter: blur(10px);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      padding: 16px;
    }
    .liq-modal.open { display: flex; }
    .liq-card {
      background: var(--ios-card-bg);
      border: 1px solid var(--ios-card-border);
      border-radius: 24px;
      padding: 26px;
      max-width: 440px;
      width: 100%;
      box-shadow: 0 30px 80px rgba(0,0,0,0.7);
    }
  </style>
</head>
<body>

<?php srnu_appbar('cotizaciones'); ?>

<main class="w">
  <!-- Encabezado estilo Apple -->
  <div class="srnu-page-heading">
    <div>
      <div style="font-size:12px;font-weight:700;color:var(--ios-primary);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">
        Módulo Operativo
      </div>
      <h1>Cotizaciones</h1>
      <p>Gestión completa, firmas digitales, órdenes de trabajo y seguimiento de pagos.</p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn light" href="cotizador_rapido.php">⚡ Cotiz. Rápida</a>
      <a class="btn blue" href="cotizador.php">＋ Nueva Cotización</a>
    </div>
  </div>

  <!-- Segmented Control Bar (Filtros por Estado) -->
  <div class="ios-segmented-scroll">
    <a href="cotizaciones.php<?= $q ? '?q=' . urlencode($q) : '' ?>" class="ios-segment-pill <?= $estadoFiltro === '' ? 'active' : '' ?>">
      <span>Todas</span>
      <span class="pill-count"><?= (int)$totalGeneral ?></span>
    </a>
    <?php foreach ($estados as $est):
      $activeCl = ($estadoFiltro === $est) ? 'active' : '';
      $cnt = (int)($countsRaw[$est] ?? 0);
    ?>
      <a href="cotizaciones.php?estado=<?= urlencode($est) ?><?= $q ? '&q=' . urlencode($q) : '' ?>" class="ios-segment-pill <?= $activeCl ?>">
        <span><?= h($est) ?></span>
        <?php if ($cnt > 0): ?>
          <span class="pill-count"><?= $cnt ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Barra de Filtros y Búsqueda -->
  <form method="get" class="filter-bar">
    <?php if ($estadoFiltro): ?>
      <input type="hidden" name="estado" value="<?= h($estadoFiltro) ?>">
    <?php endif; ?>

    <div class="search-input-wrap">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Buscar por cliente, folio, vehículo, placas o teléfono..." autocomplete="off">
    </div>

    <select name="factura" onchange="this.form.submit()" style="padding:10px 14px;background:var(--ios-input-bg);border:1px solid var(--ios-card-border);border-radius:12px;color:var(--ios-text);font-size:13px;outline:none;">
      <option value="">Factura: Todos</option>
      <option value="si" <?= $factura === 'si' ? 'selected' : '' ?>>Con Factura</option>
      <option value="no" <?= $factura === 'no' ? 'selected' : '' ?>>Sin Factura</option>
    </select>

    <button type="submit" class="btn light" style="font-size:13px;padding:9px 15px;">Filtrar</button>
    <?php if ($q || $factura || $desde || $hasta || $min || $max): ?>
      <a href="cotizaciones.php<?= $estadoFiltro ? '?estado=' . urlencode($estadoFiltro) : '' ?>" class="btn light" style="font-size:13px;padding:9px 12px;color:var(--ios-danger);">Limpiar</a>
    <?php endif; ?>
  </form>

  <!-- Lista de Cotizaciones -->
  <div class="panel" style="padding:0;overflow:visible;">
    <?php if (!$cotizaciones): ?>
      <div style="text-align:center;padding:50px 20px;">
        <div style="font-size:42px;margin-bottom:10px;">📋</div>
        <h3 style="margin:0 0 6px;">No se encontraron cotizaciones</h3>
        <p class="muted" style="margin:0 0 16px;font-size:13px;">No hay registros que coincidan con los filtros seleccionados.</p>
        <a class="btn blue" href="cotizador.php">Crear primera cotización</a>
      </div>
    <?php else: ?>
      <div class="tablebox" style="overflow:visible;">
        <table>
          <thead>
            <tr>
              <th>Folio / Fecha</th>
              <th>Cliente / Vehículo</th>
              <th>Estado Operativo</th>
              <th>Pago y Saldo</th>
              <th style="text-align:right;">Total</th>
              <th style="text-align:right;">Saldo</th>
              <th style="text-align:center;width:120px;">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($cotizaciones as $c):
            $pag = (float)$c['pagado'];
            if (!$pag && !empty($c['conservar_anticipo_recibido'])) $pag = (float)$c['anticipo_recibido_monto'];
            $saldo = round(max(0, (float)$c['total'] - $pag), 2);
            $pagoEstado = $saldo <= 0.009 ? 'pagado' : ($pag > 0.009 ? 'parcial' : 'pendiente');
            
            // Badge color según estado
            $stBadge = 'badge-blue';
            if ($c['estado'] === 'Cotización') $stBadge = 'badge-purple';
            if ($c['estado'] === 'Terminado' || $c['estado'] === 'Entregado') $stBadge = 'badge-green';
            if ($c['estado'] === 'Cancelado') $stBadge = 'badge-red';
          ?>
            <tr>
              <td>
                <div style="font-weight:700;font-size:15px;color:var(--ios-primary);font-variant-numeric:tabular-nums;">
                  <?= h($c['folio']) ?>
                </div>
                <div class="muted" style="font-size:11px;margin-top:2px;">
                  <?= h($c['fecha']) ?>
                </div>
              </td>
              <td>
                <div style="font-weight:700;font-size:14px;color:var(--ios-text);">
                  <?= h($c['cliente']) ?>
                </div>
                <div class="muted" style="font-size:12px;margin-top:2px;display:flex;align-items:center;gap:6px;">
                  <span>🚗 <?= h($c['vehiculo']) ?></span>
                  <?php if (!empty($c['telefono'])): ?>
                    <span>· 📞 <?= h($c['telefono']) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <form method="post" style="display:inline-block;margin-bottom:4px;">
                  <input type="hidden" name="actualizar_estado" value="1">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <select name="estado" onchange="this.form.submit()" style="background:var(--ios-input-bg);border:1px solid var(--ios-card-border);border-radius:8px;padding:4px 8px;font-size:12px;font-weight:600;color:var(--ios-text);">
                    <?php foreach ($estados as $es): ?>
                      <option value="<?= h($es) ?>" <?= $c['estado'] === $es ? 'selected' : '' ?>><?= h($es) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
                <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:2px;">
                  <?php if (($c['firma_estado'] ?? '') === 'firmada'): ?>
                    <span class="badge badge-green" style="font-size:10px;">Firma Cliente ✓</span>
                  <?php endif; ?>
                  <?php if (($c['firma_taller_estado'] ?? '') === 'firmada'): ?>
                    <span class="badge badge-green" style="font-size:10px;">Firma Taller ✓</span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;cursor:pointer;">
                  <input type="checkbox" <?= $pagoEstado === 'pagado' ? 'checked disabled' : '' ?>
                    <?php if ($pagoEstado !== 'pagado'): ?>
                      class="js-liquidar" data-id="<?= (int)$c['id'] ?>" data-cliente="<?= h($c['cliente']) ?>" data-vehiculo="<?= h($c['vehiculo']) ?>" data-saldo="<?= number_format($saldo, 2, '.', '') ?>" data-estado="<?= h((string)$c['estado']) ?>"
                    <?php endif; ?>
                    style="accent-color:var(--ios-success);width:16px;height:16px;">
                  <span><?= $pagoEstado === 'pagado' ? '<span class="badge badge-green">PAGADO</span>' : ($pagoEstado === 'parcial' ? '<span class="badge badge-warning">ABONADO</span>' : '<span class="badge badge-red">PENDIENTE</span>') ?></span>
                </label>
              </td>
              <td style="text-align:right;font-weight:700;font-size:14px;font-variant-numeric:tabular-nums;">
                <?= emoney($c['total']) ?>
              </td>
              <td style="text-align:right;font-weight:700;font-size:14px;font-variant-numeric:tabular-nums;color:<?= $saldo > 0.009 ? 'var(--ios-warning)' : 'var(--ios-success)' ?>;">
                <?= emoney($saldo) ?>
              </td>
              <td style="text-align:center;">
                <div style="display:inline-flex;gap:6px;align-items:center;">
                  <a class="btn light" style="font-size:11px;padding:6px 10px;" href="cotizador.php?id=<?= (int)$c['id'] ?>" title="Abrir y editar cotización">Abrir</a>

                  <!-- Menú de Acciones estilo Apple HIG Popover -->
                  <div class="ios-action-menu">
                    <button type="button" class="ios-dots-btn" onclick="togglePopover(this, event)">···</button>
                    <div class="ios-popover">
                      <div class="ios-popover-group">
                        <a href="trabajo.php?id=<?= (int)$c['id'] ?>">🛠️ Panel de Trabajo</a>
                        <a href="recepcion.php?id=<?= (int)$c['id'] ?>">📋 Check-in Recepción</a>
                        <a href="orden_trabajo.php?id=<?= (int)$c['id'] ?>">📝 Orden de Trabajo</a>
                      </div>
                      <div class="ios-popover-group">
                        <a target="_blank" href="ver_pdf.php?id=<?= (int)$c['id'] ?>&tipo=<?= $c['requiere_factura'] === 'si' ? 'factura' : 'sin_factura' ?>">👁️ Ver PDF</a>
                        <a href="generar_pdf.php?id=<?= (int)$c['id'] ?>&tipo=<?= $c['requiere_factura'] === 'si' ? 'factura' : 'sin_factura' ?>&accion=descargar">⬇️ Descargar PDF</a>
                        <a target="_blank" href="whatsapp.php?id=<?= (int)$c['id'] ?>">💬 Enviar WhatsApp</a>
                      </div>
                      <div class="ios-popover-group">
                        <a href="pagos.php?id=<?= (int)$c['id'] ?>">💵 Pagos y Abonos</a>
                        <a href="firma_digital.php?id=<?= (int)$c['id'] ?>">✍️ Firma Cliente</a>
                        <a href="firma_taller.php?id=<?= (int)$c['id'] ?>">✍️ Firma Taller</a>
                        <a href="galeria.php?id=<?= (int)$c['id'] ?>">📷 Galería / Fotos</a>
                      </div>
                      <div class="ios-popover-group">
                        <a href="duplicar_cotizacion.php?id=<?= (int)$c['id'] ?>" onclick="return confirm('¿Duplicar cotización?')">📑 Duplicar</a>
                        <a class="danger" style="color:var(--ios-danger);" href="eliminar_cotizacion.php?id=<?= (int)$c['id'] ?>" onclick="return confirm('¿Eliminar <?= h($c['folio']) ?>?')">🗑️ Eliminar</a>
                      </div>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</main>

<!-- Modal Liquidación Rápida -->
<div class="liq-modal" id="liq-modal" aria-hidden="true">
  <div class="liq-card">
    <h3 style="margin:0 0 6px;">Liquidar Cotización</h3>
    <div class="muted" style="font-size:13px;margin-bottom:14px;" id="liq-desc"></div>
    <div style="font-size:28px;font-weight:800;color:var(--ios-success);margin-bottom:18px;font-variant-numeric:tabular-nums;" id="liq-balance">$0.00</div>
    
    <form method="post" id="liq-form">
      <input type="hidden" name="id" id="liq-id">
      <input type="hidden" name="liquidar_rapido" value="1">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:var(--ios-muted);">Método de pago</label>
          <select name="metodo" style="width:100%;padding:10px;background:var(--ios-input-bg);border:1px solid var(--ios-card-border);border-radius:10px;color:var(--ios-text);">
            <option>Transferencia</option>
            <option>Efectivo</option>
            <option>Tarjeta</option>
            <option>Otro</option>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:var(--ios-muted);">Registrar para</label>
          <select name="destinatario" style="width:100%;padding:10px;background:var(--ios-input-bg);border:1px solid var(--ios-card-border);border-radius:10px;color:var(--ios-text);">
            <option>General</option>
            <option>Sergio</option>
            <option>Ricardo</option>
            <option>Tomás</option>
            <option>IVA</option>
          </select>
        </div>
      </div>

      <label style="display:flex;align-items:flex-start;gap:10px;margin-bottom:20px;cursor:pointer;background:var(--ios-hover-bg);padding:12px;border-radius:12px;">
        <input type="checkbox" name="marcar_entregado" id="liq-entregado" value="1" style="accent-color:var(--ios-primary);margin-top:2px;">
        <div>
          <strong style="font-size:13px;">Marcar vehículo como Entregado</strong>
          <div class="muted" style="font-size:11px;margin-top:2px;">Activa recordatorios de postventa y garantías automáticamente.</div>
        </div>
      </label>

      <div style="display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" class="btn light" id="liq-cancel">Cancelar</button>
        <button type="submit" class="btn green">Registrar Pago Final</button>
      </div>
    </form>
  </div>
</div>

<script>
// Manejo del Popover de Acciones estilo Apple
function togglePopover(btn, e) {
  e.stopPropagation();
  const popover = btn.nextElementSibling;
  const isOpen = popover.classList.contains('open');
  
  // Cerrar todos los popovers abiertos
  document.querySelectorAll('.ios-popover.open').forEach(p => p.classList.remove('open'));
  
  if (!isOpen) {
    popover.classList.add('open');
  }
}

document.addEventListener('click', () => {
  document.querySelectorAll('.ios-popover.open').forEach(p => p.classList.remove('open'));
});

// Modal de Liquidación Rápida
(function() {
  const modal = document.getElementById('liq-modal');
  const id = document.getElementById('liq-id');
  const desc = document.getElementById('liq-desc');
  const bal = document.getElementById('liq-balance');
  const entregar = document.getElementById('liq-entregado');

  function closeModal() {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.querySelectorAll('.js-liquidar').forEach(x => {
      if (!x.disabled) x.checked = false;
    });
  }

  document.querySelectorAll('.js-liquidar').forEach(cb => cb.addEventListener('change', function() {
    if (!this.checked) return;
    id.value = this.dataset.id || '';
    desc.textContent = (this.dataset.cliente || 'Cliente') + ' · ' + (this.dataset.vehiculo || 'Vehículo');
    const n = Number(this.dataset.saldo || 0);
    bal.textContent = n.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });
    entregar.checked = (this.dataset.estado === 'Terminado');
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
  }));

  document.getElementById('liq-cancel').addEventListener('click', closeModal);
  modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal.classList.contains('open')) closeModal(); });
})();
</script>

</body>
</html>
