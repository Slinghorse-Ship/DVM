<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Files\{Download,FileReferences};
use Kulturhus\Native\Shared\Access;

/** General downloads use their exact published source or an authenticated private reader. */
final class FileRoutes
{
    public static function serve(PDO $db,Access $access,string $mediaRoot,string $path,string $method,bool $public=true):bool
    {
        $prefix=$public?'/file/':'/file-preview/';if(!str_starts_with($path,$prefix))return false;
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');return true;}
        $file=null;if(preg_match('~^'.preg_quote($prefix,'~').'([a-f0-9]{32})$~D',$path,$match))try{
            $service=new FileReferences($db,$access,dirname($mediaRoot).'/files',dirname($mediaRoot).'/website-documents',$mediaRoot);$file=$public?$service->publicFile($match[1]):$service->file($match[1]);
        }catch(DomainException|InvalidArgumentException){}
        self::respond($file,$method,$public);return true;
    }
    public static function respond(?array $file,string $method,bool $public=false):void
    {
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');return;}
        if($file===null){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');if($method!=='HEAD')echo'Datei nicht verfügbar.';return;}
        foreach(Download::headers($file,$public)as$name=>$value)header($name.': '.$value);header('X-Robots-Tag: noindex, nofollow');if($method!=='HEAD')readfile($file['path']);
    }
}
