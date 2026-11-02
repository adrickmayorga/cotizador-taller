<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/ui.php';

$pdo = srnu_db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$c = srnu_obtener_cotizacion($id);
if (!$c) {
    http_response_code(404);
    exit('Cotización no encontrada');
}

$uploadDir = __DIR__ . '/uploads/recepciones';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['eliminar_foto'])) {
        $fid = (int)$_POST['foto_id'];
        $st = $pdo->prepare('SELECT archivo FROM recepcion_fotos WHERE id=? AND cotizacion_id=?');
        $st->execute([$fid, $id]);
        $f = $st->fetch();
        if ($f) {
            $path = __DIR__ . '/' . $f['archivo'];
            if (is_file($path)) @unlink($path);
            $pdo->prepare('DELETE FROM recepcion_fotos WHERE id=? AND cotizacion_id=?')->execute([$fid, $id]);
        }
        header('Location: recepcion.php?id=' . $id);
        exit;
    }

    $firmaData = trim((string)($_POST['firma_data'] ?? ''));
    $vals = [
        $id,
        trim($_POST['kilometraje'] ?? ''),
        trim($_POST['combustible'] ?? ''),
        trim($_POST['placas'] ?? ''),
        trim($_POST['danos'] ?? ''),
        trim($_POST['testigos'] ?? ''),
        trim($_POST['objetos'] ?? ''),
        trim($_POST['notas'] ?? ''),
        trim($_POST['firma_cliente'] ?? ''),
        $firmaData
    ];

    $pdo->prepare('INSERT INTO recepciones(cotizacion_id,kilometraje,combustible,placas,danos,testigos,objetos,notas,firma_cliente,firma_data)
        VALUES(?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
        kilometraje=VALUES(kilometraje),combustible=VALUES(combustible),placas=VALUES(placas),danos=VALUES(danos),testigos=VALUES(testigos),objetos=VALUES(objetos),notas=VALUES(notas),firma_cliente=VALUES(firma_cliente),firma_data=IF(VALUES(firma_data)<>\'\',VALUES(firma_data),firma_data),updated_at=NOW()')
        ->execute($vals);

    if (!empty($_FILES['fotos']['name']) && is_array($_FILES['fotos']['name'])) {
        foreach ($_FILES['fotos']['name'] as $i => $name) {
            if ($_FILES['fotos']['error'][$i] !== UPLOAD_ERR_OK || $_FILES['fotos']['size'][$i] > 8 * 1024 * 1024) continue;
            $tmp = $_FILES['fotos']['tmp_name'][$i];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
            if (!$ext) continue;
            $fname = 'recepcion_' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dest = $uploadDir . '/' . $fname;
            if (move_uploaded_file($tmp, $dest)) {
                $rel = 'uploads/recepciones/' . $fname;
                $pdo->prepare('INSERT INTO recepcion_fotos(cotizacion_id,archivo) VALUES(?,?)')->execute([$id, $rel]);
            }
        }
    }

    $pdo->prepare("UPDATE cotizaciones SET estado='Vehículo recibido',updated_at=NOW() WHERE id=? AND estado IN ('Cotización','Aprobada','Anticipo recibido')")->execute([$id]);

    // Crear o actualizar expediente permanente del vehículo
    $vc = $pdo->prepare('SELECT vehiculo_id,cliente,telefono,vehiculo FROM cotizaciones WHERE id=?');
    $vc->execute([$id]);
    $vv = $vc->fetch();
    if ($vv) {
        $vid = (int)($vv['vehiculo_id'] ?? 0);
        if ($vid > 0) {
            $pdo->prepare('UPDATE vehiculos SET cliente=?,telefono=?,descripcion=?,placas=?,kilometraje=?,updated_at=NOW() WHERE id=?')->execute([$vv['cliente'], $vv['telefono'], $vv['vehiculo'], trim($_POST['placas'] ?? ''), trim($_POST['kilometraje'] ?? ''), $vid]);
        } else {
            $pdo->prepare('INSERT INTO vehiculos(cliente,telefono,descripcion,placas,kilometraje) VALUES(?,?,?,?,?)')->execute([$vv['cliente'], $vv['telefono'], $vv['vehiculo'], trim($_POST['placas'] ?? ''), trim($_POST['kilometraje'] ?? '')]);
            $vid = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE cotizaciones SET vehiculo_id=? WHERE id=?')->execute([$vid, $id]);
        }
    }
    header('Location: recepcion.php?id=' . $id . '&guardado=1');
    exit;
}

$st = $pdo->prepare('SELECT * FROM recepciones WHERE cotizacion_id=?');
$st->execute([$id]);
$r = $st->fetch() ?: [];

$st = $pdo->prepare('SELECT * FROM recepcion_fotos WHERE cotizacion_id=? ORDER BY id');
$st->execute([$id]);
$fotos = $st->fetchAll();

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es" data-theme="dark">
<head>
  <meta charset="utf-8">
  <title>Recepción · <?= h($c['folio']) ?></title>
  <?= srnu_ui_head('Check-in · ' . h($c['folio'])) ?>
  <style>
    .recepcion-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    .recepcion-grid .full {
      grid-column: 1 / -1;
    }
    .signature-card {
      background: rgba(0, 0, 0, 0.4);
      border: 1px solid var(--ios-card-border);
      border-radius: 18px;
      padding: 16px;
      display: flex;
      flex-direction: column;
      align-items: center;
    }
    canvas#firma {
      width: 100%;
      max-width: 640px;
      height: 190px;
      background: #ffffff;
      border-radius: 14px;
      touch-action: none;
      cursor: crosshair;
      box-shadow: 0 4px 14px rgba(0,0,0,0.2) inset;
    }
    .photos-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
      gap: 14px;
      margin-top: 14px;
    }
    .photo-card {
      position: relative;
      border-radius: 14px;
      overflow: hidden;
      border: 1px solid var(--ios-card-border);
      background: var(--ios-input-bg);
      aspect-ratio: 4 / 3;
    }
    .photo-card img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      transition: transform 0.2s;
    }
    .photo-card:hover img {
      transform: scale(1.05);
    }
    .photo-del-btn {
      position: absolute;
      top: 6px;
      right: 6px;
      background: rgba(255, 69, 58, 0.85);
      backdrop-filter: blur(8px);
      border: none;
      border-radius: 50%;
      width: 28px;
      height: 28px;
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 13px;
    }
    @media (max-width: 768px) {
      .recepcion-grid { grid-template-columns: 1fr; }
      .recepcion-grid .full { grid-column: auto; }
    }
  </style>
</head>
<body>

<?php srnu_appbar('recepcion'); ?>

<main class="w">
  <!-- Encabezado estilo Apple -->
  <div class="srnu-page-heading">
    <div>
      <div style="font-size:12px;font-weight:700;color:var(--ios-primary);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">
        Control de Calidad & Taller
      </div>
      <h1>Recepción del Vehículo</h1>
      <p>
        Folio <strong><?= h($c['folio']) ?></strong> · Cliente: <b><?= h($c['cliente']) ?></b> · Auto: <b><?= h($c['vehiculo']) ?></b>
      </p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn light" href="cotizaciones.php">← Cotizaciones</a>
      <a class="btn light" href="orden_trabajo.php?id=<?= $id ?>">Orden de Trabajo</a>
      <a class="btn blue" href="cotizador.php?id=<?= $id ?>">Abrir Cotización</a>
    </div>
  </div>

  <?php if (isset($_GET['guardado'])): ?>
    <div class="alert-banner info" style="margin-bottom:20px;padding:14px;border-radius:14px;background:rgba(50,215,75,0.15);border:1px solid rgba(50,215,75,0.3);color:var(--ios-success);display:flex;align-items:center;gap:10px;">
      <span>✓</span>
      <div>Recepción y expediente guardados exitosamente.</div>
    </div>
  <?php endif; ?>

  <div class="panel" style="margin-bottom:24px;">
    <form method="post" enctype="multipart/form-data" class="recepcion-grid" id="recepcion-form">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="firma_data" id="firma-data">

      <div>
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:var(--ios-muted);text-transform:uppercase;">Kilometraje actual</label>
        <input style="width:100%;padding:12px 14px;background:var(--ios-input-bg);border:1px solid var(--ios-card-border);border-radius:12px;color:var(--ios-text);font-size:15px;outline:none;" name="kilometraje" placeholder="Ej. 45,200 km" value="<?= h($r['kilometraje'] ?? '') ?>">
      </div>

      <div>
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:var(--ios-muted);text-transform:uppercase;">Nivel de combustible</label>
        <select name="combustible" style="width:100%;padding:12px 14px;background:var(--ios-input-bg);border:1px solid var(--ios-card-border);border-radius:12px;color:var(--ios-text);font-size:15px;outline:none;">
          <?php $comb = $r['combustible'] ?? ''; ?>
          <option value="">Selecciona nivel...</option>
          <option value="Reserva / Vacío" <?= $comb === 'Reserva / Vacío' ? 'selected' : '' ?>>Reserva / Vacío (1/8)</option>
          <option value="1/4 de tanque" <?= $comb === '1/4 de tanque' ? 'selected' : '' ?>>1/4 de tanque</option>
          <option value="1/2 de tanque" <?= $comb === '1/2 de tanque' ? 'selected' : '' ?>>1/2 de tanque</option>
          <option value="3/4 de tanque" <?= $comb === '3/4 de tanque' ? 'selected' : '' ?>>3/4 de tanque</option>
          <option value="Tanque lleno" <?= $comb === 'Tanque lleno' ? 'selected' : '' ?>>Tanque lleno</option>
        </select>
      </div>

      <div>
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:var(--ios-muted);text-transform:uppercase;">Placas del auto</label>
        <input style="width:100%;padding:12px 14px;background:var(--ios-input-bg);border:1px solid var(--ios-card-border);border-radius:12px;color:var(--ios-text);font-size:15px;outline:none;text-transform:uppercase;" name="placas" placeholder="Ej. ABC-123-D" value="<?= h($r['placas'] ?? '') ?>">
      </div>

      <div>
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:var(--ios-muted);text-transform:uppercase;">Nombre de quien entrega el vehículo</label>
        <input style="width:100%;padding:12px 14px;background:var(--ios-input-bg);border:1px solid var(--ios-card-border);border-radius:12px;color:var(--ios-text);font-size:15px;outline:none;" name="firma_cliente" placeholder="Nombre completo" value="<?= h($r['firma_cliente'] ?? $c['cliente']) ?>">
      </div>

      <div class="full">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:var(--ios-muted);text-transform:uppercase;">Daños existentes (golpes, rayones, abolladuras)</label>
        <textarea style="width:100%;padding:12px 14px;background:var(--ios-input-bg);border:1px solid var(--ios-card-border);border-radius:12px;color:var(--ios-text);font-size:14px;outline:none;min-height:75px;" name="danos" placeholder="Describe cualquier detalle estético previo..."><?= h($r['danos'] ?? '') ?></textarea>
      </div>

      <div class="full">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:var(--ios-muted);text-transform:uppercase;">Testigos o luces en el tablero</label>
        <textarea style="width:100%;padding:12px 14px;background:var(--ios-input-bg);border:1px solid var(--ios-card-border);border-radius:12px;color:var(--ios-text);font-size:14px;outline:none;min-height:65px;" name="testigos" placeholder="Ej. Check engine, testigo de frenos, presión de llantas..."><?= h($r['testigos'] ?? '') ?></textarea>
      </div>

      <div class="full">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:var(--ios-muted);text-transform:uppercase;">Objetos de valor dentro del auto</label>
        <textarea style="width:100%;padding:12px 14px;background:var(--ios-input-bg);border:1px solid var(--ios-card-border);border-radius:12px;color:var(--ios-text);font-size:14px;outline:none;min-height:65px;" name="objetos" placeholder="Ej. Lentes, herramienta, cables, llanta de refacción..."><?= h($r['objetos'] ?? '') ?></textarea>
      </div>

      <div class="full">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:var(--ios-muted);text-transform:uppercase;">Notas internas de recepción</label>
        <textarea style="width:100%;padding:12px 14px;background:var(--ios-input-bg);border:1px solid var(--ios-card-border);border-radius:12px;color:var(--ios-text);font-size:14px;outline:none;min-height:65px;" name="notas"><?= h($r['notas'] ?? '') ?></textarea>
      </div>

      <div class="full">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:8px;color:var(--ios-muted);text-transform:uppercase;">
          Subir Fotografías del Vehículo (Cámara / Galería)
        </label>
        <input type="file" name="fotos[]" multiple accept="image/*" capture="environment" style="background:var(--ios-input-bg);border:1px dashed var(--ios-card-border);padding:18px;border-radius:14px;width:100%;color:var(--ios-text);cursor:pointer;">
        <div class="muted" style="font-size:12px;margin-top:6px;">En dispositivos móviles abre directamente la cámara trasera o galería de fotos.</div>
      </div>

      <!-- Firma Táctil en Canvas -->
      <div class="full">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:8px;color:var(--ios-muted);text-transform:uppercase;">
          Firma digital de conformidad del cliente
        </label>
        <div class="signature-card">
          <canvas id="firma" width="640" height="190"></canvas>
          <div style="display:flex;align-items:center;justify-content:space-between;width:100%;margin-top:12px;flex-wrap:wrap;gap:8px;">
            <button type="button" class="btn light" id="limpiar-firma" style="font-size:12px;padding:7px 12px;">Limpiar Trazo</button>
            <?php if (!empty($r['firma_data'])): ?>
              <span class="badge badge-green" style="font-size:11px;">Firma registrada anteriormente</span>
            <?php endif; ?>
            <span class="muted" style="font-size:12px;">Firma con tu dedo o stylus directamente sobre el recuadro.</span>
          </div>
        </div>
      </div>

      <div class="full" style="display:flex;justify-content:flex-end;gap:12px;margin-top:10px;">
        <a class="btn light" href="cotizaciones.php">Cancelar</a>
        <button type="submit" class="btn green" style="font-size:16px;padding:12px 24px;">Guardar Recepción</button>
      </div>
    </form>
  </div>

  <?php if ($fotos): ?>
    <section class="panel">
      <div class="section-title">
        <div>
          <h3>Fotografías Registradas (<?= count($fotos) ?>)</h3>
          <p class="muted" style="margin:2px 0 0;font-size:12px;">Evidencia fotográfica tomada durante el check-in.</p>
        </div>
      </div>
      <div class="photos-grid">
        <?php foreach ($fotos as $f): ?>
          <div class="photo-card">
            <a href="foto.php?id=<?= (int)$f['id'] ?>" target="_blank">
              <img src="foto.php?id=<?= (int)$f['id'] ?>" alt="Foto vehículo">
            </a>
            <form method="post" onsubmit="return confirm('¿Eliminar fotografía?')" style="margin:0;">
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="foto_id" value="<?= (int)$f['id'] ?>">
              <button name="eliminar_foto" class="photo-del-btn" title="Eliminar foto">✕</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</main>

<script>
(() => {
  const c = document.getElementById('firma');
  const ctx = c.getContext('2d');
  const hidden = document.getElementById('firma-data');
  let draw = false, changed = false;

  ctx.lineWidth = 2.5;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  ctx.strokeStyle = '#000000';

  function pos(e) {
    const r = c.getBoundingClientRect();
    const t = e.touches ? e.touches[0] : e;
    return {
      x: (t.clientX - r.left) * (c.width / r.width),
      y: (t.clientY - r.top) * (c.height / r.height)
    };
  }

  function start(e) {
    draw = true;
    changed = true;
    const p = pos(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
    e.preventDefault();
  }

  function move(e) {
    if (!draw) return;
    const p = pos(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    e.preventDefault();
  }

  function stop() {
    draw = false;
  }

  ['mousedown', 'touchstart'].forEach(x => c.addEventListener(x, start, { passive: false }));
  ['mousemove', 'touchmove'].forEach(x => c.addEventListener(x, move, { passive: false }));
  ['mouseup', 'mouseleave', 'touchend'].forEach(x => c.addEventListener(x, stop));

  document.getElementById('limpiar-firma').onclick = () => {
    ctx.clearRect(0, 0, c.width, c.height);
    changed = false;
    hidden.value = '';
  };

  document.getElementById('recepcion-form').addEventListener('submit', () => {
    if (changed) hidden.value = c.toDataURL('image/png');
  });
})();
</script>

</body>
</html>
