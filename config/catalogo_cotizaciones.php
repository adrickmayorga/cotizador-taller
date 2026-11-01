<?php
declare(strict_types=1);

/** Identidad del artículo: conserva modelo, signos y números; ignora mayúsculas y espacios. */
function srnu_catalogo_nombre_normalizado(string $nombre): string
{
    $nombre = trim((string)preg_replace('/\s+/u', ' ', $nombre));
    return function_exists('mb_strtolower') ? mb_strtolower($nombre, 'UTF-8') : strtolower(strtr($nombre, ['Á'=>'á','É'=>'é','Í'=>'í','Ó'=>'ó','Ú'=>'ú','Ñ'=>'ñ','Ü'=>'ü']));
}

function srnu_catalogo_texto_corto(string $texto, int $maximo): string
{
    if (function_exists('mb_substr')) return mb_substr($texto, 0, $maximo, 'UTF-8');
    preg_match_all('/./us', $texto, $caracteres);
    return implode('', array_slice($caracteres[0], 0, $maximo));
}

function srnu_catalogo_foto_segura(mixed $foto): ?string
{
    $foto = trim((string)$foto);
    return preg_match('~^uploads/productos/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp)$~i', $foto) ? $foto : null;
}

/**
 * Se ejecuta dentro de la transacción de guardado o recuperación.
 * Los artículos existentes conservan precio, costo, garantía y responsables.
 * Solo se completa una foto faltante; nunca se borra ni reemplaza una foto existente.
 */
function srnu_catalogar_items_cotizacion(PDO $pdo, array &$items): array
{
    $porId = []; $porNombre = []; $porClave = [];
    $indexar = static function(array $r) use (&$porId, &$porNombre, &$porClave): void {
        $id = (int)$r['id']; $porId[$id] = $r;
        $nombres = [(string)$r['nombre']];
        if (!empty($r['descripcion'])) $nombres[] = $r['nombre'].' — '.$r['descripcion'];
        foreach ($nombres as $nombre) {
            $clave = srnu_catalogo_nombre_normalizado($nombre);
            if (!isset($porNombre[$clave])) $porNombre[$clave] = $id;
        }
        if (!empty($r['clave_cotizacion'])) $porClave[$r['clave_cotizacion']] = $id;
    };
    foreach ($pdo->query('SELECT * FROM catalogo_servicios ORDER BY activo DESC,id ASC')->fetchAll(PDO::FETCH_ASSOC) as $r) $indexar($r);
    $resultado = ['creados'=>0, 'existentes'=>0, 'fotos'=>0, 'omitidos'=>0];
    $insertar = $pdo->prepare('INSERT INTO catalogo_servicios (nombre,descripcion,categoria,precio_base,costo_base,responsable_sergio,responsable_ricardo,responsable_tomas,garantia_meses,tipo_entrega,dias_entrega_estimada,dias_trabajo_min,dias_trabajo_max,foto_producto,activo,clave_cotizacion) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)');
    $fotoStmt = $pdo->prepare("UPDATE catalogo_servicios SET foto_producto=? WHERE id=? AND (foto_producto IS NULL OR foto_producto='')");
    foreach ($items as &$item) {
        $concepto = trim((string)($item['concepto'] ?? ''));
        if ($concepto === '' || (float)($item['cantidad'] ?? 1) <= 0) { $resultado['omitidos']++; continue; }
        $normalizado = srnu_catalogo_nombre_normalizado($concepto);
        $clave = hash('sha256', $normalizado);
        $id = (int)($item['catalogo_id'] ?? 0);
        if (!isset($porId[$id])) $id = $porClave[$clave] ?? $porNombre[$normalizado] ?? 0;
        $foto = srnu_catalogo_foto_segura($item['foto_producto'] ?? null);
        if (!$id) {
            $partes = preg_split('/\R| — /u', $concepto, 2);
            $nombre = srnu_catalogo_texto_corto(trim($partes[0]), 220);
            $descripcion = srnu_catalogo_texto_corto(trim((string)($partes[1] ?? ($nombre !== $concepto ? $concepto : ''))), 700);
            $tipo = ($item['tipo_entrega'] ?? '') === 'sobre_pedido' ? 'sobre_pedido' : 'inmediata';
            $nuevo = [
                'nombre'=>$nombre, 'descripcion'=>$descripcion, 'categoria'=>'Desde cotizaciones',
                'precio_base'=>round(max(0, (float)($item['precio'] ?? 0)), 2),
                'costo_base'=>round(max(0, (float)($item['costo_interno'] ?? 0)), 2),
                'responsable_sergio'=>!empty($item['responsable_sergio']) ? 1 : 0,
                'responsable_ricardo'=>!empty($item['responsable_ricardo']) ? 1 : 0,
                'responsable_tomas'=>!empty($item['responsable_tomas']) ? 1 : 0,
                'garantia_meses'=>max(0, (int)($item['garantia_meses'] ?? 0)),
                'tipo_entrega'=>$tipo, 'dias_entrega_estimada'=>$tipo === 'sobre_pedido' ? max(0, (int)($item['dias_entrega_estimada'] ?? 0)) : 0,
                'dias_trabajo_min'=>max(0, (int)($item['dias_trabajo_min'] ?? 0)),
                'dias_trabajo_max'=>max(0, (int)($item['dias_trabajo_max'] ?? 0)),
                'foto_producto'=>$foto, 'clave_cotizacion'=>$clave,
            ];
            try {
                $insertar->execute(array_values($nuevo));
                $id = (int)$pdo->lastInsertId();
                $resultado['creados']++;
                $indexar(['id'=>$id, 'activo'=>1] + $nuevo);
            } catch (PDOException $e) {
                // Una segunda petición puede haber guardado el mismo artículo.
                // La clave única evita duplicarlo incluso con guardados simultáneos.
                if ((string)$e->getCode() !== '23000') throw $e;
                $bloqueo = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                $buscar = $pdo->prepare('SELECT * FROM catalogo_servicios WHERE clave_cotizacion=?'.$bloqueo);
                $buscar->execute([$clave]); $existente = $buscar->fetch(PDO::FETCH_ASSOC);
                if (!$existente) throw $e;
                $id = (int)$existente['id']; $indexar($existente);
                $resultado['existentes']++;
            }
        } else { $resultado['existentes']++; }
        if ($foto && empty($porId[$id]['foto_producto'])) {
            $fotoStmt->execute([$foto, $id]);
            $resultado['fotos'] += $fotoStmt->rowCount();
            $porId[$id]['foto_producto'] = $foto;
        }
        $item['catalogo_id'] = $id;
    }
    unset($item);
    return $resultado;
}

/** Lotes pequeños: no vuelve a guardar ni modifica las cotizaciones históricas. */
function srnu_recuperar_catalogo_cotizaciones(PDO $pdo, int $cursor = 0): array
{
    $limite = 100;
    $sql = 'SELECT * FROM cotizacion_items'.($cursor > 0 ? ' WHERE id < ?' : '').' ORDER BY id DESC LIMIT '.$limite;
    $stmt = $pdo->prepare($sql); $stmt->execute($cursor > 0 ? [$cursor] : []);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) return ['creados'=>0,'existentes'=>0,'fotos'=>0,'omitidos'=>0,'procesados'=>0,'cursor'=>0,'pendientes'=>false];
    $siguiente = (int)$items[count($items)-1]['id'];
    $pdo->beginTransaction();
    try {
        $resultado = srnu_catalogar_items_cotizacion($pdo, $items);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $mas = $pdo->prepare('SELECT id FROM cotizacion_items WHERE id < ? LIMIT 1'); $mas->execute([$siguiente]);
    return $resultado + ['procesados'=>count($items),'cursor'=>$siguiente,'pendientes'=>$mas->fetchColumn() !== false];
}
