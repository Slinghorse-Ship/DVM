<?php declare(strict_types=1);
namespace Kulturhus\Native\Status;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Language\StatusMessages;
use Kulturhus\Native\Web\Html;
final class PublicPage
{
    public static function serve(\PDO $db,string $club,string $storage,string $method,?Access $access=null,?string $request=null):bool {
        header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
        $language=PublicLanguage::defaults();$languageError=null;
        try{if($access!==null){$resolved=PublicLanguage::resolve($db,$access,$club,$request??($_SERVER['REQUEST_URI']??'/status'));$language=$resolved['context'];$languageError=$resolved['error'];}}
        catch(\Throwable){$languageError=503;}
        $error=null;$services=[];
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');$error='Diese Anfrage ist nicht erlaubt.';}
        elseif($languageError!==null){http_response_code($languageError);$error=match($languageError){400=>'Ungültige Sprachauswahl.',404=>'Diese Website-Sprache ist nicht verfügbar.',default=>'Status derzeit nicht verfügbar.'};}
        else {
        try{
            $row=Db::run($db,'SELECT updated_by,public_enabled FROM native_status_settings WHERE club_id=?',[$club])->fetch();if(!$row||!$row['public_enabled'])throw new \DomainException('Nicht freigegeben.');
            $services=Factory::environment($db,$row['updated_by'],$storage,false)->monitor()->publicSnapshot($club);
        }catch(\DomainException $failure){$unavailable=str_starts_with($failure->getMessage(),'Privater Schlüssel')||str_starts_with($failure->getMessage(),'Private Konfiguration');http_response_code($unavailable?503:404);$error=$unavailable?'Status derzeit nicht verfügbar.':'Statusseite nicht freigegeben.';}
        catch(\Throwable){http_response_code(503);$error='Status derzeit nicht verfügbar.';}
        }
        header('Content-Type: text/html; charset=utf-8');if($method==='HEAD')return true;
        $text=static fn(string $source):string=>StatusMessages::text($source,$language['messages']);
        echo '<!doctype html><html lang="'.Html::e($language['locale']).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title lang="'.Html::e($language['content_locale']).'">'.Html::e($text('Systemstatus')).'</title><link rel="stylesheet" href="/assets/bootstrap-6.0.0-alpha.1.min.css"><link rel="stylesheet" href="/assets/system-status.css"></head><body><main class="status-public" lang="'.Html::e($language['content_locale']).'"><h1>'.Html::e($text('Systemstatus')).'</h1>';
        if(count($language['links'])>1){echo '<nav class="status-languages" aria-label="'.Html::e($text('Sprache')).'">';foreach($language['links']as$link)echo '<a href="'.Html::e($link['href']).'" lang="'.Html::e($link['locale']).'"'.($link['locale']===$language['locale']?' aria-current="page"':'').'>'.Html::e($link['label']).'</a>';echo '</nav>';}
        if($error!==null)echo '<p role="alert">'.Html::e($text($error)).'</p>';
        else{echo '<div class="status-list">';foreach($services as$service){$label=match($service['state']){'available'=>'Verfügbar','disrupted'=>'Gestört','restricted'=>'Eingeschränkt',default=>'Status unbekannt'};$tone=match($service['state']){'available'=>'ok','disrupted'=>'down','restricted'=>'restricted',default=>'not_checked'};echo '<article class="status-row"><h2 class="status-name">'.Html::e($text($service['label'])).'</h2><div class="status-result"><strong class="status-badge status-'.$tone.'">'.Html::e($text($label)).'</strong><span>'.View::stamp($service['checked_at'],$language['messages'],$language['content_locale']).'</span></div></article>';}echo '</div>';}
        echo '<footer><a href="'.Html::e($language['home']).'">'.Html::e($text('Zur Website')).'</a></footer></main></body></html>';return true;
    }
}
