<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Language\{ApiTokenMessages,Catalog};
final class TokensView
{
    private static function text(string $source,array $messages):string{return Html::e(ApiTokenMessages::text($source,$messages));}
    private static function stamp(?int $value,string $locale,array $messages):string
    {
        if($value===null)return ApiTokenMessages::text('Noch nicht verwendet',$messages);
        return (new \DateTimeImmutable('@'.$value))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format(explode('-',$locale,2)[0]==='en'?'d/m/Y H:i':'d.m.Y H:i');
    }
    private static function raw(mixed $value):string
    {
        return is_string($value)?$value:json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    }
    public static function original(array $draft,array $messages):string
    {
        $t=static fn(string $s):string=>self::text($s,$messages);
        $h='<section class="api-original" data-api-original><h2>'.$t('Ursprüngliche Anfrage').'</h2><p>'.$t('Die ursprünglichen Angaben bleiben zur Prüfung erhalten. Passwort und Sicherheitscode werden nicht erneut angezeigt.').'</p><dl>';
        foreach(['action'=>'Anfrage','request_id'=>'Anlegevorgang','label'=>'Bezeichnung','days'=>'Gültigkeit','scopes'=>'Freigaben','token_id'=>'Zu widerrufender Zugang']as$key=>$label){
            $field=$draft['fields'][$key]??['present'=>false,'value'=>null];
            if(!$field['present']&&!in_array($key,['action','request_id'],true))continue;
            $h.='<dt>'.$t($label).'</dt><dd><pre data-api-original-field="'.$key.'">'.Html::e($field['present']?self::raw($field['value']):ApiTokenMessages::text('Nicht übermittelt',$messages)).'</pre></dd>';
        }
        if($draft['extra_names']??[])$h.='<dt>'.$t('Zusätzliche Felder').'</dt><dd><pre>'.Html::e(self::raw($draft['extra_names'])).'</pre></dd>';
        return $h.'</dl><p>'.$t('Prüfe den gespeicherten Stand, bevor du einen neuen Vorgang bestätigst. Ein unbestätigter Vorgang wird nicht automatisch wiederholt.').'</p>'.self::reopen($messages).'</section>';
    }
    public static function reopen(array $messages):string
    {
        return '<a class="btn btn-outline theme-secondary" data-api-reopen href="?view=settings&amp;tab=api&amp;api_reopen=1">'.self::text('Aktuelle API-Zugänge öffnen',$messages).'</a>';
    }
    public static function unavailable(string $error,array $query=[],array $messages=[],string $accountUrl='/account',array $draft=[]):string
    {
        if($error==='Anfragedaten entsprechen nicht dem API-Vertrag.'&&array_key_exists('api_page',$query))$error='API-Seite auswählen.';
        $t=static fn(string $s):string=>self::text($s,$messages);
        $h='<link rel="stylesheet" href="/vereinsverwaltung/native-api.css"><section class="content native-api" data-api-unavailable><h1>'.$t('API-Zugänge').'</h1><p class="alert alert-danger" role="alert">'.$t($error).'</p>';
        if(array_key_exists('api_page',$query))$h.='<h2>'.$t('Ursprüngliche Seitenauswahl').'</h2><pre data-api-original-page>'.Html::e(self::raw($query['api_page'])).'</pre>';
        if($draft!==[])$h.=self::original($draft,$messages);
        return $h.($draft===[]?self::reopen($messages):'').'<a class="btn btn-outline theme-secondary" href="'.Html::e($accountUrl).'">'.$t('Mein Konto').'</a></section>';
    }
    public static function document(string $html,string $locale,array $messages,string $appearanceAttributes=''):string
    {
        return '<!doctype html><html lang="'.Html::e($locale).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.self::text('API-Zugänge',$messages).'</title>'.\Kulturhus\Native\Web\BootstrapAssets::styles('/vereinsverwaltung/').\Kulturhus\Native\Appearance\Chrome::assets().'</head><body'.$appearanceAttributes.'>'.$html.'</body></html>';
    }
    public static function page(array $query):int
    {
        $page=array_key_exists('api_page',$query)?$query['api_page']:'1';
        if(!is_string($page)||!preg_match('/^[1-9][0-9]{0,4}$/D',$page))throw Problem::invalid();
        return (int)$page;
    }
    public static function render(\PDO $db,#[\SensitiveParameter]string $session,string $csrf,array $query=[],?array $issued=null,?array $pending=null,?string $error=null,array $draft=[],string $accountUrl='/account'):string
    {
        $context=Factory::session($db,$session);$catalog=new Catalog($db,$context->access);$locale=$catalog->locale();$messages=$catalog->messages($locale);$allowed=$context->allowedScopes();
        $listing=Factory::tokens($db)->listing($session,self::page($query));
        // Legacy PHP callers may supply a safe flat draft; a missing original ID still blocks it.
        if($draft!==[]&&!isset($draft['original']))$draft=TokenForm::capture($draft+['action'=>'api-token-create'])+['retry_allowed'=>true];
        $editable=$draft!==[]&&TokenForm::editable($draft,$allowed,($draft['retry_allowed']??false)===true);
        $blocked=$issued===null&&(($draft!==[]&&!$editable)||($error!==null&&$draft===[]));
        $t=static fn(string $s):string=>self::text($s,$messages);
        $html='<link rel="stylesheet" href="/vereinsverwaltung/native-api.css"><script type="module" src="/vereinsverwaltung/native-api.js"></script><section class="content native-api" data-api-panel data-api-copy-success="'.$t('Kopiert.').'" data-api-copy-fallback="'.$t('API-Zugang markiert. Jetzt kopieren.').'"><a class="btn btn-outline theme-secondary" href="'.Html::e($accountUrl).'">'.$t('Mein Konto').'</a><h1>'.$t('API-Zugänge').'</h1>';
        if($error!==null)$html.='<p class="alert alert-danger" role="alert">'.$t($error).'</p>';
        if($issued!==null){
            $html.='<section class="api-once" aria-labelledby="api-once-title"><h2 id="api-once-title">'.Html::e($issued['label']).'</h2><p>'.$t('Dieser Zugang wird einmal angezeigt.').'</p><label for="api-new-token">'.$t('API-Zugang').'</label><textarea id="api-new-token" rows="3" readonly autocomplete="off" spellcheck="false">'.Html::e($issued['token']).'</textarea><button type="button" class="btn btn-solid theme-primary" data-api-copy>'.$t('Kopieren').'</button><noscript><p>'.$t('Ohne JavaScript den Zugang im Textfeld markieren und kopieren.').'</p></noscript><p role="status" aria-live="polite" data-api-copy-status></p><a class="btn btn-outline theme-secondary" href="?view=settings&amp;tab=api">'.$t('Fertig').'</a></section>';
        }elseif($pending!==null&&($pending['needs_factor']??true)){
            $html.='<form method="post" class="api-factor"><h2>'.$t('Sicherheitscode').'</h2>'.Html::hidden('action','api-token-confirm').Html::hidden('request_id',$pending['id']).Html::hidden('csrf',$csrf).'<label for="api-factor">'.$t($pending['factor']==='email'?'Code aus deiner E-Mail':'Code der Authenticator-App oder Wiederherstellungscode').'</label><input id="api-factor" name="factor" type="text" class="form-control" autocomplete="one-time-code" maxlength="80" required><button class="btn btn-solid theme-primary" type="submit">'.$t('Zugang anlegen').'</button><a class="btn btn-outline theme-secondary" href="?view=settings&amp;tab=api">'.$t('Abbrechen').'</a></form>';
        }elseif($blocked){$html.=self::original($draft,$messages);}
        elseif(!$allowed){$html.='<p>'.$t('Für deinen Zugang stehen derzeit keine API-Freigaben zur Verfügung.').'</p>';}
        else{
            $original=$draft!==[];$request=$original?TokenForm::value($draft,'request_id'):bin2hex(random_bytes(16));$label=$original?TokenForm::value($draft,'label'):'';$days=$original?TokenForm::value($draft,'days'):'30';$scopes=$original&&($draft['fields']['scopes']['present']??false)?TokenForm::value($draft,'scopes'):[];
            $html.='<details class="api-create"'.($original?' open':'').'><summary>'.$t('API-Zugang anlegen').'</summary><form method="post">'.Html::hidden('action','api-token-create').Html::hidden('request_id',$request).Html::hidden('csrf',$csrf).'<label for="api-label">'.$t('Bezeichnung').'</label><input id="api-label" name="label" class="form-control" maxlength="100" required autocomplete="off" value="'.Html::e($label).'"><label for="api-days">'.$t('Gültigkeit').'</label><select id="api-days" name="days" class="form-control">';
            foreach(['7'=>'7 Tage','30'=>'30 Tage','90'=>'90 Tage']as$value=>$title)$html.='<option value="'.$value.'"'.($days===(string)$value?' selected':'').'>'.$t($title).'</option>';
            $html.='</select><fieldset><legend>'.$t('Freigaben').'</legend><div class="api-scopes">';
            foreach($allowed as$scope)$html.='<label><input type="checkbox" class="check" name="scopes[]" value="'.Html::e($scope).'"'.(in_array($scope,$scopes,true)?' checked':'').'> '.$t(Scopes::ALL[$scope][0]).'</label>';
            $html.='</div></fieldset><label for="api-password">'.$t('Aktuelles Passwort').'</label><input id="api-password" type="password" class="form-control" name="password" required autocomplete="current-password"><button class="btn btn-solid theme-primary" type="submit">'.$t('Weiter').'</button></form></details>';
        }
        $html.='<div class="api-token-list">';if(!$listing['rows'])$html.='<p>'.$t('Noch keine API-Zugänge.').'</p>';
        foreach($listing['rows']as$row){
            $state=match($row['state']){'active'=>'Aktiv','expired'=>'Abgelaufen','revoked'=>'Widerrufen',default=>'Anmeldung geändert'};
            $html.='<article class="api-token" data-api-token="'.Html::e($row['id']).'"><header><h2>'.Html::e($row['label']).'</h2><strong>'.$t($state).'</strong></header><p>'.$t('Gültig bis').' '.Html::e(self::stamp($row['expires_at'],$locale,$messages)).'</p><details><summary>'.$t('Freigaben und Verwendung').'</summary><p>'.$t('Zuletzt').': '.Html::e(self::stamp($row['last_used_at'],$locale,$messages)).'</p><ul>';foreach($row['scopes']as$scope)$html.='<li>'.$t(Scopes::ALL[$scope][0]).'</li>';$html.='</ul></details>';
            if($row['state']!=='revoked')$html.='<form method="post">'.Html::hidden('action','api-token-revoke').Html::hidden('csrf',$csrf).Html::hidden('token_id',$row['id']).'<button class="btn btn-outline theme-danger" type="submit"'.($error!==null||$pending!==null?' disabled':'').'>'.$t('Zugang widerrufen').'<span class="visually-hidden">: '.Html::e($row['label']).'</span></button></form>';
            $html.='</article>';
        }
        $html.='</div><nav class="api-pages" aria-label="'.$t('API-Zugänge blättern').'">';foreach([-1=>'Zurück',1=>'Weitere']as$delta=>$title){$target=$listing['page']+$delta;if($target>=1&&$target<=$listing['pages'])$html.='<a class="btn btn-outline theme-secondary" href="?view=settings&amp;tab=api&amp;api_page='.$target.'">'.$t($title).'</a>';}
        $html.='<span>'.Html::e(strtr(ApiTokenMessages::text('Seite {page} von {pages}',$messages),['{page}'=>(string)$listing['page'],'{pages}'=>(string)$listing['pages']])).'</span>';
        return $html.'</nav></section>';
    }
}
