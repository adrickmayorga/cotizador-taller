<?php
declare(strict_types=1);

require_once __DIR__ . '/web.php';

function srnu_ui_head(string $title = ''): string {
    $v = @filemtime(__DIR__ . '/../assets/app.css') ?: 1;
    $js = @filemtime(__DIR__ . '/../assets/app.js') ?: 1;
    $logoUrl = srnu_cfg_get('taller_logo', 'favicon.png');
    
    $html = '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">' . "\n";
    $html .= '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    $html .= '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
    $html .= '<meta name="theme-color" content="#000000">' . "\n";
    $html .= '<link rel="apple-touch-icon" href="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    $html .= '<link rel="stylesheet" href="assets/app.css?v=' . (int)$v . '">' . "\n";
    $html .= '<script defer src="assets/app.js?v=' . (int)$js . '"></script>' . "\n";
    return $html;
}

function srnu_appbar(string $active = ''): void {
    $nombreTaller = trim(srnu_cfg_get('taller_nombre', 'SRNU CUSTOM'));
    if ($nombreTaller === '') $nombreTaller = 'TALLER PRO';
    $sloganTaller = trim(srnu_cfg_get('taller_slogan', 'Sistema de Gestión Automotriz'));
    $logoTaller = trim(srnu_cfg_get('taller_logo', 'favicon.png'));
    if (!is_file(__DIR__ . '/../' . $logoTaller) && !is_file($logoTaller)) {
        $logoTaller = 'favicon.png';
    }

    $isAdmin = function_exists('srnu_auth_is_admin') && srnu_auth_is_admin();
    $isLogged = function_exists('srnu_auth_logged_in') && srnu_auth_logged_in();
    $user = function_exists('srnu_auth_user') ? srnu_auth_user() : null;

    // Estructura de navegación jerárquica estilo iPadOS / macOS
    $navGroups = [
        'OPERACIÓN' => [
            'dashboard'   => ['dashboard.php', 'Dashboard', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/></svg>'],
            'cotizaciones'=> ['cotizaciones.php', 'Cotizaciones', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>'],
            'rapido'      => ['cotizador_rapido.php', '⚡ Cotiz. Rápida', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>'],
            'agenda'      => ['agenda.php', 'Agenda de Citas', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>'],
        ],
        'TALLER & AUTOS' => [
            'recepcion'   => ['recepcion.php', 'Recepción / Check-in', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><polyline points="9 14 12 17 16 11"/></svg>'],
            'vehiculos'   => ['vehiculos.php', 'Expedientes / Autos', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 17h14M5 17a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2M5 17a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm14 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>'],
            'clientes'    => ['clientes.php', 'Directorio Clientes', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
            'inventario'  => ['inventario.php', 'Inventario y Refacciones', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>'],
            'compras'     => ['compras.php', 'Compras y Pedidos', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>'],
        ],
        'FINANZAS & CONTROL' => [
            'caja'        => ['caja.php', 'Cortes de Caja', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>'],
            'gastos'      => ['gastos.php', 'Gastos Operativos', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>'],
            'costos'      => ['costos.php', 'Costos Reales / Margen', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>'],
            'catalogo'    => ['catalogo.php', 'Catálogo de Servicios', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>'],
            'reportes'    => ['reportes.php', 'Métricas y Reportes', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>'],
        ],
        'AJUSTES & SISTEMA' => [
            'ajustes'     => ['ajustes_taller.php', 'Ajustes del Taller', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>'],
            'usuarios'    => ['usuarios.php', 'Usuarios y Roles', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>'],
            'web'         => ['conexion_web.php', 'Acceso Remoto / Web', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>'],
            'ia'          => ['ia_local.php', 'IA Local (Ollama)', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>'],
        ]
    ];

    // Permisos según rol
    if (!$isAdmin && $isLogged) {
        unset($navGroups['FINANZAS & CONTROL']['compras']);
        unset($navGroups['FINANZAS & CONTROL']['gastos']);
        unset($navGroups['FINANZAS & CONTROL']['costos']);
        unset($navGroups['FINANZAS & CONTROL']['reportes']);
        unset($navGroups['AJUSTES & SISTEMA']);
    }

    echo '<!-- iOS / Apple Top Bar -->';
    echo '<header class="ios-topbar">';
    echo '  <div class="ios-topbar-left">';
    echo '    <button type="button" class="ios-btn-sidebar-toggle" data-sidebar-toggle aria-label="Abrir barra lateral">';
    echo '      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="3" width="18" height="18" rx="4"/><line x1="9" y1="3" x2="9" y2="21"/></svg>';
    echo '    </button>';
    echo '    <a href="dashboard.php" class="ios-topbar-brand">';
    echo '      <img src="' . htmlspecialchars($logoTaller, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($nombreTaller, ENT_QUOTES, 'UTF-8') . '">';
    echo '      <div class="ios-brand-texts">';
    echo '        <span class="ios-brand-title">' . htmlspecialchars($nombreTaller, ENT_QUOTES, 'UTF-8') . '</span>';
    echo '        <span class="ios-brand-sub">' . htmlspecialchars($sloganTaller, ENT_QUOTES, 'UTF-8') . '</span>';
    echo '      </div>';
    echo '    </a>';
    echo '  </div>';

    echo '  <div class="ios-topbar-center">';
    echo '    <a href="asistente_ia.php" class="ios-quick-search" title="Buscar o consultar asistente">';
    echo '      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
    echo '      <span>Buscar cliente, auto o folio...</span>';
    echo '      <kbd>⌘K</kbd>';
    echo '    </a>';
    echo '  </div>';

    echo '  <div class="ios-topbar-right">';
    echo '    <a href="cotizador.php" class="ios-btn-cta">';
    echo '      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
    echo '      <span>Nueva Cotización</span>';
    echo '    </a>';
    echo '    <button type="button" class="ios-icon-btn" data-theme-toggle title="Cambiar tema claro/oscuro">';
    echo '      <span class="theme-icon-light">◐</span>';
    echo '    </button>';
    if ($user) {
        $inicial = mb_strtoupper(mb_substr((string)($user['nombre'] ?? 'U'), 0, 1, 'UTF-8'), 'UTF-8');
        echo '    <div class="ios-user-badge" title="' . htmlspecialchars((string)$user['nombre'], ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars(strtoupper((string)$user['rol']), ENT_QUOTES, 'UTF-8') . ')">';
        echo '      <div class="ios-user-avatar">' . htmlspecialchars($inicial, ENT_QUOTES, 'UTF-8') . '</div>';
        echo '      <div class="ios-user-info">';
        echo '        <strong>' . htmlspecialchars((string)$user['nombre'], ENT_QUOTES, 'UTF-8') . '</strong>';
        echo '        <a href="logout.php">Cerrar sesión</a>';
        echo '      </div>';
        echo '    </div>';
    }
    echo '  </div>';
    echo '</header>';

    echo '<!-- iOS Sidebar Flotante -->';
    echo '<aside class="ios-sidebar" id="ios-sidebar">';
    echo '  <div class="ios-sidebar-scroll">';
    foreach ($navGroups as $groupTitle => $items) {
        echo '    <div class="ios-nav-group">';
        echo '      <div class="ios-nav-group-title">' . htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8') . '</div>';
        foreach ($items as $k => [$url, $label, $iconSvg]) {
            $isActive = ($active === $k) ? 'active' : '';
            echo '      <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="ios-nav-item ' . $isActive . '">';
            echo '        <span class="ios-nav-icon">' . $iconSvg . '</span>';
            echo '        <span class="ios-nav-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
            echo '      </a>';
        }
        echo '    </div>';
    }
    echo '  </div>';
    echo '</aside>';

    echo '<!-- Overlay para móvil -->';
    echo '<div class="ios-overlay" id="ios-overlay" data-sidebar-close></div>';

    echo '<!-- iOS Bottom Floating Tab Bar (Mobile) -->';
    echo '<nav class="ios-bottom-bar">';
    echo '  <a href="dashboard.php" class="ios-tab-item ' . ($active === 'dashboard' ? 'active' : '') . '">';
    echo '    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/></svg>';
    echo '    <span>Inicio</span>';
    echo '  </a>';
    echo '  <a href="cotizaciones.php" class="ios-tab-item ' . ($active === 'cotizaciones' ? 'active' : '') . '">';
    echo '    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
    echo '    <span>Cotizaciones</span>';
    echo '  </a>';
    echo '  <a href="cotizador.php" class="ios-tab-fab" title="Nueva Cotización">';
    echo '    <span>+</span>';
    echo '  </a>';
    echo '  <a href="recepcion.php" class="ios-tab-item ' . ($active === 'recepcion' ? 'active' : '') . '">';
    echo '    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>';
    echo '    <span>Recepción</span>';
    echo '  </a>';
    echo '  <button type="button" class="ios-tab-item" data-sidebar-toggle style="background:none;border:none;cursor:pointer">';
    echo '    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>';
    echo '    <span>Menú</span>';
    echo '  </button>';
    echo '</nav>';
}
