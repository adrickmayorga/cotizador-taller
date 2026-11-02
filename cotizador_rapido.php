<?php
declare(strict_types=1);
require_once __DIR__ . '/config/erp.php';
require_once __DIR__ . '/config/bbva_pyme.php';

$pdo = srnu_db();
$catalogo = srnu_catalogo_activo();
$error = '';

function qr_num(mixed $v): float { return max(0, (float)$v); }
function qr_items_desde_post(): array {
    $conceptos = $_POST['concepto'] ?? [];
    $cantidades = $_POST['cantidad'] ?? [];
    $costos = $_POST['costo_interno'] ?? [];
    $precios = $_POST['precio'] ?? [];
    $catalogos = $_POST['catalogo_id'] ?? [];
    $items = [];
    $n = max(count($conceptos), count($cantidades), count($costos), count($precios));
    for ($i=0; $i<$n; $i++) {
        $concepto = trim((string)($conceptos[$i] ?? ''));
        $cantidad = qr_num($cantidades[$i] ?? 0);
        $costo = qr_num($costos[$i] ?? 0);
        $precio = qr_num($precios[$i] ?? 0);
        if ($concepto === '' || $cantidad <= 0) continue;
        $catId = max(0, (int)($catalogos[$i] ?? 0));
        $meta = [];
        if ($catId > 0) {
            try {
                $st = srnu_db()->prepare('SELECT responsable_sergio,responsable_ricardo,responsable_tomas,garantia_meses,tipo_entrega,dias_entrega_estimada,dias_trabajo_min,dias_trabajo_max,foto_producto FROM catalogo_servicios WHERE id=? LIMIT 1');
                $st->execute([$catId]);
                $meta = $st->fetch() ?: [];
            } catch (Throwable $e) { $meta = []; }
        }
        $items[] = [
            'concepto'=>$concepto,
            'cantidad'=>$cantidad,
            'costo_interno'=>$costo,
            'precio'=>$precio,
            'total'=>round($cantidad*$precio,2),
            'catalogo_id'=>$catId ?: null,
            'responsable_sergio'=>!empty($meta['responsable_sergio']) ? 1 : 0,
            'responsable_ricardo'=>!empty($meta['responsable_ricardo']) ? 1 : 0,
            'responsable_tomas'=>!empty($meta['responsable_tomas']) ? 1 : 0,
            'garantia_meses'=>max(0,(int)($meta['garantia_meses'] ?? 0)),
            'tipo_entrega'=>in_array(($meta['tipo_entrega'] ?? 'inmediata'),['inmediata','sobre_pedido'],true) ? ($meta['tipo_entrega'] ?? 'inmediata') : 'inmediata',
            'dias_entrega_estimada'=>max(0,(int)($meta['dias_entrega_estimada'] ?? 0)),
            'dias_trabajo_min'=>max(0,(int)($meta['dias_trabajo_min'] ?? 0)),
            'dias_trabajo_max'=>max(0,(int)($meta['dias_trabajo_max'] ?? 0)),
            'foto_producto'=>srnu_guardar_foto_producto_upload(srnu_archivo_upload_indice('foto_producto', $i), srnu_catalogo_foto_segura($meta['foto_producto'] ?? $_POST['foto_producto_actual'][$i] ?? null)),
            'mostrar_foto_pdf'=>1,
        ];
    }
    return $items;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    $items = qr_items_desde_post();
    $vehiculo = trim((string)($_POST['vehiculo'] ?? ''));
    $referencia = trim((string)($_POST['referencia'] ?? ''));
    $factura = (($_POST['requiere_factura'] ?? '0') === '1');
    $subtotal = round(array_sum(array_column($items,'total')),2);
    // BBVA PyME es una casilla manual; al marcarla la factura se activa sola.
    $bbvaRapido = (($_POST['bbva_pyme'] ?? '0') === '1');
    if ($bbvaRapido) $factura = true;
    $iva = $factura ? round($subtotal*0.16,2) : 0.0;
    $total = round($subtotal+$iva,2);

    if (!$items) {
        $error = 'Agrega al menos un concepto antes de continuar.';
    } elseif ($accion === 'formal') {
        $_SESSION['srnu_cotizacion_rapida'] = [
            'vehiculo'=>$vehiculo,
            'requiere_factura'=>$factura ? 'si' : 'no',
            'guardar_articulos_catalogo'=>($_POST['guardar_articulos_catalogo'] ?? '0') === '1',
            'msi_plazos'=>array_merge(srnu_tarjeta_plazos_disponibles($subtotal, $factura ? 'si' : 'no', $bbvaRapido), ['tarjeta_1x'], $bbvaRapido ? ['bbva_pyme'] : []),
            'items'=>$items,
        ];
        header('Location: cotizador.php?desde_rapido=1');
        exit;
    } elseif ($accion === 'guardar') {
        try {
            $nombre = $referencia !== '' ? ('Rápida · '.$referencia) : 'Cotización rápida';
            $id = srnu_guardar_cotizacion([
                'cliente'=>$nombre,
                'telefono'=>'',
                'cliente_id'=>0,
                'vehiculo'=>$vehiculo,
                'fecha_raw'=>date('Y-m-d'),
                'requiere_factura'=>$factura ? 'si' : 'no',
                'subtotal'=>$subtotal,
                'iva'=>$iva,
                'total'=>$total,
                'msi_plazos'=>array_merge(srnu_tarjeta_plazos_disponibles($subtotal, $factura ? 'si' : 'no', $bbvaRapido), ['tarjeta_1x'], $bbvaRapido ? ['bbva_pyme'] : []),
                'anticipo_tipo'=>'porcentaje',
                'anticipo_valor'=>70,
                'conservar_anticipo_recibido'=>0,
                'anticipo_recibido_monto'=>0,
                'dividir_anticipo'=>0,
                'repartir_igual'=>0,
                'anticipo_sergio'=>0,
                'anticipo_ricardo'=>0,
                'anticipo_tomas'=>0,
                'participa_sergio'=>1,
                'participa_ricardo'=>1,
                'participa_tomas'=>0,
                'estado'=>'Cotización',
                'vigencia_dias'=>15,
                'dias_trabajo_general_min'=>0,
                'dias_trabajo_general_max'=>0,
                'motivo_version'=>'Cotización rápida',
                'guardar_articulos_catalogo'=>($_POST['guardar_articulos_catalogo'] ?? '0') === '1' ? 1 : 0,
            ], $items, 0);
            header('Location: cotizador.php?id='.$id.'&rapido_guardado=1');
            exit;
        } catch (Throwable $e) {
            $error = 'No se pudo guardar la cotización rápida: '.$e->getMessage();
        }
    }
}

$prevReferencia = trim((string)($_POST['referencia'] ?? ''));
$prevVehiculo = trim((string)($_POST['vehiculo'] ?? ''));
$prevFactura = isset($factura) ? $factura : (($_POST['requiere_factura'] ?? '0') === '1');
$prevBBVA = isset($bbvaRapido) ? $bbvaRapido : (($_POST['bbva_pyme'] ?? '0') === '1');
$prevItems = ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '') ? ($items ?? []) : [];

$catalogoJson = array_map(static function(array $r): array {
    return [
        'id'=>(int)$r['id'],
        'nombre'=>(string)$r['nombre'],
        'descripcion'=>(string)($r['descripcion'] ?? ''),
        'foto_producto'=>(string)($r['foto_producto'] ?? ''),
        'precio'=>(float)$r['precio_base'],
        'costo'=>(float)$r['costo_base'],
        'categoria'=>(string)($r['categoria'] ?? ''),
        'fijo'=>!empty($r['precio_fijo']),
    ];
}, $catalogo);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cotizador rápido · SRNU CUSTOM</title>
<link rel="icon" href="favicon.png">
<?=srnu_ui_head()?>
<style>
.qr-wrap{max-width:1500px;margin:0 auto;padding:22px 18px 70px}.qr-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.qr-head h1{margin:0;font-size:30px}.qr-sub{color:var(--muted,#64748b);margin-top:5px}.qr-badge{display:inline-flex;align-items:center;gap:7px;border-radius:999px;padding:8px 12px;font-weight:800;font-size:12px;background:rgba(245,158,11,.14);border:1px solid rgba(245,158,11,.3);color:#f59e0b}.qr-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:18px}.qr-panel{background:var(--panel,#fff);border:1px solid var(--border,#e2e8f0);border-radius:18px;box-shadow:0 12px 34px rgba(15,23,42,.07);overflow:hidden}.qr-panel-pad{padding:18px}.qr-topfields{display:grid;grid-template-columns:1fr 1fr auto auto;gap:12px;align-items:end}.qr-topfields label,.qr-field label{display:block;font-size:12px;font-weight:800;margin-bottom:6px;color:var(--muted,#64748b)}.qr-topfields input,.qr-topfields select,.qr-row input,.qr-row select{width:100%;box-sizing:border-box;border:1px solid var(--border,#cbd5e1);border-radius:10px;padding:10px 11px;background:var(--input,#fff);color:inherit}.qr-catalog{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;margin-top:14px}.qr-catalog select{width:100%;border:1px solid var(--border,#cbd5e1);border-radius:10px;padding:11px;background:var(--input,#fff);color:inherit}.qr-table-wrap{overflow:auto;margin-top:16px}.qr-table{width:100%;border-collapse:separate;border-spacing:0 8px;min-width:900px}.qr-table th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted,#64748b);text-align:left;padding:0 8px}.qr-row td{padding:4px 6px;vertical-align:middle}.qr-row{background:rgba(148,163,184,.06)}.qr-row td:first-child{border-radius:12px 0 0 12px}.qr-row td:last-child{border-radius:0 12px 12px 0}.qr-money,.qr-num{text-align:right}.qr-result{font-weight:800;white-space:nowrap}.qr-margin.good{color:#16a34a}.qr-margin.low{color:#f59e0b}.qr-margin.bad{color:#dc2626}.qr-remove{border:0;background:rgba(239,68,68,.10);color:#ef4444;border-radius:9px;width:34px;height:34px;cursor:pointer;font-weight:900}.qr-add{margin-top:12px}.qr-summary{position:sticky;top:82px}.qr-summary h3{margin:0 0 14px}.qr-kpi{display:flex;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px dashed var(--border,#e2e8f0)}.qr-kpi span{color:var(--muted,#64748b)}.qr-kpi strong{font-size:16px;text-align:right}.qr-kpi.total{border-top:2px solid var(--border,#e2e8f0);border-bottom:0;margin-top:4px;padding-top:15px}.qr-kpi.total strong{font-size:25px}.qr-profit{padding:14px;border-radius:14px;background:rgba(34,197,94,.08);margin-top:12px}.qr-profit strong{font-size:24px;display:block;margin-top:4px}.qr-actions{display:grid;gap:9px;margin-top:15px}.qr-actions button,.qr-actions a,.qr-catalog button,.qr-add{display:inline-flex;align-items:center;justify-content:center;gap:7px;border-radius:10px;border:1px solid var(--border,#cbd5e1);padding:11px 13px;font-weight:800;cursor:pointer;text-decoration:none}.qr-primary{background:#2563eb!important;color:#fff!important;border-color:#2563eb!important}.qr-green{background:#16a34a!important;color:#fff!important;border-color:#16a34a!important}.qr-secondary{background:transparent;color:inherit}.qr-note{font-size:12px;line-height:1.5;color:var(--muted,#64748b);margin-top:12px}.qr-error{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.3);color:#ef4444;padding:12px 14px;border-radius:12px;margin-bottom:14px;font-weight:700}.qr-switch{display:flex;align-items:center;gap:8px;height:42px;padding:0 12px;border:1px solid var(--border,#cbd5e1);border-radius:10px;white-space:nowrap}.qr-switch input{width:auto}.qr-empty{padding:22px;text-align:center;color:var(--muted,#64748b)}
.qr-msi{margin-top:14px;padding-top:14px;border-top:1px solid var(--border,#e2e8f0)}.qr-msi-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:10px}.qr-msi-head h4{margin:0;font-size:15px}.qr-msi-head small{display:block;color:var(--muted,#64748b);font-weight:600;margin-top:2px}.qr-msi-badge{font-size:10px;font-weight:900;border-radius:999px;padding:5px 8px;background:rgba(37,99,235,.10);color:#2563eb;white-space:nowrap}.qr-msi-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.qr-msi-card{border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:10px;background:rgba(148,163,184,.05)}.qr-msi-card strong{display:block;font-size:13px}.qr-msi-total{font-size:15px;font-weight:900;margin-top:4px}.qr-msi-monthly{font-size:12px;color:var(--muted,#64748b);margin-top:2px}.qr-msi-card.unavailable{opacity:.58}.qr-msi-card.unavailable .qr-msi-total{color:#dc2626}.qr-msi-status{font-size:10px;font-weight:900;margin-top:5px}.qr-msi-status.ok{color:#16a34a}.qr-msi-status.no{color:#dc2626}.qr-msi-note{font-size:11px;line-height:1.45;color:var(--muted,#64748b);margin-top:8px}.qr-bbva-box{margin-top:12px;padding:11px;border-radius:13px;background:rgba(37,99,235,.06);border:1px solid rgba(37,99,235,.18)}.qr-bbva-box[hidden],.qr-msi-grid[hidden],.qr-msi-note[hidden]{display:none!important}.qr-bbva-box .qr-msi-grid{margin-top:9px}
@media(max-width:980px){.qr-grid{grid-template-columns:1fr}.qr-summary{position:static}.qr-topfields{grid-template-columns:1fr}.qr-head{flex-direction:column}.qr-wrap{padding:14px 10px 60px}}
@media print{.srnu-appbar,.qr-head .qr-badge,.qr-catalog,.qr-add,.qr-remove,.qr-actions,.qr-note{display:none!important}.qr-wrap{padding:0}.qr-grid{grid-template-columns:1fr}.qr-panel{box-shadow:none;border:0}.qr-summary{position:static}.qr-table{min-width:0}.qr-table input{border:0;padding:0}.qr-topfields input{border:0;padding:0}.qr-summary{break-inside:avoid}}
</style>
</head>
<body>
<?php srnu_appbar('rapido'); ?>
<main class="w qr-wrap">
    <div class="qr-head">
        <div><h1>⚡ Cotizador rápido</h1><div class="qr-sub">Prueba costos y precios sin llenar datos del cliente. Nada se guarda en MySQL hasta que tú lo decidas.</div></div>
        <div class="qr-badge">● MODO PRUEBA · NO GUARDADO</div>
    </div>
    <?php if($error!==''): ?><div class="qr-error"><?=eh($error)?></div><?php endif; ?>
    <form method="post" id="qr-form" autocomplete="off" enctype="multipart/form-data">
    <div class="qr-grid">
        <section class="qr-panel">
            <div class="qr-panel-pad">
                <div class="qr-topfields">
                    <div><label>Referencia opcional</label><input name="referencia" placeholder="Ej. Tacoma blanca / WhatsApp" value="<?=eh($prevReferencia)?>"></div>
                    <div><label>Vehículo opcional</label><input name="vehiculo" id="qr-vehiculo" placeholder="Ej. Toyota Tacoma 2025" value="<?=eh($prevVehiculo)?>"></div>
                    <label class="qr-switch"><input type="checkbox" name="requiere_factura" id="qr-factura" value="1" <?=$prevFactura?'checked':''?>> <span id="qr-factura-label">Factura + IVA</span></label>
                    <input type="hidden" name="requiere_factura" id="qr-factura-forzada" value="1" disabled>
                    <label class="qr-switch"><input type="checkbox" name="bbva_pyme" id="qr-bbva" value="1" <?=$prevBBVA?'checked':''?>> <span>BBVA PyME</span></label>
                </div>
                <div class="qr-catalog">
                    <select id="qr-catalogo"><option value="">Añadir desde catálogo SRNU…</option><?php foreach($catalogo as $c): ?><option value="<?=(int)$c['id']?>"><?=eh(($c['categoria']??'') ? $c['categoria'].' · '.$c['nombre'] : $c['nombre'])?> · <?=emoney($c['precio_base'])?></option><?php endforeach;?></select>
                    <button type="button" class="qr-primary" id="qr-add-catalog">＋ Añadir</button>
                </div>
                <div class="qr-table-wrap">
                    <table class="qr-table">
                        <thead><tr><th style="min-width:280px">Concepto</th><th style="width:80px">Cant.</th><th style="width:125px">Costo/u</th><th style="width:125px">Venta/u</th><th style="width:160px">Foto</th><th style="width:125px">Venta total</th><th style="width:115px">Utilidad</th><th style="width:90px">Margen</th><th></th></tr></thead>
                        <tbody id="qr-body"></tbody>
                    </table>
                </div>
                <button type="button" class="qr-add qr-secondary" id="qr-add-row">＋ Agregar concepto manual</button>
                <input type="hidden" name="guardar_articulos_catalogo" value="0">
                <label style="display:flex;gap:8px;margin-top:12px;align-items:flex-start;"><input type="checkbox" name="guardar_articulos_catalogo" value="1" <?=($_POST['guardar_articulos_catalogo'] ?? '1') === '1' ? 'checked' : ''?>> <span>Guardar artículos nuevos con su foto en el catálogo al guardar la cotización.</span></label>
            </div>
        </section>
        <aside class="qr-panel qr-summary">
            <div class="qr-panel-pad">
                <h3>Resumen instantáneo</h3>
                <div class="qr-kpi"><span>Costo interno total</span><strong id="s-costo">$0.00</strong></div>
                <div class="qr-kpi"><span>Venta antes de IVA</span><strong id="s-subtotal">$0.00</strong></div>
                <div class="qr-kpi"><span>IVA</span><strong id="s-iva">$0.00</strong></div>
                <div class="qr-kpi total"><span>Total cliente</span><strong id="s-total">$0.00</strong></div>
                <div class="qr-profit"><span>Utilidad bruta estimada</span><strong id="s-utilidad">$0.00</strong><div id="s-margen">Margen: 0.0%</div></div>

                <div class="qr-msi" id="qr-msi">
                    <div class="qr-msi-head">
                        <div><h4>💳 Pago con tarjeta</h4><small>Una exhibición siempre; MSI disponibles calculados automáticamente.</small></div>
                        <span class="qr-msi-badge" id="qr-msi-mode">CLIP</span>
                    </div>
                    <div class="qr-msi-note" id="qr-clip-una"></div>
                    <div class="qr-msi-grid" id="qr-clip-grid"></div>
                    <div class="qr-msi-note" id="qr-clip-note">El límite de Clip se valida sobre el cobro final con comisión incluida: máximo $50,000.</div>

                    <div class="qr-bbva-box" id="qr-bbva-box" hidden>
                        <div class="qr-msi-head" style="margin-bottom:0">
                            <div><h4>BBVA PyME</h4><small>Cobro final mayor a $50,000, con IVA y comisión · factura obligatoria.</small></div>
                            <span class="qr-msi-badge">BBVA</span>
                        </div>
                        <div class="qr-msi-note" id="qr-bbva-una"></div>
                        <div class="qr-msi-grid" id="qr-bbva-grid"></div>
                    </div>
                </div>

                <div class="qr-actions">
                    <button type="button" class="qr-secondary" id="qr-copy">⧉ Copiar resumen</button>
                    <button type="button" class="qr-secondary" onclick="window.print()">🖨 Imprimir prueba</button>
                    <button type="submit" name="accion" value="formal" class="qr-primary">→ Pasar al cotizador completo</button>
                    <button type="submit" name="accion" value="guardar" class="qr-green" onclick="return confirm('¿Guardar esta prueba en la base de datos como cotización?')">💾 Guardar en base de datos</button>
                    <button type="button" class="qr-secondary" id="qr-clear">Limpiar todo</button>
                </div>
                <p class="qr-note"><b>Siempre se incluye tarjeta a una exhibición y se calculan los MSI disponibles.</b> Clip se conserva; sus MSI superiores a $50,000 se ocultan. BBVA PyME muestra solo operaciones cuyo cobro final, con IVA y comisión, supera $50,000 y requiere factura. <b>Pasar al cotizador completo</b> conserva la foto y tu elección de catálogo. <b>Guardar en base de datos</b> crea el folio y guarda los artículos cuando esa opción está marcada.</p>
            </div>
        </aside>
    </div>
    </form>
</main>
<script>
window.SRNU_PAGOS_REGLAS = <?= json_encode(srnu_tarjeta_reglas(), JSON_UNESCAPED_SLASHES) ?>;
<?php readfile(__DIR__ . '/assets/cotizacion_tarjeta.js'); ?>
const CATALOGO = <?=json_encode($catalogoJson,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const PREVIOS = <?=json_encode($prevItems,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const body=document.getElementById('qr-body');
const money=n=>new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(Number(n)||0);
const pagos=window.SRNU_PAGOS, MSI_LIMITE=pagos.reglas.limite;
const CLIP_SOBRETASAS=pagos.reglas.clip.sobretasas;
const BBVA_SOBRETASAS=pagos.reglas.bbva.sobretasas;
function clipMsi(total,plazo){
 return pagos.clip(total,plazo);
}
function bbvaMsi(subtotal,plazo){
 return pagos.bbva(subtotal,plazo);
}
function msiCard(plazo,cobro){
 const mensual=plazo>0?cobro/plazo:0;
 return `<div class="qr-msi-card"><strong>${plazo} MSI</strong><div class="qr-msi-total">${money(cobro)}</div><div class="qr-msi-monthly">${money(mensual)} / mes</div><div class="qr-msi-status">${cobro<=0?'Agrega un precio':''}</div></div>`;
}
function renderMSI(subtotal,total){
 const clipGrid=document.getElementById('qr-clip-grid'), clipNote=document.getElementById('qr-clip-note');
 const base=Math.max(0,Number(total)||0);
 document.getElementById('qr-clip-una').textContent='Una sola exhibición Clip: '+(base>0?money(pagos.clip(base,0)):'Agrega un precio para calcular');
 const clip=[];
 pagos.plazosClip(base).forEach(plazo=>{
   const cobro=base>0?clipMsi(base,plazo):0;
   clip.push(msiCard(plazo,cobro));
 });
 clipGrid.innerHTML=clip.join('');
 if(base<=0){
   clipNote.textContent='Agrega un precio para calcular los MSI automáticamente.';
 }else if(clip.length===0){
   clipNote.textContent='Pago con tarjeta a una sola exhibición incluido.';
 }else{
   clipNote.innerHTML='Solo se muestran MSI Clip cuyo cobro final no supera <b>$50,000.00</b>.';
 }
 const bbvaBox=document.getElementById('qr-bbva-box'), bbvaGrid=document.getElementById('qr-bbva-grid');
 const enBBVA=document.getElementById('qr-bbva').checked;
 clipGrid.hidden=false;
 clipNote.hidden=false;
 if(enBBVA){
   bbvaBox.hidden=false;
   const hayBBVA=pagos.unaBBVA(subtotal)||pagos.plazosBBVA(subtotal).length>0;
   const sinOpciones=subtotal>0&&!hayBBVA;
   document.getElementById('qr-bbva-una').hidden=!(pagos.unaBBVA(subtotal)||sinOpciones);
   document.getElementById('qr-bbva-una').textContent=pagos.unaBBVA(subtotal)?'Una sola exhibición BBVA: '+money(pagos.bbva(subtotal,0)):(sinOpciones?'Con este monto ningún cobro BBVA supera $50,000, por eso no hay opciones BBVA. La factura sí queda activa.':'');
   bbvaGrid.innerHTML=pagos.plazosBBVA(subtotal).map(plazo=>msiCard(plazo,bbvaMsi(subtotal,plazo))).join('');
   document.getElementById('qr-msi-mode').textContent='CLIP + BBVA PyME';
 }else{
   bbvaBox.hidden=true; bbvaGrid.innerHTML='';
   document.getElementById('qr-bbva-una').textContent='';
   document.getElementById('qr-msi-mode').textContent='CLIP';
 }
}
function rowHTML(d={}){
 const tr=document.createElement('tr'); tr.className='qr-row';
 tr.innerHTML=`<td><input name="concepto[]" class="q-concepto" placeholder="Servicio / producto" value="${esc(d.concepto||((d.nombre||'')+(d.descripcion?' — '+d.descripcion:'')))}"><input type="hidden" name="catalogo_id[]" value="${Number(d.id||d.catalogo_id||0)}"></td><td><input name="cantidad[]" class="qr-num q-cant" type="number" min="0" step="0.01" value="${d.cantidad??1}"></td><td><input name="costo_interno[]" class="qr-money q-costo" type="number" min="0" step="0.01" value="${Number(d.costo??d.costo_interno??0).toFixed(2)}"></td><td><input name="precio[]" class="qr-money q-precio" type="number" min="0" step="0.01" value="${Number(d.precio??0).toFixed(2)}"></td><td><input type="hidden" name="foto_producto_actual[]" value="${esc(d.foto_producto||'')}"><img class="qr-photo-preview" src="${esc(d.foto_producto||'')}" ${d.foto_producto?'':'hidden'} alt="Foto del artículo" style="width:72px;height:54px;object-fit:contain;margin-bottom:5px"><input class="qr-photo-input" type="file" name="foto_producto[]" accept="image/jpeg,image/png,image/webp" style="max-width:155px"></td><td class="qr-result q-total">$0.00</td><td class="qr-result q-util">$0.00</td><td class="qr-result qr-margin q-margin">0%</td><td><button class="qr-remove" type="button" title="Quitar">×</button></td>`;
 tr.querySelector('.qr-photo-input').addEventListener('change',e=>{
   if(tr.fotoTemporal)URL.revokeObjectURL(tr.fotoTemporal);
   const foto=e.target.files?.[0], imagen=tr.querySelector('.qr-photo-preview');
   tr.fotoTemporal=foto?URL.createObjectURL(foto):'';
   imagen.src=tr.fotoTemporal||tr.querySelector('input[name="foto_producto_actual[]"]').value;
   imagen.hidden=!imagen.getAttribute('src');
 });
 tr.querySelectorAll('input').forEach(i=>i.addEventListener('input',calc));
 tr.querySelector('.qr-remove').addEventListener('click',()=>{if(tr.fotoTemporal)URL.revokeObjectURL(tr.fotoTemporal);tr.remove(); if(!body.children.length)addRow(); calc();});
 body.appendChild(tr); calc();
}
function esc(v){return String(v).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}
function addRow(d={}){rowHTML(d);}
function calc(){
 let costo=0,subtotal=0;
 body.querySelectorAll('.qr-row').forEach(r=>{
   const q=Math.max(0,Number(r.querySelector('.q-cant').value)||0), c=Math.max(0,Number(r.querySelector('.q-costo').value)||0), p=Math.max(0,Number(r.querySelector('.q-precio').value)||0);
   const vt=q*p, ct=q*c, u=vt-ct, m=vt>0?(u/vt*100):0; costo+=ct; subtotal+=vt;
   r.querySelector('.q-total').textContent=money(vt); r.querySelector('.q-util').textContent=money(u);
   const me=r.querySelector('.q-margin'); me.textContent=m.toFixed(1)+'%'; me.className='qr-result qr-margin q-margin '+(m>=35?'good':m>=20?'low':'bad');
 });
 const facturaCheck=document.getElementById('qr-factura');
 subtotal=pagos.centavos(subtotal);
 // BBVA PyME manual: al marcarlo la factura se activa y se bloquea; al desmarcarlo vuelve a ser libre.
 const enBBVA=document.getElementById('qr-bbva').checked;
 if(enBBVA) facturaCheck.checked=true;
 facturaCheck.disabled=enBBVA;
 document.getElementById('qr-factura-forzada').disabled=!enBBVA;
 document.getElementById('qr-factura-label').textContent=enBBVA?'Factura + IVA · activada por BBVA PyME':'Factura + IVA';
 const total=pagos.total(subtotal,facturaCheck.checked), iva=pagos.centavos(total-subtotal), utilidad=subtotal-costo, margen=subtotal>0?utilidad/subtotal*100:0;
 document.getElementById('s-costo').textContent=money(costo);document.getElementById('s-subtotal').textContent=money(subtotal);document.getElementById('s-iva').textContent=money(iva);document.getElementById('s-total').textContent=money(total);document.getElementById('s-utilidad').textContent=money(utilidad);document.getElementById('s-margen').textContent='Margen: '+margen.toFixed(1)+'%';
 renderMSI(subtotal,total);
}
document.getElementById('qr-factura').addEventListener('change',calc);
document.getElementById('qr-bbva').addEventListener('change',calc);
document.getElementById('qr-add-row').addEventListener('click',()=>{addRow(); body.lastElementChild.querySelector('.q-concepto').focus();});
document.getElementById('qr-add-catalog').addEventListener('click',()=>{const id=Number(document.getElementById('qr-catalogo').value);const d=CATALOGO.find(x=>x.id===id);if(d){addRow(d);document.getElementById('qr-catalogo').value='';}});
document.getElementById('qr-catalogo').addEventListener('change',e=>{if(e.target.value)document.getElementById('qr-add-catalog').focus();});
document.getElementById('qr-clear').addEventListener('click',()=>{if(!confirm('¿Limpiar toda la prueba?'))return;body.innerHTML='';document.getElementById('qr-form').reset();addRow();calc();});
document.getElementById('qr-copy').addEventListener('click',async()=>{
 calc();
 let lines=['SRNU CUSTOM · Cotización rápida']; const v=document.getElementById('qr-vehiculo').value.trim(); if(v)lines.push('Vehículo: '+v); lines.push('');
 body.querySelectorAll('.qr-row').forEach(r=>{const c=r.querySelector('.q-concepto').value.trim();if(!c)return;const q=Number(r.querySelector('.q-cant').value)||0,p=Number(r.querySelector('.q-precio').value)||0;lines.push(`${q} × ${c}: ${money(q*p)}`);});
 lines.push('',`Subtotal: ${document.getElementById('s-subtotal').textContent}`);if(document.getElementById('qr-factura').checked)lines.push(`IVA: ${document.getElementById('s-iva').textContent}`);lines.push(`TOTAL: ${document.getElementById('s-total').textContent}`);
 const subtotal=[...body.querySelectorAll('.qr-row')].reduce((a,r)=>a+(Math.max(0,Number(r.querySelector('.q-cant').value)||0)*Math.max(0,Number(r.querySelector('.q-precio').value)||0)),0);
 const total=pagos.total(subtotal,document.getElementById('qr-factura').checked);
 lines.push('','PAGO CON TARJETA CLIP:');
 lines.push('Una sola exhibición Clip: '+money(pagos.clip(total,0)));
 pagos.plazosClip(total).forEach(plazo=>{
   const cobro=clipMsi(total,plazo);
   lines.push(`${plazo} MSI: ${money(cobro)} (${money(cobro/plazo)}/mes)`);
 });
 if(document.getElementById('qr-bbva').checked){
   lines.push('','BBVA PyME · FACTURA + IVA OBLIGATORIA:');
   if(pagos.unaBBVA(subtotal))lines.push('Una sola exhibición BBVA: '+money(pagos.bbva(subtotal,0)));
   pagos.plazosBBVA(subtotal).forEach(plazo=>{const cobro=bbvaMsi(subtotal,plazo);lines.push(`${plazo} MSI: ${money(cobro)} (${money(cobro/plazo)}/mes)`);});
 }
 const resumen=lines.join('\n');
 let copiado=false;
 // En conexiones HTTP de la red local puede no estar disponible navigator.clipboard.
 if(navigator.clipboard?.writeText){
   try{await navigator.clipboard.writeText(resumen);copiado=true;}catch(e){}
 }
 if(!copiado){
   const campo=document.createElement('textarea');campo.value=resumen;
   campo.setAttribute('readonly','');
   campo.style.cssText='position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;font-size:16px;';
   document.body.appendChild(campo);campo.focus();campo.select();campo.setSelectionRange(0,campo.value.length);
   try{copiado=document.execCommand('copy');}catch(e){}
   campo.remove();
 }
 if(copiado){
   const boton=document.getElementById('qr-copy');const anterior=boton.textContent;
   boton.textContent='✓ Resumen copiado';setTimeout(()=>boton.textContent=anterior,1400);
 }else{
   window.prompt('Copia manualmente este resumen:',resumen);
 }
});
document.getElementById('qr-form').addEventListener('submit',e=>{calc();const valid=[...body.querySelectorAll('.q-concepto')].some(x=>x.value.trim()!=='');if(!valid){e.preventDefault();alert('Agrega al menos un concepto.');}});
if(PREVIOS.length){PREVIOS.forEach(addRow);}else{addRow();}calc();
</script>
</body></html>
