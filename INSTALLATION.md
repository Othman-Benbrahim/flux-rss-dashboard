# Installation sur alwaysdata

Procédure pour une installation neuve, base vide. Remplacez `COMPTE` par le nom
de votre compte alwaysdata partout où il apparaît.

Durée : une vingtaine de minutes.

---

## 1. Créer la base de données

Administration alwaysdata → **Bases de données → MySQL → Ajouter une base de
données**.

- **Nom** : `COMPTE_rss` (alwaysdata préfixe automatiquement avec votre compte)
- **Encodage** : `utf8mb4` / `utf8mb4_general_ci`

Puis **Bases de données → MySQL → Utilisateurs → Ajouter un utilisateur** :

- **Nom** : `COMPTE_rss` par exemple
- **Mot de passe** : générez-en un long et aléatoire, et **notez-le maintenant**
  — c'est celui que vous mettrez dans `config.local.php` à l'étape 5. Ce doit
  être un mot de passe neuf, pas l'ancien.
- **Droits** : `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP` sur
  la base créée ci-dessus.

L'hôte MySQL à utiliser est **`mysql-COMPTE.alwaysdata.net`**, pas `localhost`.

---

## 2. Régler la version de PHP

Administration → **Environnement → PHP**.

Choisissez **8.2** ou **8.3**. Le code utilise `str_starts_with()` et `match`,
donc **PHP 8.0 est le minimum absolu** ; en dessous, rien ne démarre.

Extensions nécessaires, toutes actives par défaut chez alwaysdata :
`pdo_mysql`, `curl`, `mbstring`, `dom`, `fileinfo`, `json`. L'étape 8 les
vérifiera.

---

## 3. Créer le site

Administration → **Web → Sites → Ajouter un site**.

- **Type** : `PHP`
- **Adresses** : votre domaine ou sous-domaine, par exemple
  `rss.visiondufutur.com` ou `COMPTE.alwaysdata.net`
- **Racine** : `/www/flux-rss` (chemin relatif à `$HOME`)

Si vous utilisez un sous-domaine, pensez à activer le certificat SSL une fois le
DNS propagé : **Web → Sites → SSL**. La procédure Let's Encrypt d'alwaysdata est
automatique. C'est important ici : sans HTTPS, le cookie de session n'est pas
marqué `secure`.

---

## 4. Déposer les fichiers

### En SSH (recommandé)

```bash
ssh COMPTE@ssh-COMPTE.alwaysdata.net
mkdir -p ~/www/flux-rss
```

Puis, depuis votre machine, dans le dossier contenant `flux-rss-alwaysdata.zip` :

```bash
scp flux-rss-alwaysdata.zip COMPTE@ssh-COMPTE.alwaysdata.net:~/
```

De retour en SSH :

```bash
cd ~
unzip flux-rss-alwaysdata.zip
cp -r flux-rss/. www/flux-rss/
rm -rf flux-rss flux-rss-alwaysdata.zip
```

### En SFTP

Hôte `ssh-COMPTE.alwaysdata.net`, port 22, vos identifiants SSH. Déposez le
contenu du dossier `flux-rss/` dans `www/flux-rss/`.

**Attention** : votre client SFTP masque probablement les fichiers commençant
par un point. Vérifiez que **`.htaccess`**, **`uploads/.htaccess`** et
**`uploads/backgrounds/.htaccess`** sont bien montés — ce sont eux qui empêchent
l'exécution de code dans le dossier des envois. Dans FileZilla :
*Serveur → Forcer l'affichage des fichiers cachés*.

### Droits sur les dossiers d'envoi

```bash
cd ~/www/flux-rss
mkdir -p uploads/backgrounds
chmod 755 uploads uploads/backgrounds
```

---

## 5. Configurer les identifiants

C'est ici que va votre mot de passe MySQL. **Ne le mettez nulle part ailleurs.**

Les variables d'environnement ne conviennent pas : chez alwaysdata, le champ
*Environment* n'existe que pour les sites à processus persistant (Node, Python,
programme utilisateur), pas pour les sites PHP. On passe donc par un fichier.

En SSH :

```bash
cd ~/www/flux-rss
cp config.local.example.php config.local.php
nano config.local.php
```

Remplissez :

```php
return [
    'db_host' => 'mysql-COMPTE.alwaysdata.net',
    'db_name' => 'COMPTE_rss',
    'db_user' => 'COMPTE_rss',
    'db_pass' => 'le-mot-de-passe-noté-à-l-étape-1',
];
```

Puis restreignez les droits :

```bash
chmod 600 config.local.php
```

**Variante plus sûre**, si vous voulez le fichier hors de la racine web :
placez-le dans `~/www/` au lieu de `~/www/flux-rss/`. `config.php` le cherche
d'abord un cran au-dessus de lui, puis à côté. Un fichier hors racine web ne
peut être servi par le serveur en aucune circonstance, même si le `.htaccess`
saute.

---

## 6. Importer la structure de la base

La base est vide, donc **`migrate.php` ne sert à rien ici** : il est destiné aux
installations existantes. `schema.sql` contient déjà tout, y compris la table
`tabs` et la colonne `widgets.tab_id`.

### En SSH

```bash
cd ~/www/flux-rss
mysql -h mysql-COMPTE.alwaysdata.net -u COMPTE_rss -p COMPTE_rss < schema.sql
```

Le mot de passe est demandé de façon interactive — ne le passez jamais en
argument, il resterait dans l'historique du shell.

### Par phpMyAdmin

Administration → **Bases de données → MySQL → phpMyAdmin**. Sélectionnez la
base, onglet **Importer**, envoyez `schema.sql`, exécutez.

Vérification : six tables doivent apparaître — `users`, `user_settings`, `tabs`,
`widgets`, `rss_cache`, `article_previews_cache`.

---

## 7. Retirer les fichiers qui n'ont rien à faire en production

```bash
cd ~/www/flux-rss
rm -f schema.sql tests.php
```

`tests.php` ne s'exécute qu'en ligne de commande, mais autant ne pas le laisser.
Gardez `migrate.php` : il refuse de s'exécuter par le web et vous servira lors
d'une future mise à jour.

---

## 8. Vérifier l'installation

Ouvrez `https://votre-domaine/install-check.php`.

La page teste la version de PHP, les six extensions, la lecture de
`config.local.php`, la connexion à la base, la présence des six tables, les
droits sur `uploads/`, la présence des `.htaccess`, le HTTPS, et l'accès sortant
nécessaire aux flux. Elle n'affiche aucun identifiant.

Corrigez ce qui est marqué en rouge avant de continuer.

---

## 9. Tester la protection du dossier d'envois

C'est le contrôle le plus important, et il ne prend qu'une minute.

```bash
cd ~/www/flux-rss
echo '<?php echo "EXECUTE"; ?>' > uploads/test-securite.php
```

Ouvrez `https://votre-domaine/uploads/test-securite.php`.

- **Attendu** : une erreur **403 Forbidden**, ou le code source affiché tel
  quel. Dans les deux cas, c'est bon.
- **Problème** : si le mot `EXECUTE` s'affiche, le `.htaccess` n'a pas été monté
  ou n'est pas pris en compte. N'allez pas plus loin — un fichier PHP téléversé
  s'exécuterait sur votre serveur.

Puis supprimez le fichier de test :

```bash
rm uploads/test-securite.php
```

---

## 10. Créer le compte propriétaire

Ouvrez `https://votre-domaine/login.php`.

Aucun compte n'existant, le formulaire propose la création. Le compte créé ici
est **le propriétaire** : le seul qui pourra modifier le tableau de bord. Le mot
de passe doit faire **12 caractères minimum**.

À la création, l'onglet « Accueil » est généré automatiquement.

Vous arrivez ensuite sur le tableau de bord. Testez l'enchaînement complet :
ajouter un flux RSS, le déplacer, le redimensionner, recharger la page pour
confirmer que la position est bien enregistrée.

---

## 11. Supprimer le vérificateur

```bash
rm ~/www/flux-rss/install-check.php
```

Il révèle la version de PHP et la structure de la base. Il n'a plus d'usage.

---

## Si quelque chose casse

**Erreur 500 sur tout le site.** Presque toujours le `.htaccess`. Les erreurs
Apache sont dans `$HOME/admin/logs/apache/apache.log`, lisibles en SSH :

```bash
tail -30 ~/admin/logs/apache/apache.log
```

Un message `Invalid command '\xef\xbb\xbf'` signifie que le fichier a été
enregistré avec un BOM — réenregistrez-le en UTF-8 sans BOM.

**Page blanche.** Erreur PHP fatale. Les erreurs applicatives partent dans le
log via `error_log()` :

```bash
tail -30 ~/admin/logs/COMPTE/php.log
```

Le chemin exact des logs PHP figure dans **Web → Sites → Logs** de
l'administration.

**« Base de données indisponible ».** Hôte, nom de base, utilisateur ou mot de
passe incorrect dans `config.local.php`. Rappel : l'hôte est
`mysql-COMPTE.alwaysdata.net`, pas `localhost`. Testez la connexion isolément :

```bash
mysql -h mysql-COMPTE.alwaysdata.net -u COMPTE_rss -p COMPTE_rss -e "SHOW TABLES;"
```

**« Ce flux n'est pas enregistré sur ce tableau de bord ».** Comportement
normal, pas un bug : `api_rss.php` n'accepte que les adresses déjà présentes
dans un widget. Si vous appelez l'API à la main pour tester, elle refusera.
Passez par l'interface.

**Un flux reste sur « Chargement… ».** Vérifiez l'accès sortant dans
`install-check.php`. Si l'accès est bon, le flux est peut-être servi sur un port
non standard ou derrière une redirection vers une adresse privée — les deux sont
refusés par `lib_http.php`, volontairement.

---

## Récapitulatif des fichiers en production

| Fichier | Rôle |
|---|---|
| `index.php` | Tableau de bord |
| `login.php`, `logout.php` | Session du propriétaire |
| `config.php` | Chargement de la configuration |
| `config.local.php` | **Vos identifiants** — jamais versionné |
| `db.php` | Connexion, session, CSRF, propriétaire |
| `lib_http.php` | Récupération distante protégée du SSRF |
| `lib_sanitize.php` | Filtrage du HTML et des réglages |
| `lib_upload.php` | Traitement des images téléversées |
| `api_*.php` | Points d'entrée JSON |
| `migrate.php` | Mise à niveau future (CLI uniquement) |
| `.htaccess` | Protection des fichiers internes |
| `uploads/.htaccess` | Interdiction d'exécution |

Supprimés après installation : `schema.sql`, `tests.php`, `install-check.php`.
