<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use Throwable;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Communication\{EventDrafts,EventDraftRequests};
use Kulturhus\Native\Cms\EventDetails;

/** The existing manager selects one exact event; an interrupted response retains the same intention. */
final class EventSources extends Workspace
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    public static function applies(string $view,array $post,array $query=[]):bool
    {return in_array($view,['social','press'],true)&&(($query['section']??null)==='events'||($post['operation']??null)===$view.'-event-draft');}
    public static function restore(array $post):array
    {
        if(!is_string($post['event_request_key']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$post['event_request_key'])||!is_array($post['event']??null))throw new DomainException('Ursprüngliches Veranstaltungsformular öffnen.');
        return ['key'=>$post['event_request_key'],'input'=>EventDraftRequests::capture($post['event'])];
    }
    public function action(string $module,array $post):array
    {
        $this->validatePost($post);$state=self::restore($post);$service=new EventDraftRequests($this->db,$this->access,$this->mediaRoot,$module);$service->assertContext($state['input']);
        try{
            if(isset($post['event_review']))$state['result']=$service->check($state['key'],$state['input']);
            elseif(isset($post['event_another'])){$next=$service->another($state['key'],$state['input']);$state=array_replace($state,$next);$state['prepared']=true;}
            else $state['result']=$service->save($state['key'],$state['input']);
        }catch(DomainException $error){http_response_code(422);$state['error']=$error->getMessage();}
        catch(Throwable){http_response_code(503);$state['error']='Veranstaltungsübernahme nicht bestätigt. Ursprüngliche Auswahl behalten und Ergebnis prüfen.';}
        return ['view'=>$module,'section'=>'events','notice'=>'','compose_state'=>['event_selection'=>$state],'event_draft_response'=>['ok'=>!isset($state['error']),'error'=>isset($state['error'])?$this->contentText($state['error']):null,'result'=>$state['result']??null,'prepared'=>$state['prepared']??false]];
    }
    private function t(string $source,array $values=[]):string{return Html::e($this->contentFormat($source,$values));}
    private function url(string $module,array $extra=[]):string{return '/?'.http_build_query(['view'=>$module,'section'=>'events']+$extra);}
    private function finish(string $html,array $context,string $module):string
    {if((new EventDraftRequests($this->db,$this->access,$this->mediaRoot,$module))->context()!==$context)throw new DomainException('Person oder Vereinszuordnung geändert.');return $html;}
    public function manager(string $module='social',?array $state=null):string
    {
        $service=new EventDraftRequests($this->db,$this->access,$this->mediaRoot,$module);$context=$service->context();
        $h='<section class="event-draft-workspace"><header class="social-library-heading"><div><h2>'.$this->t($module==='press'?'Veranstaltung für Presse auswählen':'Veranstaltung teilen').'</h2><p>'.$this->t('Die ausgewählte Fassung wird als eigener Entwurf übernommen. Text und Bild anschließend prüfen und bewusst freigeben.').'</p></div><a class="btn btn-outline theme-secondary" data-save-original data-navigation-native target="_blank" rel="noopener" href="'.Html::e('/?'.http_build_query(['view'=>$module,'section'=>'compose'])).'">'.$this->t($module==='press'?'Freie Mitteilung anlegen':'Freien Beitrag gestalten').'</a></header>';
        if($module==='social'&&isset($_GET['event_plan'])&&$state===null)return $this->planned($h,$context);
        if(isset($_GET['event_request'])){
            if(!is_string($_GET['event_request']))throw new DomainException('Ursprünglichen Veranstaltungsvorgang auswählen.');
            $h.=$this->result($service->review($_GET['event_request']),$module);
            return $this->finish($h.'<a class="btn btn-outline theme-secondary" data-save-original data-navigation-native href="'.Html::e($this->url($module)).'">'.$this->t('Veranstaltung neu auswählen').'</a></section>',$context,$module);
        }
        if($state!==null){
            $service->assertContext($state['input']);
            $h.='<article class="panel event-selection-correction">';
            if(isset($state['error']))$h.='<p role="alert">'.$this->t($state['error']).'</p>';
            if(isset($state['result']))$h.=$this->result($state['result'],$module);
            if($state['prepared']??false)$h.='<p role="status">'.$this->t('Weiterer Entwurf vorbereitet. Die bisherige Übernahme bleibt erhalten. Noch nicht gespeichert.').'</p>';
            $h.=$this->form($module,$state).'<a class="btn btn-outline theme-secondary" data-save-original data-navigation-native target="_blank" rel="noopener" href="'.Html::e($this->url($module,['event_request'=>$state['key']])).'">'.$this->t('Ursprüngliches Ergebnis getrennt prüfen').'</a><a class="btn btn-outline theme-secondary" data-save-original data-navigation-native target="_blank" rel="noopener" href="'.Html::e($this->url($module)).'">'.$this->t('Veranstaltung getrennt neu auswählen').'</a><p>'.$this->t('Prüfen legt keinen Entwurf an. Eine bereits bestätigte Übernahme bleibt ihrer ursprünglichen Auswahl zugeordnet.').'</p></article>';
            return $this->finish($h.$this->history($module).'</section>',$context,$module);
        }
        $value=static function(string $key):string{$v=$_GET[$key]??'';if(!is_string($v))throw new DomainException('Veranstaltungsfilter prüfen.');return$v;};
        $sources=new EventDrafts($this->db,$this->access,$module);$choices=$sources->sources();
        if(!$choices)return $this->finish($h.'<p class="social-empty">'.$this->t('Keine zugängliche Veranstaltungsquelle. Berechtigungen prüfen.').'</p>'.$this->history($module).'</section>',$context,$module);
        $query=['q'=>$value('q'),'seite'=>$value('seite'),'archiv'=>$value('archiv'),'source'=>$value('source')?:array_key_first($choices)];$page=$sources->listing($query,6);
        $h.='<form method="get" data-navigation-native class="social-filters">'.Html::hidden('view',$module).Html::hidden('section','events').Html::select('source',$this->contentText('Veranstaltungsquelle'),$this->contentOptions($choices),$query['source']).Html::input('q',$this->contentText('Veranstaltung suchen'),$query['q']).Html::select('archiv',$this->contentText('Zeitraum'),$this->contentOptions([''=>'Kommende Termine','1'=>'Vergangene Termine']),$query['archiv']).'<button class="btn btn-solid theme-primary">'.$this->t('Suchen').'</button></form><p role="status">'.$this->t('{count} Veranstaltungen · Seite {page} von {pages}',['count'=>$page['total'],'page'=>$page['page'],'pages'=>$page['pages']]).'</p>';
        if($query['source']==='manager')$h.='<p>'.$this->t('Kalendertermine können interne Angaben enthalten. Bestätige die bewusste Übernahme. Teilnehmer, Rückmeldungen und Berichtsnotizen bleiben im Kalender.').'</p>';
        $locale=explode('-',(new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->locale(),2)[0]==='en'?'en':'de';
        $h.='<div class="social-content-grid">';
        foreach($page['rows']as$event){
            $label=$event['event_source']==='manager'?(($event['_manager_original']['activity_kind']??'')==='external'?'Externer Kalendertermin':'Vereinskalender'):($event['event_source']==='local'?'Eigene Veranstaltung':null);
            $h.='<article class="panel social-event-source" data-event-source="'.Html::e($event['id']).'"><span class="badge">'.($label!==null?$this->t($label):$this->t('Import · {source}',['source'=>['facebook'=>'Facebook','eventbrite'=>'Eventbrite','stadtlandfunk'=>'StadtLand.Funk'][$event['event_source']]??$event['event_source']])).'</span><h3>'.Html::e($event['title']).'</h3><p>'.Html::e(EventDetails::start($event)->setTimezone(new \DateTimeZone('Europe/Berlin'))->format($locale==='en'?'Y-m-d H:i':'d.m.Y H:i')).' Europe/Berlin</p><p>'.Html::e(trim(($event['event_location']??'').' '.($event['event_address']??''))).'</p>';
            if($event['event_status']==='cancelled')$h.='<p class="badge">'.$this->t('Abgesagt').'</p>';
            if($this->can($module,'edit')){
                $input=$context+['source'=>$query['source'],'event_id'=>$event['id'],'revision'=>(string)$event['published_version'],'internal_confirm'=>'0','locale'=>$locale];
                $h.=$this->form($module,['key'=>bin2hex(random_bytes(16)),'input'=>EventDraftRequests::capture($input)]);
            }
            $h.='</article>';
        }
        if(!$page['rows'])$h.='<p class="social-empty">'.$this->t('Keine zugänglichen Veranstaltungen in dieser Quelle und diesem Zeitraum. Quelle oder Zeitraum wechseln.').'</p>';
        $h.='</div><nav class="website-pagination" aria-label="'.$this->t('Veranstaltungsseiten').'">';
        foreach([-1=>'Zurück',1=>'Weiter']as$step=>$label){$next=$page['page']+$step;if($next>0&&$next<=$page['pages'])$h.='<a class="btn btn-outline theme-secondary" data-save-original data-navigation-native href="'.Html::e($this->url($module,array_replace($query,['seite'=>$next]))).'">'.$this->t($label).'</a>';}
        return $this->finish($h.'</nav>'.$this->history($module).'</section>',$context,$module);
    }
    private function planned(string $h,array $context):string
    {
        $key=$_GET['event_plan'];if(!is_string($key)||!preg_match('/\Aevent:([a-f0-9]{32}):(post|story|reel):(initial|d14|d7|d3|d1|d0)\z/D',$key,$match))throw new DomainException('Ursprüngliche Veranstaltungsauswahl prüfen.',422);
        $result=(new \Kulturhus\Native\Communication\EditorialMonth($this->db,$this->box()))->listing($this->access,['plan_month'=>$_GET['plan_month']??'']);$selected=null;
        foreach($result['rows']as$row)if($row['id']===$key){$selected=$row;break;}
        if(!$selected)throw new DomainException('Veranstaltungsplanung inzwischen geändert. Kalender erneut öffnen.',409);
        $event=(new \Kulturhus\Native\Cms\Events($this->db,$this->access->club(),$this->access))->publicId($match[1],true);
        if(!$event)throw new DomainException('Veranstaltungsfassung inzwischen geändert oder zurückgezogen. Bitte neu auswählen.',409);
        $h.='<article class="panel" data-event-plan="'.Html::e($key).'"><h3>'.Html::e($selected['title']).'</h3><p>'.Html::e($this->mediaTime($selected['due_at'])).' · '.$this->t(['post'=>'Beitrag','story'=>'Story','reel'=>'Reel'][$match[2]]).'</p>';
        if($selected['target_item_id'])$h.='<a class="btn btn-solid theme-primary" data-nav href="'.Html::e('/?'.http_build_query(['view'=>'social','section'=>'compose','item'=>$selected['target_item_id']]).($match[2]==='story'?'#social-image-editor':'')).'">'.$this->t('Entwurf öffnen').'</a>';
        elseif($this->can('social','edit')){
            $input=$context+['source'=>'website','event_id'=>$event['id'],'revision'=>(string)$event['published_version'],'internal_confirm'=>'0','locale'=>'de','format'=>$match[2]];
            if($match[3]!=='initial')$input['label']=match($match[3]){'d0'=>'HEUTE','d1'=>'MORGEN',default=>'NOCH '.substr($match[3],1).' TAGE'};
            $h.=$this->form('social',['key'=>bin2hex(random_bytes(16)),'input'=>EventDraftRequests::capture($input)]);
        }
        return $this->finish($h.'</article></section>',$context,'social');
    }

    private function form(string $module,array $state):string
    {
        $unsaved=isset($state['error'])||($state['prepared']??false)||isset($state['result'])&&!$state['result']['found'];
        $h='<form method="post" data-navigation-native data-event-draft-form'.($unsaved?' data-unsaved-changes':'').'>'.Html::hidden('csrf',$this->csrf).Html::hidden('operation',$module.'-event-draft').Html::hidden('event_request_key',$state['key']);
        foreach($state['input']as$key=>$value)if($key!=='internal_confirm')$h.=Html::hidden('event['.$key.']',$value);
        $h.=Html::hidden('event[internal_confirm]','0');
        if($state['input']['source']==='manager')$h.='<label class="event-source-confirm"><input type="checkbox" name="event[internal_confirm]" value="1" required'.($state['input']['internal_confirm']==='1'?' checked':'').'> '.$this->t($module==='press'?'Diesen Kalendertermin für einen Presseentwurf übernehmen.':'Dieser Kalendertermin ist für einen Social-Beitrag bestimmt.').'</label>';
        $h.='<div class="actions"><button class="btn btn-solid theme-primary">'.$this->t($module==='press'?'Presseentwurf vorbereiten':'Social-Entwurf vorbereiten').'</button><button class="btn btn-outline theme-secondary" name="event_review" value="1" formnovalidate>'.$this->t('Ergebnis prüfen').'</button>';
        if(($state['result']['found']??false))$h.='<button class="btn btn-outline theme-secondary" name="event_another" value="1" formnovalidate>'.$this->t('Weiteren eigenen Entwurf vorbereiten').'</button>';
        return $h.'</div></form>';
    }
    private function history(string $module):string
    {
        $page=$_GET['event_history_page']??'1';if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page))throw new DomainException('Veranstaltungsseite prüfen.');
        $history=(new EventDraftRequests($this->db,$this->access,$this->mediaRoot,$module))->history((int)$page);$h='<details class="event-import-history" data-event-history><summary>'.$this->t('Eigene bestätigte Übernahmen').'</summary><div class="actions">';
        foreach($history['rows']as$row)$h.='<a class="btn btn-outline theme-secondary" data-navigation-native data-save-original target="_blank" rel="noopener" href="'.Html::e($this->url($module,['event_request'=>$row['request_key']])).'">'.Html::e($row['title']).' · '.$this->t('Übernahme {time}',['time'=>$this->mediaTime($row['created_at'])]).'</a>';
        $h.='</div><nav aria-label="'.$this->t('Bestätigte Veranstaltungsübernahmen').'">';
        foreach([-1=>'Vorherige Übernahmen',1=>'Weitere Übernahmen']as$step=>$label){$next=$history['page']+$step;if($next>0&&$next<=$history['pages'])$h.='<a class="btn btn-outline theme-secondary" data-navigation-native data-save-original target="_blank" rel="noopener" href="'.Html::e($this->url($module,['event_history_page'=>$next])).'">'.$this->t($label).'</a>';}
        return$h.'</nav></details>';
    }
    private function result(array $result,string $module):string
    {
        if(!$result['found'])return '<p role="status">'.$this->t('Derzeit keine ursprüngliche Übernahme gefunden. Ein laufender Speichervorgang ist damit nicht ausgeschlossen.').'</p>';
        $time=$this->mediaTime($result['created_at']);
        return '<div data-event-original="'.Html::e($result['key']).'"><p role="status">'.$this->t('Ursprünglicher Entwurf gespeichert.').' · '.Html::e($time).'</p><h3>'.Html::e($result['snapshot']['title']).'</h3><p class="event-original-body">'.Html::e($result['snapshot']['body']).'</p><a class="btn btn-outline theme-secondary" data-save-original data-navigation-native target="_blank" rel="noopener" href="'.Html::e('/?'.http_build_query(['view'=>$module,'section'=>'compose','item'=>$result['id']])).'">'.$this->t('Entwurf öffnen').'</a></div>';
    }
}
