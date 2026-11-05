<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Html,Workspace};
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Communication\{ConfirmationReminders,Digests\Plans,Digests\Schedule};
use Kulturhus\Native\Language\{Catalog,EditorialHubMessages as Messages};

/** Same period services, with original posted values retained after a refusal. */
final class NewsletterAutomation extends Workspace
{
    private array $messages=[];
    private function text(string $s,array$v=[]):string{return Messages::text($s,$this->messages,$v);}
    private function e(string $s,array$v=[]):string{return Html::e($this->text($s,$v));}
    private function version(mixed $raw):?int{try{return NewsletterPlanForm::number($raw);}catch(\DomainException){return null;}}
    private function select(string $name,string $label,mixed $value,array $options):string
    {
        $choice=is_bool($value)?($value?'1':'0'):(is_int($value)?(string)$value:$value);$valid=is_string($choice)&&array_key_exists($choice,$options);$h='<label>'.$this->e($label).'<select class="form-control" name="'.$name.'" required>';if(!$valid)$h.='<option value="__invalid_original_choice__" selected>'.$this->e('Auswahl prüfen').'</option>';foreach($options as$key=>$title)$h.='<option value="'.$key.'"'.($valid&&$choice===(string)$key?' selected':'').'>'.$this->e($title).'</option>';return$h.'</select></label>';
    }
    private function currentLink():string{return'<a class="btn btn-outline theme-secondary" data-nav href="/?view=newsletter&amp;section=planning&amp;planning_view=automation">'.$this->e('Aktuellen Plan bewusst öffnen').'</a>';}
    private function periodTitle(string $key):string
    {
        if(preg_match('/\Amonth-([0-9]{4})-([0-9]{2})\z/D',$key,$m))return$this->text('Monatsnewsletter').' · '.$m[2].'/'.$m[1];if(preg_match('/\Aquarter-([0-9]{4})-Q([1-4])\z/D',$key,$m))return$this->text('Quartalsnewsletter').' · Q'.$m[2].' '.$m[1];return$this->text('Zeitraum prüfen');
    }
    public function render():string
    {
        $this->access->require('newsletter','read');$this->messages=(new Catalog($this->db,$this->access))->messages();$h='<section class="newsletter-automation" data-newsletter-automation><h2>'.$this->e('Newsletter-Vorbereitung').'</h2><p>'.$this->e('Monats- und Quartalsausgaben werden als Entwurf vorbereitet. Jede Ausgabe benötigt eine Freigabe.').'</p>';
        if(!$this->can('newsletter','manage'))return$h.'<p>'.$this->e('Die geplanten Ausgaben stehen im Kalender. Pläne können von der Newsletterverwaltung bearbeitet werden.').'</p></section>';
        $op=$_POST['operation']??'';$postedPeriod=$_POST['period']??null;
        if(in_array($op,['newsletter-digest','newsletter-period-pause'],true)&&(!is_string($postedPeriod)||!in_array($postedPeriod,['calendar','month','quarter'],true)))return$h.'<p role="alert">'.$this->e('Newsletterplan erneut öffnen.').'</p>'.$this->currentLink().'</section>';
        $invalid=[];foreach(['prepare_days'=>'Vorbereitungstage vor Monatsbeginn','send_hour'=>'Versandstunde am Monatsersten (Berlin)','paused'=>'Vorbereitung und Versand','enabled'=>'Bestätigungserinnerungen']as$key=>$label)if(array_key_exists($key,$_POST)&&!is_scalar($_POST[$key]))$invalid[$this->text($label)]=$_POST[$key];
        if($invalid)$h.='<details open><summary>'.$this->e('Ursprüngliche Angaben prüfen').'</summary><pre>'.Html::e(json_encode($invalid,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)).'</pre></details>';
        if($this->can('website','read')){
            $plans=new Plans($this->db,$this->access);
            foreach(['calendar'=>'Kalenderplan','month'=>'Monatliche Ausgaben','quarter'=>'Quartalsausgaben']as$period=>$label){$stored=$plans->state($period);if($period!=='calendar'&&!$stored['configured'])continue;
                $posted=$op==='newsletter-digest'&&$postedPeriod===$period;$version=$posted?($_POST['plan_version']??null):$stored['version'];$stale=$this->version($version)!==$stored['version'];$draft=$posted?$_POST:$stored;$paused=$posted?(array_key_exists('paused',$_POST)?$_POST['paused']:false):$stored['paused'];if($paused==='on')$paused='1';
                $h.='<section class="panel" data-newsletter-period="'.$period.'"><h3>'.$this->e($label).'</h3><p>'.$this->e(!$stored['configured']?'Noch kein Plan eingerichtet.':($stored['paused']?'Plan pausiert.':'Vorbereitung aktiv.')).'</p>';
                if($stale)$h.='<p role="alert">'.$this->e('Der Plan wurde inzwischen geändert. Deine Angaben bleiben erhalten.').'</p>'.$this->currentLink();
                $h.=$this->start('newsletter-digest').Html::hidden('period',$period).NewsletterPlanForm::hidden('plan_version',$version);
                if($period==='calendar')foreach(['prepare_days'=>['Vorbereitungstage vor Monatsbeginn','(?:[1-9]|1[0-9]|2[01])'],'send_hour'=>['Versandstunde am Monatsersten (Berlin)','(?:[0-9]|1[0-9]|2[0-3])']]as$key=>[$caption,$pattern]){$value=$draft[$key]??null;$h.=Html::input($key,$this->text($caption),is_scalar($value)?(string)$value:'','text','inputmode="numeric" pattern="'.$pattern.'" required');}
                else$h.=Html::hidden('prepare_days',(string)$stored['prepare_days']).Html::hidden('send_hour',(string)$stored['send_hour']);
                $h.=$this->select('paused','Vorbereitung und Versand',$paused,['0'=>'Aktiv','1'=>'Pausiert']).'<button class="btn btn-solid theme-primary"'.($stale?' disabled':'').'>'.$this->e('Periodenplan speichern').'</button></form>';
                if($stored['configured']){
                    $schedule=Schedule::at($period,new \DateTimeImmutable(),$stored['prepare_days'],$stored['send_hour']);if($period==='calendar'&&new \DateTimeImmutable()>new \DateTimeImmutable($schedule['deadline']))$schedule=Schedule::at($period,(new \DateTimeImmutable($schedule['start']))->modify('+1 month'),$stored['prepare_days'],$stored['send_hour']);
                    $pausePosted=$op==='newsletter-period-pause'&&$postedPeriod===$period;$key=$pausePosted?($_POST['period_key']??null):$schedule['period_key'];$keyValid=is_string($key)&&preg_match('/\A(?:month-[0-9]{4}-(?:0[1-9]|1[0-2])|quarter-[0-9]{4}-Q[1-4])\z/D',$key);
                    $pause=$keyValid?$plans->period($period,$key):['version'=>-1,'paused'=>false];$pauseVersion=$pausePosted?($_POST['period_version']??null):$pause['version'];$pauseStale=!$keyValid||$this->version($pauseVersion)!==$pause['version'];
                    $local=static fn(string$at):string=>(new \DateTimeImmutable($at))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i');$h.='<p><strong>'.$this->e('Gespeicherter Plan').'</strong><br>'.$this->e($this->periodTitle($schedule['period_key'])).'<br>'.$this->e('Vorbereitung').': '.Html::e($local($schedule['prepare_at'])).'<br>'.$this->e('Versand').': '.Html::e($local($schedule['send_at'])).' – '.Html::e($local($schedule['deadline'])).'</p>';
                    if($pauseStale)$h.='<p role="alert">'.$this->e('Der Plan wurde inzwischen geändert. Deine Angaben bleiben erhalten.').'</p>'.$this->currentLink();
                    $toggle=$pausePosted?($_POST['paused']??null):($pause['paused']?'0':'1');if(!in_array($toggle,['0','1',0,1,true,false],true))$pauseStale=true;$h.=$this->start('newsletter-period-pause').Html::hidden('period',$period).NewsletterPlanForm::hidden('period_key',$key).NewsletterPlanForm::hidden('period_version',$pauseVersion).NewsletterPlanForm::hidden('paused',$toggle).'<button class="btn btn-outline theme-secondary"'.($pauseStale?' disabled':'').'>'.$this->e($toggle==='0'?'Diese Periode fortsetzen':'Nur diese Periode pausieren').' · '.Html::e($keyValid?$this->periodTitle($key):$this->text('Zeitraum prüfen')).'</button></form>';
                }
                $h.='</section>';
            }
        }else$h.='<p>'.$this->e('Für automatische Terminausgaben wird zusätzlich Lesezugriff auf die Website benötigt.').'</p>';
        try{$reminders=new ConfirmationReminders($this->db,$this->access,$this->box());$stored=$reminders->plan();$posted=$op==='newsletter-reminder-plan';$version=$posted?($_POST['plan_version']??null):$stored['version'];$stale=$this->version($version)!==$stored['version'];$enabled=$posted?($_POST['enabled']??false):$stored['enabled'];if($enabled==='on')$enabled='1';$h.='<details class="panel newsletter-reminder-settings"><summary>'.$this->e('Bestätigungserinnerungen').'</summary><p>'.$this->e('Auf Wunsch einmalig nach einem Tag erinnern. Nur tatsächlich zugestellte, noch gültige Anmeldungen werden berücksichtigt.').'</p>';if($stale)$h.='<p role="alert">'.$this->e('Der Plan wurde inzwischen geändert. Deine Angaben bleiben erhalten.').'</p>'.$this->currentLink();$h.=$this->start('newsletter-reminder-plan').NewsletterPlanForm::hidden('plan_version',$version).$this->select('enabled','Bestätigungserinnerungen',$enabled,['0'=>'Nicht automatisch erinnern','1'=>'Einmalig erinnern']).'<button class="btn btn-solid theme-primary"'.($stale?' disabled':'').'>'.$this->e('Erinnerungsplan speichern').'</button></form></details>';}catch(\DomainException){$h.='<p>'.$this->e('Bestätigungserinnerungen sind derzeit nicht verfügbar.').'</p>';}
        return$h.'</section>';
    }
}
