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

### PHASE 2 — MODERNISATION DU MOTEUR QR CODE & TYPES COMMERCIAUX (VALIDÉ)
- **Objectif :** Enrichir le moteur QR Code de fonctionnalités commerciales à forte valeur ajoutée sans dépendance externe propriétaire.
- **Réalisations :**
  1. **Nouveaux types de QR codes commerciaux :**
     - **Multi-URL Dynamique (`\Helpers\MultiUrlEngine`) :** Redirection conditionnelle par langue du navigateur (avec pondération RFC 7231 `q`), par pays détecté (Madagascar/France/etc.), par type d'appareil (iOS/Android/Mobile/Desktop), et par plage horaire avec fuseau horaire (`Indian/Antananarivo`).
     - **Feedback & Avis Client (`\Helpers\CommercialQr::renderFeedbackPage`) :** Formulaire de notation 1 à 5 étoiles, commentaire, coordonnées optionnelles, protection anti-spam par pot de miel (honeypot) et jeton CSRF, persistance en base dans la table `feedback`.
     - **Coupon & Promotion Commerciale (`\Helpers\CommercialQr::renderCouponPage`) :** Page de bon de réduction pour emballage, badge de remise, code promo avec bouton copier en 1 clic, compte à rebours dynamique et bouton de réclamation directe sur WhatsApp.
  2. **Améliorations graphiques du générateur QR :**
     - Support des dégradés vectoriels SVG en pur PHP (`linearGradient` horizontal, vertical, diagonal, radial).
     - Formes de points personnalisées : `dots`, `circle`/`rounded`, `diamond`, `hexagon`, `square`.
     - Formes d'yeux personnalisées : `circle`, `diamond`, `hexagon`, `rounded`, etc.
     - Intégration de logo au centre avec zone de sécurité (`isPunched`) et élévation automatique de la correction d'erreur au niveau High (`H`) pour garantir une lecture à 100% sur les emballages imprimés.
     - Prévisualisation temps réel sans rechargement de page (`QRController::preview`).

---

### PHASE 3 — PERFORMANCES, CACHE REDIS & GABARITS PACKAGING
- **Module 3A (Cache Redis Optionnel & Repli Fichier) :** Driver Redis configurable avec circuit-breaker et repli automatique transparent vers fichier. Sanctuarisation absolue du verrou de quotas partagé flock().
- **Module 3B (QrTemplates) :** Table `qrtemplates`. Gabarits d'impression packaging avec contrôle de contraste et décodabilité.
- **Module 3C (Rôles et Permissions RBAC) :** Table `roles`. Permissions granulaires serveur (`links.*`, `qr.*`, `stats.*`).
- **Module 3D (Email 2FA) :** Table `email2fa`. Code OTP à 6 chiffres avec limitation stricte.
- **Module 3E (ParamTemplates) :** Table `paramtemplates`. Injection propre de balises UTM.

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

## 3. BILAN D'EXÉCUTION DES PHASES

### Phase 1 (Redirections & Anti-Bots) — TERMINÉE ET VALIDÉE
- **Tests :** 33/33 assertions réussies (`tests/Phase1ModernizationTest.php`).
- **CI GitHub Actions :** Succès 100% sur PHP 8.1, 8.2, 8.3.
- **Pull Request :** PR #2 ouverte sur GitHub.

### Phase 2 (Moteur QR Code & Types Commerciaux) — TERMINÉE ET VALIDÉE
- **Tests :** 50/50 assertions réussies (`tests/Phase2ModernizationTest.php`).
- **Total assertions de la suite :** **336 assertions réussies / 336 (0 échec)**.
- **Non-régression :** Préservation totale des 253 assertions historiques + 33 de la Phase 1.

