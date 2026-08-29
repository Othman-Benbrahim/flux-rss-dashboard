<?php
/**
 * api_create_widget.php — création d'un widget dans un onglet.
 *
 * Séparé de la sauvegarde de grille : le widget est inséré en base d'abord,
 * ce qui lui donne un identifiant réel avant tout redimensionnement.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/lib_sanitize.php';

const MAX_WIDGETS_PER_TAB = 100;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Méthode non autorisée.', 405);
}

$user_id = require_owner($pdo);
require_csrf();

$tab_id = isset($_GET['tab_id']) ? (int) $_GET['tab_id'] : 0;
require_own_tab($pdo, $tab_id, $user_id);

$widget = json_decode(file_get_contents('php://input'), true);
if (!is_array($widget)) {
    json_error('Données invalides.');
}

$type = (string) ($widget['type'] ?? '');
if (!is_valid_widget_type($type)) {
    json_error('Type de widget inconnu.');
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM widgets WHERE user_id = ? AND tab_id = ?');
$stmt->execute([$user_id, $tab_id]);
if ((int) $stmt->fetchColumn() >= MAX_WIDGETS_PER_TAB) {
    json_error('Nombre maximal de widgets atteint pour cet onglet.');
}

$settings = sanitize_widget_settings(
    is_array($widget['settings'] ?? null) ? $widget['settings'] : [],
    $type
);

$stmt = $pdo->prepare('
    INSERT INTO widgets (user_id, tab_id, type, x, y, w, h, settings)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
');

try {
    $stmt->execute([
        $user_id,
        $tab_id,
        $type,
        max(0, min(100, (int) ($widget['x'] ?? 0))),
        max(0, min(1000, (int) ($widget['y'] ?? 0))),
        max(1, min(12, (int) ($widget['w'] ?? 3))),
        max(1, min(50, (int) ($widget['h'] ?? 4))),
        json_encode($settings, JSON_UNESCAPED_UNICODE),
    ]);
} catch (Throwable $e) {
    error_log('Création de widget : ' . $e->getMessage());
    json_error('Erreur lors de la création du widget.', 500);
}

json_out(['success' => true, 'id' => (int) $pdo->lastInsertId(), 'settings' => $settings]);
