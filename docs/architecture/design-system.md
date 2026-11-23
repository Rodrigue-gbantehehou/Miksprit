# Miksprit — règles d’interface

Cette règle s’applique à toutes les interfaces de `landing/`, `client/` et `admin/`, y compris le portail captif, les pages de compte et les composants partagés. Chaque contributeur, agent compris, doit la lire et la suivre avant de modifier une interface.

Dans `client/` et `admin/`, réutiliser les primitives versionnées de `components/ui/`, construites selon les conventions shadcn/ui. Dans `landing/`, composer les composants déjà présents au lieu de créer un système parallèle.

## Identité visuelle

- Produit réseau et paiement destiné à des opérateurs : interface calme, précise et dense en information utile.
- Palette commune : orange Miksprit `#EA8B3B`, orange foncé `#984B0E` pour les textes et états actifs, fond `#f7f9f7`, surface blanche, texte `#172d27`, texte secondaire `#7c8b85`, bordure `#e7ece9`. Garder le vert pour les états de succès.
- Couleur réservée aux états : rouge pour échec/danger, ambre pour attente, vert pour succès observé. La couleur n’est jamais le seul indicateur d’état.
- Typographie sans-serif système, titres à contraste net, textes de contrôle lisibles. Éviter les microtextes pour les actions et informations nécessaires.
- Espacement fondé sur une échelle de 4 px; rayon commun `0.65rem`; bordures fines et ombres rares.

## Comportement visuel

- Pas de dégradés décoratifs, blobs, halos, glassmorphism, fausses notifications, chiffres de démo, graphiques inventés, images d’illustration génériques ou cartes KPI sans données réelles.
- Chaque écran doit avoir une identité liée à son usage et au rôle de son utilisateur. Ne pas appliquer automatiquement une structure de landing page, une grande accroche, une grille de cartes à icônes ou un formulaire en carte à toutes les pages.
- Éviter les motifs de template répétés : titres en capitales espacées sans fonction, pastilles décoratives, icônes dans des carrés colorés, ombres et coins arrondis ajoutés par défaut. Un effet visuel doit servir la compréhension ou l’action.
- Les pages marketing, comptes, portail captif, gestionnaire et administration ont des objectifs différents. La vitrine explique le bénéfice et mène à l’inscription; le portail met les tickets et leur prix en avant; les pages de compte se concentrent sur leur formulaire; les consoles privilégient les tâches, données et états opérationnels.
- Une page répond d’abord à ces questions : qui l’utilise, pour faire quoi, quelle information doit être vue en premier, quelle est l’action principale, et quels états doivent être représentés.
- Chaque page expose une action principale au plus. Navigation, filtres et actions gardent les mêmes tailles, focus clavier et états disabled/loading.
- Préférer tableaux lisibles pour les listes métier et une seule surface structurante par section; utiliser les cartes seulement pour regrouper des éléments qui vont ensemble.
- Chaque écran de données prévoit chargement, vide, erreur/indisponibilité et contenu réel. Une API déconnectée est nommée comme telle; aucun état produit n’est inféré côté navigateur.
- Les icônes proviennent de Lucide, utilisent une taille cohérente et accompagnent un libellé accessible; aucune icône emoji décorative.
- Responsive d’abord pour le contenu : tables défilables, navigation mobile accessible, cibles tactiles confortables et focus visible.

## Revue obligatoire des changements d’interface

Avant de livrer un changement visuel :

1. Examiner la page complète et les composants qu’elle partage avec les autres écrans.
2. Définir la priorité de contenu et l’action principale à partir de la tâche réelle de l’utilisateur.
3. Vérifier que les textes, valeurs, visuels et états reflètent le produit réel, sans contenu inventé.
4. Comparer avec les autres pages du même parcours pour repérer les répétitions inutiles et les différences incohérentes.
5. Vérifier le rendu sur grand et petit écran, le focus clavier, les erreurs, le chargement et l’état vide. Si une vérification visuelle n’a pas été possible, le dire clairement au lieu de la présenter comme faite.

Le changement n’est prêt que si la page est compréhensible au premier regard, adaptée à son usage, cohérente avec le système Miksprit et utilisable sur mobile.

## Composants

Réutiliser les primitives `Button`, `Badge`, `Card` et `Separator` dans `components/ui/`. Étendre leurs variantes au lieu de créer une nouvelle classe locale pour chaque écran. Les composants de domaine peuvent les composer et définir leur sémantique, sans redéfinir les tokens globaux.

Toute divergence de palette, de rayon ou de comportement partagé nécessite une mise à jour de cette règle et des deux feuilles globales au même moment.
