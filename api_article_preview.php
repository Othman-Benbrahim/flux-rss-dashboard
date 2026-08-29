<?php
/**
 * api_article_preview.php — aperçu Open Graph d'un article au survol.
 *
 * Comme api_rss.php, ce point d'entrée est public mais restreint : seule une
 * adresse figurant déjà dans un flux mis en cache peut être demandée. Le
 * serveur ne récupère donc jamais une page choisie par le visiteur.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/lib_http.php';

const PREVIEW_CACHE_HOURS = 24;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Méthode non autorisée.', 405);
}

$article_url = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
if ($article_url === '') {
    json_error('URL de l\'article manquante.');
}
if (!preg_match('~^https?://~i', $article_url)) {
    json_error('Adresse invalide.');
}

// ---------------------------------------------------------------------------
// 1. Liste blanche : le lien doit provenir d'un flux déjà chargé
// ---------------------------------------------------------------------------
/**
 * L'article figure-t-il dans un des flux mis en cache ?
 */
function link_is_known(PDO $pdo, string $url): bool
{
    // Pré-filtrage SQL pour éviter de décoder tout le cache.
    $stmt = $pdo->prepare('SELECT content_json FROM rss_cache WHERE content_json LIKE ?');
    $stmt->execute(['%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $url) . '%']);

    foreach ($stmt->fetchAll() as $row) {
        $data = json_decode((string) $row['content_json'], true);
        if (!is_array($data) || empty($data['items'])) {
            continue;
        }
        foreach ($data['items'] as $item) {
            if (isset($item['link']) && $item['link'] === $url) {
                return true;
            }
        }
    }

    return false;
}

if (!link_is_known($pdo, $article_url)) {
    json_error('Cet article ne provient pas d\'un flux de ce tableau de bord.', 403);
}

// ---------------------------------------------------------------------------
// 2. Cache
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT title, summary, image_url, last_fetched FROM article_previews_cache WHERE article_url = ?');
$stmt->execute([$article_url]);
$cache = $stmt->fetch();

if ($cache) {
    $age = time() - strtotime((string) $cache['last_fetched']);
    if ($age >= 0 && $age < PREVIEW_CACHE_HOURS * 3600) {
        json_out([
            'title'     => $cache['title'],
            'summary'   => $cache['summary'],
            'image_url' => $cache['image_url'],
        ]);
    }
}

// ---------------------------------------------------------------------------
// 3. Récupération et extraction Open Graph
// ---------------------------------------------------------------------------
try {
    [$html, $code] = safe_fetch($article_url);
} catch (HttpFetchException $e) {
    json_error($e->getMessage(), 502);
}

if ($code !== 200 || $html === '') {
    json_error('Impossible de récupérer l\'article.', 502);
}

libxml_use_internal_errors(true);
$doc = new DOMDocument();
@$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET);
$xpath = new DOMXPath($doc);

function meta_content(DOMXPath $xpath, string $query): string
{
    $nodes = $xpath->query($query);

    return ($nodes && $nodes->length > 0) ? trim((string) $nodes->item(0)->nodeValue) : '';
}

$title = meta_content($xpath, '//meta[@property="og:title"]/@content');
if ($title === '') {
    $tags = $doc->getElementsByTagName('title');
    $title = $tags->length > 0 ? trim((string) $tags->item(0)->nodeValue) : '';
}

$summary = meta_content($xpath, '//meta[@property="og:description"]/@content');
if ($summary === '') {
    $summary = meta_content($xpath, '//meta[@name="description"]/@content');
}

$image = meta_content($xpath, '//meta[@property="og:image"]/@content');
if ($image !== '' && !preg_match('~^https?://~i', $image)) {
    $image = absolutize_url($image, $article_url);
}
if ($image !== '' && !preg_match('~^https?://~i', $image)) {
    $image = '';
}

// Bornage : ces valeurs alimentent des colonnes VARCHAR(255) / longtext.
$title   = mb_substr($title, 0, 250);
$summary = mb_substr($summary, 0, 1000);
$image   = mb_substr($image, 0, 250);

$stmt = $pdo->prepare('
    INSERT INTO article_previews_cache (article_url, title, summary, image_url, last_fetched)
    VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
    ON DUPLICATE KEY UPDATE
        title = VALUES(title), summary = VALUES(summary),
        image_url = VALUES(image_url), last_fetched = CURRENT_TIMESTAMP
');
$stmt->execute([$article_url, $title, $summary, $image]);

json_out(['title' => $title, 'summary' => $summary, 'image_url' => $image]);
