<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{
    Workspace as BaseWorkspace,
    Html,
    Editor,
    RichTextEditor,
    ImageEditor,
    VideoEditor,
};
use Kulturhus\Native\Communication\Workspace as Communication;
use Kulturhus\Native\Communication\{Subscribers, PressContacts};
use Kulturhus\Native\Cms\Media;
final class Planning extends BaseWorkspace
{
    private ?array $planningMessages=null;
    private function planningText(string $source,array $values=[]):string
    {
        $this->planningMessages??=(new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->messages();
        return \Kulturhus\Native\Language\EditorialHubMessages::text($source,$this->planningMessages,$values);
    }
    private function planningLabel(string $source,array $values=[]):string{return Html::e($this->planningText($source,$values));}
    protected function start(string $operation):string
    {
        $h=parent::start($operation);
        $module=$this->planningModule;
        foreach(PlanningFormState::route($module,$_GET)as$key=>$value)if(!in_array($key,['view','section'],true))$h.=Html::hidden($key,$value);
        if(PlanningFormState::operation($operation)){
            $h=str_replace('<form method="post">','<form method="post" data-planning-action data-planning-unconfirmed="'.$this->planningLabel('Planung nicht bestätigt. Aktuellen gespeicherten Status prüfen, bevor du erneut handelst.').'">',$h);
            $h.='<p role="alert" tabindex="-1" data-planning-error hidden></p><a class="btn btn-outline theme-secondary" data-nav data-planning-check hidden href="'.Html::e(PlanningFormState::url($module,$_GET)).'">'.$this->planningLabel('Gespeicherten Planungsstatus prüfen').'</a>';
        }
        return$h;
    }
    private string $planningModule='social';
    public function manager(string $module,?array $failure=null): string
    {
        $this->planningModule=$module;
        $club=$this->access->require($module,'read')['club_id'];
        if($failure!==null)return PlanningFormState::recovery($failure);
        try{$url=PlanningFormState::url($module,$_GET);}catch(\DomainException){$url='/?'.http_build_query(['view'=>$module,'section'=>'planning']);}
        $read='<p class="planning-read-status"><a class="btn btn-outline theme-secondary" data-nav data-planning-read href="'.Html::e($url).'">'.$this->planningLabel('Gespeicherten Planungsstatus prüfen').'</a></p>';
        try{$body=$this->body($module);if($this->access->require($module,'read')['club_id']!==$club)throw new \DomainException('Vereinszuordnung während Planung geändert.',409);return$read.$body;}
        catch(\DomainException $error){http_response_code(in_array($error->getCode(),[403,404,409,422],true)?$error->getCode():422);$failure=PlanningFormState::failure($this->db,$this->access,$module,[],$error->getMessage(),false);return PlanningFormState::recovery($failure);}
    }
    private function body(string $module):string
    {
        if($module==='social')return $this->social();
        if($module==='newsletter'){
            $view=$_GET['planning_view']??(($_GET['tab']??null)==='automation'?'automation':null);$legacy=count(array_intersect(['newsletter_job','newsletter_page','newsletter_item','newsletter_kind','newsletter_state'],array_keys($_GET)))>0;
            if($view!==null&&(!is_string($view)||!in_array($view,['month','history','automation'],true)))throw new \DomainException('Planungsansicht prüfen.',422);
            if($view==='month'||($view===null&&!$legacy))return(new MonthPlanner($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($_GET,'newsletter');
            if($view==='history'||($view===null&&$legacy))return(new NewsletterDeliveryPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render();
        }
        if($module==='press')return (new PressDeliveryPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render().$this->suggestions($module,new Communication($this->db,$this->access,$module));
        if($module==='newsletter')return(new NewsletterAutomation($this->db,$this->access,$this->mediaRoot,$this->csrf))->render().$this->socialSuggestions('newsletter');
        throw new \DomainException('Unbekannter Arbeitsbereich.',422);
    }

    private function suggestions(string $module,Communication $w): string
    {
        $h='';
        if ($this->can($module, "manage")) {
            if(in_array($module,['press','newsletter'],true))$h.='<details><summary>'.$this->planningLabel('Geplante Vorschläge').'</summary>';
            $h .= '<h2>'.$this->planningLabel('Geplante Vorschläge').'</h2>';
            foreach ($w->automations() as $automation) {
                $h .=
                    '<article class="panel"><p>' .
                    Html::e(
                        $this->mediaTime($automation["due_at"]) .
                            " · " .
                            $this->planningText(['enabled'=>'Vorbereitung geplant','paused'=>'Vorbereitung pausiert','prepared'=>'Vorbereitet','cancelled'=>'Gestoppt','outdated'=>'Inhalt geändert · erneut prüfen'][$automation['status']]??'Status prüfen'),
                    ) .
                    "</p>";
                if (
                    in_array($automation["status"], ["enabled", "paused"], true)
                ) {
                    $h .=
                        $this->start("communication-pause") .
                        Html::hidden("automation_id", $automation["id"]) .
                        Html::hidden("version", $automation["version"]) .
                        Html::hidden(
                            "paused",
                            $automation["status"] === "enabled" ? "1" : "0",
                        ) .
                        '<button class="btn btn-solid theme-primary">' .
                        $this->planningLabel($automation["status"] === "enabled"
                            ? "Pausieren"
                            : "Fortsetzen") .
                        "</button></form>";
                }
                $h .= "</article>";
            }
            if(in_array($module,['press','newsletter'],true))$h.='</details>';
        }
        return $h;
    }

    private function social():string
    {
        $this->access->require('social','read');
        $view=$_GET['planning_view']??(($_GET['tab']??null)==='automation'?'automation':null);
        if($view!==null&&(!is_string($view)||!in_array($view,['month','history','automation'],true)))throw new \DomainException('Planungsansicht prüfen.');
        if($view==='automation')return (new EventPreparationPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render().$this->socialSuggestions();
        $legacyHistory=count(array_intersect(['queue_job','queue_page','queue_provider','queue_state'],array_keys($_GET)))>0;
        if($view==='month'||($view===null&&!$legacyHistory))return '<p><a class="btn btn-outline theme-secondary" data-nav href="/?view=social&amp;section=planning&amp;planning_view=automation#event-preparation">'.Html::e(\Kulturhus\Native\Language\EventPreparationMessages::text('Automatische Veranstaltungsentwürfe',(new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->messages())).'</a></p>'.(new MonthPlanner($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($_GET);
        try{$box=$this->box();}catch(\DomainException){return '<h2>'.$this->planningLabel('Planung & Verlauf').'</h2><p>'.$this->planningLabel('Privater Veröffentlichungszugang nicht verfügbar.').'</p>'.($this->can('website','manage')?'<a class="btn btn-outline theme-secondary" data-nav href="/?view=settings#connections">'.$this->planningLabel('Verbindungen öffnen').'</a>':'');}
        $result=(new \Kulturhus\Native\Communication\PublicationCatalogue($this->db,$box))->listing($this->access,$_GET);
        $back=['view'=>'social','section'=>'planning','planning_view'=>'month'];
        if(isset($_GET['plan_month'])){\Kulturhus\Native\Communication\EditorialMonth::query($_GET);foreach(['plan_month','plan_channel','plan_state','plan_step']as$key)if(isset($_GET[$key]))$back[$key]=$_GET[$key];}
        $h='<p><a class="btn btn-outline theme-secondary" data-nav href="/?'.Html::e(http_build_query($back)).'">'.$this->planningLabel('Monat').'</a></p><section class="publication-history" aria-labelledby="publication-heading"><h2 id="publication-heading">'.$this->planningLabel('Planung & Verlauf').'</h2>'.$this->controls($result);
        foreach($result['rows']as$row){
            $platform=['stadtlandfunk'=>'StadtLand.Funk','facebook'=>'Facebook','instagram'=>'Instagram','meta'=>'Meta'][$row['channel']];
            $kind=['event'=>'Veranstaltung','post'=>'Beitrag','story'=>'Story','reel'=>'Reel','carousel'=>'Karussell'][$row['content_kind']]??'Beitrag';
            $status=match($row['status']){'pending'=>'Geplant','processing'=>'Wird veröffentlicht','sent'=>'Veröffentlicht','uncertain'=>'Ergebnis prüfen','cancelled'=>'Gestoppt',default=>'Status prüfen'};
            $h.='<article class="panel publication-row" id="job-'.Html::e($row['id']).'"'.($row['id']===$result['focus']?' tabindex="-1" autofocus data-publication-focus':'').'><div class="publication-row-heading"><span class="badge">'.Html::e($platform.' · '.$this->planningText($kind)).'</span><span class="badge">'.$this->planningLabel($status).'</span></div><h3>'.(!empty($row['title_missing'])?$this->planningLabel('Originalfassung nicht verfügbar'):Html::e($row['title'])).'</h3><p>'.Html::e($this->mediaTime($row['due_at'])).'</p><div class="publication-row-actions">';
            if($row['item_id']!==null)$h.='<a class="btn btn-outline theme-secondary" data-nav href="/?'.Html::e(http_build_query(['view'=>'social','item'=>$row['item_id']])).'">'.$this->planningLabel('Redaktion öffnen').'</a>';
            if($row['permalink']!==null)$h.='<a class="btn btn-outline theme-secondary" href="'.Html::e($row['permalink']).'" target="_blank" rel="noopener">'.$this->planningLabel('Veröffentlichung öffnen').'</a>';
            if(isset($row['carousel_ready'])&&$row['status']!=='sent')$h.='<span class="badge">'.$this->planningLabel('{ready} / {count} Bilder vorbereitet',['ready'=>$row['carousel_ready'],'count'=>$row['carousel_count']]).'</span>';
            if(!empty($row['carousel_stop'])&&$this->can('social','publish'))$h.=$this->start('meta-carousel-stop').Html::hidden('job_id',$row['id']).'<details><summary>'.$this->planningLabel('Vorbereitung stoppen').'</summary><label class="check"><input class="check" type="checkbox" name="confirm_stop" value="1" required>'.$this->planningLabel('Vorbereitung beenden').'</label><button class="btn btn-outline theme-secondary">'.$this->planningLabel('Stoppen').'</button></details></form>';
            $stale=$row['status']==='processing'&&$row['started_at']!==null&&strtotime($row['started_at'].' UTC')<=time()-600;
            if($this->can('social','publish')&&($row['kind']==='stadtlandfunk'?(in_array($row['status'],['sent','uncertain'],true)||$stale):($row['has_result']&&(in_array($row['status'],['sent','uncertain'],true)||($row['content_kind']==='carousel'&&$stale))))){
                $h.=$this->start($row['kind']==='stadtlandfunk'?'stadtlandfunk-reconcile':'meta-reconcile').Html::hidden('job_id',$row['id']).'<button class="btn btn-outline theme-secondary">'.$this->planningLabel('Status abgleichen').'</button></form>';
            }
            $h.='</div></article>';
        }
        if(!$result['rows'])$h.='<p>'.$this->planningLabel('Keine Veröffentlichungsaufträge.').'</p>';
        return $h.$this->controls($result,false).$this->socialSuggestions().'</section>';
    }

    private function socialSuggestions(string $module='social'):string
    {
        $club=$this->access->require($module,'read')['club_id'];
        $manage=$this->can($module,'manage');
        $rows=(new Communication($this->db,$this->access,$module))->automations();if(!$rows)return '<p>'.$this->planningLabel('Keine automatischen Vorbereitungen.').'</p>';
        $t=$this->planningLabel(...);
        $h='<details class="publication-suggestions"><summary>'.$t('Geplante Vorschläge').' · '.count($rows).'</summary><div class="publication-suggestions-list" tabindex="0" role="region" aria-label="'.$t('Geplante Vorschläge').'">';
        foreach($rows as$row){
            $original=\Kulturhus\Native\Shared\Db::run($this->db,"SELECT JSON_UNQUOTE(JSON_EXTRACT(payload,'$.title')) title,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.kind')) kind FROM ".\Kulturhus\Native\Communication\Schema::table($module,'revisions')." WHERE club_id=? AND item_id=? AND version=?",[$club,$row['item_id'],$row['source_version']])->fetch();$title=$original['title']??null;
            $format=$module==='newsletter'?'Newsletterausgabe':(['post'=>'Beitrag','story'=>'Story','reel'=>'Reel','carousel'=>'Karussell'][$original['kind']??'']??'Status prüfen');
            $month=(new \DateTimeImmutable($row['due_at'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m');
            $url='/?'.http_build_query(['view'=>$module,'section'=>'planning','planning_view'=>'month','plan_month'=>$month,'plan_step'=>'automation']).'#plan-'.($module==='newsletter'?substr(hash('sha256','newsletter-month:preparation:'.$row['id']),0,32):$row['id']);
            $h.='<article class="panel"><span class="badge">'.$t($format).'</span><h4>'.(is_string($title)?Html::e($title):$t('Originalfassung nicht verfügbar')).'</h4><p>'.Html::e($this->mediaTime($row['due_at'])).' · '.$t(['enabled'=>'Vorbereitung geplant','paused'=>'Vorbereitung pausiert','prepared'=>'Vorbereitet','cancelled'=>'Gestoppt','outdated'=>'Inhalt geändert · erneut prüfen'][$row['status']]??'Status prüfen').'</p><a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($url).'">'.$t('Im Monat ansehen').'</a>';
            if($manage&&in_array($row['status'],['enabled','paused'],true))$h.=$this->start('communication-pause').Html::hidden('automation_id',$row['id']).Html::hidden('version',$row['version']).Html::hidden('paused',$row['status']==='enabled'?'1':'0').'<button class="btn btn-outline theme-secondary">'.$t($row['status']==='enabled'?'Pausieren':'Fortsetzen').'</button></form>';
            $h.='</article>';
        }
        if($this->access->require($module,'read')['club_id']!==$club)throw new \DomainException('Vereinszuordnung während Planung geändert.',409);return $h.'</div></details>';
    }

    private function controls(array $result,bool $filter=true):string
    {
        $route=PlanningFormState::route('social',array_replace($_GET,['planning_view'=>'history']));unset($route['queue_job']);
        $month='';foreach(['plan_month','plan_channel','plan_state','plan_step']as$key)if(isset($route[$key]))$month.=Html::hidden($key,$route[$key]);
        $h='<div class="publication-controls">';
        if($filter)$h.='<form method="get" class="publication-filters">'.Html::hidden('view','social').Html::hidden('section','planning').Html::hidden('planning_view','history').$month.Html::select('queue_provider',$this->planningText('Plattform'),array_map($this->planningText(...),['all'=>'Alle','meta'=>'Facebook / Instagram','stadtlandfunk'=>'StadtLand.Funk']),$result['provider']).Html::select('queue_state',$this->planningText('Status'),array_map($this->planningText(...),['all'=>'Alle','pending'=>'Geplant','processing'=>'Wird veröffentlicht','sent'=>'Veröffentlicht','uncertain'=>'Ergebnis prüfen','cancelled'=>'Gestoppt']),$result['state']).'<button class="btn btn-outline theme-secondary">'.$this->planningLabel('Anzeigen').'</button></form>';
        $h.='<nav class="website-pagination" aria-label="'.$this->planningLabel('Veröffentlichungsseiten').'"><span>'.$this->planningLabel('{count} Aufträge · {page}/{pages}',['count'=>$result['total'],'page'=>$result['page'],'pages'=>$result['pages']]).'</span>';
        foreach([-1=>'Zurück',1=>'Weiter']as$direction=>$label){$page=$result['page']+$direction;if($page>0&&$page<=$result['pages'])$h.='<a class="btn btn-outline theme-secondary" data-nav href="/?'.Html::e(http_build_query(array_replace($route,['queue_page'=>$page,'queue_provider'=>$result['provider'],'queue_state'=>$result['state']]))).'">'.$this->planningLabel($label).'</a>';}
        return $h.'</nav></div>';
    }
}
