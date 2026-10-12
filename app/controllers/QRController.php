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
use Core\Auth;
use Core\Helper;
use Core\View;
use Models\User;
use Helpers\CDN;

class QR {

    /**
     * Generate QR
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 7.1
     * @param string $alias
     * @return void
     */
    public function generate(string $alias){

        if(!$qr = DB::qrs()->where('alias', $alias)->first()){
            die();
        }

        $user = \Models\User::where('id', $qr->userid)->first();
        
        $qr->data = json_decode($qr->data);

        if($qr->urlid && $url = DB::url()->where('id', $qr->urlid)->first()){        
            $data = ['type' => 'link', 'data' =>  \Helpers\App::shortRoute($url->domain, $url->alias.$url->custom)];
        } else {        
            $data = ['type' => $qr->data->type, 'data' => $qr->data->data];
        }
        $size = 1000;
        try {

            if($qr->filename){
                $exists = false;
                if(config('cdn') && config('cdn')->enabled){
                    $exists = CDN::factory()->get(str_replace(PUB.'/', '', View::storage($qr->filename, 'qr')));
                } else{
                    $exists = file_exists(View::storage($qr->filename, 'qr'));
                }

                if($exists) return Helper::redirect()->to(uploads($qr->filename, 'qr'));
            }
            
            $margin = isset($qr->data->margin) && is_numeric($qr->data->margin) && $qr->data->margin <= 10 ? $qr->data->margin : 0;

            $data = \Helpers\QR::factory($data, $size, $margin)->format('svg');

            $data = \Helpers\QR::applyDesign($data, $qr->data, $user, $size, $margin);

            $qr->filename = $qr->alias.time().\Core\Helper::rand(6).'.svg';
            $qr->data = json_encode($qr->data);
            $qr->save();

            $data->create('file', appConfig('app.storage')['qr']['path'].'/'.$qr->filename);
            
            $data->create('raw');

        } catch(\Exception $e){
            return \Core\Response::factory($e->getMessage())->send();
        }
    }

    /**
	 * Download QR
	 *
	 * @author GemPixel <https://gempixel.com> 
	 * @version 7.1
	 * @param \Core\Request $request
	 * @param string $alias
	 * @param string $format
	 * @param integer $size
	 * @return void
	 */
	public function download(Request $request, string $alias, string $format, int $size = 300){
		
        if(!$qr = DB::qrs()->where('alias', $alias)->first()){
            stop(404);
        }
        
        $user = \Models\User::where('id', $qr->userid)->first();

        $qr->data = json_decode($qr->data);
        
        if($qr->urlid && $url = DB::url()->where('id', $qr->urlid)->first()){        
            $data = ['type' => 'link', 'data' =>  \Helpers\App::shortRoute($url->domain, $url->alias.$url->custom)];
        } else {        
            $data = ['type' => $qr->data->type, 'data' => $qr->data->data];
        }
		
        $qrsize = 300;

        $margin = isset($qr->data->margin) && is_numeric($qr->data->margin) && $qr->data->margin <= 10 ? $qr->data->margin : 0;

		if(is_numeric($size) && $size > 50 && $size <= 1000) $qrsize = $size;
		
		$data = \Helpers\QR::factory($data, $qrsize, $margin)->format($format);

        $data = \Helpers\QR::applyDesign($data, $qr->data, $user, $qrsize, $margin);
        
		return \Core\File::contentDownload('QR-'.$qr->name.'-'.$alias.'.'.$data->extension(), function() use ($data) {
			return $data->string();
		});
	}
    /**
	 * Generate QR Code
	 *
	 * @author GemPixel <https://gempixel.com> 
	 * @version 7.3
	 * @param \Core\Request $request
	 * @return void
	 */
    public function generateqr(Request $request){
        
        if(!config('publicqr')) stop();

        if(!$request->type) return Response::factory(['error' => true, 'message' => e('Invalid request. Please try again'), 'token' => csrf_token()])->json();

        if(!in_array($request->type, ['text', 'link', 'email', 'sms', 'phone', 'wifi', 'staticvcard', 'event'])) return Response::factory(['error' => true, 'message' => e('Invalid request. Please try again'), 'token' => csrf_token()])->json();

        try{           

            $data = \Helpers\QR::factory($request, 1000, 0)->format('svg');

            $rgbbg = sscanf($request->bg, "#%02x%02x%02x");
            $rgbfg = sscanf($request->fg, "#%02x%02x%02x");

            $bg = $rgbbg ? 'rgb('.implode(',', $rgbbg).')' : 'rgb(255,255,255)';
            $fg = $rgbfg ? 'rgb('.implode(',', $rgbfg).')' : 'rgb(0,0,0)';

            $data->color($fg, $bg, null, null);

            if(config('qrlogo')){
                $data->withLogo(PUB.'/content/'.config('qrlogo'), 250);
            }
            $qr = $data->create('uri');

        } catch(\Exception $e){
            return Response::factory(['error' => true, 'message' => $e->getMessage(), 'token' => csrf_token()])->json();
        }

        $response = '<img src="'.$qr.'" class="img-responsive img-fluid rounded">';

        return Response::factory(['error' => false, 'message' => e('QR code successfully generated'), 'html' => $response, 'token' => csrf_token()])->json();
    }
}