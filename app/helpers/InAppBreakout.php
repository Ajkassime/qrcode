<?php
/**
 * =======================================================================================
 *                           GemFramework (c) GemPixel / Codex Internal
 * ---------------------------------------------------------------------------------------
 *  In-App Browser Breakout Service (Module 1A)
 *  Enables opening dynamic QR code destinations in native system browsers (Safari/Chrome)
 *  when scanned from in-app WebViews (Instagram, Facebook, TikTok, WhatsApp, Messenger).
 * =======================================================================================
 *
 * @package Helpers
 * @author Codex Engineering
 * @license Proprietary / Internal
 */

namespace Helpers;

use Core\Response;
use Core\Request;
use Core\Helper;

class InAppBreakout {

    /**
     * Cache for detection result in current request
     * @var array|null
     */
    protected static $detectionCache = null;

    /**
     * Check if a destination URL is supported for external breakout
     *
     * @param string|null $url
     * @return bool
     */
    public static function isSupportedUrl(?string $url): bool {
        if (empty($url)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * Conservative in-app browser detection from User-Agent and headers
     *
     * @param string|null $ua
     * @return array
     */
    public static function detectInApp(?string $ua = null): array {
        if ($ua === null) {
            $ua = (string) (request()->userAgent() ?? '');
        }

        // Determine client OS platform
        $platform = 'other';
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $platform = 'ios';
        } elseif (preg_match('/Android/i', $ua)) {
            $platform = 'android';
        }

        $app = null;
        $appName = null;

        // 1. Instagram
        if (preg_match('/Instagram/i', $ua)) {
            $app = 'instagram';
            $appName = 'Instagram';
        }
        // 2. Facebook / Messenger
        elseif (preg_match('/FB_IAB|FB4A|FBIOS|FBAV|FBAN/i', $ua)) {
            if (preg_match('/MESSENGER/i', $ua)) {
                $app = 'messenger';
                $appName = 'Facebook Messenger';
            } else {
                $app = 'facebook';
                $appName = 'Facebook';
            }
        }
        // 3. TikTok / ByteDance
        elseif (preg_match('/musical_ly|ByteLocale|TikTok|trill/i', $ua)) {
            $app = 'tiktok';
            $appName = 'TikTok';
        }
        // 4. WhatsApp in-app webview (user browsing a web link inside WhatsApp)
        elseif (preg_match('/WhatsApp/i', $ua) && preg_match('/Mobile|Safari|Android/i', $ua)) {
            $app = 'whatsapp';
            $appName = 'WhatsApp';
        }
        // 5. Line
        elseif (preg_match('/Line\//i', $ua)) {
            $app = 'line';
            $appName = 'LINE';
        }
        // 6. WeChat
        elseif (preg_match('/MicroMessenger/i', $ua)) {
            $app = 'wechat';
            $appName = 'WeChat';
        }
        // 7. Twitter / X in-app webview
        elseif (preg_match('/Twitter/i', $ua) && preg_match('/Mobile|Safari|Android/i', $ua)) {
            $app = 'twitter';
            $appName = 'X (Twitter)';
        }
        // 8. LinkedIn
        elseif (preg_match('/LinkedInApp/i', $ua)) {
            $app = 'linkedin';
            $appName = 'LinkedIn';
        }
        // 9. Generic Android WebView (identifiable by '; wv' or Version/4.0 with Chrome)
        elseif ($platform === 'android' && (preg_match('/; wv\b/i', $ua) || (preg_match('/Version\/[0-9\.]+/i', $ua) && preg_match('/Chrome\/[0-9\.]+/i', $ua) && !preg_match('/SamsungBrowser/i', $ua)))) {
            $app = 'generic_webview';
            $appName = 'Navigateur intégré';
        }

        return [
            'isInApp'  => ($app !== null),
            'platform' => $platform,
            'app'      => $app,
            'appName'  => $appName ?? 'Navigateur intégré',
            'ua'       => $ua,
        ];
    }

    /**
     * Determine whether breakout should be triggered for a specific link and request
     *
     * @param object $url
     * @param Request $request
     * @return bool
     */
    public static function shouldBreakout(object $url, $request): bool {
        // 1. Check if option is explicitly enabled on this link
        $options = [];
        if (!empty($url->options)) {
            $options = is_array($url->options) ? $url->options : json_decode((string) $url->options, true);
            if (!is_array($options)) {
                $options = [];
            }
        }

        // Disabled by default for all links
        if (empty($options['inapp_breakout'])) {
            return false;
        }

        // 2. Do not trigger breakout if user explicitly asked to "Continue here"
        if (method_exists($request, 'get') && $request->get('_breakout') === 'skip') {
            return false;
        }
        if (method_exists($request, 'query') && $request->query('_breakout') === 'skip') {
            return false;
        }

        // 3. Do not trigger breakout if already passed via session flag
        if (method_exists($request, 'session') && $request->session('inapp_breakout_passed_' . $url->id)) {
            return false;
        }

        // 4. Validate destination URL is HTTP/HTTPS
        if (!self::isSupportedUrl($url->url)) {
            return false;
        }

        // 5. Detect in-app browser
        $ua = method_exists($request, 'userAgent') ? $request->userAgent() : null;
        $detection = self::detectInApp($ua);
        if (!$detection['isInApp']) {
            return false;
        }

        // 6. SPECIAL PRESERVATION FOR WHATSAPP TARGETS:
        // If target is directly a WhatsApp link (wa.me / whatsapp://) and user is in WhatsApp,
        // breakout is redundant and should not degrade native interaction.
        if (self::isWhatsAppTarget($url->url) && $detection['app'] === 'whatsapp') {
            return false;
        }

        return true;
    }

    /**
     * Check if a target URL points directly to WhatsApp
     *
     * @param string $url
     * @return bool
     */
    public static function isWhatsAppTarget(string $url): bool {
        if (strpos($url, 'whatsapp://') === 0) {
            return true;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        return in_array(strtolower($host), ['wa.me', 'api.whatsapp.com', 'chat.whatsapp.com'], true);
    }

    /**
     * Generate Android Chrome intent URL
     *
     * @param string $targetUrl
     * @return string
     */
    public static function getAndroidIntentUrl(string $targetUrl): string {
        $scheme = (string) parse_url($targetUrl, PHP_URL_SCHEME);
        if (empty($scheme)) {
            $scheme = 'https';
        }
        $withoutScheme = preg_replace('~^https?://~i', '', $targetUrl);
        return 'intent://' . $withoutScheme . '#Intent;scheme=' . $scheme . ';package=com.android.chrome;end';
    }

    /**
     * Render the Breakout transition page
     *
     * @param object $url
     * @param mixed $request
     * @return Response
     */
    public static function renderBreakoutPage(object $url, $request): Response {
        $ua = method_exists($request, 'userAgent') ? $request->userAgent() : null;
        $detection = self::detectInApp($ua);
        $platform = $detection['platform'];
        $appName = htmlspecialchars($detection['appName'], ENT_QUOTES, 'UTF-8');
        $targetUrl = $url->url;

        // Build bypass URL (to continue in-app without breakout)
        $currentUri = (string) ($request->server('REQUEST_URI') ?? '');
        $separator = (strpos($currentUri, '?') !== false) ? '&' : '?';
        $continueInAppUrl = $currentUri . $separator . '_breakout=skip';

        // Android Intent
        $androidIntent = self::getAndroidIntentUrl($targetUrl);

        $targetEscaped = htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8');
        $continueEscaped = htmlspecialchars($continueInAppUrl, ENT_QUOTES, 'UTF-8');
        $androidIntentEscaped = htmlspecialchars($androidIntent, ENT_QUOTES, 'UTF-8');

        // Instructions by platform
        $instructionHtml = '';
        if ($platform === 'ios') {
            $instructionHtml = '
                <div class="breakout-instruction">
                    <div class="step-num">1</div>
                    <div class="step-text">Appuyez sur le menu <strong>•••</strong> ou l\'icône de partage <strong><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg></strong> en haut ou en bas de l\'écran.</div>
                </div>
                <div class="breakout-instruction">
                    <div class="step-num">2</div>
                    <div class="step-text">Sélectionnez <strong>« Ouvrir dans Safari »</strong> ou <strong>« Ouvrir dans le navigateur »</strong>.</div>
                </div>';
        } else {
            $instructionHtml = '
                <div class="breakout-instruction">
                    <div class="step-num">1</div>
                    <div class="step-text">Appuyez sur le bouton principal ci-dessous pour lancer <strong>Google Chrome</strong>.</div>
                </div>
                <div class="breakout-instruction">
                    <div class="step-num">2</div>
                    <div class="step-text">Si la redirection ne se lance pas automatiquement, appuyez sur le menu <strong>⋮</strong> puis <strong>« Ouvrir dans Chrome »</strong>.</div>
                </div>';
        }

        $html = '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Ouverture du lien | Navigateur externe</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        :root {
            --primary: #0066FF;
            --primary-hover: #0052CC;
            --bg: #F8FAFC;
            --card-bg: #FFFFFF;
            --text-main: #0F172A;
            --text-muted: #64748B;
            --border: #E2E8F0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: var(--bg); color: var(--text-main); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 16px; }
        .card { background: var(--card-bg); border-radius: 20px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.02); max-width: 440px; width: 100%; padding: 28px 24px; text-align: center; border: 1px solid var(--border); }
        .badge { display: inline-flex; align-items: center; gap: 6px; background: #EEF2F6; color: #334155; font-size: 12px; font-weight: 600; padding: 6px 12px; border-radius: 9999px; margin-bottom: 20px; }
        .badge-dot { width: 8px; height: 8px; border-radius: 50%; background: #F59E0B; }
        .icon-wrap { width: 64px; height: 64px; margin: 0 auto 16px; border-radius: 16px; background: #EFF6FF; color: var(--primary); display: flex; align-items: center; justify-content: center; }
        h1 { font-size: 20px; font-weight: 700; margin-bottom: 8px; color: var(--text-main); }
        p.subtitle { font-size: 14px; color: var(--text-muted); line-height: 1.5; margin-bottom: 24px; }
        .instructions-box { background: #F8FAFC; border: 1px solid var(--border); border-radius: 14px; padding: 16px; text-align: left; margin-bottom: 24px; }
        .breakout-instruction { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 12px; font-size: 13px; color: #334155; }
        .breakout-instruction:last-child { margin-bottom: 0; }
        .step-num { width: 22px; height: 22px; border-radius: 50%; background: var(--primary); color: #fff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .step-text { line-height: 1.4; }
        .btn-primary { display: block; width: 100%; background: var(--primary); color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 15px; padding: 14px 20px; border-radius: 12px; transition: background 0.15s ease; border: none; cursor: pointer; margin-bottom: 12px; }
        .btn-primary:active { background: var(--primary-hover); }
        .btn-secondary { display: inline-block; font-size: 13px; color: var(--text-muted); text-decoration: none; padding: 8px 12px; transition: color 0.15s ease; }
        .btn-secondary:hover { color: var(--text-main); }
        .target-preview { font-size: 11px; color: #94A3B8; margin-top: 16px; word-break: break-all; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">
            <span class="badge-dot"></span>
            Détecté depuis ' . $appName . '
        </div>
        <div class="icon-wrap">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="2" y1="12" x2="22" y2="12"></line>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
            </svg>
        </div>
        <h1>Ouvrir dans le navigateur</h1>
        <p class="subtitle">Pour une expérience optimale (téléchargements, formulaires, sécurité), ce lien est conçu pour s\'ouvrir dans votre navigateur habituel.</p>

        <div class="instructions-box">
            ' . $instructionHtml . '
        </div>

        ' . ($platform === 'android' ? '
        <a href="' . $androidIntentEscaped . '" class="btn-primary" id="openExternalBtn">
            Ouvrir dans Google Chrome
        </a>' : '
        <button type="button" class="btn-primary" id="copyAndOpenBtn" onclick="handleOpenClick()">
            Ouvrir dans Safari
        </button>') . '

        <a href="' . $continueEscaped . '" class="btn-secondary">
            Continuer dans cette application →
        </a>

        <div class="target-preview">
            Destination : ' . htmlspecialchars(substr($targetUrl, 0, 60) . (strlen($targetUrl) > 60 ? '...' : ''), ENT_QUOTES, 'UTF-8') . '
        </div>
    </div>

    <script>
        var targetUrl = "' . addslashes($targetUrl) . '";
        var isAndroid = ' . ($platform === 'android' ? 'true' : 'false') . ';

        function handleOpenClick() {
            // Attempt window open
            var win = window.open(targetUrl, "_blank");
            if (!win) {
                // If popup blocked in WebView, copy link to clipboard as a helpful fallback
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(targetUrl).then(function() {
                        alert("Lien copié dans le presse-papier ! Vous pouvez le coller dans Safari.");
                    }).catch(function() {
                        window.location.href = targetUrl;
                    });
                } else {
                    window.location.href = targetUrl;
                }
            }
        }

        // Automatic attempt for Android Chrome intent
        if (isAndroid) {
            window.addEventListener("DOMContentLoaded", function() {
                setTimeout(function() {
                    try {
                        window.location.href = "' . addslashes($androidIntent) . '";
                    } catch(e) {}
                }, 300);
            });
        }
    </script>
</body>
</html>';

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
