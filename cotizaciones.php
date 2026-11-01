<?php
declare(strict_types=1);
require_once __DIR__ . '/config/erp.php';
$pdo = srnu_db();

$estados = ['Cotización','Aprobada','Anticipo recibido','Vehículo recibido','En proceso','Terminado','Entregado','Cancelado'];

// Liquidación rápida desde el listado. Registra el saldo como un pago real para
// que caja, dashboard, reportes y reparto trabajen con el mismo dato.
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['liquidar_rapido'])) {
    $id = (int)($_POST['id'] ?? 0);
    $metodo = trim((string)($_POST['metodo'] ?? 'Transferencia'));
    $destinatario = trim((string)($_POST['destinatario'] ?? 'General'));
    $entregar = !empty($_POST['marcar_entregado']);
    $metodosPermitidos = ['Transferencia','Efectivo','Tarjeta','Otro'];
    $destinatariosPermitidos = ['General','Sergio','Ricardo','Tomás','IVA'];
    if (!in_array($metodo, $metodosPermitidos, true)) $metodo = 'Transferencia';
    if (!in_array($destinatario, $destinatariosPermitidos, true)) $destinatario = 'General';

    $c = $id > 0 ? srnu_obtener_cotizacion($id) : null;
    if ($c) {
        $pagadoReal = srnu_total_pagado($id);

        // Si existía un anticipo histórico congelado pero aún no había sido
        // convertido a movimientos, lo importamos antes de liquidar. Esto evita
        // que el anticipo desaparezca del cálculo al registrar el pago final.
        if ($pagadoReal <= 0.009 && !empty($c['conservar_anticipo_recibido']) && (float)$c['anticipo_recibido_monto'] > 0.009) {
            $insAnt = $pdo->prepare('INSERT INTO pagos(cotizacion_id,fecha,metodo,monto,destinatario,notas,created_at) VALUES(?,?,?,?,?,?,NOW())');
            if (!empty($c['dividir_anticipo'])) {
                $partes = [
                    ['Sergio', (float)($c['anticipo_sergio'] ?? 0)],
                    ['Ricardo', (float)($c['anticipo_ricardo'] ?? 0)],
                    ['Tomás', (float)($c['anticipo_tomas'] ?? 0)],
                ];
                foreach ($partes as [$persona,$monto]) {
                    if ($monto > 0.009) $insAnt->execute([$id,$c['fecha'] ?: date('Y-m-d'),'Transferencia',$monto,$persona,'Importado desde anticipo ya recibido']);
                }
            } else {
                $insAnt->execute([$id,$c['fecha'] ?: date('Y-m-d'),'Transferencia',(float)$c['anticipo_recibido_monto'],'General','Importado desde anticipo ya recibido']);
            }
            $pagadoReal = srnu_total_pagado($id);
        }

        $saldo = round(max(0, (float)$c['total'] - $pagadoReal), 2);
        if ($saldo > 0.009) {
            $pdo->prepare('INSERT INTO pagos(cotizacion_id,fecha,metodo,monto,destinatario,notas,created_at) VALUES(?,?,?,?,?,?,NOW())')
                ->execute([$id,date('Y-m-d'),$metodo,$saldo,$destinatario,'Liquidación rápida desde cotizaciones']);
        }

        if ($entregar) {
            $pdo->prepare("UPDATE cotizaciones SET estado='Entregado', updated_at=NOW() WHERE id=?")->execute([$id]);
            srnu_consumir_inventario_cotizacion($id);
            srnu_generar_postventa_entrega($id);
        }
    }
    $qs = $_GET;
    $qs['liquidado'] = '1';
    header('Location: cotizaciones.php?'.http_build_query($qs)); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['actualizar_estado'])) {
    $id=(int)($_POST['id']??0); $estado=(string)($_POST['estado']??'Cotización');
    if ($id>0 && in_array($estado,$estados,true)) {
        if ($estado==='Aprobada') $pdo->prepare("UPDATE cotizaciones SET estado=?, aceptada_at=COALESCE(aceptada_at,NOW()), updated_at=NOW() WHERE id=?")->execute([$estado,$id]);
        else $pdo->prepare("UPDATE cotizaciones SET estado=?, updated_at=NOW() WHERE id=?")->execute([$estado,$id]);
        if (in_array($estado,['En proceso','Terminado','Entregado'],true)) srnu_consumir_inventario_cotizacion($id);
        if ($estado==='Entregado') srnu_generar_postventa_entrega($id);
    }
    header('Location: cotizaciones.php?'.http_build_query($_GET)); exit;
}

$q=trim((string)($_GET['q']??''));
$estadoFiltro=trim((string)($_GET['estado']??''));
$factura=trim((string)($_GET['factura']??''));
$responsable=trim((string)($_GET['responsable']??''));
$desde=trim((string)($_GET['desde']??''));
$hasta=trim((string)($_GET['hasta']??''));
$min=trim((string)($_GET['min']??''));
$max=trim((string)($_GET['max']??''));
$where=[];$params=[];
if($q!==''){ $where[]='(c.cliente LIKE ? OR c.telefono LIKE ? OR c.vehiculo LIKE ? OR c.folio LIKE ? OR EXISTS(SELECT 1 FROM recepciones r WHERE r.cotizacion_id=c.id AND r.placas LIKE ?) OR EXISTS(SELECT 1 FROM vehiculos v WHERE v.id=c.vehiculo_id AND (v.placas LIKE ? OR v.vin LIKE ?)))'; $like='%'.$q.'%'; array_push($params,$like,$like,$like,$like,$like,$like,$like); }
if(in_array($estadoFiltro,$estados,true)){ $where[]='c.estado=?'; $params[]=$estadoFiltro; }
if(in_array($factura,['si','no'],true)){ $where[]='c.requiere_factura=?'; $params[]=$factura; }
if(in_array($responsable,['sergio','ricardo','tomas'],true)){ $where[]="EXISTS(SELECT 1 FROM cotizacion_items ci WHERE ci.cotizacion_id=c.id AND ci.responsable_{$responsable}=1)"; }
if($desde!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$desde)){ $where[]='c.fecha>=?'; $params[]=$desde; }
if($hasta!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$hasta)){ $where[]='c.fecha<=?'; $params[]=$hasta; }
if($min!=='' && is_numeric($min)){ $where[]='c.total>=?'; $params[]=(float)$min; }
if($max!=='' && is_numeric($max)){ $where[]='c.total<=?'; $params[]=(float)$max; }
$sql="SELECT c.*, COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.cotizacion_id=c.id),0) AS pagado, (SELECT fd.estado FROM firmas_digitales fd WHERE fd.cotizacion_id=c.id LIMIT 1) AS firma_estado, (SELECT fd.firmado_at FROM firmas_digitales fd WHERE fd.cotizacion_id=c.id LIMIT 1) AS firma_firmado_at, (SELECT ft.estado FROM firmas_taller ft WHERE ft.cotizacion_id=c.id LIMIT 1) AS firma_taller_estado, (SELECT ft.firmado_at FROM firmas_taller ft WHERE ft.cotizacion_id=c.id LIMIT 1) AS firma_taller_firmado_at, (SELECT cl.estado FROM confirmaciones_locales cl WHERE cl.cotizacion_id=c.id LIMIT 1) AS confirmacion_estado, (SELECT cl.confirmado_at FROM confirmaciones_locales cl WHERE cl.cotizacion_id=c.id LIMIT 1) AS confirmacion_confirmado_at FROM cotizaciones c".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY c.id DESC";
$stmt=$pdo->prepare($sql);$stmt->execute($params);$cotizaciones=$stmt->fetchAll();
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cotizaciones · SRNU Custom</title><link rel="icon" href="favicon.png"><link rel="shortcut icon" href="favicon.ico">
<style>
*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;background:#f4f5f7;margin:0;color:#171717}.wrap{max-width:1480px;margin:26px auto;padding:0 18px}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.top h1{margin:0}.nav{display:flex;gap:8px;flex-wrap:wrap}a,.btn{display:inline-block;padding:9px 12px;border-radius:7px;text-decoration:none;border:1px solid #222;background:#111;color:#fff;cursor:pointer;font:inherit}.light{background:#fff;color:#111}.blue{background:#1769ff;border-color:#1769ff}.green{background:#0b6b3a;border-color:#0b6b3a}.orange{background:#9a5b00;border-color:#9a5b00}.purple{background:#7c3aed;border-color:#7c3aed}.danger{background:#9d1111;border-color:#9d1111}.filters{margin:18px 0;background:#fff;border:1px solid #e1e4e8;border-radius:12px;padding:14px;display:grid;grid-template-columns:2fr repeat(7,minmax(110px,1fr));gap:8px}.filters input,.filters select{width:100%;padding:9px;border:1px solid #ccc;border-radius:7px}.filters .buttons{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap}.tablebox{overflow:auto;border-radius:12px;box-shadow:0 4px 18px #00000010}table{width:100%;border-collapse:collapse;background:#fff;min-width:1350px}th,td{padding:10px;border-bottom:1px solid #e8e8e8;text-align:left;vertical-align:top}th{background:#111;color:#fff;position:sticky;top:0}.money{text-align:right;white-space:nowrap}.small{font-size:12px;color:#666}.tag{display:inline-block;padding:4px 7px;border-radius:12px;background:#eee;font-size:11px}.estado-form{display:flex;gap:5px}.estado-form select{max-width:150px;padding:5px;border:1px solid #bbb;border-radius:6px}.estado-form button{padding:5px 7px}.actions{min-width:340px}.actions a{font-size:11px;padding:6px 8px;margin:2px}.saldo{font-weight:700}.saldo.ok{color:#19763a}.saldo.pend{color:#a33}.pago-cell{min-width:112px}.pago-status{display:inline-flex;align-items:center;gap:7px;font-size:11px;font-weight:900;border-radius:999px;padding:6px 9px;margin-top:6px}.pago-status.pagado{background:#dcfce7;color:#166534}.pago-status.parcial{background:#ffedd5;color:#9a3412}.pago-status.pendiente{background:#fee2e2;color:#991b1b}.quick-paid{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:800;cursor:pointer}.quick-paid input{width:19px;height:19px;accent-color:#16a34a;cursor:pointer}.quick-paid input:disabled{cursor:default}.liq-modal{position:fixed;inset:0;background:rgba(2,8,23,.66);display:none;align-items:center;justify-content:center;padding:18px;z-index:9999;backdrop-filter:blur(7px)}.liq-modal.open{display:flex}.liq-card{width:min(520px,100%);background:#fff;border-radius:20px;padding:20px;box-shadow:0 25px 80px rgba(0,0,0,.28)}.liq-card h2{margin:0 0 5px}.liq-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:16px 0}.liq-card label{font-size:12px;font-weight:900;color:#475569}.liq-card select{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:10px;margin-top:6px}.liq-balance{font-size:28px;font-weight:900;margin:8px 0}.liq-deliver{display:flex;gap:9px;align-items:flex-start;background:#f8fafc;border:1px solid #e2e8f0;padding:12px;border-radius:12px}.liq-deliver input{margin-top:2px;width:18px;height:18px;accent-color:#16a34a}.liq-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}@media(max-width:900px){.filters{grid-template-columns:1fr 1fr}.filters .q{grid-column:1/-1}}@media(max-width:560px){.liq-grid{grid-template-columns:1fr}.liq-actions{flex-direction:column-reverse}.liq-actions .btn{width:100%;text-align:center}}html[data-theme="dark"] .liq-card{background:#0f172a;color:#e5edf8;border:1px solid #334155}html[data-theme="dark"] .liq-card h2{color:#f8fafc}html[data-theme="dark"] .liq-card label{color:#cbd5e1}html[data-theme="dark"] .liq-card select{background:#0b1220;color:#e5edf8;border-color:#334155}html[data-theme="dark"] .liq-deliver{background:#111827;border-color:#334155}

.ai-fab{position:fixed;right:22px;bottom:22px;z-index:9000;width:58px;height:58px;border-radius:18px;border:0;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;font-weight:1000;font-size:17px;box-shadow:0 18px 45px rgba(37,99,235,.35);cursor:pointer}.ai-panel{position:fixed;right:22px;bottom:92px;z-index:9001;width:min(430px,calc(100vw - 28px));height:min(650px,calc(100vh - 125px));background:#fff;border:1px solid #dbe4ef;border-radius:22px;box-shadow:0 30px 90px rgba(2,8,23,.3);display:none;flex-direction:column;overflow:hidden}.ai-panel.open{display:flex}.ai-head{padding:15px 16px;background:linear-gradient(135deg,#0f172a,#172554);color:#fff;display:flex;justify-content:space-between;gap:12px;align-items:center}.ai-head strong{display:block}.ai-head span{font-size:11px;color:#cbd5e1}.ai-close{border:0;background:#ffffff18;color:#fff;border-radius:10px;width:34px;height:34px;cursor:pointer}.ai-body{padding:14px;overflow:auto;flex:1;background:#f8fafc}.ai-msg{padding:10px 12px;border-radius:14px;background:#fff;border:1px solid #e2e8f0;font-size:12px;margin-bottom:10px}.ai-results{display:grid;gap:8px}.ai-result{display:block;background:#fff;border:1px solid #dbe4ef;border-radius:13px;padding:10px;text-decoration:none!important;color:#0f172a!important}.ai-result:hover{border-color:#93c5fd;box-shadow:0 6px 18px rgba(37,99,235,.10)}.ai-result .rtop{display:flex;justify-content:space-between;gap:8px}.ai-result b{font-size:12px}.ai-result small{display:block;color:#64748b;margin-top:4px;line-height:1.35}.ai-badge{font-size:9px;font-weight:900;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:4px 6px;white-space:nowrap}.ai-suggestions{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}.ai-chip{border:1px solid #cbd5e1;background:#fff;border-radius:999px;padding:6px 8px;font-size:10px;cursor:pointer}.ai-form{padding:12px;border-top:1px solid #e2e8f0;background:#fff;display:flex;gap:8px}.ai-form input{flex:1;min-width:0;padding:11px;border:1px solid #cbd5e1;border-radius:12px}.ai-form button{border:0;border-radius:12px;background:#2563eb;color:#fff;padding:0 14px;font-weight:900;cursor:pointer}html[data-theme="dark"] .ai-panel,html[data-theme="dark"] .ai-body,html[data-theme="dark"] .ai-form,html[data-theme="dark"] .ai-msg,html[data-theme="dark"] .ai-result,html[data-theme="dark"] .ai-chip{background:#0f172a;color:#e5edf8;border-color:#334155}html[data-theme="dark"] .ai-result{color:#e5edf8!important}html[data-theme="dark"] .ai-result small{color:#94a3b8}@media(max-width:600px){.ai-fab{right:12px;bottom:12px}.ai-panel{right:8px;bottom:78px;width:calc(100vw - 16px);height:72vh}}
</style><link rel="stylesheet" href="assets/app.css"><script defer src="assets/app.js"></script></head><body><?php srnu_appbar('cotizaciones'); ?><div class="wrap">
<div class="top"><div><h1>SRNU CUSTOM</h1><div class="small">Cotizaciones · administración y operación</div></div><div class="nav"><a href="dashboard.php">Dashboard</a><a href="catalogo.php" class="light">Catálogo</a><a href="importar_pdf.php" class="purple">↥ Importar PDF</a><a href="cotizador_rapido.php" class="orange">⚡ Rápida</a><a href="cotizador.php" class="blue">+ Nueva cotización</a></div></div>
<?php if(($_GET['importado']??'')==='1'):?><div class="srnu-ok" style="margin-top:14px">Cotización recuperada desde PDF. Ya puedes editarla normalmente.</div><?php endif;?>
<?php if(($_GET['liquidado']??'')==='1'):?><div class="srnu-ok" style="margin-top:14px">Pago final registrado. La cotización ya refleja el nuevo saldo.</div><?php endif;?>
<form class="filters" method="get">
<input class="q" name="q" value="<?=h($q)?>" placeholder="Cliente, teléfono, vehículo, placas, VIN o folio">
<select name="estado"><option value="">Todos los estados</option><?php foreach($estados as $e):?><option <?= $estadoFiltro===$e?'selected':''?>><?=h($e)?></option><?php endforeach;?></select>
<select name="factura"><option value="">Factura / sin factura</option><option value="si" <?=$factura==='si'?'selected':''?>>Con factura</option><option value="no" <?=$factura==='no'?'selected':''?>>Sin factura</option></select>
<select name="responsable"><option value="">Cualquier responsable</option><option value="sergio" <?=$responsable==='sergio'?'selected':''?>>Sergio</option><option value="ricardo" <?=$responsable==='ricardo'?'selected':''?>>Ricardo</option><option value="tomas" <?=$responsable==='tomas'?'selected':''?>>Tomás</option></select>
<input type="date" name="desde" value="<?=h($desde)?>" title="Desde"><input type="date" name="hasta" value="<?=h($hasta)?>" title="Hasta">
<input type="number" step="0.01" name="min" value="<?=h($min)?>" placeholder="Total mínimo"><input type="number" step="0.01" name="max" value="<?=h($max)?>" placeholder="Total máximo">
<div class="buttons"><button class="btn" type="submit">Filtrar</button><a class="btn light" href="cotizaciones.php">Limpiar</a><span class="small" style="align-self:center"><?=count($cotizaciones)?> resultado(s)</span></div>
</form>
<?php if(!$cotizaciones):?><div style="background:#fff;padding:30px;text-align:center;border-radius:10px">No hay cotizaciones con esos filtros.</div><?php else:?><div class="tablebox"><table><thead><tr><th>Folio / fecha</th><th>Cliente</th><th>Vehículo</th><th>Estado</th><th>Pago</th><th>Factura</th><th>Total</th><th>Pagado</th><th>Saldo</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($cotizaciones as $c): $pag=(float)$c['pagado']; if(!empty($c['conservar_anticipo_recibido']) && $pag<=0) $pag=(float)$c['anticipo_recibido_monto']; $saldo=max(0,(float)$c['total']-$pag); ?>
<tr><td><strong><?=h($c['folio'])?></strong><?php if(!empty($c['importado_desde_pdf'])):?><div><span class="tag">RECUPERADA PDF</span></div><?php endif;?><div class="small"><?=h($c['fecha'])?> · vigencia <?= (int)($c['vigencia_dias']??15)?> días</div></td>
<td><?=h($c['cliente'])?><div class="small"><?=h($c['telefono'])?></div></td><td><?=h($c['vehiculo'])?></td>
<td><form class="estado-form" method="post"><input type="hidden" name="id" value="<?=(int)$c['id']?>"><select name="estado"><?php foreach($estados as $e):?><option <?=$c['estado']===$e?'selected':''?>><?=h($e)?></option><?php endforeach;?></select><button class="btn" name="actualizar_estado" value="1">✓</button></form><?php if(!empty($c['aceptada_at'])):?><div class="small">Aceptada: <?=h($c['aceptada_at'])?></div><?php endif;?></td>
<td class="pago-cell"><?php $pagoEstado=$saldo<=0.009?'pagado':($pag>0.009?'parcial':'pendiente'); ?><label class="quick-paid"><input type="checkbox" <?=$pagoEstado==='pagado'?'checked disabled':''?> <?php if($pagoEstado!=='pagado'):?>class="js-liquidar" data-id="<?=(int)$c['id']?>" data-cliente="<?=h($c['cliente'])?>" data-vehiculo="<?=h($c['vehiculo'])?>" data-saldo="<?=number_format($saldo,2,'.','')?>" data-estado="<?=h((string)$c['estado'])?>"<?php endif;?>> <span>Pagado</span></label><div class="pago-status <?=$pagoEstado?>"><?=$pagoEstado==='pagado'?'PAGADO':($pagoEstado==='parcial'?'PARCIAL':'PENDIENTE')?></div></td>
<td><span class="tag"><?=$c['requiere_factura']==='si'?'Con factura':'Sin factura'?></span></td><td class="money"><strong>$<?=number_format((float)$c['total'],2)?></strong></td><td class="money">$<?=number_format($pag,2)?></td><td class="money saldo <?=$saldo<=0.009?'ok':'pend'?>">$<?=number_format($saldo,2)?></td>
<td class="actions"><a class="blue" href="trabajo.php?id=<?=(int)$c['id']?>">Panel</a><a href="cotizador.php?id=<?=(int)$c['id']?>">Editar</a><a class="<?=($c['firma_estado']??'')==='firmada'?'green':'purple'?>" href="firma_digital.php?id=<?=(int)$c['id']?>"><?=($c['firma_estado']??'')==='firmada'?'Firma cliente ✓':'Firma cliente'?></a><a class="<?=($c['firma_taller_estado']??'')==='firmada'?'green':'purple'?>" href="firma_taller.php?id=<?=(int)$c['id']?>"><?=($c['firma_taller_estado']??'')==='firmada'?'Firma taller ✓':'Firma taller'?></a><a class="<?=($c['confirmacion_estado']??'')==='confirmada'?'green':'purple'?>" href="confirmacion_local.php?id=<?=(int)$c['id']?>"><?=($c['confirmacion_estado']??'')==='confirmada'?'Confirmación ✓':'Confirmación local'?></a><?php if(($c['estado']??'Cotización')==='Cotización'):?><a class="green" href="aceptar_cotizacion.php?id=<?=(int)$c['id']?>">Marcar aceptada</a><?php endif;?><a class="blue" target="_blank" href="ver_pdf.php?id=<?=(int)$c['id']?>&tipo=<?=$c['requiere_factura']==='si'?'factura':'sin_factura'?>">Ver PDF</a><a href="generar_pdf.php?id=<?=(int)$c['id']?>&tipo=<?=$c['requiere_factura']==='si'?'factura':'sin_factura'?>&accion=descargar">Descargar</a><a class="green" target="_blank" href="imprimir_pdf.php?id=<?=(int)$c['id']?>&tipo=<?=$c['requiere_factura']==='si'?'factura':'sin_factura'?>">Imprimir</a><a class="orange" href="pagos.php?id=<?=(int)$c['id']?>">Pagos</a><a href="versiones.php?id=<?=(int)$c['id']?>">Versiones</a><a href="orden_trabajo.php?id=<?=(int)$c['id']?>">Orden</a><a href="recepcion.php?id=<?=(int)$c['id']?>">Recepción</a><a href="garantias.php?id=<?=(int)$c['id']?>">Garantías</a><a href="historial_cliente.php?telefono=<?=urlencode((string)$c['telefono'])?>">Historial cliente</a><a class="green" target="_blank" href="whatsapp.php?id=<?=(int)$c['id']?>">WhatsApp</a><a href="agenda.php?cotizacion_id=<?=(int)$c['id']?>">Agenda</a><a href="expediente.php?id=<?=(int)$c['id']?>">Expediente</a><a href="costos.php?id=<?=(int)$c['id']?>">Costos reales</a><a href="reparto.php?id=<?=(int)$c['id']?>">Reparto</a><a href="galeria.php?id=<?=(int)$c['id']?>">Galería</a><a href="notas.php?id=<?=(int)$c['id']?>">Notas/archivos</a><a href="duplicar_cotizacion.php?id=<?=(int)$c['id']?>" onclick="return confirm('¿Duplicar esta cotización?')">Duplicar</a><a href="comparar_versiones.php?id=<?=(int)$c['id']?>">Comparar</a><?php if(!empty($c['pdf_origen_archivo'])):?><a class="purple" target="_blank" href="<?=h($c['pdf_origen_archivo'])?>">PDF origen</a><?php endif;?><a class="danger" href="eliminar_cotizacion.php?id=<?=(int)$c['id']?>" onclick="return confirm('¿Eliminar <?=h($c['folio'])?>?')">Eliminar</a></td></tr>
<?php endforeach;?></tbody></table></div><?php endif;?></div>
<button type="button" class="ai-fab" id="ai-fab" title="Asistente inteligente SRNU">IA</button>
<div class="ai-panel" id="ai-panel" aria-hidden="true">
  <div class="ai-head"><div><strong>IA SRNU · Qwen local</strong><span>Entiende preguntas naturales y busca en clientes, cotizaciones, productos, pagos, garantías, inventario y compras</span></div><button type="button" class="ai-close" id="ai-close">✕</button></div>
  <div class="ai-body" id="ai-body"><div class="ai-msg">Pregúntame en lenguaje normal. Si Qwen está activo interpreta tu intención; tus datos permanecen en esta computadora.</div><div class="ai-suggestions"><button class="ai-chip">cotizaciones pendientes</button><button class="ai-chip">garantías por vencer</button><button class="ai-chip">stock bajo</button><button class="ai-chip">pedidos pendientes</button></div><div class="ai-results" id="ai-results"></div></div>
  <form class="ai-form" id="ai-form"><input id="ai-q" autocomplete="off" placeholder="Ej. trabajos de Tacoma, teléfono de Juan..."><button>Buscar</button></form>
</div>
<div class="liq-modal" id="liq-modal" aria-hidden="true"><div class="liq-card"><h2>Liquidar cotización</h2><div class="small" id="liq-desc"></div><div class="liq-balance" id="liq-balance">$0.00</div><form method="post" id="liq-form"><input type="hidden" name="id" id="liq-id"><input type="hidden" name="liquidar_rapido" value="1"><div class="liq-grid"><label>Método de pago<select name="metodo"><option>Transferencia</option><option>Efectivo</option><option>Tarjeta</option><option>Otro</option></select></label><label>Registrar para<select name="destinatario"><option>General</option><option>Sergio</option><option>Ricardo</option><option>Tomás</option><option>IVA</option></select></label></div><label class="liq-deliver"><input type="checkbox" name="marcar_entregado" id="liq-entregado" value="1"><span><strong>Marcar vehículo como Entregado al liquidar</strong><br><span class="small">También activa las automatizaciones de entrega, garantías y postventa.</span></span></label><div class="liq-actions"><button type="button" class="btn light" id="liq-cancel">Cancelar</button><button type="submit" class="btn green">Registrar pago final</button></div></form></div></div>
<script>
(function(){
 const modal=document.getElementById('liq-modal'), id=document.getElementById('liq-id'), desc=document.getElementById('liq-desc'), bal=document.getElementById('liq-balance'), entregar=document.getElementById('liq-entregado');
 function closeModal(){modal.classList.remove('open');modal.setAttribute('aria-hidden','true');document.querySelectorAll('.js-liquidar').forEach(x=>{if(!x.disabled)x.checked=false;});}
 document.querySelectorAll('.js-liquidar').forEach(cb=>cb.addEventListener('change',function(){
   if(!this.checked)return;
   id.value=this.dataset.id||'';
   desc.textContent=(this.dataset.cliente||'Cliente')+' · '+(this.dataset.vehiculo||'Vehículo')+' · saldo restante';
   const n=Number(this.dataset.saldo||0); bal.textContent=n.toLocaleString('es-MX',{style:'currency',currency:'MXN'});
   entregar.checked=(this.dataset.estado==='Terminado');
   modal.classList.add('open');modal.setAttribute('aria-hidden','false');
 }));
 document.getElementById('liq-cancel').addEventListener('click',closeModal);
 modal.addEventListener('click',e=>{if(e.target===modal)closeModal();});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('open'))closeModal();});
})();
</script>
<script>
(function(){
 const fab=document.getElementById('ai-fab'),panel=document.getElementById('ai-panel'),close=document.getElementById('ai-close'),form=document.getElementById('ai-form'),q=document.getElementById('ai-q'),results=document.getElementById('ai-results'),body=document.getElementById('ai-body');
 if(!fab)return; function open(){panel.classList.add('open');panel.setAttribute('aria-hidden','false');setTimeout(()=>q.focus(),80)} function shut(){panel.classList.remove('open');panel.setAttribute('aria-hidden','true')}
 fab.onclick=open; close.onclick=shut; document.querySelectorAll('.ai-chip').forEach(c=>c.onclick=()=>{q.value=c.textContent.trim();open();search()});
 function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
 async function search(){const term=q.value.trim();if(!term)return; results.innerHTML='<div class="ai-msg">Buscando en SRNU...</div>'; try{const fd=new FormData();fd.append('q',term);const r=await fetch('asistente_ia.php',{method:'POST',body:fd});const d=await r.json();let html='<div class="ai-msg">'+esc(d.mensaje||'')+'</div>'; if(d.resultados&&d.resultados.length){html+='<div class="ai-results">'+d.resultados.map(x=>'<a class="ai-result" href="'+esc(x.url)+'"><div class="rtop"><b>'+esc(x.titulo)+'</b><span class="ai-badge">'+esc(x.badge||x.tipo)+'</span></div><small>'+esc(x.subtitulo)+'</small></a>').join('')+'</div>';}results.innerHTML=html;}catch(e){results.innerHTML='<div class="ai-msg">No pude consultar la base local. Revisa que Apache y MySQL estén activos.</div>';}}
 form.addEventListener('submit',e=>{e.preventDefault();search()}); document.addEventListener('keydown',e=>{if(e.key==='Escape'&&panel.classList.contains('open'))shut();});
})();
</script>
</body></html>
