<?php
/**
 * =======================================================================================
 *                           GemFramework (c) GemPixel / Codex Internal
 * ---------------------------------------------------------------------------------------
 *  Bot & Crawler Protection Service (Module 1B)
 *  Distinguishes real human scans, social media OpenGraph preview fetchers,
 *  legitimate search crawlers, and unwanted automated scrapers/bots.
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
use Helpers\App;

class BotProtection {

    /**
     * Social preview crawlers pattern (OpenGraph / Twitter Cards / Chat metadata preview)
     */
    const SOCIAL_PREVIEW_REGEX = '/facebookexternalhit|Facebot|Twitterbot|TelegramBot|Discordbot|LinkedInBot|Pinterest|Slackbot|SkypeUriPreview|Applebot|vkShare|Viber/i';

    /**
     * Known legitimate search engine crawlers
     */
    const SEARCH_ENGINE_REGEX = '/Googlebot|bingbot|Baiduspider|YandexBot|DuckDuckBot|Sogou|Exabot/i';

    /**
     * Unwanted scrapers, vulnerability scanners, and aggressive bots
     */
    const MALICIOUS_BOT_REGEX = '/sqlmap|nikto|zgrab|nmap|masscan|wprecon|acunetix|dirbuster|gobuster|censys|shodan|fuzz|petalbot|mj12bot|ahrefsbot|semrushbot|dotbot|megaindex|zoominfobot|blexbot|seznambot|sitebulb/i';

    /**
     * Analyze an incoming request and categorize the visitor
     *
     * @param mixed $request
     * @return array
     */
    public static function analyze($request = null): array {
        if ($request === null) {
            $request = request();
        }

        $ua = (string) ($request->userAgent() ?? '');
        $accept = (string) ($request->server('HTTP_ACCEPT') ?? '');
        $acceptLang = (string) ($request->server('HTTP_ACCEPT_LANGUAGE') ?? '');
        $secChUa = (string) ($request->server('HTTP_SEC_CH_UA') ?? '');

        // 1. Check for malicious scanners / aggressive scrapers
        if (!empty($ua) && preg_match(self::MALICIOUS_BOT_REGEX, $ua, $m)) {
            self::logDecision('blocked_malicious', $m[0], $request);
            return [
                'type'            => 'bad_bot',
                'is_bot'          => true,
                'is_preview'      => false,
                'allow_opengraph' => false,
                'count_stat'      => false,
                'allow_geocoding' => false,
                'bot_name'        => $m[0],
                'reason'          => 'malicious_signature',
            ];
        }

        // Suspicious request: completely empty user agent and no standard browser headers
        if (empty($ua) && empty($accept) && empty($acceptLang)) {
            self::logDecision('blocked_empty_ua', 'empty', $request);
            return [
                'type'            => 'bad_bot',
                'is_bot'          => true,
                'is_preview'      => false,
                'allow_opengraph' => false,
                'count_stat'      => false,
                'allow_geocoding' => false,
                'bot_name'        => 'unknown_headless',
                'reason'          => 'missing_mandatory_headers',
            ];
        }

        // 2. Check for Social Media Previews (OpenGraph bots)
        // CRITICAL DISTINCTION:
        // WhatsApp User-Agent when acting as a metadata fetcher: 'WhatsApp/2.23.10.77 i' or 'WhatsApp/...' without WebKit/Safari components.
        // WhatsApp in-app browser (user tapping link): 'Mozilla/5.0 ... WhatsApp/2.23 ... Mobile Safari ...'.
        $isWhatsAppPreview = false;
        if (preg_match('/WhatsApp/i', $ua)) {
            // If it lacks browser rendering engine (Gecko/WebKit/Chrome/Safari), it's strictly a preview bot!
            if (!preg_match('/(WebKit|Safari|Chrome|Gecko)/i', $ua)) {
                $isWhatsAppPreview = true;
            }
        }

        if (preg_match(self::SOCIAL_PREVIEW_REGEX, $ua, $m) || preg_match('/WhatsApp/i', $ua) || preg_match('/Instagram|FBAV|FBAN|FB_IAB/i', $ua)) {
            $matchedName = $isWhatsAppPreview ? 'WhatsAppPreview' : ($m[0] ?? 'SocialApp');

            // If it's a real human browsing inside WhatsApp, Instagram, Facebook, TikTok, etc.
            if (preg_match('/Instagram|FBAV|FBAN|FB_IAB|WhatsApp/i', $ua) && preg_match('/Mobile|Safari|Android|Chrome/i', $ua)) {
                return [
                    'type'            => 'human',
                    'is_bot'          => false,
                    'is_preview'      => false,
                    'allow_opengraph' => true,
                    'count_stat'      => true,
                    'allow_geocoding' => true,
                    'bot_name'        => null,
                    'reason'          => 'human_inapp_webview',
                ];
            }

            return [
                'type'            => 'social_preview',
                'is_bot'          => true,
                'is_preview'      => true,
                'allow_opengraph' => true,
                'count_stat'      => false,
                'allow_geocoding' => false,
                'bot_name'        => $matchedName,
                'reason'          => 'social_preview_crawler',
            ];
        }

        // 3. Check for standard Search Engine Crawlers
        if (!empty($ua) && preg_match(self::SEARCH_ENGINE_REGEX, $ua, $m)) {
            return [
                'type'            => 'search_engine',
                'is_bot'          => true,
                'is_preview'      => false,
                'allow_opengraph' => true,
                'count_stat'      => false,
                'allow_geocoding' => false,
                'bot_name'        => $m[0],
                'reason'          => 'search_engine_crawler',
            ];
        }

        // 4. Jaybizzle CrawlerDetect fallback for generic automated scrapers (e.g. curl, python-requests, Guzzle)
        if (class_exists('\Jaybizzle\CrawlerDetect\CrawlerDetect')) {
            $crawlerDetect = new \Jaybizzle\CrawlerDetect\CrawlerDetect();
            if ($crawlerDetect->isCrawler($ua)) {
                $crawlerName = $crawlerDetect->getMatches() ?: 'generic_crawler';
                return [
                    'type'            => 'bad_bot',
                    'is_bot'          => true,
                    'is_preview'      => false,
                    'allow_opengraph' => false,
                    'count_stat'      => false,
                    'allow_geocoding' => false,
                    'bot_name'        => (string) $crawlerName,
                    'reason'          => 'generic_crawler_detected',
                ];
            }
        }

        // 5. Normal Human Visitor (Standard mobile, desktop, or in-app webview)
        return [
            'type'            => 'human',
            'is_bot'          => false,
            'is_preview'      => false,
            'allow_opengraph' => true,
            'count_stat'      => true,
            'allow_geocoding' => true,
            'bot_name'        => null,
            'reason'          => 'human_browser',
        ];
    }

    /**
     * Render OpenGraph tags response for social preview bots
     * This allows WhatsApp, Facebook, Twitter, etc. to display title/image/description
     * without recording fake scans or triggering paid reverse geocoding!
     *
     * @param object $url
     * @return Response
     */
    public static function handleSocialPreview(object $url): Response {
        $metaTitle = !empty($url->meta_title) ? $url->meta_title : ($url->url ?? config('title'));
        $metaDesc = !empty($url->meta_description) ? $url->meta_description : config('description');
        $siteTitle = config('title') ?? 'QR Platform';

        $shortUrl = App::shortRoute($url->domain, $url->alias . ($url->custom ?? ''));
        $metaImage = '';
        if (!empty($url->meta_image)) {
            $metaImage = uploads($url->meta_image, 'images');
        } else {
            $metaImage = $shortUrl . '/i';
        }

        $html = '<!DOCTYPE html>
<html lang="fr" prefix="og: http://ogp.me/ns#">
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($metaTitle . ' | ' . $siteTitle, ENT_QUOTES, 'UTF-8') . '</title>
    <meta name="description" content="' . htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8') . '" />
    <meta name="robots" content="noindex, follow">
    <meta property="og:type" content="website" />
    <meta property="og:url" content="' . htmlspecialchars($shortUrl, ENT_QUOTES, 'UTF-8') . '" />
    <meta property="og:title" content="' . htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8') . '" />
    <meta property="og:description" content="' . htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8') . '" />
    ' . (!empty($metaImage) ? '<meta property="og:image" content="' . htmlspecialchars($metaImage, ENT_QUOTES, 'UTF-8') . '" />' : '') . '
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="' . htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8') . '">
    <meta name="twitter:description" content="' . htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8') . '">
    ' . (!empty($metaImage) ? '<meta name="twitter:image" content="' . htmlspecialchars($metaImage, ENT_QUOTES, 'UTF-8') . '">' : '') . '
</head>
<body>
    <p>Aperçu du lien commercial.</p>
</body>
</html>';

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Handle blocked bad bot with minimal footprint
     *
     * @param string $message
     * @return Response
     */
    public static function handleBadBot(string $message = 'Access Denied: Automated scraping or malicious scanner detected.'): Response {
        http_response_code(403);
        return new Response($message, 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Proportionate security logging (never stores raw PII or exact personal data)
     *
     * @param string $action
     * @param string $signature
     * @param mixed $request
     * @return void
     */
    protected static function logDecision(string $action, string $signature, $request): void {
        try {
            $logDir = STORAGE . '/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0775, true);
            }
            $file = $logDir . '/bot_protection_' . date('Y-m-d') . '.log';
            $ipHash = substr(hash('sha256', (string) $request->ip()), 0, 16);
            $entry = sprintf("[%s] ACTION=%s SIGNATURE=%s IP_HASH=%s URI=%s\n",
                date('Y-m-d H:i:s'),
                $action,
                substr($signature, 0, 32),
                $ipHash,
                substr((string) ($request->server('REQUEST_URI') ?? ''), 0, 64)
            );
            @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Fail silently to never disrupt request processing
        }
    }
}
