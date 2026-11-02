<?php
declare(strict_types=1);
require_once __DIR__ . '/config/erp.php';
require_once __DIR__ . '/config/ui.php';
require_once __DIR__ . '/config/licencia.php';

// Solo administradores pueden modificar los ajustes del taller
if (function_exists('srnu_auth_require_admin')) {
    srnu_auth_require_admin();
}

$pdo = srnu_db();
$msgOk = '';
$msgErr = '';

// Directorio para logotipos
$uploadDir = __DIR__ . '/uploads/logo';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!srnu_auth_check_csrf($_POST['csrf'] ?? null)) {
        $msgErr = 'La sesión del formulario expiró. Intenta nuevamente.';
    } else {
        // Datos de Identidad
        $nombre = trim((string)($_POST['taller_nombre'] ?? ''));
        $slogan = trim((string)($_POST['taller_slogan'] ?? ''));
        $rfc = trim((string)($_POST['taller_rfc'] ?? ''));
        $titular = trim((string)($_POST['taller_titular'] ?? ''));
        $telefono = trim((string)($_POST['taller_telefono'] ?? ''));
        $email = trim((string)($_POST['taller_email'] ?? ''));
        $direccion = trim((string)($_POST['taller_direccion'] ?? ''));
        $ciudad = trim((string)($_POST['taller_ciudad'] ?? ''));

        // Parámetros comerciales
        $anticipoDef = (float)($_POST['anticipo_defecto'] ?? 70);
        $vigenciaDef = (int)($_POST['vigencia_defecto'] ?? 15);
        $moneda = trim((string)($_POST['moneda_simbolo'] ?? '$'));
        $ivaDef = (float)($_POST['iva_tasa'] ?? 16);

        // Cuentas bancarias para PDFs
        $banco1Nombre = trim((string)($_POST['banco1_nombre'] ?? ''));
        $banco1Titular = trim((string)($_POST['banco1_titular'] ?? ''));
        $banco1Cuenta = trim((string)($_POST['banco1_cuenta'] ?? ''));
        $banco1Clabe = trim((string)($_POST['banco1_clabe'] ?? ''));

        $banco2Nombre = trim((string)($_POST['banco2_nombre'] ?? ''));
        $banco2Titular = trim((string)($_POST['banco2_titular'] ?? ''));
        $banco2Cuenta = trim((string)($_POST['banco2_cuenta'] ?? ''));
        $banco2Clabe = trim((string)($_POST['banco2_clabe'] ?? ''));

        // Procesar subida de nuevo Logo
        if (!empty($_FILES['taller_logo']['name']) && $_FILES['taller_logo']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['taller_logo']['tmp_name'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp);
            $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
            
            if (isset($extMap[$mime])) {
                $ext = $extMap[$mime];
                $fname = 'logo_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest = $uploadDir . '/' . $fname;
                if (move_uploaded_file($tmp, $dest)) {
                    $relLogo = 'uploads/logo/' . $fname;
                    srnu_cfg_set('taller_logo', $relLogo);
                    
                    // También guardar como favicon.png si es png/jpg para actualizar icono global
                    if (in_array($ext, ['png', 'jpg'], true)) {
                        @copy($dest, __DIR__ . '/favicon.png');
                    }
                }
            } else {
                $msgErr = 'Formato de logotipo no soportado. Usa PNG, JPG o SVG.';
            }
        }

        // Guardar todas las configuraciones
        srnu_cfg_set('taller_nombre', $nombre !== '' ? $nombre : 'SRNU CUSTOM');
        srnu_cfg_set('taller_slogan', $slogan);
        srnu_cfg_set('taller_rfc', $rfc);
        srnu_cfg_set('taller_titular', $titular);
        srnu_cfg_set('taller_telefono', $telefono);
        srnu_cfg_set('taller_email', $email);
        srnu_cfg_set('taller_direccion', $direccion);
        srnu_cfg_set('taller_ciudad', $ciudad);

        srnu_cfg_set('anticipo_defecto', (string)$anticipoDef);
        srnu_cfg_set('vigencia_defecto', (string)$vigenciaDef);
        srnu_cfg_set('moneda_simbolo', $moneda);
        srnu_cfg_set('iva_tasa', (string)$ivaDef);

        srnu_cfg_set('banco1_nombre', $banco1Nombre);
        srnu_cfg_set('banco1_titular', $banco1Titular);
        srnu_cfg_set('banco1_cuenta', $banco1Cuenta);
        srnu_cfg_set('banco1_clabe', $banco1Clabe);

        srnu_cfg_set('banco2_nombre', $banco2Nombre);
        srnu_cfg_set('banco2_titular', $banco2Titular);
        srnu_cfg_set('banco2_cuenta', $banco2Cuenta);
        srnu_cfg_set('banco2_clabe', $banco2Clabe);

        if (isset($_POST['licencia_token'])) {
            $newToken = trim((string)$_POST['licencia_token']);
            if ($newToken !== '') {
                $chk = srnu_licencia_validar($newToken);
                if (!$chk['valida']) {
                    $msgErr = 'La clave de licencia ingresada no es válida: ' . $chk['mensaje'];
                } else {
                    srnu_licencia_guardar_token($newToken);
                }
            }
        }

        if (!$msgErr) {
            $msgOk = 'Los ajustes del taller y marca blanca fueron guardados correctamente.';
        }
    }
}

// Cargar valores actuales
$curNombre = srnu_cfg_get('taller_nombre', 'SRNU CUSTOM');
$curSlogan = srnu_cfg_get('taller_slogan', 'Sistema de Gestión Automotriz');
$curRfc = srnu_cfg_get('taller_rfc', '');
$curTitular = srnu_cfg_get('taller_titular', '');
$curTelefono = srnu_cfg_get('taller_telefono', '');
$curEmail = srnu_cfg_get('taller_email', '');
$curDireccion = srnu_cfg_get('taller_direccion', '');
$curCiudad = srnu_cfg_get('taller_ciudad', '');
$curLogo = srnu_cfg_get('taller_logo', 'favicon.png');

$curAnticipo = srnu_cfg_get('anticipo_defecto', '70');
$curVigencia = srnu_cfg_get('vigencia_defecto', '15');
$curMoneda = srnu_cfg_get('moneda_simbolo', '$');
$curIva = srnu_cfg_get('iva_tasa', '16');

$curB1Nombre = srnu_cfg_get('banco1_nombre', 'BBVA');
$curB1Titular = srnu_cfg_get('banco1_titular', '');
$curB1Cuenta = srnu_cfg_get('banco1_cuenta', '');
$curB1Clabe = srnu_cfg_get('banco1_clabe', '');

$curB2Nombre = srnu_cfg_get('banco2_nombre', '');
$curB2Titular = srnu_cfg_get('banco2_titular', '');
$curB2Cuenta = srnu_cfg_get('banco2_cuenta', '');
$curB2Clabe = srnu_cfg_get('banco2_clabe', '');

$infoLicencia = srnu_licencia_validar();
$curLicenciaToken = srnu_licencia_obtener_token();

function ehVal(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es" data-theme="dark">
<head>
  <meta charset="utf-8">
  <title>Ajustes del Taller · Marca Blanca</title>
  <?= srnu_ui_head('Ajustes del Taller') ?>
  <style>
    .settings-section {
      background: var(--ios-card);
      border: 1px solid var(--ios-border);
      border-radius: var(--ios-radius-lg);
      padding: 24px;
      margin-bottom: 22px;
      box-shadow: var(--ios-shadow);
    }
    .settings-title {
      font-size: 16px;
      font-weight: 700;
      color: var(--ios-text);
      margin: 0 0 6px 0;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .settings-desc {
      font-size: 13px;
      color: var(--ios-muted);
      margin: 0 0 20px 0;
    }
    .settings-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    .settings-grid .full {
      grid-column: 1 / -1;
    }
    .field-label {
      display: block;
      font-size: 12px;
      font-weight: 700;
      color: var(--ios-muted);
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .field-input {
      width: 100%;
      background: var(--ios-input);
      border: 1px solid var(--ios-border);
      border-radius: var(--ios-radius-sm);
      padding: 12px 14px;
      font-size: 14px;
      color: var(--ios-text);
      outline: none;
      transition: border-color 0.2s;
    }
    .field-input:focus {
      border-color: var(--ios-primary);
      box-shadow: 0 0 0 3px var(--ios-primary-light);
    }
    .logo-uploader-card {
      display: flex;
      align-items: center;
      gap: 20px;
      background: rgba(255, 255, 255, 0.03);
      border: 1px dashed var(--ios-border);
      border-radius: 16px;
      padding: 16px;
    }
    .logo-preview-box {
      width: 80px;
      height: 80px;
      border-radius: 16px;
      background: rgba(0, 0, 0, 0.3);
      border: 1px solid var(--ios-border);
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      flex-shrink: 0;
    }
    .logo-preview-box img {
      max-width: 100%;
      max-height: 100%;
      object-fit: contain;
    }
    @media (max-width: 768px) {
      .settings-grid { grid-template-columns: 1fr; }
      .settings-grid .full { grid-column: auto; }
      .logo-uploader-card { flex-direction: column; align-items: flex-start; }
    }
  </style>
</head>
<body>

<?php srnu_appbar('ajustes'); ?>

<main class="w">
  <div class="srnu-page-heading">
    <div>
      <div style="font-size:12px;font-weight:700;color:var(--ios-primary);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">
        Configuración del Sistema
      </div>
      <h1>Ajustes del Taller & Marca Blanca</h1>
      <p>Personaliza el logotipo, nombre del taller, datos fiscales y cuentas bancarias para cotizaciones y PDFs.</p>
    </div>
  </div>

  <?php if ($msgOk): ?>
    <div style="margin-bottom:20px;padding:14px;border-radius:14px;background:rgba(50,215,75,0.15);border:1px solid rgba(50,215,75,0.3);color:var(--ios-success);display:flex;align-items:center;gap:10px;">
      <span>✓</span>
      <div><?= ehVal($msgOk) ?></div>
    </div>
  <?php endif; ?>

  <?php if ($msgErr): ?>
    <div style="margin-bottom:20px;padding:14px;border-radius:14px;background:rgba(255,69,58,0.15);border:1px solid rgba(255,69,58,0.3);color:var(--ios-danger);display:flex;align-items:center;gap:10px;">
      <span>⚠️</span>
      <div><?= ehVal($msgErr) ?></div>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= ehVal(srnu_auth_csrf()) ?>">

    <!-- 1. Identidad & Logo -->
    <section class="settings-section">
      <h3 class="settings-title">🏢 Identidad Visual & Logotipo</h3>
      <p class="settings-desc">El logotipo y nombre configurados aquí aparecerán en la cabecera del sistema, en los PDFs y en la pantalla de inicio de sesión.</p>

      <div class="settings-grid">
        <div class="full">
          <label class="field-label">Logotipo Oficial del Taller</label>
          <div class="logo-uploader-card">
            <div class="logo-preview-box">
              <img src="<?= ehVal($curLogo) ?>?v=<?= time() ?>" alt="Logo taller" id="logo-preview">
            </div>
            <div style="flex:1;">
              <input type="file" name="taller_logo" id="logo-input" accept="image/png,image/jpeg,image/webp,image/svg+xml" style="font-size:13px;color:var(--ios-text);">
              <div class="muted" style="font-size:12px;margin-top:6px;">Formatos recomendados: PNG transparente o SVG. Tamaño óptimo: 400x120 px.</div>
            </div>
          </div>
        </div>

        <div>
          <label class="field-label" for="taller_nombre">Nombre Comercial del Taller</label>
          <input class="field-input" id="taller_nombre" name="taller_nombre" value="<?= ehVal($curNombre) ?>" required placeholder="Ej. SRNU Custom">
        </div>

        <div>
          <label class="field-label" for="taller_slogan">Eslogan o Giro Comercial</label>
          <input class="field-input" id="taller_slogan" name="taller_slogan" value="<?= ehVal($curSlogan) ?>" placeholder="Ej. Modificaciones & Mecánica de Alto Rendimiento">
        </div>
      </div>
    </section>

    <!-- 2. Datos Fiscales y Contacto -->
    <section class="settings-section">
      <h3 class="settings-title">📍 Datos Fiscales & Contacto</h3>
      <p class="settings-desc">Información legal que se muestra al pie de las cotizaciones y facturas digitales.</p>

      <div class="settings-grid">
        <div>
          <label class="field-label" for="taller_rfc">RFC / Tax ID</label>
          <input class="field-input" id="taller_rfc" name="taller_rfc" value="<?= ehVal($curRfc) ?>" placeholder="Ej. XAXX010101000">
        </div>

        <div>
          <label class="field-label" for="taller_titular">Razón Social o Titular</label>
          <input class="field-input" id="taller_titular" name="taller_titular" value="<?= ehVal($curTitular) ?>" placeholder="Nombre de la empresa o propietario">
        </div>

        <div>
          <label class="field-label" for="taller_telefono">Teléfono / WhatsApp de Atención</label>
          <input class="field-input" id="taller_telefono" name="taller_telefono" value="<?= ehVal($curTelefono) ?>" placeholder="Ej. 614 123 4567">
        </div>

        <div>
          <label class="field-label" for="taller_email">Correo Electrónico de Contacto</label>
          <input class="field-input" id="taller_email" name="taller_email" type="email" value="<?= ehVal($curEmail) ?>" placeholder="contacto@mitaller.com">
        </div>

        <div>
          <label class="field-label" for="taller_direccion">Dirección Física</label>
          <input class="field-input" id="taller_direccion" name="taller_direccion" value="<?= ehVal($curDireccion) ?>" placeholder="Calle, Número exterior e interior, Colonia">
        </div>

        <div>
          <label class="field-label" for="taller_ciudad">Ciudad y Estado</label>
          <input class="field-input" id="taller_ciudad" name="taller_ciudad" value="<?= ehVal($curCiudad) ?>" placeholder="Ej. Chihuahua, Chih.">
        </div>
      </div>
    </section>

    <!-- 3. Parámetros Comerciales -->
    <section class="settings-section">
      <h3 class="settings-title">⚙️ Parámetros Comerciales por Defecto</h3>
      <p class="settings-desc">Configura los valores sugeridos al generar una nueva cotización.</p>

      <div class="settings-grid">
        <div>
          <label class="field-label" for="anticipo_defecto">% de Anticipo Sugerido</label>
          <input class="field-input" id="anticipo_defecto" name="anticipo_defecto" type="number" step="1" min="0" max="100" value="<?= ehVal($curAnticipo) ?>">
        </div>

        <div>
          <label class="field-label" for="vigencia_defecto">Días de Vigencia de Cotización</label>
          <input class="field-input" id="vigencia_defecto" name="vigencia_defecto" type="number" step="1" min="1" max="90" value="<?= ehVal($curVigencia) ?>">
        </div>

        <div>
          <label class="field-label" for="moneda_simbolo">Símbolo de Moneda</label>
          <input class="field-input" id="moneda_simbolo" name="moneda_simbolo" value="<?= ehVal($curMoneda) ?>" style="max-width:100px;">
        </div>

        <div>
          <label class="field-label" for="iva_tasa">% Tasa de IVA</label>
          <input class="field-input" id="iva_tasa" name="iva_tasa" type="number" step="0.1" value="<?= ehVal($curIva) ?>" style="max-width:100px;">
        </div>
      </div>
    </section>

    <!-- 4. Cuentas Bancarias para Depósitos -->
    <section class="settings-section">
      <h3 class="settings-title">💳 Cuentas Bancarias para Depósito</h3>
      <p class="settings-desc">Cuentas que se imprimen en el PDF y en el enlace web para que el cliente realice su transferencia o anticipo.</p>

      <div class="settings-grid">
        <div class="full"><strong style="font-size:14px;color:var(--ios-primary);">Cuenta Principal (Banco 1)</strong></div>
        <div>
          <label class="field-label">Banco</label>
          <input class="field-input" name="banco1_nombre" value="<?= ehVal($curB1Nombre) ?>" placeholder="Ej. BBVA Bancomer">
        </div>
        <div>
          <label class="field-label">Titular de la cuenta</label>
          <input class="field-input" name="banco1_titular" value="<?= ehVal($curB1Titular) ?>" placeholder="Nombre de la empresa o titular">
        </div>
        <div>
          <label class="field-label">Número de cuenta / Tarjeta</label>
          <input class="field-input" name="banco1_cuenta" value="<?= ehVal($curB1Cuenta) ?>" placeholder="Número de cuenta">
        </div>
        <div>
          <label class="field-label">CLABE Interbancaria (18 dígitos)</label>
          <input class="field-input" name="banco1_clabe" value="<?= ehVal($curB1Clabe) ?>" placeholder="18 dígitos para SPEI">
        </div>

        <div class="full" style="margin-top:10px;"><strong style="font-size:14px;color:var(--ios-muted);">Cuenta Secundaria (Banco 2 - Opcional)</strong></div>
        <div>
          <label class="field-label">Banco</label>
          <input class="field-input" name="banco2_nombre" value="<?= ehVal($curB2Nombre) ?>" placeholder="Ej. Santander / Banorte">
        </div>
        <div>
          <label class="field-label">Titular de la cuenta</label>
          <input class="field-input" name="banco2_titular" value="<?= ehVal($curB2Titular) ?>" placeholder="Titular">
        </div>
        <div>
          <label class="field-label">Número de cuenta</label>
          <input class="field-input" name="banco2_cuenta" value="<?= ehVal($curB2Cuenta) ?>">
        </div>
        <div>
          <label class="field-label">CLABE Interbancaria</label>
          <input class="field-input" name="banco2_clabe" value="<?= ehVal($curB2Clabe) ?>">
        </div>
      </div>
    </section>

    <!-- 5. Licencia del Sistema -->
    <section class="settings-section">
      <h3 class="settings-title">🔑 Licencia del Sistema & Soporte Offline</h3>
      <p class="settings-desc">Estado de activación del software para este servidor y taller.</p>

      <div style="background:rgba(255,255,255,0.03);border:1px solid var(--ios-border);border-radius:16px;padding:18px;margin-bottom:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
          <div>
            <div style="font-weight:700;font-size:15px;color:<?= $infoLicencia['valida'] ? 'var(--ios-success)' : 'var(--ios-danger)' ?>;">
              <?= $infoLicencia['valida'] ? '● LICENCIA ACTIVA' : '● LICENCIA EXPIRADA O INVÁLIDA' ?>
            </div>
            <div class="muted" style="font-size:13px;margin-top:2px;">
              <?= ehVal($infoLicencia['mensaje']) ?> · Vence: <b><?= ehVal((string)($infoLicencia['valida_hasta'] ?? 'N/A')) ?></b>
            </div>
          </div>
          <span class="badge <?= $infoLicencia['valida'] ? 'badge-green' : 'badge-red' ?>" style="font-size:12px;text-transform:uppercase;">
            Plan <?= ehVal(strtoupper((string)($infoLicencia['plan'] ?? 'STANDARD'))) ?>
          </span>
        </div>
      </div>

      <div class="settings-grid">
        <div class="full">
          <label class="field-label" for="licencia_token">Clave de Licencia (Token Criptográfico)</label>
          <textarea class="field-input" id="licencia_token" name="licencia_token" style="min-height:70px;font-family:monospace;font-size:12px;" placeholder="Pega aquí tu clave de licencia entregada por el desarrollador..."><?= ehVal($curLicenciaToken) ?></textarea>
          <div class="muted" style="font-size:12px;margin-top:6px;">Esta clave valida el software sin necesidad de internet permanente.</div>
        </div>
      </div>
    </section>

    <!-- Botón Flotante / Inferior de Guardar -->
    <div style="display:flex;justify-content:flex-end;gap:12px;margin:24px 0 60px 0;">
      <a class="btn light" href="dashboard.php">Cancelar</a>
      <button type="submit" class="btn blue" style="font-size:16px;padding:14px 28px;">Guardar Todos los Ajustes</button>
    </div>
  </form>
</main>

<script>
// Previsualización instantánea del logotipo al seleccionarlo
document.getElementById('logo-input')?.addEventListener('change', function(e) {
  const file = e.target.files[0];
  if (file) {
    const reader = new FileReader();
    reader.onload = function(evt) {
      document.getElementById('logo-preview').src = evt.target.result;
    };
    reader.readAsDataURL(file);
  }
});
</script>

</body>
</html>
