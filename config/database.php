<?php
declare(strict_types=1);
require_once __DIR__ . '/menu_precios.php';
require_once __DIR__ . '/bbva_pyme.php';
require_once __DIR__ . '/database_schema.php';
require_once __DIR__ . '/catalogo_cotizaciones.php';

// SRNU CUSTOM · Credenciales locales separadas del código versionable.
// database.local.php se incluye en el ZIP local, pero .gitignore evita subirlo a GitHub.
$srnuLocalDb = __DIR__ . '/database.local.php';
if (is_file($srnuLocalDb)) require_once $srnuLocalDb;
if (!defined('SRNU_DB_HOST')) define('SRNU_DB_HOST', '127.0.0.1');
if (!defined('SRNU_DB_NAME')) define('SRNU_DB_NAME', 'srnu_cotizador');
if (!defined('SRNU_DB_USER')) define('SRNU_DB_USER', 'root');
if (!defined('SRNU_DB_PASS')) define('SRNU_DB_PASS', '');

function srnu_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . SRNU_DB_HOST . ';dbname=' . SRNU_DB_NAME . ';charset=utf8mb4';
    $conexion = new PDO($dsn, SRNU_DB_USER, SRNU_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    srnu_actualizar_esquema_si_necesario($conexion);
    $pdo = $conexion;
    return $pdo;
}

function srnu_migrar_esquema(PDO $pdo): void
{

    // Actualización automática para cotizaciones creadas con versiones anteriores.
    $cols = $pdo->query("SHOW COLUMNS FROM cotizaciones")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('anticipo_tipo', $cols, true)) {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN anticipo_tipo VARCHAR(20) NOT NULL DEFAULT 'porcentaje' AFTER msi_plazos");
    }
    if (!in_array('anticipo_valor', $cols, true)) {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN anticipo_valor DECIMAL(12,2) NOT NULL DEFAULT 70.00 AFTER anticipo_tipo");
    }
    if (!in_array('conservar_anticipo_recibido', $cols, true)) {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN conservar_anticipo_recibido TINYINT(1) NOT NULL DEFAULT 0 AFTER anticipo_valor");
    }
    if (!in_array('anticipo_recibido_monto', $cols, true)) {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN anticipo_recibido_monto DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER conservar_anticipo_recibido");
    }
    if (!in_array('dividir_anticipo', $cols, true)) {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN dividir_anticipo TINYINT(1) NOT NULL DEFAULT 0 AFTER anticipo_recibido_monto");
    }
    if (!in_array('repartir_igual', $cols, true)) {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN repartir_igual TINYINT(1) NOT NULL DEFAULT 0 AFTER dividir_anticipo");
    }
    if (!in_array('anticipo_sergio', $cols, true)) {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN anticipo_sergio DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER repartir_igual");
    }
    if (!in_array('anticipo_ricardo', $cols, true)) {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN anticipo_ricardo DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER anticipo_sergio");
    }
    if (!in_array('anticipo_tomas', $cols, true)) $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN anticipo_tomas DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER anticipo_ricardo");
    if (!in_array('participa_sergio', $cols, true)) $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN participa_sergio TINYINT(1) NOT NULL DEFAULT 1 AFTER anticipo_tomas");
    if (!in_array('participa_ricardo', $cols, true)) $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN participa_ricardo TINYINT(1) NOT NULL DEFAULT 1 AFTER participa_sergio");
    if (!in_array('participa_tomas', $cols, true)) $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN participa_tomas TINYINT(1) NOT NULL DEFAULT 0 AFTER participa_ricardo");

    // Responsables por concepto. Se agregan automáticamente para instalaciones existentes.
    $itemCols = $pdo->query("SHOW COLUMNS FROM cotizacion_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('responsable_sergio', $itemCols, true)) {
        $pdo->exec("ALTER TABLE cotizacion_items ADD COLUMN responsable_sergio TINYINT(1) NOT NULL DEFAULT 0 AFTER total");
    }
    if (!in_array('responsable_ricardo', $itemCols, true)) {
        $pdo->exec("ALTER TABLE cotizacion_items ADD COLUMN responsable_ricardo TINYINT(1) NOT NULL DEFAULT 0 AFTER responsable_sergio");
    }
    if (!in_array('responsable_tomas', $itemCols, true)) {
        $pdo->exec("ALTER TABLE cotizacion_items ADD COLUMN responsable_tomas TINYINT(1) NOT NULL DEFAULT 0 AFTER responsable_ricardo");
    }

    // Usuarios para acceso seguro cuando el sistema se publica en internet.
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(120) NOT NULL,
        usuario VARCHAR(80) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        ultimo_acceso DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $userCols = $pdo->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('rol', $userCols, true)) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN rol VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER password_hash");
    }
    if (!in_array('updated_at', $userCols, true)) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS configuracion_sistema (
        clave VARCHAR(100) NOT NULL PRIMARY KEY,
        valor TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ===== REGISTRO DE CLIENTES =====
    $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(180) NOT NULL,
        telefono VARCHAR(60) NOT NULL,
        telefono_normalizado VARCHAR(30) NOT NULL,
        email VARCHAR(180) NULL,
        notas VARCHAR(700) NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cliente_telefono (telefono_normalizado),
        KEY idx_cliente_nombre (nombre),
        KEY idx_cliente_telefono (telefono)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $colsCliente = $pdo->query("SHOW COLUMNS FROM cotizaciones")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('cliente_id', $colsCliente, true)) {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN cliente_id INT UNSIGNED NULL AFTER telefono");
    }
    // Recuperación de cotizaciones importadas desde PDF.
    if (!in_array('importado_desde_pdf', $colsCliente, true)) {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN importado_desde_pdf TINYINT(1) NOT NULL DEFAULT 0 AFTER cliente_id");
    }
    if (!in_array('pdf_origen_nombre', $colsCliente, true)) {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN pdf_origen_nombre VARCHAR(255) NULL AFTER importado_desde_pdf");
    }
    if (!in_array('pdf_origen_hash', $colsCliente, true)) {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN pdf_origen_hash CHAR(64) NULL AFTER pdf_origen_nombre");
    }
    if (!in_array('pdf_folio_origen', $colsCliente, true)) {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN pdf_folio_origen VARCHAR(60) NULL AFTER pdf_origen_hash");
    }
    if (!in_array('pdf_origen_archivo', $colsCliente, true)) {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN pdf_origen_archivo VARCHAR(255) NULL AFTER pdf_folio_origen");
    }

    // Recuperar automáticamente los clientes de cotizaciones antiguas y enlazarlos.
    try {
        $pendientesCliente = $pdo->query("SELECT id, cliente, telefono FROM cotizaciones WHERE (cliente_id IS NULL OR cliente_id=0) AND TRIM(cliente)<>'' AND TRIM(telefono)<>''")->fetchAll();
        if ($pendientesCliente) {
            $buscarCliente = $pdo->prepare('SELECT id FROM clientes WHERE telefono_normalizado=? LIMIT 1');
            $insertarCliente = $pdo->prepare('INSERT INTO clientes(nombre,telefono,telefono_normalizado,activo) VALUES(?,?,?,1)');
            $actualizarCliente = $pdo->prepare('UPDATE clientes SET nombre=?, telefono=?, activo=1 WHERE id=?');
            $enlazarCot = $pdo->prepare('UPDATE cotizaciones SET cliente_id=? WHERE id=?');
            foreach ($pendientesCliente as $pc) {
                $normalizado = preg_replace('/\D+/', '', (string)$pc['telefono']) ?? '';
                if (strlen($normalizado) === 12 && substr($normalizado, 0, 2) === '52') $normalizado = substr($normalizado, 2);
                if ($normalizado === '') continue;
                $buscarCliente->execute([$normalizado]);
                $cid = (int)($buscarCliente->fetchColumn() ?: 0);
                if ($cid <= 0) {
                    $insertarCliente->execute([(string)$pc['cliente'], (string)$pc['telefono'], $normalizado]);
                    $cid = (int)$pdo->lastInsertId();
                } else {
                    $actualizarCliente->execute([(string)$pc['cliente'], (string)$pc['telefono'], $cid]);
                }
                if ($cid > 0) $enlazarCot->execute([$cid, (int)$pc['id']]);
            }
        }
    } catch (Throwable $e) {
        // La recuperación es auxiliar; nunca debe impedir abrir el cotizador.
    }

    // ===== FIRMAS DIGITALES DE COTIZACIÓN =====
    // Un enlace único permite que el cliente revise y firme la cotización desde
    // un navegador. La firma se liga a un hash de la cotización para detectar
    // modificaciones posteriores y evitar reutilizar una aceptación antigua.
    $pdo->exec("CREATE TABLE IF NOT EXISTS firmas_digitales (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NOT NULL UNIQUE,
        token CHAR(64) NOT NULL UNIQUE,
        estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
        nombre_firmante VARCHAR(180) NULL,
        firma_data LONGTEXT NULL,
        snapshot_hash CHAR(64) NOT NULL,
        hash_verificacion CHAR(64) NULL,
        acepto TINYINT(1) NOT NULL DEFAULT 0,
        firmado_at DATETIME NULL,
        ip_firmante VARCHAR(64) NULL,
        user_agent VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_firma_estado (estado),
        KEY idx_firma_token (token),
        CONSTRAINT fk_firma_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ===== FIRMA DIGITAL DEL TALLER =====
    // Firma interna de SRNU CUSTOM ligada al hash contractual de la cotización.
    $pdo->exec("CREATE TABLE IF NOT EXISTS firmas_taller (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NOT NULL UNIQUE,
        estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
        nombre_firmante VARCHAR(180) NULL,
        firma_data LONGTEXT NULL,
        snapshot_hash CHAR(64) NOT NULL,
        hash_verificacion CHAR(64) NULL,
        firmado_at DATETIME NULL,
        ip_firmante VARCHAR(64) NULL,
        user_agent VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_firma_taller_estado (estado),
        CONSTRAINT fk_firma_taller_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ===== CONFIRMACIÓN LOCAL DE COTIZACIÓN (LAN) =====
    // Alternativa a la firma dibujada. El cliente puede aceptar desde un
    // celular conectado a la misma red local. La aceptación se liga al hash
    // contractual de la cotización para invalidarse si después cambia.
    $pdo->exec("CREATE TABLE IF NOT EXISTS confirmaciones_locales (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NOT NULL UNIQUE,
        token CHAR(64) NOT NULL UNIQUE,
        estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
        nombre_confirmante VARCHAR(180) NULL,
        snapshot_hash CHAR(64) NOT NULL,
        hash_verificacion CHAR(64) NULL,
        acepto TINYINT(1) NOT NULL DEFAULT 0,
        confirmado_at DATETIME NULL,
        ip_confirmante VARCHAR(64) NULL,
        user_agent VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_confirmacion_estado (estado),
        KEY idx_confirmacion_token (token),
        CONSTRAINT fk_confirmacion_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ===== SRNU ADMIN / OPERACIÓN =====
    // Nuevos campos de cotización. Se crean automáticamente sin perder datos existentes.
    $cols = $pdo->query("SHOW COLUMNS FROM cotizaciones")->fetchAll(PDO::FETCH_COLUMN);
    $nuevas = [
        'estado' => "ALTER TABLE cotizaciones ADD COLUMN estado VARCHAR(30) NOT NULL DEFAULT 'Cotización' AFTER participa_tomas",
        'vigencia_dias' => "ALTER TABLE cotizaciones ADD COLUMN vigencia_dias INT NOT NULL DEFAULT 15 AFTER estado",
        'dias_trabajo_general_min' => "ALTER TABLE cotizaciones ADD COLUMN dias_trabajo_general_min INT NOT NULL DEFAULT 0 AFTER vigencia_dias",
        'dias_trabajo_general_max' => "ALTER TABLE cotizaciones ADD COLUMN dias_trabajo_general_max INT NOT NULL DEFAULT 0 AFTER dias_trabajo_general_min",
        'aceptada_at' => "ALTER TABLE cotizaciones ADD COLUMN aceptada_at DATETIME NULL AFTER dias_trabajo_general_max",
    ];
    foreach ($nuevas as $col => $sql) if (!in_array($col, $cols, true)) $pdo->exec($sql);

    // Compatibilidad: si la versión anterior tenía tiempos por concepto, usar el rango
    // máximo registrado como propuesta inicial del tiempo general del trabajo.
    try {
        $pdo->exec("UPDATE cotizaciones c
            SET c.dias_trabajo_general_min = CASE WHEN c.dias_trabajo_general_min=0 THEN COALESCE((SELECT MAX(ci.dias_trabajo_min) FROM cotizacion_items ci WHERE ci.cotizacion_id=c.id),0) ELSE c.dias_trabajo_general_min END,
                c.dias_trabajo_general_max = CASE WHEN c.dias_trabajo_general_max=0 THEN COALESCE((SELECT MAX(ci.dias_trabajo_max) FROM cotizacion_items ci WHERE ci.cotizacion_id=c.id),0) ELSE c.dias_trabajo_general_max END
            WHERE c.dias_trabajo_general_min=0 OR c.dias_trabajo_general_max=0");
    } catch (Throwable $e) { /* Compatibilidad auxiliar. */ }

    $itemCols = $pdo->query("SHOW COLUMNS FROM cotizacion_items")->fetchAll(PDO::FETCH_COLUMN);
    $nuevasItems = [
        'costo_interno' => "ALTER TABLE cotizacion_items ADD COLUMN costo_interno DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER precio",
        'garantia_meses' => "ALTER TABLE cotizacion_items ADD COLUMN garantia_meses INT NOT NULL DEFAULT 0 AFTER responsable_tomas",
        'catalogo_id' => "ALTER TABLE cotizacion_items ADD COLUMN catalogo_id INT NULL AFTER garantia_meses",
    ];
    foreach ($nuevasItems as $col => $sql) if (!in_array($col, $itemCols, true)) $pdo->exec($sql);

    $pdo->exec("CREATE TABLE IF NOT EXISTS pagos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NOT NULL,
        fecha DATE NOT NULL,
        metodo VARCHAR(50) NOT NULL,
        monto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        destinatario VARCHAR(30) NOT NULL DEFAULT 'General',
        notas VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pago_cotizacion (cotizacion_id),
        CONSTRAINT fk_pagos_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cotizacion_versiones (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NOT NULL,
        version_no INT NOT NULL,
        snapshot_json LONGTEXT NOT NULL,
        motivo VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_version_cotizacion (cotizacion_id),
        UNIQUE KEY uq_version (cotizacion_id, version_no),
        CONSTRAINT fk_version_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS catalogo_servicios (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(220) NOT NULL,
        descripcion VARCHAR(700) NULL,
        precio_base DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        costo_base DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        responsable_sergio TINYINT(1) NOT NULL DEFAULT 0,
        responsable_ricardo TINYINT(1) NOT NULL DEFAULT 0,
        responsable_tomas TINYINT(1) NOT NULL DEFAULT 0,
        garantia_meses INT NOT NULL DEFAULT 0,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_catalogo_nombre (nombre)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ordenes_trabajo (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NOT NULL UNIQUE,
        estado VARCHAR(30) NOT NULL DEFAULT 'Vehículo recibido',
        notas TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_orden_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS recepciones (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NOT NULL UNIQUE,
        kilometraje VARCHAR(40) NULL,
        combustible VARCHAR(40) NULL,
        placas VARCHAR(40) NULL,
        danos TEXT NULL,
        testigos TEXT NULL,
        objetos TEXT NULL,
        notas TEXT NULL,
        firma_cliente VARCHAR(180) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_recepcion_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ampliación de recepción: firma dibujada y fotografías.
    $recCols = $pdo->query("SHOW COLUMNS FROM recepciones")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('firma_data', $recCols, true)) $pdo->exec("ALTER TABLE recepciones ADD COLUMN firma_data LONGTEXT NULL AFTER firma_cliente");
    $pdo->exec("CREATE TABLE IF NOT EXISTS recepcion_fotos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NOT NULL,
        archivo VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_foto_cotizacion (cotizacion_id),
        CONSTRAINT fk_foto_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS garantias (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NOT NULL,
        item_id INT UNSIGNED NULL,
        concepto VARCHAR(500) NOT NULL,
        fecha_inicio DATE NOT NULL,
        fecha_fin DATE NOT NULL,
        notas VARCHAR(700) NULL,
        estado VARCHAR(20) NOT NULL DEFAULT 'Vigente',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_garantia_cotizacion (cotizacion_id),
        KEY idx_garantia_fin (fecha_fin),
        CONSTRAINT fk_garantia_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");



    // ===== SRNU ERP LOCAL · MÓDULOS AVANZADOS =====
    // Clasificación operativa por servicio/producto.
    $catCols = $pdo->query("SHOW COLUMNS FROM catalogo_servicios")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tipo_entrega', $catCols, true)) $pdo->exec("ALTER TABLE catalogo_servicios ADD COLUMN tipo_entrega VARCHAR(30) NOT NULL DEFAULT 'inmediata' AFTER garantia_meses");
    if (!in_array('dias_entrega_estimada', $catCols, true)) $pdo->exec("ALTER TABLE catalogo_servicios ADD COLUMN dias_entrega_estimada INT NOT NULL DEFAULT 0 AFTER tipo_entrega");
    if (!in_array('dias_trabajo_min', $catCols, true)) $pdo->exec("ALTER TABLE catalogo_servicios ADD COLUMN dias_trabajo_min INT NOT NULL DEFAULT 0 AFTER dias_entrega_estimada");
    if (!in_array('dias_trabajo_max', $catCols, true)) $pdo->exec("ALTER TABLE catalogo_servicios ADD COLUMN dias_trabajo_max INT NOT NULL DEFAULT 0 AFTER dias_trabajo_min");
    if (!in_array('foto_producto', $catCols, true)) $pdo->exec("ALTER TABLE catalogo_servicios ADD COLUMN foto_producto VARCHAR(255) NULL AFTER dias_trabajo_max");
    if (!in_array('categoria', $catCols, true)) $pdo->exec("ALTER TABLE catalogo_servicios ADD COLUMN categoria VARCHAR(80) NULL AFTER foto_producto");
    if (!in_array('precio_fijo', $catCols, true)) $pdo->exec("ALTER TABLE catalogo_servicios ADD COLUMN precio_fijo TINYINT(1) NOT NULL DEFAULT 0 AFTER categoria");
    if (!in_array('menu_codigo', $catCols, true)) $pdo->exec("ALTER TABLE catalogo_servicios ADD COLUMN menu_codigo VARCHAR(80) NULL AFTER precio_fijo");
    if (!in_array('clave_cotizacion', $catCols, true)) $pdo->exec("ALTER TABLE catalogo_servicios ADD COLUMN clave_cotizacion CHAR(64) NULL, ADD UNIQUE KEY uq_catalogo_clave_cotizacion (clave_cotizacion)");
    try { $idxMenu=$pdo->query("SHOW INDEX FROM catalogo_servicios WHERE Key_name='uq_catalogo_menu_codigo'")->fetch(); if(!$idxMenu)$pdo->exec("ALTER TABLE catalogo_servicios ADD UNIQUE KEY uq_catalogo_menu_codigo (menu_codigo)"); } catch (Throwable $e) {}
    srnu_sembrar_catalogo_menu($pdo);

    $itemCols = $pdo->query("SHOW COLUMNS FROM cotizacion_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tipo_entrega', $itemCols, true)) $pdo->exec("ALTER TABLE cotizacion_items ADD COLUMN tipo_entrega VARCHAR(30) NOT NULL DEFAULT 'inmediata' AFTER catalogo_id");
    if (!in_array('dias_entrega_estimada', $itemCols, true)) $pdo->exec("ALTER TABLE cotizacion_items ADD COLUMN dias_entrega_estimada INT NOT NULL DEFAULT 0 AFTER tipo_entrega");
    if (!in_array('dias_trabajo_min', $itemCols, true)) $pdo->exec("ALTER TABLE cotizacion_items ADD COLUMN dias_trabajo_min INT NOT NULL DEFAULT 0 AFTER dias_entrega_estimada");
    if (!in_array('dias_trabajo_max', $itemCols, true)) $pdo->exec("ALTER TABLE cotizacion_items ADD COLUMN dias_trabajo_max INT NOT NULL DEFAULT 0 AFTER dias_trabajo_min");

    if (!in_array('foto_producto', $itemCols, true)) $pdo->exec("ALTER TABLE cotizacion_items ADD COLUMN foto_producto VARCHAR(255) NULL AFTER dias_trabajo_max");
    if (!in_array('mostrar_foto_pdf', $itemCols, true)) $pdo->exec("ALTER TABLE cotizacion_items ADD COLUMN mostrar_foto_pdf TINYINT(1) NOT NULL DEFAULT 1 AFTER foto_producto");

    $cotCols = $pdo->query("SHOW COLUMNS FROM cotizaciones")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('vehiculo_id', $cotCols, true)) $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN vehiculo_id INT UNSIGNED NULL AFTER vehiculo");
    if (!in_array('notas_internas_resumen', $cotCols, true)) $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN notas_internas_resumen VARCHAR(700) NULL AFTER aceptada_at");

    $ordCols = $pdo->query("SHOW COLUMNS FROM ordenes_trabajo")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('inventario_descontado', $ordCols, true)) $pdo->exec("ALTER TABLE ordenes_trabajo ADD COLUMN inventario_descontado TINYINT(1) NOT NULL DEFAULT 0 AFTER estado");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agenda_trabajos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NULL,
        titulo VARCHAR(220) NOT NULL,
        fecha_inicio DATE NOT NULL,
        hora_inicio TIME NULL,
        fecha_entrega DATE NULL,
        hora_entrega TIME NULL,
        responsable VARCHAR(80) NULL,
        estado VARCHAR(30) NOT NULL DEFAULT 'Programado',
        duracion_estimada_horas DECIMAL(8,2) NOT NULL DEFAULT 0.00,
        notas VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_agenda_fecha (fecha_inicio), KEY idx_agenda_cot (cotizacion_id),
        CONSTRAINT fk_agenda_cot FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS inventario (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sku VARCHAR(80) NULL,
        nombre VARCHAR(220) NOT NULL,
        categoria VARCHAR(120) NULL,
        unidad VARCHAR(40) NOT NULL DEFAULT 'pieza',
        stock DECIMAL(12,3) NOT NULL DEFAULT 0.000,
        stock_minimo DECIMAL(12,3) NOT NULL DEFAULT 0.000,
        costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        precio_referencia DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_inventario_sku (sku), KEY idx_inv_nombre (nombre)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS catalogo_insumos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        catalogo_id INT UNSIGNED NOT NULL,
        inventario_id INT UNSIGNED NOT NULL,
        cantidad DECIMAL(12,3) NOT NULL DEFAULT 1.000,
        UNIQUE KEY uq_catalogo_insumo (catalogo_id,inventario_id),
        CONSTRAINT fk_ci_catalogo FOREIGN KEY (catalogo_id) REFERENCES catalogo_servicios(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_ci_inventario FOREIGN KEY (inventario_id) REFERENCES inventario(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS movimientos_inventario (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        inventario_id INT UNSIGNED NOT NULL,
        tipo VARCHAR(30) NOT NULL,
        cantidad DECIMAL(12,3) NOT NULL,
        costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        referencia_tipo VARCHAR(40) NULL,
        referencia_id INT UNSIGNED NULL,
        notas VARCHAR(700) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_mov_inv (inventario_id), KEY idx_mov_fecha (created_at),
        CONSTRAINT fk_mov_inventario FOREIGN KEY (inventario_id) REFERENCES inventario(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS proveedores (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(220) NOT NULL,
        contacto VARCHAR(180) NULL,
        telefono VARCHAR(60) NULL,
        email VARCHAR(180) NULL,
        pais VARCHAR(100) NULL,
        tiempo_entrega_dias INT NOT NULL DEFAULT 0,
        notas VARCHAR(1000) NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_proveedor_nombre (nombre)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS compras (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        proveedor_id INT UNSIGNED NULL,
        cotizacion_id INT UNSIGNED NULL,
        fecha DATE NOT NULL,
        numero_pedido VARCHAR(120) NULL,
        tracking VARCHAR(220) NULL,
        estatus VARCHAR(40) NOT NULL DEFAULT 'Solicitado',
        total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        fecha_estimada DATE NULL,
        notas VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_compra_fecha (fecha), KEY idx_compra_cot (cotizacion_id),
        CONSTRAINT fk_compra_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_compra_cot FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $compCols = $pdo->query("SHOW COLUMNS FROM compras")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('inventario_aplicado', $compCols, true)) $pdo->exec("ALTER TABLE compras ADD COLUMN inventario_aplicado TINYINT(1) NOT NULL DEFAULT 0 AFTER estatus");

    $pdo->exec("CREATE TABLE IF NOT EXISTS compra_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        compra_id INT UNSIGNED NOT NULL,
        inventario_id INT UNSIGNED NULL,
        descripcion VARCHAR(300) NOT NULL,
        cantidad DECIMAL(12,3) NOT NULL DEFAULT 1.000,
        costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        CONSTRAINT fk_compra_item_compra FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_compra_item_inv FOREIGN KEY (inventario_id) REFERENCES inventario(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS gastos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fecha DATE NOT NULL,
        categoria VARCHAR(100) NOT NULL,
        concepto VARCHAR(300) NOT NULL,
        monto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        metodo VARCHAR(60) NULL,
        responsable VARCHAR(80) NULL,
        cotizacion_id INT UNSIGNED NULL,
        notas VARCHAR(700) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_gasto_fecha (fecha), KEY idx_gasto_cot (cotizacion_id),
        CONSTRAINT fk_gasto_cot FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cierres_caja (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fecha DATE NOT NULL UNIQUE,
        efectivo DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        transferencias DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        tarjeta DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        comisiones DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        otros DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        esperado DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        real_conciliado DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        diferencia DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        notas VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Compatibilidad con una posible instalación previa que hubiera creado la columna `real`.
    $cajaCols = $pdo->query("SHOW COLUMNS FROM cierres_caja")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('real', $cajaCols, true) && !in_array('real_conciliado', $cajaCols, true)) {
        $pdo->exec("ALTER TABLE cierres_caja CHANGE COLUMN `real` real_conciliado DECIMAL(12,2) NOT NULL DEFAULT 0.00");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS vehiculos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cliente VARCHAR(180) NOT NULL,
        telefono VARCHAR(60) NULL,
        descripcion VARCHAR(220) NOT NULL,
        placas VARCHAR(50) NULL,
        vin VARCHAR(80) NULL,
        kilometraje VARCHAR(50) NULL,
        color VARCHAR(80) NULL,
        notas TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_veh_cliente (cliente), KEY idx_veh_placas (placas), KEY idx_veh_vin (vin)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS costos_reales (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NOT NULL,
        fecha DATE NOT NULL,
        categoria VARCHAR(100) NOT NULL,
        concepto VARCHAR(300) NOT NULL,
        monto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        responsable VARCHAR(80) NULL,
        notas VARCHAR(700) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_costoreal_cot (cotizacion_id),
        CONSTRAINT fk_costoreal_cot FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS galeria_trabajos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NOT NULL,
        etapa VARCHAR(30) NOT NULL DEFAULT 'General',
        archivo VARCHAR(255) NOT NULL,
        descripcion VARCHAR(500) NULL,
        publicable TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_galeria_cot (cotizacion_id),
        CONSTRAINT fk_galeria_cot FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS plantillas (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(220) NOT NULL,
        descripcion VARCHAR(700) NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS plantilla_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        plantilla_id INT UNSIGNED NOT NULL,
        catalogo_id INT UNSIGNED NULL,
        concepto VARCHAR(500) NOT NULL,
        cantidad DECIMAL(12,2) NOT NULL DEFAULT 1.00,
        precio DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        costo_interno DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        responsable_sergio TINYINT(1) NOT NULL DEFAULT 0,
        responsable_ricardo TINYINT(1) NOT NULL DEFAULT 0,
        responsable_tomas TINYINT(1) NOT NULL DEFAULT 0,
        garantia_meses INT NOT NULL DEFAULT 0,
        tipo_entrega VARCHAR(30) NOT NULL DEFAULT 'inmediata',
        dias_entrega_estimada INT NOT NULL DEFAULT 0,
        dias_trabajo_min INT NOT NULL DEFAULT 0,
        dias_trabajo_max INT NOT NULL DEFAULT 0,
        CONSTRAINT fk_pi_plantilla FOREIGN KEY (plantilla_id) REFERENCES plantillas(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_pi_catalogo FOREIGN KEY (catalogo_id) REFERENCES catalogo_servicios(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $tplCols = $pdo->query("SHOW COLUMNS FROM plantilla_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('dias_trabajo_min', $tplCols, true)) $pdo->exec("ALTER TABLE plantilla_items ADD COLUMN dias_trabajo_min INT NOT NULL DEFAULT 0 AFTER dias_entrega_estimada");
    if (!in_array('dias_trabajo_max', $tplCols, true)) $pdo->exec("ALTER TABLE plantilla_items ADD COLUMN dias_trabajo_max INT NOT NULL DEFAULT 0 AFTER dias_trabajo_min");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notas_internas (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NOT NULL,
        nota TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_nota_cot (cotizacion_id),
        CONSTRAINT fk_nota_cot FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS adjuntos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NOT NULL,
        archivo VARCHAR(255) NOT NULL,
        nombre_original VARCHAR(255) NOT NULL,
        tipo VARCHAR(120) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_adj_cot (cotizacion_id),
        CONSTRAINT fk_adj_cot FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reparto_ajustes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NOT NULL,
        persona VARCHAR(30) NOT NULL,
        monto_objetivo DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        notas VARCHAR(500) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_reparto_persona (cotizacion_id,persona),
        CONSTRAINT fk_reparto_cot FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS postventa_recordatorios (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cotizacion_id INT UNSIGNED NOT NULL,
        garantia_id INT UNSIGNED NULL,
        tipo VARCHAR(80) NOT NULL DEFAULT 'Seguimiento',
        fecha_recordatorio DATE NOT NULL,
        descripcion VARCHAR(700) NOT NULL,
        estado VARCHAR(30) NOT NULL DEFAULT 'Pendiente',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_postventa_fecha (fecha_recordatorio), KEY idx_postventa_cot (cotizacion_id),
        CONSTRAINT fk_postventa_cot FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_postventa_gar FOREIGN KEY (garantia_id) REFERENCES garantias(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

}

function srnu_slug_filename(string $value): string
{
    $value = trim($value);
    $value = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value, " .");
}

function srnu_pdf_filename(string $cliente, string $vehiculo, string $factura): string
{
    $estado = $factura === 'si' ? 'Con Factura' : 'Sin Factura';
    $cliente = srnu_slug_filename($cliente) ?: 'Cliente';
    $vehiculo = srnu_slug_filename($vehiculo) ?: 'Vehiculo';
    return $cliente . ', ' . $vehiculo . ', ' . $estado . '.pdf';
}

/**
 * Calcula el reparto interno de los conceptos antes de IVA.
 * Si un concepto tiene varios responsables, su importe se divide por igual
 * entre ellos; el último responsable absorbe cualquier centavo residual.
 */
function srnu_reparto_interno_conceptos(array $items): array
{
    $totales = [
        'sergio' => 0.00,
        'ricardo' => 0.00,
        'tomas' => 0.00,
        'sin_asignar' => 0.00,
    ];

    foreach ($items as $item) {
        $total = round(max(0, (float)($item['total'] ?? 0)), 2);
        if ($total <= 0) continue;

        $responsables = [];
        if (!empty($item['responsable_sergio'])) $responsables[] = 'sergio';
        if (!empty($item['responsable_ricardo'])) $responsables[] = 'ricardo';
        if (!empty($item['responsable_tomas'])) $responsables[] = 'tomas';

        if (!$responsables) {
            $totales['sin_asignar'] = round($totales['sin_asignar'] + $total, 2);
            continue;
        }

        $centavos = (int)round($total * 100);
        $base = intdiv($centavos, count($responsables));
        $resto = $centavos - ($base * count($responsables));

        foreach ($responsables as $i => $persona) {
            $parteCentavos = $base + (($i === count($responsables) - 1) ? $resto : 0);
            $totales[$persona] = round($totales[$persona] + ($parteCentavos / 100), 2);
        }
    }

    return $totales;
}

/**
 * Resumen administrativo. No forma parte del PDF del cliente.
 * El IVA se maneja como una cuarta participación interna y nunca recibe anticipo.
 */
function srnu_resumen_interno(
    array $items,
    float $subtotal,
    float $iva,
    bool $dividirAnticipo,
    float $anticipoSergio,
    float $anticipoRicardo,
    float $anticipoTomas
): array {
    $reparto = srnu_reparto_interno_conceptos($items);
    $recibido = [
        'sergio' => $dividirAnticipo ? max(0, round($anticipoSergio, 2)) : 0.00,
        'ricardo' => $dividirAnticipo ? max(0, round($anticipoRicardo, 2)) : 0.00,
        'tomas' => $dividirAnticipo ? max(0, round($anticipoTomas, 2)) : 0.00,
    ];

    $personas = [];
    foreach (['sergio','ricardo','tomas'] as $persona) {
        $corresponde = round((float)$reparto[$persona], 2);
        $anticipo = round((float)$recibido[$persona], 2);
        $personas[$persona] = [
            'corresponde' => $corresponde,
            'anticipo' => $anticipo,
            'restante' => round(max(0, $corresponde - $anticipo), 2),
            'excedente' => round(max(0, $anticipo - $corresponde), 2),
        ];
    }

    $iva = round(max(0, $iva), 2);
    $subtotal = round(max(0, $subtotal), 2);
    $anticipoTotal = round($recibido['sergio'] + $recibido['ricardo'] + $recibido['tomas'], 2);

    return [
        'personas' => $personas,
        'iva' => [
            'corresponde' => $iva,
            'anticipo' => 0.00,
            'restante' => $iva,
            'excedente' => 0.00,
        ],
        'sin_asignar' => round((float)$reparto['sin_asignar'], 2),
        'subtotal' => $subtotal,
        'total_con_iva' => round($subtotal + $iva, 2),
        'anticipo_total_integrantes' => $anticipoTotal,
        'anticipo_sin_distribuir' => $dividirAnticipo ? 0.00 : 0.00,
    ];
}

function srnu_total_pagado(int $cotizacionId): float
{
    if ($cotizacionId <= 0) return 0.0;
    $stmt = srnu_db()->prepare('SELECT COALESCE(SUM(monto),0) FROM pagos WHERE cotizacion_id=?');
    $stmt->execute([$cotizacionId]);
    return round((float)$stmt->fetchColumn(), 2);
}

function srnu_crear_version_cotizacion(int $cotizacionId, string $motivo = 'Edición de cotización'): void
{
    if ($cotizacionId <= 0) return;
    $pdo = srnu_db();
    $stmt = $pdo->prepare('SELECT * FROM cotizaciones WHERE id=?');
    $stmt->execute([$cotizacionId]);
    $cot = $stmt->fetch();
    if (!$cot) return;
    $stmt = $pdo->prepare('SELECT * FROM cotizacion_items WHERE cotizacion_id=? ORDER BY id');
    $stmt->execute([$cotizacionId]);
    $cot['items'] = $stmt->fetchAll();
    $ver = $pdo->prepare('SELECT COALESCE(MAX(version_no),0)+1 FROM cotizacion_versiones WHERE cotizacion_id=?');
    $ver->execute([$cotizacionId]);
    $versionNo = (int)$ver->fetchColumn();
    $ins = $pdo->prepare('INSERT INTO cotizacion_versiones (cotizacion_id,version_no,snapshot_json,motivo,created_at) VALUES (?,?,?,?,NOW())');
    $ins->execute([$cotizacionId,$versionNo,json_encode($cot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$motivo]);
}

function srnu_normalizar_telefono(string $telefono): string
{
    $n = preg_replace('/\D+/', '', $telefono) ?? '';
    // Para números mexicanos guardados como +52XXXXXXXXXX, usamos los 10 dígitos locales.
    if (strlen($n) === 12 && str_starts_with($n, '52')) $n = substr($n, 2);
    return $n;
}

function srnu_clientes_activos(): array
{
    try {
        return srnu_db()->query("SELECT * FROM clientes WHERE activo=1 ORDER BY nombre, telefono")->fetchAll();
    } catch (Throwable $e) { return []; }
}

function srnu_obtener_cliente(int $id): ?array
{
    if ($id <= 0) return null;
    $s = srnu_db()->prepare('SELECT * FROM clientes WHERE id=?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function srnu_guardar_cliente(string $nombre, string $telefono, int $id = 0, string $email = '', string $notas = ''): int
{
    $nombre = trim($nombre);
    $telefono = trim($telefono);
    $normalizado = srnu_normalizar_telefono($telefono);
    if ($nombre === '' || $normalizado === '') return 0;
    $pdo = srnu_db();
    if ($id > 0) {
        $s = $pdo->prepare('UPDATE clientes SET nombre=?, telefono=?, telefono_normalizado=?, email=?, notas=?, activo=1 WHERE id=?');
        $s->execute([$nombre,$telefono,$normalizado,trim($email),trim($notas),$id]);
        return $id;
    }
    $s = $pdo->prepare('SELECT id FROM clientes WHERE telefono_normalizado=? LIMIT 1');
    $s->execute([$normalizado]);
    $existente = (int)($s->fetchColumn() ?: 0);
    if ($existente > 0) {
        $pdo->prepare('UPDATE clientes SET nombre=?, telefono=?, email=IF(?<>\'\',?,email), notas=IF(?<>\'\',?,notas), activo=1 WHERE id=?')
            ->execute([$nombre,$telefono,trim($email),trim($email),trim($notas),trim($notas),$existente]);
        return $existente;
    }
    $s = $pdo->prepare('INSERT INTO clientes(nombre,telefono,telefono_normalizado,email,notas,activo) VALUES(?,?,?,?,?,1)');
    $s->execute([$nombre,$telefono,$normalizado,trim($email),trim($notas)]);
    return (int)$pdo->lastInsertId();
}

/** Guarda una fotografía de producto de forma segura dentro de uploads/productos. */
function srnu_guardar_foto_producto_upload(array $file, ?string $actual = null): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return $actual ?: null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) return $actual ?: null;
    if ((int)($file['size'] ?? 0) > 8 * 1024 * 1024) return $actual ?: null;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file((string)$file['tmp_name']);
    $exts = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($exts[$mime])) return $actual ?: null;
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'productos';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir)) return $actual ?: null;
    $deny = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($deny)) @file_put_contents($deny, "Options -Indexes\n<FilesMatch \\\"\\.(php|phtml|phar|cgi|pl|py|sh)$\\\">\nRequire all denied\n</FilesMatch>\n");
    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $exts[$mime];
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!@move_uploaded_file((string)$file['tmp_name'], $dest)) return $actual ?: null;
    return 'uploads/productos/' . $name;
}

function srnu_archivo_upload_indice(string $campo, int $i): array
{
    if (empty($_FILES[$campo]) || !is_array($_FILES[$campo]['name'] ?? null)) return ['error'=>UPLOAD_ERR_NO_FILE];
    return [
        'name' => $_FILES[$campo]['name'][$i] ?? '',
        'type' => $_FILES[$campo]['type'][$i] ?? '',
        'tmp_name' => $_FILES[$campo]['tmp_name'][$i] ?? '',
        'error' => $_FILES[$campo]['error'][$i] ?? UPLOAD_ERR_NO_FILE,
        'size' => $_FILES[$campo]['size'][$i] ?? 0,
    ];
}

function srnu_imagen_producto_data_uri(?string $relative): string
{
    $relative = trim((string)$relative);
    if ($relative === '' || !str_starts_with(str_replace('\\\\','/',$relative), 'uploads/productos/')) return '';
    $full = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($full) || filesize($full) > 10 * 1024 * 1024) return '';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($full);
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) return '';
    $bytes = @file_get_contents($full);
    return $bytes === false ? '' : 'data:' . $mime . ';base64,' . base64_encode($bytes);
}

function srnu_foto_catalogo(int $catalogoId): ?string
{
    if ($catalogoId <= 0) return null;
    try {
        $st = srnu_db()->prepare('SELECT foto_producto FROM catalogo_servicios WHERE id=? LIMIT 1');
        $st->execute([$catalogoId]);
        $v = $st->fetchColumn();
        return $v ? (string)$v : null;
    } catch (Throwable $e) { return null; }
}

function srnu_catalogo_activo(): array
{
    try {
        return srnu_db()->query('SELECT * FROM catalogo_servicios WHERE activo=1 ORDER BY nombre')->fetchAll();
    } catch (Throwable $e) { return []; }
}

function srnu_guardar_cotizacion(array $data, array $items, int $id = 0): int
{
    // Validación en servidor: ni peticiones manipuladas ni modo rápido pueden evitar la factura.
    srnu_aplicar_bbva_pyme($data);
    $pdo = srnu_db();
    $pdo->beginTransaction();
    try {
        if (!empty($data['guardar_articulos_catalogo'])) srnu_catalogar_items_cotizacion($pdo, $items);
        $msi = json_encode($data['msi_plazos'] ?? [], JSON_UNESCAPED_UNICODE);
        $clienteId = (int)($data['cliente_id'] ?? 0);
        if ($clienteId <= 0) {
            $clienteId = srnu_guardar_cliente((string)($data['cliente'] ?? ''), (string)($data['telefono'] ?? ''));
        } else {
            // Mantener sincronizado el registro si el usuario corrigió nombre o teléfono.
            $clienteId = srnu_guardar_cliente((string)($data['cliente'] ?? ''), (string)($data['telefono'] ?? ''), $clienteId);
        }
        $estado = trim((string)($data['estado'] ?? 'Cotización')) ?: 'Cotización';
        $vigencia = max(1, (int)($data['vigencia_dias'] ?? 15));
        $trabajoGeneralMin = max(0, min(365, (int)($data['dias_trabajo_general_min'] ?? 0)));
        $trabajoGeneralMax = max(0, min(365, (int)($data['dias_trabajo_general_max'] ?? 0)));
        if ($trabajoGeneralMax > 0 && $trabajoGeneralMin > $trabajoGeneralMax) { [$trabajoGeneralMin, $trabajoGeneralMax] = [$trabajoGeneralMax, $trabajoGeneralMin]; }
        if ($id > 0) {
            // Guardar una fotografía de la versión anterior antes de modificarla.
            srnu_crear_version_cotizacion($id, (string)($data['motivo_version'] ?? 'Edición de cotización'));
            $stmt = $pdo->prepare('UPDATE cotizaciones SET cliente=?, telefono=?, cliente_id=?, vehiculo=?, fecha=?, requiere_factura=?, subtotal=?, iva=?, total=?, msi_plazos=?, anticipo_tipo=?, anticipo_valor=?, conservar_anticipo_recibido=?, anticipo_recibido_monto=?, dividir_anticipo=?, repartir_igual=?, anticipo_sergio=?, anticipo_ricardo=?, anticipo_tomas=?, participa_sergio=?, participa_ricardo=?, participa_tomas=?, estado=?, vigencia_dias=?, dias_trabajo_general_min=?, dias_trabajo_general_max=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([$data['cliente'],$data['telefono'],$clienteId,$data['vehiculo'],$data['fecha_raw'],$data['requiere_factura'],$data['subtotal'],$data['iva'],$data['total'],$msi,$data['anticipo_tipo'] ?? 'porcentaje',$data['anticipo_valor'] ?? 70,$data['conservar_anticipo_recibido'] ?? 0,$data['anticipo_recibido_monto'] ?? 0,$data['dividir_anticipo'] ?? 0,$data['repartir_igual'] ?? 0,$data['anticipo_sergio'] ?? 0,$data['anticipo_ricardo'] ?? 0,$data['anticipo_tomas'] ?? 0,$data['participa_sergio'] ?? 1,$data['participa_ricardo'] ?? 1,$data['participa_tomas'] ?? 0,$estado,$vigencia,$trabajoGeneralMin,$trabajoGeneralMax,$id]);
            $pdo->prepare('DELETE FROM cotizacion_items WHERE cotizacion_id=?')->execute([$id]);
        } else {
            $tempFolio = 'TMP-' . bin2hex(random_bytes(6));
            $stmt = $pdo->prepare('INSERT INTO cotizaciones (folio, cliente, telefono, cliente_id, vehiculo, fecha, requiere_factura, subtotal, iva, total, msi_plazos, anticipo_tipo, anticipo_valor, conservar_anticipo_recibido, anticipo_recibido_monto, dividir_anticipo, repartir_igual, anticipo_sergio, anticipo_ricardo, anticipo_tomas, participa_sergio, participa_ricardo, participa_tomas, estado, vigencia_dias, dias_trabajo_general_min, dias_trabajo_general_max, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
            $stmt->execute([$tempFolio,$data['cliente'],$data['telefono'],$clienteId,$data['vehiculo'],$data['fecha_raw'],$data['requiere_factura'],$data['subtotal'],$data['iva'],$data['total'],$msi,$data['anticipo_tipo'] ?? 'porcentaje',$data['anticipo_valor'] ?? 70,$data['conservar_anticipo_recibido'] ?? 0,$data['anticipo_recibido_monto'] ?? 0,$data['dividir_anticipo'] ?? 0,$data['repartir_igual'] ?? 0,$data['anticipo_sergio'] ?? 0,$data['anticipo_ricardo'] ?? 0,$data['anticipo_tomas'] ?? 0,$data['participa_sergio'] ?? 1,$data['participa_ricardo'] ?? 1,$data['participa_tomas'] ?? 0,$estado,$vigencia,$trabajoGeneralMin,$trabajoGeneralMax]);
            $id = (int)$pdo->lastInsertId();
            $folio = 'SRNU-' . date('Y') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE cotizaciones SET folio=? WHERE id=?')->execute([$folio,$id]);
        }
        $itemStmt = $pdo->prepare('INSERT INTO cotizacion_items (cotizacion_id, concepto, cantidad, precio, costo_interno, total, responsable_sergio, responsable_ricardo, responsable_tomas, garantia_meses, catalogo_id, tipo_entrega, dias_entrega_estimada, dias_trabajo_min, dias_trabajo_max, foto_producto, mostrar_foto_pdf) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($items as $item) {
            $itemStmt->execute([
                $id,$item['concepto'],$item['cantidad'],$item['precio'],
                max(0,(float)($item['costo_interno'] ?? 0)),$item['total'],
                !empty($item['responsable_sergio']) ? 1 : 0,
                !empty($item['responsable_ricardo']) ? 1 : 0,
                !empty($item['responsable_tomas']) ? 1 : 0,
                max(0,(int)($item['garantia_meses'] ?? 0)),
                !empty($item['catalogo_id']) ? (int)$item['catalogo_id'] : null,
                in_array(($item['tipo_entrega'] ?? 'inmediata'), ['inmediata','sobre_pedido'], true) ? $item['tipo_entrega'] : 'inmediata',
                max(0,(int)($item['dias_entrega_estimada'] ?? 0)),
                max(0,(int)($item['dias_trabajo_min'] ?? 0)),
                max(0,(int)($item['dias_trabajo_max'] ?? 0)),
                !empty($item['foto_producto']) ? (string)$item['foto_producto'] : null,
                array_key_exists('mostrar_foto_pdf', $item) ? (!empty($item['mostrar_foto_pdf']) ? 1 : 0) : 1,
            ]);
        }
        if ($estado === 'Aprobada') {
            $pdo->prepare('UPDATE cotizaciones SET aceptada_at=COALESCE(aceptada_at,NOW()) WHERE id=?')->execute([$id]);
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function srnu_obtener_cotizacion(int $id): ?array
{
    if ($id <= 0) return null;
    $pdo = srnu_db();
    $stmt = $pdo->prepare('SELECT * FROM cotizaciones WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $stmt = $pdo->prepare('SELECT id, concepto, cantidad, precio, costo_interno, total, responsable_sergio, responsable_ricardo, responsable_tomas, garantia_meses, catalogo_id, tipo_entrega, dias_entrega_estimada, dias_trabajo_min, dias_trabajo_max, foto_producto, mostrar_foto_pdf FROM cotizacion_items WHERE cotizacion_id=? ORDER BY id');
    $stmt->execute([$id]);
    $row['items'] = $stmt->fetchAll();
    $row['msi_plazos'] = json_decode((string)$row['msi_plazos'], true) ?: [];
    return $row;
}

/** Devuelve un hash estable de los datos visibles/contractuales de la cotización. */
function srnu_hash_cotizacion_para_firma(array $cotizacion): string
{
    $items = [];
    foreach (($cotizacion['items'] ?? []) as $it) {
        $hashItem = [
            'concepto' => (string)($it['concepto'] ?? ''),
            'cantidad' => number_format((float)($it['cantidad'] ?? 0), 2, '.', ''),
            'precio' => number_format((float)($it['precio'] ?? 0), 2, '.', ''),
            'total' => number_format((float)($it['total'] ?? 0), 2, '.', ''),
            'garantia_meses' => (int)($it['garantia_meses'] ?? 0),
            'tipo_entrega' => (string)($it['tipo_entrega'] ?? 'inmediata'),
            'dias_entrega_estimada' => (int)($it['dias_entrega_estimada'] ?? 0),
            'foto_producto' => !empty($it['mostrar_foto_pdf']) ? (string)($it['foto_producto'] ?? '') : '',
            'mostrar_foto_pdf' => !empty($it['mostrar_foto_pdf']) ? 1 : 0,
        ];
        $items[] = $hashItem;
    }
    $payload = [
        'id' => (int)($cotizacion['id'] ?? 0),
        'folio' => (string)($cotizacion['folio'] ?? ''),
        'cliente' => (string)($cotizacion['cliente'] ?? ''),
        'telefono' => (string)($cotizacion['telefono'] ?? ''),
        'vehiculo' => (string)($cotizacion['vehiculo'] ?? ''),
        'fecha' => (string)($cotizacion['fecha'] ?? ''),
        'requiere_factura' => (string)($cotizacion['requiere_factura'] ?? ''),
        'subtotal' => number_format((float)($cotizacion['subtotal'] ?? 0), 2, '.', ''),
        'iva' => number_format((float)($cotizacion['iva'] ?? 0), 2, '.', ''),
        'total' => number_format((float)($cotizacion['total'] ?? 0), 2, '.', ''),
        'msi_plazos' => array_values($cotizacion['msi_plazos'] ?? []),
        'anticipo_tipo' => (string)($cotizacion['anticipo_tipo'] ?? ''),
        'anticipo_valor' => number_format((float)($cotizacion['anticipo_valor'] ?? 0), 2, '.', ''),
        'vigencia_dias' => (int)($cotizacion['vigencia_dias'] ?? 15),
        'items' => $items,
    ];
    // Compatibilidad con aceptaciones anteriores: el nuevo rango general solo
    // altera el hash contractual cuando realmente se capturó un plazo.
    $tgMin = (int)($cotizacion['dias_trabajo_general_min'] ?? 0);
    $tgMax = (int)($cotizacion['dias_trabajo_general_max'] ?? 0);
    if ($tgMin > 0 || $tgMax > 0) {
        $payload['dias_trabajo_general_min'] = $tgMin;
        $payload['dias_trabajo_general_max'] = $tgMax;
    }
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function srnu_obtener_firma_digital(int $cotizacionId): ?array
{
    if ($cotizacionId <= 0) return null;
    $stmt = srnu_db()->prepare('SELECT * FROM firmas_digitales WHERE cotizacion_id=? LIMIT 1');
    $stmt->execute([$cotizacionId]);
    return $stmt->fetch() ?: null;
}

/** Crea o regenera el enlace de firma para la versión actual de la cotización. */
function srnu_preparar_firma_digital(int $cotizacionId, bool $regenerar = false): ?array
{
    $cot = srnu_obtener_cotizacion($cotizacionId);
    if (!$cot) return null;
    $pdo = srnu_db();
    $firma = srnu_obtener_firma_digital($cotizacionId);
    $hash = srnu_hash_cotizacion_para_firma($cot);
    if (!$firma) {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO firmas_digitales(cotizacion_id,token,estado,snapshot_hash,created_at,updated_at) VALUES(?,?,'pendiente',?,NOW(),NOW())")
            ->execute([$cotizacionId,$token,$hash]);
    } elseif ($regenerar) {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE firmas_digitales SET token=?, estado='pendiente', nombre_firmante=NULL, firma_data=NULL, snapshot_hash=?, hash_verificacion=NULL, acepto=0, firmado_at=NULL, ip_firmante=NULL, user_agent=NULL, updated_at=NOW() WHERE cotizacion_id=?")
            ->execute([$token,$hash,$cotizacionId]);
    } elseif (($firma['estado'] ?? '') === 'pendiente' && !hash_equals((string)$firma['snapshot_hash'], $hash)) {
        // Si aún no estaba firmada, actualizamos el enlace para la versión actual.
        $pdo->prepare('UPDATE firmas_digitales SET snapshot_hash=?, updated_at=NOW() WHERE cotizacion_id=?')->execute([$hash,$cotizacionId]);
    }
    return srnu_obtener_firma_digital($cotizacionId);
}

function srnu_firma_digital_es_valida(array $cotizacion, ?array $firma = null): bool
{
    if (!$firma || ($firma['estado'] ?? '') !== 'firmada' || empty($firma['firma_data']) || empty($firma['snapshot_hash'])) return false;
    return hash_equals((string)$firma['snapshot_hash'], srnu_hash_cotizacion_para_firma($cotizacion));
}


/** Obtiene la firma interna del taller asociada a una cotización. */
function srnu_obtener_firma_taller(int $cotizacionId): ?array
{
    if ($cotizacionId <= 0) return null;
    $stmt = srnu_db()->prepare('SELECT * FROM firmas_taller WHERE cotizacion_id=? LIMIT 1');
    $stmt->execute([$cotizacionId]);
    return $stmt->fetch() ?: null;
}

/** Prepara o reinicia la firma del taller para la versión actual. */
function srnu_preparar_firma_taller(int $cotizacionId, bool $reiniciar = false): ?array
{
    $cot = srnu_obtener_cotizacion($cotizacionId);
    if (!$cot) return null;
    $pdo = srnu_db();
    $firma = srnu_obtener_firma_taller($cotizacionId);
    $hash = srnu_hash_cotizacion_para_firma($cot);
    if (!$firma) {
        $pdo->prepare("INSERT INTO firmas_taller(cotizacion_id,estado,snapshot_hash,created_at,updated_at) VALUES(?,'pendiente',?,NOW(),NOW())")->execute([$cotizacionId,$hash]);
    } elseif ($reiniciar) {
        $pdo->prepare("UPDATE firmas_taller SET estado='pendiente', nombre_firmante=NULL, firma_data=NULL, snapshot_hash=?, hash_verificacion=NULL, firmado_at=NULL, ip_firmante=NULL, user_agent=NULL, updated_at=NOW() WHERE cotizacion_id=?")->execute([$hash,$cotizacionId]);
    } elseif (($firma['estado'] ?? '') === 'pendiente' && !hash_equals((string)$firma['snapshot_hash'], $hash)) {
        $pdo->prepare('UPDATE firmas_taller SET snapshot_hash=?, updated_at=NOW() WHERE cotizacion_id=?')->execute([$hash,$cotizacionId]);
    }
    return srnu_obtener_firma_taller($cotizacionId);
}

function srnu_firma_taller_es_valida(array $cotizacion, ?array $firma = null): bool
{
    if (!$firma || ($firma['estado'] ?? '') !== 'firmada' || empty($firma['firma_data']) || empty($firma['snapshot_hash'])) return false;
    return hash_equals((string)$firma['snapshot_hash'], srnu_hash_cotizacion_para_firma($cotizacion));
}


/** Obtiene la confirmación local asociada a una cotización. */
function srnu_obtener_confirmacion_local(int $cotizacionId): ?array
{
    if ($cotizacionId <= 0) return null;
    $stmt = srnu_db()->prepare('SELECT * FROM confirmaciones_locales WHERE cotizacion_id=? LIMIT 1');
    $stmt->execute([$cotizacionId]);
    return $stmt->fetch() ?: null;
}

/** Crea o regenera el enlace de confirmación local para la versión actual. */
function srnu_preparar_confirmacion_local(int $cotizacionId, bool $regenerar = false): ?array
{
    $cot = srnu_obtener_cotizacion($cotizacionId);
    if (!$cot) return null;
    $pdo = srnu_db();
    $confirmacion = srnu_obtener_confirmacion_local($cotizacionId);
    $hash = srnu_hash_cotizacion_para_firma($cot);
    if (!$confirmacion) {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO confirmaciones_locales(cotizacion_id,token,estado,snapshot_hash,created_at,updated_at) VALUES(?,?,'pendiente',?,NOW(),NOW())")
            ->execute([$cotizacionId,$token,$hash]);
    } elseif ($regenerar) {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE confirmaciones_locales SET token=?, estado='pendiente', nombre_confirmante=NULL, snapshot_hash=?, hash_verificacion=NULL, acepto=0, confirmado_at=NULL, ip_confirmante=NULL, user_agent=NULL, updated_at=NOW() WHERE cotizacion_id=?")
            ->execute([$token,$hash,$cotizacionId]);
    } elseif (($confirmacion['estado'] ?? '') === 'pendiente' && !hash_equals((string)$confirmacion['snapshot_hash'], $hash)) {
        $pdo->prepare('UPDATE confirmaciones_locales SET snapshot_hash=?, updated_at=NOW() WHERE cotizacion_id=?')->execute([$hash,$cotizacionId]);
    }
    return srnu_obtener_confirmacion_local($cotizacionId);
}

/** Comprueba que la aceptación local corresponde exactamente a la versión actual. */
function srnu_confirmacion_local_es_valida(array $cotizacion, ?array $confirmacion = null): bool
{
    if (!$confirmacion || ($confirmacion['estado'] ?? '') !== 'confirmada' || empty($confirmacion['snapshot_hash']) || empty($confirmacion['acepto'])) return false;
    return hash_equals((string)$confirmacion['snapshot_hash'], srnu_hash_cotizacion_para_firma($cotizacion));
}



/**
 * Construye un respaldo PORTABLE de los datos del cliente que ya son visibles
 * en la cotización. No incluye costos internos, pagos, notas privadas ni otros
 * datos administrativos que no deban viajar con el PDF del cliente.
 */
function srnu_pdf_respaldo_publico(array $cotizacion): array
{
    $items = [];
    foreach (($cotizacion['items'] ?? []) as $item) {
        $items[] = [
            'concepto' => (string)($item['concepto'] ?? ''),
            'cantidad' => (float)($item['cantidad'] ?? 0),
            'precio' => (float)($item['precio'] ?? 0),
            'responsable_sergio' => !empty($item['responsable_sergio']) ? 1 : 0,
            'responsable_ricardo' => !empty($item['responsable_ricardo']) ? 1 : 0,
            'responsable_tomas' => !empty($item['responsable_tomas']) ? 1 : 0,
            'garantia_meses' => max(0, (int)($item['garantia_meses'] ?? 0)),
            'tipo_entrega' => (($item['tipo_entrega'] ?? 'inmediata') === 'sobre_pedido') ? 'sobre_pedido' : 'inmediata',
            'dias_entrega_estimada' => max(0, (int)($item['dias_entrega_estimada'] ?? 0)),
            // La ruta local puede permitir recuperar la foto si el archivo aún existe.
            'foto_producto' => (string)($item['foto_producto'] ?? ''),
            'mostrar_foto_pdf' => !empty($item['mostrar_foto_pdf']) ? 1 : 0,
        ];
    }
    return [
        'formato' => 'SRNU_PDF_BACKUP',
        'version' => 1,
        'folio_origen' => (string)($cotizacion['folio'] ?? ''),
        'cliente' => (string)($cotizacion['cliente'] ?? ''),
        'telefono' => (string)($cotizacion['telefono'] ?? ''),
        'vehiculo' => (string)($cotizacion['vehiculo'] ?? ''),
        'fecha' => (string)($cotizacion['fecha'] ?? date('Y-m-d')),
        'requiere_factura' => (($cotizacion['requiere_factura'] ?? 'no') === 'si') ? 'si' : 'no',
        'msi_plazos' => array_values(array_map('strval', is_array($cotizacion['msi_plazos'] ?? null) ? $cotizacion['msi_plazos'] : [])),
        'anticipo_tipo' => in_array(($cotizacion['anticipo_tipo'] ?? 'porcentaje'), ['porcentaje','monto'], true) ? $cotizacion['anticipo_tipo'] : 'porcentaje',
        'anticipo_valor' => (float)($cotizacion['anticipo_valor'] ?? 70),
        'conservar_anticipo_recibido' => !empty($cotizacion['conservar_anticipo_recibido']) ? 1 : 0,
        'anticipo_recibido_monto' => (float)($cotizacion['anticipo_recibido_monto'] ?? 0),
        'dividir_anticipo' => !empty($cotizacion['dividir_anticipo']) ? 1 : 0,
        'repartir_igual' => !empty($cotizacion['repartir_igual']) ? 1 : 0,
        'anticipo_sergio' => (float)($cotizacion['anticipo_sergio'] ?? 0),
        'anticipo_ricardo' => (float)($cotizacion['anticipo_ricardo'] ?? 0),
        'anticipo_tomas' => (float)($cotizacion['anticipo_tomas'] ?? 0),
        'participa_sergio' => !empty($cotizacion['participa_sergio']) ? 1 : 0,
        'participa_ricardo' => !empty($cotizacion['participa_ricardo']) ? 1 : 0,
        'participa_tomas' => !empty($cotizacion['participa_tomas']) ? 1 : 0,
        'vigencia_dias' => max(1, (int)($cotizacion['vigencia_dias'] ?? 15)),
        'dias_trabajo_general_min' => max(0, (int)($cotizacion['dias_trabajo_general_min'] ?? 0)),
        'dias_trabajo_general_max' => max(0, (int)($cotizacion['dias_trabajo_general_max'] ?? 0)),
        'items' => $items,
    ];
}

function srnu_base64url_encode(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function srnu_base64url_decode(string $encoded): string|false
{
    $encoded = strtr($encoded, '-_', '+/');
    $pad = strlen($encoded) % 4;
    if ($pad) $encoded .= str_repeat('=', 4 - $pad);
    return base64_decode($encoded, true);
}

/** Inserta el respaldo como comentario PDF inmediatamente antes del último %%EOF. */
function srnu_inyectar_respaldo_pdf(string $pdfPath, array $cotizacion): bool
{
    if (!is_file($pdfPath) || filesize($pdfPath) < 100) return false;
    $payload = srnu_pdf_respaldo_publico($cotizacion);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $encoded = srnu_base64url_encode($json);
    $hash = hash('sha256', $json);
    $marker = "%SRNU_BACKUP_V1:" . $encoded . ':' . $hash . "\n";
    $bytes = @file_get_contents($pdfPath);
    if ($bytes === false) return false;
    // Evita duplicar marcadores cuando se vuelve a abrir/generar el mismo archivo.
    $bytes = preg_replace('/%SRNU_BACKUP_V1:[A-Za-z0-9_\-]+:[a-f0-9]{64}\s*/', '', $bytes) ?? $bytes;
    // El comentario debe ir ANTES de startxref. Insertarlo entre el valor de
    // startxref y %%EOF rompe lectores estrictos de PDF. Como el xref ya está
    // escrito antes de este punto, insertar aquí no altera sus offsets.
    $pos = strrpos($bytes, 'startxref');
    if ($pos === false) return false;
    $nuevo = substr($bytes, 0, $pos) . $marker . substr($bytes, $pos);
    return @file_put_contents($pdfPath, $nuevo) !== false;
}

/** Extrae y verifica el respaldo estructurado de un PDF SRNU moderno. */
function srnu_extraer_respaldo_pdf(string $pdfPath): ?array
{
    if (!is_file($pdfPath)) return null;
    $size = filesize($pdfPath);
    if ($size === false || $size <= 0) return null;
    $read = min($size, 2 * 1024 * 1024);
    $fh = @fopen($pdfPath, 'rb');
    if (!$fh) return null;
    @fseek($fh, -$read, SEEK_END);
    $tail = (string)@fread($fh, $read);
    @fclose($fh);
    if (!preg_match('/%SRNU_BACKUP_V1:([A-Za-z0-9_\-]+):([a-f0-9]{64})/i', $tail, $m)) return null;
    $json = srnu_base64url_decode($m[1]);
    if ($json === false || !hash_equals(strtolower($m[2]), hash('sha256', $json))) return null;
    $data = json_decode($json, true);
    return is_array($data) && ($data['formato'] ?? '') === 'SRNU_PDF_BACKUP' ? $data : null;
}

/** Intenta extraer texto de PDFs antiguos usando pdftotext si está instalado. */
function srnu_extraer_texto_pdf_antiguo(string $pdfPath): string
{
    if (!is_file($pdfPath) || !function_exists('exec')) return '';
    $candidatos = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $candidatos = [
            __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'pdftotext.exe',
            'C:\\xampp\\poppler\\Library\\bin\\pdftotext.exe',
            'C:\\Program Files\\poppler\\Library\\bin\\pdftotext.exe',
            'C:\\Program Files\\XpdfReader-win64\\bin64\\pdftotext.exe',
        ];
        $local = str_replace('\\','/', (string)getenv('LOCALAPPDATA'));
        if ($local !== '') {
            foreach ([
                $local.'/Microsoft/WinGet/Packages/oschwartz10612.Poppler*/Library/bin/pdftotext.exe',
                $local.'/Microsoft/WinGet/Packages/oschwartz10612.Poppler*/*/Library/bin/pdftotext.exe',
                $local.'/Microsoft/WinGet/Packages/oschwartz10612.Poppler*/poppler-*/Library/bin/pdftotext.exe',
            ] as $g) foreach ((glob($g) ?: []) as $x) $candidatos[] = $x;
        }
        $where = [];
        @exec('where pdftotext 2>NUL', $where, $code);
        if ($code === 0) foreach ($where as $w) if (trim($w) !== '') $candidatos[] = trim($w);
    } else {
        $candidatos = ['/usr/bin/pdftotext','/usr/local/bin/pdftotext'];
    }
    $exe = null;
    foreach (array_unique($candidatos) as $c) if (is_file($c)) { $exe = $c; break; }
    if ($exe === null) return '';
    $tmp = tempnam(sys_get_temp_dir(), 'srnu_pdf_txt_');
    if ($tmp === false) return '';
    @unlink($tmp);
    $tmp .= '.txt';
    $cmd = escapeshellarg($exe) . ' -layout -enc UTF-8 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmp) . ' 2>&1';
    $out=[]; $code=1; @exec($cmd,$out,$code);
    $text = ($code === 0 && is_file($tmp)) ? (string)@file_get_contents($tmp) : '';
    @unlink($tmp);
    return $text;
}

function srnu_numero_pdf(string $v): float
{
    $v = preg_replace('/[^0-9,\.\-]/', '', $v) ?? '';
    if (str_contains($v, ',') && str_contains($v, '.')) $v = str_replace(',', '', $v);
    elseif (str_contains($v, ',') && !str_contains($v, '.')) $v = str_replace(',', '.', $v);
    return (float)$v;
}

/** Recuperación heurística para PDFs SRNU antiguos que no llevan respaldo interno. */
function srnu_parsear_pdf_antiguo(string $texto, string $nombreArchivo): array
{
    $d = [
        'formato'=>'SRNU_PDF_LEGACY','version'=>0,'folio_origen'=>'','cliente'=>'','telefono'=>'','vehiculo'=>'',
        'fecha'=>date('Y-m-d'),'requiere_factura'=>'no','msi_plazos'=>[],'anticipo_tipo'=>'porcentaje','anticipo_valor'=>70,
        'conservar_anticipo_recibido'=>0,'anticipo_recibido_monto'=>0,'dividir_anticipo'=>0,'repartir_igual'=>0,
        'anticipo_sergio'=>0,'anticipo_ricardo'=>0,'anticipo_tomas'=>0,'participa_sergio'=>1,'participa_ricardo'=>1,'participa_tomas'=>0,
        'vigencia_dias'=>15,'dias_trabajo_general_min'=>0,'dias_trabajo_general_max'=>0,'items'=>[]
    ];
    $base = pathinfo($nombreArchivo, PATHINFO_FILENAME);
    $partes = array_map('trim', explode(',', $base));
    if (count($partes) >= 2) { $d['cliente']=$partes[0]; $d['vehiculo']=$partes[1]; }
    if (stripos($base,'con factura')!==false) $d['requiere_factura']='si';
    if (stripos($base,'sin factura')!==false) $d['requiere_factura']='no';
    $texto = str_replace("\r", '', $texto);
    if (preg_match('/NOMBRE:\s*([^\n]+)/iu',$texto,$m)) $d['cliente']=trim($m[1]);
    if (preg_match('/TEL[ÉE]FONO:\s*([^\n]+)/iu',$texto,$m)) $d['telefono']=trim($m[1]);
    if (preg_match('/Folio:\s*([^\s\n]+)/iu',$texto,$m)) $d['folio_origen']=trim($m[1]);
    if (preg_match('/Vigencia:\s*(\d+)\s*d[ií]as/iu',$texto,$m)) $d['vigencia_dias']=max(1,(int)$m[1]);
    if (preg_match('/IVA\s*\(16%\)\s*\$?\s*([0-9.,]+)/iu',$texto,$m) && srnu_numero_pdf($m[1])>0.01) $d['requiere_factura']='si';
    if (stripos($texto,'BBVA PyME')!==false) $d['msi_plazos'][]='bbva_pyme';
    if (preg_match('/UNA\s+SOLA\s+EXHIBICI[ÓO]N/iu',$texto)) $d['msi_plazos'][]='tarjeta_1x';
    preg_match_all('/\b(3|6|9|12|18|24)\s*(?:MSI|MESES(?:\s+SIN\s+INTERESES)?)/iu',$texto,$mm);
    foreach (($mm[1]??[]) as $plazo) if (!in_array((string)$plazo,$d['msi_plazos'],true)) $d['msi_plazos'][]=(string)$plazo;
    if (preg_match('/(?:tiempo estimado total de trabajo|tiempo estimado de trabajo)[^\n]*?de\s+(\d+)\s+a\s+(\d+)\s+d[ií]as/iu',$texto,$m)) {
        $d['dias_trabajo_general_min']=(int)$m[1]; $d['dias_trabajo_general_max']=(int)$m[2];
    }
    // Las filas de la tabla terminan en Cantidad, Precio y Total. Las líneas de responsables se ignoran.
    $enTabla=false;
    foreach (preg_split('/\n/u',$texto) as $line) {
        $t=trim($line);
        if (preg_match('/Concepto\s+Cantidad\s+Precio\s+Total/iu',$t)) { $enTabla=true; continue; }
        if ($enTabla && preg_match('/^Subtotal\b/iu',$t)) break;
        if (!$enTabla || $t==='' || preg_match('/^(Responsable|Responsables):/iu',$t)) continue;
        if (preg_match('/^(.*?)\s{2,}([0-9]+(?:[.,][0-9]+)?)\s+\$?\s*([0-9.,]+)\s+\$?\s*([0-9.,]+)\s*$/u',$t,$m)) {
            $concepto=trim($m[1]); $cantidad=srnu_numero_pdf($m[2]); $precio=srnu_numero_pdf($m[3]);
            if ($concepto!=='' && $cantidad>0) $d['items'][]=[
                'concepto'=>$concepto,'cantidad'=>$cantidad,'precio'=>$precio,'responsable_sergio'=>0,'responsable_ricardo'=>0,'responsable_tomas'=>0,
                'garantia_meses'=>0,'tipo_entrega'=>'inmediata','dias_entrega_estimada'=>0,'foto_producto'=>'','mostrar_foto_pdf'=>0
            ];
        }
    }
    return $d;
}


// Protección global: toda página que carga la base queda autenticada, salvo rutas públicas con token.
require_once __DIR__ . '/auth.php';
srnu_auth_bootstrap();
