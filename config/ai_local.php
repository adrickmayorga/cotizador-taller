<?php
declare(strict_types=1);

/** SRNU CUSTOM · integración opcional con Ollama local. */
function srnu_ai_config(): array
{
    static $cfg = null;
    if (is_array($cfg)) return $cfg;
    $defaults = [
        'enabled'=>true,
        'url'=>'http://127.0.0.1:11434',
        'model'=>'qwen3-vl:4b-instruct',
        'timeout'=>180,
        'max_pdf_pages'=>4,
        'max_text_chars'=>70000,
        'temperature'=>0.0,
    ];
    $file = __DIR__ . '/ai_local_config.php';
    $custom = is_file($file) ? require $file : [];
    $cfg = array_merge($defaults, is_array($custom) ? $custom : []);
    $cfg['url'] = rtrim((string)$cfg['url'], '/');
    return $cfg;
}

function srnu_ai_http(string $path, ?array $payload = null, ?int $timeout = null): array
{
    $cfg = srnu_ai_config();
    if (empty($cfg['enabled'])) return ['ok'=>false,'error'=>'IA local desactivada'];
    $url = $cfg['url'] . '/' . ltrim($path, '/');
    $timeout ??= (int)$cfg['timeout'];
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($body === false) return ['ok'=>false,'error'=>'No se pudo preparar la solicitud a la IA'];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) return ['ok'=>false,'error'=>$err ?: 'No se pudo conectar con Ollama'];
        $json = json_decode((string)$raw, true);
        if ($code < 200 || $code >= 300) return ['ok'=>false,'error'=>(string)($json['error'] ?? ('Ollama respondió HTTP '.$code)),'raw'=>$raw];
        return ['ok'=>true,'data'=>is_array($json)?$json:[],'raw'=>$raw];
    }

    $headers = "Content-Type: application/json\r\n";
    $opts = ['http'=>['method'=>$payload===null?'GET':'POST','timeout'=>$timeout,'ignore_errors'=>true,'header'=>$headers]];
    if ($payload !== null) $opts['http']['content'] = $body;
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    if ($raw === false) return ['ok'=>false,'error'=>'No se pudo conectar con Ollama en '.$cfg['url']];
    $json = json_decode($raw, true);
    if (!is_array($json)) return ['ok'=>false,'error'=>'Respuesta inválida de Ollama'];
    if (!empty($json['error'])) return ['ok'=>false,'error'=>(string)$json['error']];
    return ['ok'=>true,'data'=>$json,'raw'=>$raw];
}

function srnu_ai_estado(): array
{
    $cfg = srnu_ai_config();
    if (empty($cfg['enabled'])) return ['online'=>false,'model_ok'=>false,'model'=>$cfg['model'],'error'=>'IA local desactivada'];
    $r = srnu_ai_http('/api/tags', null, 4);
    if (empty($r['ok'])) return ['online'=>false,'model_ok'=>false,'model'=>$cfg['model'],'error'=>$r['error'] ?? 'Ollama no responde'];
    $models = [];
    foreach (($r['data']['models'] ?? []) as $m) {
        $name = (string)($m['name'] ?? $m['model'] ?? '');
        if ($name !== '') $models[] = $name;
    }
    $wanted = (string)$cfg['model'];
    $ok = in_array($wanted, $models, true);
    if (!$ok) {
        $wantedBase = preg_replace('/:(latest)$/', '', $wanted);
        foreach ($models as $m) if ($m === $wantedBase || str_starts_with($m, $wantedBase.':')) { $ok=true; break; }
    }
    return ['online'=>true,'model_ok'=>$ok,'model'=>$wanted,'models'=>$models,'error'=>$ok?'':('Falta descargar '.$wanted)];
}

function srnu_ai_chat(array $messages, ?array $schema = null, array $options = []): array
{
    $cfg = srnu_ai_config();
    $payload = [
        'model'=>(string)$cfg['model'],
        'stream'=>false,
        'messages'=>$messages,
        'options'=>array_merge(['temperature'=>(float)$cfg['temperature']], $options),
    ];
    if ($schema !== null) $payload['format'] = $schema;
    $r = srnu_ai_http('/api/chat', $payload, (int)$cfg['timeout']);
    if (empty($r['ok'])) return $r;
    $content = (string)($r['data']['message']['content'] ?? '');
    return ['ok'=>true,'content'=>$content,'data'=>$r['data']];
}

function srnu_ai_json(string $content): ?array
{
    $content = trim($content);
    if ($content === '') return null;
    $d = json_decode($content, true);
    if (is_array($d)) return $d;
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/su', $content, $m)) {
        $d = json_decode($m[1], true); if (is_array($d)) return $d;
    }
    $a = strpos($content, '{'); $b = strrpos($content, '}');
    if ($a !== false && $b !== false && $b > $a) {
        $d = json_decode(substr($content,$a,$b-$a+1), true); if (is_array($d)) return $d;
    }
    return null;
}

function srnu_ai_cotizacion_schema(): array
{
    $itemProps = [
        'concepto'=>['type'=>'string'], 'cantidad'=>['type'=>'number'], 'precio'=>['type'=>'number'],
        'responsable_sergio'=>['type'=>'boolean'], 'responsable_ricardo'=>['type'=>'boolean'], 'responsable_tomas'=>['type'=>'boolean'],
        'garantia_meses'=>['type'=>'integer'], 'tipo_entrega'=>['type'=>'string','enum'=>['inmediata','sobre_pedido']],
        'dias_entrega_estimada'=>['type'=>'integer'],
    ];
    return [
        'type'=>'object','additionalProperties'=>false,
        'properties'=>[
            'folio_origen'=>['type'=>'string'],'cliente'=>['type'=>'string'],'telefono'=>['type'=>'string'],'vehiculo'=>['type'=>'string'],
            'fecha'=>['type'=>'string'],'requiere_factura'=>['type'=>'string','enum'=>['si','no']],
            'vigencia_dias'=>['type'=>'integer'],'dias_trabajo_general_min'=>['type'=>'integer'],'dias_trabajo_general_max'=>['type'=>'integer'],
            'anticipo_tipo'=>['type'=>'string','enum'=>['porcentaje','monto']], 'anticipo_valor'=>['type'=>'number'],
            'conservar_anticipo_recibido'=>['type'=>'boolean'],'anticipo_recibido_monto'=>['type'=>'number'],
            'dividir_anticipo'=>['type'=>'boolean'],'repartir_igual'=>['type'=>'boolean'],
            'anticipo_sergio'=>['type'=>'number'],'anticipo_ricardo'=>['type'=>'number'],'anticipo_tomas'=>['type'=>'number'],
            'participa_sergio'=>['type'=>'boolean'],'participa_ricardo'=>['type'=>'boolean'],'participa_tomas'=>['type'=>'boolean'],
            'msi_plazos'=>['type'=>'array','items'=>['type'=>'string']],
            'items'=>['type'=>'array','items'=>['type'=>'object','additionalProperties'=>false,'properties'=>$itemProps,'required'=>array_keys($itemProps)]],
            'confianza'=>['type'=>'number'],'observaciones'=>['type'=>'string'],
        ],
        'required'=>['folio_origen','cliente','telefono','vehiculo','fecha','requiere_factura','vigencia_dias','dias_trabajo_general_min','dias_trabajo_general_max','anticipo_tipo','anticipo_valor','conservar_anticipo_recibido','anticipo_recibido_monto','dividir_anticipo','repartir_igual','anticipo_sergio','anticipo_ricardo','anticipo_tomas','participa_sergio','participa_ricardo','participa_tomas','msi_plazos','items','confianza','observaciones']
    ];
}

function srnu_ai_prompt_cotizacion(string $filename): string
{
    return "Eres el recuperador de cotizaciones de SRNU CUSTOM. Extrae SOLO datos que estén visibles o razonablemente explícitos en la cotización. No inventes. Si un dato no existe usa cadena vacía, 0, false o arreglo vacío. El vehículo puede inferirse del nombre del archivo si es claro. La modalidad es 'si' o 'no'. MSI válidos: 3,6,9,12,18,24, bbva_pyme, tarjeta_1x. Para servicios sobre pedido usa tipo_entrega=sobre_pedido; si no hay evidencia usa inmediata. La garantía de un concepto sobre pedido puede ser 12 meses solo si el documento así lo expresa; de lo contrario 0. En items, precio es precio unitario y cantidad debe ser mayor que 0 cuando se pueda identificar. responsables solo true si el PDF identifica a Sergio, Ricardo o Tomás. Devuelve JSON conforme al esquema, sin explicación fuera del JSON. Archivo: ".$filename;
}

function srnu_ai_normalizar_cotizacion(array $ai, array $base = []): array
{
    $d = $base;
    $str = fn($k,$def='') => trim((string)($ai[$k] ?? $d[$k] ?? $def));
    foreach (['folio_origen','cliente','telefono','vehiculo'] as $k) { $v=$str($k); if($v!=='')$d[$k]=$v; }
    $fecha=$str('fecha',date('Y-m-d')); if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha))$d['fecha']=$fecha;
    $rf=$str('requiere_factura','no'); $d['requiere_factura']=$rf==='si'?'si':'no';
    foreach(['vigencia_dias','dias_trabajo_general_min','dias_trabajo_general_max'] as $k){$v=max(0,(int)($ai[$k]??$d[$k]??0)); if($k==='vigencia_dias')$v=max(1,$v?:15);$d[$k]=$v;}
    $d['anticipo_tipo']=in_array(($ai['anticipo_tipo']??$d['anticipo_tipo']??'porcentaje'),['porcentaje','monto'],true)?($ai['anticipo_tipo']??$d['anticipo_tipo']):'porcentaje';
    foreach(['anticipo_valor','anticipo_recibido_monto','anticipo_sergio','anticipo_ricardo','anticipo_tomas'] as $k)$d[$k]=max(0,(float)($ai[$k]??$d[$k]??($k==='anticipo_valor'?70:0)));
    foreach(['conservar_anticipo_recibido','dividir_anticipo','repartir_igual','participa_sergio','participa_ricardo','participa_tomas'] as $k)$d[$k]=!empty($ai[$k]??$d[$k]??0)?1:0;
    $allowed=['3','6','9','12','18','24','bbva_pyme','tarjeta_1x'];
    $msi=[];foreach((array)($ai['msi_plazos']??$d['msi_plazos']??[]) as $m){$m=(string)$m;if(in_array($m,$allowed,true)&&!in_array($m,$msi,true))$msi[]=$m;}$d['msi_plazos']=$msi;
    $items=[];
    foreach((array)($ai['items']??[]) as $it){
        if(!is_array($it))continue;$concepto=trim((string)($it['concepto']??''));$cantidad=max(0,(float)($it['cantidad']??0));$precio=max(0,(float)($it['precio']??0));
        if($concepto===''||$cantidad<=0)continue;$tipo=(($it['tipo_entrega']??'inmediata')==='sobre_pedido')?'sobre_pedido':'inmediata';
        $items[]=['concepto'=>$concepto,'cantidad'=>$cantidad,'precio'=>$precio,'responsable_sergio'=>!empty($it['responsable_sergio'])?1:0,'responsable_ricardo'=>!empty($it['responsable_ricardo'])?1:0,'responsable_tomas'=>!empty($it['responsable_tomas'])?1:0,'garantia_meses'=>max(0,(int)($it['garantia_meses']??0)),'tipo_entrega'=>$tipo,'dias_entrega_estimada'=>max(0,(int)($it['dias_entrega_estimada']??0)),'foto_producto'=>'','mostrar_foto_pdf'=>0];
    }
    if($items)$d['items']=$items;
    $d['_ia_confianza']=max(0,min(1,(float)($ai['confianza']??0)));
    $d['_ia_observaciones']=trim((string)($ai['observaciones']??''));
    return $d;
}

function srnu_ai_extraer_desde_texto(string $text, string $filename, array $base=[]): array
{
    $cfg=srnu_ai_config();$max=max(5000,(int)$cfg['max_text_chars']);$text=mb_substr($text,0,$max,'UTF-8');
    $messages=[
        ['role'=>'system','content'=>'Eres un extractor documental preciso. Nunca inventes datos.'],
        ['role'=>'user','content'=>srnu_ai_prompt_cotizacion($filename)."\n\nTEXTO EXTRAÍDO DEL PDF:\n".$text],
    ];
    $r=srnu_ai_chat($messages,srnu_ai_cotizacion_schema());if(empty($r['ok']))return ['ok'=>false,'error'=>$r['error']??'La IA no respondió'];
    $data=srnu_ai_json((string)$r['content']);if(!$data)return ['ok'=>false,'error'=>'La IA no devolvió JSON válido'];
    return ['ok'=>true,'data'=>srnu_ai_normalizar_cotizacion($data,$base),'mode'=>'texto'];
}

function srnu_ai_find_pdftoppm(): ?string
{
    $c=[];
    if(PHP_OS_FAMILY==='Windows'){
        $c=[__DIR__.'/../tools/pdftoppm.exe','C:\\xampp\\poppler\\Library\\bin\\pdftoppm.exe','C:\\Program Files\\poppler\\Library\\bin\\pdftoppm.exe'];
        $local=str_replace('\\','/',(string)getenv('LOCALAPPDATA'));
        if($local!==''){
            foreach([$local.'/Microsoft/WinGet/Packages/oschwartz10612.Poppler*/Library/bin/pdftoppm.exe',$local.'/Microsoft/WinGet/Packages/oschwartz10612.Poppler*/*/Library/bin/pdftoppm.exe',$local.'/Microsoft/WinGet/Packages/oschwartz10612.Poppler*/poppler-*/Library/bin/pdftoppm.exe'] as $g)foreach((glob($g)?:[]) as $x)$c[]=$x;
        }
        if(function_exists('exec')){$w=[];$code=1;@exec('where pdftoppm 2>NUL',$w,$code);if($code===0)foreach($w as $x)$c[]=trim($x);}
    }else{$c=['/usr/bin/pdftoppm','/usr/local/bin/pdftoppm'];}
    foreach(array_unique($c) as $x)if($x!==''&&is_file($x))return $x;return null;
}

function srnu_ai_pdf_imagenes(string $pdfPath): array
{
    if(!function_exists('exec')||!is_file($pdfPath))return [];$exe=srnu_ai_find_pdftoppm();if(!$exe)return [];
    $cfg=srnu_ai_config();$pages=max(1,min(8,(int)$cfg['max_pdf_pages']));$dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'srnu_ai_'.bin2hex(random_bytes(5));@mkdir($dir,0775,true);$prefix=$dir.DIRECTORY_SEPARATOR.'page';
    $cmd=escapeshellarg($exe).' -f 1 -l '.$pages.' -r 115 -jpeg -jpegopt quality=78 '.escapeshellarg($pdfPath).' '.escapeshellarg($prefix).' 2>&1';$o=[];$code=1;@exec($cmd,$o,$code);if($code!==0)return [];
    $files=glob($prefix.'-*.jpg')?:[];natsort($files);return array_values($files);
}

function srnu_ai_extraer_desde_pdf_visual(string $pdfPath,string $filename,array $base=[]): array
{
    $files=srnu_ai_pdf_imagenes($pdfPath);if(!$files)return ['ok'=>false,'error'=>'No se pudieron convertir las páginas del PDF a imágenes. Instala Poppler/pdftoppm para PDFs escaneados.'];
    $images=[];foreach($files as $f){$b=@file_get_contents($f);if($b!==false)$images[]=base64_encode($b);@unlink($f);}if($files){$dir=dirname($files[0]);@rmdir($dir);}if(!$images)return ['ok'=>false,'error'=>'No se pudieron leer las imágenes temporales del PDF'];
    $messages=[['role'=>'system','content'=>'Eres un extractor visual de documentos. Lee cuidadosamente las páginas y no inventes datos.'],['role'=>'user','content'=>srnu_ai_prompt_cotizacion($filename).' Analiza estas páginas del PDF.','images'=>$images]];
    $r=srnu_ai_chat($messages,srnu_ai_cotizacion_schema());if(empty($r['ok']))return ['ok'=>false,'error'=>$r['error']??'La IA visual no respondió'];$data=srnu_ai_json((string)$r['content']);if(!$data)return ['ok'=>false,'error'=>'La IA visual no devolvió JSON válido'];return ['ok'=>true,'data'=>srnu_ai_normalizar_cotizacion($data,$base),'mode'=>'vision'];
}

function srnu_ai_recuperar_pdf(string $pdfPath,string $filename,string $text,array $base=[]): array
{
    $st=srnu_ai_estado();if(empty($st['online'])||empty($st['model_ok']))return ['ok'=>false,'error'=>$st['error']??'IA local no disponible','status'=>$st];
    if(mb_strlen(trim($text),'UTF-8')>=100){$r=srnu_ai_extraer_desde_texto($text,$filename,$base);if(!empty($r['ok'])&&!empty($r['data']['items']))return $r;}
    $v=srnu_ai_extraer_desde_pdf_visual($pdfPath,$filename,$base);if(!empty($v['ok']))return $v;
    if(isset($r)&&!empty($r['ok']))return $r;
    return $v;
}

function srnu_ai_busqueda_schema(): array
{
    return ['type'=>'object','additionalProperties'=>false,'properties'=>[
        'term'=>['type'=>'string'],
        'domains'=>['type'=>'array','items'=>['type'=>'string','enum'=>['cotizaciones','clientes','garantias','inventario','compras','catalogo','pagos']]],
        'estado'=>['type'=>'string'],
        'pago'=>['type'=>'string','enum'=>['','pendiente','pagado']],
    ],'required'=>['term','domains','estado','pago']];
}

function srnu_ai_interpretar_busqueda(string $q): ?array
{
    $st=srnu_ai_estado();if(empty($st['online'])||empty($st['model_ok']))return null;
    $prompt="Interpreta una búsqueda dentro del ERP automotriz SRNU. Extrae el término específico útil (nombre, teléfono, vehículo, folio, producto, proveedor). Si la consulta es genérica como 'stock bajo', 'garantías por vencer' o 'pedidos pendientes', term debe ser vacío. domains puede incluir uno o varios. estado solo puede ser: Cotización, Aprobada, Anticipo recibido, Vehículo recibido, En proceso, Terminado, Entregado, Cancelado o vacío. pago: pendiente, pagado o vacío. Consulta: ".$q;
    $r=srnu_ai_chat([['role'=>'system','content'=>'Clasifica búsquedas de un ERP. Responde solo JSON.'],['role'=>'user','content'=>$prompt]],srnu_ai_busqueda_schema(),['temperature'=>0]);
    if(empty($r['ok']))return null;$d=srnu_ai_json((string)$r['content']);return is_array($d)?$d:null;
}


function srnu_ai_recomendaciones_schema(): array
{
    return ['type'=>'object','additionalProperties'=>false,'properties'=>[
        'tipo_vehiculo'=>['type'=>'string'],
        'recomendaciones'=>['type'=>'array','maxItems'=>6,'items'=>['type'=>'object','additionalProperties'=>false,'properties'=>[
            'catalogo_id'=>['type'=>'integer'],
            'motivo'=>['type'=>'string'],
            'prioridad'=>['type'=>'string','enum'=>['alta','media','baja']],
            'compatibilidad'=>['type'=>'string'],
        ],'required'=>['catalogo_id','motivo','prioridad','compatibilidad']]],
    ],'required'=>['tipo_vehiculo','recomendaciones']];
}

function srnu_ai_recomendar_servicios(string $vehiculo, array $catalogo, array $existentes=[]): array
{
    $vehiculo=trim($vehiculo); if($vehiculo==='') return ['ok'=>false,'error'=>'Escribe primero el vehículo.'];
    $existentesNorm=array_map(fn($x)=>mb_strtolower(trim((string)$x),'UTF-8'),$existentes);
    $compact=[];$byId=[];
    foreach($catalogo as $c){
        $id=(int)($c['id']??0); if($id<=0)continue;$byId[$id]=$c;
        $compact[]=['id'=>$id,'nombre'=>(string)$c['nombre'],'categoria'=>(string)($c['categoria']??''),'descripcion'=>(string)($c['descripcion']??''),'precio'=>(float)($c['precio_base']??0),'precio_fijo'=>!empty($c['precio_fijo']),'tipo_entrega'=>(string)($c['tipo_entrega']??'inmediata')];
    }
    $st=srnu_ai_estado();
    if(!empty($st['online'])&&!empty($st['model_ok'])){
        $prompt="Vehículo: {$vehiculo}\nServicios ya agregados: ".json_encode($existentes,JSON_UNESCAPED_UNICODE)."\nCatálogo disponible: ".json_encode($compact,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\nActúa como asesor técnico de personalización automotriz en México. Recomienda hasta 6 servicios que los propietarios de este tipo de vehículo suelen buscar (estética, iluminación, multimedia, accesorios o performance), pero SOLO puedes elegir IDs del catálogo proporcionado. No inventes compatibilidad ni piezas; si requiere revisar versión/equipamiento indícalo en compatibilidad. Evita servicios ya agregados. Prioriza recomendaciones útiles y razonables sobre ventas forzadas. La recomendación se basa en patrones comunes de propietarios, no en búsquedas web en tiempo real.";
        $r=srnu_ai_chat([['role'=>'system','content'=>'Eres un asesor automotriz prudente. Elige únicamente servicios del catálogo dado y responde JSON.'],['role'=>'user','content'=>$prompt]],srnu_ai_recomendaciones_schema(),['temperature'=>0.2]);
        if(!empty($r['ok'])){
            $d=srnu_ai_json((string)$r['content']);$out=[];
            foreach((array)($d['recomendaciones']??[]) as $rec){$id=(int)($rec['catalogo_id']??0);if(!isset($byId[$id]))continue;$c=$byId[$id];$n=mb_strtolower(trim((string)$c['nombre']),'UTF-8');$dup=false;foreach($existentesNorm as $ex)if($ex!==''&&(str_contains($ex,$n)||str_contains($n,$ex))){$dup=true;break;}if($dup)continue;$out[]=['servicio'=>$c,'motivo'=>trim((string)($rec['motivo']??'')),'prioridad'=>(string)($rec['prioridad']??'media'),'compatibilidad'=>trim((string)($rec['compatibilidad']??''))];if(count($out)>=6)break;}
            if($out)return ['ok'=>true,'ia'=>true,'tipo_vehiculo'=>(string)($d['tipo_vehiculo']??''),'recomendaciones'=>$out];
        }
    }
    // Respaldo gratuito sin IA: reglas conservadoras por tipo de vehículo.
    $v=mb_strtolower($vehiculo,'UTF-8');$tokens=[];
    if(preg_match('/ram|silverado|sierra|tacoma|frontier|hilux|ranger|f-?150|lobo|pickup|pick-up|hunter|1200/u',$v))$tokens=['tapa de batea','reversa','28,000','carplay','cerámico 3 años','ambiental'];
    elseif(preg_match('/audi|volkswagen|vw|seat|bmw|mini/u',$v))$tokens=['euro-switch','carplay','ambiental','28,000','cerámico 3 años'];
    elseif(preg_match('/suv|rav4|cr-v|hr-v|sportage|tucson|tiguan|cx-5|compass|highlander/u',$v))$tokens=['carplay','ambiental','28,000','cerámico 3 años','mantenimiento integral'];
    else $tokens=['carplay','28,000','cerámico 1 año','mantenimiento integral','reversa'];
    $out=[];foreach($tokens as $tok){foreach($catalogo as $c){if(str_contains(mb_strtolower((string)$c['nombre'],'UTF-8'),mb_strtolower($tok,'UTF-8'))){$out[]=['servicio'=>$c,'motivo'=>'Recomendación frecuente para mejorar uso, estética o equipamiento de este tipo de vehículo.','prioridad'=>count($out)<2?'alta':'media','compatibilidad'=>'Confirmar compatibilidad física y electrónica con la versión exacta del vehículo.'];break;}}if(count($out)>=6)break;}
    return ['ok'=>true,'ia'=>false,'tipo_vehiculo'=>'','recomendaciones'=>$out];
}
