<?php
/**
 * api_rss.php — proxy de flux RSS.
 *
 * Le point d'entrée est public (les visiteurs voient le tableau en lecture
 * seule), mais il n'accepte QUE les adresses déjà enregistrées dans un widget
 * du propriétaire. Un visiteur ne peut donc pas s'en servir pour faire
 * interroger une adresse arbitraire par le serveur.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/lib_http.php';

const RSS_MAX_ITEMS = 20; // nombre d'articles conservés en cache

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Méthode non autorisée.', 405);
}

$requested = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
if ($requested === '') {
    json_error('URL du flux manquante.');
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 5;
$limit = max(1, min(RSS_MAX_ITEMS, $limit));

$owner = owner_id($pdo);
if ($owner === null) {
    json_error('Aucun tableau de bord configuré.', 404);
}

// ---------------------------------------------------------------------------
// 1. Liste blanche : l'adresse doit provenir d'un widget existant
// ---------------------------------------------------------------------------
/**
 * Adresses de flux enregistrées par le propriétaire.
 */
function registered_feed_urls(PDO $pdo, int $owner): array
{
    $stmt = $pdo->prepare("SELECT settings FROM widgets WHERE user_id = ? AND type = 'rss'");
    $stmt->execute([$owner]);

    $urls = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings = json_decode((string) $row['settings'], true);
        if (is_array($settings) && !empty($settings['url']) && is_string($settings['url'])) {
            $urls[] = trim($settings['url']);
        }
    }

    return $urls;
}

if (!in_array($requested, registered_feed_urls($pdo, $owner), true)) {
    json_error('Ce flux n\'est pas enregistré sur ce tableau de bord.', 403);
}

// Une adresse saisie sans schéma (« lemonde.fr ») est complétée en https.
$feed_url = preg_match('~^https?://~i', $requested) ? $requested : 'https://' . $requested;

/**
 * Une page Apple Podcasts n'est pas un flux et n'en déclare aucun : la
 * découverte automatique n'a rien à s'y accrocher. L'API Lookup d'Apple,
 * publique et sans clé, renvoie l'adresse du flux d'origine à partir de
 * l'identifiant numérique présent dans l'URL.
 *
 * La requête passe par safe_fetch : les protections réseau s'appliquent.
 */
function resolve_apple_podcast(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));

    if ($host !== 'podcasts.apple.com' && $host !== 'itunes.apple.com') {
        return $url;
    }
    if (!preg_match('~/id(\d+)~', $url, $matches)) {
        return $url;
    }

    try {
        [$body, $code] = safe_fetch('https://itunes.apple.com/lookup?id=' . $matches[1] . '&entity=podcast');
    } catch (HttpFetchException $e) {
        return $url;
    }

    if ($code !== 200) {
        return $url;
    }

    $data = json_decode($body, true);
    $found = $data['results'][0]['feedUrl'] ?? '';

    return (is_string($found) && preg_match('~^https?://~i', $found)) ? $found : $url;
}

$feed_url = resolve_apple_podcast($feed_url);

// ---------------------------------------------------------------------------
// 2. Cache
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT content_json, last_fetched FROM rss_cache WHERE feed_url = ?');
$stmt->execute([$requested]);
$cache = $stmt->fetch();

if ($cache) {
    $age = time() - strtotime((string) $cache['last_fetched']);
    if ($age >= 0 && $age < RSS_CACHE_MINUTES * 60) {
        $cached = json_decode((string) $cache['content_json'], true);
        if (is_array($cached) && isset($cached['items'])) {
            $out = mark_read_state($pdo, $owner, array_slice($cached['items'], 0, $limit));
            json_out([
                'items'     => $out,
                'feed_type' => $cached['feed_type'] ?? 'article',
                'cached'    => true,
            ]);
        }
    }
}

// ---------------------------------------------------------------------------
// 3. Récupération
// ---------------------------------------------------------------------------
try {
    [$content, $code, $finalUrl] = safe_fetch($feed_url);
} catch (HttpFetchException $e) {
    json_error($e->getMessage(), 502);
}

if ($code !== 200 || $content === '') {
    json_error('Impossible de récupérer le flux distant.', 502);
}

libxml_use_internal_errors(true);
$xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET);

// ---------------------------------------------------------------------------
// 4. Découverte automatique si la page n'est pas un flux
// ---------------------------------------------------------------------------
if ($xml === false) {
    $discovered = discover_feed_url($content, $finalUrl);

    if ($discovered !== null) {
        try {
            [$content, $code, $finalUrl] = safe_fetch($discovered);
            if ($code === 200 && $content !== '') {
                $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET);
            }
        } catch (HttpFetchException $e) {
            $xml = false;
        }
    }

    // Dernier recours : les chemins conventionnels.
    if ($xml === false || $xml === null) {
        foreach (['/feed/', '/rss/', '/rss.xml', '/feed', '/atom.xml'] as $path) {
            $candidate = rtrim($finalUrl, '/') . $path;
            try {
                [$body, $status] = safe_fetch($candidate);
            } catch (HttpFetchException $e) {
                continue;
            }
            if ($status === 200 && $body !== '') {
                $parsed = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
                if ($parsed !== false) {
                    $xml = $parsed;
                    break;
                }
            }
        }
    }
}

if ($xml === false || $xml === null) {
    json_error('Le contenu récupéré n\'est pas un flux valide.', 502);
}

/**
 * Cherche un <link rel="alternate" type="application/rss+xml"> dans une page HTML.
 */
function discover_feed_url(string $html, string $baseUrl): ?string
{
    if (preg_match_all('/<link[^>]+>/i', $html, $matches)) {
        foreach ($matches[0] as $tag) {
            if (stripos($tag, 'alternate') === false) {
                continue;
            }
            if (stripos($tag, 'application/rss+xml') === false
                && stripos($tag, 'application/atom+xml') === false) {
                continue;
            }
            if (preg_match('/href=[\'"]([^\'"]+)[\'"]/i', $tag, $href)) {
                return absolutize_url(html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $baseUrl);
            }
        }
    }

    $dom = new DOMDocument();
    if (@$dom->loadHTML($html, LIBXML_NONET)) {
        foreach ($dom->getElementsByTagName('link') as $link) {
            $rel  = strtolower($link->getAttribute('rel'));
            $type = strtolower($link->getAttribute('type'));
            if ($rel === 'alternate'
                && ($type === 'application/rss+xml' || $type === 'application/atom+xml')) {
                $href = $link->getAttribute('href');
                if ($href !== '') {
                    return absolutize_url($href, $baseUrl);
                }
            }
        }
    }

    return null;
}

// ---------------------------------------------------------------------------
// 5. Normalisation RSS 2.0 / Atom
// ---------------------------------------------------------------------------
$entries = isset($xml->channel->item)
    ? $xml->channel->item
    : (isset($xml->entry) ? $xml->entry : []);

$items = [];
foreach ($entries as $entry) {
    if (count($items) >= RSS_MAX_ITEMS) {
        break;
    }

    $namespaces = $entry->getNamespaces(true);

    $descHtml = isset($entry->description)
        ? (string) $entry->description
        : (isset($entry->content) ? (string) $entry->content : '');

    if (isset($namespaces['content'])) {
        $contentNs = $entry->children($namespaces['content']);
        if (isset($contentNs->encoded)) {
            $descHtml .= ' ' . (string) $contentNs->encoded;
        }
    }

    $summary = trim(preg_replace('/\s+/u', ' ', strip_tags($descHtml)));
    if (mb_strlen($summary) > 300) {
        $summary = mb_substr($summary, 0, 300) . '…';
    }

    $imageUrl = '';
    if (isset($namespaces['media'])) {
        $media = $entry->children($namespaces['media']);
        foreach ([$media->content ?? null, $media->thumbnail ?? null, $media->group->thumbnail ?? null] as $node) {
            if ($node !== null && isset($node->attributes()->url)) {
                $imageUrl = (string) $node->attributes()->url;
                break;
            }
        }
    }

    // Vignette de podcast : <itunes:image href="...">
    if ($imageUrl === '' && isset($namespaces['itunes'])) {
        $itunes = $entry->children($namespaces['itunes']);
        if (isset($itunes->image) && isset($itunes->image->attributes()->href)) {
            $imageUrl = (string) $itunes->image->attributes()->href;
        }
    }

    // Pièce jointe : audio d'un côté, image de l'autre.
    // Sans ce test de type, l'enclosure d'un podcast — un MP3 — se retrouvait
    // dans un attribut src d'image.
    $audioUrl = '';
    foreach ($entry->enclosure ?? [] as $enclosure) {
        $encType = strtolower((string) ($enclosure['type'] ?? ''));
        $encUrl  = (string) ($enclosure['url'] ?? '');

        if ($encUrl === '') {
            continue;
        }
        if ($audioUrl === '' && str_starts_with($encType, 'audio/')) {
            $audioUrl = $encUrl;
        } elseif ($imageUrl === '' && str_starts_with($encType, 'image/')) {
            $imageUrl = $encUrl;
        }
    }
    if ($imageUrl === '' && preg_match('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $descHtml, $m)) {
        $imageUrl = $m[1];
    }
    if ($imageUrl === ''
        && preg_match('~<iframe[^>]+src=[\'"](?:https?:)?//(?:www\.)?youtube\.com/embed/([a-zA-Z0-9_-]+)~i', $descHtml, $m)) {
        $imageUrl = 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
    }

    // Les URL d'images finissent dans un attribut src : on n'accepte que http(s).
    if ($imageUrl !== '' && !preg_match('~^https?://~i', $imageUrl)) {
        $imageUrl = '';
    }

    $link = isset($entry->link['href'])
        ? (string) $entry->link['href']          // Atom
        : trim((string) $entry->link);           // RSS 2.0

    // Beaucoup de flux de podcasts omettent <link> dans leurs items et
    // n'exposent qu'un <guid> qui est déjà une adresse. Sans ce repli, tous
    // les épisodes se retrouvaient sans lien et étaient écartés à l'affichage.
    if (!preg_match('~^https?://~i', $link) && isset($entry->guid)) {
        $candidate = trim((string) $entry->guid);

        // isPermaLink="false" signale explicitement un identifiant qui n'est
        // pas une adresse : on ne s'en sert pas comme lien.
        $isPermaLink = !isset($entry->guid['isPermaLink'])
            || strtolower(trim((string) $entry->guid['isPermaLink'])) !== 'false';

        if ($isPermaLink && preg_match('~^https?://~i', $candidate)) {
            $link = $candidate;
        }
    }

    if (!preg_match('~^https?://~i', $link)) {
        $link = '';
    }

    if ($audioUrl !== '' && !preg_match('~^https?://~i', $audioUrl)) {
        $audioUrl = '';
    }

    // Durée iTunes : « 1:02:03 », « 42:15 » ou un nombre de secondes.
    $duration = '';
    if ($audioUrl !== '' && isset($namespaces['itunes'])) {
        $itunes = $entry->children($namespaces['itunes']);
        if (isset($itunes->duration)) {
            $raw = trim((string) $itunes->duration);
            if (ctype_digit($raw)) {
                $seconds  = (int) $raw;
                $duration = $seconds >= 3600
                    ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
                    : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
            } elseif (preg_match('/^[0-9:]{3,12}$/', $raw)) {
                $duration = $raw;
            }
        }
    }

    $items[] = [
        'title'       => (string) $entry->title,
        'link'        => $link,
        'description' => $summary,
        'image_url'   => $imageUrl,
        'audio_url'   => $audioUrl,
        'duration'    => $duration,
        'date'        => isset($entry->pubDate)
            ? (string) $entry->pubDate
            : (isset($entry->updated) ? (string) $entry->updated : ''),
    ];
}

// ---------------------------------------------------------------------------
// 6. Nature du flux — sert au filtre « type » de l'interface
// ---------------------------------------------------------------------------
$feedType = 'article';

if (stripos($feed_url, 'youtube.com/feeds/videos.xml') !== false
    || isset($xml->getNamespaces(true)['yt'])) {
    $feedType = 'youtube';
} else {
    foreach ($items as $item) {
        if (!empty($item['audio_url'])) {
            $feedType = 'podcast';
            break;
        }
    }
}

// ---------------------------------------------------------------------------
// 7. Mise en cache et réponse
// ---------------------------------------------------------------------------
$payload = ['items' => $items, 'feed_type' => $feedType];

/**
 * Ajoute l'état lu/non-lu à une liste d'articles.
 *
 * L'état est celui du propriétaire du tableau : il est calculé à la sortie et
 * jamais mis en cache, puisqu'il change à chaque lecture alors que le contenu
 * du flux, lui, reste valable.
 */
function mark_read_state(PDO $pdo, int $owner, array $items): array
{
    if (!$items) {
        return $items;
    }

    $hashes = [];
    foreach ($items as $item) {
        if (!empty($item['link'])) {
            $hashes[] = hash('sha256', $item['link']);
        }
    }
    if (!$hashes) {
        return $items;
    }

    $placeholders = implode(',', array_fill(0, count($hashes), '?'));

    try {
        $stmt = $pdo->prepare("SELECT url_hash FROM read_articles WHERE user_id = ? AND url_hash IN ($placeholders)");
        $stmt->execute(array_merge([$owner], $hashes));
        $read = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        // Le suivi de lecture est accessoire : s'il est indisponible (table
        // absente parce que migrate.php n'a pas encore tourné, par exemple),
        // les articles s'affichent quand même, tous en « non lu ».
        error_log('Suivi de lecture indisponible : ' . $e->getMessage());

        return $items;
    }

    foreach ($items as &$item) {
        $item['read'] = !empty($item['link']) && isset($read[hash('sha256', $item['link'])]);
    }
    unset($item);

    return $items;
}

$stmt = $pdo->prepare('
    INSERT INTO rss_cache (feed_url, content_json, last_fetched)
    VALUES (?, ?, CURRENT_TIMESTAMP)
    ON DUPLICATE KEY UPDATE content_json = VALUES(content_json), last_fetched = CURRENT_TIMESTAMP
');
$stmt->execute([$requested, json_encode($payload, JSON_UNESCAPED_UNICODE)]);

json_out([
    'items'     => mark_read_state($pdo, $owner, array_slice($items, 0, $limit)),
    'feed_type' => $feedType,
    'cached'    => false,
]);
