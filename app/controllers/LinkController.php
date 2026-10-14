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

use Core\Request;
use Core\Response;
use Core\DB;
use Core\Helper;
use Core\View;
use Core\Plugin;
use Core\Auth;
use Helpers\Gate;
use Models\User;

class Link {

	use Traits\Links;

	/**
	* Static path to grab favicon
	*/
   	const ICOPATH = "https://www.google.com/s2/favicons?domain={{url}}&sz=24";

	/**
	 * Add Url
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.0
	 * @param \Core\Request $request
	 * @return void
	 */
	public function shorten(Request $request){

		if($image = $request->file('metaimage')){
			$request->metaimage = $image;
		}

		Auth::check();

		$user = Auth::user();

		if($user && $user->teamPermission('links.create') == false){
			return (new Response(['error' => false, 'message' => e('You do not have this permission. Please contact your team administrator.')]))->json();
		}

		if($user && $user->team()){
			$user = User::first($user->rID());
		}

		if($request->urls){

			$urls = explode("\n", $request->urls);
			if(!$urls || empty($urls)) return (new Response(['error' => true, 'message' => e('Please enter valid links.')]))->json();

			$results = [];

			foreach($urls as $url){
				$request->url = trim($url);
				try	{
					$results[] = $this->createLink($request, $user)['shorturl'];
				} catch (\Exception $e){
					$results[] =  $e->getMessage();
				}
			}

			return (new Response(['error' => false, 'message' => e('Links have been shortened.'), 'multiple' => true, 'data' => implode("\n", $results)]))->json();
		}

		try	{
			$this->createLink($request, $user, true);
		} catch (\Exception $e){
			return (new Response(['error' => true, 'message' => $e->getMessage()]))->json();
		}
	}

    /**
     * Redirect Link
     *
     * @author GemPixel <https://gempixel.com>
     * @version 7.5
     * @param string $alias
     * @return void
     */
    public function redirect(Request $request, string $alias){

		// Module 1B: Anti-Bot & Scraper Protection
		$botAnalysis = class_exists('\Helpers\BotProtection')
			? \Helpers\BotProtection::analyze($request)
			: ['type' => 'human', 'is_preview' => false];

		if ($botAnalysis['type'] === 'bad_bot') {
			return \Helpers\BotProtection::handleBadBot();
		}

		// @group Plugin: Pre-redirect checks or complete override
		Plugin::dispatch('link.preredirect', $alias);

		if(!$url = $this->getURL($request, $alias)){
			return $this->notFound($request);
		}

		// Module 1B: Serve clean OpenGraph metadata for social previews (WhatsApp, Meta) without counting fake scans or geocoding
		if (!empty($botAnalysis['is_preview'])) {
			if (!$url->status) return Gate::inactive();
			return \Helpers\BotProtection::handleSocialPreview($url);
		}

		// Plugin: Take control of redirect
		Plugin::dispatch('link.redirect', $url);

		// @group Plugin: Override $url data
		if($response = Plugin::dispatch('link.override', $url)){
			$url = $response;
		}

		$user = null;
		if($url->userid != 0 && $user = \Models\User::where('id', $url->userid)->first()){
			// Disable URLs of user is banned
			if($user->banned) return stop(404);
		}

		// Check if URL is disabled
		if(!$url->status) return Gate::inactive();

		// Check blacklist domain
		if(!$url->qrid && !$url->profileid && ($this->domainBlacklisted($url->url) || $this->wordBlacklisted($url->url))) return Gate::disabled();

		// Check with Google Web Risk
		if(!$url->qrid && !$url->profileid && !$this->safe($url->url)) {
			$url->status = 0;
			$url->save();
			return Gate::disabled();
		}

		// Check with Phish
		if(!$url->qrid && !$url->profileid && $this->phish($url->url)) {
			$url->status = 0;
			$url->save();
			return Gate::disabled();
		}

		// Check with VirusTotal
		if(!$url->qrid && !$url->profileid && $this->virus($url->url)) {
			$url->status = 0;
			$url->save();
			return Gate::disabled();
		}

		// Password check is stored in a session. User will have access until the browser is closed.
		if($request->isPost() && $request->password){
			// if encrypted Password (old version)
			if(strlen($url->pass) == 32) $request->password = md5($request->password);

			// Check Password
			if($request->password != $url->pass){
				return back()->with("danger", e("The password is invalid or does not match."));
			}

			// Set Session after successful attempt
			$request->session("{$url->id}_passcheck", true);
		}

		// Let's check if it is password-protected
		if(!empty($url->pass) && $request->session("{$url->id}_passcheck") == false) return Gate::password($url);

		if($url->profileid && $profile = DB::profiles()->where('id', $url->profileid)->first()){
			$this->updateStats($request, $url, null);
			return Gate::profile($profile, null, $url);
		}

		// Get User info
		if($user){
			// If membership expired, switch to free
			if($user->pro && time() > strtotime($user->expiration)){
				$user->pro = 0;
				$user->trial = 0;
				$user->save();
			}
			$hasMedia = $user->media;
			$isPro = $user->admin ? 1 : $user->pro;
		}else{
			$hasMedia = config('show_media');
			$isPro = 0;
		}

		if(!config("pro")){
			$isPro = 1;
		}
		// Rotator
		$options = null;

		if(!empty($url->options)) $options = json_decode($url->options, true);

		if($options && isset($options['clicklimit']) && $options['clicklimit'] && $options['clicklimit'] <= $url->click) {
			if( isset($options['expirationredirect']) && !empty($options['expirationredirect'])){
				header('Location: '.$options['expirationredirect']);
				exit;
			}else {
				return Gate::expired();
			}
		}


		// Check if expired
		if(!empty($url->expiry) && strtotime("now") > strtotime($url->expiry)) {
			if( isset($options['expirationredirect']) && !empty($options['expirationredirect'])){
				header('Location: '.$options['expirationredirect']);
				exit;
			}else {
				return Gate::expired();
			}
		}

		// Update Stats
		$this->updateStats($request, $url, $user);

		if($options && isset($options['rotators'])) {
			$rotators = [];
			$list = $options['rotators'];
			$count = count($options['rotators']);
			if($count > 0){
				$list[] = ['percent' => round(100/($count+1)), 'link' => $url->url];
				foreach($list as $i => $redirect) {
					$rotators = array_merge($rotators, array_fill(0, $redirect['percent'], $i));
				}
				$key = $rotators[mt_rand(0,count($rotators)-1)];
				if($key < $count){
					if(isset($list[$key]['count'])){
						$options['rotators'][$key]['count'] += 1;
					}else{
						$options['rotators'][$key]['count'] = 1;
					}
				}

				$url->options = json_encode($options);
				$url->save();

				$url->url = $list[$key]['link'];
			}

		}

		// Check if URL is geo targeted
		if(!empty($url->location) && config("geotarget")){

			$geo = $request->country();

			$country = strtolower($geo['country']);
			$state = strtolower($geo['state']);
			$location = json_decode($url->location, true);

			if($country && isset($location[$country])) {
				$redirect = isset($location[$country]['all']) ? $location[$country]['all'] : $location[$country];
			}

			if($state && isset($location[$country][$state])) {
				$redirect = $location[$country][$state];
			}
			if(isset($redirect) && !is_array($redirect)) $url->url = $redirect;
		}

		// Check if URL is device targeted
		if(!empty($url->devices) && config("devicetarget")){

			if(strpos($request->device(), ' ') !== false){
				$device = strtolower(implode(' ', explode(' ',$request->device(), -1)));
			} else {
				$device = strtolower($request->device());
			}

			$devices = json_decode($url->devices, true);
			if(isset($devices[$device]) && $device) {

				$url->url = $devices[$device];

				if($device == 'iphone' || $device == 'ipad' || $device == 'android'){
					if($user->has('deeplink') && $options && isset($options['deeplink']) && $options['deeplink']['enabled']) return Gate::deeplink($url, $user, $device, $options['deeplink']);
				}
			}
		}


		if($options && isset($options['languages'])){
			$browser_language = $request->server('http_accept_language') ? substr($request->server('http_accept_language'), 0, 2) : null;
			if($browser_language && strpos($browser_language, ' ') !== false){
				$language = strtolower(implode(' ', explode(' ',$browser_language, -1)));
			} else {
				$language = $browser_language ? strtolower($browser_language) : null;
			}

			if(isset($options['languages'][$language]) && $language) {
				$url->url = $options['languages'][$language];
			}
		}

		if(DB::reports()->whereRaw('bannedlink LIKE ?', ['%'.$url->url.'%'])->first()){
			return Gate::disabled();
		}

		// Check for Advanced Multi-Criteria Targeting (Country + Region + Device + OS + Language + Schedule)
		if(!empty($options['advanced_targeting_rules'])){
			$geo = $request->country();
			$visitorCtx = [
				'country'  => $geo['country'] ?? '',
				'region'   => $geo['state'] ?? '',
				'device'   => $request->device() ?? '',
				'os'       => $request->device() ?? '',
				'language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
			];
			$url->url = \Helpers\AdvancedTargeting::resolve($url, $visitorCtx);
		}

		// Replace encoded ampersand
		$url->url = str_replace("&amp;", "&", $url->url);

		// Append parameters (via UtmManager for safe parameter merge and WhatsApp target preservation)
		if(!empty($url->parameters) && $params = json_decode($url->parameters, true)){
			$url->url = \Helpers\UtmManager::applyParameters($url->url, $params);
		}

		// Forward queries if any
		if($request->query()){
			if(strpos($url->url, "?")){
				$url->url = $url->url."&".http_build_query($request->query());
			}else{
				$url->url = $url->url."?".http_build_query($request->query());
			}
		}

		// Optional Campaign Geo Consent Check (disabled by default, 0ms penalty if not enabled)
		if($options && !empty($options['geoconsent']) && !$request->session("geoconsent_{$url->id}")){
			return Gate::consentLocation($url, $user);
		}

		// Module 1A: In-App Browser Breakout (Optional per-link, disabled by default)
		if(class_exists('\Helpers\InAppBreakout') && \Helpers\InAppBreakout::shouldBreakout($url, $request)){
			return \Helpers\InAppBreakout::renderBreakoutPage($url, $request);
		}

		if($url->qrid){

			$qr = DB::qrs()->where('id', $url->qrid)->first();

			$data = json_decode($qr->data);

			if($data->type == 'vcard'){
				return \Core\File::contentDownload('vcard.vcf', function() use ($data){
					echo \Helpers\QR::typeVcard($data->data);
				});
			}

			// Phase 2: Dynamic Multi-URL conditional redirection
			if($data->type == 'multiurl' && class_exists('\Helpers\MultiUrlEngine')){
				$config = (array) ($data->data ?? []);
				if(empty($config['default']) && !empty($url->url)){
					$config['default'] = $url->url;
				}
				$resolved = \Helpers\MultiUrlEngine::resolve($config, $request);
				if(!empty($resolved)){
					$url->url = $resolved;
				}
				return Gate::direct($url, null);
			}

			// Phase 2: Customer Feedback Rating Page
			if($data->type == 'feedback' && class_exists('\Helpers\CommercialQr')){
				if($request->isPost() && $request->post('rating') !== null){
					$result = \Helpers\CommercialQr::handleFeedbackSubmission($url, $request);
					return (new \Core\Response($result))->json();
				}
				return \Helpers\CommercialQr::renderFeedbackPage($url, $qr, (array) ($data->data ?? []), $request);
			}

			// Phase 2: Retail Promotional Coupon Page
			if($data->type == 'coupon' && class_exists('\Helpers\CommercialQr')){
				return \Helpers\CommercialQr::renderCouponPage($url, $qr, (array) ($data->data ?? []), $request);
			}

			return Gate::direct($url, null);
		}

		if(!empty($url->meta_title)) View::set("title",$url->meta_title);
		if(!empty($url->meta_description)) View::set("description",$url->meta_description);

		View::set("url", Helpers\App::shortRoute($url->domain, $url->alias.$url->custom));
		if($url->meta_image){
			View::set("image", uploads($url->meta_image, 'images'));
		} else {
			View::set("image", Helpers\App::shortRoute($url->domain, $url->alias.$url->custom).'/i');
		}

		// Check if overlay
		if($url->type && preg_match("~overlay-(.*)~", $url->type) && $overlay = DB::overlay()->where("id",  str_replace("overlay-", "", $url->type))->where("userid", $user->id)->first()){
			return Gate::overlay($url, $overlay);
		}

		// Custom Splash Page
		if(is_numeric($url->type) && $splash = DB::splash()->where('id', $url->type)->where('userid', $url->userid)->first()){
			return Gate::custom($url, $splash, $user);
		}

		if($hasMedia && $media = $this->isMedia($url->url)){
			return Gate::media($url, $media, $user);
		}

		// Check redirect method
		if(config("frame") == "3" || $isPro){
			if(empty($url->type)){

				return Gate::direct($url, $user);

			}elseif(in_array($url->type, array("direct","frame","splash"))){

				$fn = $url->type;

				return Gate::$fn($url, $user);
			}
		}
		// Switch to a method
		$methods = array("0" => "direct", "1" => "frame", "2" => "splash", "3" =>  "splash");
		$fn = $methods[config("frame")];
		return Gate::$fn($url, $user);
    }
    /**
     * Capture Screenshot
     *
     * @author GemPixel <https://gempixel.com>
     * @version 6.0
     * @param string $alias
     * @return void
     */
    public function image(Request $request, string $alias){
		if(!$url = $this->getURL($request, $alias)){
			stop(404);
		}

		header("Cache-Control: max-age=31556926");
		header("Etag: ".md5($url->url));

		if($url->meta_image){
			header("Location: ".uploads('images/'.$url->meta_image));
			exit;
		}

		$lurl = urlencode($url->url);

		$list = [
			// "https://s.wordpress.com/mshots/v1/$lurl?w=800",
			// "https://api.pagepeeker.com/v2/thumbs.php?size=l&url=$lurl",
			// "https://api.miniature.io/?width=800&height=600&screen=1024&url=$lurl",
			"https://image.thum.io/get/width/600/crop/900/".urldecode($lurl)
		];

		$api_url = $list[array_rand($list, 1)];

		header("Location: $api_url");
		exit;
    }

    /**
     * Generate Favicon
     *
     * @author GemPixel <https://gempixel.com>
     * @version 6.0
     * @param string $alias
     * @return void
     */
    public function icon(int $id){

      	if(!$url = DB::url()->where('id', $id)->first()){
			header("Location: ".assets('images/unknown.svg'));
			exit;
		}

		if(!$url->url || empty($url->url)){
			header("Location: ".assets('images/unknown.svg'));
			exit;
		}

		if(!in_array(Helper::parseUrl($url->url, 'scheme'), ["http", "https"])){
			header("Location: ".assets('images/unknown.svg'));
			exit;
		}


		header("Cache-Control: max-age=31556926");
		header("Etag: ".md5($url->url));

      	$host = Helper::parseUrl($url->url, 'host');

		header("Location: ".str_replace('{{url}}', trim($host), self::ICOPATH));
		exit;
    }

    /**
     * Generate QR Code
     *
     * @author GemPixel <https://gempixel.com>
     * @version 6.7
     * @param string $alias
     * @return void
     */
    public function qr(Request $request, string $alias, int $size = 300, $action = "view"){

		if(!$url = $this->getURL($request, $alias)){
			stop(404);
		}

		$qrsize = 300;

		if(is_numeric($size) && $size > 50 && $size <= 1000) $qrsize = $size;

		$url = \Helpers\App::shortRoute($url->domain, $url->alias.$url->custom);

		return \Helpers\QR::factory($url, $qrsize)->color('rgb(0,0,0)', 'rgb(255,255,255)')->format('svg')->create();
    }
	/**
	 * Download QR
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.0
	 * @param \Core\Request $request
	 * @param string $alias
	 * @param string $format
	 * @param integer $size
	 * @return void
	 */
	public function qrDownload(Request $request, string $alias, string $format, int $size = 300){

		if(!$url = $this->getURL($request, $alias)){
			stop(404);
		}

		$qrsize = 300;

		if(is_numeric($size) && $size > 50 && $size <= 1000) $qrsize = $size;

		$url = \Helpers\App::shortRoute($url->domain, $url->alias.$url->custom);

		if(!config('imagemagick')){
			$qr = new \Helpers\QrGd($url, $qrsize, 0);
			$qr->format($format)->color('rgb(0,0,0)', 'rgb(255,255,255)');
		} else {
			$qr = \Helpers\QR::factory($url, $qrsize)->format($format)->color('rgb(0,0,0)', 'rgb(255,255,255)');
		}

		return \Core\File::contentDownload('QR-code-'.$alias.'.'.$qr->extension(), function() use ($qr) {
			return $qr->string();
		});
	}
	/**
	 * Delete Link
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.0
	 * @param integer $id
	 * @param string $nonce
	 * @return void
	 */
	public function delete(int $id, string $nonce){

		if(Auth::user()->teamPermission('links.delete') == false){
            return back()->with('danger', e('You do not have this permission. Please contact your team administrator.'));
        }

		if(!Helper::validateNonce($nonce, 'link.delete')){
            return Helper::redirect()->back()->with('danger', e('An unexpected error occurred. Please try again.'));
        }
		// @group Plugin
		Plugin::dispatch('link.delete', $id);

		if(!$this->deleteLink($id, Auth::user())){
			return Helper::redirect()->back()->with('danger', e('Link not found. Please try again.'));
		}

		return Helper::redirect()->back()->with('success', e('Link has been deleted.'));
	}
	/**
	 * Delete Many
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.0
	 * @param \Core\Request $request
	 * @return void
	 */
	public function deleteMany(Request $request){

		if(Auth::user()->teamPermission('links.delete') == false){
            return back()->with('danger', e('You do not have this permission. Please contact your team administrator.'));
        }

        $ids = json_decode($request->selected);

        if(!$ids || empty($ids)) return Helper::redirect()->back()->with('danger', e('No link was selected. Please try again.'));

        foreach($ids as $id){
			if(empty($id)) continue;
			// @group Plugin
			Plugin::dispatch('link.delete', $id);
            $this->deleteLink($id, Auth::user());
        }

        return Helper::redirect()->back()->with('success', e('Selected Links have been deleted.'));
	}
	/**
	 * Archive Selected
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.0
	 * @return void
	 */
	public function archiveSelected(Request $request){

		if(Auth::user()->teamPermission('links.edit') == false){
			return Response::factory(['error' => true, 'message' => e('You do not have this permission. Please contact your team administrator.')]);
        }

		if($request->link){
			DB::url()->where('id', $request->link)->where('userid', Auth::user()->rID())->update(['archived' => 1]);
			$ids[] = (int) $request->link;
		} else {
			$ids = json_decode(html_entity_decode($request->selected));
			if(!$ids){
				return Response::factory(['error' => true, 'message' => e('You need to select at least 1 link.')])->json();
			}
			foreach($ids as $id){
				DB::url()->where('id', $id)->where('userid', Auth::user()->rID())->update(['archived' => 1]);
			}
		}


		return Response::factory(['error' => false, 'message' => e('Selected links have been archived.'), 'html' => '<script>refreshlinks('.json_encode($ids).')</script>'])->json();
	}
	/**
	 * UnArchive Selected
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.0
	 * @param \Core\Request $request
	 * @return void
	 */
	public function unarchiveSelected(Request $request){

		if(Auth::user()->teamPermission('links.edit') == false){
			return Response::factory(['error' => true, 'message' => e('You do not have this permission. Please contact your team administrator.')]);
        }

		if($request->link){
			DB::url()->where('id', $request->link)->where('userid', Auth::user()->rID())->update(['archived' => 0]);
			$ids[] = (int) $request->link;
		} else {
			$ids = json_decode(html_entity_decode($request->selected));
			if(!$ids){
				return Response::factory(['error' => true, 'message' => e('You need to select at least 1 link.')])->json();
			}
			foreach($ids as $id){
				DB::url()->where('id', $id)->where('userid', Auth::user()->rID())->update(['archived' => 0]);
			}
		}

		return Response::factory(['error' => false, 'message' => e('Selected links have been removed from archive.'), 'html' => '<script>refreshlinks('.json_encode($ids).')</script>'])->json();
	}
	/**
	 * Public Selected
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.0
	 * @return void
	 */
	public function publicSelected(Request $request){

		if(Auth::user()->teamPermission('links.edit') == false){
			return Response::factory(['error' => true, 'message' => e('You do not have this permission. Please contact your team administrator.')]);
        }

		if($request->link){
			DB::url()->where('id', $request->link)->where('userid', Auth::user()->rID())->update(['public' => 1]);
			$ids[] = (int) $request->link;
		} else {
			$ids = json_decode(html_entity_decode($request->selected));
			if(!$ids){
				return Response::factory(['error' => true, 'message' => e('You need to select at least 1 link.')])->json();
			}
			foreach($ids as $id){
				DB::url()->where('id', $id)->where('userid', Auth::user()->rID())->update(['public' => 1]);
			}
		}


		return Response::factory(['error' => false, 'message' => e('Selected links have been set to public.'), 'html' => '<script>refreshlinks('.json_encode($ids).')</script>'])->json();
	}
	/**
	 * Private Selected
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.0
	 * @param \Core\Request $request
	 * @return void
	 */
	public function privateSelected(Request $request){

		if(Auth::user()->teamPermission('links.edit') == false){
			return Response::factory(['error' => true, 'message' => e('You do not have this permission. Please contact your team administrator.')]);
        }

		if($request->link){
			DB::url()->where('id', $request->link)->where('userid', Auth::user()->rID())->update(['public' => 0]);
			$ids[] = (int) $request->link;
		} else {
			$ids = json_decode(html_entity_decode($request->selected));
			if(!$ids){
				return Response::factory(['error' => true, 'message' => e('You need to select at least 1 link.')])->json();
			}
			foreach($ids as $id){
				DB::url()->where('id', $id)->where('userid', Auth::user()->rID())->update(['public' => 0]);
			}
		}

		return Response::factory(['error' => false, 'message' => e('Selected links have been set to private.'), 'html' => '<script>refreshlinks('.json_encode($ids).')</script>'])->json();
	}
	 /**
     * Edit Link
     *
     * @author GemPixel <https://gempixel.com>
     * @version 6.0
     * @param integer $id
     * @return void
     */
    public function edit(int $id){

		if(Auth::user()->teamPermission('links.edit') == false){
            return Helper::redirect()->to(route('links'))->with('danger', e('You do not have this permission. Please contact your team administrator.'));
        }

        if(!$url = DB::url()->where('id', $id)->where("userid",  \Core\Auth::user()->rID())->first()) return Helper::redirect()->back()->with('danger', e('Link does not exist.'));

        View::set('title', e('Update Link'));

		// @group Plugin
		Plugin::dispatch('link.edit', $url);

		$locations = [];
		if($url->location && $url->location != "null"){
			foreach(json_decode($url->location, true) as $country => $location){
				if(is_array($location)){
					foreach($location as $city => $data){
						$locations[$country.'|'.$city] = $data;
					}
				} else {
					$locations[$country] = $location;
				}
			}
		}

		$url->url = Helper::clean($url->url, 3);

		$url->devices = $url->devices && $url->devices != "null" ? json_decode($url->devices, true) : [];

		if($url->options && $url->options != "null"){
			$options = json_decode($url->options, true);
		}

		$url->languages = [];
		if(isset($options['languages'])){
			$url->languages = $options['languages'];
		}

		$url->rotators = [];
		if(isset($options['rotators'])){
			$url->rotators = $options['rotators'];
		}

		$url->clicklimit = $options['clicklimit'] ?? '';
		$url->expirationredirect = $options['expirationredirect'] ?? '';

		$url->parameters = $url->parameters && $url->parameters != "null" ? json_decode($url->parameters, true) : [];
		$url->pixels = $url->pixels && $url->pixels != "null" ? explode(',', $url->pixels) : [];
		$url->deeplink = $options['deeplink'] ?? [];

		$channels = [];

		foreach(DB::tochannels()->where('type', 'links')->where('itemid', $url->id)->findMany() as $channel){
			$channels[] = $channel->channelid;
		}

		View::push(assets('frontend/libs/clipboard/dist/clipboard.min.js'), 'js')->toFooter();
		View::push(assets('frontend/libs/autocomplete/jquery.autocomplete.min.js'), 'js')->toFooter();

        \Helpers\CDN::load('datetimepicker');

        return View::with('user.edit', compact('url', 'locations', 'channels'))->extend('layouts.dashboard');
    }
    /**
     * Update Link
     *
     * @author GemPixel <https://gempixel.com>
     * @version 6.0
     * @param \Core\Request $request
     * @param integer $id
     * @return void
     */
    public function update(Request $request, int $id){

        \Gem::addMiddleware('DemoProtect');

		if(Auth::user()->teamPermission('links.edit') == false){
            return back()->with('danger', e('You do not have this permission. Please contact your team administrator.'));
        }

        if(!$url = DB::url()->where('id', $id)->where("userid", \Core\Auth::user()->rID())->first()) return Helper::redirect()->back()->with('danger', e('URL does not exist.'));

		// @group Plugin
		Plugin::dispatch('link.edit', $url);

		if($image = $request->file('metaimage')){
			$request->metaimage = $image;
		}

        try{

			$this->updateLink($request, $url, \Core\Auth::user());

			$channels = [];

			if(is_null($request->channels)) $request->channels = [];

			foreach(DB::tochannels()->where('type', 'links')->where('itemid', $url->id)->findMany() as $channel){
				if(!in_array($channel->id, $request->channels)){
					$channel->delete();
				}
			}

			foreach($request->channels as $channel){
				if(!DB::tochannels()->where('type', 'links')->where('itemid', $url->id)->where('channelid', $channel)->first()){
					$tochannel = DB::tochannels()->create();

					$tochannel->userid = user()->rID();
					$tochannel->channelid = $channel;
					$tochannel->itemid = $url->id;
					$tochannel->type = 'links';
					$tochannel->save();
				}
			}

        } catch(\Exception $e){
            return Helper::redirect()->back()->with('danger', $e->getMessage());
        }

        return Helper::redirect()->back()->with('success', e('Link has been updated successfully.'));
    }
	/**
	 * Add to campaign
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.0
	 * @param \Core\Request $request
	 * @return void
	 */
	public function addtocampaign(Request $request){

		if(!is_numeric($request->campaigns)) return Response::factory(['error' => true, 'message' => e('Invalid campaign. Please choose a valid campaign.'), 'token' => csrf_token()])->json();

		$campaignid = 0;

		if($campaign = DB::bundle()->where('id', $request->campaigns)->where('userid', Auth::user()->rID())->first()){
			$campaignid = $campaign->id;
		}

		$ids = json_decode(html_entity_decode($request->bundleids));

		if(!$ids){
			return Response::factory(['error' => true, 'message' => e('You need to select at least 1 link.'), 'token' => csrf_token()])->json();
		}

		foreach($ids as $id){
			DB::url()->where('id', $id)->where('userid', Auth::user()->rID())->update(['bundle' => $campaignid]);
		}

		return Response::factory(['error' => false, 'message' => $campaignid ? e('Selected links have been added to the {c} campaign.', null, ['c' => $campaign->name]) : e('Selected links have been removed from campaigns.'), 'token' => csrf_token(), 'html' => '<script>refreshlinks('.json_encode($ids).')</script>'])->json();

	}
	/**
	 * Bookmark
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.0
	 * @param \Core\Request $request
	 * @return void
	 */
	public function bookmark(Request $request){

		if(_STATE == 'DEMO') return Response::factory(["error" => 1, "msg" => "This has been disabled in demo."])->json();

		if(!$user = \Models\User::whereRaw('MD5(api) = ?', clean($request->token))->first()){
            return Response::factory(clean($request->callback).'('.json_encode(['error' => 1, 'msg' => 'Invalid request. Please update bookmarklet.']).')')->send();
        }

		try{
			$link = $this->createLink($request, $user);
		} catch(\Exception $e){
			return Response::factory(clean($request->callback).'('.json_encode(['error' => 1, 'msg' => $e->getMessage()]).')')->send();
		}

		return Response::factory(clean($request->callback).'('.json_encode(['error' => 0, 'short' => $link['shorturl']]).')')->send();
	}
	/**
	 * Script Js
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.0
	 * @param \Core\Request $request
	 * @return void
	 */
	public function scriptjs(Request $request){

		if(_STATE == 'DEMO') return Response::factory(["error" => 1, "msg" => "This has been disabled in demo."])->json();

		header("Content-type: text/javascript");
		ob_start(function($content) { return str_replace(array("\r\n", "\r", "\n", "\t", '  ', '    ', '    '), '', $content); });

		$js = file_get_contents(STORAGE."/app/jShortener.js");
		$js = str_replace("__URL__", config('url'), $js);

		echo $js;
		ob_end_flush();
	}

	/**
     * Full Page Script
     *
     * @author GemPixel <https://gempixel.com>
     * @version 6.0
     * @param \Core\Request $request
     * @return void
     */
    public function fullpage(Request $request){

		if(!$request->key || !$request->url) return Response::factory(['error' => 1, 'message' => 'Invalid Request. Please try again.'])->json();

        if(!$user = \Models\User::whereRaw('MD5(api) = ?', clean($request->key))->first()){
            return Response::factory(['error' => 1, 'message' => 'Invalid Request. Please try again.'])->json();
        }

        try{
			$request->url = urldecode($request->url);
			$link = $this->createLink($request, $user);
		} catch(\Exception $e){
			return Response::factory(clean($request->callback).'('.json_encode(['error' => 1, 'msg' => $e->getMessage()]).')')->send();
		}
        return Response::factory(clean($request->callback).'('.json_encode(['error' => 0, 'short' => $link['shorturl']]).')')->send();
    }
	/**
	 * Quick Shortening
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.3.3
	 * @param \Core\Request $request
	 * @return void
	 */
	public function quick(Request $request){

		if(_STATE == 'DEMO') return  Helper::redirect()->to(route('home'))->with('danger', e("This has been disabled in demo."));

		if(!Auth::logged()) return  Helper::redirect()->to(route('login'))->with('danger', e("You need to be logged in to use this feature."));

		$user = Auth::logged() ? Auth::user() : null;

		$request->url = $request->u;

		try{
			$link = $this->createLink($request, $user);
		} catch(\Exception $e){
			return Helper::redirect()->to(route('dashboard'))->with('danger', $e->getMessage());
		}

		return Helper::redirect()->to($request->u);
	}
	/**
	 * Not Found
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.0
	 * @param \Core\Request $request
	 * @return void
	 */
	protected function notFound(Request $request){

		$currenturi = trim(str_replace($request->path(), '', $request->uri(false)), '/');

        if(config('url') != $currenturi){

            $host = \idn_to_utf8(Helper::parseUrl($request->host(), 'host'));

            if($domain = \Core\DB::domains()->whereRaw("domain = ? OR domain = ?", ["http://".$host,"https://".$host])->first()){
                if($domain->redirect404){
                    header("Location: {$domain->redirect404}");
                    exit;
                }
            }
		}

		return stop(404);
	}
	/**
	 * Redirect Rotator
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.0
	 * @return void
	 */
	public function campaign($alias){

		if(!$bundle = DB::bundle()->where('slug', clean($alias))->first()){
			stop(404);
		}

		if($bundle->access == "private") stop(404);

		if(!$url = DB::url()->where('bundle', $bundle->id)->orderByExpr('RAND()')->first()){
			stop(404);
		}

		$bundle->view++;
		$bundle->save();

		return Helper::redirect()->to(\Helpers\App::shortRoute($url->domain, $url->alias.$url->custom));
	}
	/**
	 * Campaign List
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.0
	 * @param string $username
	 * @param string $alias
	 * @return void
	 */
	public function campaignList(string $username, string $alias){

		if(!$user = \Models\User::where("username", clean($username))->first()){
            stop(404);
        }

		if($user->banned) {
			return Gate::disabled();
		}

        if(!$user->public || !$user->defaultbio) stop(404);

        if(!$profile = DB::profiles()->where('id', $user->defaultbio)->first()){
            stop(404);
		}
		$id = explode('-', clean($alias));

		if(!$bundle = DB::bundle()->where('userid', $user->id)->where('id', end($id))->first()){
			stop(404);
		}

		if($bundle->access == "private") stop(404);

		$bundle->view++;
		$bundle->save();

		return \Helpers\Gate::bundle($profile, $bundle, $user);
	}
	/**
	 * User Profile
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.4.1
	 * @param \Core\Request $request
	 * @param string $username
	 * @return void
	 */
	public function profile(Request $request, string $username){
        if(!$user = \Models\User::where("username", clean($username))->first()){
            stop(404);
        }

		if($user->banned) {
			return Gate::disabled();
		}

        if(!$user->public || !$user->defaultbio) {

			if(Auth::logged() && Auth::user()->rID() == $user->id) return Helper::redirect()->to(route('settings'))->with('warning', e('You have to make your profile public or set a default bio for this page to be accessible.'));

			stop(404);
		}


        if(!$profile = DB::profiles()->where('id', $user->defaultbio)->first()){
            stop(404);
		}

        if(!$url = DB::url()->first($profile->urlid)){
			stop(404);
		}

        $this->updateStats($request, $url, null);
        return \Helpers\Gate::profile($profile, $user);
    }

	/**
	 * Reset Stats
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 6.1.6
	 * @param integer $id
	 * @param string $nonce
	 * @return void
	 */
	public function reset(int $id, string $nonce){

		if(!Helper::validateNonce($nonce, 'link.reset')){
            return Helper::redirect()->back()->with('danger', e('An unexpected error occurred. Please try again.'));
        }

		$user = Auth::user();

		if(!$url = DB::url()->where('id', $id)->where("userid",  $user->rID())->first()) return Helper::redirect()->back()->with('danger', e('Link does not exist.'));

		DB::stats()->where('urlid', $url->id)->deleteMany();

		$url->click = 0;
		$url->uniqueclick = 0;

		$url->save();

		return Helper::redirect()->back()->with('success', e('Statistics have been successfully reset.'));

	}
	/**
	 * Verify Links
	 *
	 * @author GemPixel <https://gempixel.com>
	 * @version 7.5
	 * @return void
	 */
	public function verify(Request $request){

		if($request->isPost()){

			$baseurl = explode('?', $request->url)[0];

			$url = parse_url($baseurl);

			if(!isset($url['path'])) return back()->with('danger', e('Link not found. Please try again.'));

			$path = explode('/', $url['path']);

			$alias = end($path);

			$domain = str_replace('/'.$alias, '', $baseurl);

			$alias = str_replace('&amp;', '&', $alias);

			$domain = str_replace(["http://", "https://"], "", $domain);

			$domain = idn_to_utf8($domain);

			if("http://".$domain == config("url") || "https://".$domain == config("url")){
				$url = DB::url()->whereRaw("(alias = BINARY :id OR custom = BINARY :id) AND (domain LIKE :domain OR domain IS NULL OR domain = '')", [':id' => $alias, ':domain' => "%{$domain}"])->first();
			}else{
				$url = DB::url()->whereRaw("(alias = BINARY :id OR custom = BINARY :id) AND domain LIKE :domain", [':id' => $alias, ':domain' => "%{$domain}"])->first();
			}

			if(!$url){
				return back()->with('danger', e('Link not found. Please try again.'));;
			}

			return back()->with('success', e('Your final destination is: {u}', null, ['u' => $url->url]));
		}

		if(!config('verifylink')) stop(404);

        Plugin::dispatch('verifylink');

		View::set('title', e('Verify Short Link'));

        return View::with('verifylink', compact('request'))->extend('layouts.main');
	}

	/**
	 * Browser Geolocation Consent Callback
	 *
	 * Receives visitor geolocation coordinates upon explicit consent,
	 * verifies scan-bound session token, stat_id, url_id, client IP, and one-time use,
	 * resolves administrative boundaries without storing raw GPS coordinates,
	 * and updates the visitor's own stats record with source='browser_consent'.
	 *
	 * @param \Core\Request $request
	 * @param string $alias
	 * @return \Core\Response
	 */
	public function geoConsent(Request $request, string $alias){
		if (!$request->isPost()) {
			return (new Response(['error' => true, 'message' => 'Method not allowed'], 405))->json();
		}

		$alias = clean($alias);
		if (!$url = DB::url()->whereRaw('(alias = :id OR custom = :id)', [':id' => $alias])->first()) {
			return (new Response(['error' => true, 'message' => 'Link not found'], 404))->json();
		}

		// 1. Verify that geoconsent is explicitly enabled for this link
		$options = !empty($url->options) ? json_decode($url->options, true) : [];
		if (empty($options['geoconsent'])) {
			return (new Response(['error' => true, 'message' => 'Geo consent is not enabled for this link'], 403))->json();
		}

		// 2. Enforce one-time use (anti-replay)
		if (!empty($request->session("geoconsent_used_{$url->id}"))) {
			return (new Response(['error' => true, 'message' => 'Consent already processed for this scan'], 409))->json();
		}

		// 3. Verify scan-bound session state and cryptographic nonce
		$statId = $request->session("last_stat_{$url->id}");
		$expectedNonce = $request->session("geoconsent_nonce_{$url->id}");

		$rawBody = $request->getBody();
		$body = is_string($rawBody) && $rawBody !== '' ? (json_decode($rawBody, true) ?? []) : [];
		if (empty($body)) {
			$body = $request->all(true);
		}
		$providedNonce = isset($body['consent_token']) ? (string) $body['consent_token'] : (string) ($request->consent_token ?? '');

		if (empty($statId) || empty($expectedNonce) || $providedNonce === '' || !hash_equals((string) $expectedNonce, $providedNonce)) {
			return (new Response(['error' => true, 'message' => 'Invalid or expired scan session token'], 403))->json();
		}

		// 4. Strictly verify the target stats record belongs to this link AND this visitor's IP
		$stat = DB::stats()->where('id', $statId)->first();
		$clientIp = appConfig('haship') ? md5((defined('AuthToken') ? AuthToken : '') . $request->ip()) : $request->ip();

		if (!$stat || (int) $stat->urlid !== (int) $url->id || (string) $stat->ip !== (string) $clientIp) {
			return (new Response(['error' => true, 'message' => 'Scan verification failed'], 403))->json();
		}

		// 5. Consume token immediately (one-time use)
		$request->session("geoconsent_used_{$url->id}", 1);
		$request->session("geoconsent_{$url->id}", 1);
		$request->unset("geoconsent_nonce_{$url->id}");

		// 6. If visitor declined/skipped coordinates, redirect cleanly without modifying stats
		if (!array_key_exists('latitude', $body) && !array_key_exists('longitude', $body)) {
			return (new Response(['success' => true, 'redirect' => $url->url]))->json();
		}

		$lat = isset($body['latitude']) ? filter_var($body['latitude'], FILTER_VALIDATE_FLOAT) : false;
		$lon = isset($body['longitude']) ? filter_var($body['longitude'], FILTER_VALIDATE_FLOAT) : false;
		$accuracy = (isset($body['accuracy']) && $body['accuracy'] !== null && $body['accuracy'] !== '')
			? filter_var($body['accuracy'], FILTER_VALIDATE_FLOAT)
			: null;

		if ($lat === false || $lon === false || !is_finite((float) $lat) || !is_finite((float) $lon)) {
			return (new Response(['error' => true, 'message' => 'Invalid coordinates format'], 422))->json();
		}

		if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
			return (new Response(['error' => true, 'message' => 'Invalid coordinates range'], 422))->json();
		}

		if ($accuracy === false || ($accuracy !== null && (!is_finite((float) $accuracy) || $accuracy <= 0.0 || $accuracy > 1000000.0))) {
			return (new Response(['error' => true, 'message' => 'Invalid accuracy value'], 422))->json();
		}

		// 7. Resolve administrative location (never store raw GPS coordinates)
		$resolved = class_exists('\Helpers\GeoLocation')
			? \Helpers\GeoLocation::resolveCoordinates((float) $lat, (float) $lon, $accuracy !== null ? (float) $accuracy : null)
			: ['country' => null, 'region' => null, 'city' => null, 'accuracy_radius' => null, 'declared_accuracy_meters' => null, 'location_level' => 'unknown', 'precision' => 'unknown', 'source' => 'browser_consent'];

		// If the external reverse geocoder is unavailable/disabled or coordinate resolution returned unknown,
		// preserve the existing MaxMind IP geolocation on $stat rather than overwriting with null, and redirect cleanly.
		if (empty($resolved['country']) || ($resolved['location_level'] ?? 'unknown') === 'unknown') {
			return (new Response([
				'success'        => true,
				'geo_updated'    => false,
				'location_level' => 'unknown',
				'missing_reason' => $resolved['missing_reason'] ?? 'reverse_geocoder_unavailable',
				'redirect'       => $url->url,
			]))->json();
		}

		$stat->country = $resolved['country'];
		$stat->city = !empty($resolved['city']) ? $resolved['city'] : null;

		if (class_exists('\Helpers\GeoLocation') && \Helpers\GeoLocation::hasStatsColumn('region')) {
			$stat->region = !empty($resolved['region']) ? $resolved['region'] : null;
			$stat->geo_source = 'browser_consent';
			$stat->accuracy_radius = (isset($resolved['accuracy_radius']) && $resolved['accuracy_radius'] !== null)
				? (int) ceil((float) $resolved['accuracy_radius'])
				: null;
		}

		$stat->save();

		return (new Response([
			'success'        => true,
			'geo_updated'    => true,
			'location_level' => $resolved['location_level'] ?? $resolved['precision'] ?? 'country',
			'redirect'       => $url->url,
		]))->json();
	}
}