<?php
declare(strict_types=1);
require_once __DIR__ . '/config/erp.php';
header('Content-Type: application/json; charset=utf-8');

function ai_norm(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $map=['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n'];
    return strtr($s,$map);
}
function ai_add(array &$out,string $tipo,string $titulo,string $sub,string $url,string $badge=''): void {
    if(count($out)>=30)return;
    $key=$tipo.'|'.$url; foreach($out as $x) if(($x['_key']??'')===$key)return;
    $out[]=['_key'=>$key,'tipo'=>$tipo,'titulo'=>$titulo,'subtitulo'=>$sub,'url'=>$url,'badge'=>$badge];
}
try {
    $q=trim((string)($_POST['q']??$_GET['q']??''));
    if($q===''){echo json_encode(['ok'=>true,'mensaje'=>'Escribe qué quieres encontrar. Ejemplos: “cotizaciones pendientes”, “teléfono de Juan”, “garantías por vencer”, “stock bajo”, “pedido Tacoma”.','resultados'=>[]],JSON_UNESCAPED_UNICODE);exit;}
    $pdo=srnu_db(); $n=ai_norm($q); $out=[];
    // Qwen interpreta la intención y el término útil. Si Ollama no está disponible,
    // se mantiene el clasificador heurístico original para que el buscador nunca falle.
    $plan=srnu_ai_interpretar_busqueda($q); $iaActiva=is_array($plan);
    $words=array_values(array_filter(preg_split('/\s+/u',$n)?:[],fn($w)=>mb_strlen($w)>=2&&!in_array($w,['de','del','la','el','los','las','un','una','para','por','con','que','me','dame','busca','buscar','encuentra','encontrar'],true)));
    $termHeur=$words?end($words):$q;
    $termRaw=trim((string)($plan['term']??''));
    $term=$termRaw!==''?$termRaw:$termHeur;
    $genericos=['cotizacion','cotizaciones','pendiente','pendientes','pagado','pagados','liquidado','liquidados','garantia','garantias','vencer','vigente','vigentes','stock','inventario','bajo','compras','compra','pedido','pedidos','proveedor','proveedores'];
    $likeTerm=($termRaw===''&&$iaActiva)||in_array(ai_norm($term),$genericos,true)?'%':'%'.$term.'%';
    $domains=array_map('strval',(array)($plan['domains']??[]));
    $wantGarant=in_array('garantias',$domains,true)||str_contains($n,'garant');
    $wantStock=in_array('inventario',$domains,true)||str_contains($n,'stock')||str_contains($n,'inventario');
    $wantCompra=in_array('compras',$domains,true)||str_contains($n,'compra')||str_contains($n,'pedido')||str_contains($n,'proveedor');
    $wantCliente=in_array('clientes',$domains,true)||str_contains($n,'cliente')||str_contains($n,'telefono')||preg_match('/\d{7,}/',$n);
    $wantPago=in_array('pagos',$domains,true)||str_contains($n,'pago')||str_contains($n,'saldo')||str_contains($n,'debe')||str_contains($n,'pagad');
    $wantCotizacion=!$iaActiva||!$domains||in_array('cotizaciones',$domains,true)||$wantPago;
    $wantCatalogo=!$iaActiva||!$domains||in_array('catalogo',$domains,true);
    $estadoDetectado=trim((string)($plan['estado']??''));
    $estadosValidos=['Cotización','Aprobada','Anticipo recibido','Vehículo recibido','En proceso','Terminado','Entregado','Cancelado'];
    if(!in_array($estadoDetectado,$estadosValidos,true)){$estadoDetectado='';foreach(['cotizacion'=>'Cotización','aprobada'=>'Aprobada','anticipo recibido'=>'Anticipo recibido','vehiculo recibido'=>'Vehículo recibido','en proceso'=>'En proceso','terminado'=>'Terminado','entregado'=>'Entregado','cancelado'=>'Cancelado'] as $k=>$v){if(str_contains($n,$k)){$estadoDetectado=$v;break;}}}
    $pagoPlan=(string)($plan['pago']??'');

    if($wantCotizacion){
        // Cotizaciones: búsqueda por cliente/teléfono/vehículo/folio/concepto.
        $sql="SELECT c.id,c.folio,c.cliente,c.telefono,c.vehiculo,c.estado,c.total,c.fecha,COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.cotizacion_id=c.id),0) pagado FROM cotizaciones c WHERE (c.cliente LIKE ? OR c.telefono LIKE ? OR c.vehiculo LIKE ? OR c.folio LIKE ? OR EXISTS(SELECT 1 FROM cotizacion_items ci WHERE ci.cotizacion_id=c.id AND ci.concepto LIKE ?))";
        $ps=[$likeTerm,$likeTerm,$likeTerm,$likeTerm,$likeTerm];
    if($estadoDetectado!==''){$sql.=' AND c.estado=?';$ps[]=$estadoDetectado;}
    if($pagoPlan==='pendiente'||str_contains($n,'pendiente')||str_contains($n,'debe'))$sql.=' HAVING (c.total-pagado)>0.01';
    elseif($pagoPlan==='pagado'||str_contains($n,'pagado')||str_contains($n,'liquidado'))$sql.=' HAVING (c.total-pagado)<=0.01';
        $sql.=' ORDER BY c.id DESC LIMIT 12';
        $st=$pdo->prepare($sql);$st->execute($ps);foreach($st as $r){$saldo=max(0,(float)$r['total']-(float)$r['pagado']);ai_add($out,'Cotización',$r['folio'].' · '.$r['cliente'],$r['vehiculo'].' · '.$r['estado'].' · Saldo $'.number_format($saldo,2),'trabajo.php?id='.(int)$r['id'],$saldo<=.01?'PAGADO':'$'.number_format($saldo,2));}
    }

    if($wantCliente || (!$iaActiva && count($out)<4)){$st=$pdo->prepare("SELECT id,nombre,telefono,email FROM clientes WHERE activo=1 AND (nombre LIKE ? OR telefono LIKE ? OR email LIKE ?) ORDER BY nombre LIMIT 10");$st->execute([$likeTerm,$likeTerm,$likeTerm]);foreach($st as $r)ai_add($out,'Cliente',$r['nombre'],$r['telefono'].($r['email']?' · '.$r['email']:''),'historial_cliente.php?telefono='.urlencode($r['telefono']),'CLIENTE');}
    if($wantGarant || (!$iaActiva && count($out)<3)){
        $genericGarant=$wantGarant && ($likeTerm==='%');
        $sqlG="SELECT g.id,g.cotizacion_id,g.concepto,g.fecha_fin,g.estado,c.folio,c.cliente FROM garantias g JOIN cotizaciones c ON c.id=g.cotizacion_id WHERE ".($genericGarant?"g.estado='Vigente'":"(g.concepto LIKE ? OR c.cliente LIKE ? OR c.folio LIKE ?)")." ORDER BY g.fecha_fin LIMIT 10";
        $st=$pdo->prepare($sqlG);$st->execute($genericGarant?[]:[$likeTerm,$likeTerm,$likeTerm]);foreach($st as $r)ai_add($out,'Garantía',$r['concepto'],$r['cliente'].' · vence '.edate($r['fecha_fin']).' · '.$r['estado'],'garantias.php?id='.(int)$r['cotizacion_id'],'GARANTÍA');
    }
    if($wantStock){
        if($likeTerm==='%'){$st=$pdo->query("SELECT id,nombre,sku,stock,stock_minimo,unidad FROM inventario WHERE activo=1 ORDER BY (stock<=stock_minimo) DESC,(stock-stock_minimo),nombre LIMIT 12");}
        else{$st=$pdo->prepare("SELECT id,nombre,sku,stock,stock_minimo,unidad FROM inventario WHERE activo=1 AND (nombre LIKE ? OR sku LIKE ?) ORDER BY (stock<=stock_minimo) DESC,nombre LIMIT 12");$st->execute([$likeTerm,$likeTerm]);}
        foreach($st as $r)ai_add($out,'Inventario',$r['nombre'],'Stock '.rtrim(rtrim(number_format((float)$r['stock'],3,'.',''),'0'),'.').' '.$r['unidad'].' · mínimo '.$r['stock_minimo'],'inventario.php',((float)$r['stock']<=(float)$r['stock_minimo'])?'STOCK BAJO':'STOCK');
    }
    if($wantCompra){
        if($likeTerm==='%'){$st=$pdo->query("SELECT co.id,co.numero_pedido,co.estatus,co.total,co.fecha_estimada,p.nombre proveedor,c.folio FROM compras co LEFT JOIN proveedores p ON p.id=co.proveedor_id LEFT JOIN cotizaciones c ON c.id=co.cotizacion_id WHERE co.estatus NOT IN ('Recibido','Cancelado') ORDER BY co.id DESC LIMIT 12");}
        else{$st=$pdo->prepare("SELECT co.id,co.numero_pedido,co.estatus,co.total,co.fecha_estimada,p.nombre proveedor,c.folio FROM compras co LEFT JOIN proveedores p ON p.id=co.proveedor_id LEFT JOIN cotizaciones c ON c.id=co.cotizacion_id WHERE p.nombre LIKE ? OR co.numero_pedido LIKE ? OR co.tracking LIKE ? OR c.folio LIKE ? ORDER BY co.id DESC LIMIT 12");$st->execute([$likeTerm,$likeTerm,$likeTerm,$likeTerm]);}
        foreach($st as $r)ai_add($out,'Compra',($r['numero_pedido']?:'Compra #'.$r['id']).' · '.($r['proveedor']?:'Proveedor'),$r['estatus'].' · $'.number_format((float)$r['total'],2).($r['fecha_estimada']?' · llegada '.edate($r['fecha_estimada']):''),'compras.php','PEDIDO');
    }
    if($wantCatalogo){
        // Catálogo/productos, útil cuando se pregunta por un servicio o producto.
        $st=$pdo->prepare("SELECT id,nombre,descripcion,precio_base,tipo_entrega,dias_entrega_estimada FROM catalogo_servicios WHERE activo=1 AND (nombre LIKE ? OR descripcion LIKE ?) ORDER BY nombre LIMIT 10");$st->execute([$likeTerm,$likeTerm]);foreach($st as $r)ai_add($out,'Catálogo',$r['nombre'],'$'.number_format((float)$r['precio_base'],2).' · '.srnu_tipo_entrega_label($r['tipo_entrega']).($r['dias_entrega_estimada']?' · '.$r['dias_entrega_estimada'].' días llegada':''),'catalogo.php?edit='.(int)$r['id'],'CATÁLOGO');
    }

    $motor=$iaActiva?'IA local Qwen + MySQL':'buscador local MySQL';
    $msg=count($out)?'Encontré '.count($out).' resultado(s) relacionados usando '.$motor.'. Tus datos permanecen en esta computadora.':'No encontré coincidencias. Prueba con nombre, teléfono, vehículo, folio, concepto, “stock”, “garantías” o “pedidos”.';
    foreach($out as &$x)unset($x['_key']);unset($x);
    echo json_encode(['ok'=>true,'mensaje'=>$msg,'resultados'=>$out,'ia'=>['activa'=>$iaActiva,'plan'=>$plan]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'mensaje'=>'No se pudo realizar la búsqueda local.','detalle'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
