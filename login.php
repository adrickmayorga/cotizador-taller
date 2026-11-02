<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/web.php';

$pdo = srnu_db();
$count = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
if ($count === 0) {
    header('Location: configurar_acceso.php');
    exit;
}
if (srnu_auth_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$next = srnu_auth_safe_next($_GET['next'] ?? $_POST['next'] ?? 'dashboard.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!srnu_auth_check_csrf($_POST['csrf'] ?? null)) {
        $error = 'La sesión del formulario expiró. Intenta nuevamente.';
    } else {
        $usuario = trim((string)($_POST['usuario'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $s = $pdo->prepare('SELECT * FROM usuarios WHERE usuario=? AND activo=1 LIMIT 1');
        $s->execute([$usuario]);
        $u = $s->fetch();
        if (!$u || !password_verify($password, (string)$u['password_hash'])) {
            $error = 'Usuario o contraseña incorrectos.';
            usleep(250000);
        } else {
            srnu_auth_login($u);
            $pdo->prepare('UPDATE usuarios SET ultimo_acceso=NOW() WHERE id=?')->execute([(int)$u['id']]);
            header('Location: ' . $next);
            exit;
        }
    }
}

function lh($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$nombreTaller = trim(srnu_cfg_get('taller_nombre', 'SRNU CUSTOM'));
if ($nombreTaller === '') $nombreTaller = 'TALLER PRO';
$sloganTaller = trim(srnu_cfg_get('taller_slogan', 'Sistema de Gestión Automotriz'));
$logoTaller = trim(srnu_cfg_get('taller_logo', 'favicon.png'));
if (!is_file(__DIR__ . '/' . $logoTaller)) {
    $logoTaller = 'favicon.png';
}
?>
<!doctype html>
<html lang="es" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="theme-color" content="#000000">
  <title>Acceso · <?= lh($nombreTaller) ?></title>
  <link rel="icon" href="<?= lh($logoTaller) ?>">
  <link rel="apple-touch-icon" href="<?= lh($logoTaller) ?>">
  <style>
    :root {
      --bg: #000000;
      --card-bg: rgba(28, 28, 30, 0.75);
      --card-border: rgba(255, 255, 255, 0.12);
      --input-bg: rgba(44, 44, 46, 0.6);
      --input-border: rgba(255, 255, 255, 0.1);
      --text: #ffffff;
      --text-muted: #8e8e93;
      --primary: #0a84ff;
      --primary-hover: #0071e3;
      --danger: #ff453a;
      --success: #32d74b;
    }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body {
      margin: 0;
      min-height: 100vh;
      min-height: 100dvh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: radial-gradient(ellipse at 50% 20%, #1c1c24 0%, #0a0a0c 50%, #000000 100%);
      font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", "Helvetica Neue", Helvetica, Arial, sans-serif;
      color: var(--text);
      -webkit-font-smoothing: antialiased;
    }
    .login-container {
      width: 100%;
      max-width: 400px;
      background: var(--card-bg);
      backdrop-filter: blur(40px) saturate(180%);
      -webkit-backdrop-filter: blur(40px) saturate(180%);
      border: 1px solid var(--card-border);
      border-radius: 28px;
      padding: 38px 32px;
      box-shadow: 0 30px 90px rgba(0, 0, 0, 0.6), 0 0 1px rgba(255, 255, 255, 0.2) inset;
      animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(14px) scale(0.98); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .brand-header {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      margin-bottom: 30px;
    }
    .brand-logo {
      width: 68px;
      height: 68px;
      border-radius: 18px;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 10px 24px rgba(0, 0, 0, 0.4);
      margin-bottom: 14px;
      overflow: hidden;
    }
    .brand-logo img {
      width: 48px;
      height: 48px;
      object-fit: contain;
    }
    .brand-header h1 {
      font-size: 22px;
      font-weight: 700;
      letter-spacing: -0.02em;
      margin: 0;
    }
    .brand-header p {
      color: var(--text-muted);
      font-size: 13px;
      margin: 4px 0 0;
      letter-spacing: -0.01em;
    }
    .alert-banner {
      border-radius: 14px;
      padding: 12px 14px;
      font-size: 13px;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .alert-banner.error {
      background: rgba(255, 69, 58, 0.15);
      border: 1px solid rgba(255, 69, 58, 0.3);
      color: #ff6961;
    }
    .alert-banner.info {
      background: rgba(50, 215, 75, 0.15);
      border: 1px solid rgba(50, 215, 75, 0.3);
      color: #32d74b;
    }
    .form-group {
      margin-bottom: 18px;
    }
    .form-label {
      display: block;
      font-size: 12px;
      font-weight: 600;
      color: var(--text-muted);
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .form-input {
      width: 100%;
      background: var(--input-bg);
      border: 1px solid var(--input-border);
      border-radius: 14px;
      padding: 14px 16px;
      font-size: 15px;
      color: #fff;
      font-family: inherit;
      transition: all 0.2s ease;
      outline: none;
    }
    .form-input:focus {
      background: rgba(58, 58, 60, 0.75);
      border-color: var(--primary);
      box-shadow: 0 0 0 4px rgba(10, 132, 255, 0.2);
    }
    .form-btn {
      width: 100%;
      background: var(--primary);
      color: #fff;
      border: none;
      border-radius: 14px;
      padding: 15px;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
      box-shadow: 0 4px 16px rgba(10, 132, 255, 0.35);
      transition: all 0.2s ease;
      margin-top: 8px;
    }
    .form-btn:hover {
      background: var(--primary-hover);
      box-shadow: 0 6px 20px rgba(10, 132, 255, 0.5);
      transform: translateY(-1px);
    }
    .form-btn:active {
      transform: scale(0.98);
    }
    .login-footer {
      text-align: center;
      margin-top: 26px;
      font-size: 12px;
      color: var(--text-muted);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }
    .status-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: var(--success);
      box-shadow: 0 0 6px var(--success);
    }
  </style>
</head>
<body>

<main class="login-container">
  <div class="brand-header">
    <div class="brand-logo">
      <img src="<?= lh($logoTaller) ?>" alt="<?= lh($nombreTaller) ?>">
    </div>
    <h1><?= lh($nombreTaller) ?></h1>
    <p><?= lh($sloganTaller) ?></p>
  </div>

  <?php if (isset($_GET['expirada'])): ?>
    <div class="alert-banner info">
      <span>⏱️</span>
      <div>Tu sesión anterior terminó por inactividad. Inicia sesión nuevamente.</div>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert-banner error">
      <span>⚠️</span>
      <div><?= lh($error) ?></div>
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= lh(srnu_auth_csrf()) ?>">
    <input type="hidden" name="next" value="<?= lh($next) ?>">

    <div class="form-group">
      <label class="form-label" for="usuario">Usuario</label>
      <input class="form-input" id="usuario" name="usuario" autocomplete="username" required autofocus placeholder="Ingresa tu usuario">
    </div>

    <div class="form-group">
      <label class="form-label" for="password">Contraseña</label>
      <input class="form-input" id="password" type="password" name="password" autocomplete="current-password" required placeholder="••••••••">
    </div>

    <button type="submit" class="form-btn">Continuar</button>
  </form>

  <div class="login-footer">
    <span class="status-dot"></span>
    <span>Acceso seguro protegido · Servidor Local</span>
  </div>
</main>

</body>
</html>
