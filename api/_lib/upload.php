<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** Valida y guarda una imagen subida. Devuelve nombre de archivo (aleatorio). */
function save_uploaded_image(array $f, string $destDir): string {
    if (!isset($f['error']) || is_array($f['error'])) throw new UserError('Archivo inválido');
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) throw new UserError('La imagen excede el tamaño permitido');
    if ($f['error'] !== UPLOAD_ERR_OK) throw new UserError('Error al subir el archivo (código ' . $f['error'] . ')');
    $max = (int)cfg('max_upload_bytes', 3145728);
    if ($f['size'] <= 0 || $f['size'] > $max) throw new UserError('La imagen debe pesar menos de ' . round($max / 1048576, 1) . ' MB');
    if (!is_uploaded_file($f['tmp_name'])) throw new UserError('Subida no válida');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($map[$mime])) throw new UserError('Solo se permiten imágenes JPG, PNG o WEBP');
    $info = @getimagesize($f['tmp_name']);
    if (!$info || $info[0] < 16 || $info[1] < 16 || $info[0] > 6000 || $info[1] > 6000) throw new UserError('Imagen inválida o dimensiones no permitidas');
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $name = bin2hex(random_bytes(16)) . '.' . $map[$mime];
    $dest = rtrim($destDir, '/') . '/' . $name;
    // Re-codificar con GD (elimina metadatos y cualquier contenido incrustado)
    $saved = false;
    if (function_exists('imagecreatefromstring')) {
        $im = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
        if ($im) {
            if ($mime === 'image/png') { imagesavealpha($im, true); $saved = imagepng($im, $dest, 6); }
            elseif ($mime === 'image/webp' && function_exists('imagewebp')) { $saved = imagewebp($im, $dest, 85); }
            elseif ($mime === 'image/jpeg') { $saved = imagejpeg($im, $dest, 86); }
            imagedestroy($im);
        }
    }
    if (!$saved && !move_uploaded_file($f['tmp_name'], $dest)) throw new UserError('No se pudo guardar el archivo (revisa permisos de /uploads)');
    @chmod($dest, 0644);
    return $name;
}
