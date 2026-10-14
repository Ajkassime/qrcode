# MATRICE DES RISQUES ET POINTS DE VIGILANCE — ANALYSE DE PRODUCTION

> **Document de Référence Codex :** Analyse exhaustive des risques de migration et contre-mesures  
> **Dépôt :** `https://github.com/Ajkassime/qrcode` — Branche : `main` (commit `718a7f4`)  
> **Date :** 13 octobre 2026  
> **Classification :** CRITIQUE — PRODUCTION PACKAGING & QR CODES COMMERCIAUX

---

## 1. SYNTHÈSE DE LA MATRICE DES RISQUES

| ID | Risque Identifié | Gravité | Probabilité initiale | Contre-mesure Codex Obligatoire |
| :---: | :--- | :---: | :---: | :--- |
| **R1** | **Régression sur les redirections QR codes en production** | Critique | Moyenne | Maintien du cycle de redirection complet dans `LinkController.php`. Validation par `php tests/run_tests.php`. |
| **R2** | **Bypass de sécurité par fallback incontrôlé** | Critique | Élevée si mal conçu | **Interdiction absolue** d'un fallback direct vers `$link->url`. Redirection obligatoire vers écrans d'erreur sécurisés (`Gate`). |
| **R3** | **Perte ou altération des colonnes statistiques existantes** | Élevée | Faible | Exclusion formelle de l'updater web automatique. Comparaison stricte avec le schéma officiel (`SetupController.php`). |
| **R4** | **Dépassement de quotas ou panne du géocodage mondial** | Élevée | Moyenne | Préservation intégrale de `app/helpers/GeoLocation.php` et de ses verrous atomiques dans `storage/app/`. |
| **R5** | **Rupture de compatibilité WhatsApp & Webviews in-app** | Critique | Élevée | Préservation du filtrage User-Agent et exclusion du bot OpenGraph pour éviter les doubles comptages. |
| **R6** | **Réintroduction des vulnérabilités CSRF (routes en GET)** | Élevée | Très Élevée | Rejet de l'écrasement de `app/routes.php`. Maintien des méthodes HTTP `POST` protégées par `CSRF`. |
| **R7** | **Échec du pipeline CI (PHP 8.1) avec Altcha** | Moyenne | Très Élevée | Vérification des prérequis PHP de toute dépendance (`altcha-org/altcha` exige PHP >= 8.2). |
| **R8** | **Écrasement de la détection IP durcie** | Élevée | Élevée | Conservation stricte de `core/Request.class.php` (rejet de la version naïve officielle). |

---

## 2. ANALYSE DÉTAILLÉE DES RISQUES MAJEURS

### R1 & R2. Sécurité des Redirections et Interdiction du Fallback Brut
- **Contexte :** Des dizaines de milliers de QR codes sont imprimés physiquement sur des emballages de produits distribués à Madagascar et à l'international.
- **Danger identifié :** Toute tentative d'ajouter un fallback d'urgence non contrôlé redirigeant aveuglément vers l'URL brute (`$link->url`) en cas d'erreur briserait les contrôles critiques suivants :
  - Vérification de l'état du lien (`$url->status == 0`)
  - Contrôle de mot de passe (`$url->pass`)
  - Détection anti-phishing et anti-malware Google Web Risk
  - Date d'expiration et quotas de clics
  - Consentement GPS préalable
- **Contre-mesure Codex :** Tout incident dans le traitement d'un lien doit aboutir à un arrêt contrôlé (`Gate::disabled()`, `Gate::expired()`, `Gate::password()`, ou code d'erreur HTTP adéquat), sans jamais exposer ou ouvrir l'URL cible de manière non autorisée.

---

### R3. Préservation des Données Statistiques et Schéma BDD
- **Contexte :** Nos clients suivent leurs statistiques géographiques par région malgache (`region`), source de géocodage (`geo_source`) et rayon d'incertitude (`accuracy_radius`).
- **Danger :** L'exécuteur de mise à jour officiel de GemPixel (`/update`) ne tient pas compte des personnalisations locales et pourrait réinitialiser des tables ou altérer des index existants.
- **Contre-mesure Codex :**
  - Ne jamais exécuter `/update` en production.
  - Utiliser uniquement des migrations SQL vérifiées et documentées, déduites du schéma officiel réel (`SetupController.php`).

---

### R4. Quotas Atomiques & Géocodage Mondial
- **Contexte :** Le service de géocodage externe limite strictement le nombre de requêtes par minute.
- **Danger :** Si `app/helpers/GeoLocation.php` ou ses fichiers d'état dans `storage/app/` étaient altérés, les quotas pourraient être dépassés, entraînant des erreurs 429 ou des coûts financiers imprévus.
- **Contre-mesure Codex :** Conservation intégrale de `app/helpers/GeoLocation.php` et de ses méthodes d'écriture atomique avec verrou `flock()`.

---

### R5. Compatibilité WhatsApp
- **Contexte :** Les scans proviennent en grande majorité d'applications mobiles (WhatsApp, navigateurs intégrés).
- **Danger :** Les robots d'exploration de prévisualisation (User-Agent `facebookexternalhit` / `WhatsApp`) ne doivent pas consommer de quotas API de géocodage ni gonfler artificiellement les statistiques de clics réels.
- **Contre-mesure Codex :** Maintenir la séparation stricte entre le traitement des métadonnées OpenGraph pour les bots et la redirection réelle avec géolocalisation pour l'utilisateur final.

---

### R6 & R8. Durcissement Sécurité (CSRF et Proxies IP)
- **Contexte :** Le code de la branche `main` a fait l'objet d'un audit de sécurité rigoureux et validé par tests.
- **Danger :** Le code officiel fourni utilise des routes `GET` sans token CSRF pour des actions modifiant les données (`/teams/toggle/{id}`, `/plans/{id}/toggle`, `/links/{id}/disable`) et une détection IP naïve dans `core/Request.class.php`.
- **Contre-mesure Codex :** Rejeter formellement le remplacement de ces fichiers. Conserver nos implémentations sécurisées.

---

### R7. Compatibilité Multi-PHP sur la CI
- **Contexte :** Notre workflow GitHub Actions `.github/workflows/ci.yml` valide le code sur PHP 8.1, 8.2 et 8.3 avec `composer check-platform-reqs`.
- **Danger :** Le paquet `altcha-org/altcha: ^1.1` présent dans l'archive fournie requiert PHP >= 8.2 dans ses dépendances. Son ajout provoquerait l'échec de la CI sur PHP 8.1.
- **Contre-mesure Codex :** Toute nouvelle dépendance doit être validée pour compatibilité multi-PHP avant d'être intégrée dans `composer.json`.
