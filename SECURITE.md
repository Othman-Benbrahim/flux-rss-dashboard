# Correctifs de sécurité — notes de déploiement

Passe portant uniquement sur la sécurité et la cohérence du modèle
d'utilisation. Aucun changement d'apparence : la grille, les onglets, le
glissé-déposé et la palette pastel sont inchangés.

Modèle retenu : **un seul compte propriétaire**. Lui seul écrit ; les
visiteurs voient le tableau en lecture seule.

---

## À faire avant toute mise en ligne

1. **Changer le mot de passe MySQL.** L'ancien `db.php` le contenait en clair.
   Considérez-le comme divulgué.
2. **Renseigner la configuration**, au choix :
   - variables d'environnement `FLUXRSS_DB_HOST`, `FLUXRSS_DB_NAME`,
     `FLUXRSS_DB_USER`, `FLUXRSS_DB_PASS` (recommandé sur alwaysdata) ;
   - ou `cp config.local.example.php config.local.php` puis remplir le fichier.
3. **Migrer la base** : `php migrate.php` en SSH. Le script crée la table
   `tabs`, ajoute `widgets.tab_id`, rattache les widgets orphelins et vide les
   caches, dont le format a changé.
4. **Supprimer les anciens fichiers** du serveur s'ils y sont encore :
   `api_init_db_tabs.php`, `test_ia.php`, `test_regex.php`, `test.xml`,
   `othman_rss.sql`.
5. **Vérifier que `uploads/` n'exécute pas de PHP.** Un `.htaccess` est fourni.
   Test : déposer un fichier contenant `<?php echo 1; ?>` nommé `t.php` dans
   `uploads/` et l'appeler dans le navigateur. Le code source doit s'afficher,
   pas le chiffre 1. Supprimer le fichier ensuite.
6. **Retirer `tests.php`** du serveur de production (il ne s'exécute qu'en CLI,
   mais il n'a rien à y faire).

---

## Ce qui a été corrigé

### 1. Identifiants hors du code
`db.php` ne contient plus aucun secret. `config.php` lit l'environnement ou
`config.local.php`, ignoré par git et refusé par le `.htaccess`. Les erreurs de
connexion PDO ne sont plus renvoyées au navigateur : elles partaient avec le nom
d'hôte et l'utilisateur.

### 2. SSRF — le trou principal
`api_rss.php` et `api_article_preview.php` acceptaient une URL arbitraire, sans
authentification, et la passaient à cURL avec les redirections activées.

Deux verrous ont été posés.

**Liste blanche.** `api_rss.php` n'accepte qu'une adresse déjà enregistrée dans
un widget du propriétaire. `api_article_preview.php` n'accepte qu'un lien
figurant dans un flux déjà en cache. Un visiteur ne choisit donc plus ce que le
serveur va chercher.

**Filtrage réseau** (`lib_http.php`), appliqué à chaque requête *et à chaque
redirection* :

- schémas `http` et `https` uniquement — `file://`, `gopher://`, `dict://` sont
  refusés ;
- ports 80 et 443 uniquement, ce qui ferme le balayage de services internes ;
- pas d'identifiants dans l'URL ;
- résolution DNS explicite, puis rejet de toute adresse privée, réservée, de
  bouclage ou de lien-local — dont `169.254.169.254` ;
- l'IP validée est épinglée sur la connexion (`CURLOPT_RESOLVE`), ce qui ferme
  la fenêtre de réattribution DNS entre la vérification et la connexion ;
- redirections suivies à la main, cinq au maximum, chacune revalidée ;
- taille de réponse plafonnée (2 Mo par défaut), délai plafonné.

### 3. XSS stocké via les flux
Titres, liens, résumés, images et libellés de favoris venaient de sites tiers et
entraient directement dans `innerHTML`. Un seul flux compromis suffisait à faire
exécuter du JavaScript dans la session du propriétaire.

Tout passe désormais par `esc()`, et les adresses par `safeUrl()`, qui n'accepte
que `http(s)://` ou un fichier du dossier `uploads/`. Les identifiants de vidéo
YouTube sont validés au format strict avant d'entrer dans un `src`. Les titres
d'onglets ne sont plus interpolés dans un attribut `onclick` : seul
l'identifiant numérique y figure. Les valeurs des champs de saisie sont posées
par propriété DOM et non par concaténation HTML.

Côté serveur, le HTML des notes CKEditor est filtré à l'entrée
(`lib_sanitize.php`) sur une liste blanche de balises et d'attributs : les `on*`
et les `href` en `javascript:` ou `data:` sont retirés, les liens externes
reçoivent `rel="noopener noreferrer"`.

### 4. Téléversements exécutables
`api_upload_background.php` faisait confiance au `Content-Type` envoyé par le
client et conservait l'extension d'origine — un fichier PHP pouvait atterrir
dans `uploads/`.

`lib_upload.php` centralise le traitement : type MIME lu dans le contenu par
`finfo`, second contrôle par `getimagesize()`, **extension déduite du type
détecté** et jamais du nom envoyé, nom aléatoire, taille plafonnée, permissions
`0644`. Un `.htaccess` coupe l'exécution dans `uploads/` et ses sous-dossiers.
L'ancien fond est supprimé du disque lors d'un remplacement.

### 5. CSRF
Aucun jeton n'existait, et les envois multipart échappent au préflight CORS :
un site tiers pouvait écrire dans le compte pendant une session ouverte.

Un jeton est désormais posé en session, transmis en en-tête `X-CSRF-Token` pour
les requêtes JSON et en champ `csrf_token` pour les envois multipart, et vérifié
par `hash_equals()` sur toute écriture. La déconnexion passe en POST avec jeton.

### 6. Modèle utilisateur cohérent
`$user_id = 1` était codé en dur dans presque toutes les lectures, alors que les
écritures utilisaient la session : tout compte connecté écrivait sur le tableau
de l'administrateur.

`owner_id()` résout le compte propriétaire (le premier créé). Les lectures
publiques renvoient son tableau ; les écritures exigent une session
correspondant à ce compte. L'appartenance de l'onglet est vérifiée à chaque
écriture (`require_own_tab()`), et le type d'un widget est relu en base plutôt
qu'accepté du client.

### 7. Cache réactivé
Le cache RSS était commenté (« DÉSACTIVÉ TEMPORAIREMENT POUR FORCER LA MISE À
JOUR DES MINIATURES »). Chaque chargement de page relançait un appel distant par
widget. Il est réactivé, à 15 minutes par défaut, réglable par
`FLUXRSS_CACHE_MINUTES`. Le cache stocke jusqu'à 20 articles et la limite
d'affichage est appliquée à la sortie, ce qui évite de recharger le flux quand
vous changez ce réglage.

### 8. Divers
- `login.php` : jeton CSRF, `session_regenerate_id()` à la connexion, message
  d'erreur identique que le compte existe ou non, blocage après cinq tentatives,
  mot de passe de 12 caractères minimum à la création. L'onglet « Accueil » est
  créé avec le compte.
- `api_init_db_tabs.php`, endpoint de migration DDL appelable par n'importe qui,
  est remplacé par `migrate.php`, exécutable en CLI uniquement.
- `othman_rss.sql` (qui portait l'hôte, le nom de base et un extrait du Monde)
  est remplacé par `schema.sql`, à jour de `tabs` et `tab_id`, sans données.
- Bornage des entrées : 30 onglets, 100 widgets par onglet, 100 favoris,
  dimensions de grille, longueurs de champs.
- `.htaccess` racine : accès direct refusé à `config*.php`, `db.php`, `lib_*.php`,
  `migrate.php` et `*.sql`, plus `X-Content-Type-Options`, `X-Frame-Options` et
  `Referrer-Policy`.

---

## Vérification

`php tests.php` en CLI. 47 assertions : refus `file://`, `gopher://`,
boucle locale, `localhost`, `::1`, `169.254.169.254`, plages privées,
identifiants dans l'URL, ports non standard ; résolution des URL relatives ;
retrait de `<script>`, `onerror`, `onclick`, `javascript:`, `data:`, `<iframe>`,
`<svg onload>` ; conservation de la mise en forme légitime ; bornage des
réglages de widgets.

---

## Deux points restés ouverts

**`api_article_preview.php` n'est appelé nulle part.** L'aperçu au survol
fonctionne uniquement avec les attributs `data-*` remplis depuis le flux. Le
fichier est durci, mais c'est du code mort : soit vous le supprimez, soit vous
le branchez sur `applyHoverPreviews()` pour enrichir l'aperçu quand le flux ne
fournit ni image ni résumé.

**Pas de Content-Security-Policy.** Le fichier est truffé de `onclick` en ligne
et de styles inline ; une CSP utile imposerait de les sortir d'abord. C'est le
même chantier que la sortie du CSS et du JS d'`index.php` vers des fichiers
séparés — à faire, mais après l'identité visuelle, et sans urgence maintenant
que l'échappement est en place.

---

# Ajouts : OPML, widgets HTML/JS, suivi de lecture, menus

## Mise à jour d'une installation existante

Une table a été ajoutée. En SSH :

```bash
cd ~/www/flux-rss
php migrate.php
```

Déposez aussi le nouveau dossier `assets/` (trois fichiers JavaScript). Sans
lui, les menus, l'import OPML et le marquage de lecture restent inertes.

## Widget HTML / JS — isolation, pas assainissement

Le contenu de ce widget n'est **pas** filtré, et c'est délibéré. Il est rendu
dans une iframe :

```html
<iframe sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox allow-forms"
        srcdoc="…"></iframe>
```

L'absence de `allow-same-origin` donne au cadre une **origine opaque** : le code
qui s'y exécute ne peut atteindre ni le DOM parent, ni `document.cookie`, ni le
`localStorage` du domaine, ni le jeton CSRF. Filtrer le HTML en plus ne
renforcerait rien et retirerait tout l'intérêt du widget.

Deux règles à ne jamais enfreindre si vous touchez à ce code :

1. **`allow-scripts` et `allow-same-origin` ne vont jamais ensemble.** Combinés,
   ils permettent au code embarqué de retirer l'attribut `sandbox` de sa propre
   iframe, puis de recharger — l'isolation disparaît.
2. Le contenu passe par `esc()` avant d'entrer dans l'attribut `srcdoc`.
   Sans cela, une charge contenant un guillemet double sortirait de l'attribut
   et s'exécuterait dans la page hôte. Vérifié sur quatre charges de sortie
   d'attribut.

Contrepartie assumée : un widget qui a besoin de cookies, du stockage du
domaine, ou de dialoguer avec la page hôte ne fonctionnera pas. Les embeds
tiers habituels (météo, compteurs, lecteurs) passent sans problème.

## Import OPML

Deux points d'entrée séparés, ce qui évite d'écrire quoi que ce soit avant
validation.

`api_opml_parse.php` lit le fichier et renvoie l'arborescence. Plafond de 2 Mo
et de 500 flux. Les `xmlUrl` qui ne sont pas en `http(s)` sont écartés au
parsing — un OPML contenant `file:///etc/passwd` ne produit aucune entrée. Les
doublons internes au fichier sont fusionnés, et les flux déjà présents sur le
tableau sont signalés puis rendus non sélectionnables.

`api_opml_import.php` crée les widgets : 60 flux par import, 100 par onglet,
dédoublonnage contre l'ensemble du tableau, disposition en quatre colonnes sous
les widgets existants. Les flux importés entrent automatiquement dans la liste
blanche de `api_rss.php`, puisque celle-ci est dérivée des widgets.

Le parsing n'utilise pas `LIBXML_NOENT`, qui activerait la substitution
d'entités. Sur libxml récent les entités externes sont désactivées par défaut,
donc l'écart pratique est nul ; le drapeau a été retiré partout, y compris dans
`api_rss.php`, parce qu'il n'apporte rien et ouvre une porte inutile.

## Suivi lu / non lu

Les adresses sont stockées en **SHA-256** dans `read_articles`, pas en clair :
l'index reste de taille fixe, et la table ne constitue pas un historique de
lecture lisible. Clé unique `(user_id, url_hash)`.

L'état est calculé à la sortie de `api_rss.php` et jamais mis en cache — le
contenu d'un flux reste valable quinze minutes, l'état de lecture change à
chaque clic. Purge automatique au-delà de 180 jours, déclenchée une fois sur
cinquante pour ne pas peser sur chaque requête.

« Tout marquer comme lu » porte sur l'onglet visible uniquement. Un bouton qui
effacerait l'état d'onglets qu'on ne voit pas serait une mauvaise surprise.

## Menus de l'en-tête

Purement présentationnel, sans effet sur la sécurité. La déconnexion reste un
POST avec jeton — l'entrée de menu déclenche la soumission d'un formulaire
caché. Fermeture au clic extérieur et à Échap, navigation aux flèches,
focus visible au clavier.
