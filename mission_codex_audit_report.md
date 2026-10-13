# RAPPORT DE MISSION CODEX — AUDIT, GÉOLOCALISATION MONDIALE & SÉCURISATION

- **Dépôt :** `https://github.com/Ajkassime/qrcode.git`
- **Pull Request :** [#1](https://github.com/Ajkassime/qrcode/pull/1)
- **Branche source :** `main`
- **Branche de travail :** `codex/audit-cleanup-qrcode`
- **Suite de tests :** **253 / 253 assertions réussies** (0 échec)

---

## 1. SITUATION TECHNIQUE & TRAITEMENT DES EXIGENCES D'AUDIT

### Point 1 : Configuration unifiée, gestion des proxys et support de GEOSECONDARY_URL
- **Variables d'environnement prises en charge et alignées :**
  - `REVERSE_GEOCODER_ENABLED=true` : activation explicite du géocodage inverse GPS mondial.
  - `REVERSE_GEOCODER_PROVIDER` : choix du prestataire (`opencage`, `bigdatacloud`, `nominatim`, `google`, `mapbox`).
  - `REVERSE_GEOCODER_API_KEY` : clé d'authentification du prestataire (chargée via environnement/configuration, jamais en paramètre CLI).
  - `REVERSE_GEOCODER_MAX_RPM` : plafond de requêtes par minute (remplace/complète l'ancienne variable `RPM`).
  - `REVERSE_GEOCODER_DAILY_QUOTA` : quota journalier d'appels autorisés.
  - `TRUSTED_PROXY_IPS` : liste des adresses IP ou blocs CIDR de mandataires de confiance (Nginx, HAProxy, AWS ALB).
  - `TRUST_CLOUDFLARE_PROXY=true` : à activer **uniquement** si le serveur est hébergé derrière le réseau Cloudflare afin de valider les en-têtes `CF-Connecting-IP`.
- **Implémentation de GEOSECONDARY_URL :**
  - Support effectif dans [`app/helpers/GeoLocation.php`](app/helpers/GeoLocation.php) de `GEOSECONDARY_URL` pour les points de terminaison personnalisés ou internes.
  - Interpolation sécurisée des paramètres `{ip}` et `{key}` (`str_replace(['{ip}', '{key}'], [urlencode($ip), urlencode($key)], $urlTemplate)`).

### Point 2 : Persistance transactionnelle et atomique des quotas résistante aux interruptions (`GeoLocation::mutateSharedQuotaState`)
- **Problème résolu :** L'ancien enchaînement `ftruncate($fp, 0)` puis `fwrite()` présentait une fenêtre de vulnérabilité où une interruption brutale du processus PHP-FPM pouvait laisser un fichier `ratelimit_state.json` vide (0 octet). Ce fichier vide risquait alors d'être interprété comme un état initial lors de la lecture suivante, provoquant une remise à zéro involontaire des compteurs de quotas.
- **Architecture transactionnelle et atomique mise en place :**
  - **Fichier de verrouillage dédié :** [`GeoLocation::mutateSharedQuotaState()`](app/helpers/GeoLocation.php) utilise désormais un fichier de verrouillage séparé `ratelimit.lock` avec `flock(LOCK_EX)`. Cela permet de synchroniser tous les processus PHP-FPM concurrents sans être perturbé par le remplacement atomique du fichier de données.
  - **Distinction explicite création vs corruption :**
    - Si `ratelimit_state.json` **n'existe pas** (`!file_exists`), il s'agit d'une création initiale légitime : l'état est initialisé avec les valeurs par défaut.
    - Si le fichier **existe déjà mais est vide** (`filesize === 0` ou contenu vide), il s'agit d'une anomalie/interruption : la méthode applique immédiatement le mode **Fail-Closed** (`return null`) sans jamais remettre les compteurs à zéro.
    - Si le fichier **contient du JSON invalide ou corrompu**, la méthode applique également le mode **Fail-Closed** (`return null`).
  - **Écriture atomique par fichier temporaire et renommage POSIX :**
    - Le nouvel état est d'abord écrit intégralement dans un fichier temporaire unique (`ratelimit_state.tmp.<random>`), avec vérification stricte du nombre d'octets écrits et appel explicite à `fflush()`.
    - Le remplacement s'effectue ensuite via un appel atomique `rename()`. Ainsi, `ratelimit_state.json` n'est jamais tronqué sur place et ne traverse aucun état intermédiaire vide ou incomplet.
  - **Preuve par tests réels avec octets tronqués sur disque :**
    - Un test unitaire injecte de véritables octets JSON partiels sur le disque (`substr($json, 0, 15)`) et prouve que le système détecte la corruption, refuse tout appel externe non comptabilisé (`missing_reason = 'quota_storage_inaccessible'`), et maintient ce verrouillage protecteur sur les requêtes suivantes.

### Point 3 : Transparence de la confidentialité et conformité des données transmises
- **Fichiers :** [`storage/themes/default/gates/consent.php`](storage/themes/default/gates/consent.php) et [`app/helpers/Gate.php`](app/helpers/Gate.php)
- **Correction apportée :**
  - Suppression de toute affirmation inexacte selon laquelle les coordonnées transmises seraient « anonymisées ».
  - Information transparente du visiteur : les coordonnées GPS précises (latitude, longitude, rayon d'incertitude) sont transmises au prestataire externe sélectionné (ex. OpenCage, Google, Mapbox, Nominatim, BigDataCloud) pour identifier les entités administratives correspondantes, et sont soumises à la politique de confidentialité de ce prestataire.
  - Clarification distincte : notre serveur ne stocke **jamais** de coordonnées GPS brutes (latitude/longitude) dans sa base de données MySQL. Seules les entités administratives résolues (pays, région administrative, ville) et la précision approximative sont enregistrées dans la table `stats`.
  - Maintien du bouton « Continuer sans partager » : la redirection vers le lien cible s'effectue immédiatement sans aucune transmission GPS ni enregistrement de coordonnées.

### Point 4 : Distinction entre identifiants applicatifs internes et codes ISO 3166-2 officiels
- **Fichiers :** [`storage/app/geo_boundaries.json`](storage/app/geo_boundaries.json) et [`app/helpers/GeoLocation.php`](app/helpers/GeoLocation.php)
- **Distinction formelle documentée :**
  - **Codes officiels ISO 3166-2:MG :** désignent historiquement les **6 provinces** de Madagascar (`MG-T` Antananarivo, `MG-F` Fianarantsoa, `MG-D` Antsiranana, `MG-M` Mahajanga, `MG-A` Toamasina, `MG-U` Toliara).
  - **Identifiants applicatifs internes :** désignent les **23 régions administratives actuelles** de Madagascar (`MG-ANA` pour Analamanga, `MG-VAK`, `MG-ITA`, `MG-BON`, `MG-VAT` pour Vatovavy, `MG-FIT` pour Fitovinany, etc.). Ces codes préfixés sont des identifiants applicatifs internes propres à la plateforme et ne doivent pas être présentés comme une norme officielle ISO ou INSTAT. Ils sont exclusivement utilisés par l'application pour lever toute ambiguïté et éviter les collisions entre régions issues d'une même ancienne province.
  - La section `code_standard_classification` dans `geo_boundaries.json` formalise cette distinction.

### Point 5 : Protection des secrets et accessibilité de la configuration sous PHP-FPM
- **Protection des secrets :**
  - Ajout des règles `/.env` et `/.env.*` au fichier `.gitignore` afin de garantir qu'aucun fichier d'environnement réel contenant des secrets ne soit accidentellement indexé ou commité.
  - Fourniture d'un gabarit neutre `.env.example` sans clé d'API réelle, documentant les variables nécessaires.
  - Suppression de l'option `--api-key=SECRET` en ligne de commande dans `scripts/test-worldwide-geocoder.php` afin d'éviter toute exposition de secrets dans la table des processus (`ps aux`, `/proc`). Les clés sont exclusivement lues depuis l'environnement ou la configuration et systématiquement masquées dans les sorties de diagnostic (`abc*************xyz`).
- **Accessibilité de la configuration sous PHP-FPM :**
  - Création de la méthode [`GeoLocation::getEnv()`](app/helpers/GeoLocation.php) et du chargeur [`GeoLocation::loadEnvFile()`](app/helpers/GeoLocation.php).
  - La méthode inspecte successivement `$_ENV`, `$_SERVER` (alimenté par `fastcgi_param` sous Nginx ou `SetEnv` sous Apache), `getenv()`, et lit automatiquement le fichier `.env` à la racine s'il existe.
  - Les fichiers de configuration (`app/config/app.php`) utilisent systématiquement `\Helpers\GeoLocation::getEnv()`.
  - Un test unitaire dédié valide le bon fonctionnement de ce mécanisme.

### Point 6 : Qualification rigoureuse des tests, intégration HTTP et cycle de vie
- **Classification claire des tests pour l'audit et la documentation :**
  1. **Tests unitaires et simulations contrôlées :**
     - Couvrent les composants isolés : détection IP, protection anti-usurpation d'en-têtes de proxy, validation polygonale, hiérarchie administrative, calcul des métriques qualité, et résilience aux pannes.
     - Le script [`scripts/test-worldwide-geocoder.php`](scripts/test-worldwide-geocoder.php) opère en mode de simulation contrôlée (jeux de coordonnées de référence hors-ligne) avec isolation stricte du cache temporaire (`codex_geotest_*`) garantissant l'intégrité absolue des quotas de production. Ses tests portent désormais la qualification explicite `SIMULATED_INTEGRATION_TEST`.
     - Les codes de sortie du script CLI sont strictement vérifiés : `$code === 0` en cas de réussite, `$code !== 0` (ex. code 1) en cas de configuration manquante ou d'échec.
  2. **Tests d'intégration HTTP isolés sur serveur web réel :**
     - Méthode dédiée [`GeoLocationTest::testIsolatedHttpServerConsentAndWhatsAppLifecycle()`](tests/GeoLocationTest.php).
     - Démarre un serveur web éphémère (`php -S 127.0.0.1:<port>`) avec base de données SQLite isolée et effectue de véritables requêtes HTTP avec cURL et User-Agent mobile (évitant les blocages de robots).
     - Valide l'intégralité du cycle de vie HTTP réel :
       - **Requête 1 (Scan QR) :** affichage de la page de consentement, présence du jeton cryptographique HMAC-SHA256 (64 caractères hexadécimaux lié au scan), présence du jeton CSRF et du lien d'opt-out (« Continuer sans partager ») pointant vers l'URL WhatsApp cible.
       - **Requête 2 (Opt-out ou Soumission POST) :** envoi effectif de la requête POST au point de terminaison `/geo/consent/<alias>` avec cookies de session.
       - **Résultat :** redirection HTTP 200/302 vers l'URL WhatsApp cible (`https://wa.me/261340000000...`), mise à jour des statistiques administratives (Mananjary, Vatovavy, Madagascar) et absence stricte de toute coordonnée GPS brute (latitude/longitude) dans la table SQLite.
  3. **Essais en conditions réelles (`--live`) pour l'exploitant :**
     - Nécessitent une clé d'API valide auprès d'un fournisseur pris en charge (ex. OpenCage).
     - Commande : `REVERSE_GEOCODER_API_KEY=votre_cle php scripts/test-worldwide-geocoder.php --live --provider=opencage`
      - Envoie de véritables requêtes HTTPS aux serveurs du prestataire sélectionné et affiche la charge utile JSON reçue ainsi que le temps de réponse.

### Point 7 : Conversion des actions sensibles d'état exposées en GET vers POST avec protection CSRF
- **Routes sécurisées :**
  - `/teams/toggle/{id}` (`team.toggle`) : activation / désactivation de membre d'équipe.
  - `/plans/{id}/toggle` (`admin.plans.toggle`) : activation / désactivation d'un forfait.
  - `/links/{id}/disable` (`admin.links.disable`) : désactivation d'un lien raccourci / QR code.
  - `/links/{id}/approve` (`admin.links.approve`) : approbation d'un lien en attente.
  - `/users/{id}/ban` (`admin.users.ban`) : bannissement d'un utilisateur.
  - `/domains/{id}/disable` (`admin.domains.disable`) : désactivation d'un domaine personnalisé.
  - `/domains/{id}/activate` (`admin.domains.activate`) : activation d'un domaine personnalisé.
- **Architecture de sécurité mise en place :**
  - **Enregistrement de routage :** Ces actions modificatrices d'état sont désormais enregistrées exclusivement en `Gem::post(...)->middleware('CSRF')`. Toute tentative d'accès via la méthode `GET` est formellement rejetée avec le code HTTP 405 (`METHOD_NOT_ALLOWED`).
  - **Protection CSRF unifiée :** Le middleware `CSRF` valide systématiquement le jeton de session anti-falsification.
  - **Composants d'interface (Vues) :** Les boutons d'action dans les vues d'administration et d'équipe (`storage/themes/default/teams/index.php`, `admin/plans/index.php`, `admin/links/view.php`, `admin/partials/links.php`, `admin/domains/index.php`, `admin/index.php`, `admin/search.php`, `admin/users/index.php`) ont été convertis avec les attributs `data-trigger="post" data-token="<?php echo csrf_token() ?>"`.
  - **Gestionnaire JavaScript unifié :** Ajout de l'écouteur `[data-trigger=post]` dans `public/static/custom.js` et `public/static/custom.min.js` (en plus de `server.js`), assurant que les requêtes POST asynchrones avec jeton CSRF soient déclenchées de manière homogène sur toutes les interfaces (y compris les vues d'administration qui ne chargent que `custom.min.js`).
  - **Préservation stricte des routes GET légitimes :**
    - Liens d'invitation et d'activation basés sur jeton sécurisé : `/teams/{token}/accept` et `/user/{id}/verify/{token}` conservent intentionnellement la méthode GET pour permettre aux utilisateurs d'ouvrir directement le lien depuis leur messagerie électronique.
    - Routes de consultation : `/links/{id}/view`, `/plans`, téléchargements, prévisualisations et redirections restent en GET sans interférence CSRF.
  - **Tests automatisés :** 13 actions sensibles testées pour le rejet GET (405), l'acceptation POST, et l'exigence du middleware CSRF, accompagnées de tests confirmant la persistance des accès GET légitimes.

### Point 8 : Protection du verrou de synchronisation (`ratelimit.lock`) et isolation des quotas lors des nettoyages concurrents
- **Problème résolu :** Dans un environnement d'exécution multi-processus POSIX (ex. PHP-FPM), supprimer un fichier de verrouillage (`unlink('ratelimit.lock')`) pendant qu'un autre travailleur PHP détient un verrou actif `flock(LOCK_EX)` crée une scission d'inœuds (*split-brain*). Un processus concurrent entrant recrée alors un nouvel inœud portant le même nom, et les deux processus s'exécutent simultanément en croyant détenir le verrou exclusif. De plus, une purge périodique du cache de réponses ne doit jamais effacer le fichier d'état des quotas réels (`ratelimit_state.json`).
- **Architecture de protection :**
  - **Préservation par défaut des quotas :** `GeoLocation::clearCache(bool $clearSharedFiles = true, bool $purgeQuotaState = false)` et `GeoLocation::purgeSharedCacheFiles(bool $purgeQuotaState = false)` n'effacent jamais `ratelimit_state.json` lors des opérations de maintenance de cache ordinaires.
  - **Vérification non-bloquante avant suppression du verrou :** Lors d'une réinitialisation dure explicite des quotas (`$purgeQuotaState = true`), `ratelimit.lock` n'est supprimé que si une tentative d'acquisition immédiate et non-bloquante (`flock($lockFp, LOCK_EX | LOCK_NB)`) aboutit. Si un travailleur concurrent détient le verrou, l'effacement est annulé et l'inœud actif est intégralement préservé.
  - **Test unitaire de concurrence :** `testConcurrentCacheCleaningProtectsLockAndQuotas` simule l'acquisition concurrente d'un verrou par un processus tiers (Worker A), vérifie que l'inœud de `ratelimit.lock` reste inchangé pendant les nettoyages, et valide que les fichiers ne sont supprimés qu'une fois le verrou libéré.

---

## 2. RÉSULTATS DES TESTS AUTOMATISÉS

- **Script d'exécution :** `php tests/run_tests.php`
- **Total des assertions exécutées :** **253**
- **Assertions réussies :** **253**
- **Assertions échouées :** **0**
- **Vérification syntaxique (`php -l`) :** 100 % des fichiers validés sans erreur.
- **Environnements validés :** PHP 8.1, PHP 8.2, PHP 8.3 (Matrice GitHub Actions).

---

## 3. CE QUI EST IMPLÉMENTÉ VS CE QUI DÉPEND DE LA PRODUCTION

| Composant | Implémenté et testé dans le code | Dépendance de déploiement en production |
| :--- | :--- | :--- |
| **MaxMind GeoLite2-City** | Base chargée localement (`storage/app/GeoLite2-City.mmdb`) | Tâche cron de mise à jour avec `MAXMIND_LICENSE_KEY` |
| **Géocodage inverse GPS** | 5 adaptateurs intégrés (OpenCage, BigDataCloud, Nominatim, Google, Mapbox) | Renseigner `REVERSE_GEOCODER_ENABLED=true` et `REVERSE_GEOCODER_API_KEY` dans `.env` ou `www.conf` |
| **Sécurité des quotas & verrous** | Verrouillage dédié `ratelimit.lock`, écriture atomique tempfile + rename, mode fail-closed, protection d'inœud non-bloquante contre le split-brain | S'assurer que `storage/cache/geolocation/` est accessible en lecture/écriture par le pool PHP-FPM (`www-data`) |
| **Protection CSRF & Actions sensibles** | Conversion de 7 routes critiques en POST + CSRF, rejet GET (405), gestionnaire JS unifié | Aucune dépendance externe, actif immédiatement |
| **Cycle de vie HTTP** | Test d'intégration complet validant le flux QR -> Consentement -> Redirection WhatsApp | Aucun composant tiers nécessaire |
| **Protection des secrets** | `.gitignore` durci, masquage de clé, lecture d'environnement sécurisée | Définir des permissions `chmod 600 .env` sur le serveur de production |
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
   Copiez `.env.example` vers `.env` et ajustez les permissions :
   ```bash
   cp .env.example .env
   chmod 600 .env
   chown www-data:www-data .env
   ```
   Contenu de référence dans `.env` :
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
   REVERSE_GEOCODER_API_KEY=votre_cle_api_secrete php scripts/test-worldwide-geocoder.php --live --provider=opencage
   ```

5. **Application de la migration de structure :**
   ```bash
   php scripts/migrate-geo-stats.php --status
   php scripts/migrate-geo-stats.php --apply --confirm
   ```
