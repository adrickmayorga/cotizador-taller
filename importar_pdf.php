<?php
declare(strict_types=1);
require_once __DIR__ . '/config/erp.php';
$pdo = srnu_db();

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fnum($v): float { return max(0, (float)str_replace(',', '', (string)$v)); }

$error = '';
$datos = null;
$modoRecuperacion = '';
$archivoGuardado = '';
$hashPdf = '';
$iaEstado = srnu_ai_estado();
$iaDetalle = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    try {
        $cliente = trim((string)($_POST['cliente'] ?? ''));
        $telefono = trim((string)($_POST['telefono'] ?? ''));
        $vehiculo = trim((string)($_POST['vehiculo'] ?? ''));
        if ($cliente === '' || $vehiculo === '') throw new RuntimeException('Cliente y vehículo son obligatorios.');
        $fecha = (string)($_POST['fecha'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = date('Y-m-d');
        $requiere = (($_POST['requiere_factura'] ?? 'no') === 'si') ? 'si' : 'no';
        $conceptos = $_POST['concepto'] ?? [];
        $cantidades = $_POST['cantidad'] ?? [];
        $precios = $_POST['precio'] ?? [];
        $garantias = $_POST['garantia_meses'] ?? [];
        $tipos = $_POST['tipo_entrega'] ?? [];
        $dias = $_POST['dias_entrega_estimada'] ?? [];
        $respS = $_POST['resp_sergio'] ?? [];
        $respR = $_POST['resp_ricardo'] ?? [];
        $respT = $_POST['resp_tomas'] ?? [];
        $items=[]; $subtotal=0.0;
        for($i=0;$i<count($conceptos);$i++){
            $c=trim((string)($conceptos[$i]??'')); $q=fnum($cantidades[$i]??0); $p=fnum($precios[$i]??0);
            if($c===''||$q<=0) continue;
            $tipo=(($tipos[$i]??'inmediata')==='sobre_pedido')?'sobre_pedido':'inmediata';
            $gar=max(0,(int)($garantias[$i]??0)); if($tipo==='sobre_pedido'&&$gar<=0)$gar=12;
            $total=round($q*$p,2); $subtotal+=$total;
            $items[]=[
                'concepto'=>$c,'cantidad'=>$q,'precio'=>$p,'costo_interno'=>0,'total'=>$total,
                'responsable_sergio'=>!empty($respS[$i])?1:0,'responsable_ricardo'=>!empty($respR[$i])?1:0,'responsable_tomas'=>!empty($respT[$i])?1:0,
                'garantia_meses'=>$gar,'catalogo_id'=>null,'tipo_entrega'=>$tipo,'dias_entrega_estimada'=>max(0,(int)($dias[$i]??0)),
                'dias_trabajo_min'=>0,'dias_trabajo_max'=>0,'foto_producto'=>trim((string)($_POST['foto_producto'][$i]??'')),'mostrar_foto_pdf'=>!empty($_POST['mostrar_foto_pdf'][$i])?1:0
            ];
        }
        if(!$items) throw new RuntimeException('Agrega al menos un concepto válido.');
        $subtotal=round($subtotal,2); $iva=$requiere==='si'?round($subtotal*.16,2):0.0; $total=round($subtotal+$iva,2);
        $msi = array_values(array_intersect(['3','6','9','12','18','24'], array_map('strval', $_POST['msi_plazos']??[])));
        if(!empty($_POST['bbva_pyme']) && $subtotal>50000){$msi[]='bbva_pyme';$requiere='si';$iva=round($subtotal*.16,2);$total=round($subtotal+$iva,2);} 
        if(!empty($_POST['tarjeta_1x']))$msi[]='tarjeta_1x';
        $id=srnu_guardar_cotizacion([
            'cliente'=>$cliente,'telefono'=>$telefono,'cliente_id'=>0,'vehiculo'=>$vehiculo,'fecha_raw'=>$fecha,'requiere_factura'=>$requiere,
            'subtotal'=>$subtotal,'iva'=>$iva,'total'=>$total,'msi_plazos'=>$msi,
            'anticipo_tipo'=>in_array(($_POST['anticipo_tipo']??'porcentaje'),['porcentaje','monto'],true)?$_POST['anticipo_tipo']:'porcentaje',
            'anticipo_valor'=>fnum($_POST['anticipo_valor']??70),'conservar_anticipo_recibido'=>!empty($_POST['conservar_anticipo_recibido'])?1:0,
            'anticipo_recibido_monto'=>fnum($_POST['anticipo_recibido_monto']??0),'dividir_anticipo'=>!empty($_POST['dividir_anticipo'])?1:0,'repartir_igual'=>!empty($_POST['repartir_igual'])?1:0,
            'anticipo_sergio'=>fnum($_POST['anticipo_sergio']??0),'anticipo_ricardo'=>fnum($_POST['anticipo_ricardo']??0),'anticipo_tomas'=>fnum($_POST['anticipo_tomas']??0),
            'participa_sergio'=>!empty($_POST['participa_sergio'])?1:0,'participa_ricardo'=>!empty($_POST['participa_ricardo'])?1:0,'participa_tomas'=>!empty($_POST['participa_tomas'])?1:0,
            'estado'=>'Cotización','vigencia_dias'=>max(1,(int)($_POST['vigencia_dias']??15)),
            'dias_trabajo_general_min'=>max(0,(int)($_POST['dias_trabajo_general_min']??0)),'dias_trabajo_general_max'=>max(0,(int)($_POST['dias_trabajo_general_max']??0)),
            'motivo_version'=>'Recuperada desde PDF'
        ],$items,0);
        $pdo->prepare('UPDATE cotizaciones SET importado_desde_pdf=1,pdf_origen_nombre=?,pdf_origen_hash=?,pdf_folio_origen=?,pdf_origen_archivo=? WHERE id=?')
            ->execute([trim((string)($_POST['pdf_origen_nombre']??'')),trim((string)($_POST['pdf_origen_hash']??'')),trim((string)($_POST['folio_origen']??'')),trim((string)($_POST['pdf_origen_archivo']??'')),$id]);
        header('Location: cotizador.php?id='.$id.'&importado=1'); exit;
    } catch(Throwable $e){ $error=$e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'analizar') {
    try {
        if(empty($_FILES['pdf']) || ($_FILES['pdf']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Selecciona un PDF válido.');
        $f=$_FILES['pdf']; if((int)($f['size']??0)>30*1024*1024) throw new RuntimeException('El PDF supera el límite de 30 MB.');
        $tmp=(string)$f['tmp_name']; $sig=(string)@file_get_contents($tmp,false,null,0,5); if($sig!=='%PDF-') throw new RuntimeException('El archivo no parece ser un PDF válido.');
        $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=(string)$finfo->file($tmp); if(!in_array($mime,['application/pdf','application/octet-stream'],true)) throw new RuntimeException('Tipo de archivo no permitido.');
        $hashPdf=hash_file('sha256',$tmp)?:'';
        $nombre=(string)($f['name']??'cotizacion.pdf');
        $dir=__DIR__.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'pdf_importados'; if(!is_dir($dir))@mkdir($dir,0775,true);
        if(is_dir($dir) && !is_file($dir.DIRECTORY_SEPARATOR.'.htaccess')) @file_put_contents($dir.DIRECTORY_SEPARATOR.'.htaccess', "Options -Indexes\n<FilesMatch \"\.(php|phtml|phar|cgi|pl|py|sh)$\">\nRequire all denied\n</FilesMatch>\n");
        $safe=date('Ymd-His').'-'.bin2hex(random_bytes(5)).'.pdf'; if(is_dir($dir))@move_uploaded_file($tmp,$dir.DIRECTORY_SEPARATOR.$safe);
        $ruta=is_file($dir.DIRECTORY_SEPARATOR.$safe)?$dir.DIRECTORY_SEPARATOR.$safe:$tmp;
        $archivoGuardado=is_file($dir.DIRECTORY_SEPARATOR.$safe)?'uploads/pdf_importados/'.$safe:'';
        $datos=srnu_extraer_respaldo_pdf($ruta);
        if($datos){
            $modoRecuperacion='completa';
            $iaDetalle='El PDF contiene respaldo SRNU estructurado; no fue necesario usar IA.';
        } else {
            $texto=srnu_extraer_texto_pdf_antiguo($ruta);
            $base=srnu_parsear_pdf_antiguo($texto,$nombre);
            $ia=srnu_ai_recuperar_pdf($ruta,$nombre,$texto,$base);
            if(!empty($ia['ok'])){
                $datos=$ia['data'];
                $modoRecuperacion='ia_'.($ia['mode']??'texto');
                $iaDetalle='IA local '.(($ia['mode']??'texto')==='vision'?'visual':'de texto').' · confianza '.number_format(((float)($datos['_ia_confianza']??0))*100,0).'%';
                if(!empty($datos['_ia_observaciones'])) $iaDetalle.=' · '.$datos['_ia_observaciones'];
            } else {
                $datos=$base;
                $modoRecuperacion=$texto!==''?'parcial':'manual';
                $iaDetalle='IA local no disponible: '.($ia['error']??'sin respuesta').'. Se utilizó la recuperación tradicional.';
            }
        }
        $datos['pdf_origen_nombre']=$nombre; $datos['pdf_origen_hash']=$hashPdf; $datos['pdf_origen_archivo']=$archivoGuardado;
        // Si ya existe una cotización importada desde exactamente este PDF, avisamos, pero permitimos continuar.
        $st=$pdo->prepare("SELECT id,folio,cliente,vehiculo FROM cotizaciones WHERE pdf_origen_hash=? ORDER BY id DESC LIMIT 1");$st->execute([$hashPdf]);$dup=$st->fetch();
        if($dup)$datos['_duplicado']=$dup;
    } catch(Throwable $e){$error=$e->getMessage();}
}

$defaultItem=['concepto'=>'','cantidad'=>1,'precio'=>0,'responsable_sergio'=>0,'responsable_ricardo'=>0,'responsable_tomas'=>0,'garantia_meses'=>0,'tipo_entrega'=>'inmediata','dias_entrega_estimada'=>0,'foto_producto'=>'','mostrar_foto_pdf'=>0];
if(is_array($datos) && empty($datos['items'])) $datos['items']=[$defaultItem];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Importar PDF · SRNU Custom</title><link rel="icon" href="favicon.png"><link rel="stylesheet" href="assets/app.css"><style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#eef2f7;color:#0f172a}.wrap{max-width:1180px;margin:28px auto;padding:0 18px}.hero{display:flex;justify-content:space-between;gap:18px;align-items:center;margin-bottom:18px}.hero h1{margin:0;font-size:30px}.card{background:#fff;border:1px solid #dbe3ee;border-radius:20px;padding:20px;box-shadow:0 12px 38px #0f172a12;margin-bottom:16px}.upload{border:2px dashed #94a3b8;border-radius:18px;padding:28px;text-align:center}.upload input{display:block;margin:16px auto}.btn{display:inline-block;border:0;border-radius:11px;background:#111827;color:#fff;padding:11px 15px;text-decoration:none;font-weight:800;cursor:pointer}.blue{background:#2563eb}.purple{background:#7c3aed}.light{background:#fff;color:#111827;border:1px solid #cbd5e1}.ok{background:#ecfdf5;border:1px solid #86efac;color:#166534;padding:13px;border-radius:13px;margin-bottom:14px}.warn{background:#fff7ed;border:1px solid #fdba74;color:#9a3412;padding:13px;border-radius:13px;margin-bottom:14px}.err{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;padding:13px;border-radius:13px;margin-bottom:14px}.ai-status{display:flex;align-items:center;justify-content:space-between;gap:12px;background:#eef2ff;border:1px solid #c7d2fe;padding:12px 14px;border-radius:13px;margin-bottom:14px}.ai-dot{width:10px;height:10px;border-radius:99px;display:inline-block;margin-right:7px;background:#ef4444}.ai-dot.on{background:#22c55e}.ai-note{background:#eff6ff;border:1px solid #93c5fd;color:#1e3a8a;padding:13px;border-radius:13px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.grid label,.item label{font-size:12px;font-weight:800;color:#475569}.grid input,.grid select,.item input,.item select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:9px;margin-top:5px}.items{display:grid;gap:12px}.item{display:grid;grid-template-columns:2.5fr .7fr 1fr .8fr 1fr 1fr;gap:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:12px;align-items:end}.checks{display:flex;gap:8px;flex-wrap:wrap;font-size:11px}.checks label{display:flex;gap:5px;align-items:center}.checks input{width:auto;margin:0}.meta{font-size:12px;color:#64748b}.footer{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}.msi{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px}.msi label{background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:7px 9px;font-size:12px}@media(max-width:900px){.grid{grid-template-columns:1fr 1fr}.item{grid-template-columns:1fr 1fr}.item .wide{grid-column:1/-1}}@media(max-width:560px){.grid{grid-template-columns:1fr}.item{grid-template-columns:1fr}.footer{flex-direction:column}.btn{text-align:center}}
</style></head><body><?php srnu_appbar('cotizaciones');?><div class="wrap"><div class="hero"><div><h1>Recuperar cotización desde PDF</h1><div class="meta">Importa un PDF enviado al cliente y vuelve a convertirlo en una cotización editable.</div></div><a class="btn light" href="cotizaciones.php">← Cotizaciones</a></div>
<div class="ai-status"><div><strong><span class="ai-dot <?=(!empty($iaEstado['online'])&&!empty($iaEstado['model_ok']))?'on':''?>"></span>IA local</strong><div class="meta"><?=(!empty($iaEstado['online'])&&!empty($iaEstado['model_ok']))?'Ollama conectado · '.h($iaEstado['model']):h($iaEstado['error']??'Ollama no disponible')?> · el importador funciona también sin IA.</div></div><a class="btn light" href="ia_local.php">Configurar IA</a></div>
<?php if($error!==''):?><div class="err"><?=h($error)?></div><?php endif;?>
<?php if(!$datos):?><div class="card"><form method="post" enctype="multipart/form-data"><input type="hidden" name="accion" value="analizar"><div class="upload"><h2>Selecciona el PDF</h2><p>Los PDFs generados con esta versión se recuperan de forma completa. Los anteriores se intentan reconstruir automáticamente.</p><input type="file" name="pdf" accept="application/pdf,.pdf" required><button class="btn purple">Analizar PDF</button></div></form></div>
<?php else:?>
<?php if($modoRecuperacion==='completa'):?><div class="ok"><strong>Recuperación completa.</strong> Este PDF contiene un respaldo SRNU válido. Revisa los datos y pulsa “Importar y editar”.</div><?php elseif(str_starts_with($modoRecuperacion,'ia_')):?><div class="ai-note"><strong>Recuperación con IA local.</strong> <?=h($iaDetalle)?>. Revisa los datos antes de importar: la IA acelera la captura, pero tú conservas el control final.</div><?php elseif($modoRecuperacion==='parcial'):?><div class="warn"><strong>PDF antiguo.</strong> Recuperé la información que pude leer del documento. <?=h($iaDetalle)?> Revisa especialmente conceptos, responsables, garantías y anticipo antes de importar.</div><?php else:?><div class="warn"><strong>Recuperación manual.</strong> <?=h($iaDetalle)?> Completa los conceptos viendo tu PDF original y luego impórtalo.</div><?php endif;?>
<?php if(!empty($datos['_duplicado'])):?><div class="warn">Este mismo PDF parece haberse importado antes como <strong><?=h($datos['_duplicado']['folio'])?></strong> · <?=h($datos['_duplicado']['cliente'])?> · <?=h($datos['_duplicado']['vehiculo'])?>. Puedes importarlo otra vez si realmente lo necesitas.</div><?php endif;?>
<?php if(!empty($datos['pdf_origen_archivo'])):?><div style="margin:0 0 14px"><a class="btn light" target="_blank" href="<?=h($datos['pdf_origen_archivo'])?>">Ver PDF original ↗</a></div><?php endif;?>
<form method="post" class="card"><input type="hidden" name="accion" value="guardar"><input type="hidden" name="pdf_origen_nombre" value="<?=h($datos['pdf_origen_nombre']??'')?>"><input type="hidden" name="pdf_origen_hash" value="<?=h($datos['pdf_origen_hash']??'')?>"><input type="hidden" name="pdf_origen_archivo" value="<?=h($datos['pdf_origen_archivo']??'')?>"><input type="hidden" name="folio_origen" value="<?=h($datos['folio_origen']??'')?>">
<h2>Datos recuperados</h2><div class="grid"><label>Cliente<input name="cliente" required value="<?=h($datos['cliente']??'')?>"></label><label>Teléfono<input name="telefono" value="<?=h($datos['telefono']??'')?>"></label><label>Vehículo<input name="vehiculo" required value="<?=h($datos['vehiculo']??'')?>"></label><label>Fecha<input type="date" name="fecha" value="<?=h($datos['fecha']??date('Y-m-d'))?>"></label><label>Modalidad<select name="requiere_factura"><option value="no" <?=($datos['requiere_factura']??'no')==='no'?'selected':''?>>Sin factura</option><option value="si" <?=($datos['requiere_factura']??'no')==='si'?'selected':''?>>Con factura</option></select></label><label>Vigencia (días)<input type="number" min="1" name="vigencia_dias" value="<?=h($datos['vigencia_dias']??15)?>"></label><label>Trabajo mínimo (días)<input type="number" min="0" name="dias_trabajo_general_min" value="<?=h($datos['dias_trabajo_general_min']??0)?>"></label><label>Trabajo máximo (días)<input type="number" min="0" name="dias_trabajo_general_max" value="<?=h($datos['dias_trabajo_general_max']??0)?>"></label></div>
<h3>Conceptos</h3><div class="items" id="items"><?php foreach(($datos['items']??[$defaultItem]) as $i=>$it):?><div class="item"><label class="wide">Concepto<input name="concepto[]" value="<?=h($it['concepto']??'')?>"></label><label>Cantidad<input type="number" step="0.01" min="0" name="cantidad[]" value="<?=h($it['cantidad']??1)?>"></label><label>Precio<input type="number" step="0.01" min="0" name="precio[]" value="<?=h($it['precio']??0)?>"></label><label>Garantía meses<input type="number" min="0" name="garantia_meses[]" value="<?=h($it['garantia_meses']??0)?>"></label><label>Entrega<select name="tipo_entrega[]"><option value="inmediata" <?=($it['tipo_entrega']??'inmediata')==='inmediata'?'selected':''?>>Inmediata</option><option value="sobre_pedido" <?=($it['tipo_entrega']??'')==='sobre_pedido'?'selected':''?>>Sobre pedido</option></select></label><label>Días llegada<input type="number" min="0" name="dias_entrega_estimada[]" value="<?=h($it['dias_entrega_estimada']??0)?>"></label><div class="wide checks"><label><input type="checkbox" name="resp_sergio[<?=$i?>]" value="1" <?=!empty($it['responsable_sergio'])?'checked':''?>> Sergio</label><label><input type="checkbox" name="resp_ricardo[<?=$i?>]" value="1" <?=!empty($it['responsable_ricardo'])?'checked':''?>> Ricardo</label><label><input type="checkbox" name="resp_tomas[<?=$i?>]" value="1" <?=!empty($it['responsable_tomas'])?'checked':''?>> Tomás</label><input type="hidden" name="foto_producto[]" value="<?=h($it['foto_producto']??'')?>"><label><input type="checkbox" name="mostrar_foto_pdf[<?=$i?>]" value="1" <?=!empty($it['mostrar_foto_pdf'])?'checked':''?>> conservar foto si existe localmente</label><button type="button" class="btn light js-remove">Quitar concepto</button></div></div><?php endforeach;?></div><button type="button" class="btn light" id="add">+ Agregar concepto</button>
<h3>Pago y anticipo</h3><div class="grid"><label>Anticipo<select name="anticipo_tipo"><option value="porcentaje" <?=($datos['anticipo_tipo']??'porcentaje')==='porcentaje'?'selected':''?>>Porcentaje</option><option value="monto" <?=($datos['anticipo_tipo']??'')==='monto'?'selected':''?>>Monto</option></select></label><label>Valor<input type="number" step="0.01" min="0" name="anticipo_valor" value="<?=h($datos['anticipo_valor']??70)?>"></label><label><span>Anticipo ya recibido</span><div class="checks"><label><input type="checkbox" name="conservar_anticipo_recibido" value="1" <?=!empty($datos['conservar_anticipo_recibido'])?'checked':''?>> Conservar</label></div></label><label>Monto recibido<input type="number" step="0.01" min="0" name="anticipo_recibido_monto" value="<?=h($datos['anticipo_recibido_monto']??0)?>"></label></div>
<div class="checks" style="margin-top:12px"><label><input type="checkbox" name="dividir_anticipo" value="1" <?=!empty($datos['dividir_anticipo'])?'checked':''?>> Dividir anticipo</label><label><input type="checkbox" name="repartir_igual" value="1" <?=!empty($datos['repartir_igual'])?'checked':''?>> Repartir igual</label><label><input type="checkbox" name="participa_sergio" value="1" <?=!empty($datos['participa_sergio'])?'checked':''?>> Sergio</label><label><input type="checkbox" name="participa_ricardo" value="1" <?=!empty($datos['participa_ricardo'])?'checked':''?>> Ricardo</label><label><input type="checkbox" name="participa_tomas" value="1" <?=!empty($datos['participa_tomas'])?'checked':''?>> Tomás</label></div><div class="grid" style="margin-top:10px"><label>Sergio<input type="number" step="0.01" min="0" name="anticipo_sergio" value="<?=h($datos['anticipo_sergio']??0)?>"></label><label>Ricardo<input type="number" step="0.01" min="0" name="anticipo_ricardo" value="<?=h($datos['anticipo_ricardo']??0)?>"></label><label>Tomás<input type="number" step="0.01" min="0" name="anticipo_tomas" value="<?=h($datos['anticipo_tomas']??0)?>"></label></div>
<h3>Tarjeta / MSI</h3><div class="msi"><?php $sel=array_map('strval',$datos['msi_plazos']??[]);foreach(['3','6','9','12','18','24'] as $m):?><label><input type="checkbox" name="msi_plazos[]" value="<?=$m?>" <?=in_array($m,$sel,true)?'checked':''?>> <?=$m?> MSI</label><?php endforeach;?><label><input type="checkbox" name="tarjeta_1x" value="1" <?=in_array('tarjeta_1x',$sel,true)?'checked':''?>> Una exhibición</label><label><input type="checkbox" name="bbva_pyme" value="1" <?=in_array('bbva_pyme',$sel,true)?'checked':''?>> BBVA PyME</label></div>
<div class="footer"><a class="btn light" href="importar_pdf.php">Cancelar</a><button class="btn blue">Importar y editar</button></div></form>
<?php endif;?></div><script>
(function(){const items=document.getElementById('items'),add=document.getElementById('add');if(!items||!add)return;function reindex(){[...items.children].forEach((row,i)=>{['resp_sergio','resp_ricardo','resp_tomas','mostrar_foto_pdf'].forEach(n=>{const el=row.querySelector(`input[name^="${n}["]`);if(el)el.name=`${n}[${i}]`;});});}function wire(){reindex();items.querySelectorAll('.js-remove').forEach(b=>b.onclick=()=>{if(items.children.length>1){b.closest('.item').remove();reindex();}});}add.onclick=()=>{const i=items.children.length,d=document.createElement('div');d.className='item';d.innerHTML=`<label class="wide">Concepto<input name="concepto[]"></label><label>Cantidad<input type="number" step="0.01" min="0" name="cantidad[]" value="1"></label><label>Precio<input type="number" step="0.01" min="0" name="precio[]" value="0"></label><label>Garantía meses<input type="number" min="0" name="garantia_meses[]" value="0"></label><label>Entrega<select name="tipo_entrega[]"><option value="inmediata">Inmediata</option><option value="sobre_pedido">Sobre pedido</option></select></label><label>Días llegada<input type="number" min="0" name="dias_entrega_estimada[]" value="0"></label><div class="wide checks"><label><input type="checkbox" name="resp_sergio[${i}]" value="1"> Sergio</label><label><input type="checkbox" name="resp_ricardo[${i}]" value="1"> Ricardo</label><label><input type="checkbox" name="resp_tomas[${i}]" value="1"> Tomás</label><input type="hidden" name="foto_producto[]" value=""><label><input type="checkbox" name="mostrar_foto_pdf[${i}]" value="1"> foto en PDF</label><button type="button" class="btn light js-remove">Quitar concepto</button></div>`;items.appendChild(d);wire();};wire();})();
</script></body></html>
