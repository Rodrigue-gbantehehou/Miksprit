# Miksprit — contrats et prochaines étapes

## Contrats communs

- Backend Symfony, PHP 8.4+, Doctrine et MySQL.
- Un fichier par entité dans `api/src/Entity`; services sous `api/src/Service/<Domaine>`; événements sous `api/src/Event`; repositories directement dans `api/src/Repository`.
- `client/` contient le portail captif et la console propriétaire; `admin/` le panneau global; `landing/` la vitrine et la connexion centrale.
- L'API applique JWT HttpOnly, CSRF signé, contrôle des rôles et isolation tenant côté serveur.
- Les intégrations de paiement et de routeur sont des adaptateurs sélectionnés par registre/tag. Aucun succès externe n'est simulé.

## Jalon paiement et accès

| Domaine | État |
|---|---|
| Checkout public | Commande/tentative idempotentes, forfait publié, redirection checkout hébergé et retour navigateur sans valeur probante |
| FedaPay | Adaptateur configurable, reprise token, webhook signé, lecture transaction serveur et confirmation XOF |
| Ledger/outbox | Écritures append-only et messages de provisioning créés transactionnellement |
| MikroTik | Driver enregistré, enrôlement et vérification avant activation, création/expiration de voucher avec limites du forfait |
| Portail captif | Achat, interrogation de statut et affichage des identifiants après provisioning |
| Extensibilité | Registres pour ajouter un fournisseur de paiement et un kit Wi-Fi par adaptateur |

## Contrats d'exploitation

- Les secrets FedaPay et routeur sont gardés dans `api/.env.local` en développement ou un secret manager en production.
- Le retour paiement est construit par `PAYMENT_RETURN_URL`; ne jamais accepter une URL arbitraire du client.
- Les routes propriétaire vérifient l'appartenance au tenant, même si `X-Tenant-ID` est fourni.
- RouterOS doit rester sur TLS avec vérification du certificat et de l'hôte. L'enrôlement n'ouvre aucune connexion sortante.
- Les états réseau ambigus restent en rapprochement manuel pour éviter doubles charges ou comptes hotspot dupliqués.

## Reste avant production

1. Renseigner les clés de l'environnement choisi, URL de retour réelle, clé de chiffrement et références secrètes routeur.
2. Examiner et appliquer les migrations dans la base cible; vérifier l'état de Doctrine.
3. Planifier les deux commandes worker/expiration, superviser leurs métriques et créer un processus d'intervention pour les cas ambigus.
4. Valider les parcours auth/mail dans un navigateur et le paiement → voucher → accès en sandbox puis sur un routeur de test.
5. Compléter gestion des membres et renouvellement JWT, CI, alertes, sauvegardes planifiées et exercice de restauration.
