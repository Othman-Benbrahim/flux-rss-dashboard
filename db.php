<?php
/**
 * db.php — connexion, session, et helpers partagés par toutes les API.
 *
 * Modèle : tableau de bord mono-utilisateur.
 * Un seul compte existe (le propriétaire). Il est le seul à pouvoir écrire.
 * Les visiteurs non identifiés voient le tableau en lecture seule.
 */

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); // alwaysdata est derrière un reverse proxy

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------------------
// Erreurs : journalisées, jamais affichées
// ---------------------------------------------------------------------------
// Une exception non rattrapée affichait jusqu'ici la trace complète dans le
// navigateur, avec le chemin des fichiers et le nom de la base. Elle part
// désormais dans le journal, et le client reçoit une réponse propre.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(static function (Throwable $e): void {
    error_log('Exception non rattrapée : ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'Erreur interne du serveur.']);
});

// ---------------------------------------------------------------------------
// Connexion PDO
// ---------------------------------------------------------------------------
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Le message d'erreur de PDO contient l'hôte et l'utilisateur : ne jamais le renvoyer au client.
    error_log('Connexion BDD impossible : ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['error' => 'Base de données indisponible.']));
}

// ---------------------------------------------------------------------------
// Réponses JSON
// ---------------------------------------------------------------------------
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $code = 400): void
{
    json_out(['error' => $message], $code);
}

// ---------------------------------------------------------------------------
// Propriétaire unique du tableau de bord
// ---------------------------------------------------------------------------
/**
 * Identifiant du compte propriétaire : le premier compte créé.
 * Renvoie null tant qu'aucun compte n'existe.
 */
function owner_id(PDO $pdo): ?int
{
    static $cached = false;
    static $value = null;

    if ($cached) {
        return $value;
    }
    $cached = true;

    $id = $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
    $value = $id === null || $id === false ? null : (int) $id;

    return $value;
}

function is_owner(PDO $pdo): bool
{
    $owner = owner_id($pdo);

    return $owner !== null
        && isset($_SESSION['user_id'])
        && (int) $_SESSION['user_id'] === $owner;
}

/**
 * Exige une session propriétaire valide. Interrompt la requête sinon.
 */
function require_owner(PDO $pdo): int
{
    if (!is_owner($pdo)) {
        json_error('Non autorisé. Veuillez vous connecter.', 403);
    }

    return owner_id($pdo);
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Vérifie le jeton, envoyé soit en en-tête X-CSRF-Token (requêtes JSON),
 * soit en champ csrf_token (envois multipart, qui échappent au préflight CORS).
 */
function require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');

    if (empty($_SESSION['csrf_token']) || !is_string($sent) || $sent === ''
        || !hash_equals($_SESSION['csrf_token'], $sent)) {
        json_error('Jeton de sécurité invalide. Rechargez la page.', 403);
    }
}

// ---------------------------------------------------------------------------
// Appartenance des onglets
// ---------------------------------------------------------------------------
/**
 * Vérifie qu'un onglet appartient bien au propriétaire.
 * Renvoie l'identifiant validé, ou interrompt la requête.
 */
function require_own_tab(PDO $pdo, int $tabId, int $userId): int
{
    $stmt = $pdo->prepare('SELECT id FROM tabs WHERE id = ? AND user_id = ?');
    $stmt->execute([$tabId, $userId]);

    if ($stmt->fetchColumn() === false) {
        json_error('Onglet introuvable.', 404);
    }

    return $tabId;
}

/**
 * Onglet à afficher par défaut (le premier dans l'ordre) pour un utilisateur.
 */
function default_tab_id(PDO $pdo, int $userId): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM tabs WHERE user_id = ? ORDER BY tab_order ASC, id ASC LIMIT 1');
    $stmt->execute([$userId]);
    $id = $stmt->fetchColumn();

    return $id === false ? null : (int) $id;
}
