<?php
/**
 * api_upload_background.php — image de fond du tableau de bord.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/lib_upload.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';

// Si l'envoi dépasse post_max_size, PHP vide $_POST ET $_FILES. La
// vérification du jeton échouerait alors avec un message trompeur
// (« jeton invalide ») alors que le vrai problème est la taille.
if ($method === 'POST' && empty($_POST) && empty($_FILES)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    json_error(
        'Fichier trop volumineux pour le serveur (limite PHP : '
        . ini_get('post_max_size') . '). Choisissez une image plus légère.',
        413
    );
}

$user_id = require_owner($pdo);
require_csrf();

if ($method === 'POST') {
    if (!isset($_FILES['background_file'])) {
        json_error('Aucun fichier reçu.');
    }

    // Diagnostic explicite : sans cela, un dossier absent ou en lecture seule
    // se manifeste par un échec silencieux côté interface.
    $dir = __DIR__ . '/uploads/backgrounds';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        json_error('Le dossier uploads/backgrounds est introuvable et n\'a pas pu être créé.', 500);
    }
    if (!is_writable($dir)) {
        json_error('Le dossier uploads/backgrounds n\'est pas inscriptible (chmod 755 requis).', 500);
    }

    try {
        $path = store_uploaded_image($_FILES['background_file'], 'backgrounds', 'bg');
    } catch (UploadException $e) {
        json_error($e->getMessage());
    }

    // Ancien fond : on le retire du disque pour ne pas accumuler les fichiers.
    $stmt = $pdo->prepare('SELECT background_image_url FROM user_settings WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $previous = $stmt->fetchColumn();

    try {
        // Une image remplace la couleur de fond : les deux ensemble n'ont pas
        // de sens, l'image recouvrant la couleur.
        $stmt = $pdo->prepare('
            INSERT INTO user_settings (user_id, background_image_url)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE background_image_url = VALUES(background_image_url)
        ');
        $stmt->execute([$user_id, $path]);

        try {
            $pdo->prepare('UPDATE user_settings SET background_color = NULL WHERE user_id = ?')
                ->execute([$user_id]);
        } catch (Throwable $e) {
            error_log('Colonne background_color absente : ' . $e->getMessage());
        }
    } catch (Throwable $e) {
        delete_uploaded_file($path);
        error_log('Enregistrement du fond : ' . $e->getMessage());
        json_error('Erreur lors de l\'enregistrement.', 500);
    }

    if ($previous) {
        delete_uploaded_file((string) $previous);
    }

    json_out(['success' => true, 'background_image_url' => $path]);
}

if ($method === 'DELETE') {
    $stmt = $pdo->prepare('SELECT background_image_url FROM user_settings WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $previous = $stmt->fetchColumn();

    $stmt = $pdo->prepare('UPDATE user_settings SET background_image_url = NULL WHERE user_id = ?');
    $stmt->execute([$user_id]);

    if ($previous) {
        delete_uploaded_file((string) $previous);
    }

    json_out(['success' => true]);
}

json_error('Méthode non autorisée.', 405);
