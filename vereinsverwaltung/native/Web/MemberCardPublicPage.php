<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use PDO;
use Kulturhus\Verein\{Database,MemberCards};
use Kulturhus\Native\Language\MemberCardMessages;

/** Anonymous token check: no login, consumption, analytics or recipient/contact lookup output. */
final class MemberCardPublicPage
{
    public static function serve(PDO $db,string $nativeClub):bool
    {
        if(rtrim((string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH),'/')!=='/mitgliederausweis/pruefen')return false;
        require_once dirname(__DIR__,2).'/src/autoload.php';
        $method=$_SERVER['REQUEST_METHOD']??'GET';header('Cache-Control: no-store, private, max-age=0');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
        $nonce=base64_encode(random_bytes(18));header("Content-Security-Policy: default-src 'none'; style-src 'nonce-".$nonce."'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");header('Content-Type: text/html; charset=utf-8');
        $locale=str_starts_with(strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE']??''),'en')?'en':'de';$messages=MemberCardMessages::messages($locale);$text=static fn(string$s):string=>Html::e(MemberCardMessages::text($s,$messages));$data=['valid'=>false];$unavailable=false;
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');return true;}
        try{if(!Database::tableExists($db,'member_cards'))throw new \RuntimeException('Card maintenance pending');$data=MemberCards::verify($db,is_string($_GET['token']??null)?$_GET['token']:'',$nativeClub);http_response_code($data['valid']?200:404);}catch(\Throwable){http_response_code(503);$unavailable=true;}
        if($method==='HEAD')return true;$title=$unavailable?'Ausweisprüfung derzeit nicht verfügbar. Bitte erneut versuchen.':($data['valid']?'Gültiger Mitgliederausweis':'Dieser Mitgliederausweis ist nicht gültig.');
        echo'<!doctype html><html lang="'.$locale.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.$text('Mitgliederausweis').'</title><style nonce="'.Html::e($nonce).'">*{box-sizing:border-box}body{margin:0;padding:clamp(1rem,5vw,3rem);font:1rem/1.5 system-ui,sans-serif;color:#183128;background:#f3f6f4}main{max-width:36rem;margin:3vh auto;background:white;padding:clamp(1rem,5vw,2rem);border:1px solid #b7c9be;border-radius:1rem;overflow-wrap:anywhere}h1{font-size:1.4rem;line-height:1.3}h2{font-size:1.6rem}.valid{border-top:8px solid #24543e}.invalid{border-top:8px solid #b45309}p{font-size:1rem}</style></head><body><main class="'.(!$unavailable&&$data['valid']?'valid':'invalid').'" data-member-card-verification="'.(!$unavailable&&$data['valid']?'valid':($unavailable?'unavailable':'invalid')).'"><h1>'.$text($title).'</h1>';
        if(!$unavailable&&$data['valid'])echo'<p>'.Html::e($data['club_name']).'</p><h2>'.Html::e($data['name']).'</h2><p>'.$text('Gültig bis').': '.Html::e((new \DateTimeImmutable($data['valid_until']))->format($locale==='en'?'Y-m-d':'d.m.Y')).'</p>';
        if(!$unavailable&&$data['valid'])echo'<p>'.$text('Geprüft am').': '.Html::e((new \DateTimeImmutable('now',new \DateTimeZone('Europe/Berlin')))->format($locale==='en'?'Y-m-d H:i':'d.m.Y H:i')).'</p>';elseif(!$unavailable)echo'<p>'.$text('Lass dir bei Unklarheiten den aktuellen Ausweis zeigen.').'</p>';echo'</main></body></html>';return true;
    }
}
