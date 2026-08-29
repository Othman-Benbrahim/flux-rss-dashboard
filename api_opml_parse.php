<?php
/**
 * api_opml_parse.php — lecture d'un fichier OPML.
 *
 * N'écrit rien : renvoie l'arborescence dossiers / flux pour que l'utilisateur
 * choisisse ce qu'il importe. L'écriture se fait ensuite par api_opml_import.php.
 */

require __DIR__ . '/db.php';

const OPML_MAX_BYTES = 2097152; // 2 Mo
const OPML_MAX_FEEDS = 500;     // au-delà, le fichier est tronqué

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Méthode non autorisée.', 405);
}

$owner = require_owner($pdo);
require_csrf();

if (!isset($_FILES['opml_file']) || !is_array($_FILES['opml_file'])) {
    json_error('Aucun fichier reçu.');
}

$file = $_FILES['opml_file'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_error('Envoi du fichier impossible.');
}
if (!is_uploaded_file($file['tmp_name'])) {
    json_error('Envoi invalide.');
}
if ($file['size'] > OPML_MAX_BYTES) {
    json_error('Fichier trop volumineux (2 Mo maximum).');
}

$content = file_get_contents($file['tmp_name']);
if ($content === false || trim($content) === '') {
    json_error('Fichier vide.');
}

// Pas de LIBXML_NOENT : la substitution d'entités ouvrirait la porte à
// l'expansion récursive (« billion laughs »), qui épuiserait la mémoire.
libxml_use_internal_errors(true);
$xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET);

if ($xml === false || !isset($xml->body)) {
    json_error('Ce fichier n\'est pas un OPML valide.');
}

// ---------------------------------------------------------------------------
// Parcours récursif des <outline>
// ---------------------------------------------------------------------------
$folders = [];
$count   = 0;
$truncated = false;

/**
 * Un outline sans xmlUrl mais avec des enfants est un dossier ;
 * un outline avec xmlUrl est un flux.
 */
function walk_outlines(SimpleXMLElement $node, string $folder, array &$folders, int &$count, bool &$truncated): void
{
    foreach ($node->outline as $outline) {
        if ($count >= OPML_MAX_FEEDS) {
            $truncated = true;

            return;
        }

        $attrs  = $outline->attributes();
        $xmlUrl = isset($attrs['xmlUrl']) ? trim((string) $attrs['xmlUrl']) : '';
        $label  = trim((string) ($attrs['title'] ?? $attrs['text'] ?? ''));

        if ($xmlUrl !== '') {
            if (!preg_match('~^https?://~i', $xmlUrl)) {
                continue; // seuls http(s) sont exploitables par le proxy
            }

            $key = $folder !== '' ? $folder : 'Sans dossier';
            if (!isset($folders[$key])) {
                $folders[$key] = [];
            }
            $folders[$key][] = [
                'title' => mb_substr($label !== '' ? $label : $xmlUrl, 0, 120),
                'url'   => mb_substr($xmlUrl, 0, 500),
            ];
            $count++;
            continue;
        }

        if (isset($outline->outline)) {
            $child = $label !== '' ? ($folder !== '' ? $folder . ' / ' . $label : $label) : $folder;
            walk_outlines($outline, $child, $folders, $count, $truncated);
        }
    }
}

walk_outlines($xml->body, '', $folders, $count, $truncated);

if ($count === 0) {
    json_error('Aucun flux trouvé dans ce fichier.');
}

// ---------------------------------------------------------------------------
// Signalement des flux déjà présents
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT settings FROM widgets WHERE user_id = ? AND type = 'rss'");
$stmt->execute([$owner]);

$existing = [];
foreach ($stmt->fetchAll() as $row) {
    $settings = json_decode((string) $row['settings'], true);
    if (is_array($settings) && !empty($settings['url'])) {
        $existing[strtolower(trim((string) $settings['url']))] = true;
    }
}

$out = [];
$seen = [];
foreach ($folders as $name => $feeds) {
    $clean = [];
    foreach ($feeds as $feed) {
        $key = strtolower($feed['url']);
        if (isset($seen[$key])) {
            continue; // doublon interne au fichier
        }
        $seen[$key] = true;

        $feed['existing'] = isset($existing[$key]);
        $clean[] = $feed;
    }
    if ($clean) {
        $out[] = ['name' => $name, 'feeds' => $clean];
    }
}

json_out([
    'folders'   => $out,
    'total'     => $count,
    'truncated' => $truncated,
]);
