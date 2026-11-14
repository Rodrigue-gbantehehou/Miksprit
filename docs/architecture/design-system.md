# Miksprit — règles d’interface

Cette règle s’applique à `client/` et `admin/`. Toute nouvelle interface utilise les composants réutilisables de `components/ui/`, construits selon les conventions shadcn/ui (Radix pour les primitives interactives, CVA pour les variantes et `cn()` pour composer les classes). Le CLI shadcn n’est pas requis : les composants sont versionnés dans le dépôt et doivent rester lisibles et modifiables.

## Identité visuelle

- Produit réseau et paiement destiné à des opérateurs : interface calme, précise et dense en information utile.
- Palette commune : vert Miksprit `#176d55`, vert sombre `#105743`, fond `#f7f9f7`, surface blanche, texte `#172d27`, texte secondaire `#7c8b85`, bordure `#e7ece9`.
- Couleur réservée aux états : rouge pour échec/danger, ambre pour attente, vert pour succès observé. La couleur n’est jamais le seul indicateur d’état.
- Typographie sans-serif système, titres à contraste net, textes de contrôle lisibles. Éviter les microtextes pour les actions et informations nécessaires.
- Espacement fondé sur une échelle de 4 px; rayon commun `0.65rem`; bordures fines et ombres rares.

## Comportement visuel

- Pas de dégradés décoratifs, blobs, halos, glassmorphism, fausses notifications, chiffres de démo, graphiques inventés, images d’illustration génériques ou cartes KPI sans données réelles.
- Chaque page expose une action principale au plus. Navigation, filtres et actions gardent les mêmes tailles, focus clavier et états disabled/loading.
- Préférer tableaux lisibles pour les listes métier et une seule surface structurante par section; utiliser les cartes seulement pour regrouper des éléments qui vont ensemble.
- Chaque écran de données prévoit chargement, vide, erreur/indisponibilité et contenu réel. Une API déconnectée est nommée comme telle; aucun état produit n’est inféré côté navigateur.
- Les icônes proviennent de Lucide, utilisent une taille cohérente et accompagnent un libellé accessible; aucune icône emoji décorative.
- Responsive d’abord pour le contenu : tables défilables, navigation mobile accessible, cibles tactiles confortables et focus visible.

## Composants

Réutiliser les primitives `Button`, `Badge`, `Card` et `Separator` dans `components/ui/`. Étendre leurs variantes au lieu de créer une nouvelle classe locale pour chaque écran. Les composants de domaine peuvent les composer et définir leur sémantique, sans redéfinir les tokens globaux.

Toute divergence de palette, de rayon ou de comportement partagé nécessite une mise à jour de cette règle et des deux feuilles globales au même moment.
