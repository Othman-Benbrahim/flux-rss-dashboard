<?php
/**
 * config.php — chargement de la configuration.
 *
 * Aucun identifiant ne doit figurer dans ce fichier.
 * Deux sources possibles, dans cet ordre :
 *   1. les variables d'environnement (recommandé sur alwaysdata) ;
 *   2. un fichier config.local.php, non versionné (voir .gitignore).
 */

// Le fichier d'identifiants est cherché à deux endroits : à côté de ce
// fichier, ou un cran au-dessus — ce qui permet de le placer hors de la
// racine web, là où le serveur ne peut pas le servir du tout.
$candidates = [
    dirname(__DIR__) . '/config.local.php',
    __DIR__ . '/config.local.php',
];

$conf = [];
foreach ($candidates as $candidate) {
    if (is_readable($candidate)) {
        $loaded = require $candidate;
        if (is_array($loaded)) {
            $conf = $loaded;
        }
        break;
    }
}

function conf_value(array $conf, string $key, string $env, ?string $default = null): string
{
    $fromEnv = getenv($env);
    if ($fromEnv !== false && $fromEnv !== '') {
        return $fromEnv;
    }
    if (isset($conf[$key]) && $conf[$key] !== '') {
        return (string) $conf[$key];
    }
    if ($default !== null) {
        return $default;
    }
    http_response_code(500);
    exit('Configuration incomplète : ' . $env . ' est absent.');
}

define('DB_HOST', conf_value($conf, 'db_host', 'FLUXRSS_DB_HOST'));
define('DB_NAME', conf_value($conf, 'db_name', 'FLUXRSS_DB_NAME'));
define('DB_USER', conf_value($conf, 'db_user', 'FLUXRSS_DB_USER'));
define('DB_PASS', conf_value($conf, 'db_pass', 'FLUXRSS_DB_PASS'));

// Durée de vie du cache des flux, en minutes.
define('RSS_CACHE_MINUTES', (int) conf_value($conf, 'rss_cache_minutes', 'FLUXRSS_CACHE_MINUTES', '15'));

// Taille maximale d'un téléversement, en octets (5 Mo par défaut).
define('UPLOAD_MAX_BYTES', (int) conf_value($conf, 'upload_max_bytes', 'FLUXRSS_UPLOAD_MAX', '5242880'));

// Taille maximale d'une réponse distante récupérée par le proxy (2 Mo).
define('FETCH_MAX_BYTES', (int) conf_value($conf, 'fetch_max_bytes', 'FLUXRSS_FETCH_MAX', '2097152'));
