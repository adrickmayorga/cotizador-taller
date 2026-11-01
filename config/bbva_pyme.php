<?php
/**
 * SRNU CUSTOM — Terminal, comisiones y factura obligatoria.
 * BBVA PyME se elige con una casilla; al marcarla la factura se activa sola.
 * BBVA solo ofrece operaciones cuyo cobro final, con IVA y comisión, supera $50,000.
 * Clip solo muestra MSI dentro de su límite; una exhibición se incluye siempre.
 */
declare(strict_types=1);

function srnu_tarjeta_reglas(): array
{
    return [
        'limite' => 50000.00,
        'iva' => 0.16,
        'clip' => [
            'base' => 0.0299, 'cargo' => 1.00, 'iva_comision' => 0.16,
            'sobretasas' => [3=>0.0457, 6=>0.0757, 9=>0.1107, 12=>0.1277, 18=>0.1927, 24=>0.2717],
        ],
        'bbva' => [
            'base' => 0.0250,
            'sobretasas' => [3=>0.0557, 6=>0.0949, 9=>0.1319, 12=>0.1670],
        ],
    ];
}

function srnu_tarjeta_total(float $subtotal, string $requiereFactura): float
{
    $subtotal = round(max(0.0, $subtotal), 2);
    return round($subtotal + ($requiereFactura === 'si' ? round($subtotal * 0.16, 2) : 0.0), 2);
}

function srnu_tarjeta_redondear(float $cobro, int $plazo): float
{
    // Evita que errores binarios conviertan $50,000 exactos en $50,010.
    $cobro = round($cobro, 8);
    return $plazo > 0 ? ceil($cobro / 10) * 10 : ceil($cobro * 100) / 100;
}

function srnu_clip_cobro(float $total, int $plazo = 3): float
{
    if ($total <= 0) return 0.0;
    $clip = srnu_tarjeta_reglas()['clip'];
    $tasa = $clip['base'] + ($clip['sobretasas'][$plazo] ?? 0.0);
    return srnu_tarjeta_redondear(($total + $clip['cargo']) / (1 - $tasa * (1 + $clip['iva_comision'])), $plazo);
}

function srnu_bbva_cobro(float $subtotal, int $plazo = 3): float
{
    if ($subtotal <= 0) return 0.0;
    $bbva = srnu_tarjeta_reglas()['bbva'];
    $tasa = $bbva['base'] + ($bbva['sobretasas'][$plazo] ?? 0.0);
    return srnu_tarjeta_redondear(srnu_tarjeta_total($subtotal, 'si') / (1 - $tasa), $plazo);
}

/** Cada plazo BBVA se valida por su propio cobro final, siempre con factura. */
function srnu_bbva_plazos_disponibles(float $subtotal): array
{
    $reglas = srnu_tarjeta_reglas();
    $plazos = [];
    foreach (array_keys($reglas['bbva']['sobretasas']) as $plazo) {
        if (srnu_bbva_cobro($subtotal, (int)$plazo) > $reglas['limite']) $plazos[] = (string)$plazo;
    }
    return $plazos;
}

function srnu_bbva_una_exhibicion_disponible(float $subtotal): bool
{
    return srnu_bbva_cobro($subtotal, 0) > srnu_tarjeta_reglas()['limite'];
}

/**
 * BBVA PyME es una selección MANUAL (casilla). Está activo solo cuando 'bbva_pyme'
 * viene marcado en $plazos. Al activarse, la factura se activa sola (ver srnu_aplicar_bbva_pyme).
 * $subtotal y $requiereFactura se conservan solo por compatibilidad con los llamados existentes.
 */
function srnu_bbva_pyme_obligatorio(float $subtotal, string $requiereFactura = 'no', array $plazos = []): bool
{
    return in_array('bbva_pyme', array_map('strval', $plazos), true);
}

/** Plazos que realmente se pueden ofrecer con el total y terminal vigentes. */
function srnu_tarjeta_plazos_disponibles(float $subtotal, string $requiereFactura = 'no', bool $bbva = false): array
{
    $reglas = srnu_tarjeta_reglas();
    $total = srnu_tarjeta_total($subtotal, $bbva ? 'si' : $requiereFactura);
    $plazos = [];
    foreach (array_keys($reglas['clip']['sobretasas']) as $plazo) {
        if (srnu_clip_cobro($total, (int)$plazo) <= $reglas['limite']) $plazos[] = (string)$plazo;
    }
    if ($bbva) $plazos = array_merge($plazos, srnu_bbva_plazos_disponibles($subtotal));
    return array_values(array_unique($plazos));
}

function srnu_cotizacion_usa_bbva(array $cotizacion, bool $conFactura = false): bool
{
    return srnu_bbva_pyme_obligatorio(
        (float)($cotizacion['subtotal'] ?? 0),
        $conFactura ? 'si' : (string)($cotizacion['requiere_factura'] ?? 'no'),
        is_array($cotizacion['msi_plazos'] ?? null) ? $cotizacion['msi_plazos'] : []
    );
}

/** Aplica la regla también al guardar desde el modo rápido u otros módulos. */
function srnu_aplicar_bbva_pyme(array &$data): void
{
    $subtotal = round(max(0.0, (float)($data['subtotal'] ?? 0)), 2);
    $factura = (string)($data['requiere_factura'] ?? 'no');
    $enviados = is_array($data['msi_plazos'] ?? null) ? $data['msi_plazos'] : [];
    $bbva = srnu_bbva_pyme_obligatorio($subtotal, $factura, $enviados);
    $plazos = srnu_tarjeta_plazos_disponibles($subtotal, $factura, $bbva);
    // Toda cotización incluye tarjeta a una exhibición, además de los MSI.
    $plazos[] = 'tarjeta_1x';
    if ($bbva) {
        $data['requiere_factura'] = 'si';
        $data['iva'] = round($subtotal * 0.16, 2);
        $data['total'] = round($subtotal + $data['iva'], 2);
        $plazos[] = 'bbva_pyme';
    } else {
        $plazos = array_values(array_diff($plazos, ['bbva_pyme']));
    }
    $data['msi_plazos'] = array_values(array_unique($plazos));
}
