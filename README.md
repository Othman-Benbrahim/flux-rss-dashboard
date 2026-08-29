# Flux RSS — tableau de bord personnel

Page de démarrage à widgets, dans l'esprit de Protopage : flux RSS, favoris,
notes, vidéos, photos et widgets HTML, organisés en onglets et disposés au
glisser-déposé.

PHP 8 et MySQL, sans dépendance serveur à installer. Conçu pour tourner sur un
hébergement mutualisé.

## Fonctionnalités

- Grille éditable au glisser-déposé (gridstack), onglets multiples
- Widgets : flux RSS, favoris, notes riches, YouTube, photo, code HTML/JS
- Découverte automatique du flux depuis l'URL d'un site ou d'une chaîne YouTube
- Podcasts : vignette iTunes, durée, lecteur audio à la demande
- Vignettes de remplacement générées quand un article n'a pas d'image
- Import OPML avec écran de sélection
- Suivi lu / non lu
- Tags thématiques par flux, recherche et filtres par tag, source et type
- Fond d'écran : palette de couleurs extensible ou image
- Mono-utilisateur : un compte propriétaire, consultation publique en lecture seule

## Sécurité

Le proxy de flux n'accepte que les adresses déjà enregistrées dans un widget, et
filtre schémas, ports et plages d'adresses privées avec épinglage de l'IP
résolue. Tout contenu distant est échappé avant rendu. Les widgets HTML/JS
s'exécutent dans une iframe en origine opaque. Les téléversements dérivent leur
extension du type MIME détecté. Écritures protégées par jeton CSRF.

Détail complet dans [SECURITE.md](SECURITE.md).

## Installation

Voir [INSTALLATION.md](INSTALLATION.md). En résumé : créer la base, importer
`schema.sql`, renseigner `config.local.php`, ouvrir `login.php` pour créer le
compte propriétaire.

## Développement

```bash
php tests.php            # garde-fous SSRF, échappement, nettoyage HTML
python3 tools-check-js.py # appels de fonctions sans définition
```

## Licence

MIT
