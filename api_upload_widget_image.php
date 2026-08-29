<?php
/**
 * api_upload_widget_image.php — image affichée dans un widget « photo ».
 */

require __DIR__ . '/db.php';
require __DIR__ . '/lib_upload.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Méthode non autorisée.', 405);
}

require_owner($pdo);
require_csrf();

if (!isset($_FILES['widget_image'])) {
    json_error('Aucun fichier reçu.');
}

try {
    $path = store_uploaded_image($_FILES['widget_image'], '', 'img');
} catch (UploadException $e) {
    json_error($e->getMessage());
}

json_out(['success' => true, 'url' => $path]);
