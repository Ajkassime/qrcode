# MATRICE DE COMPATIBILITÉ — ANALYSE TECHNIQUE DU CODE OFFICIEL FOURNI (7.8.4)

> **Document de Référence Codex :** Évaluation d'impact et matrice de compatibilité technique vérifiée  
> **Dépôt :** `https://github.com/Ajkassime/qrcode` — Branche `main` (commit `718a7f4`)  
> **Archive analysée :** Répertoire `main 8.0.6` / archive `main.zip` (version démontrée : **GemPixel 7.8.4**)  
> **Date :** 13 octobre 2026  
> **Statut :** AUDIT PRÉALABLE — MIGRATION SUSPENDUE EN ATTENTE DE L'ARCHIVE 8.0.6 OFFICIELLE

---

## 1. SYNTHÈSE DE LA MATRICE DE COMPATIBILITÉ

L'analyse porte sur les composants propriétaires et durcis de notre dépôt face au code de l'archive fournie.

| Domaine Fonctionnel / Technique | Chemin Réel dans `main` | Statut Codex | Niveau de Risque |
| :--- | :--- | :---: | :---: |
| **1. Géolocalisation mondiale multi-fournisseurs** | `app/helpers/GeoLocation.php` | **Compatible avec adaptation mineure** | Moyen |
| **2. Quotas atomiques & Rate Limiting** | `storage/app/` (`ratelimit_state.json`, `ratelimit.lock`) | **Compatible sans modification** | Faible |
| **3. Statistiques enrichies (région, précision, source)** | `app/controllers/StatsController.php`, `app/traits/Links.php` | **Compatible avec adaptation mineure** | Moyen |
| **4. Détection IP & Proxies Cloudflare durcis** | `core/Request.class.php` (méthode `ip()`) | **Conflit critique (Refus écrasement)** | Élevé |
| **5. Consentement GPS & Écrans intermédiaires** | `app/helpers/Gate.php`, `app/routes.php` (`/consent`) | **Compatible avec adaptation mineure** | Moyen |
| **6. Compatibilité WhatsApp & Redirection sécurisée** | `app/controllers/LinkController.php` (classe `Link`) | **Conflit nécessitant fusion chirurgicale** | Critique |
| **7. Sécurisation CSRF sur actions sensibles** | `app/routes.php` (routes en méthode `POST` avec `CSRF`) | **Conflit critique (Refus écrasement)** | Élevé |
| **8. Suite de tests automatisés (253 assertions)** | `tests/run_tests.php` | **Compatible sans modification** | Faible |
| **9. Pipeline CI GitHub Actions multi-PHP** | `.github/workflows/ci.yml` | **Point de blocage Altcha sur PHP 8.1** | Élevé |
| **10. Thèmes d'interface utilisateur** | `storage/themes/default/`, `storage/themes/the23/` | **Compatible avec adaptation mineure** | Moyen |
| **11. Nouvelles tables officielles de l'archive** | Tables `roles`, `qrtemplates`, `paramtemplates`, etc. | **Compatible après extraction SQL exacte** | Faible |

---

## 2. ANALYSE DÉTAILLÉE PAR COMPOSANT RÉEL DU DÉPÔT

### 2.1. Géolocalisation Mondiale (`app/helpers/GeoLocation.php`)
- **Fichier réel :** `app/helpers/GeoLocation.php` (namespace `\Helpers\GeoLocation`, 2 523 lignes).
- **Statut :** `Compatible avec adaptation mineure`
- **Analyse :** 
  - Ce fichier est absent de l'archive officielle. Il ne risque pas d'être écrasé directement, mais ses points d'appel dans `app/controllers/LinkController.php` et `app/traits/Links.php` doivent être préservés lors de toute fusion.
  - Le système de quotas atomiques via `flock()` et fichier temporaire dans `storage/app/` est totalement autonome.

### 2.2. Détection IP Sécurisée (`core/Request.class.php`)
- **Fichier réel :** `core/Request.class.php` (namespace `\Core\Request`, méthode `ip()`).
- **Statut :** `Conflit critique — Ne jamais écraser`
- **Analyse :**
  - Dans notre code `main`, `Request::ip()` valide strictement les proxys mandataires via `TRUSTED_PROXY_IPS` et les plages CIDR officielles de Cloudflare avant d'accepter `CF-Connecting-IP`.
  - Dans l'archive officielle fournie (`main 8.0.6/core/Request.class.php`), la méthode `ip()` fait confiance de manière naïve aux en-têtes `HTTP_CF_CONNECTING_IP` et `HTTP_X_FORWARDED_FOR` sans vérifier l'IP de l'émetteur (`REMOTE_ADDR`).
  - **Décision :** La version Codex de `core/Request.class.php` doit impérativement être conservée.

### 2.3. Sécurité CSRF sur Routes Sensibles (`app/routes.php`)
- **Fichier réel :** `app/routes.php`.
- **Statut :** `Conflit critique — Ne jamais écraser`
- **Analyse :**
  - Sur notre branche `main`, les actions modifiant l'état en base de données ont été converties en requêtes HTTP `POST` protégées par le middleware CSRF :
    - `Gem::post('/teams/toggle/{id}', 'User\Teams@toggle')->middleware('CSRF')`
    - `Gem::post('/plans/{id}/toggle', 'Admin\Plans@toggle')->middleware('CSRF')`
    - `Gem::post('/links/{id}/disable', 'Admin\Links@disable')->middleware('CSRF')`
    - `Gem::post('/domains/{id}/disable', 'Admin\Domains@disable')->middleware('CSRF')`
  - Dans l'archive fournie, ces routes sont toujours déclarées en requêtes `GET` sans vérification CSRF.
  - **Décision :** Refuser tout remplacement global de `app/routes.php`. Seules les nouvelles routes officielles (ex: gestion des templates, rôles) doivent être greffées.

### 2.4. Cycle de Redirection & Compatibilité WhatsApp (`app/controllers/LinkController.php`)
- **Fichier réel :** `app/controllers/LinkController.php` (classe `Link`).
- **Statut :** `Conflit nécessitant une fusion chirurgicale`
- **Analyse :**
  - `LinkController.php` orchestre l'ensemble des contrôles de sécurité : statut du lien (`!$url->status`), vérification des listes noires et domaines dangereux, détection Google Web Risk/Phishing/VirusTotal, protection par mot de passe, expiration temporelle et quota de clics (`clicklimit`), avant d'effectuer la redirection ou d'appeler `Gate::consentLocation()`.
  - **Règle absolue :** Aucun bloc d'erreur ne doit effectuer de fallback direct vers l'URL brute `$link->url`, sous peine de court-circuiter ces barrières de sécurité indispensables pour nos clients industriels.
  - La gestion des prévisualisations in-app (WhatsApp / Facebook bots) doit rester étanche pour ne pas fausser les métriques de clics.

### 2.5. Exigence PHP du Paquet Altcha & Pipeline CI
- **Fichiers réels :** `composer.json` et `.github/workflows/ci.yml`.
- **Statut :** `Point de blocage CI identifié`
- **Analyse :**
  - L'archive fournie introduit `"altcha-org/altcha": "^1.1"` dans `composer.json`.
  - Le `composer.json` interne de `altcha-org/altcha` exige explicitement `"php": ">=8.2"`.
  - Or notre pipeline CI `.github/workflows/ci.yml` exécute `composer check-platform-reqs` sur une matrice comprenant PHP 8.1, PHP 8.2 et PHP 8.3.
  - L'ajout d'Altcha sans ajustement ferait immédiatement échouer la CI sur PHP 8.1.
  - **Décision :** La compatibilité multi-PHP de toute nouvelle dépendance doit être validée avant intégration.

### 2.6. Suite de Tests Existante
- **Fichier réel :** `tests/run_tests.php`.
- **Statut :** `Compatible sans modification`
- **Analyse :**
  - La suite de tests s'exécute via `php tests/run_tests.php` et valide **253 assertions** (sécurité QR, durcissement CSRF, sécurité des uploads, middlewares de routage, géolocalisation mondiale).
  - Aucune dépendance externe PHPUnit n'est requise. Ce banc de test constituera le juge de paix obligatoire lors de toute future intégration.

---

## 3. CONCLUSION DE LA MATRICE

Le code de l'archive fournie (7.8.4) contient des évolutions fonctionnelles intéressantes mais également des régressions de sécurité critiques (retour en arrière sur CSRF et vérification IP). 

**La migration reste formellement gelée jusqu'à la transmission de l'archive 8.0.6 officielle authentique.**
