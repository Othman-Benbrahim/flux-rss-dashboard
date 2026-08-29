<?php
/**
 * api_background.php — couleur de fond et palette personnalisée.
 *
 * Le téléversement d'image reste dans api_upload_background.php : ici, rien
 * n'est un fichier, tout est du JSON.
 *
 * Couleur et image sont exclusives : poser l'une efface l'autre.
 */

require __DIR__ . '/db.php';

const MAX_CUSTOM_COLORS = 24;

/**
 * Les colonnes background_color et custom_colors sont ajoutées par migrate.php.
 * Sans elles, chaque requête finissait en « erreur interne du serveur », ce qui
 * ne disait rien de la cause. On vérifie une fois et on le dit clairement.
 */
function require_background_columns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $found = $pdo->query("SHOW COLUMNS FROM user_settings LIKE 'background_color'")->rowCount()
               + $pdo->query("SHOW COLUMNS FROM user_settings LIKE 'custom_colors'")->rowCount();
    } catch (Throwable $e) {
        error_log('Vérification des colonnes de fond : ' . $e->getMessage());
        json_error('Impossible de lire la structure de la base.', 500);
    }

    if ($found < 2) {
        json_error(
            'La base n\'est pas à jour : les couleurs de fond nécessitent '
            . 'l\'exécution de « php migrate.php » en SSH.',
            503
        );
    }
}

/**
 * Une couleur est acceptée en #rrggbb ou #rrggbbaa — la composante alpha
 * permet les fonds légèrement transparents. Rien d'autre n'entre en base,
 * puisque la valeur finit dans une propriété CSS.
 */
function valid_color(string $color): bool
{
    return (bool) preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $color);
}

function read_palette(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT custom_colors FROM user_settings WHERE user_id = ?');
    $stmt->execute([$userId]);
    $raw = $stmt->fetchColumn();

    $palette = $raw ? json_decode((string) $raw, true) : [];

    return is_array($palette) ? array_values(array_filter($palette, 'valid_color')) : [];
}

function ensure_settings_row(PDO $pdo, int $userId): void
{
    $pdo->prepare('INSERT IGNORE INTO user_settings (user_id) VALUES (?)')->execute([$userId]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST' && $method !== 'DELETE') {
    json_error('Méthode non autorisée.', 405);
}

$user_id = require_owner($pdo);
require_csrf();
require_background_columns($pdo);

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

// ---------------------------------------------------------------------------
// Suppression
// ---------------------------------------------------------------------------
if ($method === 'DELETE') {
    $target = $_GET['target'] ?? 'all';

    if ($target === 'color' || $target === 'all') {
        $pdo->prepare('UPDATE user_settings SET background_color = NULL WHERE user_id = ?')->execute([$user_id]);
    }

    json_out(['success' => true]);
}

// ---------------------------------------------------------------------------
// Couleur de fond
// ---------------------------------------------------------------------------
if (array_key_exists('color', $input)) {
    $color = $input['color'];

    if ($color === null || $color === '') {
        ensure_settings_row($pdo, $user_id);
        $pdo->prepare('UPDATE user_settings SET background_color = NULL WHERE user_id = ?')->execute([$user_id]);

        json_out(['success' => true, 'color' => null]);
    }

    if (!is_string($color) || !valid_color($color)) {
        json_error('Couleur invalide. Format attendu : #rrggbb ou #rrggbbaa.');
    }

    ensure_settings_row($pdo, $user_id);

    // Une couleur remplace l'image : les deux ensemble n'auraient pas de sens,
    // l'image masquant la couleur.
    $stmt = $pdo->prepare('SELECT background_image_url FROM user_settings WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $previousImage = $stmt->fetchColumn();

    $pdo->prepare('UPDATE user_settings SET background_color = ?, background_image_url = NULL WHERE user_id = ?')
        ->execute([$color, $user_id]);

    if ($previousImage) {
        require_once __DIR__ . '/lib_upload.php';
        delete_uploaded_file((string) $previousImage);
    }

    json_out(['success' => true, 'color' => $color]);
}

// ---------------------------------------------------------------------------
// Palette personnalisée
// ---------------------------------------------------------------------------
if (array_key_exists('add_color', $input)) {
    $color = is_string($input['add_color']) ? $input['add_color'] : '';

    if (!valid_color($color)) {
        json_error('Couleur invalide. Format attendu : #rrggbb ou #rrggbbaa.');
    }

    $palette = read_palette($pdo, $user_id);

    if (in_array(strtolower($color), array_map('strtolower', $palette), true)) {
        json_out(['success' => true, 'palette' => $palette]); // déjà présente
    }
    if (count($palette) >= MAX_CUSTOM_COLORS) {
        json_error('Palette pleine (' . MAX_CUSTOM_COLORS . ' couleurs au maximum).');
    }

    $palette[] = $color;

    ensure_settings_row($pdo, $user_id);
    $pdo->prepare('UPDATE user_settings SET custom_colors = ? WHERE user_id = ?')
        ->execute([json_encode($palette), $user_id]);

    json_out(['success' => true, 'palette' => $palette]);
}

if (array_key_exists('remove_color', $input)) {
    $color   = is_string($input['remove_color']) ? strtolower($input['remove_color']) : '';
    $palette = read_palette($pdo, $user_id);

    $palette = array_values(array_filter($palette, static fn ($c) => strtolower($c) !== $color));

    ensure_settings_row($pdo, $user_id);
    $pdo->prepare('UPDATE user_settings SET custom_colors = ? WHERE user_id = ?')
        ->execute([json_encode($palette), $user_id]);

    json_out(['success' => true, 'palette' => $palette]);
}

json_error('Requête incomplète.');
