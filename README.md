# Tienda de eSIM y chips (PHP + MySQL, sin frameworks)

Esta es una tienda en línea para vender **eSIM** y **chips físicos** (Telcel, Bait y otras compañías) en México. Los precios están en MXN.
Se hizo con HTML5, CSS3 y JavaScript puro, más **PHP 8+ (PDO)** y **MySQL/MariaDB**. Funciona en hosting compartido con cPanel y Apache, y **no necesita Composer ni Node**.

> Aviso legal: la tienda se presenta como **distribuidor independiente, no afiliado a Telcel**. No uses logotipos oficiales sin autorización.

## Requisitos
- PHP 8.0 o superior, con las extensiones `pdo_mysql`, `openssl`, `curl`, `fileinfo`, `mbstring` y `gd` (gd es opcional).
- MySQL 5.7+/8.x o MariaDB 10.3+.
- Apache con `mod_rewrite` y `mod_headers`, y `AllowOverride All` para que se apliquen los `.htaccess`.
- HTTPS. Es obligatorio en producción para PayPal y para las cookies seguras.

## Instalación en cPanel
1. **Sube los archivos.** Usa el Administrador de archivos (sube `tienda.zip` y elige *Extraer*) o FTP.
   - Puedes ponerlos en `public_html/` o en una subcarpeta como `public_html/tienda/`.
2. **Crea la base de datos.** Entra a cPanel > *Bases de datos MySQL*:
   - Crea la base, crea un usuario con una contraseña fuerte y asígnale **todos los privilegios** sobre esa base.
3. **Importa el esquema.** En *phpMyAdmin*, selecciona la base, entra a *Importar* y elige `sql/schema.sql`.
   - Esto crea las tablas y carga productos de **EJEMPLO**. Todos aparecen marcados como `[EJEMPLO]` y sus códigos eSIM son ficticios.
4. **Configura.** Copia `config/config.sample.php` como `config/config.php` y edita lo siguiente:
   - `db`: host (normalmente `localhost`), nombre, usuario y contraseña.
   - `base_url`: la URL pública sin diagonal final.
   - `app_key`: una cadena aleatoria de 64 caracteres. **No la cambies después**, porque con ella se cifra el secreto de PayPal. Puedes generarla con `php -r "echo bin2hex(random_bytes(32));"`.
   - Deja `dev_simulated_payment => false` y `debug => false`.
5. **Ajusta los permisos.** Las carpetas deben tener `755` y los archivos `644`.
   - `uploads/`, `uploads/products/` y `uploads/private/` necesitan permiso de escritura para PHP. Normalmente basta con `755` en cPanel; si no funciona, usa `775`. **Nunca uses 777.**
   - Pon `config/config.php` en `640` o `600` si tu hosting lo permite.
6. **Crea el administrador.** Hay dos formas:
   - **Web:** abre `https://tudominio.com/install.php`. Elige usuario y una contraseña de al menos 10 caracteres con letras y números.
     Al terminar se crea `config/install.lock` y el instalador intenta **borrarse solo**. Si no lo consigue, **borra `install.php` a mano**.
   - **SSH/Terminal:** ejecuta `php install.php usuario correo@dominio.com --temp`.
     Esto genera una contraseña temporal aleatoria en `config/ADMIN_PASSWORD_TEMPORAL.txt` (el `.htaccess` impide abrirlo desde la web). El panel te obligará a cambiarla. **Borra ese archivo** después.
7. **Entra al panel** en `/admin/`. En *Configuración* edita lo siguiente:
   - El nombre de la tienda, los datos de contacto, el banner, el envío y las instrucciones de transferencia.
   - Los métodos de pago.
   - El aviso del pie de página.
8. **Cambia los productos de ejemplo.** Edita o desactiva los de ejemplo, sube tus imágenes y carga tus códigos eSIM reales en *Inventario eSIM* (uno por uno o con CSV).
9. **Programa el cron (recomendado).** En cPanel > *Cron Jobs*, agrega uno cada 15 minutos:
   `php /home/USUARIO/public_html/cron/expirar_reservas.php`
   Sirve para liberar el stock y los códigos de los pedidos que no se pagaron. Sin cron, esto solo se ejecuta cuando alguien hace un checkout.

## Despliegue en Railway (Docker)
Railway **no** ejecuta PHP si lo sirve como sitio estático. Este proyecto trae un `Dockerfile` (PHP 8.3 + Apache) y `railway.json`.
Guía paso a paso (MySQL, variables `${{MySQL.MYSQLHOST}}`…, Volume para `uploads/`, dominio y primer acceso a `/admin`): **[docs/RAILWAY.md](docs/RAILWAY.md)**.
Sin `config/config.php`, la configuración se lee de variables de entorno (`MYSQL*`/`MYSQL_URL`, `APP_URL`, `APP_KEY`, `INSTALL_TOKEN`…).

## PayPal (Orders v2)
1. En <https://developer.paypal.com> crea una *App* (REST). Obtendrás un **Client ID** y un **Secret**.
2. En el panel, abre *PayPal*. Ahí:
   - Elige el modo `sandbox` (pruebas) o `live` (real).
   - Pega el Client ID y el Secret, activa PayPal y presiona **Probar conexión**.
3. Puedes configurar un webhook (opcional, pero recomendado). En PayPal:
   - Crea un webhook a `https://tudominio.com/api/paypal_webhook.php` con el evento `PAYMENT.CAPTURE.COMPLETED`.
   - Pega su **Webhook ID** en el panel.
   - Así se registran los pagos aunque el cliente cierre la ventana antes de que termine la captura.
4. **Seguridad:**
   - El Secret se guarda **cifrado** (AES-256-GCM con `app_key`) y **nunca** se envía al navegador. Al frontend solo llega el Client ID, que es público.
   - El servidor crea y captura la orden, y compara el monto y la moneda con el pedido antes de darlo por pagado.
5. Para pasar a producción, cambia a `live`, usa las credenciales live y haz una compra real de bajo monto.

## Pago manual (transferencia, depósito u OXXO)
1. El cliente recibe las instrucciones que escribiste en *Configuración*.
2. Cuando recibas el dinero, ve a *Pedidos* y elige **Marcar pagado** (puedes anotar una referencia).
3. Si la entrega eSIM es automática, se asignan los códigos en ese momento. Si es manual, usa el botón **Entregar eSIM**.

## Pago simulado (SOLO DESARROLLO)
- Solo funciona si pones `dev_simulated_payment => true` en `config/config.php`. Viene **desactivado**.
- Mientras está desactivado, `api/dev_pay.php` responde 404.
- **Nunca lo actives en producción.**

## Cómo funciona el inventario eSIM
- Al confirmar el pedido se **reservan** códigos con transacción y `SELECT … FOR UPDATE`. Con esto dos clientes nunca reciben el mismo código.
- El stock físico se descuenta con una actualización condicional (`stock >= cantidad`).
- Si el pedido no se paga dentro de *Horas de reserva*, se cancela y libera el inventario.
- Al confirmarse el pago, los códigos pasan a *entregado*. Repetir la entrega no duplica nada.
- El cliente ve sus datos de activación (ICCID, código LPA, SM-DP+, QR) en la página de su pedido, con un enlace secreto (token).
- El QR se genera o se sirve **solo a través de PHP** y se valida contra el token del pedido. Las imágenes QR que subes se guardan en `uploads/private/`, que no es accesible desde la web.
- **Formato del CSV:** `iccid,activation_code,smdp_address,confirmation_code,phone_number`
  - Solo `iccid` y `activation_code` son obligatorias.
  - Los códigos duplicados se omiten y se reportan.

## Seguridad incluida
- PDO con consultas preparadas.
- Escape de HTML en todas las salidas, tanto en PHP como en JS.
- Protección CSRF en todo POST.
- Sesiones seguras: HttpOnly, SameSite=Lax, Secure con HTTPS y regeneración del ID al iniciar sesión.
- `password_hash`. El usuario se bloquea 15 min tras 5 intentos fallidos, y hay límites por IP en login, checkout y contacto.
- Cabeceras de seguridad: CSP, X-Frame-Options, nosniff, Referrer-Policy y Permissions-Policy.
- Subidas: solo jpg, png o webp, validadas por **MIME real** (finfo) y por `getimagesize`. Se guardan con nombre aleatorio y PHP no se ejecuta en `uploads/`.
- Las carpetas `config/`, `sql/`, `cron/` y `api/_lib/` están bloqueadas con `.htaccess`.
- Bitácora de auditoría de acciones de admin y de pagos.

## Estructura
- **Páginas públicas:** `index.html`, `productos.html`, `producto.html?p=slug`, `carrito.html`, `checkout.html`, `pedido-confirmado.html`, `contacto.html`, `404.html`.
- **Panel:** `admin/` (index.html, admin.js, admin.css).
- **API pública:** `api/*.php`. **API del panel:** `api/admin/*.php`. **Lógica compartida:** `api/_lib/`.
- **Recursos:** `assets/css`, `assets/js`, `assets/img`.
- **Otros:** `config/`, `uploads/`, `sql/schema.sql`, `cron/`, `install.php`.

## Limitaciones conocidas
- El envío de correos usa `mail()` de PHP. Que llegue depende del hosting, así que configura SPF y DKIM en cPanel.
  - Aunque el correo falle, el cliente siempre tiene la página de su pedido.
- Un pago de PayPal que llegue **después** de que el pedido se canceló por expirar se registra en la bitácora pero no se entrega. Revísalo a mano y reembolsa o reactiva el pedido.
- La tienda no calcula impuestos ni emite facturas (CFDI).
