# Despliegue en Miniservidor con Docker

Este proyecto está listo para levantarse en tu miniservidor con **1 solo comando**.

---

## Servicios incluidos en el stack
1. **Cotizador / ERP Automotriz (`app`)**:
   - PHP 8.2 + Apache con `mod_rewrite` habilitado.
   - **Chromium headless** preinstalado para generación de PDFs rápida y pixel-perfect.
   - Puerto web: `http://IP_DE_TU_SERVIDOR:8080/`
2. **Base de Datos MariaDB 10.11 (`db`)**:
   - Base de datos: `srnu_cotizador`.
   - Inicialización automática al primer arranque con `srnu_cotizador.sql`.
   - Volumen persistente para que nunca se pierda tu información.
3. **phpMyAdmin (`phpmyadmin`)**:
   - Administrador web de base de datos listo.
   - Puerto: `http://IP_DE_TU_SERVIDOR:8081/`

---

## Pasos para iniciar en el Miniservidor

### 1. Copiar los archivos al Miniservidor
Copia la carpeta del proyecto a tu miniservidor (por SSH, SCP, FileZilla, rsync o pendrive):
```bash
# Ejemplo por SCP si tu servidor es Linux:
scp -r "srnu sql" usuario@IP_DEL_SERVIDOR:/home/usuario/cotizador
```

### 2. Iniciar el servicio con Docker
Entra a la carpeta en la terminal del miniservidor y ejecuta:
```bash
cd cotizador
docker compose up -d --build
```

### 3. ¡Listo! Abrir en el navegador
Desde tu computadora de trabajo, laptop o celular conectado a la misma red, abre:
* **Cotizador ERP:** `http://IP_DE_TU_SERVIDOR:8080/`
* **phpMyAdmin:** `http://IP_DE_TU_SERVIDOR:8081/`

*(Reemplaza `IP_DE_TU_SERVIDOR` por la dirección IP local de tu miniservidor, por ejemplo `http://192.168.1.50:8080`)*

---

## Comandos de Mantenimiento

* **Ver estado de los contenedores:**
  ```bash
  docker compose ps
  ```
* **Ver logs en tiempo real:**
  ```bash
  docker compose logs -f
  ```
* **Detener el servidor:**
  ```bash
  docker compose down
  ```
* **Reiniciar el servidor:**
  ```bash
  docker compose restart
  ```
