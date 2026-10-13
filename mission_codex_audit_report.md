# RAPPORT DE MISSION CODEX — AUDIT, GÉOLOCALISATION MONDIALE & SÉCURISATION

- **Dépôt :** `https://github.com/Ajkassime/qrcode.git`
- **Pull Request :** [#1](https://github.com/Ajkassime/qrcode/pull/1)
- **Branche source :** `main`
- **Branche de travail :** `codex/audit-cleanup-qrcode`
- **Dernier commit :** `400f873` (`docs(geo): add updated mission audit report to repository root`)
- **Statut CI GitHub Actions :** **193 / 193 assertions réussies** sur **PHP 8.1, 8.2 et 8.3** (Runs `38364994570` et `38364999784`)

---

## 1. SITUATION TECHNIQUE & TRAITEMENT DES 7 POINTS D'AUDIT

### Point 1 : Suppression des frontières rectangulaires synthétiques & Registre des 23 régions de Madagascar
- **Problème résolu :** `storage/app/geo_boundaries.json` contenait des boîtes englobantes rectangulaires à 4 points qui risquaient d'attribuer une ville ou une région de manière imprécise. De plus, plusieurs régions partageaient des codes historiques dupliqués (`MG-T`, `MG-F`).
- **Correction apportée :**
  - Toutes les boîtes rectangulaires ont été purgées. Le fichier est explicitement marqué `authoritative_polygons: false`.
  - La méthode `GeoLocation::isAuthoritativePolygon()` exige désormais au minimum 6 sommets géométriques distincts et rejette tout polygone à 4 sommets ou avec seulement 2 coordonnées de longitude/latitude distinctes.
  - Le registre de Madagascar recense l'ensemble des **23 régions administratives** avec **23 codes uniques normalisés** (ex. `MG-ANA` pour Analamanga, `MG-VAK`, `MG-ITA`, `MG-BON`, `MG-VAT` pour Vatovavy, `MG-FIT` pour Fitovinany, etc.), supprimant toute ambiguïté issue des anciens codes provinciaux.

### Point 2 : Priorité stricte des fournisseurs & Protection contre les fausses attributions
- **Problème résolu :** L'ancien moteur pouvait privilégier un polygone local approximatif avant d'interroger un fournisseur externe.
- **Correction apportée :**
  - Pour les coordonnées GPS issues du consentement, le fournisseur de **géocodage inverse externe est prioritaire** (`GeoLocation::resolveCoordinates()`).
  - Les limites locales ne sont consultées que si un polygone est explicitement certifié et vérifié géométriquement (`isAuthoritativePolygon() === true`).
  - En l'absence de ville résolue par le géocodeur externe ou en cas de zone rurale, l'application se replie proprement sur la région ou le pays, sans jamais promouvoir un hameau ou inventer un chef-lieu urbain.

### Point 3 : Prise en charge des 5 fournisseurs de géocodage inverse mondial
- **Adaptateurs intégrés :**
  - `opencage` : API OpenCage Data (support international, conventions `q={lat}+{lon}`)
  - `bigdatacloud` : API BigDataCloud Reverse Geocoding
  - `nominatim` : OpenStreetMap Nominatim avec User-Agent requis
  - `google` : Google Maps Geocoding API (`latlng={lat},{lon}`)
  - `mapbox` : Mapbox Geocoding v5 (conventions conformes `{lon},{lat}.json`)
- **Variables d'environnement prises en charge :**
  - `REVERSE_GEOCODER_PROVIDER`, `REVERSE_GEOCODER_API_KEY`, `REVERSE_GEOCODER_TIMEOUT`, `REVERSE_GEOCODER_RPM`, `REVERSE_GEOCODER_DAILY_QUOTA`
  - `GEOSECONDARY_ENABLED`, `GEOSECONDARY_PROVIDER`, `GEOSECONDARY_URL`, `GEOSECONDARY_API_KEY`, `GEOSECONDARY_CACHE_TTL`
  - `TRUSTED_PROXIES` (support Cloudflare / AWS ALB / reverse proxy)

### Point 4 : Cache partagé inter-processus, quotas atomiques & Traitement de la précision GPS
- **Cache L2 sur disque (`storage/cache/geolocation/`) :** Les résultats sont indexés par cellule de grille (~110m) avec vérification de l'expiration TTL lors de la lecture (`mtime + ttl < time()`). Les entrées expirées sont invalidées.
- **Limitation de débit et quotas atomiques :** Gestion de l'état partagé entre les processus PHP-FPM dans `storage/cache/geolocation/ratelimit_state.json` via verrou exclusif `flock($fp, LOCK_EX)`. Respect strict du plafond par minute (`rpm`) et du quota quotidien (`daily_quota`).
- **Précision GPS non déclarée :** Si `accuracy` est absente (`null`), elle n'est plus fixée arbitrairement à 50 mètres (`declared_accuracy_meters: null`, `accuracy_radius: null`). La géolocalisation est plafonnée au niveau région/pays (`missing_reason = 'missing_gps_accuracy'`).
- **Dégradation prudente :**
  - `accuracy > 50 km` : dégradation au pays uniquement.
  - `accuracy > 5 km` : dégradation à la région uniquement (`city = null`).
  - Proximité d'une frontière régionale avec incertitude chevauchante : repli au niveau pays.

### Point 5 : Transparence du consentement & Confidentialité
- **Fichiers :** `storage/themes/default/gates/consent.php` et `app/helpers/Gate.php`
- **Correction apportée :**
  - La page de consentement informe de manière transparente le visiteur que la position anonymisée peut être transmise à un service tiers de géocodage inverse (avec mention du nom du fournisseur actif).
  - Clarification que les coordonnées GPS brutes (latitude/longitude) ne sont pas stockées en base de données.
  - Préservation des options de partage (« Autoriser la localisation ») et de refus (« Continuer sans partager »).
  - Validation cryptographique stricte par nonce `HMAC-SHA256` lié à `(url->id, stat_id, client_ip)` pour prévenir toute injection ou rejeu.

### Point 6 : Robustesse multi-continents & Résilience aux pannes
- Validé sur des coordonnées de référence indépendantes : Europe (Genève, Paris), Afrique (Nairobi, Mananjary, Manakara), Asie (Tokyo), Amériques (New York, São Paulo), Océanie (Sydney).
- En cas d'indisponibilité du fournisseur externe (panne, dépassement de quota ou timeout), l'application ne bloque pas la navigation : la statistique conserve les données de pays MaxMind et la redirection (y compris vers WhatsApp) s'exécute immédiatement.

---

## 2. SYNTHÈSE DES VÉRIFICATIONS & SUITE DE TESTS

### A. Résultats des tests automatisés
- **Suite de tests :** `tests/run_tests.php` (`tests/GeoLocationTest.php`)
- **Assertions :** **193 exécutées, 193 réussies, 0 échec**
- **Environnements validés :** PHP 8.1, PHP 8.2, PHP 8.3 (Matrice GitHub Actions)
- **Contrôles spécifiques validés :**
  - Traversée multi-sauts `X-Forwarded-For` avec mandataires de confiance.
  - Résolution MaxMind IPv4 et IPv6 sans invention de ville sur sous-réseaux mobiles CGNAT.
  - Rejet des frontières rectangulaires et validation des 23 régions malgaches sans doublons de codes.
  - Priorité des 5 adaptateurs de géocodage inverse (OpenCage, BigDataCloud, Nominatim, Google, Mapbox).
  - Cache disque partagé inter-processus et verrouillage atomique des quotas journaliers.
  - Gestion des coordonnées à précision manquante (`null`) ou dégradée (> 5km, > 50km).
  - Affichage hiérarchique (Niveau 1 : ville, Niveau 2 : région, Niveau 3 : pays).
  - Sécurisation du consentement GPS (HMAC, anti-rejeu, nonces, refus de cross-visitor).
  - Scripts CLI (`migrate-geo-stats.php`, `geo-repair.php`, `geo-diagnose.php`) sur base réelle avec arrêt strict si hors-ligne.

---

## 3. CE QUI EST IMPLÉMENTÉ VS CE QUI DÉPEND DE LA PRODUCTION

| Composant | État dans le code | Dépendance en production |
| :--- | :--- | :--- |
| **MaxMind GeoLite2-City** | Fonctionnel (`storage/app/GeoLite2-City.mmdb`) | Mise à jour périodique via cron (`scripts/update_geolite2.sh` avec `MAXMIND_LICENSE_KEY`) |
| **Géocodage inverse mondial** | 5 adaptateurs implémentés avec cache et quotas | Clé API à renseigner dans `.env` (`REVERSE_GEOCODER_API_KEY`) pour OpenCage, Google, Mapbox ou BigDataCloud |
| **Mandataires de confiance** | Traitement `X-Forwarded-For` sécurisé | Configurer `TRUSTED_PROXIES` dans `.env` si l'application est derrière Cloudflare ou un load balancer |
| **Base de données `stats`** | Scripts de migration prêts (`scripts/migrate-geo-stats.php`) | Exécution manuelle requise en production : `php scripts/migrate-geo-stats.php --apply --confirm` |
| **Consentement navigateur** | Sécurisé par HMAC et affichage transparent | Fonctionne dès que l'option `geoconsent` est cochée sur un lien |

---

## 4. LIMITES CONNUES & COMPROMIS TECHNIQUES

1. **Adresses IP mobiles (CGNAT) :**
   - Sur certains réseaux mobiles à Madagascar et à l'international, les opérateurs mutualisent des plages d'adresses IP sans géolocalisation urbaine précise dans les bases IP. Dans ce cas, la base MaxMind GeoLite2 fournit le pays et parfois la région, mais la ville reste indéterminée. C'est un comportement normal : l'application n'invente jamais de ville.
2. **Quotas d'API externes :**
   - L'utilisation d'un fournisseur de géocodage inverse externe est soumise aux quotas et limites souscrits auprès du prestataire. Le système de cache local par grille et les quotas atomiques configurables protègent l'application contre les surcoûts et les blocages.
3. **Imprécision du signal GPS :**
   - Selon l'environnement du visiteur (intérieur d'un bâtiment, absence de puce GPS sur ordinateur de bureau), le navigateur peut renvoyer une précision dégradée (> 5 km ou précision non renseignée). Le système applique alors un principe de précaution en retenant uniquement la région ou le pays.

---

## 5. PROCÉDURE D'ACTIVATION EN PRODUCTION

1. **Variables d'environnement recommandées dans `.env` :**
   ```dotenv
   # Fournisseur de géocodage inverse (exemples : opencage, bigdatacloud, google, mapbox, nominatim)
   REVERSE_GEOCODER_PROVIDER=opencage
   REVERSE_GEOCODER_API_KEY=votre_cle_api_ici
   REVERSE_GEOCODER_RPM=60
   REVERSE_GEOCODER_DAILY_QUOTA=2500

   # Mandataires de confiance si utilisation de Cloudflare
   TRUSTED_PROXIES=173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,103.31.4.0/22,141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,188.114.96.0/20,197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,104.16.0.0/13,104.24.0.0/14,172.64.0.0/13,131.0.72.0/22
   ```

2. **Migration de la base de données :**
   ```bash
   php scripts/migrate-geo-stats.php --status
   php scripts/migrate-geo-stats.php --apply --confirm
   ```

3. **Mise à jour régulière de la base GeoLite2 :**
   - Configurer une tâche cron mensuelle ou bi-hebdomadaire appelant `scripts/update_geolite2.sh` avec une clé de licence MaxMind valide.
