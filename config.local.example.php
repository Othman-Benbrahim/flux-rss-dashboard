<?php
/**
 * Copier ce fichier en config.local.php et y mettre les vrais identifiants.
 * config.local.php est ignoré par git et refusé par le serveur web (.htaccess).
 *
 * Sur alwaysdata, préférer les variables d'environnement :
 *   FLUXRSS_DB_HOST, FLUXRSS_DB_NAME, FLUXRSS_DB_USER, FLUXRSS_DB_PASS
 */

return [
    'db_host' => 'mysql-xxxxx.alwaysdata.net',
    'db_name' => 'xxxxx_rss',
    'db_user' => 'xxxxx',
    'db_pass' => '',

    // Optionnels
    'rss_cache_minutes' => 15,
    'upload_max_bytes'  => 5242880,
    'fetch_max_bytes'   => 2097152,
];
