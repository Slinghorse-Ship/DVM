<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;

use Kulturhus\Native\Communication\NewsletterDeliveryCatalogue as Catalogue;
use Kulturhus\Native\Web\{Workspace,Html};

final class NewsletterDeliveryPanel extends Workspace
{
    private array $messages=[];
    private function text(string $source,array $values=[]):string{return \Kulturhus\Native\Language\EditorialHubMessages::text($source,$this->messages,$values);}
    private function label(string $source,array $values=[]):string{return Html::e($this->text($source,$values));}
    public function render(): string
    {
        $this->messages=(new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->messages();
        $page=Catalogue::page($this->db,$this->access,$this->box(),$_GET);$e=Html::e(...);
        $route=PlanningFormState::route('newsletter',array_replace($_GET,['planning_view'=>'history']));unset($route['newsletter_job']);$route=array_replace($route,['newsletter_state'=>$page['state'],'newsletter_kind'=>$page['kind'],'newsletter_item'=>$page['item'],'newsletter_page'=>$page['page']]);
        $url=static fn(array $extra=[]):string=>'/?'.http_build_query(array_replace($route,$extra),'','&',PHP_QUERY_RFC3986);
        $subject=fn(array $r):string=>$r['subject']!==''?$r['subject']:$this->text(Catalogue::KINDS[$r['kind']]??'Newslettermail');
        $h='<section class="newsletter-deliveries" aria-label="'.$this->label('Newsletter-Zustellverlauf').'"><header class="newsletter-delivery-heading"><h2>'.$this->label('Zustellverlauf').'</h2></header>';
        if($page['selected']){
            $r=$page['selected'];$h.='<section class="panel newsletter-delivery-focus"><header class="newsletter-delivery-heading"><h2>'.$e($subject($r)).'</h2><a class="btn btn-outline theme-secondary" data-nav href="'.$e($url()).'">'.$this->label('Schließen').'</a></header><p>'.($r['removed']?$this->label('Empfänger entfernt'):$e($r['email'])).'</p><p><span class="badge">'.$this->label(Catalogue::STATES[$r['status']]??'Stand prüfen').'</span> · '.$this->label(Catalogue::KINDS[$r['kind']]).'</p><dl><dt>'.$this->label('Geplant für').'</dt><dd>'.$e($this->mediaTime($r['due_at'])).'</dd>';
            if($r['started_at'])$h.='<dt>'.$this->label('Versandversuch').'</dt><dd>'.$e($this->mediaTime($r['started_at'])).'</dd>';
            if($r['completed_at'])$h.='<dt>'.$this->label('Letzter Stand').'</dt><dd>'.$e($this->mediaTime($r['completed_at'])).'</dd>';
            $h.='<dt>'.$this->label('Versandversuche').'</dt><dd>'.(int)$r['attempts'].'</dd></dl>';
            if($r['status']==='uncertain')$h.='<p role="status">'.$this->label('Versandergebnis offen').'</p>';
            if(isset(Catalogue::REASONS[$r['reason']??'']))$h.='<p>'.$this->label(Catalogue::REASONS[$r['reason']]).'</p>';
            if($r['original'])$h.='<p class="newsletter-delivery-original">'.$e($r['original']['title']).'</p>';
            if($r['prepared']&&!$r['removed'])$h.='<details class="newsletter-delivery-message"><summary>'.$this->label('Gespeicherter Mailinhalt').'</summary><div class="newsletter-delivery-text">'.Html::text($r['message']).'</div></details>';
            elseif(!$r['removed'])$h.='<p>'.$this->label('Mailinhalt noch nicht vorbereitet.').'</p>';
            if($r['external_id'])$h.='<details><summary>'.$this->label('Zustellkennung').'</summary><p>'.$e($r['external_id']).'</p></details>';
            $h.='</section>';
        }
        $h.='<details class="panel newsletter-delivery-list"'.($page['selected']?'':' open').'><summary>'.$this->label('Zustellungen · {count}',['count'=>$page['total']]).'</summary><form method="get" class="newsletter-delivery-filter">'.Html::hidden('view','newsletter').Html::hidden('section','planning').Html::hidden('planning_view','history').implode('',array_map(static fn($key)=>isset($route[$key])?Html::hidden($key,$route[$key]):'',['plan_month','plan_channel','plan_state','plan_step'])).Html::hidden('newsletter_item',$page['item']).Html::select('newsletter_state',$this->text('Status'),array_map($this->text(...),Catalogue::STATES),$page['state']).Html::select('newsletter_kind',$this->text('Mailart'),array_map($this->text(...),Catalogue::KINDS),$page['kind']).'<button class="btn btn-outline theme-secondary">'.$this->label('Filtern').'</button></form>';
        if($page['item']!=='')$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.$e($url(['newsletter_item'=>'','newsletter_page'=>'1'])).'">'.$this->label('Alle Ausgaben').'</a>';
        $h.='<div class="newsletter-delivery-cards">';
        foreach($page['rows']as$r)$h.='<article class="newsletter-delivery-card"><p class="newsletter-delivery-recipient">'.($r['removed']?$this->label('Empfänger entfernt'):$e($r['email'])).'</p><h3>'.$e($subject($r)).'</h3><p><span class="badge">'.$this->label(Catalogue::STATES[$r['status']]??'Stand prüfen').'</span> · '.$this->label(Catalogue::KINDS[$r['kind']]).'</p>'.(isset(\Kulturhus\Native\Delivery\DeliveryNotStarted::LABELS[$r['reason']??''])?'<p>'.$this->label(\Kulturhus\Native\Delivery\DeliveryNotStarted::LABELS[$r['reason']]).'</p>':'').'<p>'.$e($this->mediaTime($r['due_at'])).'</p><a class="btn btn-outline theme-secondary" data-nav href="'.$e($url(['newsletter_job'=>$r['id']])).'">'.$this->label('Zustellung öffnen').'</a></article>';
        if(!$page['rows'])$h.='<p>'.$this->label('Keine passenden Zustellungen.').'</p>';
        $h.='</div><nav class="newsletter-delivery-pages" aria-label="'.$this->label('Zustellseiten').'">';
        foreach(['Zurück'=>-1,'Weiter'=>1]as$label=>$delta){$next=$page['page']+$delta;if($next>0&&$next<=$page['pages'])$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.$e($url(['newsletter_page'=>$next])).'">'.$this->label($label).'</a>';}
        return $h.'<span>'.$this->label('Seite {page} von {pages}',['page'=>$page['page'],'pages'=>$page['pages']]).'</span></nav></details></section>';
    }
}
