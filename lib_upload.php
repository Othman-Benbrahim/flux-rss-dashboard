<?php
/**
 * lib_upload.php — enregistrement des images téléversées.
 *
 * L'extension du fichier final est déduite du type MIME réellement détecté,
 * jamais du nom envoyé par le client. Un GIF valide renommé « photo.php » ne
 * peut donc pas atterrir sur le disque en .php.
 */

require_once __DIR__ . '/config.php';

const UPLOAD_MIME_EXTENSIONS = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

class UploadException extends RuntimeException
{
}

/**
 * Valide et déplace un fichier téléversé.
 *
 * @param array  $file   entrée de $_FILES
 * @param string $subdir sous-dossier de uploads/ ('' pour la racine)
 * @param string $prefix préfixe du nom de fichier
 *
 * @return string chemin relatif utilisable dans une URL (ex: uploads/img_ab12.png)
 * @throws UploadException
 */
function store_uploaded_image(array $file, string $subdir = '', string $prefix = 'img'): string
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new UploadException('Envoi invalide.');
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new UploadException('Fichier trop volumineux.');
        case UPLOAD_ERR_NO_FILE:
            throw new UploadException('Aucun fichier reçu.');
        default:
            throw new UploadException('Erreur lors de l\'envoi du fichier.');
    }

    if ($file['size'] > UPLOAD_MAX_BYTES) {
        throw new UploadException('Fichier trop volumineux (maximum '
            . round(UPLOAD_MAX_BYTES / 1048576, 1) . ' Mo).');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new UploadException('Envoi invalide.');
    }

    // Type MIME réel, lu dans le contenu du fichier.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset(UPLOAD_MIME_EXTENSIONS[$mime])) {
        throw new UploadException('Seules les images JPEG, PNG, GIF et WEBP sont acceptées.');
    }

    // Seconde vérification : le fichier doit être décodable comme image.
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new UploadException('Ce fichier n\'est pas une image valide.');
    }

    $extension = UPLOAD_MIME_EXTENSIONS[$mime];

    $dir = __DIR__ . '/uploads' . ($subdir !== '' ? '/' . $subdir : '');
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new UploadException('Dossier d\'envoi indisponible.');
    }

    $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $path = $dir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $path)) {
        throw new UploadException('Impossible d\'enregistrer le fichier.');
    }

    @chmod($path, 0644);

    return 'uploads' . ($subdir !== '' ? '/' . $subdir : '') . '/' . $name;
}

/**
 * Supprime un fichier précédemment téléversé, si le chemin est bien l'un des nôtres.
 */
function delete_uploaded_file(?string $relativePath): void
{
    if (!$relativePath || !preg_match('~^uploads/(?:[A-Za-z0-9._-]+/)?[A-Za-z0-9._-]+$~', $relativePath)) {
        return;
    }

    $full = realpath(__DIR__ . '/' . $relativePath);
    $base = realpath(__DIR__ . '/uploads');

    if ($full && $base && str_starts_with($full, $base . DIRECTORY_SEPARATOR) && is_file($full)) {
        @unlink($full);
    }
}
