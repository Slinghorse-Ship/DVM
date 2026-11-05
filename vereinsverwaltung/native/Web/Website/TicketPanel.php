<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Web\{Workspace,Html};
use Kulturhus\Native\Events\Tickets\{Offers,Orders,Qr};
use Kulturhus\Native\Events\Workflow;
use Kulturhus\Native\Cms\EventDetails;
use Kulturhus\Native\Payments\Money;

final class TicketPanel extends Workspace
{
    public function render(array $page,bool $open=false):string
    {
        if(!EventDetails::isEvent($page))return '';
        $offer=(new Offers($this->db,$this->access))->get($page['id']);
        $ticketRoute=$offer?'native':(new Workflow($this->db,$this->access))->get()['tickets'];
        if(!$offer&&$ticketRoute!=='native'){
            if(!$open)return '';
            $h='<section class="panel"><p>'.($ticketRoute==='eventbrite'?'Der Ticketverkauf wird in Eventbrite verwaltet.':'Für diese Veranstaltung ist kein Ticketverkauf vorgesehen.').'</p>';
            if($ticketRoute==='eventbrite')$h.='<p><a class="btn btn-solid theme-primary" target="_blank" rel="noopener noreferrer" href="https://www.eventbrite.de/manage/events/">In Eventbrite verwalten ↗</a></p>';
            if($this->can('website','manage'))$h.='<a class="btn btn-outline theme-secondary" data-nav href="/?view=settings#event-workflow">Ticketweg einrichten</a>';
            return $h.'</section>';
        }
        $h='<details class="panel ticket-admin" id="event-tickets"'.($open?' open':'').'><summary>Tickets &amp; Gutscheine</summary>';
        if($this->access instanceof \Kulturhus\Native\Integration\ManagerAccess)$h.='<a class="btn btn-outline theme-secondary" data-nav href="/vereinsverwaltung/?view=pos">Kasse öffnen</a>';
        if($offer)$h.='<p>'.$offer['sold'].' bestätigt · '.$offer['held'].' reserviert · '.$offer['remaining'].' frei</p>';
        if($this->can('website','manage'))$h.=$this->start('ticket-offer-save').$this->workspaceState().Html::hidden('view','events').Html::hidden('id',$page['id']).Html::hidden('version',$offer['version']??0).Html::input('ticket_capacity','Kontingent',$offer['capacity']??100,'number','required min="1" max="100000" inputmode="numeric"').Html::input('ticket_price','Preis pro Ticket (€)',Money::decimal($offer['price_cents']??0),'text','required inputmode="decimal"').Html::select('ticket_sphere','Fachliche Zuordnung',Offers::SPHERES,$offer['accounting_sphere']??'zweck').'<label class="check"><input class="check" type="checkbox" name="ticket_active" value="1"'.(($offer['active']??false)?' checked':'').'><span>Ticketverkauf aktiv</span></label><button class="btn btn-solid theme-primary">Ticketangebot speichern</button></form>';
        if($offer&&$this->can('website','manage'))$h.=$this->onlineOrders($page['id']);
        if($offer&&$this->can('website','edit')){
            $input=is_string($_POST['ticket_code']??null)?$_POST['ticket_code']:'';$pass=null;
            if($input!==''&&is_string($_POST['id']??null)&&$_POST['id']===$page['id']&&is_string($_POST['csrf']??null)&&hash_equals($this->csrf,$_POST['csrf']))try{$qr=Qr::parse($input,$page['id']);if($qr['page_id']===$page['id'])$pass=(new Orders($this->db,$this->access->club(),$this->box(),$this->access))->pass($qr['page_id'],$qr['code']);}catch(\DomainException){}
            $h.='<section class="ticket-scan"><h3>Einlass</h3>'.$this->start('ticket-inspect').$this->workspaceState().Html::hidden('view','events').Html::hidden('id',$page['id']).Html::input('ticket_code','QR-Code oder Ticketcode',$input,'text','required autocomplete="off" spellcheck="false" data-ticket-code').'<div class="ticket-actions"><button class="btn btn-outline theme-secondary">Ticket prüfen</button><button class="btn btn-outline theme-secondary" type="button" data-ticket-camera>QR scannen</button></div></form><video data-ticket-video hidden playsinline muted aria-label="Kamerabild für QR-Scan"></video><button class="btn btn-outline theme-secondary" type="button" data-ticket-camera-stop hidden>Kamera schließen</button><p data-ticket-camera-status role="status"></p>';
            if($pass){$h.='<div class="ticket-result" role="status"><strong>'.Html::e($pass['buyer_name']).'</strong><span>Ticket '.$pass['ordinal'].' · '.($pass['status']==='test'?'Testticket · Kein Einlass':($pass['status']==='checked_in'?'Bereits eingelassen':($pass['payment_status']==='paid'&&$pass['status']==='valid'?'Für den Einlass gültig':'Nicht freigegeben'))).'</span></div>';
                if($pass['status']==='valid'&&$pass['payment_status']==='paid')$h.=$this->start('ticket-check-in').$this->workspaceState().Html::hidden('view','events').Html::hidden('id',$page['id']).Html::hidden('ticket_code',$input).Html::hidden('version',$pass['version']).'<button class="btn btn-solid theme-primary">Einlass bestätigen</button></form>';
            }
            $h.='</section>';
        }
        return $h.'</details>';
    }
    private function workspaceState():string
    {
        return ($_GET['tickets']??null)==='1'||($_POST['ticket_workspace']??null)==='1'?Html::hidden('ticket_workspace','1'):'';
    }
    private function onlineOrders(string $page):string
    {
        $offset=filter_var($_GET['ticket_offset']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1000000]]);if($offset===false||$offset%10!==0)$offset=0;
        $list=(new Orders($this->db,$this->access->club(),$this->box(),$this->access))->managementOrders($page,$offset);
        $labels=['reserved'=>'Reserviert','creating_checkout'=>'Zahlung wird vorbereitet','awaiting_payment'=>'Zahlung ausstehend','payment_uncertain'=>'Zahlung unklar','payment_review'=>'Zahlung wird geprüft','paid_unallocated'=>'Bezahlt, ohne zugewiesenen Platz','paid'=>'Bestätigt','expired'=>'Abgelaufen','refund_pending'=>'Erstattung noch nicht bestätigt','refunded'=>'Erstattet','cancelled'=>'Storniert'];
        $h='<details class="panel"><summary>Onlinebestellungen · '.$list['total'].'</summary><p>Vollständiges Storno nur für unbenutzte Tickets und unbenutztes Gutscheinguthaben. Kassenverkäufe direkt an der Kasse bearbeiten.</p>';
        foreach($list['items']as$order){
            $h.='<article class="panel"><h3>'.Html::e($order['buyer_name']).'</h3><p>'.Html::e($order['buyer_email']).'</p><p>'.Html::e($labels[$order['status']]??'Wird geprüft').' · '.Html::e(number_format($order['amount_cents']/100,2,',','.')).' €</p>';
            if($order['status']==='refund_pending')$h.='<p>Codes bleiben gesperrt, Plätze bleiben gehalten. Die Prüfung liest den Anbieterstand und löst keine zweite Erstattung aus.</p>'.$this->start('ticket-refund-check').$this->workspaceState().Html::hidden('view','events').Html::hidden('id',$page).Html::hidden('ticket_order',$order['id']).'<button class="btn btn-outline theme-secondary">Erstattung prüfen</button></form>';
            elseif(in_array($order['status'],['paid','paid_unallocated','reserved'],true))$h.='<details><summary>Bestellung stornieren</summary>'.$this->start('ticket-refund').$this->workspaceState().Html::hidden('view','events').Html::hidden('id',$page).Html::hidden('ticket_order',$order['id']).Html::hidden('version',$order['version']).Html::input('refund_reason','Stornogrund','','text','required maxlength="500"').'<label class="check"><input class="check" type="checkbox" name="refund_confirm" value="1" required><span>Gesamte Bestellung stornieren und einen bereits bezahlten Betrag vollständig zurückzahlen.</span></label><button class="btn btn-outline theme-danger">Storno bestätigen</button></form></details>';
            $h.='</article>';
        }
        $h.='<nav aria-label="Onlinebestellungen">';foreach(['Vorherige Bestellungen'=>-10,'Weitere Bestellungen'=>10]as$label=>$step){$next=$offset+$step;if($next>=0&&$next<$list['total'])$h.='<a class="btn btn-outline theme-secondary" data-nav href="/?view=events&amp;item='.$page.'&amp;tickets=1&amp;ticket_offset='.$next.'#event-tickets">'.$label.'</a>';}
        return $h.'</nav></details>';
    }
}
