<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use Kulturhus\Native\Communication\{PressContacts,PressContactCatalogue,PressContactSelection,SubscriberSelection,Content};
use Kulturhus\Native\Web\{Workspace,Html};

final class PressContactManager extends Workspace
{
    public static function restore(array $post): array
    {
        $state=PressContactSelection::state($post);
        if(($post['operation']??'')==='press-contact-profile'){
            foreach(['id','version','name','email','outlet','press_contact_active']as$key)if(!is_string($post[$key]??null))throw new DomainException('Kontaktformular prüfen.');
            $state['focus']=$post['id']!==''?Content::id($post['id']):'';$state['new']=$state['focus']==='';
            $state['profile']=['id'=>$state['focus'],'version'=>$post['version'],'name'=>$post['name'],'email'=>$post['email'],'outlet'=>$post['outlet'],'active'=>$post['press_contact_active']];
        }
        return$state;
    }
    private function form(string $op,array $state,array $omit=[]): string
    {
        $h=str_replace('<form method="post">','<form method="post" data-contact-management>',$this->start($op));foreach(['query','status','page','mode','focus','new']as$key)if(!in_array($key,$omit,true))$h.=Html::hidden('contact_'.$key,$key==='new'?(($state[$key]??false)?'1':'0'):($state[$key]??''));
        foreach($state['selected']??[]as$id=>$version)$h.=Html::hidden('selected['.$id.']',$version);return$h;
    }
    public function render(?array $state=null): string
    {
        $this->access->require('press','read');$write=$this->can('press','edit');$e=Html::e(...);
        $state??=['selected'=>[],'query'=>is_string($_GET['q']??null)?$_GET['q']:'','status'=>$_GET['contact_status']??'all','mode'=>'all','page'=>$_GET['page']??1,'focus'=>is_string($_GET['contact']??null)?$_GET['contact']:'','new'=>($_GET['contact_new']??'')==='1'];
        $p=PressContactCatalogue::page($this->db,$this->access,$state);$h='<section class="panel press-contact-management" aria-label="Pressekontakte"><h2>Pressekontakte</h2>';
        if($p['focus']!==''||($p['new']??false)){
            $row=$p['focus']!==''?(new PressContacts($this->db,$this->access))->get($p['focus']):['id'=>'','version'=>0,'name'=>'','email'=>'','outlet'=>'','active'=>true];$in=$p['profile']??$row;
            $h.='<section class="contact-profile press-contact-profile"><h3>'.$e($row['id']!==''?$row['name']:'Neuen Kontakt anlegen').'</h3>';
            if($write){$h.=$this->form('press-contact-profile',$p).Html::hidden('id',$row['id']).Html::hidden('version',$in['version']).Html::input('name','Name',$in['name'],'text','maxlength="200" required').Html::input('email','E-Mail',$in['email'],'email','maxlength="254" required').Html::input('outlet','Medium',$in['outlet'],'text','maxlength="200"').'<label>Status<select class="form-control" name="press_contact_active"><option value="1"'.(in_array($in['active'],[true,1,'1'],true)?' selected':'').'>Aktiv</option><option value="0"'.(in_array($in['active'],[false,0,'0'],true)?' selected':'').'>Inaktiv</option></select></label>'.Html::hidden('press_contact_complete','1').'<button class="btn btn-solid theme-primary">Kontakt speichern</button></form>';}
            else $h.='<p>'.$e($row['email']).'</p><p>'.$e($row['outlet']).'</p><p>'.($row['active']?'Aktiv':'Inaktiv').'</p>';
            $h.=$this->form('press-contacts',$p).'<button class="btn btn-outline theme-secondary" name="contact_browse" value="close">Schließen</button></form></section>';
            if(isset($p['profile'])&&$row['id']!=='')$h.=$this->form('press-contacts',$p).'<button class="btn btn-outline theme-secondary" name="contact_browse" value="'.$row['id'].'">Aktuellen Stand öffnen</button></form>';
        }
        $h.='<details class="contact-list press-contact-list"'.($p['focus']===''&&!($p['new']??false)?' open':'').'><summary>Kontakte · '.$p['total'].'</summary>'.$this->form('press-contacts',$p,['query','status']);
        $h.='<div class="contact-search">'.Html::input('contact_query','Name, E-Mail oder Medium',$p['query'],'search','maxlength="200"').Html::select('contact_status','Status',['all'=>'Alle','active'=>'Aktive','inactive'=>'Inaktive'],$p['status']).'<button class="btn btn-solid theme-primary" name="contact_browse" value="search">Suchen</button></div><div class="contact-toolbar"><button class="btn btn-outline theme-secondary" name="contact_browse" value="all">Alle</button><button class="btn btn-outline theme-secondary" name="contact_browse" value="selected">Ausgewählt · '.count($p['selected']).'</button>';
        if($p['selected'])$h.='<button class="btn btn-outline theme-secondary" name="contact_browse" value="clear">Auswahl leeren</button>';
        if($write)$h.='<button class="btn btn-outline theme-primary" name="contact_browse" value="new">Kontakt anlegen</button>';
        $h.='</div><p role="status">Seite '.$p['page'].' von '.$p['pages'].'</p>';
        foreach($p['rows']as$r){$h.=Html::hidden('visible['.$r['id'].']',$p['selected'][$r['id']]??$r['version']).'<article class="press-managed-contact"><label class="contact-select"><input class="check" type="checkbox" name="choice[]" value="'.$r['id'].'"'.(isset($p['selected'][$r['id']])?' checked':'').'><span><strong>'.$e($r['name']).'</strong><span class="contact-address">'.$e($r['email']).'</span><span>'.$e($r['outlet']).'</span><span>'.($r['active']?'Aktiv':'Inaktiv').'</span></span></label><button class="btn btn-outline theme-secondary" name="contact_browse" value="'.$r['id'].'">'.($write?'Bearbeiten':'Ansehen').'</button></article>';}
        if(!$p['rows'])$h.='<p>Keine passenden Kontakte.</p>';
        $h.='<div class="contact-toolbar">';if($p['page']>1)$h.='<button class="btn btn-outline theme-secondary" name="contact_browse" value="previous">Zurück</button>';if($p['page']<$p['pages'])$h.='<button class="btn btn-outline theme-secondary" name="contact_browse" value="next">Weiter</button>';$h.='</div>';
        if($write)$h.='<details class="press-contact-bulk"><summary>Auswahl bearbeiten · '.count($p['selected']).'</summary><div class="contact-toolbar"><button class="btn btn-outline theme-primary" name="contact_activation" value="1">Auswahl aktivieren</button><button class="btn btn-outline theme-secondary" name="contact_activation" value="0">Auswahl deaktivieren</button></div></details>';
        return$h.'</form></details></section>';
    }
}
