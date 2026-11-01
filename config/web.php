<?php
declare(strict_types=1);
require_once __DIR__ . '/lan.php';

function srnu_cfg_get(string $clave, string $default=''): string
{
    try {
        $s = srnu_db()->prepare('SELECT valor FROM configuracion_sistema WHERE clave=? LIMIT 1');
        $s->execute([$clave]);
        $v = $s->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}

function srnu_cfg_set(string $clave, string $valor): void
{
    $s = srnu_db()->prepare('INSERT INTO configuracion_sistema(clave,valor) VALUES(?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor), updated_at=NOW()');
    $s->execute([$clave,$valor]);
}

function srnu_public_base_url(): string
{
    $url = trim(srnu_cfg_get('public_base_url',''));
    if ($url === '') return '';
    if (!preg_match('~^https://[a-z0-9][a-z0-9.-]*(?::\d+)?(?:/[^?#]*)?$~i', $url)) return '';
    return rtrim($url,'/');
}

function srnu_app_path(): string
{
    $dir = str_replace('\\','/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($dir === '/' || $dir === '.') return '';
    $parts = array_filter(explode('/', trim($dir,'/')), 'strlen');
    return '/' . implode('/', array_map('rawurlencode', $parts));
}

function srnu_current_base_url(?string $host=null): string
{
    $host = $host ?? (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    return ($https?'https':'http').'://'.$host.srnu_app_path();
}

function srnu_lan_base_url(?string $host=null): string
{
    $host = $host ?? (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $ip = srnu_detectar_ip_lan($host);
    if ($ip === '') return '';
    return 'http://'.$ip.srnu_puerto_http_actual($host).srnu_app_path();
}

function srnu_share_base_url(?string $host=null): string
{
    $public = srnu_public_base_url();
    if ($public !== '') return $public;
    $lan = srnu_lan_base_url($host);
    return $lan !== '' ? $lan : srnu_current_base_url($host);
}
