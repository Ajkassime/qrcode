# RAPPORT TECHNIQUE DE RECTIFICATION & AUDIT DE MODERNISATION (2026)

**Projet :** Plateforme Commerciale QR Code & Raccourcisseur d'URL (`Ajkassime/qrcode`)  
**Branche cible principale :** `main` (commit de référence `718a7f4`)  
**Date du rapport :** 14 octobre 2026  
**Auteur :** Antigravity / Mission Codex  

---

## 1. ÉTAT DES LIEUX & DÉCISIONS STRATÉGIQUES

Conformément aux instructions impératives de la Mission Codex :
1. **Périmètre strictement limité à la Phase 1 pour toute fusion :**
   - La PR #2 (qui contenait les phases 1 à 4) est réservée et mise en attente.
   - La branche propre `feature/phase1-breakout-anti-bot` est dédiée exclusivement à la Phase 1.
   - Tous les anciens rapports GemPixel non liés à la Phase 1 ont été purgés de cette PR (conservés sur la branche `feature/modernisation-qrcode-2026`).
   - **Une Pull Request indépendante et dédiée est soumise : [PR #3](https://github.com/Ajkassime/qrcode/pull/3).**
2. **Préservation absolue des travaux des Phases 2, 3 et 4 :**
   - Tous les développements des phases 2 à 4 demeurent intégralement conservés et sécurisés sur la branche `feature/modernisation-qrcode-2026` (dernier commit `613ada2`).
   - L'historique n'a subi aucune réécriture (pas de rebase destructif, pas de force-push).
3. **Zéro action en production :**
   - Aucune fusion dans `main` n'a été réalisée.
   - Aucun script de migration SQL n'a été exécuté en production.

---

## 2. DERNIÈRES CORRECTIONS CIBLÉES RÉALISÉES SUR LA PR #3

### A. Respect des Décisions du Moteur Anti-Bots & Robots des Moteurs de Recherche
- **Problème résolu :** `BotProtection::analyze()` classait correctement les robots d'indexation (`type = search_engine`, `count_stat = false`, `allow_geocoding = false`), mais `LinkController::redirect()` appelait `updateStats()` sans vérifier `count_stat`.
- **Correction apportée :**
  - Dans `LinkController::redirect()`, `$this->updateStats()` est désormais strictement conditionné par `!empty($botAnalysis['count_stat'])`.
  - Les robots d'indexation légitimes (Googlebot, Bingbot, etc.) ne génèrent **aucun scan commercial** dans la table `stats` et n'incrémentent pas le compteur `$url->click`.
  - Le géocodage et le prompt de consentement GPS sont court-circuités pour tous les bots (`allow_geocoding = false`), évitant toute consommation d'API tierce.
  - La redirection vers la destination finale s'effectue proprement et sans interruption.
  - Le filtrage reste prudent : seuls les scanners reconnus et requêtes sans en-tête minimal sont bloqués en HTTP 403.

### B. Sécurisation du JavaScript du Navigateur Externe & Android Intents
- **Problème résolu :** L'URL de destination était insérée dans le script de `InAppBreakout.php` via `addslashes()`, vulnérable aux séquences `</script>` et aux caractères spéciaux en contexte HTML.
- **Correction apportée :**
  - Remplacement d'`addslashes()` par une sérialisation robuste `json_encode($url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES)`.
  - Les séquences `</script>` sont encodées sous forme hexadécimale `\u003C/script\u003E`, rendant toute injection XSS impossible sans altérer l'URL finale.
  - Les caractères Unicode (accents français, caractères malgaches, emojis) sont nativement préservés.
  - **Durcissement des Android Intents :** Nettoyage des caractères de contrôle ASCII (`CR/LF`) et neutralisation des séquences `#Intent;` et `;end` présentes dans les données de l'URL pour empêcher toute injection d'intent Android (`com.android.chrome`).

### C. Préservation Universelle de WhatsApp depuis Toutes les Applications
- **Problème résolu :** Le breakout n'était désactivé que lorsque l'utilisateur scannait depuis WhatsApp lui-même. Un scan depuis Instagram, Facebook ou TikTok déclenchait l'écran d'échappement vers Chrome/Safari au lieu d'ouvrir directement l'application WhatsApp.
- **Correction apportée :**
  - Pour toute destination WhatsApp (`wa.me`, `api.whatsapp.com`, `chat.whatsapp.com`, `whatsapp://`), le breakout est désormais **désactivé par défaut quelle que soit l'application d'origine** (Instagram, Facebook, TikTok, WhatsApp).
  - Préservation intégrale des messages pré-remplis et paramètres encodés (`?text=...`).
  - Zéro boucle de redirection : transmission directe vers le deep-link WhatsApp du système d'exploitation.

### D. Rendre les Options Utilisables & Fuite du Paramètre `_breakout`
- **Intégration d'options dans les formulaires :**
  - Ajout de switchs explicites dans le formulaire d'édition de lien (`storage/themes/default/user/edit.php`) pour `inapp_breakout` et `bot_protection`.
  - Ajout d'options correspondantes dans le modal de raccourcissement rapide (`storage/themes/default/partials/shortenermodal.php`).
  - Valeurs par défaut sûres : `inapp_breakout` désactivé par défaut (rétrocompatibilité totale), `bot_protection` activé par défaut.
  - Prise en compte du paramètre `bot_protection = false` dans `LinkController` permettant de désactiver le filtrage pour un lien spécifique si souhaité.
- **Suppression de la fuite `_breakout=skip` :**
  - Lors du clic sur "Continuer dans cette application", le paramètre interne `_breakout=skip` est désormais systématiquement retiré de `$request->query()` avant transmission à l'URL cible, garantissant qu'aucun paramètre interne ne pollue l'URL finale.

---

## 3. BILAN CHIFFRÉ DES TESTS AUTOMATISÉS

La suite de tests exécutée via `tests/run_tests.php` comprend désormais **307 assertions** (dont 54 assertions exclusives à la Phase 1) :

| Suite de tests | Assertions | Statut | Couverture |
| :--- | :---: | :---: | :--- |
| **Tests Historiques & Audit** | 253 | **PASS** | Génération QR, CSRF POST, Uploads, Middlewares, Géolocalisation mondiale MaxMind, Consentement GPS, Isolation HTTP, WhatsApp. |
| **Phase 1 (Breakout, Anti-Bots & Confidentialité)** | 54 | **PASS** | Détection WebViews (Instagram, FB, TikTok, WA), intents Android sécurisés, XSS json_encode, préservation WhatsApp universelle, exclusion _breakout, robots moteurs sans clic ni géocodage, 7 scénarios de confidentialité. |
| **TOTAL GÉNÉRAL PR #3** | **307 / 307** | **100% SUCCÈS** | **0 échec, 0 régression.** |

---

## 4. ESSAIS SUR VRAIS SMARTPHONES À EFFECTUER AVANT DÉPLOIEMENT

Bien que les 307 tests unitaires et d'intégration couvrent l'ensemble de la logique applicative, les tests physiques suivants sur smartphones réels sont recommandés :

1. **iOS (iPhone avec iOS 16 / 17 / 18) :**
   - **Instagram :** Scanner un QR code avec `inapp_breakout = true` $\rightarrow$ vérifier l'affichage de la modale et l'ouverture dans Safari via le menu ••• ou le bouton de copie.
   - **Instagram vers WhatsApp :** Scanner un QR code pointant vers `wa.me/261...` $\rightarrow$ vérifier l'ouverture directe de WhatsApp sans modale de breakout.
   - **Facebook & TikTok :** Vérifier le comportement du bypass « Continuer dans cette application » sans présence de `_breakout=skip` sur le site de destination.
2. **Android (Samsung / Xiaomi / Pixel avec Android 12 à 14) :**
   - **Instagram / Facebook WebView :** Vérifier le déclenchement automatique de l'Android Intent vers Google Chrome.
   - **WhatsApp :** Scanner un QR pointant vers `wa.me` avec texte pré-rempli $\rightarrow$ ouverture immédiate de la discussion sans friction.
   - **Navigateur alternatif (ex: Opera / Firefox) :** Vérifier que le visiteur n'est pas faussement identifié comme WebView.
3. **Réseau local Madagascar (Telma, Orange, Airtel) :**
   - Vérifier la rapidité de chargement de la modale de breakout sur connexion mobile 3G/4G (latence < 50ms).
