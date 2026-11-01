<?php
declare(strict_types=1);

/**
 * Precios fijos tomados del menú oficial SRNU CUSTOM · octubre 2026.
 * Solo se incluyen importes cerrados. Los servicios que dicen "desde", "varía"
 * o "cotiza" permanecen fuera de esta siembra automática.
 */
function srnu_menu_precios_fijos(): array
{
    return [
        // Performance
        ['PERF-SAVANINI-VALVULA','Performance','Válvula de alivio Savanini','Válvula de alivio Savanini con instalación para la mayoría de autos.',8000,1,0,0,12,'sobre_pedido',0],

        // Detailing · corrección a un paso + Sinergy Paint
        ['DET-1P-SEDAN-C','Detailing','Corrección de pintura 1 paso + Sinergy Paint · Sedán compacto','Corrección a un paso con 3D ONE + sellador semi cerámico Vonixx Sinergy Paint.',3500,0,1,0,0,'inmediata',0],
        ['DET-1P-SEDAN-MG','Detailing','Corrección de pintura 1 paso + Sinergy Paint · Sedán mediano/grande','Corrección a un paso con 3D ONE + sellador semi cerámico Vonixx Sinergy Paint.',4000,0,1,0,0,'inmediata',0],
        ['DET-1P-PICKUP-S','Detailing','Corrección de pintura 1 paso + Sinergy Paint · Pick-up cabina sencilla','Corrección a un paso con 3D ONE + sellador semi cerámico Vonixx Sinergy Paint.',4000,0,1,0,0,'inmediata',0],
        ['DET-1P-SUV-C','Detailing','Corrección de pintura 1 paso + Sinergy Paint · SUV compacta','Corrección a un paso con 3D ONE + sellador semi cerámico Vonixx Sinergy Paint.',4500,0,1,0,0,'inmediata',0],
        ['DET-1P-SUV-M','Detailing','Corrección de pintura 1 paso + Sinergy Paint · SUV mediana','Corrección a un paso con 3D ONE + sellador semi cerámico Vonixx Sinergy Paint.',5000,0,1,0,0,'inmediata',0],
        ['DET-1P-PICKUP-D','Detailing','Corrección de pintura 1 paso + Sinergy Paint · Pick-up doble cabina','Corrección a un paso con 3D ONE + sellador semi cerámico Vonixx Sinergy Paint.',5000,0,1,0,0,'inmediata',0],
        ['DET-1P-SUV-G','Detailing','Corrección de pintura 1 paso + Sinergy Paint · SUV grande / 3 filas','Corrección a un paso con 3D ONE + sellador semi cerámico Vonixx Sinergy Paint.',5500,0,1,0,0,'inmediata',0],
        ['DET-1P-VAN','Detailing','Corrección de pintura 1 paso + Sinergy Paint · Van / Minivan','Corrección a un paso con 3D ONE + sellador semi cerámico Vonixx Sinergy Paint.',6500,0,1,0,0,'inmediata',0],

        // Detailing · 3 pasos + cerámico 1 año
        ['DET-3P-1Y-SEDAN-C','Detailing','Corrección 3 pasos + cerámico 1 año · Sedán compacto','Corrección a 3 pasos + GYEON CanCoat de 1 año.',6000,0,1,0,12,'inmediata',0],
        ['DET-3P-1Y-SEDAN-MG','Detailing','Corrección 3 pasos + cerámico 1 año · Sedán mediano/grande','Corrección a 3 pasos + GYEON CanCoat de 1 año.',7000,0,1,0,12,'inmediata',0],
        ['DET-3P-1Y-PICKUP-S','Detailing','Corrección 3 pasos + cerámico 1 año · Pick-up cabina sencilla','Corrección a 3 pasos + GYEON CanCoat de 1 año.',7000,0,1,0,12,'inmediata',0],
        ['DET-3P-1Y-SUV-C','Detailing','Corrección 3 pasos + cerámico 1 año · SUV compacta','Corrección a 3 pasos + GYEON CanCoat de 1 año.',7500,0,1,0,12,'inmediata',0],
        ['DET-3P-1Y-SUV-M','Detailing','Corrección 3 pasos + cerámico 1 año · SUV mediana','Corrección a 3 pasos + GYEON CanCoat de 1 año.',8500,0,1,0,12,'inmediata',0],
        ['DET-3P-1Y-PICKUP-D','Detailing','Corrección 3 pasos + cerámico 1 año · Pick-up doble cabina','Corrección a 3 pasos + GYEON CanCoat de 1 año.',8500,0,1,0,12,'inmediata',0],
        ['DET-3P-1Y-SUV-G','Detailing','Corrección 3 pasos + cerámico 1 año · SUV grande / 3 filas','Corrección a 3 pasos + GYEON CanCoat de 1 año.',9500,0,1,0,12,'inmediata',0],
        ['DET-3P-1Y-VAN','Detailing','Corrección 3 pasos + cerámico 1 año · Van / Minivan','Corrección a 3 pasos + GYEON CanCoat de 1 año.',10500,0,1,0,12,'inmediata',0],

        // Detailing · 3 pasos + cerámico 3 años
        ['DET-3P-3Y-SEDAN-C','Detailing','Corrección 3 pasos + cerámico 3 años · Sedán compacto','Corrección a 3 pasos + GYEON MOHS de 3 años.',8500,0,1,0,36,'inmediata',0],
        ['DET-3P-3Y-SEDAN-MG','Detailing','Corrección 3 pasos + cerámico 3 años · Sedán mediano/grande','Corrección a 3 pasos + GYEON MOHS de 3 años.',9500,0,1,0,36,'inmediata',0],
        ['DET-3P-3Y-PICKUP-S','Detailing','Corrección 3 pasos + cerámico 3 años · Pick-up cabina sencilla','Corrección a 3 pasos + GYEON MOHS de 3 años.',9500,0,1,0,36,'inmediata',0],
        ['DET-3P-3Y-SUV-C','Detailing','Corrección 3 pasos + cerámico 3 años · SUV compacta','Corrección a 3 pasos + GYEON MOHS de 3 años.',10000,0,1,0,36,'inmediata',0],
        ['DET-3P-3Y-PICKUP-D','Detailing','Corrección 3 pasos + cerámico 3 años · Pick-up doble cabina','Corrección a 3 pasos + GYEON MOHS de 3 años.',11000,0,1,0,36,'inmediata',0],
        ['DET-3P-3Y-SUV-M','Detailing','Corrección 3 pasos + cerámico 3 años · SUV mediana','Corrección a 3 pasos + GYEON MOHS de 3 años.',11000,0,1,0,36,'inmediata',0],
        ['DET-3P-3Y-SUV-G','Detailing','Corrección 3 pasos + cerámico 3 años · SUV grande / 3 filas','Corrección a 3 pasos + GYEON MOHS de 3 años.',12000,0,1,0,36,'inmediata',0],
        ['DET-3P-3Y-VAN','Detailing','Corrección 3 pasos + cerámico 3 años · Van / Minivan','Corrección a 3 pasos + GYEON MOHS de 3 años.',13500,0,1,0,36,'inmediata',0],
        ['DET-RINES-CALIPERS','Detailing','Pulido + cerámico de rines y calipers','Pulido y protección cerámica de rines y calipers, duración hasta 2 años.',6500,0,1,0,24,'inmediata',0],
        ['DET-MANT-CER-SEDAN','Detailing','Mantenimiento integral de cerámico · Sedán / Hatchback','Mantenimiento con CarPro Essence Plus.',2200,0,1,0,0,'inmediata',0],
        ['DET-MANT-CER-SUVCM','Detailing','Mantenimiento integral de cerámico · SUV compacta / mediana','Mantenimiento con CarPro Essence Plus.',2700,0,1,0,0,'inmediata',0],
        ['DET-MANT-CER-SUVGP','Detailing','Mantenimiento integral de cerámico · SUV grande / Pick-up','Mantenimiento con CarPro Essence Plus.',3200,0,1,0,0,'inmediata',0],
        ['DET-CER-TELAS','Detailing','Cerámico para telas interior · adicional','Protección cerámica para textiles hasta 12 meses.',4500,0,1,0,12,'inmediata',0],
        ['DET-CER-PIEL','Detailing','Cerámico para piel interior · adicional','Protección cerámica para piel hasta 12 meses.',3500,0,1,0,12,'inmediata',0],
        ['DET-CER-PIEL-TELAS','Detailing','Cerámico piel + telas interior','Protección cerámica de piel y textiles hasta 12 meses.',7500,0,1,0,12,'inmediata',0],

        // Accesorios / multimedia
        ['ACC-PORTAPLACAS-CARBONO','Accesorios','Portaplacas fibra de carbono · delantera y trasera','Juego delantero y trasero instalado.',350,1,0,0,0,'inmediata',0],
        ['ACC-EUROSWITCH','Accesorios','Euro-switch con luz automática','Para Volkswagen con luces manuales; modos welcome/coming home.',2000,1,0,0,0,'inmediata',0],
        ['MULTI-CARPLAY-WIRELESS','Multimedia','Adaptador Apple CarPlay / Android Auto inalámbrico','Convierte CarPlay/Android Auto cableado a conexión inalámbrica.',1500,0,1,0,6,'inmediata',0],
        ['ACC-TAPA-BATEA-MANUAL','Accesorios','Tapa de batea manual TRIFOLD 3 piezas','Tapa para camioneta pick-up, versión manual de tres piezas.',18000,1,0,0,12,'sobre_pedido',0],
        ['ACC-TAPA-BATEA-ELECTRICA','Accesorios','Tapa de batea eléctrica con control','Tapa para camioneta pick-up, versión eléctrica con control.',36000,1,0,0,12,'sobre_pedido',0],

        // Iluminación
        ['LED-9K-BILED','Iluminación','LED principal o nieblas 9,000 lm · BI LED','Alta y baja juntas.',1750,1,0,0,0,'inmediata',0],
        ['LED-9K-SINGLE','Iluminación','LED principal o nieblas 9,000 lm · una función','LED de una sola función.',1550,1,0,0,0,'inmediata',0],
        ['LED-18K-BILED','Iluminación','LED principal o nieblas 18,000 lm · BI LED','Alta y baja juntas.',2750,1,0,0,0,'inmediata',0],
        ['LED-18K-SINGLE','Iluminación','LED principal o nieblas 18,000 lm · una función','LED de una sola función.',2650,1,0,0,0,'inmediata',0],
        ['LED-28K-BILED','Iluminación','LED principal o nieblas 28,000 lm · BI LED','Alta y baja juntas.',3850,1,0,0,0,'inmediata',0],
        ['LED-28K-SINGLE','Iluminación','LED principal o nieblas 28,000 lm · una función','LED de una sola función.',3450,1,0,0,0,'inmediata',0],
        ['LED-35K-BILED','Iluminación','LED principal o nieblas 35,000 lm · BI LED','Alta y baja juntas.',4050,1,0,0,0,'inmediata',0],
        ['LED-35K-SINGLE','Iluminación','LED principal o nieblas 35,000 lm · una función','LED de una sola función.',3850,1,0,0,0,'inmediata',0],
        ['LED-AMBIENTAL-64','Iluminación','Iluminación ambiental universal 64 colores','Módulos en manijas, posapiés y portavasos con control por app Bluetooth.',8500,1,0,0,0,'inmediata',0],
        ['LED-SERIE-D-20K','Iluminación','LED Serie D 20,000 lm · xenón de agencia','Conversión LED para vehículos con xenón de agencia.',4000,1,0,0,0,'inmediata',0],
        ['LED-D5S-20K','Iluminación','LED D5S 20,000 lm','Conversión D5S para vehículos con xenón de agencia.',4500,1,0,0,0,'inmediata',0],
        ['LED-INTERIOR','Iluminación','LED interior · por pieza','Precio por pieza; depende de medida indicada por fabricante.',150,1,0,0,0,'inmediata',0],
        ['LED-CALAVERA-SIMPLE','Iluminación','LED calaveras · par sencillo CANBUS','Par sencillo CANBUS.',600,1,0,0,0,'inmediata',0],
        ['LED-CALAVERA-PREMIUM','Iluminación','LED calaveras · par CANBUS sin hyperflash + potencia','Par CANBUS sin hyperflash y mayor potencia.',1400,1,0,0,0,'inmediata',0],
        ['LED-DECODER-CALAVERA','Iluminación','Decodificador CANBUS para LED calavera · por pieza','Decodificador para LED de calavera.',350,1,0,0,0,'inmediata',0],
        ['LED-ARNES-NUEVO','Iluminación','Arnés para LED nuevo · por pieza','Arnés para instalación LED.',150,1,0,0,0,'inmediata',0],
        ['LED-REVERSA-CSP-6K','Iluminación','Reversa CSP 6,000 lm','Reversa premium 6,000 lúmenes.',1500,1,0,0,0,'inmediata',0],
        ['LED-BITONO-DIR','Iluminación','Iluminación bitono en direccionales · par','Par con instalación.',700,1,0,0,0,'inmediata',0],
        ['LED-BITONO-DIR-CANBUS','Iluminación','Iluminación bitono direccionales CANBUS · par','Par CANBUS sin hyperflash + potencia.',1500,1,0,0,0,'inmediata',0],
    ];
}

function srnu_sembrar_catalogo_menu(PDO $pdo, bool $forzar = false): int
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS sistema_migraciones (
        clave VARCHAR(120) NOT NULL PRIMARY KEY,
        aplicado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $clave='menu_precios_fijos_2026_10_v1';
    if(!$forzar){$s=$pdo->prepare('SELECT 1 FROM sistema_migraciones WHERE clave=?');$s->execute([$clave]);if($s->fetchColumn())return 0;}

    $buscarCodigo=$pdo->prepare('SELECT id FROM catalogo_servicios WHERE menu_codigo=? LIMIT 1');
    $buscarNombre=$pdo->prepare('SELECT id FROM catalogo_servicios WHERE nombre=? LIMIT 1');
    $insertar=$pdo->prepare('INSERT INTO catalogo_servicios(nombre,descripcion,precio_base,costo_base,responsable_sergio,responsable_ricardo,responsable_tomas,garantia_meses,tipo_entrega,dias_entrega_estimada,dias_trabajo_min,dias_trabajo_max,foto_producto,categoria,precio_fijo,menu_codigo,activo) VALUES(?,?,?,?,?,?,?,?,?,?,0,0,NULL,?,?,?,1)');
    $actualizar=$pdo->prepare('UPDATE catalogo_servicios SET descripcion=?,precio_base=?,responsable_sergio=?,responsable_ricardo=?,responsable_tomas=?,garantia_meses=?,tipo_entrega=?,dias_entrega_estimada=?,categoria=?,precio_fijo=1,menu_codigo=?,activo=1 WHERE id=?');
    $n=0;
    foreach(srnu_menu_precios_fijos() as $r){
        [$codigo,$categoria,$nombre,$descripcion,$precio,$sergio,$ricardo,$tomas,$garantia,$tipo,$dias]=$r;
        $buscarCodigo->execute([$codigo]);$id=(int)($buscarCodigo->fetchColumn()?:0);
        if(!$id){$buscarNombre->execute([$nombre]);$id=(int)($buscarNombre->fetchColumn()?:0);}
        if($id){
            if($forzar){$actualizar->execute([$descripcion,$precio,$sergio,$ricardo,$tomas,$garantia,$tipo,$dias,$categoria,$codigo,$id]);$n++;}
            else{$pdo->prepare('UPDATE catalogo_servicios SET categoria=COALESCE(NULLIF(categoria,\'\'),?),precio_fijo=1,menu_codigo=COALESCE(menu_codigo,?) WHERE id=?')->execute([$categoria,$codigo,$id]);}
        }else{
            $insertar->execute([$nombre,$descripcion,$precio,0,$sergio,$ricardo,$tomas,$garantia,$tipo,$dias,$categoria,1,$codigo]);$n++;
        }
    }
    $pdo->prepare('INSERT INTO sistema_migraciones(clave) VALUES(?) ON DUPLICATE KEY UPDATE aplicado_at=aplicado_at')->execute([$clave]);
    return $n;
}
