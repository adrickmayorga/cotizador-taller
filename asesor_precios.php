<?php
declare(strict_types=1);
require_once __DIR__.'/config/erp.php';
// Acceso validado: la IA y las búsquedas no deben retener la sesión.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Content-Type: application/json; charset=utf-8');

function ap_norm(string $s): string {
    $s=mb_strtolower(trim($s),'UTF-8');
    $s=strtr($s,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $s=preg_replace('/[^a-z0-9]+/u',' ',$s)??$s;
    return trim(preg_replace('/\s+/',' ',$s)??$s);
}
function ap_tokens(string $s): array {
    $stop=['auto','carro','camioneta','pickup','pick','up','suv','sedan','coupe','modelo','version','motor','del','de','la','el','y','para','con','sin','servicio','producto'];
    $out=[];
    foreach(preg_split('/\s+/u',ap_norm($s))?:[] as $w){
        if($w===''||preg_match('/^(19|20)\d{2}$/',$w)||in_array($w,$stop,true)||mb_strlen($w)<2)continue;
        $out[]=$w;
    }
    return array_values(array_unique($out));
}
function ap_similarity(string $a,string $b): float {
    $A=ap_tokens($a);$B=ap_tokens($b);if(!$A||!$B)return 0.0;
    $inter=count(array_intersect($A,$B));$score=$inter/max(1,min(count($A),count($B)));
    $na=ap_norm(preg_replace('/\b(19|20)\d{2}\b/','',$a)??$a);$nb=ap_norm(preg_replace('/\b(19|20)\d{2}\b/','',$b)??$b);
    if($na!==''&&$nb!==''&&($na===$nb||str_contains($na,$nb)||str_contains($nb,$na)))$score=max($score,.95);
    return min(1,$score);
}
function ap_median(array $v): float {
    $v=array_values(array_filter(array_map('floatval',$v),fn($x)=>$x>0));if(!$v)return 0.0;sort($v,SORT_NUMERIC);$n=count($v);$m=intdiv($n,2);return $n%2?$v[$m]:(($v[$m-1]+$v[$m])/2);
}
function ap_percentile(array $v,float $p): float {
    $v=array_values(array_filter(array_map('floatval',$v),fn($x)=>$x>0));if(!$v)return 0.0;sort($v,SORT_NUMERIC);$n=count($v);if($n===1)return $v[0];$i=($n-1)*$p;$lo=(int)floor($i);$hi=(int)ceil($i);if($lo===$hi)return $v[$lo];return $v[$lo]+($v[$hi]-$v[$lo])*($i-$lo);
}
function ap_ddg_decode(string $href): string {
    $href=html_entity_decode($href,ENT_QUOTES|ENT_HTML5,'UTF-8');if(str_starts_with($href,'//'))$href='https:'.$href;
    $q=parse_url($href,PHP_URL_QUERY);if($q){parse_str($q,$p);if(!empty($p['uddg']))return urldecode((string)$p['uddg']);}return $href;
}
function ap_web_search_raw(string $q,int $limit=6): array {
    $url='https://html.duckduckgo.com/html/?q='.rawurlencode($q);$raw='';
    if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>6,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_USERAGENT=>'Mozilla/5.0 SRNU-Custom-PriceAdvisor/1.0']);$x=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($x!==false&&$code>=200&&$code<400)$raw=(string)$x;}
    if($raw===''&&filter_var(ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN)){$ctx=stream_context_create(['http'=>['timeout'=>6,'header'=>"User-Agent: Mozilla/5.0 SRNU-Custom-PriceAdvisor/1.0\r\n"]]);$x=@file_get_contents($url,false,$ctx);if($x!==false)$raw=(string)$x;}
    if($raw==='')return [];$out=[];
    if(preg_match_all('/<a[^>]+class="[^"]*result__a[^"]*"[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si',$raw,$m,PREG_SET_ORDER)){
        foreach($m as $x){$title=trim(html_entity_decode(strip_tags($x[2]),ENT_QUOTES|ENT_HTML5,'UTF-8'));$u=ap_ddg_decode($x[1]);if($title===''||!preg_match('#^https?://#i',$u))continue;$snippet='';$pos=strpos($raw,$x[0]);if($pos!==false){$chunk=substr($raw,$pos,2600);if(preg_match('/class="[^"]*result__snippet[^"]*"[^>]*>(.*?)<\//si',$chunk,$sm))$snippet=trim(html_entity_decode(strip_tags($sm[1]),ENT_QUOTES|ENT_HTML5,'UTF-8'));}$out[]=['titulo'=>$title,'url'=>$u,'snippet'=>$snippet];if(count($out)>=$limit)break;}
    }
    return $out;
}
function ap_web_search_cached(string $vehiculo,string $concepto): array {
    $dir=srnu_upload_dir('cache_precios');$key=hash('sha256',ap_norm($vehiculo).'|'.ap_norm($concepto));$file=$dir.'/'.$key.'.json';$ttl=21600;
    if(is_file($file)&&filemtime($file)>time()-$ttl){$d=json_decode((string)file_get_contents($file),true);if(is_array($d))return $d;}
    $queries=[
        trim($vehiculo.' '.$concepto.' instalación precio México'),
        trim($vehiculo.' '.$concepto.' servicio costo México'),
    ];$all=[];$seen=[];
    foreach($queries as $q){foreach(ap_web_search_raw($q,4) as $w){$u=(string)($w['url']??'');if($u===''||isset($seen[$u]))continue;$seen[$u]=1;$all[]=$w;if(count($all)>=7)break 2;}}
    @file_put_contents($file,json_encode($all,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return $all;
}
function ap_extract_prices(array $web): array {
    $vals=[];
    foreach($web as $w){$text=(string)($w['titulo']??'').' '.(string)($w['snippet']??'');
        if(preg_match_all('/(?:MXN\s*\$?|\$)\s*([0-9]{2,3}(?:[,.][0-9]{3})+(?:[,.][0-9]{2})?|[0-9]{3,6}(?:[,.][0-9]{2})?)/iu',$text,$m)){
            foreach($m[1] as $raw){$s=str_replace([' ', ','],'',$raw);$v=(float)$s;if($v>=100&&$v<=500000)$vals[]=$v;}
        }
    }
    return array_slice($vals,0,20);
}
function ap_schema(): array {
    return ['type'=>'object','additionalProperties'=>false,'properties'=>[
        'clasificacion'=>['type'=>'string','enum'=>['muy_bajo','bajo','competitivo','alto','muy_alto','sin_datos']],
        'accion'=>['type'=>'string','enum'=>['subir','bajar','mantener','revisar']],
        'precio_objetivo'=>['type'=>'number'],'rango_min'=>['type'=>'number'],'rango_max'=>['type'=>'number'],
        'confianza'=>['type'=>'string','enum'=>['alta','media','baja']],
        'comentario'=>['type'=>'string'],'razones'=>['type'=>'array','maxItems'=>5,'items'=>['type'=>'string']],
        'advertencia'=>['type'=>'string']
    ],'required'=>['clasificacion','accion','precio_objetivo','rango_min','rango_max','confianza','comentario','razones','advertencia']];
}
function ap_fallback(float $precio,float $catalogo,float $histMedian,int $histN,bool $fijo,float $costo,array $webPrices): array {
    $anchors=[];if($catalogo>0)$anchors[]=$catalogo;if($histMedian>0)$anchors[]=$histMedian;
    $webMedian=ap_median($webPrices);if($webMedian>0)$anchors[]=$webMedian;
    $target=$anchors?ap_median($anchors):$precio;if($fijo&&$catalogo>0)$target=$catalogo;
    if($target<=0)$target=$precio;
    $spread=$fijo?.04:($histN>=5?.10:.15);$min=max(0,$target*(1-$spread));$max=$target*(1+$spread);
    $ratio=$target>0?$precio/$target:1;
    $class=$ratio<.78?'muy_bajo':($ratio<.90?'bajo':($ratio<=1.10?'competitivo':($ratio<=1.25?'alto':'muy_alto')));
    if($fijo){$class=$ratio<.96?'bajo':($ratio>1.04?'alto':'competitivo');}
    $accion=in_array($class,['muy_bajo','bajo'],true)?'subir':(in_array($class,['alto','muy_alto'],true)?'bajar':'mantener');
    $margin=$precio>0&&$costo>0?(($precio-$costo)/$precio*100):null;$raz=[];
    if($fijo&&$catalogo>0)$raz[]='El catálogo SRNU marca este concepto como precio fijo en $'.number_format($catalogo,2).'.';
    elseif($catalogo>0)$raz[]='Precio base actual del catálogo: $'.number_format($catalogo,2).'.';
    if($histN>0)$raz[]='Mediana histórica SRNU: $'.number_format($histMedian,2).' en '.$histN.' registros comparables.';
    if($webMedian>0)$raz[]='Se detectaron referencias monetarias web alrededor de $'.number_format($webMedian,2).'; pueden incluir pieza sola y deben verificarse.';
    if($margin!==null)$raz[]='Margen bruto aproximado con el costo capturado: '.number_format($margin,1).'%.'.($margin<25?' Es un margen reducido.':'');
    if(!$raz)$raz[]='Todavía no hay suficientes referencias; usa esta evaluación como orientación inicial.';
    return ['clasificacion'=>$class,'accion'=>$accion,'precio_objetivo'=>round($target,2),'rango_min'=>round($min,2),'rango_max'=>round($max,2),'confianza'=>($fijo||$histN>=5)?'alta':(($histN>=2||$catalogo>0)?'media':'baja'),'comentario'=>$class==='competitivo'?'El precio está bien posicionado con las referencias disponibles.':($accion==='subir'?'Hay espacio para subir el precio sin alejarte demasiado de las referencias disponibles.':'Tu precio parece estar por encima de las referencias disponibles; conviene revisar qué incluye antes de mantenerlo.'),'razones'=>$raz,'advertencia'=>'Las referencias web pueden corresponder a piezas sin instalación, versiones distintas o promociones. Verifica compatibilidad y alcance antes de cambiar el precio.'];
}

try{
    $vehiculo=trim((string)($_POST['vehiculo']??''));$concepto=trim((string)($_POST['concepto']??''));$precio=max(0,(float)($_POST['precio']??0));$costo=max(0,(float)($_POST['costo']??0));$catalogoId=max(0,(int)($_POST['catalogo_id']??0));
    $profundo = (($_POST['profundo'] ?? '0') === '1');
    if($concepto===''||$precio<=0)throw new RuntimeException('Captura un concepto y un precio para analizarlo.');
    $pdo=srnu_db();$catalogo=null;
    if($catalogoId>0){$st=$pdo->prepare('SELECT * FROM catalogo_servicios WHERE id=? LIMIT 1');$st->execute([$catalogoId]);$catalogo=$st->fetch()?:null;}
    if(!$catalogo){$st=$pdo->prepare('SELECT * FROM catalogo_servicios WHERE activo=1 AND (nombre LIKE ? OR ? LIKE CONCAT("%",nombre,"%")) ORDER BY precio_fijo DESC,id DESC LIMIT 1');$like='%'.mb_substr($concepto,0,120).'%';$st->execute([$like,$concepto]);$catalogo=$st->fetch()?:null;}
    $catPrice=(float)($catalogo['precio_base']??0);$fixed=!empty($catalogo['precio_fijo']);$category=(string)($catalogo['categoria']??'');

    $sql='SELECT ci.precio,ci.cantidad,c.vehiculo,c.fecha,ci.concepto,ci.catalogo_id FROM cotizacion_items ci JOIN cotizaciones c ON c.id=ci.cotizacion_id WHERE ci.precio>0';$params=[];
    if($catalogoId>0){$sql.=' AND ci.catalogo_id=?';$params[]=$catalogoId;}else{$tok=ap_tokens($concepto);$needle=$tok[0]??mb_substr($concepto,0,50);$sql.=' AND ci.concepto LIKE ?';$params[]='%'.$needle.'%';}
    $sql.=' ORDER BY c.id DESC LIMIT 180';$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
    $all=[];$similar=[];foreach($rows as $r){$p=(float)$r['precio'];if($p<=0)continue;$all[]=$p;if($vehiculo!==''&&ap_similarity($vehiculo,(string)$r['vehiculo'])>=.55)$similar[]=$p;}
    $hist=$similar?:$all;$histN=count($hist);$histMedian=ap_median($hist);$histAvg=$histN?array_sum($hist)/$histN:0;$histP25=ap_percentile($hist,.25);$histP75=ap_percentile($hist,.75);

    $web=($profundo && $vehiculo!=='')?ap_web_search_cached($vehiculo,$concepto):[];$webPrices=ap_extract_prices($web);$webMedian=ap_median($webPrices);
    $fallback=ap_fallback($precio,$catPrice,$histMedian,$histN,$fixed,$costo,$webPrices);$analysis=$fallback;$engine='REGLAS + DATOS';
    if (!$profundo) $analysis['advertencia']='Evaluación rápida con catálogo e historial de SRNU. Pulsa "Consultar IA y web" para ampliar el análisis.';

    $aiState=$profundo ? srnu_ai_estado() : ['online'=>false,'model_ok'=>false];
    if(!empty($aiState['online'])&&!empty($aiState['model_ok'])){
        $evidence=[
            'vehiculo'=>$vehiculo,'concepto'=>$concepto,'precio_actual'=>$precio,'costo_interno'=>$costo,
            'catalogo'=>$catalogo?['nombre'=>$catalogo['nombre'],'precio_base'=>$catPrice,'precio_fijo'=>$fixed,'categoria'=>$category]:null,
            'historico'=>['n'=>$histN,'mediana'=>round($histMedian,2),'promedio'=>round($histAvg,2),'p25'=>round($histP25,2),'p75'=>round($histP75,2),'solo_vehiculos_similares'=>!empty($similar)],
            'web'=>array_map(fn($w)=>['titulo'=>$w['titulo']??'','snippet'=>$w['snippet']??'','url'=>$w['url']??''],$web),
            'precios_extraidos_web'=>$webPrices,
            'evaluacion_matematica'=>$fallback
        ];
        $prompt="Analiza el precio de una cotización automotriz de SRNU Custom en México. Distingue cuidadosamente pieza sola de servicio instalado. No asumas que una cifra web es comparable si el texto no lo demuestra. Da más peso a: 1) precio fijo oficial SRNU si existe, 2) historial SRNU del mismo concepto/vehículos similares, 3) catálogo, 4) web. Si la evidencia web es ambigua, no la uses para bajar/subir agresivamente. Considera el costo interno solo para comentar margen, sin revelar costos al cliente. Si el precio ya es competitivo, di mantener. Si existe precio fijo oficial, el objetivo debe ser ese precio salvo una razón muy fuerte y explícita. Evidencia: ".json_encode($evidence,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $r=srnu_ai_chat([['role'=>'system','content'=>'Eres asesor de pricing automotriz prudente. Responde solo JSON conforme al esquema. No inventes competidores, precios ni compatibilidades.'],['role'=>'user','content'=>$prompt]],ap_schema(),['temperature'=>0.1]);
        if(!empty($r['ok'])){$d=srnu_ai_json((string)$r['content']);if(is_array($d)){$analysis=array_merge($fallback,$d);$engine='QWEN + DATOS';}}
    }

    $alternatives=[];
    if($category!==''){$st=$pdo->prepare('SELECT id,nombre,precio_base,precio_fijo,tipo_entrega FROM catalogo_servicios WHERE activo=1 AND categoria=? AND id<>? ORDER BY ABS(precio_base-?) ASC LIMIT 4');$st->execute([$category,(int)($catalogo['id']??0),$precio]);$alternatives=$st->fetchAll();}
    $margin=$precio>0&&$costo>0?(($precio-$costo)/$precio*100):null;
    echo json_encode(['ok'=>true,'motor'=>$engine,'analisis'=>$analysis,'catalogo'=>$catalogo?['id'=>(int)$catalogo['id'],'nombre'=>(string)$catalogo['nombre'],'precio_base'=>$catPrice,'precio_fijo'=>$fixed,'categoria'=>$category]:null,'historico'=>['n'=>$histN,'mediana'=>round($histMedian,2),'promedio'=>round($histAvg,2),'p25'=>round($histP25,2),'p75'=>round($histP75,2),'similares'=>count($similar)],'margen_bruto'=>$margin===null?null:round($margin,1),'web'=>['n'=>count($web),'mediana_extraida'=>round($webMedian,2),'resultados'=>array_slice($web,0,5)],'alternativas'=>$alternatives],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
