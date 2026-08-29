<?php
/**
 * api_opml_import.php — création des widgets à partir des flux sélectionnés.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/lib_sanitize.php';

const IMPORT_MAX_FEEDS       = 60;  // par import
const IMPORT_MAX_PER_TAB     = 100; // plafond global d'un onglet
const IMPORT_COLUMNS         = 4;
const IMPORT_WIDGET_W        = 3;
const IMPORT_WIDGET_H        = 4;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Méthode non autorisée.', 405);
}

$user_id = require_owner($pdo);
require_csrf();

$input  = json_decode(file_get_contents('php://input'), true);
$tab_id = (int) ($input['tab_id'] ?? 0);
$feeds  = is_array($input['feeds'] ?? null) ? $input['feeds'] : [];

require_own_tab($pdo, $tab_id, $user_id);

if (!$feeds) {
    json_error('Aucun flux sélectionné.');
}
if (count($feeds) > IMPORT_MAX_FEEDS) {
    json_error('Import limité à ' . IMPORT_MAX_FEEDS . ' flux à la fois.');
}

// --- Flux déjà présents, tous onglets confondus ----------------------------
$stmt = $pdo->prepare("SELECT settings FROM widgets WHERE user_id = ? AND type = 'rss'");
$stmt->execute([$user_id]);

$existing = [];
foreach ($stmt->fetchAll() as $row) {
    $settings = json_decode((string) $row['settings'], true);
    if (is_array($settings) && !empty($settings['url'])) {
        $existing[strtolower(trim((string) $settings['url']))] = true;
    }
}

// --- Place disponible dans l'onglet ----------------------------------------
$stmt = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(y + h), 0) FROM widgets WHERE user_id = ? AND tab_id = ?');
$stmt->execute([$user_id, $tab_id]);
[$current, $nextY] = $stmt->fetch(PDO::FETCH_NUM);

$current = (int) $current;
$nextY   = (int) $nextY;

if ($current >= IMPORT_MAX_PER_TAB) {
    json_error('Cet onglet est déjà plein.');
}

// --- Insertion --------------------------------------------------------------
$insert = $pdo->prepare('
    INSERT INTO widgets (user_id, tab_id, type, x, y, w, h, settings)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
');

$created = 0;
$skipped = 0;
$index   = 0;

try {
    $pdo->beginTransaction();

    foreach ($feeds as $feed) {
        if ($current + $created >= IMPORT_MAX_PER_TAB) {
            break;
        }
        if (!is_array($feed)) {
            continue;
        }

        $url = trim((string) ($feed['url'] ?? ''));
        if (!preg_match('~^https?://~i', $url)) {
            $skipped++;
            continue;
        }
        if (isset($existing[strtolower($url)])) {
            $skipped++;
            continue;
        }
        $existing[strtolower($url)] = true;

        $settings = sanitize_widget_settings([
            'url'   => $url,
            'title' => (string) ($feed['title'] ?? ''),
            'limit' => 5,
        ], 'rss');

        if (($settings['title'] ?? '') === '') {
            $settings['title'] = parse_url($url, PHP_URL_HOST) ?: 'Flux';
        }

        // Disposition en colonnes, sous les widgets existants.
        $insert->execute([
            $user_id,
            $tab_id,
            'rss',
            ($index % IMPORT_COLUMNS) * IMPORT_WIDGET_W,
            $nextY + intdiv($index, IMPORT_COLUMNS) * IMPORT_WIDGET_H,
            IMPORT_WIDGET_W,
            IMPORT_WIDGET_H,
            json_encode($settings, JSON_UNESCAPED_UNICODE),
        ]);

        $created++;
        $index++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Import OPML : ' . $e->getMessage());
    json_error('Erreur lors de l\'import.', 500);
}

json_out(['success' => true, 'created' => $created, 'skipped' => $skipped]);
