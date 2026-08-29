<?php
/**
 * login.php — création du compte unique, puis connexion.
 */

require __DIR__ . '/db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECONDS = 300;

$error = '';
$msg   = '';

$owner = owner_id($pdo);
$needs_setup = $owner === null;

/**
 * Petit garde-fou contre le bourrage de mots de passe, à l'échelle de la session.
 */
function login_locked_out(): bool
{
    if (($_SESSION['login_attempts'] ?? 0) < LOGIN_MAX_ATTEMPTS) {
        return false;
    }

    $elapsed = time() - ($_SESSION['login_last_attempt'] ?? 0);
    if ($elapsed > LOGIN_LOCKOUT_SECONDS) {
        $_SESSION['login_attempts'] = 0;

        return false;
    }

    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        $error = 'Session expirée. Rechargez la page et réessayez.';
    } elseif (login_locked_out()) {
        $error = 'Trop de tentatives. Réessayez dans quelques minutes.';
    } else {
        $action   = $_POST['action'] ?? '';
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($action === 'register' && $needs_setup) {
            if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
                $error = 'Le nom d\'utilisateur doit faire entre 3 et 50 caractères.';
            } elseif (mb_strlen($password) < 12) {
                $error = 'Le mot de passe doit faire au moins 12 caractères.';
            } else {
                try {
                    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
                    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);

                    $new_id = (int) $pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO user_settings (user_id) VALUES (?)')->execute([$new_id]);
                    $pdo->prepare('INSERT INTO tabs (user_id, title, tab_order) VALUES (?, ?, 0)')
                        ->execute([$new_id, 'Accueil']);

                    $msg = 'Compte créé. Vous pouvez maintenant vous connecter.';
                    $needs_setup = false;
                } catch (Throwable $e) {
                    error_log('Création de compte : ' . $e->getMessage());
                    $error = 'Impossible de créer le compte.';
                }
            }
        } elseif ($action === 'login') {
            $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Nouvelle session : coupe la fixation de session.
                session_regenerate_id(true);
                $_SESSION['user_id']        = (int) $user['id'];
                $_SESSION['username']       = $username;
                $_SESSION['login_attempts'] = 0;
                unset($_SESSION['csrf_token']); // un jeton neuf sera émis
                header('Location: index.php');
                exit;
            }

            // Même message dans tous les cas : ne pas révéler si le compte existe.
            $_SESSION['login_attempts']    = ($_SESSION['login_attempts'] ?? 0) + 1;
            $_SESSION['login_last_attempt'] = time();
            $error = 'Identifiants incorrects.';
        }
    }
}

$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="same-origin">
    <title>Connexion — Vision du Futur</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-container { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        h1 { margin-top: 0; color: #333; text-align: center; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; color: #666; }
        input[type="text"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background-color: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; transition: background 0.3s; }
        button:hover { background-color: #0056b3; }
        .error { color: #d9534f; margin-bottom: 15px; text-align: center; }
        .success { color: #5cb85c; margin-bottom: 15px; text-align: center; }
        .hint { text-align: center; color: #666; font-size: 14px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Connexion</h1>
        <?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($msg !== ''): ?><div class="success"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <form method="POST" action="login.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="<?= $needs_setup ? 'register' : 'login' ?>">

            <?php if ($needs_setup): ?>
                <p class="hint">Aucun compte n'existe encore. Le compte créé ici sera le propriétaire du tableau de bord — c'est le seul qui pourra le modifier.</p>
            <?php endif; ?>

            <div class="form-group">
                <label for="username">Nom d'utilisateur</label>
                <input type="text" id="username" name="username" autocomplete="username" required>
            </div>
            <div class="form-group">
                <label for="password">Mot de passe<?= $needs_setup ? ' (12 caractères minimum)' : '' ?></label>
                <input type="password" id="password" name="password"
                       autocomplete="<?= $needs_setup ? 'new-password' : 'current-password' ?>" required>
            </div>

            <button type="submit"><?= $needs_setup ? 'Créer le compte' : 'Se connecter' ?></button>

            <p style="text-align:center; margin-top:20px;">
                <a href="index.php" style="color:#007bff; text-decoration:none;">Retour au tableau de bord</a>
            </p>
        </form>
    </div>
</body>
</html>
