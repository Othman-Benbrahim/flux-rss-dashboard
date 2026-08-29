<?php
/**
 * api_widgets.php — lecture et sauvegarde de la grille d'un onglet.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/lib_sanitize.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$owner = owner_id($pdo);
if ($owner === null) {
    json_error('Aucun tableau de bord configuré.', 404);
}

// ---------------------------------------------------------------------------
// GET — public, lecture seule du tableau du propriétaire
// ---------------------------------------------------------------------------
if ($method === 'GET') {
    $tab_id = isset($_GET['tab_id']) ? (int) $_GET['tab_id'] : 0;
    if ($tab_id <= 0) {
        $tab_id = (int) default_tab_id($pdo, $owner);
    }

    // L'onglet demandé doit appartenir au propriétaire, sinon rien à montrer.
    $check = $pdo->prepare('SELECT id FROM tabs WHERE id = ? AND user_id = ?');
    $check->execute([$tab_id, $owner]);
    if ($check->fetchColumn() === false) {
        json_out([]);
    }

    $stmt = $pdo->prepare('SELECT id, type, x, y, w, h, settings FROM widgets WHERE user_id = ? AND tab_id = ?');
    $stmt->execute([$owner, $tab_id]);

    $widgets = [];
    foreach ($stmt->fetchAll() as $widget) {
        $settings = json_decode((string) $widget['settings'], true);
        $widget['settings'] = is_array($settings) ? $settings : [];
        $widget['id'] = (int) $widget['id'];
        $widgets[] = $widget;
    }

    json_out($widgets);
}

// ---------------------------------------------------------------------------
// POST — réservé au propriétaire identifié
// ---------------------------------------------------------------------------
if ($method !== 'POST') {
    json_error('Méthode non autorisée.', 405);
}

$user_id = require_owner($pdo);
require_csrf();

$tab_id = isset($_GET['tab_id']) ? (int) $_GET['tab_id'] : 0;
require_own_tab($pdo, $tab_id, $user_id);

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    json_error('Données invalides ou vides.');
}
if (count($data) > 200) {
    json_error('Trop de widgets dans cette requête.');
}

try {
    $pdo->beginTransaction();

    // Widgets réellement présents dans cet onglet, pour n'écrire que sur eux.
    $existing = $pdo->prepare('SELECT id, type FROM widgets WHERE user_id = ? AND tab_id = ?');
    $existing->execute([$user_id, $tab_id]);
    $known = [];
    foreach ($existing->fetchAll() as $row) {
        $known[(int) $row['id']] = $row['type'];
    }

    $update = $pdo->prepare('
        UPDATE widgets
        SET x = ?, y = ?, w = ?, h = ?, settings = ?
        WHERE id = ? AND user_id = ? AND tab_id = ?
    ');

    $saved_ids = [];

    foreach ($data as $widget) {
        if (!is_array($widget) || empty($widget['id']) || !is_numeric($widget['id'])) {
            continue; // les créations passent par api_create_widget.php
        }

        $id = (int) $widget['id'];
        if (!isset($known[$id])) {
            continue; // widget d'un autre onglet ou d'un autre compte : ignoré
        }

        // Le type est celui enregistré en base, pas celui envoyé par le client.
        $type = $known[$id];
        $settings = is_array($widget['settings'] ?? null)
            ? sanitize_widget_settings($widget['settings'], $type)
            : [];

        $update->execute([
            max(0, min(100, (int) ($widget['x'] ?? 0))),
            max(0, min(1000, (int) ($widget['y'] ?? 0))),
            max(1, min(12, (int) ($widget['w'] ?? 3))),
            max(1, min(50, (int) ($widget['h'] ?? 4))),
            json_encode($settings, JSON_UNESCAPED_UNICODE),
            $id,
            $user_id,
            $tab_id,
        ]);

        $saved_ids[] = $id;
    }

    // Filet de sécurité : si le client a envoyé des widgets mais qu'aucun
    // n'appartient à cet onglet, c'est une désynchronisation (changement
    // d'onglet en cours), pas une suppression volontaire. On refuse plutôt
    // que de vider l'onglet.
    if (!empty($data) && empty($saved_ids)) {
        $pdo->rollBack();
        json_error('Requête désynchronisée : aucun de ces widgets n\'appartient à cet onglet.', 409);
    }

    // Suppression des widgets retirés de la grille.
    if ($saved_ids) {
        $placeholders = implode(',', array_fill(0, count($saved_ids), '?'));
        $delete = $pdo->prepare("DELETE FROM widgets WHERE user_id = ? AND tab_id = ? AND id NOT IN ($placeholders)");
        $delete->execute(array_merge([$user_id, $tab_id], $saved_ids));
    } else {
        $delete = $pdo->prepare('DELETE FROM widgets WHERE user_id = ? AND tab_id = ?');
        $delete->execute([$user_id, $tab_id]);
    }

    $pdo->commit();
    json_out(['success' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Sauvegarde de grille : ' . $e->getMessage());
    json_error('Erreur lors de la sauvegarde.', 500);
}
