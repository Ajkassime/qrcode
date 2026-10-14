# NOTE DE RECTIFICATION D'AUDIT — GEMPIXEL 8.0.6

> **Document de Rectification Codex :** Mise au point contradictoire, correction des chemins et alignement factuel  
> **Dépôt :** `https://github.com/Ajkassime/qrcode` — Branche : `main` (commit `718a7f4`)  
> **Date :** 13 octobre 2026  
> **Statut :** MIGRATION NON AUTORISÉE — GEL DES ACTIONS EN ATTENTE DE L'ARCHIVE 8.0.6 OFFICIELLE

---

## 1. DISTINCTION FONDAMENTALE : FAITS VÉRIFIÉS vs ERREURS RECTIFIÉES

Cette note rectifie formellement les quatre rapports initiaux (`GEMPIXEL_806_COMPARATIF.md`, `GEMPIXEL_806_COMPATIBILITE.md`, `GEMPIXEL_806_PLAN_MIGRATION.md`, `GEMPIXEL_806_RISQUES.md`) suite aux vérifications directes sur l'arbre de sources GitHub `main` et l'archive transmise.

| Sujet / Élément | Erreur ou Hypothèse initiale | Fait Vérifié dans le Référentiel Réel | Impact & Décision |
| :--- | :--- | :--- | :--- |
| **Version réelle de l'archive** | Nommée `main 8.0.6` | **GemPixel 7.8.4** démontrée dans le code source (`SetupController.php` L.30, `UpdateController.php` L.30). Confusion causée par `PHPFastCache v8.0.6`. | **Migration bloquée.** Ne pas utiliser cette archive pour une migration annoncée en 8.0.6. Attente de l'archive 8.0.6 officielle. |
| **Chemin du service GeoLocation** | Cité `includes/library/GeoLocation.php` | **`app/helpers/GeoLocation.php`** (namespace `\Helpers\GeoLocation`, 2 523 lignes). | Chemins corrigés dans toute la documentation. Aucun répertoire `includes/library/` n'existe dans `main`. |
| **Chemin du gestionnaire de requêtes** | Cité `includes/library/Request.class.php` | **`core/Request.class.php`** (namespace `\Core\Request`). | Chemins corrigés. Préservation de notre méthode `ip()` durcie (CIDR Cloudflare, `TRUSTED_PROXY_IPS`). |
| **Répertoire des thèmes** | Cités `content/themes/default/` & `rohy/` | **`storage/themes/default/`** et **`storage/themes/the23/`**. | Chemins réels rectifiés. Les assets publics sont sous `public/` et les templates sous `storage/themes/`. |
| **Point d'entrée des tests** | Cité `./vendor/bin/phpunit` | **`php tests/run_tests.php`** (runner autonome, **253/253 assertions réussies**). | Suppression des commandes PHPUnit inventées. Utilisation exclusive du runner réel. |
| **Fichier CI GitHub Actions** | Cité `.github/workflows/tests.yml` | **`.github/workflows/ci.yml`** (matrice PHP 8.1, 8.2, 8.3 avec `php tests/run_tests.php`). | Aligné sur le fichier de workflow existant dans GitHub. |
| **Schéma SQL des tables de l'archive** | Colonnes devinées (ex: `paramtemplates.params`) | Schéma officiel issu de `SetupController.php` L.728 et `UpdateController.php` L.291 : colonne **`data`** (et non `params`). | Interdiction de créer des tables sans extraction exacte du schéma officiel. |
| **Numéro de version en BDD** | Mise à jour prématurée de `settings.version` en `7.8.4` | La version en base de données doit rester **inchangée** tant que la migration n'est pas validée et exécutée. | Retrait de toute instruction `UPDATE settings SET value = ...`. |
| **Exigences PHP d'Altcha** | Présenté comme compatible PHP 8.1 | `altcha-org/altcha` exige **`php: >=8.2`** (`vendor/altcha-org/altcha/composer.json` L.17). | Risque critique de rupture CI sur PHP 8.1 lors de `composer check-platform-reqs`. |
| **Sécurité des redirections** | Fallback suggéré vers l'URL brute `$link->url` | **Interdiction absolue :** Un fallback brut court-circuite le mot de passe, l'expiration, le bannissement, la liste noire et le malware check. | Maintien du cycle de sécurité complet dans `LinkController.php`. |
| **Délai de Rollback** | Promesse non étayée de « < 60 secondes » | Procédure de restauration testée, contrôle d'intégrité des sauvegardes et protection des données générées durant la fenêtre. | Suppression de l'allégation commerciale. Procédure technique réelle documentée. |

---

## 2. PREUVES FORENSIQUES CONCERNANT L'ARCHIVE FOURNIE (`main 8.0.6`)

### 2.1. Preuves matérielles de la version 7.8.4
1. **`app/controllers/SetupController.php` (Ligne 30) :**
   ```php
   private $version = '7.8.4';
   ```
2. **`app/controllers/UpdateController.php` (Ligne 30 et Lignes 285-299) :**
   ```php
   private $latest = "7.8.4";
   // ...
   private function update784(){
       if(DB::columnExists('user', 'extra') === false){
           DB::alter('user', function($table){
               $table->add()->text('extra', null);
           });
       }
       DB::schema('paramtemplates', function($table){
           $table->charset("utf8mb4");
           $table->increment('id');
           $table->bigint('userid')->index();
           $table->string('name');
           $table->text('data');
           $table->timestamp('created_at');
       });
   }
   ```
   Il n'existe aucune méthode `update80()`, `update800()` ou `update806()` dans ce contrôleur.
3. **Origine du libellé « 8.0.6 » :**
   La recherche globale dans l'archive démontre que le numéro 8.0.6 n'apparaît qu'à un seul endroit :
   ```
   main 8.0.6/vendor/phpfastcache/phpfastcache/CHANGELOG.md:63: ## 8.0.6
   ```
   Il s'agit de la version de la bibliothèque tierce PHPFastCache, et nullement du logiciel GemPixel Premium URL Shortener.
4. **Fonctionnalités annoncées dans la vraie 8.0.6 officielle :**
   - Moteur Redis natif : **Totalement absent** de l'archive (PHPFastCache Files uniquement).
   - In-app browser breakout : **Totalement absent** de l'archive.
   - Assistant AI Copilot : **Totalement absent** de l'archive.

**Décision formelle :** L'archive fournie ne doit pas être utilisée pour effectuer la migration GemPixel 8.0.6. Tout développement est suspendu jusqu'à la mise à disposition de l'archive 8.0.6 officielle authentique.

---

## 3. CARTOGRAPHIE FACTUELLE DES CHEMINS DU DÉPÔT (`main`)

Les rapports antérieurs ont été corrigés pour refléter l'arborescence réelle du dépôt GitHub `Ajkassime/qrcode` :

```
├── app/
│   ├── controllers/
│   │   ├── LinkController.php          <- Contrôleur de redirection (classe Link)
│   │   ├── StatsController.php         <- Contrôleur des statistiques
│   │   ├── QRController.php            <- Contrôleur de génération QR
│   │   ├── admin/                      <- Contrôleurs d'administration (PlansController, etc.)
│   │   └── user/                       <- Contrôleurs marchands (TeamsController, etc.)
│   ├── helpers/
│   │   ├── GeoLocation.php             <- Moteur mondial de géolocalisation Codex (2 523 lignes)
│   │   ├── QR.php                      <- Helper de rendu QR code (applyDesign)
│   │   └── Gate.php                    <- Gestionnaire d'écrans intermédiaires (consent, direct)
│   ├── traits/
│   │   └── Links.php                   <- Trait de gestion des métriques et redirections
│   └── routes.php                      <- Enregistrement des routes (Gem::get, Gem::post avec CSRF)
├── core/
│   ├── Request.class.php               <- Gestionnaire HTTP durci (détection IP, validation proxy)
│   └── Helper.class.php                <- Fonctions utilitaires
├── storage/
│   ├── app/                            <- Quotas atomiques (ratelimit_state.json, ratelimit.lock)
│   └── themes/
│       ├── default/                    <- Thème par défaut
│       └── the23/                      <- Thème personnalisé de l'application
├── scripts/
│   ├── test-worldwide-geocoder.php     <- Script de simulation et validation geocoder
│   └── cli-bootstrap.php               <- Bootstrap CLI
├── tests/
│   ├── run_tests.php                   <- Point d'entrée de la suite de tests (253 assertions)
│   ├── GeoLocationTest.php             <- Tests unitaires de géolocalisation
│   ├── CsrfSecurityTest.php            <- Tests de durcissement CSRF (routes POST)
│   ├── RoutingMiddlewareTest.php       <- Tests des middlewares de routage
│   ├── QrGenerationTest.php            <- Tests de génération QR
│   └── UploadSecurityTest.php          <- Tests de sécurité upload
└── .github/
    └── workflows/
        └── ci.yml                      <- CI GitHub Actions (PHP 8.1, 8.2, 8.3 avec run_tests.php)
```

---

## 4. RECTIFICATIONS CRITIQUES DE SÉCURITÉ ET DE PROCÉDURE

### 4.1. Interdiction stricte du fallback brut vers l'URL de destination
- **Constat :** Une suggestion erronée dans les premières notes proposait d'ajouter un fallback d'urgence redirigeant vers `$link->url` brut en cas d'erreur dans le pipeline.
- **Danger identifié :** Si un lien est protégé par mot de passe, désactivé par l'administrateur, expiré, signalé pour phishing/malware par Google Web Risk, ou soumis au consentement GPS, une redirection brute vers `$link->url` court-circuite l'ensemble des barrières de sécurité et viole le consentement utilisateur.
- **Règle absolue :** Tout traitement d'erreur doit rediriger vers un écran sécurisé (`Gate::disabled()`, `Gate::expired()`, `Gate::password()`, ou erreur 404/500 contrôlée), sans jamais exposer ou déclencher la destination protégée.

### 4.2. Incompatibilité PHP d'Altcha sur l'environnement de CI (PHP 8.1)
- **Fait vérifié :** L'archive 7.8.4 intègre `altcha-org/altcha: ^1.1` dont le `composer.json` interne spécifie `"require": { "php": ">=8.2" }`.
- **Conséquence :** Notre pipeline CI (`.github/workflows/ci.yml`) exécute `composer check-platform-reqs` sur PHP 8.1, 8.2 et 8.3. L'installation directe de ce paquet sans gestion préalable provoquerait un échec immédiat du build sur PHP 8.1.
- **Recommandation :** Lorsqu'une version officielle sera intégrée, une validation de la compatibilité multi-PHP ou une alternative conforme sera exigée.

### 4.3. Procédure de Rollback réaliste et intégrité des données
- **Suppression :** La promesse d'un rollback automatique en moins de 60 secondes est supprimée des documents.
- **Procédure réelle exigée :**
  1. **Contrôle d'intégrité avant manipulation :** Vérification du dump SQL (`head`, `tail`, `wc -l`, test de restauration sur base temporaire isolée).
  2. **Protection des nouvelles données :** En cas d'incident nécessitant un retour arrière, la table `stats` et la table `links` devront faire l'objet d'un export différentiel pour ne pas écraser les scans enregistrés pendant l'intervention.
  3. **Vérification post-restauration :** Exécution immédiate de `php tests/run_tests.php` pour confirmer le retour à l'état nominal (253/253 assertions).

---

## 5. STATUT ET CONSIGNES RESPECTÉES

- **Aucune branche d'implémentation créée.**
- **Aucune modification sur la branche `main` (commit `718a7f4`).**
- **Aucune modification de la base de données de production ou de ses paramètres.**
- **Travaux suspendus jusqu'à réception de la véritable archive officielle GemPixel 8.0.6.**
