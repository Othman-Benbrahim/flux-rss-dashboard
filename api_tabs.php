<?php
/**
 * api_tabs.php — gestion des onglets du tableau de bord.
 */

require __DIR__ . '/db.php';

const MAX_TABS = 30;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$owner = owner_id($pdo);
if ($owner === null) {
    json_error('Aucun tableau de bord configuré.', 404);
}

// ---------------------------------------------------------------------------
// GET — public
// ---------------------------------------------------------------------------
if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT id, title, tab_order FROM tabs WHERE user_id = ? ORDER BY tab_order ASC, id ASC');
    $stmt->execute([$owner]);

    $tabs = array_map(static function (array $tab): array {
        $tab['id'] = (int) $tab['id'];

        return $tab;
    }, $stmt->fetchAll());

    json_out($tabs);
}

// ---------------------------------------------------------------------------
// Écritures — propriétaire identifié uniquement
// ---------------------------------------------------------------------------
$user_id = require_owner($pdo);
require_csrf();

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

if ($method === 'POST') {
    $count = (int) $pdo->query('SELECT COUNT(*) FROM tabs WHERE user_id = ' . (int) $user_id)->fetchColumn();
    if ($count >= MAX_TABS) {
        json_error('Nombre maximal d\'onglets atteint.');
    }

    $title = mb_substr(trim((string) ($input['title'] ?? '')), 0, 60);
    if ($title === '') {
        $title = 'Nouvel onglet';
    }

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(tab_order), -1) + 1 FROM tabs WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $next_order = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('INSERT INTO tabs (user_id, title, tab_order) VALUES (?, ?, ?)');
    $stmt->execute([$user_id, $title, $next_order]);

    json_out(['success' => true, 'id' => (int) $pdo->lastInsertId(), 'title' => $title]);
}

if ($method === 'PUT') {
    $id    = (int) ($input['id'] ?? 0);
    $title = mb_substr(trim((string) ($input['title'] ?? '')), 0, 60);

    if ($id <= 0 || $title === '') {
        json_error('Données invalides.');
    }
    require_own_tab($pdo, $id, $user_id);

    $stmt = $pdo->prepare('UPDATE tabs SET title = ? WHERE id = ? AND user_id = ?');
    $stmt->execute([$title, $id, $user_id]);

    json_out(['success' => true]);
}

if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        json_error('Identifiant invalide.');
    }
    require_own_tab($pdo, $id, $user_id);

    // Il doit toujours rester un onglet.
    $count = (int) $pdo->query('SELECT COUNT(*) FROM tabs WHERE user_id = ' . (int) $user_id)->fetchColumn();
    if ($count <= 1) {
        json_error('Impossible de supprimer le dernier onglet.');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('DELETE FROM widgets WHERE user_id = ? AND tab_id = ?');
        $stmt->execute([$user_id, $id]);

        $stmt = $pdo->prepare('DELETE FROM tabs WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user_id]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Suppression d\'onglet : ' . $e->getMessage());
        json_error('Erreur lors de la suppression.', 500);
    }

    json_out(['success' => true]);
}

json_error('Méthode non autorisée.', 405);
