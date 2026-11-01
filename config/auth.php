<?php
declare(strict_types=1);

function srnu_auth_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    $proto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    return $proto === 'https';
}

function srnu_auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    session_name('SRNUSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => srnu_auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function srnu_auth_public_scripts(): array
{
    return ['login.php','logout.php','configurar_acceso.php','firmar.php','confirmar_local.php'];
}

function srnu_auth_admin_scripts(): array
{
    return [
        'usuarios.php','conexion_web.php','finanzas.php','caja.php','gastos.php','costos.php',
        'reportes.php','proveedores.php','compras.php','ia_local.php'
    ];
}

function srnu_auth_current_script(): string
{
    return basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
}

function srnu_auth_safe_next(?string $next): string
{
    $next = trim((string)$next);
    if ($next === '' || str_contains($next, "\r") || str_contains($next, "\n")) return 'dashboard.php';
    if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $next) || str_starts_with($next, '//')) return 'dashboard.php';
    $next = ltrim($next, '/');
    if ($next === '' || str_contains($next, '..')) return 'dashboard.php';
    return $next;
}

function srnu_auth_user(): ?array
{
    srnu_auth_start_session();
    $u = $_SESSION['srnu_user'] ?? null;
    return is_array($u) ? $u : null;
}

function srnu_auth_logged_in(): bool
{
    return srnu_auth_user() !== null;
}

function srnu_auth_is_admin(): bool
{
    $u = srnu_auth_user();
    return $u && (($u['rol'] ?? '') === 'admin');
}

function srnu_auth_csrf(): string
{
    srnu_auth_start_session();
    if (empty($_SESSION['srnu_csrf']) || !is_string($_SESSION['srnu_csrf'])) {
        $_SESSION['srnu_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['srnu_csrf'];
}

function srnu_auth_check_csrf(?string $token): bool
{
    srnu_auth_start_session();
    $stored = (string)($_SESSION['srnu_csrf'] ?? '');
    return $stored !== '' && is_string($token) && hash_equals($stored, $token);
}

function srnu_auth_login(array $row): void
{
    srnu_auth_start_session();
    session_regenerate_id(true);
    $_SESSION['srnu_user'] = [
        'id' => (int)$row['id'],
        'nombre' => (string)$row['nombre'],
        'usuario' => (string)$row['usuario'],
        'rol' => (string)($row['rol'] ?? 'admin'),
    ];
    $_SESSION['srnu_last_activity'] = time();
    $_SESSION['srnu_csrf'] = bin2hex(random_bytes(32));
}

function srnu_auth_logout(): void
{
    srnu_auth_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $p['path'] ?? '/', $p['domain'] ?? '', (bool)($p['secure'] ?? false), (bool)($p['httponly'] ?? true));
    }
    session_destroy();
}

function srnu_auth_forbidden(string $message='No tienes permiso para entrar a este módulo.'): never
{
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acceso restringido · SRNU</title><body style="font-family:system-ui;background:#0b1120;color:#e5e7eb;padding:40px"><div style="max-width:620px;margin:auto;background:#111827;border:1px solid #334155;border-radius:18px;padding:28px"><h1>Acceso restringido</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p><p><a style="color:#93c5fd" href="dashboard.php">Volver al Dashboard</a></p></div></body></html>';
    exit;
}

function srnu_auth_require_admin(): void
{
    if (!srnu_auth_is_admin()) srnu_auth_forbidden();
}

function srnu_auth_bootstrap(): void
{
    if (PHP_SAPI === 'cli') return;
    srnu_auth_start_session();

    // Cabeceras seguras para uso local o detrás de HTTPS/Tailscale.
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');
    }

    $script = srnu_auth_current_script();
    if (in_array($script, srnu_auth_public_scripts(), true)) return;

    $u = $_SESSION['srnu_user'] ?? null;
    if (!is_array($u)) {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? 'dashboard.php');
        $path = basename((string)parse_url($uri, PHP_URL_PATH));
        $query = (string)(parse_url($uri, PHP_URL_QUERY) ?? '');
        $next = $path . ($query !== '' ? '?'.$query : '');
        header('Location: login.php?next='.rawurlencode(srnu_auth_safe_next($next)));
        exit;
    }

    $now = time();
    $last = (int)($_SESSION['srnu_last_activity'] ?? $now);
    if ($last > 0 && ($now - $last) > 28800) { // 8 horas sin actividad
        srnu_auth_logout();
        header('Location: login.php?expirada=1');
        exit;
    }
    $_SESSION['srnu_last_activity'] = $now;

    if (($u['rol'] ?? '') !== 'admin' && in_array($script, srnu_auth_admin_scripts(), true)) {
        srnu_auth_forbidden();
    }
}
