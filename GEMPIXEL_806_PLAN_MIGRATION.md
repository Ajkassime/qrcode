# CADRE MÉTHODOLOGIQUE DE MIGRATION — PRÉPARATION AUDITÉ

> **Document de Référence Codex :** Procédure technique prévisionnelle et exigences de sécurité  
> **Dépôt :** `https://github.com/Ajkassime/qrcode` — Branche : `main` (commit `718a7f4`)  
> **Statut :** DOCUMENT DE TRAVAIL AUDITÉ — AUCUNE EXÉCUTION SANS ARCHIVE 8.0.6 OFFICIELLE ET VALIDATION

---

## 1. PRINCIPES DIRECTEURS IMPÉRATIFS

Toute future migration vers une nouvelle version officielle GemPixel devra respecter les principes suivants :

1. **Aucun écrasement aveugle :** L'approche recommandée par GemPixel consistant à remplacer tous les fichiers est formellement proscrite, car elle détruirait nos développements propriétaires (géolocalisation mondiale, protection IP/Cloudflare, durcissement CSRF).
2. **Préservation absolue des barrières de sécurité :** Aucun fallback aveugle vers l'URL brute de destination n'est toléré dans `app/controllers/LinkController.php`.
3. **Schéma SQL conforme au code officiel :** Aucune table ne doit être créée sans comparaison exacte avec les définitions des contrôleurs d'installation officiels (`SetupController.php` / `UpdateController.php`).
4. **Pas de modification prématurée des versions :** Le paramètre de version dans la table `settings` ne doit être modifié qu'après validation complète et recette en production.
5. **Barrière de non-régression :** Exécution continue du banc de tests réel via `php tests/run_tests.php` (**253 assertions**).

---

## 2. PROCÉDURE PRÉVISIONNELLE PAR ÉTAPES

### ÉTAPE 0 : SAUVEGARDES ET CONTRÔLE D'INTÉGRITÉ

Avant toute manipulation technique :

#### 0.1. Sauvegarde et vérification d'intégrité de la base de données
```bash
# 1. Sauvegarde transactionnelle cohérente
mysqldump -u innovstu_qr -p --single-transaction --quick --routines --triggers \
  innovstu_qr > storage/backups/backup_pre_migration_$(date +%Y%m%d_%H%M%S).sql

# 2. Contrôle d'intégrité formel
# Vérifier que le fichier n'est pas vide et contient bien les tables critiques
test -s storage/backups/backup_pre_migration_*.sql || { echo "ERREUR: Dump vide!"; exit 1; }
grep -q "Table structure for table \`links\`" storage/backups/backup_pre_migration_*.sql || { echo "ERREUR: Table links absente du dump!"; exit 1; }
grep -q "Table structure for table \`stats\`" storage/backups/backup_pre_migration_*.sql || { echo "ERREUR: Table stats absente du dump!"; exit 1; }
```

#### 0.2. Sauvegarde des fichiers et de la configuration
```bash
# Sauvegarde de la configuration et des états de quotas réels
cp config.php storage/backups/config.php.bak
cp storage/app/ratelimit_state.json storage/backups/ratelimit_state.json.bak
```

---

### ÉTAPE 1 : CRÉATION D'UNE BRANCHE ISOLÉE (UNIQUEMENT APRÈS VALIDATION)

> [!NOTE]
> Cette étape est actuellement **gelée**. Aucune branche d'implémentation n'est créée à ce stade.

Quand l'archive 8.0.6 officielle sera validée :
```bash
git checkout -b upgrade/gempixel-8.0.6
```

---

### ÉTAPE 2 : GESTION DES DÉPENDANCES ET COMPATIBILITÉ MULTI-PHP

- Examiner les exigences PHP de chaque nouvelle dépendance.
- **Point d'attention vérifié :** Si `altcha-org/altcha` est introduit, vérifier la compatibilité avec PHP 8.1 (car Altcha déclare `php: >=8.2` dans son `composer.json`), afin de ne pas casser le workflow `.github/workflows/ci.yml` lors de `composer check-platform-reqs`.

---

### ÉTAPE 3 : MIGRATION DU SCHÉMA SQL VÉRIFIÉE ET IDEMPOTENTE

Tout script SQL de migration doit correspondre **exactement** au schéma issu des méthodes `DB::schema()` du logiciel officiel (`SetupController.php` et `UpdateController.php`).

Exemple de structure officielle extraite de `SetupController.php` pour `paramtemplates` :
```sql
CREATE TABLE IF NOT EXISTS `paramtemplates` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `data` longtext NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_paramtemplates_userid` (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
*Note : Ne pas modifier la valeur `version` dans la table `settings` à cette étape.*

---

### ÉTAPE 4 : INTÉGRATION CHIRURGICALE DU CODE

1. **Intégration des composants isolés :** Nouveaux contrôleurs n'interférant pas avec nos routes existantes.
2. **Conservation de nos sécurités :**
   - Conserver `core/Request.class.php` (détection IP sécurisée et filtrage des proxys de confiance).
   - Conserver `app/helpers/GeoLocation.php` et ses verrous atomiques dans `storage/app/`.
   - Conserver les déclarations `Gem::post` avec middleware `CSRF` dans `app/routes.php`.
   - Maintenir les validations complètes dans `app/controllers/LinkController.php` sans aucun fallback brut non contrôlé.

---

### ÉTAPE 5 : VALIDATION PAR LA SUITE DE TESTS RÉELLE

Exécution sur l'environnement de développement et validation CI :

```bash
# Vérification de syntaxe
find app core scripts tests -type f -name "*.php" -exec php -l {} \;

# Exécution de la suite de tests officielle du projet (253 assertions)
php tests/run_tests.php
```

Le critère d'acceptation est le succès complet de `php tests/run_tests.php` (0 échec, 253 assertions réussies) et le passage au vert de `.github/workflows/ci.yml` sur PHP 8.1, 8.2 et 8.3.

---

### ÉTAPE 6 : STRATÉGIE DE RETOUR ARRIÈRE (ROLLBACK) RÉALISTE

En cas d'incident détecté lors d'un déploiement :

1. **Protection des nouvelles données de production :**
   Avant de restaurer une sauvegarde antérieure, exporter les événements générés pendant l'opération :
   ```bash
   mysqldump -u innovstu_qr -p --no-create-info --where="created_at >= '2026-10-13 22:00:00'" innovstu_qr stats > /tmp/stats_delta.sql
   ```
2. **Bascule de branche Git :**
   ```bash
   git checkout main
   ```
3. **Restauration de la base de données :**
   Restauration du dump préalable vérifié, suivie de la réinjection du différentiel `stats_delta.sql`.
4. **Vérification de bon fonctionnement :**
   Exécution immédiate de `php tests/run_tests.php` pour confirmer le retour à l'état nominal.
