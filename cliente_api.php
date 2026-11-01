<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new RuntimeException('Método no permitido.'); }
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $telefono = trim((string)($_POST['telefono'] ?? ''));
    if ($nombre === '' || srnu_normalizar_telefono($telefono) === '') { http_response_code(422); throw new RuntimeException('Nombre y teléfono son obligatorios.'); }
    $id = srnu_guardar_cliente($nombre, $telefono);
    $cliente = srnu_obtener_cliente($id);
    echo json_encode(['ok'=>true,'cliente'=>$cliente], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
