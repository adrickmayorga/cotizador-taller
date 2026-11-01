<?php
declare(strict_types=1);
require_once __DIR__.'/config/erp.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Content-Type: application/json; charset=utf-8');

function am_norm(string $s): string {
    $s=mb_strtolower(trim($s),'UTF-8');
    $s=strtr($s,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $s=preg_replace('/[^a-z0-9]+/u',' ',$s)??$s;
    return trim(preg_replace('/\s+/',' ',$s)??$s);
}
function am_tokens(string $s): array {
    $stop=['auto','carro','camioneta','pickup','pick','up','suv','sedan','coupe','modelo','version','motor','del','de','la','el','y','x','4x4','4x2'];
    $out=[];foreach(preg_split('/\s+/u',am_norm($s))?:[] as $w){if($w===''||preg_match('/^(19|20)\d{2}$/',$w)||in_array($w,$stop,true))continue;if(mb_strlen($w)<2)continue;$out[]=$w;}
    return array_values(array_unique($out));
}
function am_similarity(string $a,string $b): float {
    $A=am_tokens($a);$B=am_tokens($b);if(!$A||!$B)return 0.0;$inter=count(array_intersect($A,$B));$den=max(1,min(count($A),count($B)));$score=$inter/$den;
    $na=am_norm(preg_replace('/\b(19|20)\d{2}\b/','',$a)??$a);$nb=am_norm(preg_replace('/\b(19|20)\d{2}\b/','',$b)??$b);if($na!==''&&$nb!==''&&($na===$nb||str_contains($na,$nb)||str_contains($nb,$na)))$score=max($score,.95);
    return min(1,$score);
}
function am_history(string $vehiculo): array {
    $pdo=srnu_db();$rows=$pdo->query("SELECT id,folio,cliente,vehiculo FROM cotizaciones ORDER BY id DESC LIMIT 1000")->fetchAll();$ids=[];$scores=[];$matches=[];
    foreach($rows as $r){$sc=am_similarity($vehiculo,(string)$r['vehiculo']);if($sc>=.55){$id=(int)$r['id'];$ids[]=$id;$scores[$id]=$sc;$matches[]=['id'=>$id,'folio'=>(string)$r['folio'],'cliente'=>(string)$r['cliente'],'vehiculo'=>(string)$r['vehiculo'],'score'=>$sc];}}
    if(!$ids)return ['vehiculos_similares'=>0,'cotizaciones'=>0,'servicios'=>[],'clientes'=>[]];
    $ids=array_slice($ids,0,120);$ph=implode(',',array_fill(0,count($ids),'?'));
    $st=$pdo->prepare("SELECT COALESCE(NULLIF(ci.catalogo_id,0),0) catalogo_id,ci.concepto,COUNT(*) veces,SUM(ci.cantidad) unidades,MAX(c.vehiculo) vehiculo FROM cotizacion_items ci JOIN cotizaciones c ON c.id=ci.cotizacion_id WHERE ci.cotizacion_id IN ($ph) GROUP BY catalogo_id,ci.concepto ORDER BY veces DESC,unidades DESC LIMIT 12");$st->execute($ids);$services=[];foreach($st as $r)$services[]=['catalogo_id'=>(int)$r['catalogo_id'],'concepto'=>(string)$r['concepto'],'veces'=>(int)$r['veces'],'unidades'=>(float)$r['unidades']];
    usort($matches,fn($a,$b)=>($b['score']<=>$a['score'])?:($b['id']<=>$a['id']));$clients=[];
    foreach(array_slice($matches,0,6) as $m){$it=$pdo->prepare('SELECT concepto FROM cotizacion_items WHERE cotizacion_id=? ORDER BY id LIMIT 5');$it->execute([$m['id']]);$m['servicios']=array_map('strval',$it->fetchAll(PDO::FETCH_COLUMN));unset($m['score']);$m['url']='trabajo.php?id='.$m['id'];$clients[]=$m;}
    return ['vehiculos_similares'=>count($ids),'cotizaciones'=>count($ids),'servicios'=>$services,'clientes'=>$clients];
}
function am_ddg_decode_url(string $href): string {
    $href=html_entity_decode($href,ENT_QUOTES|ENT_HTML5,'UTF-8');
    if(str_starts_with($href,'//'))$href='https:'.$href;
    $q=parse_url($href,PHP_URL_QUERY);if($q){parse_str($q,$p);if(!empty($p['uddg']))return urldecode((string)$p['uddg']);}
    return $href;
}
function am_web_search(string $q,int $limit=7): array {
    $url='https://html.duckduckgo.com/html/?q='.rawurlencode($q);$raw='';
    if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>7,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_USERAGENT=>'Mozilla/5.0 SRNU-Custom-ERP/1.0']);$x=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($x!==false&&$code>=200&&$code<400)$raw=(string)$x;}
    if($raw===''&&filter_var(ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN)){$ctx=stream_context_create(['http'=>['timeout'=>7,'header'=>"User-Agent: Mozilla/5.0 SRNU-Custom-ERP/1.0\r\n"]]);$x=@file_get_contents($url,false,$ctx);if($x!==false)$raw=(string)$x;}
    if($raw==='')return [];$out=[];
    if(preg_match_all('/<a[^>]+class="[^"]*result__a[^"]*"[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si',$raw,$m,PREG_SET_ORDER)){
        foreach($m as $i=>$x){$title=trim(html_entity_decode(strip_tags($x[2]),ENT_QUOTES|ENT_HTML5,'UTF-8'));$u=am_ddg_decode_url($x[1]);if($title===''||!preg_match('#^https?://#i',$u))continue;$snippet='';$pos=strpos($raw,$x[0]);if($pos!==false){$chunk=substr($raw,$pos,2500);if(preg_match('/class="[^"]*result__snippet[^"]*"[^>]*>(.*?)<\//si',$chunk,$sm))$snippet=trim(html_entity_decode(strip_tags($sm[1]),ENT_QUOTES|ENT_HTML5,'UTF-8'));}$out[]=['titulo'=>$title,'url'=>$u,'snippet'=>$snippet];if(count($out)>=$limit)break;}
    }
    return $out;
}
function am_market_links(string $query): array {
    $q=trim($query);return [
        'mercadolibre'=>'https://listado.mercadolibre.com.mx/'.rawurlencode($q),
        'amazon'=>'https://www.amazon.com.mx/s?k='.rawurlencode($q),
        'google'=>'https://www.google.com/search?q='.rawurlencode($q.' comprar México'),
    ];
}
function am_schema(): array {
    return ['type'=>'object','additionalProperties'=>false,'properties'=>[
        'resumen'=>['type'=>'string'],
        'recomendaciones'=>['type'=>'array','maxItems'=>6,'items'=>['type'=>'object','additionalProperties'=>false,'properties'=>[
            'catalogo_id'=>['type'=>'integer'],'motivo'=>['type'=>'string'],'prioridad'=>['type'=>'string','enum'=>['alta','media','baja']],'compatibilidad'=>['type'=>'string'],'fuente'=>['type'=>'string','enum'=>['Historial SRNU','Tendencia web','Historial + web','IA local']]
        ],'required'=>['catalogo_id','motivo','prioridad','compatibilidad','fuente']]],
        'productos_aftermarket'=>['type'=>'array','maxItems'=>6,'items'=>['type'=>'object','additionalProperties'=>false,'properties'=>[
            'nombre'=>['type'=>'string'],'busqueda_compra'=>['type'=>'string'],'motivo'=>['type'=>'string'],'compatibilidad'=>['type'=>'string'],'fuente'=>['type'=>'string','enum'=>['Tendencia web','Historial SRNU','IA local','Historial + web']]
        ],'required'=>['nombre','busqueda_compra','motivo','compatibilidad','fuente']]],
    ],'required'=>['resumen','recomendaciones','productos_aftermarket']];
}
function am_fallback_products(string $v): array {
    $n=am_norm($v);$p=[];
    if(preg_match('/tacoma|ram|silverado|sierra|frontier|ranger|f150|lobo|hilux|hunter|1200/',$n))$p=['tapa de batea específica','tapetes todo clima','dashcam delantera y trasera','estribos laterales','luces de caja LED'];
    elseif(preg_match('/golf|jetta|gli|leon|audi|bmw|mini|mercedes/',$n))$p=['dashcam discreta','tapetes todo clima','pedales deportivos','spoiler o lip específico','módulo inalámbrico CarPlay'];
    else $p=['dashcam delantera y trasera','tapetes todo clima','organizador de cajuela','módulo inalámbrico CarPlay','cámara de reversa AHD'];
    return array_map(fn($x)=>['nombre'=>ucfirst($x),'busqueda_compra'=>$v.' '.$x,'motivo'=>'Accesorio aftermarket común para mejorar uso, protección o equipamiento.','compatibilidad'=>'Confirmar año, versión y medidas antes de comprar.','fuente'=>'IA local'],$p);
}
try{
    $vehiculo=trim((string)($_POST['vehiculo']??''));if($vehiculo==='')throw new RuntimeException('Escribe primero el vehículo.');
    $existentes=json_decode((string)($_POST['existentes']??'[]'),true);if(!is_array($existentes))$existentes=[];
    $catalogo=srnu_catalogo_activo();$hist=am_history($vehiculo);
    $web1=am_web_search($vehiculo.' aftermarket mods accessories owners forum',5);$web2=am_web_search($vehiculo.' accesorios modificaciones comunes propietarios',4);$web=[];$seen=[];foreach(array_merge($web1,$web2) as $w){$k=$w['url']??'';if($k&&empty($seen[$k])){$seen[$k]=1;$web[]=$w;}if(count($web)>=7)break;}
    $compact=[];$byId=[];foreach($catalogo as $c){$id=(int)($c['id']??0);if(!$id)continue;$byId[$id]=$c;$compact[]=['id'=>$id,'nombre'=>(string)$c['nombre'],'categoria'=>(string)($c['categoria']??''),'precio'=>(float)($c['precio_base']??0),'descripcion'=>mb_substr((string)($c['descripcion']??''),0,250),'tipo_entrega'=>(string)($c['tipo_entrega']??'inmediata')];}
    $ai=null;$st=srnu_ai_estado();if(!empty($st['online'])&&!empty($st['model_ok'])){
        $prompt="Vehículo: {$vehiculo}\nServicios ya agregados: ".json_encode($existentes,JSON_UNESCAPED_UNICODE)."\nHistorial SRNU agregado (sin datos personales): ".json_encode($hist,JSON_UNESCAPED_UNICODE)."\nResultados web públicos: ".json_encode($web,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\nCatálogo SRNU permitido: ".json_encode($compact,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\nActúa como asesor aftermarket automotriz prudente en México. Cruza el historial real de SRNU con lo que muestran las fuentes web. Recomienda hasta 6 servicios del catálogo, SOLO usando catalogo_id existente, y hasta 6 productos aftermarket externos que propietarios de este vehículo suelen buscar. No inventes compatibilidad; indica qué debe verificarse. No inventes precios externos. En busqueda_compra escribe una búsqueda concreta para México incluyendo vehículo y producto. No recomiendes servicios que ya estén agregados. Distingue la fuente de cada conclusión. Si la web está vacía, usa IA local y dilo en fuente.";
        $r=srnu_ai_chat([['role'=>'system','content'=>'Analiza aftermarket con prudencia. Respeta evidencia, catálogo y compatibilidad. Responde únicamente JSON.'],['role'=>'user','content'=>$prompt]],am_schema(),['temperature'=>0.15]);if(!empty($r['ok']))$ai=srnu_ai_json((string)$r['content']);
    }
    $recs=[];$existingNorm=array_map('am_norm',$existentes);
    if(is_array($ai))foreach((array)($ai['recomendaciones']??[]) as $rec){$id=(int)($rec['catalogo_id']??0);if(!isset($byId[$id]))continue;$c=$byId[$id];$nn=am_norm((string)$c['nombre']);$dup=false;foreach($existingNorm as $e)if($e!==''&&(str_contains($e,$nn)||str_contains($nn,$e))){$dup=true;break;}if($dup)continue;$recs[]=['servicio'=>$c,'motivo'=>trim((string)($rec['motivo']??'')),'prioridad'=>(string)($rec['prioridad']??'media'),'compatibilidad'=>trim((string)($rec['compatibilidad']??'')),'fuente'=>(string)($rec['fuente']??'IA local')];if(count($recs)>=6)break;}
    if(!$recs){$base=srnu_ai_recomendar_servicios($vehiculo,$catalogo,$existentes);foreach((array)($base['recomendaciones']??[]) as $r){$r['fuente']=$hist['vehiculos_similares']?'Historial SRNU':'IA local';$recs[]=$r;}}
    $products=[];$rawProducts=is_array($ai)?(array)($ai['productos_aftermarket']??[]):am_fallback_products($vehiculo);if(!$rawProducts)$rawProducts=am_fallback_products($vehiculo);
    foreach($rawProducts as $p){$q=trim((string)($p['busqueda_compra']??''));if($q==='')$q=$vehiculo.' '.trim((string)($p['nombre']??''));$products[]=['nombre'=>trim((string)($p['nombre']??'Producto aftermarket')),'motivo'=>trim((string)($p['motivo']??'')),'compatibilidad'=>trim((string)($p['compatibilidad']??'')),'fuente'=>(string)($p['fuente']??($web?'Tendencia web':'IA local')),'links'=>am_market_links($q)];if(count($products)>=6)break;}
    $summary=is_array($ai)&&trim((string)($ai['resumen']??''))!==''?trim((string)$ai['resumen']):($hist['vehiculos_similares']?"Encontré {$hist['vehiculos_similares']} cotizaciones SRNU de vehículos similares. Combino ese historial con recomendaciones generales".($web?' y resultados web públicos.':'.'):('Todavía hay poco historial SRNU de este vehículo; las sugerencias se apoyan principalmente en la IA local'.($web?' y resultados web públicos.':'.')));
    echo json_encode(['ok'=>true,'motor'=>is_array($ai)?'QWEN + WEB':'LOCAL + WEB','resumen'=>$summary,'historial'=>$hist,'recomendaciones'=>$recs,'productos_aftermarket'=>$products,'web_resultados'=>$web],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
