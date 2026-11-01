<?php
declare(strict_types=1);

/**
 * Utilidades LAN para SRNU CUSTOM.
 * Detecta la IPv4 real de la interfaz que tiene ruta de salida/default gateway,
 * evitando adaptadores virtuales que suelen devolver gethostbyname(gethostname()).
 */
function srnu_es_ip_lan_privada(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    return (bool)preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip);
}

function srnu_host_sin_puerto(string $host): string
{
    $host = trim($host);
    if ($host === '') return '';
    if ($host[0] === '[') {
        $end = strpos($host, ']');
        return $end === false ? trim($host, '[]') : substr($host, 1, $end - 1);
    }
    if (substr_count($host, ':') === 1) {
        return explode(':', $host, 2)[0];
    }
    return $host;
}

function srnu_puerto_http_actual(string $host): string
{
    if (preg_match('/:(\d+)$/', $host, $m)) {
        $port = (int)$m[1];
        if ($port > 0 && $port <= 65535 && !in_array($port, [80, 443], true)) {
            return ':'.$port;
        }
    }
    $serverPort = (int)($_SERVER['SERVER_PORT'] ?? 0);
    if ($serverPort > 0 && !in_array($serverPort, [80, 443], true)) {
        return ':'.$serverPort;
    }
    return '';
}

function srnu_detectar_ip_lan(?string $requestHost = null): string
{
    static $cache = [];
    $requestHost = $requestHost ?? (string)($_SERVER['HTTP_HOST'] ?? '');
    $cacheKey = $requestHost;
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

    // Si el ERP ya fue abierto mediante su IP LAN, esa IP es la mejor evidencia.
    $hostIp = srnu_host_sin_puerto($requestHost);
    if (srnu_es_ip_lan_privada($hostIp)) {
        return $cache[$cacheKey] = $hostIp;
    }

    // Override opcional para instalaciones donde Windows tenga varias rutas físicas.
    $override = trim((string)(getenv('SRNU_LAN_IP') ?: ''));
    if (srnu_es_ip_lan_privada($override)) {
        return $cache[$cacheKey] = $override;
    }

    $candidatos = [];

    // Windows/Laragon/XAMPP: la tabla de rutas identifica la interfaz que realmente
    // posee la ruta predeterminada. Esto evita WSL, Hyper-V, Docker, VirtualBox, etc.
    if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
        $salida = @shell_exec('route print -4 0.0.0.0 2>NUL');
        if (is_string($salida) && $salida !== '') {
            foreach (preg_split('/\R/', $salida) ?: [] as $linea) {
                if (preg_match('/^\s*0\.0\.0\.0\s+0\.0\.0\.0\s+\S+\s+(\d+\.\d+\.\d+\.\d+)\s+(\d+)\s*$/', $linea, $m)) {
                    $ip = $m[1];
                    $metric = (int)$m[2];
                    if (srnu_es_ip_lan_privada($ip)) {
                        $pref = str_starts_with($ip, '192.168.') ? 0 : (str_starts_with($ip, '10.') ? 10 : 20);
                        $candidatos[] = ['ip'=>$ip, 'score'=>$pref * 100000 + $metric];
                    }
                }
            }
        }
    }

    // Fallback Linux/macOS o Windows si route no estuvo disponible.
    if (!$candidatos && function_exists('shell_exec')) {
        $cmd = PHP_OS_FAMILY === 'Windows' ? 'ipconfig 2>NUL' : 'hostname -I 2>/dev/null';
        $salida = @shell_exec($cmd);
        if (is_string($salida) && preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $salida, $mm)) {
            foreach ($mm[0] as $ip) {
                if (srnu_es_ip_lan_privada($ip)) {
                    $pref = str_starts_with($ip, '192.168.') ? 0 : (str_starts_with($ip, '10.') ? 10 : 20);
                    $candidatos[] = ['ip'=>$ip, 'score'=>$pref * 100000 + count($candidatos)];
                }
            }
        }
    }

    if ($candidatos) {
        usort($candidatos, fn($a,$b) => $a['score'] <=> $b['score']);
        return $cache[$cacheKey] = (string)$candidatos[0]['ip'];
    }

    // Último fallback de compatibilidad.
    try {
        $ip = gethostbyname(gethostname());
        if (srnu_es_ip_lan_privada($ip)) {
            return $cache[$cacheKey] = $ip;
        }
    } catch (Throwable $e) {
    }

    return $cache[$cacheKey] = '';
}
