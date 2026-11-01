<?php
declare(strict_types=1);
require_once __DIR__.'/config/erp.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Usa el botón de recuperación del catálogo.']); exit; }
$token = (string)($_SESSION['catalogo_recuperar_token'] ?? '');
if ($token === '' || !hash_equals($token, (string)($_POST['token'] ?? ''))) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Recarga el catálogo y vuelve a intentarlo.']); exit;
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
try {
    $resultado = srnu_recuperar_catalogo_cotizaciones(srnu_db(), max(0, (int)($_POST['cursor'] ?? 0)));
    echo json_encode(['ok'=>true]+$resultado, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('SRNU recuperar catálogo: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'No se pudo completar este lote. Puedes reintentar sin duplicar los artículos ya recuperados.'], JSON_UNESCAPED_UNICODE);
}
