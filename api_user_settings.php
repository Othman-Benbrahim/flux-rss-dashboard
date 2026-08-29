<?php
/**
 * api_user_settings.php — réglages d'affichage du tableau de bord (fond d'écran).
 */

require __DIR__ . '/db.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Méthode non autorisée.', 405);
}

$owner = owner_id($pdo);
if ($owner === null) {
    json_out(['background_image_url' => null]);
}

try {
    $stmt = $pdo->prepare('SELECT background_image_url, background_color, custom_colors FROM user_settings WHERE user_id = ?');
    $stmt->execute([$owner]);
    $settings = $stmt->fetch();
} catch (Throwable $e) {
    // Colonnes absentes : migrate.php n'a pas encore tourné. On se rabat sur
    // l'image seule plutôt que de casser le chargement de la page.
    error_log('Réglages de fond partiels : ' . $e->getMessage());
    $stmt = $pdo->prepare('SELECT background_image_url FROM user_settings WHERE user_id = ?');
    $stmt->execute([$owner]);
    $settings = $stmt->fetch();
}

$url = $settings['background_image_url'] ?? null;

// Le chemin est réinjecté dans une propriété CSS background-image : on ne
// laisse passer qu'un fichier de notre propre dossier d'envois.
if ($url !== null && !preg_match('~^uploads/(backgrounds/)?[A-Za-z0-9._-]+$~', $url)) {
    $url = null;
}

// La couleur finit dans une propriété CSS : format strict, rien d'autre.
$color = $settings['background_color'] ?? null;
if ($color !== null && !preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $color)) {
    $color = null;
}

$palette = [];
if (!empty($settings['custom_colors'])) {
    $decoded = json_decode((string) $settings['custom_colors'], true);
    if (is_array($decoded)) {
        $palette = array_values(array_filter($decoded, static fn ($c) =>
            is_string($c) && preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $c)));
    }
}

json_out([
    'background_image_url' => $url,
    'background_color'     => $color,
    'custom_colors'        => $palette,
]);
