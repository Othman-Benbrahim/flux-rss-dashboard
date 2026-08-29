<?php
/**
 * api_read.php — suivi des articles lus.
 *
 * Les adresses sont stockées sous forme d'empreinte SHA-256 : l'index reste de
 * taille fixe quelle que soit la longueur des URL, et la table ne conserve pas
 * la liste en clair de ce qui a été lu.
 */

require __DIR__ . '/db.php';

const READ_MAX_URLS      = 500;
const READ_RETENTION_DAYS = 180;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST' && $method !== 'DELETE') {
    json_error('Méthode non autorisée.', 405);
}

$user_id = require_owner($pdo);
require_csrf();

// --- Oubli : « tout remarquer comme non lu » -------------------------------
if ($method === 'DELETE') {
    $stmt = $pdo->prepare('DELETE FROM read_articles WHERE user_id = ?');
    $stmt->execute([$user_id]);

    json_out(['success' => true, 'cleared' => $stmt->rowCount()]);
}

// --- Marquage --------------------------------------------------------------
$input = json_decode(file_get_contents('php://input'), true);
$urls  = is_array($input['urls'] ?? null) ? $input['urls'] : [];

if (!$urls) {
    json_error('Aucune adresse fournie.');
}
if (count($urls) > READ_MAX_URLS) {
    $urls = array_slice($urls, 0, READ_MAX_URLS);
}

$hashes = [];
foreach ($urls as $url) {
    if (is_string($url) && preg_match('~^https?://~i', trim($url))) {
        $hashes[hash('sha256', trim($url))] = true;
    }
}
if (!$hashes) {
    json_error('Aucune adresse exploitable.');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('
        INSERT INTO read_articles (user_id, url_hash) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE read_at = read_at
    ');
    foreach (array_keys($hashes) as $hash) {
        $stmt->execute([$user_id, $hash]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Marquage lu : ' . $e->getMessage());
    json_error('Erreur lors du marquage.', 500);
}

// Purge occasionnelle : la table grossirait indéfiniment sinon. Une chance sur
// cinquante suffit à la maintenir bornée sans peser sur chaque requête.
if (random_int(1, 50) === 1) {
    try {
        $pdo->prepare('DELETE FROM read_articles WHERE user_id = ? AND read_at < (NOW() - INTERVAL ? DAY)')
            ->execute([$user_id, READ_RETENTION_DAYS]);
    } catch (Throwable $e) {
        error_log('Purge read_articles : ' . $e->getMessage());
    }
}

json_out(['success' => true, 'marked' => count($hashes)]);
