<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/ai_local.php';

function eh(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function emoney(mixed $v): string { return '$' . number_format((float)$v, 2); }
function edate(?string $v): string { if (!$v) return '—'; $t=strtotime($v); return $t?date('d/m/Y',$t):$v; }
function ego(string $url): never { header('Location: '.$url); exit; }
function epost(string $k, string $d=''): string { return trim((string)($_POST[$k] ?? $d)); }
function efloat(string $k, float $d=0): float { return max(0, (float)($_POST[$k] ?? $d)); }
function eint(string $k, int $d=0): int { return max(0, (int)($_POST[$k] ?? $d)); }
function srnu_upload_dir(string $sub): string {
    $base = dirname(__DIR__) . '/uploads/' . trim($sub, '/');
    if (!is_dir($base)) @mkdir($base, 0775, true);
    return $base;
}
function srnu_safe_upload_name(string $original): string {
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $base = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($original, PATHINFO_FILENAME)) ?: 'archivo';
    return date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . trim($base,'-') . ($ext ? '.'.$ext : '');
}
function srnu_cotizacion_label(array $c): string { return trim(($c['folio'] ?? '') . ' · ' . ($c['cliente'] ?? '') . ' · ' . ($c['vehiculo'] ?? '')); }
function srnu_get_cotizaciones_select(): array {
    return srnu_db()->query("SELECT id,folio,cliente,vehiculo,estado,total FROM cotizaciones ORDER BY id DESC LIMIT 500")->fetchAll();
}
function srnu_responsables(): array { return ['Sergio'=>'Sergio Núñez','Ricardo'=>'Ricardo','Tomás'=>'Tomás']; }
function srnu_tipo_entrega_label(string $tipo): string { return $tipo === 'sobre_pedido' ? 'Sobre pedido' : 'Entrega inmediata'; }

function srnu_consumir_inventario_cotizacion(int $cotizacionId): void {
    if ($cotizacionId <= 0) return; $pdo=srnu_db(); $c=srnu_obtener_cotizacion($cotizacionId); if(!$c) return;
    $pdo->prepare("INSERT INTO ordenes_trabajo(cotizacion_id,estado,notas) VALUES(?, 'En proceso','') ON DUPLICATE KEY UPDATE cotizacion_id=cotizacion_id")->execute([$cotizacionId]);
    $s=$pdo->prepare('SELECT inventario_descontado FROM ordenes_trabajo WHERE cotizacion_id=?');$s->execute([$cotizacionId]);if((int)$s->fetchColumn()===1)return;
    foreach($c['items'] as $it){$cat=(int)($it['catalogo_id']??0);if(!$cat)continue;$r=$pdo->prepare('SELECT ci.*,i.costo_unitario FROM catalogo_insumos ci JOIN inventario i ON i.id=ci.inventario_id WHERE ci.catalogo_id=?');$r->execute([$cat]);foreach($r as $ins){$qty=round((float)$ins['cantidad']*(float)$it['cantidad'],3);if($qty<=0)continue;$pdo->prepare('UPDATE inventario SET stock=stock-? WHERE id=?')->execute([$qty,$ins['inventario_id']]);$pdo->prepare("INSERT INTO movimientos_inventario(inventario_id,tipo,cantidad,costo_unitario,referencia_tipo,referencia_id,notas) VALUES(?, 'Consumo orden', ?, ?, 'Cotización', ?, ?)")->execute([$ins['inventario_id'],-$qty,$ins['costo_unitario'],$cotizacionId,'Consumo por '.$it['concepto']]);}}
    $pdo->prepare('UPDATE ordenes_trabajo SET inventario_descontado=1 WHERE cotizacion_id=?')->execute([$cotizacionId]);
}
function srnu_generar_postventa_entrega(int $cotizacionId): void {
    if($cotizacionId<=0)return;$pdo=srnu_db();$c=srnu_obtener_cotizacion($cotizacionId);if(!$c)return;
    foreach($c['items'] as $it){$m=(int)($it['garantia_meses']??0);if($m<=0)continue;$ch=$pdo->prepare('SELECT id,fecha_fin FROM garantias WHERE cotizacion_id=? AND item_id=?');$ch->execute([$cotizacionId,$it['id']]);$g=$ch->fetch();if(!$g){$ini=date('Y-m-d');$fin=date('Y-m-d',strtotime('+'.$m.' months'));$pdo->prepare('INSERT INTO garantias(cotizacion_id,item_id,concepto,fecha_inicio,fecha_fin,estado) VALUES(?,?,?,?,?,?)')->execute([$cotizacionId,$it['id'],$it['concepto'],$ini,$fin,'Vigente']);$gid=(int)$pdo->lastInsertId();}else{$gid=(int)$g['id'];$fin=$g['fecha_fin'];}$x=$pdo->prepare("SELECT id FROM postventa_recordatorios WHERE garantia_id=? AND tipo='Garantía'");$x->execute([$gid]);if(!$x->fetch())$pdo->prepare("INSERT INTO postventa_recordatorios(cotizacion_id,garantia_id,tipo,fecha_recordatorio,descripcion,estado) VALUES(?,?,'Garantía',?,?,'Pendiente')")->execute([$cotizacionId,$gid,date('Y-m-d',max(time(),strtotime($fin.' -30 days'))),'Revisar garantía próxima a vencer: '.$it['concepto']]);}
    $x=$pdo->prepare("SELECT id FROM postventa_recordatorios WHERE cotizacion_id=? AND tipo='Seguimiento' AND descripcion='Seguimiento 30 días después de entrega'");$x->execute([$cotizacionId]);if(!$x->fetch())$pdo->prepare("INSERT INTO postventa_recordatorios(cotizacion_id,tipo,fecha_recordatorio,descripcion,estado) VALUES(?,'Seguimiento',?,'Seguimiento 30 días después de entrega','Pendiente')")->execute([$cotizacionId,date('Y-m-d',strtotime('+30 days'))]);
}
