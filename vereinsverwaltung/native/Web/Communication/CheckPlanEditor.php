<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use Throwable;
use Kulturhus\Native\Communication\{CheckPlans,CheckPlanRequests};
use Kulturhus\Native\Web\{Workspace,Html};

/** A separate existing-manager workspace preserves an unsent human reply. */
final class CheckPlanEditor extends Workspace
{
    use ConversationInterfaceText;
    public static function restore(array $post):array
    {
        if(!is_string($post['plan_key']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$post['plan_key'])||!is_array($post['plan']??null))throw new DomainException('Ursprüngliches Abrufplanformular öffnen.');
        return ['key'=>$post['plan_key'],'input'=>CheckPlanRequests::capture($post['plan'])];
    }
    public function action(array $post):array
    {
        $this->validatePost($post);$state=self::restore($post);$service=new CheckPlanRequests($this->db,$this->access);$service->assertContext($state['input']);
        try{
            if(isset($post['plan_review']))$state['result']=$service->check($state['key'],$state['input']);
            elseif(isset($post['plan_prepare'])){
                $proposal=$service->prepare($state['key'],$state['input']);$state['result']=$proposal['original'];
                if($proposal['prepared']!==null){$state=array_replace($state,$proposal['prepared']);$state['prepared']=true;}
            }else $state['result']=$service->save($state['key'],$state['input']);
        }catch(DomainException $error){http_response_code(422);$state['error']=$error->getMessage();}
        catch(Throwable){http_response_code(503);$state['error']='Abrufplan nicht bestätigt. Ursprüngliche Angaben behalten und Ergebnis prüfen.';}
        return ['view'=>'social','section'=>'sync','notice'=>'','compose_state'=>['check_plan'=>$state],'check_plan_response'=>['ok'=>!isset($state['error']),'error'=>isset($state['error'])?$this->conversationText($state['error']):null,'result'=>$state['result']??null,'prepared'=>$state['prepared']??false]];
    }
    private function initial(string $channel):array
    {
        $service=new CheckPlanRequests($this->db,$this->access);$s=(new CheckPlans($this->db))->status($this->access,$channel);
        $input=$service->context()+['channel'=>$channel,'version'=>(string)$s['version'],'enabled'=>$s['enabled']?'1':'0'];
        if($channel==='meta')$input['interval_seconds']=(string)$s['config']['interval_seconds'];
        else{$input['day_start']=(string)$s['config']['day_start'];$input['day_end']=(string)$s['config']['day_end'];}
        return ['key'=>bin2hex(random_bytes(16)),'input'=>CheckPlanRequests::capture($input)];
    }
    private function url(string $channel,array $extra=[]):string
    {return '/?'.http_build_query(['view'=>'social','section'=>'sync','check_channel'=>$channel]+$extra);}
    private function t(string $source,array $values=[]):string{return Html::e($this->conversationText($source,$values));}
    public function manager(?array $state=null):string
    {
        $service=new CheckPlanRequests($this->db,$this->access);$actor=$service->context();
        $channel=$state['input']['channel']??($_GET['check_channel']??'meta');if(!is_string($channel)||!in_array($channel,['meta','stadtlandfunk'],true))throw new DomainException('Abrufkanal prüfen.');
        $h='<section class="card social-check-editor"><h2>'.$this->t('Automatische Abrufzeiten').'</h2><p>'.$this->t('Hier werden Nachrichten und Kommentare abgeglichen. Beiträge werden dadurch nicht veröffentlicht.').'</p><nav class="check-plan-channels" aria-label="'.$this->t('Abrufkanal').'">';
        foreach(['meta'=>'Facebook & Instagram','stadtlandfunk'=>'StadtLand.Funk']as$key=>$label)$h.='<a class="btn btn-outline theme-secondary" data-save-original data-navigation-native href="'.Html::e($this->url($key)).'"'.($key===$channel?' aria-current="page"':'').'>'.Html::e($label).'</a>';
        $h.='</nav>';
        $h.='<p>'.$this->t($channel==='meta'?'Meta wird rund um die Uhr im gewählten Intervall abgeglichen.':'StadtLand.Funk wird tagsüber in jeder Stunde zu einer neu zufällig gewählten Zeit abgeglichen.').'</p>';
        if(isset($_GET['check_request'])){
            if(!is_string($_GET['check_request']))throw new DomainException('Ursprünglichen Abrufplanvorgang auswählen.');
            $original=$service->review($channel,$_GET['check_request']);
            $h.=$this->result($original);
            if($original['found'])$h.=$this->values($original['input'],false);
            return $this->finish($h.'<a class="btn btn-outline theme-secondary" data-save-original data-navigation-native href="'.Html::e($this->url($channel)).'">'.$this->t('Aktuellen Plan bearbeiten').'</a></section>',$actor);
        }
        $state??=$this->initial($channel);$service->assertContext($state['input']);
        if(isset($state['error']))$h.='<p role="alert">'.$this->t($state['error']).'</p>';
        if(isset($state['result']))$h.=$this->result($state['result']);
        if($state['prepared']??false)$h.='<p role="status">'.$this->t('Aktueller Stand vorbereitet. Zum Speichern ausdrücklich bestätigen.').'</p>';
        $unsaved=isset($state['error'])||($state['prepared']??false)||isset($state['result'])&&!$state['result']['found'];
        $h.='<form method="post" data-navigation-native data-check-plan-form'.($unsaved?' data-unsaved-changes':'').'>'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','social-check-plan').Html::hidden('plan_key',$state['key']);
        foreach(['actor_id','club_id','authority','channel','version']as$key)$h.=Html::hidden('plan['.$key.']',$state['input'][$key]);
        $h.=$this->values($state['input'],true).'<div class="actions"><button class="btn btn-solid theme-primary">'.$this->t('Zeiten speichern').'</button><button class="btn btn-outline theme-secondary" name="plan_review" value="1" formnovalidate>'.$this->t('Ergebnis prüfen').'</button><button class="btn btn-outline theme-secondary" name="plan_prepare" value="1" formnovalidate>'.$this->t('Aktuellen Stand vorbereiten').'</button></div></form>';
        $h.='<a class="btn btn-outline theme-secondary" data-save-original data-navigation-native target="_blank" rel="noopener" href="'.Html::e($this->url($channel)).'">'.$this->t('Gespeicherten Plan getrennt prüfen').'</a><p>'.$this->t('Prüfen speichert nichts. Ein bereits gespeicherter Vorgang bleibt seiner ursprünglichen Fassung zugeordnet.').'</p>';
        $current=(new CheckPlans($this->db))->status($this->access,$channel);$h.='<details class="settings-panel"><summary>'.$this->t('Aktuell gespeicherter Plan').'</summary><p>'.$this->t('Fassung {version}',['version'=>$current['version']]).' · '.$this->t($current['enabled']?'Aktiv':'Pausiert').'</p>';
        if($current['next_at']!==null)$h.='<p>'.$this->t('Nächster Termin: {time}',['time'=>$this->conversationDate($current['next_at'])]).'</p>';
        if($current['last_checked']!==null)$h.='<p>'.$this->t('Letzter Abruf: {time}',['time'=>$this->conversationDate($current['last_checked'])]).'</p>';
        $h.=$this->values($this->initial($channel)['input'],false);$h.='<p>'.$this->t(match($current['state']){'paused'=>'Pausiert','scheduled'=>'Geplant','checking'=>'Prüfung läuft','checked'=>'Abruf bestätigt','cooldown'=>'Anbieterpause',default=>'Verbindung oder Abruf prüfen'}).'</p></details>';
        $page=$_GET['check_page']??'1';if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page))throw new DomainException('Abrufplanseite prüfen.');
        $history=$service->history($channel,(int)$page);$h.='<details class="settings-panel" data-check-plan-history><summary>'.$this->t('Eigene gespeicherte Änderungen').'</summary><div class="check-plan-history">';
        foreach($history['rows']as$row)$h.='<a class="btn btn-outline theme-secondary" data-save-original data-navigation-native target="_blank" rel="noopener" href="'.Html::e($this->url($channel,['check_request'=>$row['request_key']])).'">'.$this->t('Fassung {version}',['version'=>$row['version']]).' · '.Html::e($this->conversationDate($row['created_at'])).'</a>';
        $h.='</div><nav aria-label="'.$this->t('Gespeicherte Abrufpläne').'">';
        foreach([-1=>'Vorherige Änderungen',1=>'Weitere Änderungen']as$direction=>$label){$next=$history['page']+$direction;if($next>=1&&$next<=$history['pages'])$h.='<a class="btn btn-outline theme-secondary" data-save-original data-navigation-native target="_blank" rel="noopener" href="'.Html::e($this->url($channel,['check_page'=>$next])).'">'.$this->t($label).'</a>';}
        return $this->finish($h.'</nav></details></section>',$actor);
    }
    private function finish(string $h,array $actor):string
    {if((new CheckPlanRequests($this->db,$this->access))->context()!==$actor)throw new DomainException('Person oder Vereinszuordnung geändert.');return $h;}
    private function result(array $result):string
    {
        if(!$result['found'])return '<p role="status">'.$this->t('Derzeit keine ursprüngliche Bestätigung gefunden. Ein laufender Speichervorgang ist damit nicht ausgeschlossen.').'</p>';
        return '<p role="status" data-check-plan-original="'.Html::e($result['key']).'">'.$this->t('Ursprüngliche Fassung {version} gespeichert.',['version'=>$result['result']['version']]).' · '.Html::e($this->conversationDate($result['created_at'])).'</p>';
    }
    private function values(array $input,bool $edit):string
    {
        $h='<div class="form-grid">';
        foreach(['enabled'=>'Automatisch abgleichen']as$key=>$label){
            $h.=$edit?Html::select('plan['.$key.']',$this->conversationText($label),['0'=>$this->conversationText('Nein'),'1'=>$this->conversationText('Ja')],$input[$key]):'<p>'.$this->t($label).': '.$this->t($input[$key]==='1'?'Ja':'Nein').'</p>';
        }
        $fields=$input['channel']==='meta'?['interval_seconds'=>'Intervall (Sekunden)']:['day_start'=>'Tagesbeginn (Stunde)','day_end'=>'Tagesende (Stunde)'];
        foreach($fields as$key=>$label)$h.=$edit?Html::input('plan['.$key.']',$this->conversationText($label),$input[$key],'text','inputmode="numeric" maxlength="200" required'):'<p>'.$this->t($label).': <span data-original-content>'.Html::e($input[$key]).'</span></p>';
        return $h.'</div>'.($input['channel']==='stadtlandfunk'?'<p>'.$this->t('08:00 bis 22:00 bedeutet einen zufälligen Abruf in jeder Stunde von 08 bis 21 Uhr.').'</p>':'');
    }
}
