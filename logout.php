<?php
/**
 * logout.php — fermeture de session.
 *
 * En POST avec jeton : un lien piégé sur un site tiers ne peut plus
 * déconnecter le propriétaire à son insu.
 */

require __DIR__ . '/db.php';

// Le POST reste la voie normale. Un GET est accepté s'il porte le jeton :
// un attaquant ne peut pas le deviner, donc la protection CSRF tient, et un
// lien direct vers logout.php?token=... reste utilisable pour un test.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sent   = $method === 'POST'
    ? (string) ($_POST['csrf_token'] ?? '')
    : (string) ($_GET['token'] ?? '');

if (($method !== 'POST' && $method !== 'GET')
    || empty($_SESSION['csrf_token'])
    || $sent === ''
    || !hash_equals($_SESSION['csrf_token'], $sent)) {
    header('Location: index.php');
    exit;
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

session_destroy();

header('Location: index.php');
exit;
