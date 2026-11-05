<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Settings;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Communication\{MetaWebhookConfiguration,MetaWebhookCatalogue};
use Kulturhus\Native\Shared\Db;

/** Compact optional receiver controls; stored keys are never rendered into forms. */
final class MetaWebhookPanel extends Workspace
{
    public function render(array $failed=[]):string
    {
        if(!$this->can('social','manage'))return'';$service=new MetaWebhookConfiguration($this->db,$this->box());$state=$service->status($this->access);
        $label=!$state['installed']?'Einrichtung aktualisieren':(!$state['enabled']?'Aus':($state['current']?'Ein':'Verbindung prüfen'));
        $expanded=isset($_GET['webhook_page'])||isset($_GET['webhook_focus'])||in_array($_POST['operation']??null,['meta-webhook-save','meta-webhook-resume'],true);
        $h='<details class="panel meta-webhook-panel" id="meta-webhook-settings"'.($expanded?' open':'').'><summary>Meta-Webhooks · '.Html::e($label).'</summary>';
        if(!$state['installed'])return$h.'</details>';
        $version=$state['version'];$enabled=$state['enabled'];
        if($failed){try{$version=IndexingPanel::version($failed);$enabled=($failed['enabled']??null)==='1';}catch(\DomainException){}}
        $h.=$this->start('meta-webhook-save').Html::hidden('version',$version).Html::hidden('webhook_present','1').'<label class="check"><input class="check" type="checkbox" name="enabled" value="1"'.($enabled?' checked':'').'>Neue Nachrichten, Kommentare und Reaktionen empfangen</label>'.Html::input('app_secret','Meta-App-Secret','','password','autocomplete="new-password" maxlength="32"').Html::input('verify_token','Eigener Verifikationstoken','','password','autocomplete="new-password" maxlength="256"').'<button class="btn btn-solid theme-primary">Empfang speichern</button></form>';
        if($state['callback']!=='')$h.=Html::input('webhook_callback','Callback-Adresse',$state['callback'],'url','readonly').'<p>Callback-Adresse und Verifikationstoken bei Meta hinterlegen.</p>';
        $time=static fn($value)=>$value===null?'Noch keiner':(new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y. H:i');
        $h.='<dl><dt>Letzter geprüfter Eingang</dt><dd>'.Html::e($time($state['received'])).'</dd><dt>Letzter API-Abgleich</dt><dd>'.Html::e($time($state['completed'])).'</dd><dt>Offene Eingänge</dt><dd>'.$state['pending'].'</dd></dl>';
        $page=(new MetaWebhookCatalogue($this->db))->page($this->access,$_GET);
        if($page['total']){$h.='<details'.(isset($_GET['webhook_page'])||isset($_GET['webhook_focus'])?' open':'').'><summary>Eingänge · '.$page['total'].'</summary>';
            foreach($page['rows']as$row){$kind=['message'=>'Nachricht','comment'=>'Kommentar','metric'=>'Reaktionen'][$row['kind']]??'Eingang';$status=['pending'=>'Abgleich steht aus','processing'=>'Wird abgeglichen','done'=>'Abgeglichen','paused'=>'Zurückgestellt','failed'=>'Ergebnis prüfen'][$row['state']]??'Prüfen';$h.='<article class="panel" id="webhook-'.Html::e($row['id']).'"'.($row['id']===$page['focus']?' tabindex="-1" autofocus':'').'><span class="badge">'.Html::e(($row['channel']==='instagram'?'Instagram':'Facebook').' · '.$kind).'</span><p>'.Html::e($status.' · '.$time($row['received_at'])).'</p>';if($state['current']&&in_array($row['state'],['paused','failed'],true))$h.=$this->start('meta-webhook-resume').Html::hidden('receipt_id',$row['id']).'<button class="btn btn-outline theme-secondary">Abgleich erneut prüfen</button></form>';$h.='</article>';}
            if($page['pages']>1){$h.='<nav class="actions" aria-label="Eingangsseiten">';foreach([$page['page']-1=>'Neuere Eingänge',$page['page']+1=>'Ältere Eingänge']as$number=>$label)if($number>=1&&$number<=$page['pages'])$h.='<a class="btn btn-outline theme-secondary" data-nav href="/?view=settings&amp;webhook_page='.$number.'#meta-webhook-settings">'.$label.'</a>';$h.='<span>Seite '.$page['page'].' von '.$page['pages'].'</span></nav><form method="get" class="actions">'.Html::hidden('view','settings').Html::input('webhook_page','Eingangsseite',$page['page'],'number','min="1" max="'.$page['pages'].'" inputmode="numeric" required').'<button class="btn btn-outline theme-secondary">Seite öffnen</button></form>';}$h.='</details>';
        }
        return$h.'</details>';
    }
}
