<?php declare(strict_types=1);
namespace Kulturhus\Native\Status;

use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Language\StatusMessages;

final class View
{
    private const REASONS=[
        'bank_setup_missing'=>'Bankserver und Produktkennung fehlen','bank_login_missing'=>'Bankkennung und PIN noch nicht gespeichert','bank_mapping_missing'=>'Bankkonto noch nicht zugeordnet','eric_runtime_missing'=>'Amtliche ERiC-Laufzeit fehlt','eric_registration_missing'=>'ELSTER-Herstellerkonfiguration fehlt',
        'configuration_unreadable'=>'Einrichtung prüfen','connection_failed'=>'Verbindung nicht bestätigt','authorization_required'=>'Anmeldung oder Freigabe prüfen','provider_request_rejected'=>'Zugang oder Einrichtung prüfen','provider_rate_limit'=>'Anbieter begrenzt Abrufe','provider_unavailable'=>'Anbieter nicht verfügbar','provider_response_invalid'=>'Anbieterantwort nicht bestätigt','account_mismatch'=>'Kontozuordnung prüfen','reauthentication_required'=>'Neu anmelden',
        'runtime_ready'=>'Laufzeit geprüft','database_read'=>'Datenbank antwortet','storage_unavailable'=>'Speicher nicht verfügbar','storage_readback_failed'=>'Speicherprüfung fehlgeschlagen','storage_low'=>'Wenig freier Speicher','storage_readback_verified'=>'Schreiben und Lesen geprüft','queue_overdue'=>'Überfällige Aufgaben','queue_current'=>'Keine überfälligen Aufgaben','worker_not_observed'=>'Noch kein Laufnachweis','worker_proof_old'=>'Laufnachweis veraltet','worker_running'=>'Dienst läuft','worker_confirmed'=>'Lauf bestätigt','worker_needs_review'=>'Letzten Lauf prüfen','schema_not_current'=>'Installationsstand prüfen',
        'eric_runtime_verified'=>'ERiC gestartet','eric_runtime_failed'=>'ERiC konnte nicht starten','bank_not_observed'=>'Noch kein Banknachweis','bank_configuration_changed'=>'Bankzugang geändert','bank_authentication_required'=>'Bankfreigabe erforderlich','bank_needs_review'=>'Bankabruf prüfen','bank_proof_old'=>'Banknachweis veraltet','bank_read_confirmed'=>'Bankabruf bestätigt','push_runtime_missing'=>'Push-Laufzeit fehlt','push_runtime_verified'=>'Push-Laufzeit geprüft','smtp_authenticated'=>'Mailzugänge bestätigt','smtp_not_confirmed'=>'Mailzugang nicht bestätigt','smtp_authentication_required'=>'Mail-Anmeldung prüfen','imap_runtime_missing'=>'Postfach-Laufzeit fehlt','imap_authenticated'=>'Postfachzugänge bestätigt','imap_not_confirmed'=>'Postfachzugang nicht bestätigt','imap_authentication_required'=>'Postfach-Anmeldung prüfen','fixture_transport_disabled'=>'Testumgebung · keine Anbieteranfrage','account_confirmed'=>'Konto bestätigt','paypal_authentication'=>'PayPal-Anmeldung geprüft','paypal_merchant_unverified'=>'Anmeldung bestätigt · Händlerzuordnung ungeprüft','payments_not_enabled'=>'Zahlungen noch nicht freigegeben','probe_failed'=>'Prüfung nicht bestätigt','invalid_probe_result'=>'Prüfergebnis nicht bestätigt','invalid_observation_time'=>'Prüfzeitpunkt nicht bestätigt',
    ];

    public static function reason(string $reason,array $messages=[]):string
    {
        return StatusMessages::text(self::REASONS[$reason]??'',$messages);
    }

    public static function stamp(?string $value,array $messages=[],string $locale='de'):string
    {
        if(!$value)return Html::e(StatusMessages::text('Noch keine Prüfung',$messages));
        try{
            $time=new \DateTimeImmutable($value.' UTC');
            return '<time datetime="'.Html::e($time->format('Y-m-d\TH:i:s\Z')).'">'.Html::e($time->setTimezone(new \DateTimeZone('Europe/Berlin'))->format(explode('-',$locale,2)[0]==='en'?'d/m/Y H:i':'d.m.Y H:i')).'</time>';
        }catch(\Throwable){return Html::e(StatusMessages::text('Zeitpunkt nicht bestätigt',$messages));}
    }

    public static function render(Monitor $monitor,string $csrf,array $query,array $messages=[],array $submitted=[],string $error='',bool $unavailable=false,string $locale='de'):string
    {
        $t=static fn(string $source,array $values=[]):string=>StatusMessages::text($source,$messages,$values);
        $e=static fn(string $source,array $values=[]):string=>Html::e($t($source,$values));
        $browserMessages=[];foreach(StatusMessages::ENGLISH as$source=>$ignored)$browserMessages[$source]=$t($source);
        $group=Catalog::group($query['group']??'system');
        $rows=$monitor->snapshot($group);$preferences=$monitor->settings();
        $form=SettingsForm::prepare($preferences,$submitted,$unavailable);$draft=$form['values'];$reopen=($query['status_reopen']??null)==='1'?'2':'1';
        $h='<link rel="stylesheet" href="system-status.css"><script type="module" src="system-status.js"></script><section class="content service-status" data-status-panel data-status-automatic="'.($preferences['automatic_enabled']?'1':'0').'" data-status-group="'.Html::e($group).'" data-status-locale="'.Html::e($locale).'" data-status-messages="'.Html::e(json_encode($browserMessages,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)).'" data-status-reasons="'.Html::e(json_encode(array_map($t,self::REASONS),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)).'"><div class="page-heading"><h1>'.$e('Systemstatus').'</h1><a class="btn btn-outline theme-secondary" href="?view=settings&amp;tab=status&amp;group='.Html::e($group).'">'.$e('Ansicht aktualisieren').'</a></div><nav class="status-groups nav" aria-label="'.$e('Dienstgruppen').'">';
        foreach(Catalog::GROUPS as$key=>$label)$h.='<a class="nav-link'.($key===$group?' active':'').'" href="?view=settings&amp;tab=status&amp;group='.Html::e($key).'"'.($key===$group?' aria-current="page"':'').'>'.$e($label).'</a>';
        $h.='</nav>';
        if($preferences['automatic_enabled'])$h.='<p class="hint" data-status-automatic-notice>'.$e('Die Dienste werden automatisch im Hintergrund geprüft. Diese Ansicht aktualisiert sich selbst.').'</p>';
        if($error!=='')$h.='<p role="alert" data-status-error>'.$e($error).'</p>';
        if($form['blocked'])$h.='<p role="alert">'.$e('Ihre ursprünglichen Angaben bleiben erhalten. Öffnen Sie den aktuellen Stand bewusst, bevor Sie erneut speichern.').'</p><a class="btn btn-outline theme-secondary" data-status-reopen href="?view=settings&amp;tab=status&amp;group='.Html::e($group).'&amp;status_reopen='.$reopen.'#status-preferences">'.$e('Aktuelle Status-Einstellungen öffnen').'</a>';
        $h.='<p class="status-feedback" role="status" aria-live="polite" data-status-feedback></p><div class="status-list">';
        foreach($rows as$row){
            $kind=match($row['kind']){'provider'=>'Verbindung','passive'=>'Vorhandener Nachweis',default=>'Lokale Prüfung'};
            $environment=match($row['environment']??null){'test'=>' · Test','production'=>' · Live',default=>''};
            $duration=$row['duration_ms']!==null?$e('Prüfdauer {ms} ms',['ms'=>number_format($row['duration_ms'],0,explode('-',$locale,2)[0]==='en'?'.':',',explode('-',$locale,2)[0]==='en'?',':'.')]):'';
            $h.='<article class="status-row" data-status-service="'.Html::e($row['id']).'"><div class="status-name"><h2>'.$e($row['label']).'</h2><span class="status-kind">'.$e($kind).$e($environment).'</span></div><div class="status-result"><strong class="status-badge status-'.Html::e($row['state']).'" data-status-state>'.$e($row['status']).'</strong><span data-status-time>'.self::stamp($row['checked_at'],$messages,$locale).'</span><span data-status-duration>'.$duration.'</span><span data-status-reason>'.Html::e(self::reason($row['reason'],$messages)).'</span></div><div class="status-actions">';
            if($row['can_check'])$h.='<form method="post" data-status-check target="_blank" rel="noopener">'.Html::hidden('action','system-status-check').Html::hidden('csrf',$csrf).Html::hidden('service',$row['id']).Html::hidden('group',$group).'<button class="btn btn-solid theme-primary" type="submit">'.$e('Prüfen').'<span class="visually-hidden">: '.$e($row['label']).'</span></button></form>';
            $h.='<a class="btn btn-outline theme-secondary" href="?view=settings&amp;tab=status&amp;group='.Html::e($group).'&amp;history='.Html::e($row['id']).'#status-history">'.$e('Verlauf').'<span class="visually-hidden">: '.$e($row['label']).'</span></a></div></article>';
        }
        $h.='</div>'.\Kulturhus\Native\Web\PhotoRequirements::render(\Kulturhus\Native\Cms\PhotoRuntime::inspect(),$t);
        if(isset($query['history'])){
            if(!is_string($query['history']))throw new \DomainException('Verlauf prüfen.');
            $id=$query['history'];Catalog::service($id);$history=$monitor->history($id,$query['history_page']??1);
            $h.='<section id="status-history" class="status-history"><h2>'.$e('Verlauf').' · '.$e(Catalog::service($id)[0]).'</h2>';
            if(!$history['total'])$h.='<p>'.$e('Noch keine Prüfung für die aktuelle Verbindung.').'</p>';
            else{
                $h.='<p>'.$e('Bestätigte Prüfungen der letzten 24 Stunden: {passed} von {measured}.',['passed'=>$history['last_day']['passed'],'measured'=>$history['last_day']['measured']]).'</p><ol class="status-history-list">';
                foreach($history['rows']as$row)$h.='<li>'.self::stamp($row['observed_at']??$row['checked_at'],$messages,$locale).'<strong>'.$e(Catalog::STATES[$row['state']]).'</strong><span>'.Html::e(self::reason($row['reason'],$messages)).'</span></li>';
                $h.='</ol><nav class="status-history-pages" aria-label="'.$e('Prüfverlauf blättern').'">';
                foreach([-1=>'Vorherige',1=>'Weitere']as$delta=>$label){$page=$history['page']+$delta;if($page>=1&&$page<=$history['pages'])$h.='<a class="btn btn-outline theme-secondary" href="?'.Html::e(http_build_query(['view'=>'settings','tab'=>'status','group'=>$group,'history'=>$id,'history_page'=>$page])).'#status-history">'.$e($label).'</a>';}
                $h.='</nav>';
            }
            $h.='</section>';
        }
        $h.='<details id="status-preferences" class="status-preferences"'.($form['present']||in_array($query['status_reopen']??null,['1','2'],true)?' open':'').'><summary>'.$e('Status-Einstellungen').'</summary>';
        if($form['invalid'])$h.='<details data-status-original-input><summary>'.$e('Eingabe ansehen').'</summary><pre>'.Html::e(json_encode($form['invalid'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRETTY_PRINT)).'</pre></details>';
        $h.='<form method="post" data-status-settings-form>'.Html::hidden('action','system-status-save').Html::hidden('csrf',$csrf).SettingsForm::hidden('version',$form['version']).SettingsForm::hidden('group',$form['present']?$form['group']:$group).'<label class="status-choice"><input class="check" type="checkbox" name="automatic_enabled" value="1"'.($draft['automatic_enabled']?' checked':'').'> '.$e('Automatisch prüfen').'</label><label class="status-choice"><input class="check" type="checkbox" name="public_enabled" value="1"'.($draft['public_enabled']?' checked':'').'> '.$e('Öffentliche Statusseite freigeben').'</label><fieldset><legend>'.$e('Öffentlich sichtbare Dienste').'</legend><div class="status-choices">';
        foreach(Catalog::SERVICES as$id=>$service)$h.='<label class="status-choice"><input class="check" type="checkbox" name="public_services[]" value="'.Html::e($id).'"'.(in_array($id,$draft['public_services'],true)?' checked':'').'> '.$e($service[0]).'</label>';
        $h.='</div></fieldset><fieldset><legend>'.$e('Prüfungen pausieren').'</legend><div class="status-choices">';
        foreach(Catalog::SERVICES as$id=>$service)$h.='<label class="status-choice"><input class="check" type="checkbox" name="disabled_services[]" value="'.Html::e($id).'"'.(in_array($id,$draft['disabled_services'],true)?' checked':'').'> '.$e($service[0]).'</label>';
        $h.='</div></fieldset><button class="btn btn-solid theme-primary" type="submit"'.($form['blocked']?' disabled':'').'>'.$e('Speichern').'</button></form></details>';
        if($preferences['public_enabled'])$h.='<a class="btn btn-outline theme-secondary" href="/status" target="_blank" rel="noopener">'.$e('Öffentliche Ansicht öffnen').'</a>';
        return $h.'</section>';
    }
}
