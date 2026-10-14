<?php
/**
 * =======================================================================================
 *                           GemFramework (c) GemPixel
 * ---------------------------------------------------------------------------------------
 *  This software is packaged with an exclusive framework as such distribution
 *  or modification of this framework is not allowed before prior consent from
 *  GemPixel. If you find that this framework is packaged in a software not distributed
 *  by GemPixel or authorized parties, you must not use this software and contact GemPixel
 *  at https://gempixel.com/contact to inform them of this misuse.
 * =======================================================================================
 *
 * @package GemPixel\Premium-URL-Shortener
 * @author GemPixel (https://gempixel.com)
 * @license https://gempixel.com/licenses
 * @link https://gempixel.com
 */

namespace Helpers;

use Core\Request;
use Core\Response;
use Core\DB;
use Core\Helper;

/**
 * Class CommercialQr
 *
 * Handles dedicated landing pages and actions for commercial QR code types:
 * - Customer Feedback & Star Ratings (1-5 stars with anti-spam and DB persistence)
 * - Retail Coupon & Commercial Promotion (discount badge, promo code copy, countdown, WhatsApp claim)
 */
class CommercialQr {

    /**
     * Render the customer feedback evaluation page
     *
     * @param object $url
     * @param object|null $qr
     * @param array $data
     * @param Request $request
     * @return Response
     */
    public static function renderFeedbackPage(object $url, ?object $qr, array $data, Request $request): Response {
        $title = htmlspecialchars((string) ($data['title'] ?? 'Votre avis nous intéresse'), ENT_QUOTES, 'UTF-8');
        $subtitle = htmlspecialchars((string) ($data['subtitle'] ?? 'Prenez quelques secondes pour évaluer notre service ou produit.'), ENT_QUOTES, 'UTF-8');
        $businessName = htmlspecialchars((string) ($data['business_name'] ?? $qr->name ?? 'Notre établissement'), ENT_QUOTES, 'UTF-8');
        $csrfToken = csrf_token();
        $targetAction = htmlspecialchars(App::shortRoute($url->domain, $url->alias . $url->custom), ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>{$title} - {$businessName}</title>
    <style>
        :root {
            --primary: #0d6efd;
            --primary-dark: #0b5ed7;
            --star-active: #ffc107;
            --star-inactive: #e4e5e9;
            --bg: #f8f9fa;
            --card-bg: #ffffff;
            --text-dark: #212529;
            --text-muted: #6c757d;
            --border: #e9ecef;
            --radius: 16px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background-color: var(--bg); color: var(--text-dark); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .feedback-card { background: var(--card-bg); width: 100%; max-width: 480px; border-radius: var(--radius); box-shadow: 0 10px 30px rgba(0,0,0,0.06); padding: 28px 24px; border: 1px solid var(--border); }
        .header { text-align: center; margin-bottom: 24px; }
        .business-badge { display: inline-block; background: #e7f1ff; color: var(--primary); font-size: 13px; font-weight: 600; padding: 4px 12px; border-radius: 20px; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .title { font-size: 22px; font-weight: 700; color: var(--text-dark); margin-bottom: 8px; }
        .subtitle { font-size: 14px; color: var(--text-muted); line-height: 1.4; }
        .stars-container { display: flex; justify-content: center; gap: 8px; margin: 24px 0 8px; direction: rtl; }
        .stars-container input[type="radio"] { display: none; }
        .stars-container label { font-size: 38px; color: var(--star-inactive); cursor: pointer; transition: color 0.15s ease-in-out, transform 0.15s ease; }
        .stars-container label:hover,
        .stars-container label:hover ~ label,
        .stars-container input[type="radio"]:checked ~ label { color: var(--star-active); }
        .stars-container label:active { transform: scale(1.2); }
        .rating-desc { text-align: center; font-size: 14px; font-weight: 600; min-height: 20px; color: var(--primary); margin-bottom: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--text-dark); }
        .form-control { width: 100%; border: 1px solid #ced4da; border-radius: 10px; padding: 12px; font-size: 14px; transition: border-color 0.2s; outline: none; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(13,110,253,0.15); }
        textarea.form-control { min-height: 90px; resize: vertical; }
        .hp-field { position: absolute; left: -9999px; width: 1px; height: 1px; opacity: 0; }
        .btn-submit { width: 100%; background: var(--primary); color: #fff; border: none; padding: 14px; font-size: 16px; font-weight: 600; border-radius: 10px; cursor: pointer; transition: background 0.2s, transform 0.1s; }
        .btn-submit:hover { background: var(--primary-dark); }
        .btn-submit:active { transform: scale(0.99); }
        .success-box { display: none; text-align: center; padding: 30px 10px; }
        .success-icon { font-size: 54px; margin-bottom: 16px; color: #198754; }
        .success-title { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
        .success-msg { font-size: 14px; color: var(--text-muted); line-height: 1.5; }
    </style>
</head>
<body>
    <div class="feedback-card">
        <div id="form-section">
            <div class="header">
                <span class="business-badge">{$businessName}</span>
                <h1 class="title">{$title}</h1>
                <p class="subtitle">{$subtitle}</p>
            </div>

            <form id="feedback-form" method="POST" action="{$targetAction}">
                <input type="hidden" name="_token" value="{$csrfToken}">
                <input type="text" name="_hp_company" class="hp-field" tabindex="-1" autocomplete="off">

                <div class="stars-container">
                    <input type="radio" id="star5" name="rating" value="5" required><label for="star5" title="5 étoiles" data-text="Excellent ! (5/5)">★</label>
                    <input type="radio" id="star4" name="rating" value="4"><label for="star4" title="4 étoiles" data-text="Très bon (4/5)">★</label>
                    <input type="radio" id="star3" name="rating" value="3"><label for="star3" title="3 étoiles" data-text="Moyen (3/5)">★</label>
                    <input type="radio" id="star2" name="rating" value="2"><label for="star2" title="2 étoiles" data-text="Insuffisant (2/5)">★</label>
                    <input type="radio" id="star1" name="rating" value="1"><label for="star1" title="1 étoile" data-text="Médiocre (1/5)">★</label>
                </div>
                <div class="rating-desc" id="rating-desc">Sélectionnez une note</div>

                <div class="form-group">
                    <label class="form-label" for="comment">Votre commentaire ou suggestion</label>
                    <textarea class="form-control" id="comment" name="comment" placeholder="Qu'avez-vous particulièrement apprécié ? Comment pouvons-nous nous améliorer ?" maxlength="1000"></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label" for="contact_name">Votre nom (facultatif)</label>
                    <input type="text" class="form-control" id="contact_name" name="name" placeholder="Ex: Jean / Sahondra" maxlength="100">
                </div>

                <div class="form-group">
                    <label class="form-label" for="contact_info">Téléphone ou E-mail (facultatif)</label>
                    <input type="text" class="form-control" id="contact_info" name="contact" placeholder="Pour vous recontacter si nécessaire" maxlength="100">
                </div>

                <button type="submit" class="btn-submit" id="submit-btn">Envoyer mon avis</button>
            </form>
        </div>

        <div id="success-box" class="success-box">
            <div class="success-icon">✓</div>
            <h2 class="success-title">Misaotra betsaka !</h2>
            <p class="success-msg">Merci beaucoup pour votre retour d'expérience. Vos remarques sont précieuses pour nous améliorer au quotidien.</p>
        </div>
    </div>

    <script>
        (function() {
            var labels = document.querySelectorAll('.stars-container label');
            var desc = document.getElementById('rating-desc');
            var form = document.getElementById('feedback-form');
            var formSection = document.getElementById('form-section');
            var successBox = document.getElementById('success-box');
            var submitBtn = document.getElementById('submit-btn');

            labels.forEach(function(lbl) {
                lbl.addEventListener('mouseenter', function() {
                    desc.textContent = this.getAttribute('data-text');
                });
                lbl.addEventListener('click', function() {
                    desc.textContent = this.getAttribute('data-text');
                });
            });

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                submitBtn.disabled = true;
                submitBtn.textContent = 'Envoi en cours...';

                var formData = new FormData(form);
                var xhr = new XMLHttpRequest();
                xhr.open('POST', form.action, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onload = function() {
                    if (xhr.status >= 200 && xhr.status < 400) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.success) {
                                formSection.style.display = 'none';
                                successBox.style.display = 'block';
                                return;
                            }
                        } catch(err) {}
                    }
                    formSection.style.display = 'none';
                    successBox.style.display = 'block';
                };
                xhr.onerror = function() {
                    formSection.style.display = 'none';
                    successBox.style.display = 'block';
                };
                xhr.send(formData);
            });
        })();
    </script>
</body>
</html>
HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Handle incoming customer feedback form submission
     *
     * @param object $url
     * @param Request $request
     * @return array
     */
    public static function handleFeedbackSubmission(object $url, Request $request): array {
        // 1. Anti-spam honeypot verification
        $honeypot = trim((string) ($request->post('_hp_company') ?? ''));
        if ($honeypot !== '') {
            // Silently discard spam submission with fake success
            return ['success' => true, 'message' => 'Feedback recorded successfully.'];
        }

        // 2. Validate rating
        $rating = (int) ($request->post('rating') ?? 0);
        if ($rating < 1 || $rating > 5) {
            return ['success' => false, 'error' => 'Invalid rating. Please select between 1 and 5 stars.'];
        }

        $comment = trim((string) ($request->post('comment') ?? ''));
        $name = trim((string) ($request->post('name') ?? ''));
        $contact = trim((string) ($request->post('contact') ?? ''));
        $ip = (string) $request->ip();

        self::ensureFeedbackTable();

        try {
            $feedback = DB::feedback()->create();
            $feedback->qrid = (int) ($url->qrid ?? 0);
            $feedback->rating = $rating;
            $feedback->comment = mb_substr($comment, 0, 2000, 'UTF-8');
            $feedback->name = mb_substr($name, 0, 100, 'UTF-8');
            $feedback->contact = mb_substr($contact, 0, 100, 'UTF-8');
            $feedback->ip = $ip;
            $feedback->created_at = Helper::dtime();
            $feedback->save();

            return ['success' => true, 'message' => 'Feedback saved successfully.'];
        } catch (\Throwable $e) {
            \GemError::log('Feedback save error: ' . $e->getMessage());
            return ['success' => true, 'message' => 'Feedback recorded.'];
        }
    }

    /**
     * Render the commercial retail coupon page
     *
     * @param object $url
     * @param object|null $qr
     * @param array $data
     * @param Request $request
     * @return Response
     */
    public static function renderCouponPage(object $url, ?object $qr, array $data, Request $request): Response {
        $title = htmlspecialchars((string) ($data['title'] ?? 'Offre Exclusive'), ENT_QUOTES, 'UTF-8');
        $discount = htmlspecialchars((string) ($data['discount'] ?? '-20%'), ENT_QUOTES, 'UTF-8');
        $code = htmlspecialchars((string) ($data['code'] ?? 'PROMO2026'), ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars((string) ($data['description'] ?? 'Présentez ce code lors de votre commande ou en caisse.'), ENT_QUOTES, 'UTF-8');
        $businessName = htmlspecialchars((string) ($data['business_name'] ?? $qr->name ?? 'Notre Boutique'), ENT_QUOTES, 'UTF-8');
        $terms = htmlspecialchars((string) ($data['terms'] ?? 'Offre non cumulable, valable jusqu’à épuisement des stocks.'), ENT_QUOTES, 'UTF-8');
        $expiry = (string) ($data['expiry'] ?? '');

        // Build WhatsApp claim link
        $waPhone = preg_replace('/[^0-9]/', '', (string) ($data['whatsapp'] ?? ''));
        $waLink = '';
        if ($waPhone !== '') {
            $waMessage = "Bonjour {$businessName}, je souhaite utiliser mon code promo {$code} ({$discount}) pour ma commande.";
            $waLink = 'https://wa.me/' . $waPhone . '?text=' . rawurlencode($waMessage);
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>{$discount} - {$businessName}</title>
    <style>
        :root {
            --primary: #e63946;
            --primary-dark: #d62828;
            --wa-green: #25d366;
            --wa-dark: #128c7e;
            --bg: #f8f9fa;
            --card-bg: #ffffff;
            --text-dark: #1d3557;
            --text-muted: #6c757d;
            --border: #e9ecef;
            --radius: 20px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: var(--bg); color: var(--text-dark); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .coupon-card { background: var(--card-bg); width: 100%; max-width: 440px; border-radius: var(--radius); box-shadow: 0 12px 36px rgba(0,0,0,0.08); overflow: hidden; border: 1px solid var(--border); text-align: center; }
        .top-banner { background: linear-gradient(135deg, var(--primary) 0%, #ff6b6b 100%); color: #fff; padding: 32px 20px 24px; position: relative; }
        .business-tag { display: inline-block; background: rgba(255,255,255,0.25); color: #fff; font-size: 13px; font-weight: 700; padding: 4px 14px; border-radius: 20px; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .discount-badge { font-size: 42px; font-weight: 900; letter-spacing: -1px; line-height: 1.1; margin-bottom: 6px; }
        .promo-title { font-size: 18px; font-weight: 600; opacity: 0.95; }
        .coupon-body { padding: 28px 20px; }
        .promo-desc { font-size: 14px; color: var(--text-muted); line-height: 1.5; margin-bottom: 24px; }
        .code-box { background: #fdf2f2; border: 2px dashed var(--primary); border-radius: 12px; padding: 16px; margin-bottom: 20px; position: relative; }
        .code-label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--primary); letter-spacing: 1px; margin-bottom: 4px; }
        .code-value { font-size: 26px; font-weight: 800; font-family: monospace; color: var(--primary-dark); letter-spacing: 2px; }
        .btn-copy { margin-top: 10px; background: #fff; border: 1px solid var(--primary); color: var(--primary); font-size: 13px; font-weight: 700; padding: 8px 18px; border-radius: 20px; cursor: pointer; transition: all 0.2s; }
        .btn-copy:hover { background: var(--primary); color: #fff; }
        .countdown-container { margin-bottom: 24px; display: none; }
        .countdown-title { font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px; }
        .countdown-grid { display: flex; justify-content: center; gap: 8px; }
        .time-box { background: #f1f3f5; border-radius: 8px; padding: 8px 12px; min-width: 52px; }
        .time-num { font-size: 20px; font-weight: 800; color: var(--text-dark); }
        .time-label { font-size: 10px; color: var(--text-muted); text-transform: uppercase; }
        .actions { display: flex; flex-direction: column; gap: 10px; }
        .btn-wa { display: flex; align-items: center; justify-content: center; gap: 8px; background: var(--wa-green); color: #fff; text-decoration: none; font-size: 15px; font-weight: 700; padding: 14px; border-radius: 12px; transition: background 0.2s; }
        .btn-wa:hover { background: var(--wa-dark); }
        .terms-box { margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border); font-size: 12px; color: var(--text-muted); text-align: left; }
        .terms-summary { cursor: pointer; font-weight: 600; outline: none; }
        .terms-content { margin-top: 8px; line-height: 1.4; }
    </style>
</head>
<body>
    <div class="coupon-card">
        <div class="top-banner">
            <span class="business-tag">{$businessName}</span>
            <div class="discount-badge">{$discount}</div>
            <div class="promo-title">{$title}</div>
        </div>

        <div class="coupon-body">
            <p class="promo-desc">{$description}</p>

            <div class="code-box">
                <div class="code-label">Code promotionnel</div>
                <div class="code-value" id="promo-code">{$code}</div>
                <button type="button" class="btn-copy" id="btn-copy">Copier le code</button>
            </div>

            <div id="countdown-container" class="countdown-container" data-expiry="{$expiry}">
                <div class="countdown-title">Offre expire dans</div>
                <div class="countdown-grid">
                    <div class="time-box"><div class="time-num" id="cd-days">00</div><div class="time-label">Jours</div></div>
                    <div class="time-box"><div class="time-num" id="cd-hours">00</div><div class="time-label">Heures</div></div>
                    <div class="time-box"><div class="time-num" id="cd-mins">00</div><div class="time-label">Mins</div></div>
                    <div class="time-box"><div class="time-num" id="cd-secs">00</div><div class="time-label">Secs</div></div>
                </div>
            </div>

            <div class="actions">
HTML;

        if ($waLink !== '') {
            $waLinkEsc = htmlspecialchars($waLink, ENT_QUOTES, 'UTF-8');
            $html .= <<<HTML
                <a href="{$waLinkEsc}" class="btn-wa" target="_blank" rel="noopener">
                    <span>Réclamer via WhatsApp</span>
                </a>
HTML;
        }

        $html .= <<<HTML
            </div>

            <details class="terms-box">
                <summary class="terms-summary">Conditions d'utilisation</summary>
                <div class="terms-content">{$terms}</div>
            </details>
        </div>
    </div>

    <script>
        (function() {
            var btnCopy = document.getElementById('btn-copy');
            var promoCode = document.getElementById('promo-code');

            if (btnCopy && promoCode) {
                btnCopy.addEventListener('click', function() {
                    var text = promoCode.textContent.trim();
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(function() {
                            btnCopy.textContent = 'Copié ! ✓';
                            setTimeout(function() { btnCopy.textContent = 'Copier le code'; }, 2000);
                        });
                    } else {
                        var area = document.createElement('textarea');
                        area.value = text;
                        document.body.appendChild(area);
                        area.select();
                        document.execCommand('copy');
                        document.body.removeChild(area);
                        btnCopy.textContent = 'Copié ! ✓';
                        setTimeout(function() { btnCopy.textContent = 'Copier le code'; }, 2000);
                    }
                });
            }

            var cdBox = document.getElementById('countdown-container');
            var expiryStr = cdBox ? cdBox.getAttribute('data-expiry') : '';
            if (expiryStr) {
                var targetDate = new Date(expiryStr).getTime();
                if (!isNaN(targetDate)) {
                    cdBox.style.display = 'block';
                    function updateCd() {
                        var now = new Date().getTime();
                        var diff = targetDate - now;
                        if (diff <= 0) {
                            cdBox.innerHTML = '<div class="countdown-title" style="color:var(--primary)">Offre terminée</div>';
                            return;
                        }
                        var d = Math.floor(diff / (1000 * 60 * 60 * 24));
                        var h = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                        var m = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                        var s = Math.floor((diff % (1000 * 60)) / 1000);

                        document.getElementById('cd-days').textContent = (d < 10 ? '0' : '') + d;
                        document.getElementById('cd-hours').textContent = (h < 10 ? '0' : '') + h;
                        document.getElementById('cd-mins').textContent = (m < 10 ? '0' : '') + m;
                        document.getElementById('cd-secs').textContent = (s < 10 ? '0' : '') + s;
                    }
                    updateCd();
                    setInterval(updateCd, 1000);
                }
            }
        })();
    </script>
</body>
</html>
HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Ensure the feedback database table exists (additive, non-destructive migration)
     *
     * @return void
     */
    public static function ensureFeedbackTable(): void {
        static $checked = false;
        if ($checked) {
            return;
        }

        try {
            $driver = \Core\DB::get_driver_name();
            $tableName = defined('DBprefix') ? DBprefix . 'feedback' : 'feedback';

            if ($driver === 'sqlite') {
                \Core\DB::raw_execute("CREATE TABLE IF NOT EXISTS `{$tableName}` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `qrid` INTEGER,
                    `rating` INTEGER,
                    `comment` TEXT,
                    `name` TEXT,
                    `contact` TEXT,
                    `ip` TEXT,
                    `created_at` TEXT
                )");
            } else {
                \Core\DB::raw_execute("CREATE TABLE IF NOT EXISTS `{$tableName}` (
                    `id` bigint(20) NOT NULL AUTO_INCREMENT,
                    `qrid` bigint(20) NOT NULL,
                    `rating` tinyint(4) NOT NULL,
                    `comment` text DEFAULT NULL,
                    `name` varchar(191) DEFAULT NULL,
                    `contact` varchar(191) DEFAULT NULL,
                    `ip` varchar(64) DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    KEY `qrid` (`qrid`),
                    KEY `rating` (`rating`),
                    KEY `created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            }
            $checked = true;
        } catch (\Throwable $e) {
            \GemError::log('ensureFeedbackTable error: ' . $e->getMessage());
        }
    }
}
