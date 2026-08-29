<?php
/**
 * tests.php — vérifications des garde-fous. À lancer en CLI : php tests.php
 * Ce fichier n'est pas destiné au serveur de production.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

// Configuration minimale : les tests ne touchent pas la base.
putenv('FLUXRSS_DB_HOST=localhost');
putenv('FLUXRSS_DB_NAME=test');
putenv('FLUXRSS_DB_USER=test');
putenv('FLUXRSS_DB_PASS=test');

require __DIR__ . '/lib_http.php';
require __DIR__ . '/lib_sanitize.php';

$fails = 0;
$total = 0;

function check(string $label, bool $condition): void
{
    global $fails, $total;
    $total++;
    if (!$condition) {
        $fails++;
        echo "  ÉCHEC : $label\n";
    }
}

function rejected(string $url): bool
{
    try {
        validate_remote_url($url);

        return false;
    } catch (HttpFetchException $e) {
        return true;
    }
}

echo "--- Protection SSRF ---\n";

check('file:// refusé',                 rejected('file:///etc/passwd'));
check('gopher:// refusé',               rejected('gopher://127.0.0.1:11211/'));
check('dict:// refusé',                 rejected('dict://127.0.0.1:2628/'));
check('ftp:// refusé',                  rejected('ftp://example.com/x'));
check('boucle locale refusée',          rejected('http://127.0.0.1/'));
check('127.0.0.2 refusé',               rejected('http://127.0.0.2/admin'));
check('localhost refusé',               rejected('http://localhost/'));
check('IPv6 ::1 refusé',                rejected('http://[::1]/'));
check('métadonnées cloud refusées',     rejected('http://169.254.169.254/latest/meta-data/'));
check('réseau privé 10/8 refusé',       rejected('http://10.0.0.5/'));
check('réseau privé 192.168 refusé',    rejected('http://192.168.1.1/'));
check('réseau privé 172.16 refusé',     rejected('http://172.16.0.1/'));
check('identifiants dans URL refusés',  rejected('http://admin:secret@example.com/'));
check('port non standard refusé',       rejected('http://example.com:8080/'));
check('port 3306 refusé',               rejected('http://example.com:3306/'));
check('URL vide refusée',               rejected(''));
check('sans schéma refusé',             rejected('example.com/feed'));
check('0.0.0.0 refusé',                 rejected('http://0.0.0.0/'));

// Une adresse publique doit passer (nécessite une résolution DNS).
$publicOk = true;
try {
    $r = validate_remote_url('https://github.com/feed.xml');
    $publicOk = ($r['host'] === 'github.com' && $r['port'] === 443 && is_public_ip($r['ip']));
} catch (HttpFetchException $e) {
    $publicOk = false;
    echo "  (note : résolution DNS indisponible dans cet environnement — " . $e->getMessage() . ")\n";
}
check('adresse publique acceptée', $publicOk);

echo "--- Résolution d'URL relatives ---\n";

check('chemin absolu',   absolutize_url('/feed.xml', 'https://site.fr/blog/article') === 'https://site.fr/feed.xml');
check('chemin relatif',  absolutize_url('feed.xml', 'https://site.fr/blog/article') === 'https://site.fr/blog/feed.xml');
check('URL protocole-relative', absolutize_url('//autre.fr/f.xml', 'https://site.fr/') === 'https://autre.fr/f.xml');
check('URL déjà absolue', absolutize_url('https://x.fr/f', 'https://site.fr/') === 'https://x.fr/f');

echo "--- Nettoyage du HTML des notes ---\n";

$cases = [
    'balise script supprimée'   => ['<p>Bonjour</p><script>alert(1)</script>', 'script'],
    'gestionnaire onerror ôté'  => ['<img src=x onerror="alert(1)">', 'onerror'],
    'onclick ôté'               => ['<p onclick="alert(1)">Texte</p>', 'onclick'],
    'javascript: ôté'           => ['<a href="javascript:alert(1)">clic</a>', 'javascript:'],
    'data: ôté'                 => ['<a href="data:text/html,<script>alert(1)</script>">x</a>', 'data:'],
    'iframe supprimée'          => ['<iframe src="https://evil.fr"></iframe>', 'iframe'],
    'balise style supprimée'    => ['<style>body{display:none}</style>', '<style'],
    'svg onload supprimé'       => ['<svg onload="alert(1)"></svg>', 'onload'],
];

foreach ($cases as $label => [$input, $forbidden]) {
    $out = sanitize_note_html($input);
    check($label, stripos($out, $forbidden) === false);
}

$kept = sanitize_note_html('<p>Un <strong>texte</strong> avec un <a href="https://exemple.fr">lien</a>.</p>');
check('mise en forme conservée', str_contains($kept, '<strong>') && str_contains($kept, 'href="https://exemple.fr"'));
check('lien externe sécurisé',   str_contains($kept, 'rel="noopener noreferrer"'));

echo "--- Contrôle des liens ---\n";

check('https accepté',      is_safe_href('https://exemple.fr'));
check('mailto accepté',     is_safe_href('mailto:a@b.fr'));
check('javascript refusé',  !is_safe_href('javascript:alert(1)'));
check('JaVaScRiPt refusé',  !is_safe_href('JaVaScRiPt:alert(1)'));
check('data refusé',        !is_safe_href('data:text/html,x'));
check('vbscript refusé',    !is_safe_href('vbscript:msgbox(1)'));

echo "--- Réglages de widgets ---\n";

$rss = sanitize_widget_settings(['url' => 'https://a.fr/rss', 'limit' => 999, 'display' => 'pirate', 'mode' => 'x'], 'rss');
check('limite bornée',        $rss['limit'] === 20);
check('affichage normalisé',  $rss['display'] === 'previews');
check('mode normalisé',       $rss['mode'] === 'normal');

$img = sanitize_widget_settings(['url' => '../../etc/passwd'], 'image');
check('traversée de chemin refusée', $img['url'] === '');

$img2 = sanitize_widget_settings(['url' => 'uploads/img_ab12.png'], 'image');
check('image téléversée acceptée', $img2['url'] === 'uploads/img_ab12.png');

$bm = sanitize_widget_settings(['links' => [
    ['label' => 'Bon',  'url' => 'exemple.fr'],
    ['label' => 'Piégé', 'url' => 'javascript:alert(1)'],
]], 'bookmarks');
check('favori complété en https', $bm['links'][0]['url'] === 'https://exemple.fr');
check('favori javascript écarté', count($bm['links']) === 1);

$color = sanitize_widget_settings(['color' => 'red; background:url(x)'], 'note');
check('couleur non hexadécimale écartée', !isset($color['color']));

echo "\n";
if ($fails === 0) {
    echo "Tous les tests passent ($total).\n";
    exit(0);
}
echo "$fails échec(s) sur $total.\n";
exit(1);
