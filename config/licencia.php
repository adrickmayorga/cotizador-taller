<?php
declare(strict_types=1);
require_once __DIR__ . '/web.php';

/**
 * Motor de Licenciamiento y Activación Offline para Cotizador ERP Taller.
 * Permite validar tokens criptográficos firmados con HMAC-SHA256 sin requerir
 * conexión obligatoria a internet.
 */

define('COTIZADOR_LICENSE_SECRET', 'CILServices_Cotizador_ERP_MasterKey_2026!#99xX');

function srnu_licencia_obtener_token(): string
{
    $keyFile = __DIR__ . '/licencia.key';
    if (is_file($keyFile)) {
        $token = trim((string)@file_get_contents($keyFile));
        if ($token !== '') return $token;
    }
    return trim(srnu_cfg_get('licencia_token', ''));
}

function srnu_licencia_guardar_token(string $token): void
{
    $token = trim($token);
    @file_put_contents(__DIR__ . '/licencia.key', $token);
    srnu_cfg_set('licencia_token', $token);
}

function srnu_licencia_generar(string $taller, string $validaHasta, string $plan = 'pro'): string
{
    $payload = [
        'taller'       => trim($taller),
        'valida_hasta' => trim($validaHasta), // Formato YYYY-MM-DD o 'lifetime'
        'plan'         => trim($plan),
        'emitida_el'   => date('Y-m-d H:i:s'),
        'nonce'        => bin2hex(random_bytes(6)),
    ];
    $json = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $firma = hash_hmac('sha256', $b64, COTIZADOR_LICENSE_SECRET);
    return $b64 . '.' . $firma;
}

function srnu_licencia_validar(?string $token = null): array
{
    if ($token === null) {
        $token = srnu_licencia_obtener_token();
    }
    $token = trim((string)$token);

    if ($token === '') {
        // Modo demostración o gracia de 15 días si no hay licencia cargada
        return [
            'valida'        => true,
            'modo'          => 'demo',
            'mensaje'       => 'Periodo de prueba activo',
            'taller'        => srnu_cfg_get('taller_nombre', 'Taller Pro'),
            'plan'          => 'demo',
            'dias_restantes'=> 30,
            'valida_hasta'  => date('Y-m-d', strtotime('+30 days')),
        ];
    }

    $partes = explode('.', $token);
    if (count($partes) !== 2) {
        return [
            'valida'  => false,
            'modo'    => 'invalida',
            'mensaje' => 'El formato del token de licencia es incorrecto.',
        ];
    }

    [$b64, $firmaDada] = $partes;
    $firmaEsperada = hash_hmac('sha256', $b64, COTIZADOR_LICENSE_SECRET);
    if (!hash_equals($firmaEsperada, $firmaDada)) {
        return [
            'valida'  => false,
            'modo'    => 'invalida',
            'mensaje' => 'La firma digital de la licencia no coincide o fue manipulada.',
        ];
    }

    $json = base64_decode(strtr($b64, '-_', '+/'), true);
    if ($json === false) {
        return [
            'valida'  => false,
            'modo'    => 'invalida',
            'mensaje' => 'No se pudo decodificar el payload de la licencia.',
        ];
    }

    $datos = json_decode($json, true);
    if (!is_array($datos) || empty($datos['taller']) || empty($datos['valida_hasta'])) {
        return [
            'valida'  => false,
            'modo'    => 'invalida',
            'mensaje' => 'La licencia contiene campos corruptos o incompletos.',
        ];
    }

    $hasta = (string)$datos['valida_hasta'];
    if ($hasta === 'lifetime') {
        return [
            'valida'        => true,
            'modo'          => 'lifetime',
            'mensaje'       => 'Licencia Vitalicia Oficial',
            'taller'        => (string)$datos['taller'],
            'plan'          => (string)($datos['plan'] ?? 'pro'),
            'dias_restantes'=> 9999,
            'valida_hasta'  => 'Vitalicia',
        ];
    }

    $tsVencimiento = strtotime($hasta . ' 23:59:59');
    $hoy = time();
    $diasRestantes = (int)ceil(($tsVencimiento - $hoy) / 86400);

    if ($diasRestantes < 0) {
        return [
            'valida'        => false,
            'modo'          => 'expirada',
            'mensaje'       => 'La licencia del sistema expiró el ' . $hasta . '.',
            'taller'        => (string)$datos['taller'],
            'dias_restantes'=> 0,
            'valida_hasta'  => $hasta,
        ];
    }

    return [
        'valida'        => true,
        'modo'          => 'activa',
        'mensaje'       => 'Licencia activa (' . $diasRestantes . ' días restantes)',
        'taller'        => (string)$datos['taller'],
        'plan'          => (string)($datos['plan'] ?? 'pro'),
        'dias_restantes'=> $diasRestantes,
        'valida_hasta'  => $hasta,
    ];
}
