# Despliegue en Railway (Docker: PHP 8.3 + Apache + MySQL)

## Por qué no funcionaba
Railway detectó el proyecto como **sitio estático**: servía los `.php` como texto y `/admin` quedaba vacío porque la API no se ejecutaba.
Este repositorio ahora incluye un `Dockerfile` (PHP 8.3 + Apache) y un `railway.json` (`builder: DOCKERFILE`). Railway usa el Dockerfile automáticamente cuando está en la raíz.

## Archivos añadidos
| Archivo | Para qué sirve |
|---|---|
| `Dockerfile` | Imagen `php:8.3-apache` con `pdo_mysql`, `gd` (JPG/PNG/WEBP), `opcache`. `mbstring`, `curl`, `openssl` y `fileinfo` ya vienen en la imagen. Habilita `mod_rewrite`, `headers`, `expires` y `remoteip`. |
| `docker/apache-tienda.conf` | VirtualHost con `AllowOverride All`, escucha en `${PORT}` y bloquea `config/`, `sql/`, `cron/`, `docs/`, `api/_lib/`, `uploads/private/`, `*.md`, `*.sql`, `*.lock`, `*.log`, `Dockerfile`, etc. |
| `docker/entrypoint.sh` | Ajusta el puerto, prepara el Volume de `uploads/`, ejecuta la migración (opcional), crea el admin desde variables (opcional) y arranca Apache. |
| `docker/php.ini` | Ajustes de producción (sin errores en pantalla, límites de subida). |
| `cron/migrate.php` | Importa `sql/schema.sql` **solo si la base está vacía** (idempotente). |
| `api/_lib/config_loader.php` | Lee `config/config.php` si existe; si no, usa variables de entorno. |
| `railway.json` | Builder Dockerfile, healthcheck y política de reinicio. |

## Paso a paso

### 1. Subir el código
**Opción A: GitHub (recomendada)**
```bash
cd tienda
git init && git add . && git commit -m "Tienda lista para Railway"
git branch -M main
git remote add origin https://github.com/TU_USUARIO/tienda-esim.git
git push -u origin main
```
El `.gitignore` excluye `config/config.php`, `install.lock`, uploads y logs. **No subas secretos.**

**Opción B: CLI de Railway**
```bash
npm i -g @railway/cli
railway login
railway link        # elige tu proyecto y el servicio web
railway up          # sube la carpeta actual y construye con el Dockerfile
```

### 2. Quitar el despliegue estático actual
Tienes dos caminos:
- **Reutilizar el servicio** `esimmx-production`: conéctalo al repo nuevo (Settings → Source) o haz `railway up` desde esta carpeta. Al encontrar el `Dockerfile`, Railway deja de construirlo como sitio estático. En *Settings → Build* verifica que el builder sea **Dockerfile** y que *Custom Start Command* esté **vacío**.
- **Empezar de cero**: en el servicio actual, *Settings → Danger → Delete service*. Luego, *+ New → GitHub Repo* y elige el repo.

Si tenías variables del sitio estático, como `NIXPACKS_*`, `RAILPACK_*` o `STATIC_*`, bórralas.

### 3. Añadir MySQL
En el proyecto: **+ New → Database → Add MySQL**. Se crea un servicio llamado `MySQL`, que expone `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`, `MYSQLDATABASE` y `MYSQL_URL`.

### 4. Variables del servicio web
Ve al servicio web → **Variables → Raw Editor** y pega esto. Cambia los valores marcados:
```
MYSQLHOST=${{MySQL.MYSQLHOST}}
MYSQLPORT=${{MySQL.MYSQLPORT}}
MYSQLUSER=${{MySQL.MYSQLUSER}}
MYSQLPASSWORD=${{MySQL.MYSQLPASSWORD}}
MYSQLDATABASE=${{MySQL.MYSQLDATABASE}}
APP_URL=https://${{RAILWAY_PUBLIC_DOMAIN}}
APP_KEY=<64 caracteres hex aleatorios: php -r "echo bin2hex(random_bytes(32));"  u  openssl rand -hex 32>
AUTO_MIGRATE=1
INSTALL_TOKEN=<otra cadena aleatoria de 32+ caracteres: openssl rand -hex 24>
```
Si prefieres, cambia las cinco variables `MYSQL*` por una sola: `MYSQL_URL=${{MySQL.MYSQL_URL}}`.

Notas sobre las variables:
- **`APP_KEY`** cifra el secreto de PayPal en la base de datos. **No la cambies nunca.** Si la cambias, tendrás que volver a pegar el Secret de PayPal.
- `MYSQLHOST` apunta a la red privada (`mysql.railway.internal`), así que no hace falta exponer MySQL a Internet.
- Si usas un dominio propio, pon en `APP_URL` tu dominio, por ejemplo `https://www.tudominio.com`.

Variables opcionales:
| Variable | Valor | Uso |
|---|---|---|
| `ADMIN_USER` / `ADMIN_PASSWORD` / `ADMIN_EMAIL` | p. ej. `admin` / clave de 10+ caracteres con letras y números | Crea el primer admin al arrancar, **solo si no existe ninguno**. El panel obliga a cambiar la contraseña en el primer acceso. **Borra `ADMIN_PASSWORD` después.** Úsalas en lugar de `INSTALL_TOKEN` si lo prefieres. |
| `ENABLE_CRON` | `1` | Libera cada 15 min las reservas vencidas, en el mismo contenedor. Sin esto, se liberan al hacer cada checkout. |
| `UPLOADS_PATH` | p. ej. `/data/uploads` | Solo si montas el Volume en otra ruta. El entrypoint crea un enlace simbólico. |
| `COOKIE_SECURE` | `1` | Fuerza cookies `Secure`. Por defecto se detecta con `X-Forwarded-Proto`. |
| `TRUST_PROXY` | `1` (por defecto) | Toma la IP del cliente de `X-Forwarded-For` para el límite de intentos. |
| `APP_TIMEZONE` | `America/Mexico_City` | Zona horaria. |
| `APP_DEBUG` | `0` | **No** lo actives en producción. |
| `DEV_SIMULATED_PAYMENT` | `0` | **Nunca** en producción. |
| `DB_WAIT_SECONDS` | `90` | Cuánto espera la migración a que MySQL esté listo. |

### 5. Volume (persistencia de imágenes y QR)
El disco del contenedor es **efímero**: en cada redeploy se borra todo lo que no esté en un Volume. Eso incluye imágenes de productos, QR y `mail.log`.
1. En el servicio web, abre el menú contextual (o pulsa ⌘K) → **Attach Volume**.
2. Usa el **Mount path** `/var/www/html/uploads`.
3. Haz un redeploy. El entrypoint crea `products/` y `private/qr/`, y restaura los `.htaccess` de protección dentro del Volume.

Railway permite un solo Volume por servicio, así que `config/` **no** necesita volumen: el bloqueo del instalador se guarda en la base de datos (`settings.install_locked`) y en las tablas de admins. Aunque `install.php` reaparezca con un redeploy, queda bloqueado si ya existe un administrador.

### 6. Dominio
Servicio web → **Settings → Networking → Generate Domain**. Si Railway lo pregunta, el puerto destino es el que usa la variable `PORT`; la imagen escucha en `$PORT`, que es 8080 por defecto. También puedes añadir un **Custom Domain** con su CNAME. Railway da HTTPS automático. Cuando cambies de dominio, actualiza `APP_URL`.

### 7. Primer despliegue y acceso a /admin
1. Haz el deploy. En los logs debe aparecer `[migrate] Esquema importado (...)`. En los siguientes despliegues dirá `La base ya tiene N tablas; no se importa nada`.
2. Crea el admin con una de estas opciones:
   - **Con token:** abre `https://TU-DOMINIO/install.php?token=EL_VALOR_DE_INSTALL_TOKEN`, elige usuario y contraseña y envía el formulario. La contraseña **no** se guarda en logs ni en archivos.
   - **Con variables:** si definiste `ADMIN_USER` y `ADMIN_PASSWORD`, el admin ya existe. Borra `ADMIN_PASSWORD` de las variables.
   - **Por terminal:** ejecuta `railway ssh` y luego `php /var/www/html/install.php admin correo@dominio.com --temp`. La contraseña temporal se muestra **una sola vez** en tu terminal.
3. Cuando el admin exista, **borra `INSTALL_TOKEN`** (y `AUTO_MIGRATE` si quieres). Sin token, `install.php` responde 403.
4. Entra a `https://TU-DOMINIO/admin/` y configura la tienda. Para PayPal, usa el webhook `https://TU-DOMINIO/api/paypal_webhook.php`.

### 8. Verificación rápida
```bash
curl -I https://TU-DOMINIO/                       # 200 text/html
curl https://TU-DOMINIO/api/config.php            # JSON {"ok":true,...}, NUNCA código PHP
curl -I https://TU-DOMINIO/install.php            # 403 (sin token / ya instalado)
curl -I https://TU-DOMINIO/config/config.sample.php  # 403
curl -I https://TU-DOMINIO/sql/schema.sql         # 403
curl -I https://TU-DOMINIO/README.md              # 403
```

## Correo
La imagen no incluye un servidor SMTP. `mail()` fallará y solo quedará registrado en `uploads/private/mail.log`. Los clientes siempre ven su eSIM en la página del pedido, con su enlace secreto. Si necesitas que se envíen correos, tendrás que integrar un proveedor SMTP o una API (Resend, SendGrid, etc.); eso no está incluido.

## Respaldos
Railway → servicio MySQL → *Backups*. También puedes usar `mysqldump` con las credenciales públicas del servicio MySQL. Respalda además el Volume si tienes imágenes o QR.
