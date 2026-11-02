<?php
declare(strict_types=1);

require_once __DIR__ . '/config/erp.php';
require_once __DIR__ . '/config/web.php';

$pdo = srnu_db();
$mes = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');
$ini = $mes . '-01';
$fin = date('Y-m-t', strtotime($ini));

function dval(PDO $p, string $sql, array $a = []): float {
    $s = $p->prepare($sql);
    $s->execute($a);
    return (float)$s->fetchColumn();
}

$nombreTaller = trim(srnu_cfg_get('taller_nombre', 'SRNU CUSTOM'));
if ($nombreTaller === '') $nombreTaller = 'Taller Pro';

// Métricas de ventas y flujo
$cotNum = dval($pdo, "SELECT COUNT(*) FROM cotizaciones WHERE fecha BETWEEN ? AND ? AND estado<>'Cancelado'", [$ini, $fin]);
$cotTotal = dval($pdo, "SELECT COALESCE(SUM(total),0) FROM cotizaciones WHERE fecha BETWEEN ? AND ? AND estado<>'Cancelado'", [$ini, $fin]);
$cob = dval($pdo, 'SELECT COALESCE(SUM(monto),0) FROM pagos WHERE fecha BETWEEN ? AND ?', [$ini, $fin]);
$gas = dval($pdo, 'SELECT COALESCE(SUM(monto),0) FROM gastos WHERE fecha BETWEEN ? AND ?', [$ini, $fin]);
$cr = dval($pdo, 'SELECT COALESCE(SUM(monto),0) FROM costos_reales WHERE fecha BETWEEN ? AND ?', [$ini, $fin]);
$saldo = dval($pdo, "SELECT COALESCE(SUM(GREATEST(0, c.total - COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.cotizacion_id = c.id), 0))), 0) FROM cotizaciones c WHERE c.estado<>'Cancelado'");
$flujoNeto = $cob - ($gas + $cr);

// Alertas operativas
$low = (int)$pdo->query('SELECT COUNT(*) FROM inventario WHERE activo = 1 AND stock <= stock_minimo')->fetchColumn();
$orders = (int)$pdo->query("SELECT COUNT(*) FROM compras WHERE estatus IN ('Solicitado', 'Pagado', 'En tránsito')")->fetchColumn();
$post = (int)$pdo->query("SELECT COUNT(*) FROM postventa_recordatorios WHERE estado = 'Pendiente' AND fecha_recordatorio <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();

// Agenda de hoy y recientes
$today = $pdo->query("SELECT a.*, c.cliente, c.vehiculo, c.folio FROM agenda_trabajos a LEFT JOIN cotizaciones c ON c.id = a.cotizacion_id WHERE CURDATE() BETWEEN a.fecha_inicio AND COALESCE(a.fecha_entrega, a.fecha_inicio) ORDER BY a.hora_inicio, a.id")->fetchAll();
$recent = $pdo->query("SELECT c.*, COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.cotizacion_id = c.id), 0) pagado FROM cotizaciones c ORDER BY c.id DESC LIMIT 8")->fetchAll();
$alerts = $pdo->query("SELECT nombre, stock, stock_minimo, unidad FROM inventario WHERE activo = 1 AND stock <= stock_minimo ORDER BY stock - stock_minimo LIMIT 6")->fetchAll();

$diasSemana = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$mesesNombres = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
$fechaLegible = $diasSemana[(int)date('w')] . ', ' . date('j') . ' de ' . $mesesNombres[(int)date('n')];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Dashboard · <?= eh($nombreTaller) ?></title>
    <link rel="icon" href="<?= eh(srnu_cfg_get('taller_logo', 'favicon.png')) ?>">
    <?= srnu_ui_head('Dashboard') ?>
</head>
<body>
<?php srnu_appbar('dashboard'); ?>

<main class="w">
    <!-- Encabezado Estilo Apple -->
    <div class="srnu-page-heading">
        <div>
            <h1>Centro de Control</h1>
            <p><?= eh($fechaLegible) ?> · Resumen de operaciones y finanzas.</p>
        </div>
        <form class="ios-month-picker" method="get">
            <span style="font-size:12px;color:var(--ios-muted);margin-right:8px;font-weight:600;">Mes:</span>
            <input type="month" name="mes" value="<?= eh($mes) ?>" data-auto-submit>
        </form>
    </div>

    <!-- Widgets KPI Estilo iOS -->
    <div class="kpi-grid">
        <div class="kpi">
            <small>Cotizado en el mes</small>
            <strong><?= emoney($cotTotal) ?></strong>
            <span class="muted"><?= number_format($cotNum) ?> cotización(es) emitidas</span>
        </div>
        <div class="kpi">
            <small>Cobrado en el mes</small>
            <strong><?= emoney($cob) ?></strong>
            <span class="badge badge-green" style="font-size:10px;">Pagos registrados</span>
        </div>
        <div class="kpi">
            <small>Gastos + Costos Reales</small>
            <strong><?= emoney($gas + $cr) ?></strong>
            <span class="muted">Flujo neto: <strong style="color:<?= $flujoNeto >= 0 ? 'var(--ios-success)' : 'var(--ios-danger)' ?>;"><?= emoney($flujoNeto) ?></strong></span>
        </div>
        <div class="kpi">
            <small>Saldo pendiente global</small>
            <strong style="color:var(--ios-warning);"><?= emoney($saldo) ?></strong>
            <span class="muted">Total por cobrar a clientes</span>
        </div>
    </div>

    <!-- Tarjetas de Estado Operativo -->
    <div class="grid-3" style="margin-bottom: 20px;">
        <a class="card" style="text-decoration:none;color:inherit;" href="inventario.php">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:14px;">Stock en Alerta</h3>
                <span class="badge <?= $low > 0 ? 'badge-red' : 'badge-green' ?>"><?= $low ?></span>
            </div>
            <strong style="font-size:26px;margin:8px 0 2px;display:block;"><?= $low ?></strong>
            <p class="muted" style="font-size:11.5px;">Artículos en nivel mínimo o menor</p>
        </a>
        <a class="card" style="text-decoration:none;color:inherit;" href="compras.php">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:14px;">Pedidos Abiertos</h3>
                <span class="badge badge-blue"><?= $orders ?></span>
            </div>
            <strong style="font-size:26px;margin:8px 0 2px;display:block;"><?= $orders ?></strong>
            <p class="muted" style="font-size:11.5px;">Solicitados, pagados o en tránsito</p>
        </a>
        <a class="card" style="text-decoration:none;color:inherit;" href="postventa.php">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:14px;">Postventa y Garantías</h3>
                <span class="badge badge-orange"><?= $post ?></span>
            </div>
            <strong style="font-size:26px;margin:8px 0 2px;display:block;"><?= $post ?></strong>
            <p class="muted" style="font-size:11.5px;">Recordatorios en los próximos 30 días</p>
        </a>
    </div>

    <!-- Grids Operativos: Agenda y Alertas -->
    <div class="grid-2" style="margin-bottom: 20px;">
        <section class="panel">
            <div class="section-title">
                <h3>Agenda de Hoy</h3>
                <a class="btn light" style="font-size:11.5px;padding:5px 10px;" href="agenda.php">Ver agenda</a>
            </div>
            <?php if (!$today): ?>
                <p class="muted" style="padding:16px 0;text-align:center;">No hay trabajos programados para el día de hoy.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Trabajo</th>
                            <th>Vehículo / Cliente</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($today as $r): ?>
                        <tr>
                            <td style="font-weight:700;color:var(--ios-primary);"><?= eh(substr((string)$r['hora_inicio'], 0, 5)) ?></td>
                            <td>
                                <b><?= eh($r['titulo']) ?></b>
                                <div class="muted" style="font-size:11px;"><?= eh($r['responsable'] ?: 'Sin asignar') ?></div>
                            </td>
                            <td>
                                <?= eh($r['cliente']) ?>
                                <div class="muted" style="font-size:11px;"><?= eh($r['vehiculo']) ?></div>
                            </td>
                            <td><span class="badge badge-blue"><?= eh($r['estado']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="section-title">
                <h3>Alertas de Refacciones</h3>
                <a class="btn light" style="font-size:11.5px;padding:5px 10px;" href="inventario.php">Ver inventario</a>
            </div>
            <?php if (!$alerts): ?>
                <p class="muted" style="padding:16px 0;text-align:center;">Inventario con stock en niveles óptimos.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th>Stock Actual</th>
                            <th>Mínimo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($alerts as $r): ?>
                        <tr>
                            <td><b><?= eh($r['nombre']) ?></b></td>
                            <td><span class="badge badge-red"><?= number_format((float)$r['stock'], 2) ?> <?= eh($r['unidad']) ?></span></td>
                            <td style="color:var(--ios-muted);"><?= number_format((float)$r['stock_minimo'], 2) ?> <?= eh($r['unidad']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>

    <!-- Cotizaciones Recientes -->
    <section class="panel" style="margin-bottom: 20px;">
        <div class="section-title">
            <div>
                <h3>Cotizaciones Recientes</h3>
                <p class="muted" style="margin:2px 0 0;font-size:12px;">Últimas cotizaciones registradas en el sistema.</p>
            </div>
            <div style="display:flex;gap:8px;">
                <a class="btn light" href="cotizador_rapido.php">⚡ Cotiz. Rápida</a>
                <a class="btn blue" href="cotizador.php">＋ Nueva</a>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Cliente</th>
                    <th>Vehículo</th>
                    <th>Estado</th>
                    <th>Total</th>
                    <th>Saldo</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$recent): ?>
                <tr>
                    <td colspan="7" style="text-align:center;color:var(--ios-muted);padding:24px;">No hay cotizaciones registradas aún.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($recent as $r):
                    $pg = (float)$r['pagado'];
                    if (!$pg && !empty($r['conservar_anticipo_recibido'])) $pg = (float)$r['anticipo_recibido_monto'];
                    $saldoPendiente = max(0, (float)$r['total'] - $pg);
                ?>
                <tr>
                    <td style="font-weight:700;color:var(--ios-primary);"><?= eh($r['folio']) ?></td>
                    <td><b><?= eh($r['cliente']) ?></b></td>
                    <td><?= eh($r['vehiculo']) ?></td>
                    <td><span class="badge badge-blue"><?= eh($r['estado']) ?></span></td>
                    <td style="font-weight:600;font-variant-numeric:tabular-nums;"><?= emoney($r['total']) ?></td>
                    <td style="font-weight:700;font-variant-numeric:tabular-nums;color:<?= $saldoPendiente > 0.01 ? 'var(--ios-warning)' : 'var(--ios-success)' ?>;">
                        <?= emoney($saldoPendiente) ?>
                    </td>
                    <td style="text-align:right;">
                        <a class="btn light" style="font-size:11px;padding:5px 9px;" href="cotizador.php?id=<?= $r['id'] ?>">Abrir</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </section>

    <!-- Accesos Rápidos Mosaico Estilo App Icons -->
    <section class="panel">
        <h3 style="margin:0 0 4px;">Módulos del Taller</h3>
        <p class="muted" style="margin:0 0 10px;font-size:12px;">Acceso directo a las herramientas principales.</p>
        <div class="ios-quick-actions-grid">
            <a href="cotizador.php" class="ios-app-tile">
                <div class="tile-icon">📄</div>
                <span>Nueva Cotiz.</span>
            </a>
            <a href="cotizador_rapido.php" class="ios-app-tile">
                <div class="tile-icon">⚡</div>
                <span>Cotiz. Rápida</span>
            </a>
            <a href="recepcion.php" class="ios-app-tile">
                <div class="tile-icon">📋</div>
                <span>Check-in Auto</span>
            </a>
            <a href="agenda.php" class="ios-app-tile">
                <div class="tile-icon">📅</div>
                <span>Agenda</span>
            </a>
            <a href="inventario.php" class="ios-app-tile">
                <div class="tile-icon">📦</div>
                <span>Inventario</span>
            </a>
            <a href="compras.php" class="ios-app-tile">
                <div class="tile-icon">🛒</div>
                <span>Compras</span>
            </a>
            <a href="caja.php" class="ios-app-tile">
                <div class="tile-icon">💵</div>
                <span>Caja y Cortes</span>
            </a>
            <a href="vehiculos.php" class="ios-app-tile">
                <div class="tile-icon">🚗</div>
                <span>Expedientes</span>
            </a>
            <a href="clientes.php" class="ios-app-tile">
                <div class="tile-icon">👥</div>
                <span>Clientes</span>
            </a>
            <a href="reportes.php" class="ios-app-tile">
                <div class="tile-icon">📈</div>
                <span>Reportes</span>
            </a>
            <a href="ajustes_taller.php" class="ios-app-tile">
                <div class="tile-icon">⚙️</div>
                <span>Ajustes Taller</span>
            </a>
        </div>
    </section>
</main>

</body>
</html>
