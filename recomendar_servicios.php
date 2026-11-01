<?php
declare(strict_types=1);
require_once __DIR__.'/config/erp.php';
header('Content-Type: application/json; charset=utf-8');
try{
    $vehiculo=trim((string)($_POST['vehiculo']??''));
    $existentes=json_decode((string)($_POST['existentes']??'[]'),true); if(!is_array($existentes))$existentes=[];
    $catalogo=srnu_catalogo_activo();
    $r=srnu_ai_recomendar_servicios($vehiculo,$catalogo,$existentes);
    echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'No se pudieron generar recomendaciones.','detalle'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
