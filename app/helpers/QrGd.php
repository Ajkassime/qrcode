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

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelLow;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelQuartile;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelMedium;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;
use Endroid\QrCode\Writer\EpsWriter;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\PdfWriter;
use Endroid\QrCode\Writer\SvgWriter;

class QRGd {
    /**
     * Instance of the writer
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.0
     */
    private $writer = null;
    /**
     * Add Logo
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.0
     */
    private $logo = null;
    /**
     * Get Extension
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.0
     */
    private $extension = null;    
    /**
     * Instance of the QR
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.0
     */
    private $QR = null;    

    /**
     * QR code holder
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 7.0.3
     */
    private $qrCode = null;

    /**
     * Generate QR Code
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.0
     */
    public function __construct($data, $size = 200, $margin = 10){
        
        $this->qrCode = QrCode::create($data)
                        ->setEncoding(new Encoding('UTF-8'))
                        ->setSize($size)
                        ->setMargin($margin)
                        ->setRoundBlockSizeMode(new RoundBlockSizeModeMargin());

        return $this;
        
    }   
    /**
     * Add Logo
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.0
     * @param [type] $path
     * @param integer $size
     * @return void
     */
    public function withLogo($path, $size = 50){
        $this->logo = Logo::create($path)
                    ->setResizeToWidth($size);
        return $this;
    }
    /**
     * Error correction
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.7
     * @param string $level
     * @return void
     */
    public function errorCorrection($level = 'm'){

        $errorLevels = [
            'l' => ErrorCorrectionLevelLow::class,
            'm' => ErrorCorrectionLevelMedium::class,
            'q' => ErrorCorrectionLevelQuartile::class,
            'h' => ErrorCorrectionLevelHigh::class
        ];
    
        $this->qrCode->setErrorCorrectionLevel(new $errorLevels[$level]());
        
        return $this;
    }
    /**
     * Create a QR Code format
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.0
     * @return void
     */
    public function format($format = 'png'){
        
        if($format == 'pdf'){
            $this->writer = new PdfWriter();
            $this->extension = "pdf";
        } elseif($format == 'svg'){        
            $this->writer = new SvgWriter();
            $this->extension = "svg";
        }elseif($format == 'eps'){        
            $this->writer = new EpsWriter();
            $this->extension = "eps";
        } else {
            $this->writer = new PngWriter();
            $this->extension = "png";    
        }

        return $this;
    }
    /**
     * Set Background and Foreground color
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.0
     * @param array|string $fg
     * @param array|string $bg
     * @param string|null $frame
     * @param string|null $eye
     * @return $this
     */
    public function color($fg, $bg, $frame = null, $eye = null){

        \preg_match('|rgb\((.*)\)|', (string) $fg, $color);
        if(isset($color[1])){
            $fgColor = \explode(',', $color[1]);
        } else {
            $fgColor = [0,0,0];
        }

        \preg_match('|rgb\((.*)\)|', (string) $bg, $color);
        if(isset($color[1])){
            $bgColor = \explode(',', $color[1]);
        } else {
            $bgColor = [255,255,255];
        }

        $this->qrCode->setForegroundColor(new Color((int) $fgColor[0], (int) $fgColor[1], (int) $fgColor[2]))
                     ->setBackgroundColor(new Color((int) $bgColor[0], (int) $bgColor[1], (int) $bgColor[2]));

        return $this;
    }
    /**
     * Gradient fallback
     *
     * @param mixed $fg
     * @param mixed $bg
     * @param string $type
     * @param string|null $frame
     * @param string|null $eye
     * @return $this
     */
    public function gradient($fg, $bg, $type = 'vertical', $frame = null, $eye = null){
        $primaryFg = is_array($fg) ? ($fg[0] ?? 'rgb(0,0,0)') : $fg;
        return $this->color($primaryFg, $bg, $frame, $eye);
    }
    /**
     * Matrix module compatibility stub
     *
     * @param string $matrix
     * @return $this
     */
    public function module($matrix = null){
        return $this;
    }
    /**
     * Eye compatibility stub
     *
     * @param string $eye
     * @param string $eyeFrame
     * @return $this
     */
    public function eye($eye = null, $eyeFrame = 'square'){
        return $this;
    }
    /**
     * Frame compatibility stub
     *
     * @param array $frame
     * @return $this
     */
    public function withFrame($frame = null){
        return $this;
    }
    /**
     * Punched logo compatibility stub
     *
     * @return $this
     */
    public function isPunched(){
        return $this;
    }
    /**
     * Get MimeType
     *
     * @return string
     */
    public function getMimeType(){
        $mimes = [
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'eps' => 'application/postscript',
        ];
        return $mimes[$this->extension ?? 'png'] ?? 'image/png';
    }
    /**
     * Return QR as string without terminating execution
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.0
     * @return string
     */
    public function string(){
        if(!$this->writer){
            $this->format($this->extension ?? 'png');
        }
        $result = $this->writer->write($this->qrCode, $this->logo);
        return $result->getString();
    }
    /**
     * Return extension
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.0
     * @return string
     */
    public function extension(){    
        return $this->extension ?? 'png';
    }
    /**
     * Generate QR
     *
     * @author GemPixel <https://gempixel.com> 
     * @version 6.7
     * @return mixed
     */
    public function create($output = 'raw', $file = null){

        if(!$this->writer){
            $this->format($this->extension ?? 'png');
        }

        $result = $this->writer->write($this->qrCode, $this->logo);

        if($output == 'file'){
            if(config('cdn') && config('cdn')->enabled){
                return \Helpers\CDN::factory()->uploadRaw(str_replace(PUB.'/', '', $file), $result->getString(), $result->getMimeType());                
            }
            return $result->saveToFile($file);
        }
        
        if($output == 'uri'){
            return $result->getDataUri();            
        }

        if($output == 'raw'){
            return $result->getString();
        }

        if(!headers_sent()){
            header('Content-Type: '.$result->getMimeType());
        }
        echo $result->getString();
        exit;
    }    

}