<?php
/**
 * migrate.php — mise à niveau d'une installation existante.
 *
 * Remplace api_init_db_tabs.php, qui était appelable par n'importe qui depuis
 * le web. Ce script ne s'exécute qu'en ligne de commande :
 *
 *   php migrate.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/db.php';

echo "Migration de la base…\n";

try {
    // 1. Table des onglets
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tabs` (
          `id`        INT(11)      NOT NULL AUTO_INCREMENT,
          `user_id`   INT(11)      NOT NULL,
          `title`     VARCHAR(255) NOT NULL,
          `tab_order` INT(11)      NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          KEY `fk_user_tab` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo "  · table tabs : présente\n";

    // 2. Colonne tab_id sur les widgets
    if ($pdo->query("SHOW COLUMNS FROM widgets LIKE 'tab_id'")->rowCount() === 0) {
        $pdo->exec('ALTER TABLE widgets ADD COLUMN tab_id INT(11) NULL DEFAULT NULL AFTER user_id');
        $pdo->exec('ALTER TABLE widgets ADD KEY idx_widget_tab (tab_id)');
        echo "  · colonne widgets.tab_id : ajoutée\n";
    } else {
        echo "  · colonne widgets.tab_id : déjà présente\n";
    }

    // 3. Un onglet par défaut pour chaque compte existant
    $users = $pdo->query('SELECT id FROM users')->fetchAll();
    foreach ($users as $user) {
        $uid  = (int) $user['id'];
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tabs WHERE user_id = ?');
        $stmt->execute([$uid]);

        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->prepare('INSERT INTO tabs (user_id, title, tab_order) VALUES (?, ?, 0)')
                ->execute([$uid, 'Accueil']);
            echo "  · onglet « Accueil » créé pour le compte $uid\n";
        }
    }

    // 4. Rattachement des widgets orphelins
    $affected = $pdo->exec('
        UPDATE widgets
        SET tab_id = (
            SELECT id FROM (SELECT * FROM tabs) t
            WHERE t.user_id = widgets.user_id
            ORDER BY t.tab_order ASC, t.id ASC LIMIT 1
        )
        WHERE tab_id IS NULL
    ');
    echo "  · widgets rattachés à un onglet : $affected\n";

    // 5. Table de suivi des articles lus
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `read_articles` (
          `id`       INT(11)  NOT NULL AUTO_INCREMENT,
          `user_id`  INT(11)  NOT NULL,
          `url_hash` CHAR(64) NOT NULL,
          `read_at`  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_user_url` (`user_id`, `url_hash`),
          KEY `idx_read_at` (`read_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo "  · table read_articles : présente\n";

    // 6. Couleur de fond et palette personnalisée
    foreach ([
        'background_color' => "ALTER TABLE user_settings ADD COLUMN background_color VARCHAR(32) NULL DEFAULT NULL",
        'custom_colors'    => "ALTER TABLE user_settings ADD COLUMN custom_colors TEXT NULL DEFAULT NULL",
    ] as $column => $sql) {
        if ($pdo->query("SHOW COLUMNS FROM user_settings LIKE '$column'")->rowCount() === 0) {
            $pdo->exec($sql);
            echo "  · colonne user_settings.$column : ajoutée\n";
        } else {
            echo "  · colonne user_settings.$column : déjà présente\n";
        }
    }

    // 7. Purge des caches : leur format a changé (limite d'articles, filtrage des URL)
    $pdo->exec('DELETE FROM rss_cache');
    $pdo->exec('DELETE FROM article_previews_cache');
    echo "  · caches vidés\n";

    echo "Migration terminée.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Échec : ' . $e->getMessage() . "\n");
    exit(1);
}
