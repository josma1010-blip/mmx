<?php
// Copia este archivo como config.php y edita los valores.
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'tu_base_de_datos',
        'user' => 'tu_usuario',
        'pass' => 'tu_contraseña',
    ],
    // URL pública de la tienda SIN diagonal final. Ej: https://www.tudominio.com/tienda
    'base_url' => 'https://www.tudominio.com/tienda',
    // Forzar cookies "secure" (requiere HTTPS). Deja en null para autodetectar.
    'cookie_secure' => null,
    // Clave para cifrar el secreto de PayPal en la BD. Pon 32+ caracteres aleatorios y NO la cambies después.
    'app_key' => 'CAMBIA_ESTA_CLAVE_POR_UNA_ALEATORIA_DE_64_CARACTERES',
    // SOLO DESARROLLO: permite el pago simulado. DEBE estar en false en producción.
    'dev_simulated_payment' => false,
    // Mostrar errores PHP detallados (solo desarrollo)
    'debug' => false,
    // Zona horaria
    'timezone' => 'America/Mexico_City',
    // Tamaño máximo de imágenes subidas (bytes)
    'max_upload_bytes' => 3 * 1024 * 1024,
];
