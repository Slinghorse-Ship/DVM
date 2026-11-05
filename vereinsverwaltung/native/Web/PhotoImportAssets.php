<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

/** Locally packaged photo decoders, loaded only for a selected HEIC/RAW file. */
final class PhotoImportAssets
{
    public static function scripts():array
    {
        $root=dirname(__DIR__,2).'/public/';
        return ['photo-import.js'=>$root.'photo-import.js','photo-import.css'=>$root.'photo-import.css','photo-editor.js'=>$root.'photo-editor.js','photo-editor.css'=>$root.'photo-editor.css','photo-copy.js'=>$root.'photo-copy.js','photo-library.js'=>$root.'photo-library.js'];
    }
    public static function libraries():array
    {
        $root=dirname(__DIR__,2).'/resources/photo-import/';
        return ['photo-import-worker.js'=>__DIR__.'/assets/photo-import-worker.js','photo-raw-preview.mjs'=>__DIR__.'/assets/photo-raw-preview.mjs']+array_combine(
            ['photo-libraw.mjs','photo-libraw-worker.js','photo-libraw.wasm','photo-libheif.js'],
            array_map(static fn($name)=>$root.$name,['photo-libraw.mjs','photo-libraw-worker.js','photo-libraw.wasm','photo-libheif.js'])
        );
    }
    public static function serve(string $name):bool
    {
        $file=(self::scripts()+self::libraries())[$name]??null;if($file===null)return false;
        $method=$_SERVER['REQUEST_METHOD']??'GET';if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');return true;}
        // Embind creates trusted native-call wrappers in the decoder worker only.
        // The application keeps its strict script policy; these workers have no DOM,
        // cannot create child workers, and load only local, pinned decoder assets.
        if(in_array($name,['photo-import-worker.js','photo-libraw-worker.js'],true)){
            $evaluation=$name==='photo-libraw-worker.js'?"'unsafe-eval'":"'wasm-unsafe-eval'";
            header("Content-Security-Policy: default-src 'none'; script-src 'self' $evaluation; connect-src 'self'; worker-src 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
        }
        $mime=str_ends_with($name,'.wasm')?'application/wasm':(str_ends_with($name,'.css')?'text/css; charset=utf-8':'text/javascript; charset=utf-8');
        return AssetResponse::send($file,$mime);
    }
}
