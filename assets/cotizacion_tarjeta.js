/* Regla compartida del cotizador completo y rápido; tasas recibidas de PHP. */
window.SRNU_PAGOS = (() => {
    'use strict';
    const reglas = window.SRNU_PAGOS_REGLAS;
    const centavos = n => Math.round((Math.max(0, Number(n) || 0) + Number.EPSILON) * 100) / 100;
    const total = (subtotal, factura) => {
        subtotal = centavos(subtotal);
        return centavos(subtotal + (factura ? centavos(subtotal * reglas.iva) : 0));
    };
    const redondear = (cobro, plazo) => {
        cobro = Number(cobro.toFixed(8));
        return plazo > 0 ? Math.ceil(cobro / 10) * 10 : Math.ceil(cobro * 100) / 100;
    };
    const clip = (importe, plazo = 3) => {
        if (importe <= 0) return 0;
        const tasa = reglas.clip.base + (reglas.clip.sobretasas[plazo] || 0);
        return redondear((importe + reglas.clip.cargo) / (1 - tasa * (1 + reglas.clip.iva_comision)), plazo);
    };
    const bbva = (subtotal, plazo = 3) => {
        if (subtotal <= 0) return 0;
        const tasa = reglas.bbva.base + (reglas.bbva.sobretasas[plazo] || 0);
        return redondear(total(subtotal, true) / (1 - tasa), plazo);
    };
    const plazosBBVA = subtotal => Object.keys(reglas.bbva.sobretasas).map(Number).filter(plazo => bbva(subtotal, plazo) > reglas.limite);
    const unaBBVA = subtotal => bbva(subtotal, 0) > reglas.limite;
    // BBVA PyME es manual: 'seleccionado' es el estado de la casilla BBVA (ya no depende del monto).
    const obligatorio = (subtotal, factura, seleccionado = false) => !!seleccionado;
    const plazosClip = importe => Object.keys(reglas.clip.sobretasas).map(Number).filter(plazo => clip(importe, plazo) <= reglas.limite);
    return {reglas, centavos, total, clip, bbva, obligatorio, plazosClip, plazosBBVA, unaBBVA};
})();
