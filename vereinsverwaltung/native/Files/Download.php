<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

/** All general files remain attachments, regardless of filename or detected format. */
final class Download
{
    public static function headers(array $file,bool $public=false):array
    {
        $name=str_replace(["\r","\n",'"','\\'],['_','_','_','_'],$file['name']);$ascii=preg_replace('/[^A-Za-z0-9._ -]/','_',$name);
        return ['Content-Type'=>'application/octet-stream','Content-Length'=>(string)$file['bytes'],'Content-Disposition'=>'attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($name),'X-Content-Type-Options'=>'nosniff','Content-Security-Policy'=>"default-src 'none'; sandbox",'Cache-Control'=>$public?'public, max-age=0, must-revalidate':'private, no-store','ETag'=>'"'.$file['sha256'].'"'];
    }
}
