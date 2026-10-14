# PLAN DIRECTEUR DE MODERNISATION QR CODE 2026 (DÉVELOPPEMENT INTERNE CODEX)

> **Projet :** Modernisation propriétaire de la plateforme QR Code & URL Shortener  
> **Dépôt :** `https://github.com/Ajkassime/qrcode`  
> **Branche de travail :** `feature/modernisation-qrcode-2026` (créée à partir de `main` commit `718a7f4`)  
> **Date d'adoption :** 13 octobre 2026  
> **Principe fondamental :** Développement 100% interne de fonctionnalités originales, sans copie ni dépendance au code propriétaire GemPixel 8.0.6.

---

## 1. CARTOGRAPHIE INITIALE DE L'EXISTANT vs BESOINS DE MODERNISATION

| Domaine / Module | Statut dans `main` | Composants Existants Réutilisables | Travaux Requis |
| :--- | :--- | :--- | :--- |
| **1A. In-App Browser Breakout** | **Absent** | `app/controllers/LinkController.php`, `core/Request.class.php` (User-Agent), `app/helpers/Gate.php` | Détection prudente (FB, IG, TikTok, WA), intent Chrome natif / écran de transition propre, toggle par lien désactivé par défaut. |
| **1B. Protection Anti-Bots Sélective** | **Partiellement présent** (`core/Request.class.php`, `jaybizzle/crawler-detect`) | `Request::userAgent()`, OpenGraph tags, `GeoLocation::resolve()` | Distinction bots malveillants vs prévisualisations sociales (OG) vs humains WhatsApp, blocage avant géocodage externe payant. |
| **2. Cache Redis Optionnel & Repli Fichier** | **Partiellement présent** (`core/Helper.class.php` avec PHPFastCache Files) | `core/Helper.class.php`, `app/helpers/GeoLocation.php` (cache partagé) | Driver Redis configurable avec circuit-breaker et repli automatique vers fichier. Sanctuarisation des quotas atomiques. |
| **3A. QR Code Templates** | **Absent** | `app/helpers/QR.php` (`applyDesign()`), `app/controllers/QRController.php` | Table `qrtemplates`, interface de gabarits emballage/packaging, contrôle de contraste et décodabilité. |
| **3B. Rôles et Permissions (RBAC)** | **Partiellement présent** (Admin basique `user.admin`, `user.teamPermission`) | `core/Auth.class.php`, `Models\User`, `app/routes.php` | Table `roles`, permissions granulaires serveur (`links.*`, `qr.*`, `stats.*`), middleware `CheckRolePermission`. |
| **3C. Authentification 2FA par E-mail** | **Absent** (Google Auth uniquement dans `app/helpers/GoogleAuth.php`) | `core/Email.class.php`, `app/controllers/user/AccountController.php` | Table `email2fa`, code OTP temporaire à 6 chiffres, rate-limiting et hachage des codes. |
| **3D. Modèles de Paramètres URL (UTM)** | **Absent** | `app/traits/Links.php` | Table `paramtemplates`, injection propre des UTM sans altérer les cibles WhatsApp complexes. |
| **4A. Ciblage Avancé Combiné** | **Partiellement présent** (ciblage pays ou device séparé dans `LinkController`) | `LinkController::redirect()`, `app/helpers/GeoLocation.php` | Évaluation combinée (Pays + Appareil + Langue) sans jamais court-circuiter mot de passe, expiration ou ban. |
| **4B. Tableau de Bord & Statistiques** | **Existant à moderniser** | `storage/themes/default/`, `storage/themes/the23/`, `StatsController` | Vues statistiques enrichies (distinction scans humains / aperçus, précision GPS), accessibilité mobile. |
| **4C. Gestionnaire de Plugins Sécurisé** | **Existant statique** | `core/Plugin.class.php` | Consultation et activation sécurisée sans chargement distant. |
| **5. IA, Multidevise & Bio Pages (Optionnels)** | **Absents / Futurs** | — | Étude préalable, quotas stricts et priorisation ultérieure. |

---

## 2. DÉTAIL DES PHASES ET EXIGENCES TECHNIQUES

### PHASE 1 — REDIRECTIONS INTERNATIONALES & PROTECTION ANTI-BOTS (PRIORITÉ CRITIQUE)

#### Module 1A : In-App Browser Breakout
- **Objectif :** Faciliter la sortie des WebViews fermées (Instagram, Facebook, Messenger, TikTok, WhatsApp) vers les navigateurs natifs (Safari sous iOS, Chrome sous Android) pour les QR codes commerciaux de packaging.
- **Règles d'activation :**
  - Option `inapp_breakout` dans le JSON `options` de la table `url` (Désactivée par défaut sur tous les liens existants).
  - Aucune régression sur les QR codes ciblant directement l'application WhatsApp (`wa.me` ou `whatsapp://`).
- **Comportement technique :**
  - Détection prudente du User-Agent de la WebView : `FBAV`, `FBAN`, `Instagram`, `musical_ly`, `ByteLocale`, `TikTok`, `Line`, etc.
  - **Android :** Génération d'un intent Chrome sécurisé (`intent://...#Intent;scheme=https;package=com.android.chrome;end`) ou en-tête `Content-Disposition`.
  - **iOS :** Affichage d'un écran de transition léger et moderne (« Ouvrir dans Safari ») avec bouton tactile d'ouverture externe, et option explicite « Continuer ici » pour ne jamais coincer l'utilisateur.
  - Prévention absolue des boucles de redirection via marqueur de session ou paramètre temporaire anti-rebond.
  - Préservation intégrale des query strings et ancres.

#### Module 1B : Protection Anti-Bots Sélective
- **Objectif :** Bloquer les scrapers et scans automatisés indésirables tout en préservant scrupuleusement les crawlers d'aperçus légitimes (Meta OpenGraph, Twitterbot) et les utilisateurs humains WhatsApp.
- **Distinction fine à trois niveaux :**
  1. **Humain (Navigateur standard ou in-app) :** Traitement complet, géolocalisation, incrémentation des statistiques de clics, redirection fluide.
  2. **Crawler de prévisualisation légitime (ex: `facebookexternalhit/1.1`, `WhatsApp/2.x`, `TelegramBot`) :** Rendu des balises méta OpenGraph (titre, image, description) sans incrémenter les statistiques de scans marchands et **sans déclencher de géocodage externe payant**.
  3. **Robot automatisé / Scraper indésirable :** Blocage contrôlé HTTP 403 / 429 ou redirection neutre, sans consommation d'API de géocodage, avec journalisation minimale anonymisée.

---

### PHASE 2 — PERFORMANCES ET CACHE REDIS OPTIONNEL
- **Objectif :** Offrir une latence de résolution en mémoire ultra-rapide (< 30ms) pour les gros volumes de scans QR.
- **Architecture :**
  - Driver Redis configuré dans `config.php` (`CACHE_DRIVER = 'redis'`, `REDIS_HOST`, etc.).
  - **Circuit Breaker :** En cas d'indisponibilité ou timeout Redis (> 50ms), bascule automatique transparente vers le cache fichiers local.
  - **Exception de sécurité vitale :** Le système de quotas atomiques et de rate limiting partagé de `app/helpers/GeoLocation.php` conserve son mécanisme `flock()` exclusif sur disque (zéro risque de réinitialisation silencieuse des quotas).

---

### PHASE 3 — AMÉLIORATIONS QR CODES & ADMINISTRATION
- **Module 3A (QrTemplates) :** Table `qrtemplates`. Permet d'enregistrer des gabarits graphiques conformes aux normes d'impression packaging. Réutilise obligatoirement `\Helpers\QR::applyDesign()`.
- **Module 3B (RBAC) :** Table `roles`. Permissions granulaires vérifiées côté serveur pour isoler les accès des opérateurs de marque, imprimeurs et administrateurs.
- **Module 3C (Email 2FA) :** Table `email2fa`. Code OTP 6 chiffres envoyé par email, haché en base, avec limitation stricte à 3 tentatives et validité 10 minutes.
- **Module 3D (ParamTemplates) :** Table `paramtemplates`. Injection propre de balises UTM sans perturber les paramètres complexes WhatsApp.

---

### PHASE 4 — CIBLAGE AVANCÉ ET MODERNISATION UI
- **Module 4A (Advanced Targeting) :** Règles combinées (Pays + Appareil + Langue). Une localisation inconnue ne doit jamais être assimilée à une ville déterminée.
- **Module 4B (Dashboard) :** Modernisation du thème sans refonte destructive, préservation du thème Rohy (`storage/themes/default/`).
- **Module 4C (Gestionnaire de Plugins) :** Consultation sécurisée de l'état des plugins locaux sans téléchargement de code non signé.

---

### PHASE 5 — FONCTIONNALITÉS COMMERCIALES FUTURES (SUR ÉTUDE)
- Assistant IA (Gemini / Claude / OpenAI) avec quotas stricts par utilisateur.
- Module multi-devises et conversion sécurisée.
- Widgets Bio Pages complémentaires.

---

## 3. CRITÈRES D'ACCEPTATION POUR LA PHASE 1

1. **253 assertions existantes préservées :** Zéro régression sur la suite de tests `tests/run_tests.php`.
2. **Nouveaux tests unitaires et d'intégration :**
   - Test 1 : Scan QR classique vers URL HTTPS (redirection directe sans breakout).
   - Test 2 : Scan QR WhatsApp avec paramètres complexes (`wa.me/261...?text=...`).
   - Test 3 : Détection d'un navigateur mobile standard (Safari/Chrome -> redirection directe).
   - Test 4 : Détection WebView Instagram / Facebook avec option `inapp_breakout` activée -> écran de transition ou intent.
   - Test 5 : Détection WebView avec option désactivée -> comportement natif inchangé.
   - Test 6 : Bot d'aperçu OpenGraph (`facebookexternalhit`) -> méta-tags servis, aucun appel géocodeur externe, aucune incrémentation de scan.
   - Test 7 : Scraper/Bot indésirable -> bloqué sans appel API.
   - Test 8 : Liens historiques sans nouvelle option -> comportement inchangé garanti à 100%.
3. **Validation CI GitHub Actions :** Succès sur la matrice multi-PHP (8.1, 8.2, 8.3).
