<?php
/**
 * lib_http.php — récupération de contenu distant, protégée contre le SSRF.
 *
 * Règles appliquées à chaque requête, y compris à chaque redirection :
 *   - schémas http et https uniquement (pas de file://, gopher://, dict://…) ;
 *   - pas d'identifiants dans l'URL ;
 *   - résolution DNS explicite, puis refus de toute adresse privée,
 *     réservée, de bouclage ou de lien-local ;
 *   - l'adresse IP validée est épinglée sur la connexion (CURLOPT_RESOLVE),
 *     ce qui ferme la fenêtre de réattribution DNS entre la vérification
 *     et la connexion ;
 *   - redirections suivies manuellement, cinq au maximum, chacune revalidée ;
 *   - taille de réponse et durée plafonnées.
 */

require_once __DIR__ . '/config.php';

const HTTP_MAX_REDIRECTS = 5;
const HTTP_TIMEOUT       = 10;
const HTTP_USER_AGENT    = 'FluxRSS-Dashboard/1.0 (+lecteur de flux personnel)';

class HttpFetchException extends RuntimeException
{
}

/**
 * Résout un nom d'hôte en liste d'adresses IP (A et AAAA).
 * Un littéral IP est renvoyé tel quel.
 */
function resolve_host(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return [$host];
    }

    $ips = [];

    $v4 = @gethostbynamel($host);
    if (is_array($v4)) {
        $ips = $v4;
    }

    $records = @dns_get_record($host, DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
    }

    return array_values(array_unique($ips));
}

/**
 * Une adresse est-elle routable publiquement ?
 * Écarte 127.0.0.0/8, 10/8, 172.16/12, 192.168/16, 169.254/16, ::1, fc00::/7, etc.
 */
function is_public_ip(string $ip): bool
{
    return (bool) filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
}

/**
 * Valide une URL et renvoie ses composants utiles.
 *
 * @throws HttpFetchException si l'URL est refusée
 */
function validate_remote_url(string $url): array
{
    $parts = parse_url($url);

    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        throw new HttpFetchException('Adresse mal formée.');
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        throw new HttpFetchException('Seuls http et https sont acceptés.');
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new HttpFetchException('Les identifiants dans l\'adresse ne sont pas acceptés.');
    }

    $host = $parts['host'];
    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

    // On refuse les ports non standard : ils ne servent à rien pour un flux
    // public et ouvrent le balayage de services internes.
    if ($port !== 80 && $port !== 443) {
        throw new HttpFetchException('Port non autorisé.');
    }

    $ips = resolve_host($host);
    if (!$ips) {
        throw new HttpFetchException('Nom de domaine introuvable.');
    }

    $allowed = null;
    foreach ($ips as $ip) {
        if (is_public_ip($ip)) {
            $allowed = $ip;
            break;
        }
    }

    if ($allowed === null) {
        throw new HttpFetchException('Adresse réseau non autorisée.');
    }

    return ['host' => $host, 'port' => $port, 'ip' => $allowed, 'scheme' => $scheme];
}

/**
 * Reconstitue une URL absolue à partir d'une URL éventuellement relative.
 */
function absolutize_url(string $candidate, string $baseUrl): string
{
    if (preg_match('~^https?://~i', $candidate)) {
        return $candidate;
    }

    $base = parse_url($baseUrl);
    if ($base === false || empty($base['scheme']) || empty($base['host'])) {
        return $candidate;
    }

    $root = $base['scheme'] . '://' . $base['host'];
    if (!empty($base['port'])) {
        $root .= ':' . $base['port'];
    }

    if (str_starts_with($candidate, '//')) {
        return $base['scheme'] . ':' . $candidate;
    }
    if (str_starts_with($candidate, '/')) {
        return $root . $candidate;
    }

    $path = $base['path'] ?? '/';
    $dir  = rtrim(str_replace('\\', '/', dirname($path)), '/');

    return $root . $dir . '/' . $candidate;
}

/**
 * Récupère une ressource distante.
 *
 * @return array{0:string,1:int,2:string} corps, code HTTP, URL finale
 * @throws HttpFetchException
 */
function safe_fetch(string $url, int $maxBytes = null): array
{
    $maxBytes = $maxBytes ?? FETCH_MAX_BYTES;
    $current  = $url;

    for ($hop = 0; $hop <= HTTP_MAX_REDIRECTS; $hop++) {
        $target = validate_remote_url($current);

        $body      = '';
        $overLimit = false;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $current,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // redirections gérées ici, avec revalidation
            CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => HTTP_USER_AGENT,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ACCEPT_ENCODING => '',
            // Épinglage de l'IP validée : le nom d'hôte ne sera pas re-résolu.
            CURLOPT_RESOLVE        => [$target['host'] . ':' . $target['port'] . ':' . $target['ip']],
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, &$overLimit, $maxBytes) {
                $body .= $chunk;
                if (strlen($body) > $maxBytes) {
                    $overLimit = true;

                    return 0; // interrompt le téléchargement
                }

                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $loc   = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($overLimit) {
            throw new HttpFetchException('Réponse distante trop volumineuse.');
        }
        if ($errno !== 0 && $errno !== CURLE_WRITE_ERROR) {
            throw new HttpFetchException('Ressource distante injoignable.');
        }

        if (in_array($code, [301, 302, 303, 307, 308], true) && $loc !== '') {
            $current = absolutize_url($loc, $current);
            continue;
        }

        return [$body, $code, $current];
    }

    throw new HttpFetchException('Trop de redirections.');
}
