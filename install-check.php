<?php
/**
 * install-check.php — vérification de l'installation.
 *
 * À ouvrir dans le navigateur après le dépôt des fichiers, puis à SUPPRIMER.
 * Il n'affiche aucun mot de passe ni aucun identifiant.
 */

$checks = [];

function add(string $label, bool $ok, string $detail = ''): void
{
    global $checks;
    $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
}

// --- 1. Version de PHP ---------------------------------------------------
add(
    'Version de PHP',
    PHP_VERSION_ID >= 80000,
    PHP_VERSION . (PHP_VERSION_ID >= 80000 ? '' : ' — PHP 8.0 minimum requis')
);

// --- 2. Extensions -------------------------------------------------------
foreach (['pdo_mysql', 'curl', 'mbstring', 'dom', 'fileinfo', 'json'] as $ext) {
    add('Extension ' . $ext, extension_loaded($ext), extension_loaded($ext) ? '' : 'absente');
}

// --- 3. Configuration ----------------------------------------------------
$configOk = false;
try {
    require_once __DIR__ . '/config.php';
    $configOk = defined('DB_HOST') && DB_NAME !== '';
    add('Fichier de configuration lu', $configOk, $configOk ? 'hôte : ' . DB_HOST : '');
} catch (Throwable $e) {
    add('Fichier de configuration lu', false, 'config.local.php introuvable ou incomplet');
}

// --- 4. Connexion à la base ---------------------------------------------
$pdo = null;
if ($configOk) {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        add('Connexion à la base', true);
    } catch (PDOException $e) {
        add('Connexion à la base', false, 'identifiants ou hôte incorrects');
    }
}

// --- 5. Tables -----------------------------------------------------------
if ($pdo) {
    $expected = ['users', 'user_settings', 'tabs', 'widgets', 'rss_cache', 'article_previews_cache'];
    $found = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff($expected, $found);
    add(
        'Tables présentes',
        empty($missing),
        empty($missing) ? count($expected) . ' tables' : 'manquantes : ' . implode(', ', $missing)
    );

    if (empty($missing)) {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        add(
            'Compte propriétaire',
            true,
            $count === 0 ? 'aucun compte — à créer via login.php' : 'compte créé'
        );
    }
}

// --- 6. Dossier des envois ----------------------------------------------
$uploads = __DIR__ . '/uploads';
add('Dossier uploads/ présent', is_dir($uploads));
add('Dossier uploads/ inscriptible', is_dir($uploads) && is_writable($uploads));
add('Protection uploads/.htaccess', is_file($uploads . '/.htaccess'));
add('Dossier uploads/backgrounds/ inscriptible',
    is_dir($uploads . '/backgrounds') && is_writable($uploads . '/backgrounds'));

// --- 7. Fichiers sensibles laissés en place -----------------------------
$leftovers = array_filter([
    'api_init_db_tabs.php', 'test_ia.php', 'test_regex.php', 'test.xml', 'othman_rss.sql',
], fn ($f) => is_file(__DIR__ . '/' . $f));
add(
    'Anciens fichiers supprimés',
    empty($leftovers),
    empty($leftovers) ? '' : 'à supprimer : ' . implode(', ', $leftovers)
);

// --- 8. HTTPS ------------------------------------------------------------
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
add('Connexion en HTTPS', $https, $https ? '' : 'le cookie de session ne sera pas marqué « secure »');

// --- 9. Accès sortant ----------------------------------------------------
if (function_exists('curl_init')) {
    $ch = curl_init('https://www.lemonde.fr/rss/une.xml');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_NOBODY => true]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    add('Accès sortant HTTP', $code > 0, $code > 0 ? 'code ' . $code : 'aucune réponse');
}

$failures = count(array_filter($checks, fn ($c) => !$c['ok']));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification de l'installation</title>
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; background:#f0f2f5; margin:0; padding:40px 20px; }
        .box { max-width:640px; margin:0 auto; background:#fff; border-radius:10px; padding:32px; box-shadow:0 4px 15px rgba(0,0,0,.08); }
        h1 { margin-top:0; font-size:20px; }
        ul { list-style:none; padding:0; margin:0; }
        li { display:flex; align-items:baseline; padding:8px 0; border-bottom:1px solid #eee; font-size:14px; }
        .mark { width:24px; flex:none; font-weight:bold; }
        .ok .mark { color:#2e7d32; }
        .ko .mark { color:#c62828; }
        .detail { color:#777; font-size:12px; margin-left:8px; }
        .verdict { margin-top:24px; padding:14px; border-radius:6px; font-size:14px; }
        .good { background:#e8f5e9; color:#1b5e20; }
        .bad  { background:#ffebee; color:#b71c1c; }
        .warn { margin-top:20px; padding:12px; background:#fff8e1; color:#7a5c00; border-radius:6px; font-size:13px; }
    </style>
</head>
<body>
<div class="box">
    <h1>Vérification de l'installation</h1>
    <ul>
        <?php foreach ($checks as $c): ?>
            <li class="<?= $c['ok'] ? 'ok' : 'ko' ?>">
                <span class="mark"><?= $c['ok'] ? '✓' : '✗' ?></span>
                <span><?= htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($c['detail'] !== ''): ?>
                    <span class="detail"><?= htmlspecialchars($c['detail'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="verdict <?= $failures === 0 ? 'good' : 'bad' ?>">
        <?= $failures === 0
            ? 'Tout est en place. Ouvrez login.php pour créer le compte propriétaire.'
            : $failures . ' point(s) à corriger avant de continuer.' ?>
    </div>

    <div class="warn">
        Supprimez ce fichier une fois l'installation terminée : il révèle la version
        de PHP et la structure de la base.
    </div>
</div>
</body>
</html>
