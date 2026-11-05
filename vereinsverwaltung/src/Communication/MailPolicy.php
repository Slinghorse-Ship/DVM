<?php declare(strict_types=1);
namespace Kulturhus\Verein;
final class MailPolicy
{
    public static function allowed(string $publicUrl):bool
    {
        if(getenv('KULTURHUS_LOCAL_DEMO')==='1'||getenv('KULTURHUS_DISABLE_MAIL')==='1'||getenv('VEREIN_MODE')==='synthetic'||in_array(getenv('APP_ENV'),['local','local-synthetic','development','test'],true))return false;
        foreach([$publicUrl,(string)getenv('APP_URL')] as $url){if($url==='')continue;$host=strtolower((string)parse_url($url,PHP_URL_HOST));if($host===''||$host==='localhost'||str_ends_with($host,'.localhost')||filter_var(trim($host,'[]'),FILTER_VALIDATE_IP)||parse_url($url,PHP_URL_USER)!==null)return false;}
        return parse_url($publicUrl,PHP_URL_SCHEME)==='https';
    }
}
