<?php
/**
 * =======================================================================================
 *                           GemFramework (c) GemPixel                                     
 * ---------------------------------------------------------------------------------------
 *  This software is packaged with an exclusive framework as such distribution
 *  or modification of this framework is not allowed before prior consent from
 *  GemPixel. If you find that this framework is packaged in a software not distributed 
 *  by GemPixel or authorized parties, you must not use this software and contact gempixel
 *  at https://gempixel.com/contact to inform them of this misuse otherwise you risk
 *  of being prosecuted in courts.
 * =======================================================================================
 *
 * @package GemPixel\Premium_URL_Shortener
 * @author GemPixel (https://gempixel.com)
 * @copyright 2023 GemPixel
 * @license https://gempixel.com/license
 * @link https://gempixel.com  
 */
  
  // Database Configuration
  define('DBhost', 'localhost');      // Your mySQL Host (usually Localhost)
  define('DBname', 'database_name');  // The database name where the data will be stored
  define('DBuser', 'database_user');  // Your mySQL username
  define('DBpassword', 'password');   // Your mySQL Password 
  define('DBprefix', 'innovstu_');    // Prefix for your tables if you are using same db for multiple scripts

  define('DBport', 3306);

  // This is your base path. If you have installed this script in a folder, add the folder's name here. e.g. /folderName/
  define('BASEPATH', 'AUTO');

  // Use CDN to host libraries for faster loading
  define('USECDN', true);    

  // CDN URL to your assets
  define('CDNASSETS', null);
  define('CDNUPLOADS', null);

  // If FORCEURL is set to false, the software will accept any domain name that resolves to the server otherwise it will force settings url
  define('FORCEURL', true);

  // Your Server's Timezone - List of available timezones (Pick the closest): https://php.net/manual/en/timezones.php  
  define('TIMEZONE', 'Indian/Antananarivo'); 

  // Cache Data - If you notice anomalies, disable this. You should enable this when you get high hits
  define('CACHE', true);  

  // Do not enable this if your site is live or has many visitors
  define('DEBUG', 0);

  /************************************************************************************
   ====================================================================================
   * Security Tokens
   ====================================================================================
   ***********************************************************************************/

  define('AuthToken', 'YOUR_AUTH_TOKEN');
  define('EncryptionToken', 'YOUR_ENCRYPTION_TOKEN');
  define('PublicToken', 'YOUR_PUBLIC_TOKEN');
