<?php
/** SRNU Custom iOS API v1. Copy this file to the same directory as config/ in the existing SRNU ERP. */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

const API_VERSION = '1.0.0';
const TOKEN_LIFETIME_DAYS = 14;
const LIMIT_PAGE = 100;

function response(array $body, int $status = 200): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function fail(string $error, int $code = 400): never { response(['ok'=>false, 'error'=>$error], $code); }
function input(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 600000) fail('Solicitud demasiado grande.', 413);
    $decoded = json_decode($raw ?: '{}', true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) fail('JSON inválido.', 400);
    return $decoded;
}
function strval_safe(mixed $v, int $max = 220): string {
    if (!is_string($v) && !is_numeric($v)) return '';
    return function_exists('mb_substr') ? mb_substr(trim((string)$v), 0, $max) : substr(trim((string)$v), 0, $max);
}
function money(mixed $v, float $max = 100000000): float {
    if (!is_numeric($v)) fail('Importe inválido.', 422);
    $n = (float)$v;
    if (!is_finite($n) || $n < 0 || $n > $max) fail('Importe fuera de rango.', 422);
    return round($n, 2);
}
function normalizar_tel(string $t): string {
    $n = preg_replace('/\D+/', '', $t) ?? '';
    return strlen($n) === 12 && str_starts_with($n, '52') ? substr($n, 2) : $n;
}
function db(): PDO {
    static $db = null;
    if ($db instanceof PDO) return $db;
    // Reutiliza las credenciales del ERP solamente en el servidor. No se entregan a la app.
    $local = __DIR__ . '/config/database.local.php';
    if (is_file($local)) require_once $local;
    $host = defined('SRNU_DB_HOST') ? SRNU_DB_HOST : (getenv('SRNU_DB_HOST') ?: '127.0.0.1');
    $name = defined('SRNU_DB_NAME') ? SRNU_DB_NAME : (getenv('SRNU_DB_NAME') ?: 'srnu_cotizador');
    $user = defined('SRNU_DB_USER') ? SRNU_DB_USER : (getenv('SRNU_DB_USER') ?: 'root');
    $pass = defined('SRNU_DB_PASS') ? SRNU_DB_PASS : (getenv('SRNU_DB_PASS') ?: '');
    $db = new PDO('mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4', $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $db;
}
function require_method(string $expected): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $expected) fail('Método no permitido.', 405);
}
function require_https(): void {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    // Se confía en cabeceras X-Forwarded-Proto solamente desde un proxy local.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (in_array($ip, ['127.0.0.1', '::1'], true) &&
         strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https');
    if (!$https && !in_array($ip, ['127.0.0.1','::1'], true)) fail('La API requiere HTTPS.', 403);
}
function token(): string {
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k=>$v) if (strtolower($k) === 'authorization') $header = (string)$v;
    }
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/i', $header, $m)) fail('Sesión no autorizada.', 401);
    return strtolower($m[1]);
}
function auth(): array {
    $hash = hash('sha256', token());
    $st = db()->prepare('SELECT u.id, u.nombre, u.usuario, u.rol, t.id AS token_id FROM mobile_api_tokens t JOIN usuarios u ON u.id=t.user_id WHERE t.token_hash=? AND t.expires_at>NOW() AND u.activo=1 LIMIT 1');
    $st->execute([$hash]);
    $user = $st->fetch();
    if (!$user) fail('Sesión vencida. Vuelve a iniciar sesión.', 401);
    return $user;
}
function admin(array $user): void { if (($user['rol'] ?? '') !== 'admin') fail('Acceso restringido.', 403); }
function clean_quote(array $q): array {
    foreach (['id','cliente_id'] as $k) if (isset($q[$k])) $q[$k] = (int)$q[$k];
    foreach (['subtotal','iva','total','anticipo_valor','anticipo_recibido_monto','anticipo_sergio','anticipo_ricardo','anticipo_tomas','pagado'] as $k)
        if (isset($q[$k])) $q[$k] = (float)$q[$k];
    foreach (['participa_sergio','participa_ricardo','participa_tomas','conservar_anticipo_recibido'] as $k)
        if (isset($q[$k])) $q[$k] = (bool)$q[$k];
    if (isset($q['msi_plazos'])) $q['msi_plazos'] = json_decode((string)$q['msi_plazos'], true) ?: [];
    $q['saldo'] = round(max(0, (float)$q['total'] - (float)($q['pagado'] ?? 0)),2);
    return $q;
}
function editable_in_ios(int $id, array $q, array $items): bool {
    if (!in_array((string)($q['estado']??''), ['Cotización','Aprobada'], true)) return false;
    if (!empty($q['conservar_anticipo_recibido']) || !empty($q['dividir_anticipo']) || !empty($q['repartir_igual'])) return false;
    foreach ($items as $i) {
        if ((float)($i['costo_interno']??0) > 0.001 || !empty($i['catalogo_id']) ||
            !empty($i['foto_producto']) || (int)($i['dias_trabajo_min']??0)>0 ||
            (int)($i['dias_trabajo_max']??0)>0) return false;
    }
    $s=db()->prepare("SELECT
        (SELECT COUNT(*) FROM pagos WHERE cotizacion_id=?) AS pagos,
        (SELECT COUNT(*) FROM garantias WHERE cotizacion_id=?) AS garantias,
        (SELECT COUNT(*) FROM reparto_ajustes WHERE cotizacion_id=?) AS ajustes,
        (SELECT COUNT(*) FROM firmas_digitales WHERE cotizacion_id=? AND estado='firmada') AS firmas_cliente,
        (SELECT COUNT(*) FROM firmas_taller WHERE cotizacion_id=? AND estado='firmada') AS firmas_taller,
        (SELECT COUNT(*) FROM confirmaciones_locales WHERE cotizacion_id=? AND estado='confirmada') AS confirmaciones");
    $s->execute([$id,$id,$id,$id,$id,$id]);
    return array_sum(array_map('intval',$s->fetch()?:[]))===0;
}
function load_quote(int $id): array {
    $s = db()->prepare('SELECT c.*, (SELECT COALESCE(SUM(p.monto),0) FROM pagos p WHERE p.cotizacion_id=c.id) AS pagado FROM cotizaciones c WHERE id=?');
    $s->execute([$id]); $q = $s->fetch();
    if (!$q) fail('Cotización no encontrada.', 404);
    $s=db()->prepare('SELECT id,concepto,cantidad,precio,total,responsable_sergio,responsable_ricardo,responsable_tomas,garantia_meses,catalogo_id,tipo_entrega,dias_entrega_estimada,dias_trabajo_min,dias_trabajo_max FROM cotizacion_items WHERE cotizacion_id=? ORDER BY id');
    $s->execute([$id]);
    $editRows=db()->prepare('SELECT costo_interno,catalogo_id,foto_producto,dias_trabajo_min,dias_trabajo_max FROM cotizacion_items WHERE cotizacion_id=?');
    $editRows->execute([$id]);
    $q['editable_ios']=editable_in_ios($id,$q,$editRows->fetchAll());
    $q=clean_quote($q);
    $q['items'] = array_map(function($i) {
        foreach (['id','garantia_meses','catalogo_id','dias_entrega_estimada','dias_trabajo_min','dias_trabajo_max'] as $k) if (isset($i[$k])) $i[$k]=(int)$i[$k];
        foreach (['cantidad','precio','total'] as $k) $i[$k]=(float)$i[$k];
        foreach (['responsable_sergio','responsable_ricardo','responsable_tomas'] as $k) $i[$k]=(bool)$i[$k];
        return $i;
    },$s->fetchAll());
    return $q;
}
/** Tasas legadas tomadas de cotizador.php al 12/10/2026; confirmar con los proveedores antes de cobrar. */
function financing(float $subtotal, bool $invoice): array {
    $bbvaRequired = $subtotal > 50000;
    $invoice = $invoice || $bbvaRequired;
    $total = round($subtotal * ($invoice ? 1.16 : 1), 2);
    $clipFees = [3=>.0457, 6=>.0757, 9=>.1107, 12=>.1277, 18=>.1927, 24=>.2717];
    $bbvaFees = [3=>.0557, 6=>.0949, 9=>.1319, 12=>.1670];
    $clip = []; $bbva = [];
    foreach ($clipFees as $term=>$extra) {
        $rate = .0299 + $extra;
        $amount = ceil((($total + 1) / (1 - ($rate * 1.16))) * 100) / 100;
        $clip[] = ['months'=>$term,'total'=>round($amount,2),'monthly'=>round($amount/$term,2),'available'=>!$bbvaRequired && $amount<=50000,'rate'=>$rate];
    }
    foreach ($bbvaFees as $term=>$extra) {
        $rate=.0250+$extra;
        $amount=ceil(($total/(1-$rate))/10)*10;
        $bbva[] = ['months'=>$term,'total'=>round($amount,2),'monthly'=>round($amount/$term,2),'available'=>$bbvaRequired,'rate'=>$rate];
    }
    $oneClip=ceil((($total+1)/(1-(.0299*1.16)))*100)/100;
    $oneBBVA=ceil(($total/(1-.0250))*100)/100;
    return ['subtotal'=>$subtotal,'invoice'=>$invoice,'iva'=>round($total-$subtotal,2),'total'=>$total,
        'required_provider'=>$bbvaRequired?'BBVA PyME':'CLIP',
        'clip'=>['single'=>round($oneClip,2),'installments'=>$clip],
        'bbva'=>['single'=>round($oneBBVA,2),'installments'=>$bbva],
        'rate_notice'=>'Tasas de referencia del cotizador existente; verificar vigencia con CLIP y BBVA antes de cobrar.'];
}
function validated_items(mixed $provided): array {
    if (!is_array($provided) || count($provided)<1 || count($provided)>80) fail('Agrega de 1 a 80 conceptos.',422);
    $items=[];
    foreach ($provided as $i) {
        if (!is_array($i)) fail('Concepto inválido.',422);
        $title=strval_safe($i['concepto']??'',500);
        $qty=(float)($i['cantidad']??0); $price=money($i['precio']??0);
        if ($title==='' || !is_finite($qty) || $qty<=0 || $qty>999) fail('Revisa conceptos, precios y cantidades.',422);
        $qty=round($qty,2);
        $total=round($qty*$price,2);
        $items[]=['concepto'=>$title,'cantidad'=>$qty,'precio'=>$price,'total'=>$total,
            'responsable_sergio'=>!empty($i['responsable_sergio'])?1:0,
            'responsable_ricardo'=>!empty($i['responsable_ricardo'])?1:0,
            'responsable_tomas'=>!empty($i['responsable_tomas'])?1:0,
            'garantia_meses'=>min(120,max(0,(int)($i['garantia_meses']??0))),
            'tipo_entrega'=>($i['tipo_entrega']??'inmediata')==='sobre_pedido'?'sobre_pedido':'inmediata',
            'dias_entrega_estimada'=>min(365,max(0,(int)($i['dias_entrega_estimada']??0)))];
    }
    return $items;
}
function client_upsert(PDO $pdo, string $nombre, string $telefono, ?int $id = null): ?int {
    $tel=normalizar_tel($telefono); if($tel==='') return null;
    if($id) {
        $st=$pdo->prepare('SELECT id FROM clientes WHERE id=?');$st->execute([$id]);
        if(!$st->fetchColumn()) fail('Cliente no encontrado.',404);
        $pdo->prepare('UPDATE clientes SET nombre=?, telefono=?, telefono_normalizado=?,activo=1 WHERE id=?')->execute([$nombre,$telefono,$tel,$id]);
        return $id;
    }
    $st=$pdo->prepare('SELECT id FROM clientes WHERE telefono_normalizado=? LIMIT 1');$st->execute([$tel]);
    $existing=(int)($st->fetchColumn()?:0);
    if($existing) {
        $pdo->prepare('UPDATE clientes SET nombre=?,telefono=?,activo=1 WHERE id=?')->execute([$nombre,$telefono,$existing]);
        return $existing;
    }
    $pdo->prepare('INSERT INTO clientes(nombre,telefono,telefono_normalizado,activo) VALUES(?,?,?,1)')->execute([$nombre,$telefono,$tel]);
    return (int)$pdo->lastInsertId();
}
function save_quote(array $input, int $id = 0): array {
    $cliente=strval_safe($input['cliente']??'',180);
    $telefono=strval_safe($input['telefono']??'',40);
    $vehiculo=strval_safe($input['vehiculo']??'',180);
    if ($cliente==='' || $vehiculo==='') fail('Cliente y vehículo son obligatorios.',422);
    $items=validated_items($input['items']??[]);
    $subtotal=round(array_sum(array_column($items,'total')),2);
    if($subtotal<=0) fail('El total debe ser mayor que cero.',422);
    $prices=financing($subtotal,!empty($input['requiere_factura']));
    $anticipoTipo=($input['anticipo_tipo']??'porcentaje')==='cantidad'?'cantidad':'porcentaje';
    $anticipoValor=money($input['anticipo_valor']??70);
    if($anticipoTipo==='porcentaje' && $anticipoValor>100) fail('El porcentaje máximo es 100.',422);
    if($anticipoTipo==='cantidad' && $anticipoValor>$subtotal) fail('El anticipo no puede exceder el subtotal.',422);
    $advance=$anticipoTipo==='porcentaje'?round($subtotal*$anticipoValor/100,2):$anticipoValor;
    $pdo=db();$pdo->beginTransaction();
    try {
        if($id) {
            $s=$pdo->prepare('SELECT * FROM cotizaciones WHERE id=? FOR UPDATE');$s->execute([$id]);$previous=$s->fetch();
            if(!$previous) fail('Cotización no encontrada.',404);
            $st=$pdo->prepare('SELECT * FROM cotizacion_items WHERE cotizacion_id=? ORDER BY id');$st->execute([$id]);
            $previous['items']=$st->fetchAll();
            if (!editable_in_ios($id,$previous,$previous['items'])) {
                fail('Esta cotización tiene pagos, firmas, componentes vinculados o ajustes avanzados. Edítala desde el ERP web para proteger sus datos.',409);
            }
            $vers=$pdo->prepare('SELECT COALESCE(MAX(version_no),0)+1 FROM cotizacion_versiones WHERE cotizacion_id=?');$vers->execute([$id]);
            $pdo->prepare('INSERT INTO cotizacion_versiones(cotizacion_id,version_no,snapshot_json,motivo) VALUES(?,?,?,?)')
                ->execute([$id,(int)$vers->fetchColumn(),json_encode($previous,JSON_UNESCAPED_UNICODE),'Edición desde iOS']);
            $paid=$pdo->prepare('SELECT COALESCE(SUM(monto),0) FROM pagos WHERE cotizacion_id=?');$paid->execute([$id]);
            if ((float)$paid->fetchColumn() > $prices['total'] + .001) fail('El nuevo total no puede ser menor que los pagos registrados.',422);
            $pdo->prepare('UPDATE cotizaciones SET cliente=?,telefono=?,vehiculo=?,requiere_factura=?,subtotal=?,iva=?,total=?,msi_plazos=?,anticipo_tipo=?,anticipo_valor=?,updated_at=NOW() WHERE id=?')
                ->execute([$cliente,$telefono,$vehiculo,$prices['invoice']?'si':'no',$subtotal,$prices['iva'],$prices['total'],json_encode(['tarjeta_1x','3','6','9','12','18','24',...($prices['required_provider']==='BBVA PyME'?['bbva_pyme']:[])]),$anticipoTipo,$anticipoValor,$id]);
            $pdo->prepare('DELETE FROM cotizacion_items WHERE cotizacion_id=?')->execute([$id]);
        } else {
            $clientId=client_upsert($pdo,$cliente,$telefono);
            $tmp='TMP-'.bin2hex(random_bytes(6));
            $pdo->prepare("INSERT INTO cotizaciones(folio,cliente,telefono,cliente_id,vehiculo,fecha,requiere_factura,subtotal,iva,total,msi_plazos,anticipo_tipo,anticipo_valor,estado,vigencia_dias,created_at,updated_at) VALUES(?,?,?,?,?,CURDATE(),?,?,?,?,?,?,?,'Cotización',15,NOW(),NOW())")
                ->execute([$tmp,$cliente,$telefono,$clientId,$vehiculo,$prices['invoice']?'si':'no',$subtotal,$prices['iva'],$prices['total'],json_encode(['tarjeta_1x','3','6','9','12','18','24',...($prices['required_provider']==='BBVA PyME'?['bbva_pyme']:[])]),$anticipoTipo,$anticipoValor]);
            $id=(int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE cotizaciones SET folio=? WHERE id=?')->execute(['SRNU-'.date('Y').'-'.str_pad((string)$id,5,'0',STR_PAD_LEFT),$id]);
        }
        $ins=$pdo->prepare('INSERT INTO cotizacion_items(cotizacion_id,concepto,cantidad,precio,total,responsable_sergio,responsable_ricardo,responsable_tomas,garantia_meses,tipo_entrega,dias_entrega_estimada) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        foreach($items as $i) $ins->execute([$id,$i['concepto'],$i['cantidad'],$i['precio'],$i['total'],$i['responsable_sergio'],$i['responsable_ricardo'],$i['responsable_tomas'],$i['garantia_meses'],$i['tipo_entrega'],$i['dias_entrega_estimada']]);
        $pdo->commit();
    } catch (Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['quote'=>load_quote($id),'financing'=>$prices,'advance'=>$advance];
}

try {
    require_https();
    $route=trim((string)($_GET['route']??''),'/');
    $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
    if($route==='health') {require_method('GET');response(['ok'=>true,'version'=>API_VERSION]);}
    if($route==='login') {
        require_method('POST');$data=input();
        $username=strval_safe($data['username']??'',80);$password=(string)($data['password']??'');
        if($username===''||$password==='') fail('Ingresa usuario y contraseña.',422);
        $pdo=db();$ip=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'));
        $a=$pdo->prepare('SELECT attempts,last_attempt FROM mobile_login_attempts WHERE ip_hash=?');$a->execute([$ip]);$attempt=$a->fetch();
        if($attempt && (int)$attempt['attempts']>=8 && strtotime((string)$attempt['last_attempt'])>time()-900) fail('Demasiados intentos. Intenta de nuevo más tarde.',429);
        $s=$pdo->prepare('SELECT id,nombre,usuario,rol,password_hash FROM usuarios WHERE usuario=? AND activo=1 LIMIT 1');$s->execute([$username]);$u=$s->fetch();
        if(!$u || !password_verify($password,(string)$u['password_hash'])) {
            $pdo->prepare('INSERT INTO mobile_login_attempts(ip_hash,attempts,last_attempt) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(last_attempt<DATE_SUB(NOW(),INTERVAL 15 MINUTE),1,attempts+1),last_attempt=NOW()')->execute([$ip]);
            fail('Usuario o contraseña incorrectos.',401);
        }
        $pdo->prepare('DELETE FROM mobile_login_attempts WHERE ip_hash=?')->execute([$ip]);
        $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);
        $pdo->prepare('INSERT INTO mobile_api_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 14 DAY))')->execute([(int)$u['id'],$hash]);
        $pdo->prepare('UPDATE usuarios SET ultimo_acceso=NOW() WHERE id=?')->execute([(int)$u['id']]);
        unset($u['password_hash']);$u['id']=(int)$u['id'];
        response(['ok'=>true,'token'=>$raw,'user'=>$u]);
    }
    $user=auth();
    if($route==='me') {require_method('GET');unset($user['token_id']);$user['id']=(int)$user['id'];response(['ok'=>true,'data'=>$user]);}
    if($route==='logout') {require_method('POST');$s=db()->prepare('DELETE FROM mobile_api_tokens WHERE id=?');$s->execute([(int)$user['token_id']]);response(['ok'=>true]);}
    if($route==='dashboard') {
        require_method('GET');$p=db();
        $counts=['quotes'=>(int)$p->query('SELECT COUNT(*) FROM cotizaciones')->fetchColumn(),
            'clients'=>(int)$p->query('SELECT COUNT(*) FROM clientes WHERE activo=1')->fetchColumn(),
            'pending'=>(int)$p->query("SELECT COUNT(*) FROM cotizaciones WHERE estado IN ('Cotización','Aprobada','Anticipo recibido','En proceso')")->fetchColumn(),
            'payments_this_month'=>(float)$p->query("SELECT COALESCE(SUM(monto),0) FROM pagos WHERE fecha>=DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetchColumn()];
        response(['ok'=>true,'data'=>$counts]);
    }
    if($route==='clients' && $method==='GET') {
        $q='%'.strval_safe($_GET['q']??'',100).'%';
        $s=db()->prepare('SELECT id,nombre,telefono,email,notas FROM clientes WHERE activo=1 AND (nombre LIKE ? OR telefono LIKE ?) ORDER BY nombre LIMIT 100');$s->execute([$q,$q]);
        response(['ok'=>true,'data'=>array_map(fn($c)=>array_merge($c,['id'=>(int)$c['id']]),$s->fetchAll())]);
    }
    if($route==='clients' && $method==='POST') {
        admin($user);$b=input();$name=strval_safe($b['nombre']??'',180);$phone=strval_safe($b['telefono']??'',60);
        if($name===''||normalizar_tel($phone)==='') fail('Nombre y teléfono requeridos.',422);
        $id=client_upsert(db(),$name,$phone);response(['ok'=>true,'id'=>$id],201);
    }
    if($route==='catalog' && $method==='GET') {
        $s=db()->query('SELECT id,nombre,precio_base,responsable_sergio,responsable_ricardo,responsable_tomas,garantia_meses FROM catalogo_servicios WHERE activo=1 ORDER BY nombre LIMIT 500');
        $data=array_map(function($i) {foreach(['id','garantia_meses'] as $k)$i[$k]=(int)$i[$k];$i['precio_base']=(float)$i['precio_base'];foreach(['responsable_sergio','responsable_ricardo','responsable_tomas'] as $k)$i[$k]=(bool)$i[$k];return $i;},$s->fetchAll());
        response(['ok'=>true,'data'=>$data]);
    }
    if($route==='financing' && $method==='POST') {
        $b=input();$subtotal=0;
        if(isset($b['items'])) foreach(validated_items($b['items']) as $i) $subtotal+=$i['total'];
        else $subtotal=money($b['subtotal']??0);
        response(['ok'=>true,'data'=>financing(round($subtotal,2),!empty($b['requiere_factura']))]);
    }
    if($route==='quotes' && $method==='GET') {
        $q='%'.strval_safe($_GET['q']??'',100).'%';
        $s=db()->prepare('SELECT c.id,c.folio,c.cliente,c.vehiculo,c.estado,c.fecha,c.requiere_factura,c.total,COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.cotizacion_id=c.id),0) AS pagado FROM cotizaciones c WHERE c.folio LIKE ? OR c.cliente LIKE ? OR c.vehiculo LIKE ? OR c.telefono LIKE ? ORDER BY c.id DESC LIMIT 100');$s->execute([$q,$q,$q,$q]);
        response(['ok'=>true,'data'=>array_map('clean_quote',$s->fetchAll())]);
    }
    if($route==='quotes' && $method==='POST') {admin($user);response(['ok'=>true,'data'=>save_quote(input())],201);}
    if(preg_match('~^quotes/(\d+)$~',$route,$m)) {
        $id=(int)$m[1];
        if($method==='GET') response(['ok'=>true,'data'=>load_quote($id)]);
        if($method==='PUT') {admin($user);response(['ok'=>true,'data'=>save_quote(input(),$id)]);}
    }
    if(preg_match('~^quotes/(\d+)/payments$~',$route,$m)) {
        $id=(int)$m[1];
        if($method==='GET') {
            load_quote($id);$s=db()->prepare('SELECT id,fecha,metodo,monto,destinatario,notas FROM pagos WHERE cotizacion_id=? ORDER BY id DESC LIMIT 150');$s->execute([$id]);
            $r=array_map(function($p){$p['id']=(int)$p['id'];$p['monto']=(float)$p['monto'];return $p;},$s->fetchAll());response(['ok'=>true,'data'=>$r]);
        }
        if($method==='POST') {
            admin($user);$b=input();$amount=money($b['monto']??0);if($amount<.01)fail('El pago debe ser mayor que cero.',422);
            $methodPay=strval_safe($b['metodo']??'Transferencia',50);
            if(!in_array($methodPay,['Transferencia','Efectivo','Tarjeta','Otro'],true))fail('Método inválido.',422);
            $destination=strval_safe($b['destinatario']??'General',30);
            if(!in_array($destination,['General','Sergio','Ricardo','Tomás','IVA'],true))fail('Destinatario inválido.',422);
            $pdo=db();$pdo->beginTransaction();try {
                $s=$pdo->prepare('SELECT total FROM cotizaciones WHERE id=? FOR UPDATE');$s->execute([$id]);$total=$s->fetchColumn();if($total===false)fail('Cotización no encontrada.',404);
                $s=$pdo->prepare('SELECT COALESCE(SUM(monto),0) FROM pagos WHERE cotizacion_id=?');$s->execute([$id]);$paid=(float)$s->fetchColumn();
                if($amount > round((float)$total-$paid,2)+.001) fail('El pago supera el saldo.',422);
                $pdo->prepare('INSERT INTO pagos(cotizacion_id,fecha,metodo,monto,destinatario,notas) VALUES(?,CURDATE(),?,?,?,?)')->execute([$id,$methodPay,$amount,$destination,strval_safe($b['notas']??'',500)]);
                $pdo->prepare("UPDATE cotizaciones SET estado='Anticipo recibido',updated_at=NOW() WHERE id=? AND estado IN ('Cotización','Aprobada')")->execute([$id]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            response(['ok'=>true,'data'=>load_quote($id)],201);
        }
    }
    if($route==='inventory' && $method==='GET') {
        $s=db()->query('SELECT id,sku,nombre,stock,stock_minimo,precio_referencia FROM inventario WHERE activo=1 ORDER BY nombre LIMIT 200');
        response(['ok'=>true,'data'=>array_map(function($i){$i['id']=(int)$i['id'];foreach(['stock','stock_minimo','precio_referencia'] as $k)$i[$k]=(float)$i[$k];return $i;},$s->fetchAll())]);
    }
    if($route==='agenda' && $method==='GET') {
        $s=db()->query('SELECT id,titulo,fecha_inicio,fecha_entrega,estado,responsable FROM agenda_trabajos ORDER BY fecha_inicio DESC,id DESC LIMIT 100');
        response(['ok'=>true,'data'=>array_map(function($i){$i['id']=(int)$i['id'];return $i;},$s->fetchAll())]);
    }
    if($route==='warranties' && $method==='GET') {
        $s=db()->query('SELECT id,cotizacion_id,concepto,fecha_fin,estado FROM garantias ORDER BY fecha_fin DESC LIMIT 150');
        response(['ok'=>true,'data'=>array_map(function($i){$i['id']=(int)$i['id'];$i['cotizacion_id']=(int)$i['cotizacion_id'];return $i;},$s->fetchAll())]);
    }
    fail('Ruta no encontrada.',404);
} catch(PDOException $e) {
    error_log('SRNU mobile API database error: '.$e->getMessage());
    fail('Error de base de datos. Revisa la configuración de la API.',500);
} catch(Throwable $e) {
    error_log('SRNU mobile API error: '.$e->getMessage());
    fail('Error interno en la API.',500);
}
