<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;

use Kulturhus\Native\Communication\{Subscribers,SubscriberCatalogue,SubscriberSelection};
use Kulturhus\Native\Web\{Workspace,Html};

/** Six compact originals and one deliberately opened profile. No JavaScript dependency. */
final class NewsletterContacts extends Workspace
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    private const STATUS=['all'=>'Alle','confirmed'=>'Bestätigt','pending'=>'Bestätigung ausstehend','unsubscribed'=>'Abgemeldet'];
    private const TOPICS=['events'=>'Veranstaltungen','news'=>'Neuigkeiten'];

    public static function restore(array $post): array
    {
        $state=SubscriberSelection::state($post);
        if (($post['operation']??'')==='newsletter-profile') {
            $state['focus']=\Kulturhus\Native\Communication\Content::id($post['id']??'');
            $state['profile']=['id'=>$state['focus'],'version'=>SubscriberSelection::number($post['version']??'' ,4294967295),'name'=>\Kulturhus\Native\Communication\Content::text($post['name']??'',200),'purposes'=>$post['purposes']??[]];
            if (!is_array($state['profile']['purposes'])||!array_is_list($state['profile']['purposes'])||count($state['profile']['purposes'])>2) throw new \DomainException('Themen prüfen.');
            foreach($state['profile']['purposes']as$purpose)if(!is_string($purpose)||!isset(self::TOPICS[$purpose]))throw new \DomainException('Themen prüfen.');
        }
        return $state;
    }

    private function form(string $operation,array $state,array $omit=[]): string
    {
        $h=str_replace('<form method="post">','<form method="post" data-contact-management>',$this->start($operation));
        foreach(['query'=>'contact_query','status'=>'contact_status','page'=>'contact_page','mode'=>'contact_mode','focus'=>'contact_focus','history_page'=>'contact_history_page']as$key=>$name)if(!in_array($key,$omit,true))$h.=Html::hidden($name,$state[$key]??($key==='page'||$key==='history_page'?1:($key==='status'||$key==='mode'?'all':'')));
        foreach($state['selected']??[] as$id=>$version)$h.=Html::hidden('selected['.$id.']',$version);
        return $h;
    }

    public function render(?array $state=null): string
    {
        $this->access->require('newsletter','manage');
        $state??=['query'=>is_string($_GET['q']??null)?$_GET['q']:'','status'=>$_GET['status']??'all','page'=>$_GET['page']??1,'mode'=>'all','selected'=>[],'reason'=>'','focus'=>is_string($_GET['contact']??null)?$_GET['contact']:'','history_page'=>$_GET['contact_history_page']??1];
        $page=SubscriberCatalogue::page($this->db,$this->access,$state);$e=Html::e(...);
        $h=('<section class="panel newsletter-management" aria-label="' . Html::e($this->contentText('Newsletter-Empfänger')) . '"><h2>' . Html::e($this->contentText('Newsletter-Empfänger')) . '</h2>');
        if(($page['focus']??'')!=='')$h.=$this->profile($page);
        $h.='<details class="contact-list"'.(($page['focus']??'')===''?' open':'').('><summary>' . Html::e($this->contentText('Empfänger ·')) . ' ').$page['total'].'</summary>';
        $h.=$this->form('newsletter-contacts',$page,['query','status']);
        $h.='<div class="contact-search">'.Html::input('contact_query',$this->contentText('Name oder E-Mail suchen'),$page['query']).('<label>' . Html::e($this->contentText('Status')) . '<select name="contact_status" class="form-control">');
        foreach(self::STATUS as$key=>$label)$h.='<option value="'.$key.'"'.($page['status']===$key?' selected':'').'>'.Html::e($this->contentText($label)).'</option>';
        $h.=('</select></label><button class="btn btn-solid theme-primary" name="contact_browse" value="search">' . Html::e($this->contentText('Suchen')) . '</button></div><div class="contact-toolbar"><button class="btn btn-outline theme-primary" name="contact_browse" value="all">' . Html::e($this->contentText('Alle')) . '</button><button class="btn btn-outline theme-primary" name="contact_browse" value="selected">' . Html::e($this->contentText('Ausgewählt ·')) . ' ').count($page['selected']).'</button>';
        if($page['selected'])$h.=('<button class="btn btn-outline theme-primary" name="contact_browse" value="clear">' . Html::e($this->contentText('Auswahl leeren')) . '</button>');
        $h.='</div><p role="status">'.$page['total'].' '.Html::e($this->contentText('Empfänger')).' · '.Html::e($this->contentText('Seite')).' '.$page['page'].' '.Html::e($this->contentText('von')).' '.$page['pages'].'</p>';
        foreach($page['rows']as$row){
            $id=$row['id'];$version=$page['selected'][$id]??$row['version'];
            $h.=Html::hidden('visible['.$id.']',$version).'<article class="newsletter-contact"><label class="contact-select"><input class="check" type="checkbox" name="choice[]" value="'.$id.'"'.(isset($page['selected'][$id])?' checked':'').'><span><strong>'.$e($row['name']?:'Newsletter-Anmeldung').'</strong><span class="contact-address">'.$e($row['email']).'</span><span class="contact-status">'.Html::e($this->contentText(self::STATUS[$row['status']])).'</span></span></label><button class="btn btn-outline theme-primary" name="contact_browse" value="'.$id.('">' . Html::e($this->contentText('Bearbeiten & Verlauf')) . '</button></article>');
        }
        if(!$page['rows'])$h.=('<p>' . Html::e($this->contentText('Keine Empfänger gefunden.')) . '</p>');
        $h.='<div class="contact-toolbar">';
        if($page['page']>1)$h.=('<button class="btn btn-outline theme-primary" name="contact_browse" value="previous">' . Html::e($this->contentText('Zurück')) . '</button>');
        if($page['page']<$page['pages'])$h.=('<button class="btn btn-outline theme-primary" name="contact_browse" value="next">' . Html::e($this->contentText('Weiter')) . '</button>');
        $h.=('</div><details class="contact-unsubscribe"><summary>' . Html::e($this->contentText('Ausgewählte abmelden')) . '</summary>').Html::input('reason',$this->contentText('Grund der Abmeldung'),$page['reason']??'','text','maxlength="500"').('<label class="contact-confirm"><input class="check" type="checkbox" name="confirm_unsubscribe" value="1">' . Html::e($this->contentText('Ausgewählte Empfänger vom Newsletter abmelden')) . '</label><button class="btn btn-solid theme-danger" name="contact_unsubscribe" value="1">' . Html::e($this->contentText('Auswahl abmelden')) . '</button></details></form></details>');
        $confirmation=new ConfirmationPanel($this->db,$this->access,$this->mediaRoot,$this->csrf);
        $h.=('<details class="contact-add"><summary>' . Html::e($this->contentText('Anmeldung & Einwilligung')) . '</summary>').$confirmation->render().$this->writtenConsent().'</details></section>';
        return $h;
    }

    private function profile(array $state): string
    {
        $service=new Subscribers($this->db,$this->access);$row=$service->get($state['focus']);$input=$state['profile']??$row;$e=Html::e(...);
        $h='<section class="contact-profile" aria-label="'.Html::e($this->contentText('Empfänger bearbeiten')).'"><h3>'.$e($row['name']?:$row['email']).'</h3><p class="contact-address">'.$e($row['email']).'</p><p>'.Html::e($this->contentText(self::STATUS[$row['status']])).'</p>';
        $h.=$this->form('newsletter-profile',$state).Html::hidden('id',$row['id']).Html::hidden('version',$input['version']).Html::input('name','Name',$input['name'],'text','maxlength="200"').('<fieldset><legend>' . Html::e($this->contentText('Newsletter-Themen')) . '</legend>');
        foreach(self::TOPICS as$key=>$label){
            // Submitted additions remain visible after rejection; they never become consent.
            if(!in_array($key,$row['purposes'],true)&&!in_array($key,$input['purposes'],true))continue;
            $h.='<label class="contact-confirm"><input class="check" type="checkbox" name="purposes[]" value="'.$key.'"'.(in_array($key,$input['purposes'],true)?' checked':'').'>'.Html::e($this->contentText($label)).'</label>';
        }
        $h.=('</fieldset><button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Änderungen speichern')) . '</button></form>');
        $history=$service->historyPage($row['id'],SubscriberSelection::number($state['history_page']??1));$state['history_page']=$history['page'];
        $h.=('<details class="contact-history"><summary>' . Html::e($this->contentText('Einwilligung & Änderungen ·')) . ' ').$history['total'].'</summary>';
        $labels=['written_consent'=>$this->contentText('Schriftliche Einwilligung'),'requested'=>$this->contentText('Bestätigung angefordert'),'doi_confirmed'=>$this->contentText('Anmeldung bestätigt'),'unsubscribed'=>$this->contentText('Abgemeldet'),'admin_unsubscribed'=>$this->contentText('Durch Verwaltung abgemeldet'),'profile_updated'=>$this->contentText('Name oder Themen geändert')];
        foreach($history['rows']as$event){
            $h.='<article><strong>'.$e($this->contentText($labels[$event['action']]??$this->contentText('Einwilligungsänderung'))).'</strong><p>'.$e($this->displayTime($event['created_at'])).'</p>';
            if($event['action']==='profile_updated'){
                $change=json_decode($event['evidence'],true,16,JSON_THROW_ON_ERROR);
                $topics=fn(array $keys):string=>implode(', ',array_map(fn(string $key):string=>$this->contentText(self::TOPICS[$key]),$keys));
                $h.=('<p>' . Html::e($this->contentText('Name:')) . ' ').$e($change['name_before']).' → '.$e($change['name_after']).('</p><p>' . Html::e($this->contentText('Themen:')) . ' ').$e($topics($change['topics_before'])).' → '.$e($topics($change['topics_after'])).'</p>';
            }else $h.='<p>'.$e($event['evidence']).'</p>';
            $h.='</article>';
        }
        $h.=$this->form('newsletter-contacts',$state).('<p>' . Html::e($this->contentText('Seite')) . ' ').$history['page'].' '.Html::e($this->contentText('von')).' '.$history['pages'].'</p><div class="contact-toolbar">';
        if($history['page']>1)$h.=('<button class="btn btn-outline theme-primary" name="contact_browse" value="history-previous">' . Html::e($this->contentText('Ältere Seite zurück')) . '</button>');
        if($history['page']<$history['pages'])$h.=('<button class="btn btn-outline theme-primary" name="contact_browse" value="history-next">' . Html::e($this->contentText('Ältere Änderungen')) . '</button>');
        $h.='</div></form></details>';
        $h.=$this->form('newsletter-contacts',$state).('<button class="btn btn-outline theme-primary" name="contact_browse" value="close">' . Html::e($this->contentText('Bearbeitung schließen')) . '</button></form>');
        $renewals=new \Kulturhus\Native\Communication\Confirmations\Renewals($this->db,$this->access,$this->box(),$this->mediaRoot);
        if($row['status']==='pending'||$renewals->history($row['id']))$h.=(new ConfirmationPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($row['id']);
        return $h.'</section>';
    }

    private function writtenConsent(): string
    {
        return ('<details><summary>' . Html::e($this->contentText('Schriftliche Einwilligung erfassen')) . '</summary>').$this->start('newsletter-consent').Html::input('email',$this->contentText('E-Mail-Adresse'),'','email','required').Html::input('name','Name','','text','required').('<label class="contact-confirm"><input class="check" type="checkbox" name="purposes[]" value="events">' . Html::e($this->contentText('Veranstaltungen')) . '</label><label class="contact-confirm"><input class="check" type="checkbox" name="purposes[]" value="news">' . Html::e($this->contentText('Neuigkeiten')) . '</label>').Html::input('evidence',$this->contentText('Konkreter Nachweisverweis'),'','text','required minlength="10"').('<button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Einwilligung dokumentieren')) . '</button></form></details>');
    }
}
