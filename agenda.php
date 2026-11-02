<?php
declare(strict_types=1);
require_once __DIR__ . '/config/erp.php';
require_once __DIR__ . '/config/ui.php';

$pdo = srnu_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['guardar'])) {
        $id = (int)($_POST['id'] ?? 0);
        $cot = (int)($_POST['cotizacion_id'] ?? 0);
        $titulo = epost('titulo');
        if ($titulo === '') $titulo = 'Trabajo de Taller';
        $data = [
            $cot ?: null,
            $titulo,
            epost('fecha_inicio', date('Y-m-d')),
            epost('hora_inicio') ?: null,
            epost('fecha_entrega') ?: null,
            epost('hora_entrega') ?: null,
            epost('responsable'),
            epost('estado', 'Programado'),
            efloat('duracion_estimada_horas'),
            epost('notas')
        ];
        if ($id) {
            $pdo->prepare('UPDATE agenda_trabajos SET cotizacion_id=?,titulo=?,fecha_inicio=?,hora_inicio=?,fecha_entrega=?,hora_entrega=?,responsable=?,estado=?,duracion_estimada_horas=?,notas=? WHERE id=?')->execute([...$data, $id]);
        } else {
            $pdo->prepare('INSERT INTO agenda_trabajos(cotizacion_id,titulo,fecha_inicio,hora_inicio,fecha_entrega,hora_entrega,responsable,estado,duracion_estimada_horas,notas) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute($data);
        }
    }
    if (isset($_POST['eliminar'])) {
        $pdo->prepare('DELETE FROM agenda_trabajos WHERE id=?')->execute([(int)$_POST['id']]);
    }
    ego('agenda.php?mes=' . urlencode($_POST['mes'] ?? date('Y-m')));
}

$mes = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');
$inicio = $mes . '-01';
$fin = date('Y-m-t', strtotime($inicio));

$q = $pdo->prepare("SELECT a.*, c.folio, c.cliente, c.vehiculo,
    (SELECT COUNT(*) FROM cotizacion_items ci WHERE ci.cotizacion_id=a.cotizacion_id AND ci.tipo_entrega='sobre_pedido') as sobre_pedido
    FROM agenda_trabajos a
    LEFT JOIN cotizaciones c ON c.id=a.cotizacion_id
    WHERE a.fecha_inicio <= ? AND COALESCE(a.fecha_entrega, a.fecha_inicio) >= ?
    ORDER BY a.fecha_inicio, a.hora_inicio");
$q->execute([$fin, $inicio]);
$events = $q->fetchAll();

$by = [];
foreach ($events as $e) {
    $d = $e['fecha_inicio'];
    $last = $e['fecha_entrega'] ?: $d;
    for ($ts = strtotime($d), $end = strtotime($last); $ts <= $end && $ts <= strtotime($fin); $ts += 86400) {
        $day = date('Y-m-d', $ts);
        if ($day >= $inicio) $by[$day][] = $e;
    }
}

$cots = srnu_get_cotizaciones_select();
$prefill = (int)($_GET['cotizacion_id'] ?? 0);
$days = (int)date('t', strtotime($inicio));
$first = (int)date('N', strtotime($inicio));

$diasNombres = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
$hoyFecha = date('Y-m-d');
?>
<!doctype html>
<html lang="es" data-theme="dark">
<head>
  <meta charset="utf-8">
  <title>Agenda de Trabajos · Taller</title>
  <?= srnu_ui_head('Agenda de Trabajos') ?>
</head>
<body>

<?php srnu_appbar('agenda'); ?>

<main class="w">
  <div class="srnu-page-heading">
    <div>
      <div style="font-size:12px;font-weight:700;color:var(--ios-primary);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">
        Control Operativo
      </div>
      <h1>Agenda de Trabajos</h1>
      <p>Programación de ingresos, entregas, responsables y seguimiento de vehículos en taller.</p>
    </div>
    <div class="toolbar">
      <a class="btn light" href="?mes=<?= date('Y-m', strtotime($inicio . ' -1 month')) ?>">← Mes anterior</a>
      <form style="display:inline-flex;gap:6px;">
        <input type="month" name="mes" value="<?= eh($mes) ?>" onchange="this.form.submit()" style="padding:9px 12px;background:var(--ios-input);border:1px solid var(--ios-border);border-radius:12px;color:var(--ios-text);font-size:13px;">
      </form>
      <a class="btn light" href="?mes=<?= date('Y-m', strtotime($inicio . ' +1 month')) ?>">Mes siguiente →</a>
    </div>
  </div>

  <!-- Formulario para agregar cita/trabajo -->
  <section class="panel" style="margin-bottom:20px;">
    <h3 style="margin:0 0 14px;font-size:15px;display:flex;align-items:center;gap:8px;">
      <span>📅</span> Programar Nuevo Trabajo o Cita
    </h3>
    <form method="post" class="erp-form cols-4">
      <input type="hidden" name="mes" value="<?= eh($mes) ?>">

      <div class="full" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:var(--ios-muted);text-transform:uppercase;margin-bottom:4px;">Cotización Relacionada</label>
          <select name="cotizacion_id" style="width:100%;padding:10px;background:var(--ios-input);border:1px solid var(--ios-border);border-radius:10px;color:var(--ios-text);">
            <option value="">Sin cotización ligada</option>
            <?php foreach ($cots as $c): ?>
              <option value="<?= $c['id'] ?>" <?= $prefill === $c['id'] ? 'selected' : '' ?>><?= eh(srnu_cotizacion_label($c)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:var(--ios-muted);text-transform:uppercase;margin-bottom:4px;">Título o Trabajo</label>
          <input name="titulo" placeholder="Ej. Afinación mayor Tacoma / Instalación de luces" required style="width:100%;padding:10px;background:var(--ios-input);border:1px solid var(--ios-border);border-radius:10px;color:var(--ios-text);">
        </div>
      </div>

      <div>
        <label style="display:block;font-size:11px;font-weight:700;color:var(--ios-muted);text-transform:uppercase;margin-bottom:4px;">Fecha de Inicio</label>
        <input type="date" name="fecha_inicio" value="<?= date('Y-m-d') ?>" style="width:100%;padding:10px;background:var(--ios-input);border:1px solid var(--ios-border);border-radius:10px;color:var(--ios-text);">
      </div>

      <div>
        <label style="display:block;font-size:11px;font-weight:700;color:var(--ios-muted);text-transform:uppercase;margin-bottom:4px;">Hora Inicio</label>
        <input type="time" name="hora_inicio" value="09:00" style="width:100%;padding:10px;background:var(--ios-input);border:1px solid var(--ios-border);border-radius:10px;color:var(--ios-text);">
      </div>

      <div>
        <label style="display:block;font-size:11px;font-weight:700;color:var(--ios-muted);text-transform:uppercase;margin-bottom:4px;">Fecha de Entrega</label>
        <input type="date" name="fecha_entrega" value="<?= date('Y-m-d') ?>" style="width:100%;padding:10px;background:var(--ios-input);border:1px solid var(--ios-border);border-radius:10px;color:var(--ios-text);">
      </div>

      <div>
        <label style="display:block;font-size:11px;font-weight:700;color:var(--ios-muted);text-transform:uppercase;margin-bottom:4px;">Hora Entrega</label>
        <input type="time" name="hora_entrega" value="18:00" style="width:100%;padding:10px;background:var(--ios-input);border:1px solid var(--ios-border);border-radius:10px;color:var(--ios-text);">
      </div>

      <div>
        <label style="display:block;font-size:11px;font-weight:700;color:var(--ios-muted);text-transform:uppercase;margin-bottom:4px;">Técnico Responsable</label>
        <input name="responsable" placeholder="Ej. Sergio, Ricardo, Tomás..." style="width:100%;padding:10px;background:var(--ios-input);border:1px solid var(--ios-border);border-radius:10px;color:var(--ios-text);">
      </div>

      <div>
        <label style="display:block;font-size:11px;font-weight:700;color:var(--ios-muted);text-transform:uppercase;margin-bottom:4px;">Estado del Trabajo</label>
        <select name="estado" style="width:100%;padding:10px;background:var(--ios-input);border:1px solid var(--ios-border);border-radius:10px;color:var(--ios-text);">
          <option>Programado</option>
          <option>En proceso</option>
          <option>Esperando producto</option>
          <option>Listo</option>
          <option>Entregado</option>
          <option>Cancelado</option>
        </select>
      </div>

      <div class="full" style="display:flex;gap:12px;align-items:flex-end;">
        <div style="flex:1;">
          <label style="display:block;font-size:11px;font-weight:700;color:var(--ios-muted);text-transform:uppercase;margin-bottom:4px;">Notas u Observaciones</label>
          <input name="notas" placeholder="Detalles adicionales sobre el trabajo o refacciones..." style="width:100%;padding:10px;background:var(--ios-input);border:1px solid var(--ios-border);border-radius:10px;color:var(--ios-text);">
        </div>
        <button type="submit" class="btn blue" name="guardar" value="1" style="padding:11px 22px;white-space:nowrap;">Agregar a Agenda</button>
      </div>
    </form>
  </section>

  <!-- Vista Calendario Mes Estilo iOS -->
  <div class="calendar-days-header">
    <?php foreach ($diasNombres as $dn): ?>
      <div><?= $dn ?></div>
    <?php endforeach; ?>
  </div>

  <section class="calendar-grid">
    <?php for ($i = 1; $i < $first; $i++): ?>
      <div style="background:transparent;border:none;"></div>
    <?php endfor; ?>

    <?php for ($d = 1; $d <= $days; $d++):
      $date = $mes . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
      $isToday = ($date === $hoyFecha);
    ?>
      <div class="calendar-day <?= $isToday ? 'today' : '' ?>">
        <div class="n"><?= $d ?></div>
        <?php foreach ($by[$date] ?? [] as $e): ?>
          <a class="calendar-event" href="#trabajo-<?= (int)$e['id'] ?>">
            <b><?= eh($e['titulo']) ?></b>
            <?php if ($e['cliente']): ?>
              <div style="color:var(--ios-muted);font-size:10px;"><?= eh($e['cliente']) ?> · <?= eh($e['vehiculo']) ?></div>
            <?php endif; ?>
            <div style="margin-top:2px;display:flex;gap:4px;flex-wrap:wrap;">
              <span class="badge badge-blue" style="font-size:9px;padding:2px 5px;"><?= eh($e['responsable'] ?: 'Taller') ?></span>
              <?php if ((int)$e['sobre_pedido'] > 0): ?>
                <span class="badge badge-orange" style="font-size:9px;padding:2px 5px;">Pedido</span>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endfor; ?>
  </section>

  <!-- Lista Detallada de Trabajos del Mes -->
  <section class="panel">
    <div class="section-title">
      <div>
        <h3>Detalle de Trabajos Registrados</h3>
        <p class="muted" style="margin:2px 0 0;font-size:12px;">Lista cronológica de eventos programados en este mes.</p>
      </div>
    </div>
    <div class="tablebox" style="overflow-x:auto;">
      <table>
        <thead>
          <tr>
            <th>Fechas</th>
            <th>Trabajo</th>
            <th>Cliente / Vehículo</th>
            <th>Responsable</th>
            <th>Estado</th>
            <th style="text-align:right;">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$events): ?>
          <tr>
            <td colspan="6" style="text-align:center;padding:30px;color:var(--ios-muted);">No hay trabajos registrados para este mes.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($events as $e): ?>
            <tr id="trabajo-<?= (int)$e['id'] ?>">
              <td>
                <div style="font-weight:700;font-size:13px;"><?= edate($e['fecha_inicio']) ?></div>
                <div class="muted" style="font-size:11px;"><?= eh(substr((string)$e['hora_inicio'], 0, 5)) ?> hrs</div>
                <?php if ($e['fecha_entrega']): ?>
                  <div class="muted" style="font-size:11px;margin-top:2px;">Entrega: <?= edate($e['fecha_entrega']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <b style="font-size:14px;"><?= eh($e['titulo']) ?></b>
                <?php if ((int)$e['sobre_pedido'] > 0): ?>
                  <span class="badge badge-orange" style="font-size:10px;margin-left:4px;">Sobre pedido</span>
                <?php endif; ?>
                <?php if ($e['notas']): ?>
                  <div class="muted" style="font-size:12px;margin-top:2px;"><?= eh($e['notas']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($e['cliente']): ?>
                  <div style="font-weight:600;"><?= eh($e['cliente']) ?></div>
                  <div class="muted" style="font-size:12px;">🚗 <?= eh($e['vehiculo']) ?></div>
                <?php else: ?>
                  <span class="muted">Sin cliente asignado</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge badge-blue"><?= eh($e['responsable'] ?: 'Taller') ?></span>
              </td>
              <td>
                <span class="badge badge-green"><?= eh($e['estado']) ?></span>
              </td>
              <td style="text-align:right;">
                <div style="display:inline-flex;gap:6px;">
                  <?php if ($e['cotizacion_id']): ?>
                    <a class="btn light" style="font-size:11px;padding:5px 9px;" href="cotizador.php?id=<?= $e['cotizacion_id'] ?>">Cotización</a>
                  <?php endif; ?>
                  <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar evento?')">
                    <input type="hidden" name="id" value="<?= $e['id'] ?>">
                    <input type="hidden" name="mes" value="<?= eh($mes) ?>">
                    <button class="btn light" name="eliminar" value="1" style="font-size:11px;padding:5px 9px;color:var(--ios-danger);">Eliminar</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

</body>
</html>
