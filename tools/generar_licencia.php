<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/licencia.php';

$taller = $argv[1] ?? ($_GET['taller'] ?? 'SRNU Custom');
$vigencia = $argv[2] ?? ($_GET['vigencia'] ?? '2028-12-31');
$plan = $argv[3] ?? ($_GET['plan'] ?? 'pro');

$token = srnu_licencia_generar($taller, $vigencia, $plan);
$validacion = srnu_licencia_validar($token);

if (php_sapi_name() === 'cli') {
    echo "=============================================\n";
    echo "  GENERADOR DE LICENCIAS - COTIZADOR TALLER  \n";
    echo "=============================================\n";
    echo "Taller:    " . $taller . "\n";
    echo "Vigencia:  " . $vigencia . "\n";
    echo "Plan:      " . $plan . "\n";
    echo "Estado:    " . ($validacion['valida'] ? 'VÁLIDA' : 'ERROR') . "\n";
    echo "Token de Licencia:\n\n";
    echo $token . "\n\n";
    echo "Para activar en el servidor destino, guarda este token en config/licencia.key\n";
    echo "=============================================\n";
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'taller'     => $taller,
        'valida_hasta' => $vigencia,
        'plan'       => $plan,
        'token'      => $token,
        'validacion' => $validacion,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
