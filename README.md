# Real Media Export

Ce dépôt contient un plugin WordPress ajoutant une page d’export dans l’administration pour les sites utilisant [Real Media Library](https://devowl.io/).

## Installation

1. Copier le dossier `real-media-export` dans le répertoire `wp-content/plugins/` de votre site WordPress.
2. Activer le plugin "Real Media Export" depuis le menu **Extensions** de WordPress.

## Utilisation

Un nouvel écran **Export RML** est disponible dans le menu **Médias**. Il permet :

- de choisir le dossier Real Media Library à exporter ;
- d’inclure ou non les sous-dossiers ;
- de définir une taille maximale par archive ZIP (les archives sont découpées automatiquement) ;
- de personnaliser le préfixe des fichiers générés ;
- de conserver la structure de dossiers au sein des archives.

Chaque export crée une ou plusieurs archives ZIP téléchargeables depuis l’interface. Les fichiers générés sont stockés dans `wp-content/uploads/real-media-export/` et sont automatiquement nettoyés après un délai configurable (par défaut 24 heures).
