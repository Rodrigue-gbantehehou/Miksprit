# Clé de chiffrement des credentials voucher

Le provisionneur chiffre username/password avant de les stocker dans la colonne `vouchers.encrypted_credential`. L'adaptateur utilise XChaCha20-Poly1305-IETF (AEAD) via sodium; chaque credential reçoit un nonce aléatoire et les données associées lient le ciphertext à la version de l'enveloppe.

La variable `VOUCHER_CREDENTIAL_ENCRYPTION_KEY` est obligatoire lorsque le chiffreur est instancié. Elle doit contenir en base64 exactement 32 octets aléatoires. Génération locale :

```powershell
php -r "echo base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)), PHP_EOL;"
```

Fournir sa valeur via un gestionnaire de secrets en production, ou dans `api/.env.local` ignoré par Git en développement. Ne jamais ajouter la sortie générée dans `.env`, les journaux ou le dépôt. Une valeur absente, invalide ou de mauvaise taille fait échouer la construction du chiffreur; aucune clé par défaut n'existe.

L'enveloppe stockée commence par `v1.`. La rotation de clé doit attendre une procédure de re-chiffrement ou un keyring versionné : remplacer directement la clé rend les credentials historiques indéchiffrables. Les credentials en clair sont transmis au routeur et ne sont jamais écrits dans la base.
