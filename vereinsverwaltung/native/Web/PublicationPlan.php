<?php
declare(strict_types=1);
namespace Kulturhus\Native\Web;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{Conflict,PublicationSchedule,TemplatePolicy};
use Kulturhus\Native\Shared\Access;
use PDO;

/** Shared form adapter for the real publication worker, never a date-only display flag. */
final class PublicationPlan
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    private PublicationSchedule $schedule;
    public function __construct(private PDO $db,private Access $access,private string $csrf){$this->schedule=new PublicationSchedule($db,$access);}

    public function action(array $post,array $page,bool $cancel):void
    {
        $this->access->require('website','publish');
        if($this->csrf===''||!is_string($post['csrf']??null)||!hash_equals($this->csrf,$post['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.');
        $version=filter_var($post['schedule_version']??null,FILTER_VALIDATE_INT);
        if($version===false||$version===null||$version<0)throw new InvalidArgumentException('Gültigen Planungsstand angeben.');
        if($cancel){
            $plan=$this->schedule->forPage($page['id']);$id=$post['schedule_id']??'';
            if(!is_string($id)||!$plan||$plan['id']!==$id)throw new Conflict('Planung wurde inzwischen geändert.');
            $this->schedule->cancel($id,$version);
        }else{
            $time=$post['publish_at']??'';if(!is_string($time))throw new InvalidArgumentException('Veröffentlichungszeit muss Text sein.');
            $this->schedule->plan($page['id'],$page['version'],PublicationSchedule::parseBerlin($time),$version);
        }
    }

    public function summary(array $page):string
    {
        $plan=$this->schedule->forPage($page['id']);if(!$plan)return '';
        return '<small class="publication-plan-status">'.Html::e($this->label($plan,$page)).'</small>';
    }

    public function render(array $page,string $prefix):string
    {
        $plan=$this->schedule->forPage($page['id']);$e=Html::e(...);
        $h='<section class="panel publication-planning" id="publication-planning"><h2>'.$e($this->contentText('Veröffentlichung planen')).'</h2>';
        if($plan)$h.='<p role="status">'.$e($this->label($plan,$page)).'</p><p class="help">'.$e($this->contentFormat('Geplant für Version {version} · {date} (Europe/Berlin)',['version'=>$plan['page_version'],'date'=>$this->date($plan['due_at'])])).'</p>';
        $can=true;try{TemplatePolicy::assertCapability($this->db,$this->access,$page['id'],'publish');}catch(DomainException){$can=false;}
        $pending=$plan&&in_array($plan['status'],['pending','processing'],true);
        $approval=\Kulturhus\Native\Cms\NewsChannels::isNews($page)?(new \Kulturhus\Native\Cms\NewsPolicy($this->db,$this->access))->state($page['id'],$page):['enabled'=>false,'valid'=>true];
        $ready=!$approval['enabled']||$approval['valid'];
        if(!$ready)$h.='<p>'.$e($this->contentText($pending?'Der gespeicherte Termin benötigt eine neue Vier-Augen-Freigabe.':'Vor der Planung eine Vier-Augen-Freigabe einholen.')).'</p>';
        $posted=$this->contentPosted($prefix.'-plan',$page['id']);$cancelPosted=$this->contentPosted($prefix.'-cancel-plan',$page['id']);
        $currentVersion=$pending?$plan['version']:0;
        if(($posted||$cancelPosted)&&((is_string($_POST['schedule_version']??null)&&(string)$_POST['schedule_version']!==(string)$currentVersion)||(is_string($_POST['version']??null)&&(string)$_POST['version']!==(string)$page['version']))){
            $view=match($prefix){'album'=>'media','news'=>'news',default=>'website'};
            $h.='<p role="alert">'.$e($this->contentText('Planung wurde inzwischen geändert.')).' <a data-nav href="'.$e('/?'.http_build_query(['view'=>$view,'item'=>$page['id']]).'#publication-planning').'">'.$e($this->contentText('Aktuellen Stand öffnen')).'</a></p>';
        }
        if($can&&!$page['archived']){
            $value=$posted&&is_string($_POST['publish_at']??null)?$_POST['publish_at']:($pending?$this->local($plan['due_at']):'');
            $version=$posted&&is_string($_POST['schedule_version']??null)?$_POST['schedule_version']:$currentVersion;
            $h.=$this->form($prefix.'-plan',$page).Html::hidden('schedule_version',$version).Html::input('publish_at',$this->contentText('Datum und Uhrzeit (Europe/Berlin)'),$value,'datetime-local','required').'<button class="btn btn-outline theme-secondary secondary"'.(!$ready?' disabled':'').'>'.$e($this->contentText($pending?'Termin ändern':'Veröffentlichung planen')).'</button></form>';
        }
        if($can&&$pending){
            $version=$cancelPosted&&is_string($_POST['schedule_version']??null)?$_POST['schedule_version']:$plan['version'];
            $id=$cancelPosted&&is_string($_POST['schedule_id']??null)?$_POST['schedule_id']:$plan['id'];
            $h.=$this->form($prefix.'-cancel-plan',$page).Html::hidden('schedule_id',$id).Html::hidden('schedule_version',$version).'<button class="btn btn-outline theme-secondary secondary">'.$e($this->contentText('Planung abbrechen')).'</button></form>';
        }
        return $h.'<p class="help">'.$e($this->contentText('Der Termin benötigt einen laufenden Veröffentlichungsdienst. Nach Änderungen am Entwurf muss der Veröffentlichungstermin erneut bestätigt werden.')).'</p></section>';
    }
    private function label(array $plan,array $page):string
    {
        if(in_array($plan['status'],['pending','processing'],true)&&$plan['page_version']!==$page['version'])return $this->contentText('Entwurf geändert – Termin neu planen');
        if($plan['status']==='pending')return $this->contentFormat('Geplant: {date}',['date'=>$this->date($plan['due_at'])]);
        return $this->contentText(match($plan['status']){'processing'=>'Veröffentlichung wird verarbeitet','published'=>'Nach Termin veröffentlicht','cancelled'=>'Planung abgebrochen','conflicted'=>'Nicht veröffentlicht: Entwurf wurde geändert','blocked'=>'Nicht veröffentlicht: Berechtigung oder Inhalt prüfen','failed'=>'Veröffentlichung fehlgeschlagen – erneut planen',default=>'Planung prüfen'});
    }
    private function local(int $timestamp):string{return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i');}
    private function date(int $timestamp):string
    {
        $english=explode('-',(new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->locale(),2)[0]==='en';
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format($english?'Y-m-d H:i':'d.m.Y H:i');
    }
    private function form(string $op,array $page):string
    {
        $version=$this->contentPosted($op,$page['id'])&&is_string($_POST['version']??null)?$_POST['version']:$page['version'];
        return '<form method="post">'.Html::hidden('operation',$op).Html::hidden('csrf',$this->csrf).Html::hidden('id',$page['id']).Html::hidden('version',$version);
    }
}
