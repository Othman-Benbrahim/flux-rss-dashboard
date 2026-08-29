<?php
/**
 * lib_sanitize.php — nettoyage du contenu enregistré en base.
 *
 * Les notes sont saisies dans CKEditor et renvoyées en HTML. Même si seul le
 * propriétaire écrit, ce HTML est ensuite affiché à tous les visiteurs : il est
 * filtré à l'entrée sur une liste blanche de balises et d'attributs.
 */

const NOTE_ALLOWED_TAGS = [
    'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'a',
    'ul', 'ol', 'li', 'blockquote',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'span', 'div', 'figure', 'figcaption', 'code', 'pre',
];

const NOTE_ALLOWED_ATTRS = ['href', 'title', 'class'];

/**
 * Une URL est-elle sûre dans un attribut href ?
 * Interdit javascript:, data:, vbscript: et compagnie.
 */
function is_safe_href(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    if (str_starts_with($url, '#') || str_starts_with($url, '/')) {
        return true;
    }

    return (bool) preg_match('~^(https?://|mailto:)~i', $url);
}

/**
 * Filtre le HTML d'une note sur la liste blanche ci-dessus.
 */
function sanitize_note_html(string $html): string
{
    if (trim($html) === '') {
        return '';
    }
    if (mb_strlen($html) > 100000) {
        $html = mb_substr($html, 0, 100000);
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $ok = @$doc->loadHTML(
        '<?xml encoding="utf-8" ?><div id="note-root">' . $html . '</div>',
        LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    if (!$ok) {
        return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
    }

    $root = $doc->getElementById('note-root');
    if ($root === null) {
        return '';
    }

    $walk = static function (DOMNode $node) use (&$walk): void {
        // Parcours à rebours : on peut supprimer sans casser l'itération.
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);

            if ($child instanceof DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue; // texte : conservé tel quel
            }

            $tag = strtolower($child->nodeName);

            if (!in_array($tag, NOTE_ALLOWED_TAGS, true)) {
                // Balise refusée : on garde son contenu textuel, on jette la balise.
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            for ($a = $child->attributes->length - 1; $a >= 0; $a--) {
                $attr = $child->attributes->item($a);
                $name = strtolower($attr->nodeName);

                if (!in_array($name, NOTE_ALLOWED_ATTRS, true)) {
                    $child->removeAttribute($attr->nodeName); // écarte tous les on*
                    continue;
                }
                if ($name === 'href' && !is_safe_href($attr->nodeValue)) {
                    $child->removeAttribute('href');
                }
            }

            if ($tag === 'a' && $child->hasAttribute('href')) {
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener noreferrer');
            }

            $walk($child);
        }
    };

    $walk($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }

    return $out;
}

/**
 * Normalise et borne les réglages d'un widget avant enregistrement.
 */
function sanitize_widget_settings(array $settings, string $type): array
{
    $clean = [];

    if (isset($settings['title']) && is_string($settings['title'])) {
        $clean['title'] = mb_substr(trim($settings['title']), 0, 120);
    }

    if (isset($settings['color']) && is_string($settings['color'])
        && preg_match('/^#[0-9a-fA-F]{6}$/', $settings['color'])) {
        $clean['color'] = $settings['color'];
    }

    switch ($type) {
        case 'rss':
            if (isset($settings['url']) && is_string($settings['url'])) {
                $clean['url'] = mb_substr(trim($settings['url']), 0, 500);
            }
            $clean['limit'] = max(1, min(20, (int) ($settings['limit'] ?? 5)));
            $clean['display'] = in_array($settings['display'] ?? '', ['titles', 'previews'], true)
                ? $settings['display'] : 'previews';
            $clean['mode'] = in_array($settings['mode'] ?? '', ['normal', 'photos', 'single'], true)
                ? $settings['mode'] : 'normal';
            $clean['tags'] = sanitize_tags($settings['tags'] ?? []);
            break;

        case 'bookmarks':
            $links = [];
            foreach ((array) ($settings['links'] ?? []) as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $label = mb_substr(trim((string) ($link['label'] ?? '')), 0, 120);
                $url   = mb_substr(trim((string) ($link['url'] ?? '')), 0, 500);
                if ($label === '' && $url === '') {
                    continue;
                }
                // Un lien portant déjà un schéma autre que http(s) est écarté,
                // et non « complété » : « javascript:… » ne doit pas devenir
                // « https://javascript:… », qui masquerait la saisie initiale.
                if ($url !== '' && !preg_match('~^https?://~i', $url)) {
                    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
                        continue;
                    }
                    $url = 'https://' . ltrim($url, '/');
                }
                if ($url !== '' && !is_safe_href($url)) {
                    continue;
                }
                $links[] = ['label' => $label, 'url' => $url];
                if (count($links) >= 100) {
                    break;
                }
            }
            $clean['links'] = $links;
            break;

        case 'youtube':
            $url = trim((string) ($settings['url'] ?? ''));
            $clean['url'] = preg_match('~^https?://~i', $url) ? mb_substr($url, 0, 500) : '';
            break;

        case 'image':
            // Seuls les fichiers téléversés par l'application sont acceptés.
            $url = trim((string) ($settings['url'] ?? ''));
            $clean['url'] = preg_match('~^uploads/[A-Za-z0-9._-]+$~', $url) ? $url : '';
            break;

        case 'note':
            $clean['text'] = sanitize_note_html((string) ($settings['text'] ?? ''));
            break;

        case 'html':
            // Volontairement NON filtré. Ce code est rendu exclusivement dans une
            // iframe « sandbox » sans allow-same-origin : il s'exécute dans une
            // origine opaque, sans accès au DOM parent, aux cookies ni au
            // stockage du domaine. Filtrer ici retirerait tout l'intérêt du
            // widget sans rien ajouter à la sécurité, qui repose sur
            // l'isolation et non sur l'assainissement.
            // L'isolation est vérifiée côté client : voir renderWidget().
            $clean['html'] = mb_substr((string) ($settings['html'] ?? ''), 0, 50000);
            break;
    }

    return $clean;
}

/**
 * Normalise une liste de tags thématiques.
 *
 * Les tags sont déclarés au niveau du flux et font foi tels quels : aucune
 * classification automatique n'est appliquée au contenu des articles.
 * Accepte un tableau ou une chaîne séparée par des virgules.
 */
function sanitize_tags($tags): array
{
    if (is_string($tags)) {
        $tags = explode(',', $tags);
    }
    if (!is_array($tags)) {
        return [];
    }

    $clean = [];
    foreach ($tags as $tag) {
        if (!is_string($tag)) {
            continue;
        }

        // Espaces normalisés, casse d'affichage conservée.
        $tag = trim(preg_replace('/\s+/u', ' ', $tag));
        if ($tag === '') {
            continue;
        }

        $tag = mb_substr($tag, 0, 30);
        $key = mb_strtolower($tag);

        if (!isset($clean[$key])) {
            $clean[$key] = $tag;
        }
        if (count($clean) >= 8) {
            break;
        }
    }

    return array_values($clean);
}

/**
 * Types de widgets acceptés.
 */
function is_valid_widget_type(string $type): bool
{
    return in_array($type, ['rss', 'bookmarks', 'note', 'youtube', 'image', 'html'], true);
}
