<?php
declare(strict_types=1);
function srnu_ui_head(): string {
    $v = @filemtime(__DIR__ . '/../assets/app.css') ?: 1;
    $js = @filemtime(__DIR__ . '/../assets/app.js') ?: 1;
    return '<link rel="stylesheet" href="assets/app.css?v='.(int)$v.'"><script defer src="assets/app.js?v='.(int)$js.'"></script>';
}
function srnu_appbar(string $active=''): void {
    $items = [
      'dashboard'=>['dashboard.php','Dashboard'],
      'cotizaciones'=>['cotizaciones.php','Cotizaciones'],
      'rapido'=>['cotizador_rapido.php','⚡ Rápida'],
      'clientes'=>['clientes.php','Clientes'],
      'agenda'=>['agenda.php','Agenda'],
      'inventario'=>['inventario.php','Inventario'],
      'compras'=>['compras.php','Compras'],
      'finanzas'=>['finanzas.php','Finanzas'],
      'catalogo'=>['catalogo.php','Catálogo'],
      'reportes'=>['reportes.php','Reportes'],
      'ia'=>['ia_local.php','IA Local'],
    ];
    if (function_exists('srnu_auth_is_admin') && srnu_auth_is_admin()) {
        $items['web']=['conexion_web.php','Acceso web'];
        $items['usuarios']=['usuarios.php','Usuarios'];
    } elseif (function_exists('srnu_auth_logged_in') && srnu_auth_logged_in()) {
        foreach (['compras','finanzas','reportes','ia'] as $restricted) unset($items[$restricted]);
    }
    echo '<header class="srnu-appbar"><div class="srnu-appbar-inner">';
    echo '<a class="srnu-brand" href="dashboard.php"><img src="favicon.png" alt="SRNU"><div><strong>SRNU CUSTOM</strong><span>Sistema local de gestión</span></div></a>';
    echo '<nav class="srnu-nav">';
    foreach($items as $key=>$it){$cl=$active===$key?'active':'';echo '<a class="'.$cl.'" href="'.$it[0].'">'.$it[1].'</a>';}
    echo '<a class="primary" href="cotizador.php">＋ Nueva cotización</a></nav>';
    echo '<button type="button" class="srnu-theme-btn" data-theme-toggle title="Cambiar tema">◐</button>';
    if (function_exists('srnu_auth_user') && ($u=srnu_auth_user())) {
        echo '<div class="srnu-user-pill"><span>'.htmlspecialchars((string)$u['nombre'],ENT_QUOTES,'UTF-8').'</span><small>'.htmlspecialchars(strtoupper((string)$u['rol']),ENT_QUOTES,'UTF-8').'</small><a href="logout.php">Salir</a></div>';
    }
    echo '<div class="srnu-local-badge"><span class="srnu-local-dot"></span>PROTEGIDO</div>';
    echo '<button type="button" class="srnu-menu-btn" data-srnu-menu aria-label="Abrir menú">☰</button>';
    echo '</div><nav class="srnu-mobile-nav">';
    foreach($items as $it) echo '<a href="'.$it[0].'">'.$it[1].'</a>';
    echo '<a href="cotizador.php">＋ Nueva cotización</a><button type="button" data-theme-toggle>◐ Cambiar tema</button>'; if(function_exists('srnu_auth_logged_in')&&srnu_auth_logged_in()) echo '<a href="logout.php">Cerrar sesión</a>'; echo '</nav></header>';
}
