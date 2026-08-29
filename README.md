# Flux RSS — tableau de bord personnel

<img width="1903" height="949" alt="Firefox_Screenshot_2026-08-29T20-40-31 927Z" src="https://github.com/user-attachments/assets/e5bd57aa-35af-4c33-afcd-86bc904f848d" />


Une page de démarrage à widgets, dans l'esprit de Protopage : des flux RSS, des
favoris, des notes et des widgets libres, organisés en onglets et disposés au
glisser-déposé.

PHP 8 et MySQL, sans dépendance à installer, sans Docker, sans processus
persistant. Le projet est conçu pour tourner sur un hébergement mutualisé
ordinaire.

## Pourquoi ce projet

Les tableaux de bord auto-hébergés existants — Glance, Dashy, Homarr — viennent
tous du monde du *homelab*. Ils supposent un serveur qu'on administre, une
configuration en YAML versionnée, et ils sont pensés pour surveiller des
services. Aucun ne reproduit ce que faisait Protopage : des onglets, des notes
adhésives, l'ajout d'un flux ou d'un lien à la volée depuis la page elle-même,
et une esthétique de page personnelle plutôt que de console d'administration.

Ce dépôt comble ce créneau. Tout s'édite dans le navigateur ; aucun fichier de
configuration n'est nécessaire au quotidien.

## Fonctionnalités

### Organisation

- **Onglets multiples**, renommables sur place
- **Grille au glisser-déposé** (gridstack) : déplacement, redimensionnement,
  positions enregistrées automatiquement
- **Fond d'écran** : palette de couleurs douces extensible, ou image téléversée
  — les deux sont exclusifs

### Widgets

| Type | Contenu |
|---|---|
| **Flux RSS** | Articles d'un flux, en liste, en liste avec résumé, en mosaïque de photos ou en grande image |
| **Favoris** | Liste de liens avec favicons automatiques |
| **Note** | Texte riche édité sur place (CKEditor 5 en ligne) |
| **YouTube** | Vidéo intégrée |
| **Photo** | Image téléversée |
| **Code HTML / JS** | Widget libre, exécuté dans un cadre isolé |

### Flux

- **Découverte automatique** : coller l'adresse d'un site suffit, le flux est
  trouvé via la balise `<link rel="alternate">`, puis par les chemins usuels
  (`/feed/`, `/rss.xml`, `/atom.xml`…). Les chaînes YouTube fonctionnent par ce
  biais, sans clé d'API Google.
- **Podcasts** : vignette `itunes:image`, durée `itunes:duration`, et lecteur
  audio créé à la demande au clic — aucune connexion n'est ouverte tant qu'on
  n'écoute pas.
- **Vignettes de remplacement** générées en SVG quand un article n'a pas
  d'image. La teinte dérive du nom d'hôte, donc les articles d'une même source
  partagent la même couleur.
- **Cache serveur** de 15 minutes par flux, réglable.
- **Détection du type** de flux : article, podcast ou vidéo.

### Lecture et recherche

- **Suivi lu / non lu** : un article cliqué passe en lu, avec marquage en bloc
  et remise à zéro depuis le menu
- **Tags thématiques** déclarés par flux, jusqu'à huit
- **Barre de filtres** : recherche plein texte (raccourci `/`, insensible aux
  accents), pastilles de tags, sélecteur de source, sélecteur de type
- **Import OPML** en deux temps : lecture du fichier, écran de sélection par
  dossier, puis création des widgets. Rien n'est écrit avant validation, les
  doublons sont fusionnés, les flux déjà présents sont grisés.

### Accès

Le modèle est **mono-utilisateur** : un seul compte propriétaire, créé au
premier lancement, est autorisé à modifier le tableau. Les visiteurs le
consultent en lecture seule, sans compte.

## Sécurité

Le projet a fait l'objet d'un audit et d'une passe de durcissement complète.
Les décisions structurantes :

**Le proxy de flux n'est pas ouvert.** `api_rss.php` n'accepte qu'une adresse
déjà enregistrée dans un widget du propriétaire ; `api_article_preview.php`
qu'un lien figurant dans un flux en cache. Un visiteur ne choisit jamais ce que
le serveur va chercher.

**Filtrage réseau à chaque requête et à chaque redirection** (`lib_http.php`) :
schémas `http`/`https` uniquement, ports 80 et 443 uniquement, rejet des plages
privées, réservées, de bouclage et de lien-local — dont `169.254.169.254`.
L'adresse IP validée est épinglée sur la connexion, ce qui ferme la fenêtre de
réattribution DNS. Redirections suivies manuellement, cinq au maximum, chacune
revalidée. Taille et durée de réponse plafonnées.

**Tout contenu distant est échappé** avant d'entrer dans le document. Les
adresses passent par un filtre qui n'admet que `http(s)` ou un fichier du
dossier des envois. Le HTML des notes est filtré côté serveur sur une liste
blanche de balises et d'attributs.

**Les widgets HTML/JS sont isolés, pas assainis.** Leur contenu est rendu dans
une `<iframe sandbox>` sans `allow-same-origin` : origine opaque, donc aucun
accès au DOM parent, aux cookies, au stockage du domaine ni au jeton CSRF.
Filtrer ce code en plus ne renforcerait rien et retirerait tout son intérêt.

**Les téléversements dérivent leur extension du type MIME détecté**, jamais du
nom envoyé par le client, avec double contrôle par `getimagesize()`. Un
`.htaccess` coupe l'exécution dans le dossier des envois.

**Toute écriture exige un jeton CSRF**, transmis en en-tête pour les requêtes
JSON et en champ pour les envois multipart, qui échappent au contrôle préalable
du navigateur.

Le détail complet, y compris ce qui reste ouvert, est dans
[SECURITE.md](SECURITE.md).

## Installation

Guide pas à pas dans [INSTALLATION.md](INSTALLATION.md). En résumé :

```bash
# 1. Base de données
mysql -h <hote> -u <utilisateur> -p <base> < schema.sql

# 2. Identifiants
cp config.local.example.php config.local.php
# renseigner hôte, base, utilisateur, mot de passe
chmod 600 config.local.php

# 3. Dossier des envois
mkdir -p uploads/backgrounds && chmod 755 uploads uploads/backgrounds
```

Ouvrir ensuite `install-check.php` pour vérifier l'environnement, le supprimer,
puis `login.php` : aucun compte n'existant, le formulaire propose la création du
compte propriétaire.

**Prérequis** : PHP 8.0 ou plus, avec `pdo_mysql`, `curl`, `mbstring`, `dom`,
`fileinfo` et `json` — tous actifs par défaut sur la plupart des hébergements.
MySQL 5.7 ou MariaDB 10.2 et plus.

### Mise à jour d'une installation existante

```bash
php migrate.php
```

Le script est réservé à la ligne de commande : c'est un script de modification
de schéma, il n'a rien à faire derrière une URL publique. Les mêmes
instructions peuvent être passées à la main dans phpMyAdmin.

## Configuration

Aucune configuration n'est nécessaire au quotidien. `config.local.php` ne
contient que les identifiants de base, plus trois réglages facultatifs :

| Clé | Défaut | Rôle |
|---|---|---|
| `rss_cache_minutes` | `15` | Durée de vie du cache des flux |
| `upload_max_bytes` | `5242880` | Taille maximale d'une image téléversée |
| `fetch_max_bytes` | `2097152` | Taille maximale d'une réponse distante |

Les variables d'environnement `FLUXRSS_DB_HOST`, `FLUXRSS_DB_NAME`,
`FLUXRSS_DB_USER` et `FLUXRSS_DB_PASS` sont prioritaires si elles sont
définies. `config.local.php` peut être placé un niveau au-dessus de la racine
web, hors d'atteinte du serveur.

## Architecture

Application PHP classique : pas de framework, pas d'autochargement, pas de
gestionnaire de paquets. Chaque point d'entrée est un fichier.

```
index.php                   Page unique : grille, onglets, panneaux
login.php / logout.php      Session du propriétaire
config.php                  Chargement de la configuration
db.php                      Connexion, session, CSRF, résolution du propriétaire

lib_http.php                Récupération distante protégée du SSRF
lib_sanitize.php            Filtrage du HTML et normalisation des réglages
lib_upload.php              Traitement des images téléversées

api_widgets.php             Lecture et sauvegarde d'une grille
api_create_widget.php       Création d'un widget
api_tabs.php                Onglets
api_rss.php                 Proxy de flux, cache, état de lecture
api_article_preview.php     Aperçu Open Graph au survol
api_read.php                Marquage lu / non lu
api_opml_parse.php          Lecture d'un OPML, sans écriture
api_opml_import.php         Création des widgets sélectionnés
api_background.php          Couleur de fond et palette
api_upload_background.php   Image de fond
api_upload_widget_image.php Image d'un widget
api_user_settings.php       Réglages d'affichage

assets/menu.js              Menus déroulants
assets/filters.js           Barre de filtres
assets/read-state.js        Suivi de lecture
assets/opml.js              Import OPML
assets/background.js        Fond d'écran

schema.sql                  Structure complète
migrate.php                 Mise à niveau (ligne de commande)
```

### Modèle de données

Sept tables. `users` et `user_settings` pour le compte unique, `tabs` et
`widgets` pour la structure, `rss_cache` et `article_previews_cache` pour les
contenus distants, `read_articles` pour l'état de lecture — dont les adresses
sont stockées en empreinte SHA-256, ce qui borne l'index et évite de conserver
un historique de lecture lisible en clair.

Les réglages d'un widget sont un document JSON dans la colonne `settings`. Il
n'y a **pas** de table d'articles : le cache conserve les flux tels quels, et la
recherche comme les filtres opèrent sur ce qui est déjà chargé dans la page.
C'est un choix assumé — voir *Limites connues*.

### Adaptation aux écrans

La grille se replie à 6 colonnes sous 1100 px et à 1 colonne sous 700 px. Dans
ces modes, **toute écriture est bloquée** : gridstack recalcule les positions
pour tenir dans moins de colonnes, et les enregistrer écraserait la disposition
établie sur grand écran. Le glisser-déposé est donc désactivé, avec un bandeau
qui l'explique. On réorganise sur grand écran, on consulte partout.

Le même principe gouverne la vue filtrée : elle remplace le positionnement
absolu par un flux en colonnes CSS, sans jamais toucher au moteur de gridstack,
si bien que retirer un filtre restaure la grille au pixel près.

## Développement

```bash
php tests.php             # 47 assertions : SSRF, échappement, nettoyage HTML
python3 tools-check-js.py # appels de fonctions sans définition correspondante
php -l <fichier>          # contrôle syntaxique
```

`tests.php` couvre le refus de `file://`, `gopher://`, `localhost`, `::1`, des
plages privées, des ports non standard et des identifiants dans l'URL ; la
résolution des adresses relatives ; le retrait de `<script>`, `onerror`,
`javascript:`, `data:`, `<iframe>` et `<svg onload>` ; la conservation de la
mise en forme légitime ; le bornage des réglages de widgets.

`tools-check-js.py` existe pour une raison précise : le script de la page est
long, et une suppression trop large y a déjà emporté des fonctions encore
appelées. L'outil vérifie que toute fonction invoquée, dans le script comme dans
un attribut `onclick`, est définie quelque part.

Les deux outils sont à retirer d'un serveur de production, avec `schema.sql` et
`install-check.php`.

## Limites connues

**Pas de table d'articles.** Les flux sont mis en cache tels quels. La recherche
et les filtres portent donc sur ce qui est affiché, pas sur un historique. Une
recherche sur trois mois, la déduplication entre flux reprenant la même dépêche,
ou l'export d'un corpus vers un outil d'analyse supposeraient une table
`articles`. C'est le principal chantier structurel si le projet doit devenir un
outil de veille plutôt qu'une page de démarrage.

**Pas de rafraîchissement en tâche de fond.** Les flux sont récupérés au
chargement de la page, avec un cache de 15 minutes. Une tâche planifiée
déporterait ce coût hors du rendu.

**Pas de Content-Security-Policy.** `index.php` contient de nombreux
gestionnaires `onclick` en ligne et des styles inline ; une CSP utile impose de
les sortir d'abord. C'est le même chantier que l'extraction du CSS et du
JavaScript vers des fichiers séparés.

**`index.php` est monolithique** — près de deux mille lignes, CSS et script
compris. Le découpage est engagé, les fonctionnalités récentes vivant dans
`assets/`, mais il est loin d'être terminé.

**Les tags sont déclarés par flux**, pas par article. Un flux généraliste
étiqueté « Tech » étiquette ainsi tous ses articles, y compris ceux qui ne le
sont pas. C'est imprécis et assumé : classer par article supposerait des règles
par mots-clés, ou un modèle de langage.

## Crédits

- [gridstack.js](https://gridstackjs.com) — grille au glisser-déposé
- [CKEditor 5](https://ckeditor.com) — édition des notes

Le modèle d'interaction — onglets, notes, édition sur place — est repris de
Protopage.

## Licence

MIT — voir [LICENSE](LICENSE).

