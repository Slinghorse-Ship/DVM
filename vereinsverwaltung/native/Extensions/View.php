<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Language\ExtensionMessages;
final class View
{
    private static function hidden(array $row,string $csrf,string $action,mixed $revision=null,bool $original=false):string
    {
        return Html::hidden('csrf',$csrf).Html::hidden('plugin',$row['id']).ConfigurationForm::hidden('revision',$original?$revision:(string)$row['revision']).Html::hidden('action','extension-'.$action);
    }
    private static function button(array $row,string $csrf,string $action,string $label,bool $blocked=false):string
    {
        return '<form method="post">'.self::hidden($row,$csrf,$action).'<button class="btn btn-solid theme-primary" type="submit"'.($blocked?' disabled':'').'>'.Html::e($label).'</button></form>';
    }
    private static function label(string $plugin,string $source,array $messages):string
    {
        return in_array($plugin,['website-notice','editorial-layouts'],true)?ExtensionMessages::text($source,$messages):$source;
    }
    public static function render(Manager $manager,string $csrf,array $query=[],?array $draft=null,array $messages=[],string $locale='de',bool $unavailable=false):string
    {
        $text=static fn(string $source):string=>ExtensionMessages::text($source,$messages);$e=static fn(string $source):string=>Html::e($text($source));
        $rows=$manager->listing();$page=$query['extension_page']??'1';if(!is_string($page)||!preg_match('/^[1-9][0-9]{0,4}$/D',$page))throw new \DomainException('Ungültige Erweiterungsseite.');
        $pages=max(1,(int)ceil(count($rows)/9));$page=min((int)$page,$pages);$target=null;
        if($draft!==null)foreach($rows as$row)if(($draft['plugin']??null)===$row['id']){$target=$row;break;}
        $snapshot=$draft!==null?ConfigurationForm::prepare($target,$draft,$unavailable):null;
        $html='<link rel="stylesheet" href="/vereinsverwaltung/native-extensions.css"><section class="content native-extensions" data-extension-panel><h1>'.$e('Erweiterungen').'</h1>';
        if($manager->registryDiagnostics())$html.='<p class="alert alert-danger" role="alert">'.$e('Zusätzliche Erweiterungen konnten nicht geladen werden. Lokales Paket prüfen.').'</p>';
        if($snapshot!==null){
            $original=['plugin'=>$snapshot['plugin'],'configuration'=>$snapshot['configuration']];
            $html.='<section class="extension-original" data-extension-original><h2>'.$e('Ursprüngliche Angaben').'</h2>'.ConfigurationForm::hidden('revision',$snapshot['revision']).'<pre>'.Html::e(json_encode($original,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRETTY_PRINT)).'</pre><p>'.$e('Die ursprünglichen Angaben bleiben erhalten. Öffnen Sie den aktuellen Stand bewusst, bevor Sie eine neue Aktion bestätigen.').'</p><a class="btn btn-outline theme-secondary" href="/vereinsverwaltung/?view=settings&amp;tab=extensions&amp;extension_reopen='.(($query['extension_reopen']??null)==='1'?'2':'1').'">'.$e('Aktuelle Erweiterungen öffnen').'</a></section>';
            if($snapshot['blocked'])$html.='<p role="alert">'.$e($snapshot['unavailable']?'Erweiterung derzeit nicht sicher prüfbar. Öffnen Sie den gespeicherten Stand erneut.':'Ursprüngliche Erweiterung und ihren Stand prüfen.').'</p>';
        }
        $html.='<div class="extension-list">';
        foreach(array_slice($rows,($page-1)*9,9)as$row){
            $hasDraft=$snapshot!==null&&$snapshot['plugin']===$row['id'];$blocked=$snapshot!==null&&(!$hasDraft||$snapshot['blocked']);
            $state=!$row['available']?'Paket fehlt':($row['needs_upgrade']?'Aktualisierung erforderlich':($row['enabled']?match($row['runtime_state']){'failed'=>'Start fehlgeschlagen','paused'=>'Freigabe prüfen','manual'=>'Manuell geladen',default=>'Aktiv'}:($row['installed_version']?'Inaktiv':'Nicht installiert')));
            $html.='<article class="extension-card" id="extension-'.Html::e($row['id']).'"><header><h2>'.Html::e(self::label($row['id'],$row['label'],$messages)).'</h2><span>'.$e($state).'</span></header><p>'.$e('Version').' '.Html::e($row['installed_version']??$row['version']).'</p><div class="extension-actions">';
            if(!$row['available']){$html.='<p>'.$e('Lokales Paket wieder ergänzen. Die gespeicherten Einstellungen bleiben erhalten.').'</p></div></article>';continue;}
            if(!$row['installed_version'])$html.=self::button($row,$csrf,'install',$text('Installieren'),$snapshot!==null);
            else{
                if($row['enabled'])$html.=self::button($row,$csrf,'disable',$text('Deaktivieren'),$snapshot!==null);
                if($row['needs_upgrade'])$html.=self::button($row,$csrf,'upgrade',$text('Aktualisieren'),$snapshot!==null);
                elseif(!$row['enabled'])$html.=self::button($row,$csrf,'enable',$text('Aktivieren'),$snapshot!==null);
                elseif(in_array($row['runtime_state'],['failed','paused'],true))$html.=self::button($row,$csrf,'enable',$text('Erneut starten'),$snapshot!==null);
            }
            $html.='</div>';
            if($row['dependencies']){$html.='<p>'.$e('Benötigt:').' ';foreach($row['dependencies']as$dependency=>$minimum)$html.=Html::e($dependency).' '.$e('ab').' '.Html::e($minimum).' ';$html.='</p>';}
            if($row['installed_version']&&(!$row['needs_upgrade']||$hasDraft)){
                if($row['fields']){
                    $values=$hasDraft?$snapshot['values']:$row['configuration'];
                    $html.='<details class="extension-configuration"'.($hasDraft?' open':'').'><summary>'.$e('Einstellungen').'</summary><form method="post">'.self::hidden($row,$csrf,'configure',$hasDraft?$snapshot['revision']:null,$hasDraft).'<fieldset'.($blocked?' disabled':'').'>';
                    foreach($row['fields']as$key=>$field){
                        $id='extension-'.$row['id'].'-'.$key;$name='configuration['.$key.']';$value=$values[$key]??null;$html.='<div class="extension-field"><label for="'.Html::e($id).'">'.Html::e(self::label($row['id'],$field['label'],$messages)).'</label>';
                        if($field['type']==='boolean')$html.='<input class="check" type="checkbox" id="'.Html::e($id).'" name="'.Html::e($name).'" value="1"'.($value?' checked':'').'>';
                        elseif($field['type']==='select'){$html.='<select class="form-control" id="'.Html::e($id).'" name="'.Html::e($name).'">';if(!is_string($value)||!array_key_exists($value,$field['options']))$html.='<option selected value="'.Html::e(ConfigurationForm::literal($value)).'">'.Html::e(ConfigurationForm::literal($value)).'</option>';foreach($field['options']as$option=>$label)$html.='<option value="'.Html::e((string)$option).'"'.($value===(string)$option?' selected':'').'>'.Html::e($label).'</option>';$html.='</select>';}
                        else{$type=$field['type']==='integer'&&!$blocked?'number':'text';$html.='<input class="form-control" id="'.Html::e($id).'" name="'.Html::e($name).'" type="'.$type.'" value="'.Html::e(ConfigurationForm::literal($value)).'"'.($type==='number'?' min="'.$field['min'].'" max="'.$field['max'].'"':($field['type']==='text'?' maxlength="'.$field['max'].'"':'')).'>';}
                        $html.='</div>';
                    }
                    $html.='<button class="btn btn-solid theme-primary" type="submit">'.$e('Speichern').'</button></fieldset></form></details>';
                }
                if(!$row['enabled'])$html.='<details class="extension-remove"><summary>'.$e('Entfernen').'</summary><form method="post">'.self::hidden($row,$csrf,'uninstall').'<fieldset'.($snapshot!==null?' disabled':'').'><label class="extension-confirm"><input type="checkbox" class="check" name="confirm" value="1" required> '.$e('Einstellungen dieser Erweiterung löschen').'</label><button class="btn btn-outline theme-danger" type="submit">'.$e('Deinstallieren').'</button></fieldset></form></details>';
            }
            $html.='</article>';
        }
        $html.='</div><nav class="extension-pages" aria-label="'.$e('Erweiterungen blättern').'">';foreach([-1=>'Zurück',1=>'Weitere']as$delta=>$label){$targetPage=$page+$delta;if($targetPage>=1&&$targetPage<=$pages)$html.='<a class="btn btn-outline theme-secondary" href="?view=settings&amp;tab=extensions&amp;extension_page='.$targetPage.'">'.$e($label).'</a>';}$html.='</nav>';
        $historyPage=$query['extension_history_page']??'1';if(!is_string($historyPage)||!preg_match('/^[1-9][0-9]{0,4}$/D',$historyPage))throw new \DomainException('Ungültige Verlaufsseite.');
        $history=$manager->history((int)$historyPage);$html.='<details class="extension-history"'.(isset($query['extension_history_page'])?' open':'').'><summary>'.$e('Letzte Änderungen').'</summary><ol>';$actions=['install'=>'installiert','enable'=>'aktiviert','disable'=>'deaktiviert','configure'=>'konfiguriert','upgrade'=>'aktualisiert','uninstall'=>'deinstalliert'];
        foreach($history['rows']as$entry){try{$date=(new \DateTimeImmutable($entry['occurred_at'].' UTC'))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format(explode('-',$locale,2)[0]==='en'?'d/m/Y H:i':'d.m.Y H:i');}catch(\Throwable){$date=$entry['occurred_at'];}$html.='<li>'.Html::e($entry['plugin_id'].' · '.$text($actions[$entry['action']]??$entry['action']).' · '.$date).'</li>';}
        $html.='</ol><nav class="extension-pages" aria-label="'.$e('Änderungsverlauf blättern').'">';foreach([-1=>'Zurück',1=>'Weitere']as$delta=>$label){$targetPage=$history['page']+$delta;if($targetPage>=1&&$targetPage<=$history['pages'])$html.='<a class="btn btn-outline theme-secondary" href="?view=settings&amp;tab=extensions&amp;extension_page='.$page.'&amp;extension_history_page='.$targetPage.'">'.$e($label).'</a>';}
        return $html.'</nav></details></section>';
    }
}
