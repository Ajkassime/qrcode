# RAPPORT DE MISSION CODEX — AUDIT, GÉOLOCALISATION MONDIALE & SÉCURISATION

- **Dépôt :** `https://github.com/Ajkassime/qrcode.git`
- **Pull Request :** [#1](https://github.com/Ajkassime/qrcode/pull/1)
- **Branche source :** `main`
- **Branche de travail :** `codex/audit-cleanup-qrcode`
- **Suite de tests :** **197 / 197 assertions réussies** (0 échec)

---

## 1. SITUATION TECHNIQUE & TRAITEMENT DES EXIGENCES D'AUDIT

### Point 1 : Configuration unifiée, gestion des proxys et support de GEOSECONDARY_URL
- **Variables d'environnement prises en charge et alignées :**
  - `REVERSE_GEOCODER_ENABLED=true` : activation explicite du géocodage inverse GPS.
  - `REVERSE_GEOCODER_PROVIDER` : choix du prestataire (`opencage`, `bigdatacloud`, `nominatim`, `google`, `mapbox`).
  - `REVERSE_GEOCODER_API_KEY` : clé d'authentification du prestataire.
  - `REVERSE_GEOCODER_MAX_RPM` : plafond de requêtes par minute (remplace/complète l'ancienne variable `RPM`).
  - `REVERSE_GEOCODER_DAILY_QUOTA` : quota journalier d'appels autorisés.
  - `TRUSTED_PROXY_IPS` : liste des adresses IP ou blocs CIDR de mandataires de confiance (Nginx, HAProxy, AWS ALB).
  - `TRUST_CLOUDFLARE_PROXY=true` : à activer **uniquement** si le serveur est hébergé derrière le réseau Cloudflare afin de valider les en-têtes `CF-Connecting-IP`.
- **Implémentation de GEOSECONDARY_URL :**
  - Support effectif dans [`app/helpers/GeoLocation.php`](file:///Users/ajkassime/Downloads/qr/app/helpers/GeoLocation.php) de `GEOSECONDARY_URL` pour les points de terminaison personnalisés ou internes.
  - Interpolation sécurisée des paramètres `{ip}` et `{key}` (`str_replace(['{ip}', '{key}'], [urlencode($ip), urlencode($key)], $urlTemplate)`).

### Point 2 : Sécurité des quotas en mode Fail-Closed (`GeoLocation::mutateSharedQuotaState`)
- **Problème résolu :** Si le fichier partagé d'état des quotas était inaccessible (permissions, disque plein) ou si l'acquisition du verrou exclusif `flock(LOCK_EX)` échouait, l'ancien comportement exécutait le mutateur en mémoire sans persistance, ce qui risquait de laisser passer des appels externes non comptabilisés.
- **Correction apportée :**
  - [`GeoLocation::mutateSharedQuotaState()`](file:///Users/ajkassime/Downloads/qr/app/helpers/GeoLocation.php) adopte désormais une politique stricte de **refus par défaut (Fail-Closed)** : en cas d'impossibilité d'ouvrir le fichier ou de poser le verrou, la méthode retourne `null`.
  - La méthode de résolution intercepte ce cas et bloque immédiatement tout appel HTTP externe, retournant `missing_reason = 'quota_storage_inaccessible'` et `location_level = 'unknown'`.
  - Un test unitaire automatisé dédié dans [`tests/GeoLocationTest.php`](file:///Users/ajkassime/Downloads/qr/tests/GeoLocationTest.php) prouve formellement qu'aucun appel HTTP externe n'est émis lorsque le stockage partagé est inaccessible.

### Point 3 : Transparence de la confidentialité et conformité des données transmises
- **Fichiers :** [`storage/themes/default/gates/consent.php`](file:///Users/ajkassime/Downloads/qr/storage/themes/default/gates/consent.php) et [`app/helpers/Gate.php`](file:///Users/ajkassime/Downloads/qr/app/helpers/Gate.php)
- **Correction apportée :**
  - Suppression de toute affirmation inexacte selon laquelle les coordonnées transmises seraient « anonymisées ».
  - Information transparente du visiteur : les coordonnées GPS précises (latitude, longitude, rayon d'incertitude) sont transmises au prestataire externe sélectionné (ex. OpenCage, Google, Mapbox, Nominatim, BigDataCloud) pour identifier les entités administratives correspondantes, et sont soumises à la politique de confidentialité de ce prestataire.
  - Clarification distincte : notre serveur ne stocke **jamais** de coordonnées GPS brutes (latitude/longitude) dans sa base de données MySQL. Seules les entités administratives résolues (pays, région, ville) et la précision approximative sont enregistrées dans la table `stats`.
  - Maintien du bouton « Continuer sans partager » : la redirection vers le lien cible s'effectue immédiatement sans aucune transmission GPS.

### Point 4 : Distinction entre codes régionaux internes et codes ISO 3166-2 officiels
- **Fichiers :** [`storage/app/geo_boundaries.json`](file:///Users/ajkassime/Downloads/qr/storage/app/geo_boundaries.json) et [`app/helpers/GeoLocation.php`](file:///Users/ajkassime/Downloads/qr/app/helpers/GeoLocation.php)
- **Distinction formelle documentée :**
  - **Codes officiels ISO 3166-2:MG :** désignent historiquement les **6 provinces** de Madagascar (`MG-T` Antananarivo, `MG-F` Fianarantsoa, `MG-D` Antsiranana, `MG-M` Mahajanga, `MG-A` Toamasina, `MG-U` Toliara).
  - **Codes régionaux internes INSTAT :** désignent les **23 régions administratives actuelles** de Madagascar (`MG-ANA` pour Analamanga, `MG-VAK`, `MG-ITA`, `MG-BON`, `MG-VAT` pour Vatovavy, `MG-FIT` pour Fitovinany, etc.). Ces identifiants internes préfixés sont utilisés par l'application pour lever toute ambiguïté et éviter les collisions entre régions issues d'une même ancienne province.
  - La section `code_standard_classification` dans `geo_boundaries.json` formalise cette distinction.

### Point 5 : Accessibilité de la configuration sur PHP-FPM
- **Problème identifié :** Sous PHP-FPM, la directive par défaut `clear_env = yes` dans la configuration du pool (`www.conf`) supprime les variables d'environnement système du processus travailleur. De plus, PHP ne lit pas nativement les fichiers `.env` sans bibliothèque dédiée.
- **Correction apportée :**
  - Création de la méthode [`GeoLocation::getEnv()`](file:///Users/ajkassime/Downloads/qr/app/helpers/GeoLocation.php) et du chargeur [`GeoLocation::loadEnvFile()`](file:///Users/ajkassime/Downloads/qr/app/helpers/GeoLocation.php).
  - La méthode inspecte successivement `$_ENV`, `$_SERVER` (alimenté par `fastcgi_param` sous Nginx ou `SetEnv` sous Apache), `getenv()`, et lit automatiquement le fichier `.env` à la racine s'il existe.
  - Les fichiers de configuration (`app/config/app.php`) utilisent systématiquement `\Helpers\GeoLocation::getEnv()`.
  - Un test unitaire dédié valide le bon fonctionnement de ce mécanisme.

### Point 6 : Procédure de test et distinction simulation vs réel
- **Script dédié :** [`scripts/test-worldwide-geocoder.php`](file:///Users/ajkassime/Downloads/qr/scripts/test-worldwide-geocoder.php)
- **Distinction méthodologique claire :**
  1. **Mode hors-ligne / Références indépendantes (exécuté en CI) :**
     - Exécution sur des jeux de données de référence vérifiés sans dépendance réseau externe.
     - Validation de 5 continents (Genève, Tokyo, Sydney, New York, São Paulo, Mananjary, zone rurale de Vatovavy).
     - Vérification de la gestion des erreurs réseau (panne simulée).
     - Vérification du respect des quotas partagés (dépassement simulé).
     - Vérification de la sécurité fail-closed (stockage inaccessible).
     - Vérification de la préservation de la redirection WhatsApp.
  2. **Mode réseau réel (`--live`) pour l'exploitant en production :**
     - Commande : `php scripts/test-worldwide-geocoder.php --live --provider=opencage --api-key=VOTRE_CLE`
     - Envoie de véritables requêtes HTTPS aux serveurs du prestataire sélectionné et affiche la charge utile JSON reçue ainsi que le temps de réponse.

---

## 2. RÉSULTATS DES TESTS AUTOMATISÉS

- **Script d'exécution :** `php tests/run_tests.php`
- **Total des assertions exécutées :** **197**
- **Assertions réussies :** **197**
- **Assertions échouées :** **0**
- **Vérification syntaxique (`php -l`) :** 100 % des fichiers validés sans erreur.
- **Environnements validés :** PHP 8.1, PHP 8.2, PHP 8.3 (Matrice GitHub Actions).

---

## 3. CE QUI EST IMPLÉMENTÉ VS CE QUI DÉPEND DE LA PRODUCTION

| Composant | Implémenté et testé dans le code | Dépendance de déploiement en production |
| :--- | :--- | :--- |
| **MaxMind GeoLite2-City** | Base chargée localement (`storage/app/GeoLite2-City.mmdb`) | Tâche cron de mise à jour avec `MAXMIND_LICENSE_KEY` |
| **Géocodage inverse GPS** | 5 adaptateurs intégrés (OpenCage, BigDataCloud, Nominatim, Google, Mapbox) | Renseigner `REVERSE_GEOCODER_ENABLED=true` et `REVERSE_GEOCODER_API_KEY` dans `.env` ou `www.conf` |
| **Sécurité des quotas** | Verrouillage atomique et politique fail-closed | S'assurer que `storage/cache/geolocation/` est accessible en écriture par l'utilisateur PHP-FPM (`www-data`) |
| **Mandataires inverses** | Filtrage `TRUSTED_PROXY_IPS` et support Cloudflare | Configurer `TRUSTED_PROXY_IPS` si utilisation d'un reverse proxy, et `TRUST_CLOUDFLARE_PROXY=true` si derrière Cloudflare |
| **Migration SQL `stats`** | Scripts CLI vérifiés (`scripts/migrate-geo-stats.php`) | Exécution manuelle par l'administrateur : `php scripts/migrate-geo-stats.php --apply --confirm` |

---

## 4. LIMITES CONNUES & COMPROMIS TECHNIQUES

1. **Adresses IP mobiles (CGNAT) :**
   - Sur les réseaux mobiles 3G/4G/5G (notamment à Madagascar avec Orange, Telma, Airtel), les adresses IP publiques sont mutualisées sans point de présence urbain précis. La base MaxMind résout alors le pays (`Madagascar`), mais pas la ville (`city = null`). Il s'agit d'un comportement attendu de protection des données : l'application n'invente jamais de ville fictive.
2. **Quotas et facturation des prestataires externes :**
   - L'utilisation d'un géocodeur inverse externe (OpenCage, Google, Mapbox) implique des coûts proportionnels au volume de scans avec consentement GPS. Le cache L2 partagé et les quotas configurables évitent les dépassements imprévus.
3. **Imprécision du signal GPS :**
   - En environnement clos ou sur ordinateur fixe, le navigateur peut transmettre une précision dégradée (> 5 km) ou non renseignée (`null`). Dans ce cas, la localisation est automatiquement plafonnée au niveau région ou pays.

---

## 5. INSTRUCTIONS DE DÉPLOIEMENT EN PRODUCTION

1. **Configuration PHP-FPM (`/etc/php/8.x/fpm/pool.d/www.conf`) :**
   Si vous n'utilisez pas de fichier `.env`, transmettez les variables directement au pool FPM :
   ```ini
   env[REVERSE_GEOCODER_ENABLED] = true
   env[REVERSE_GEOCODER_PROVIDER] = opencage
   env[REVERSE_GEOCODER_API_KEY] = votre_cle_api_secrete
   env[REVERSE_GEOCODER_MAX_RPM] = 60
   env[REVERSE_GEOCODER_DAILY_QUOTA] = 2500
   ```
   Ou assurez-vous que `clear_env = no` est défini si les variables sont injectées au niveau du service systemd.

2. **Alternative fichier `.env` (à la racine du projet) :**
   ```dotenv
   REVERSE_GEOCODER_ENABLED=true
   REVERSE_GEOCODER_PROVIDER=opencage
   REVERSE_GEOCODER_API_KEY=votre_cle_api_secrete
   REVERSE_GEOCODER_MAX_RPM=60
   REVERSE_GEOCODER_DAILY_QUOTA=2500

   # Activer uniquement si l'infrastructure est derrière Cloudflare
   TRUST_CLOUDFLARE_PROXY=false
   ```

3. **Vérification des permissions du dossier de cache :**
   ```bash
   chown -R www-data:www-data storage/cache/geolocation
   chmod -R 0775 storage/cache/geolocation
   ```

4. **Test en direct avec le fournisseur configuré :**
   ```bash
   php scripts/test-worldwide-geocoder.php --live --provider=opencage --api-key=votre_cle_api_secrete
   ```

5. **Application de la migration de structure :**
   ```bash
   php scripts/migrate-geo-stats.php --status
   php scripts/migrate-geo-stats.php --apply --confirm
   ```
