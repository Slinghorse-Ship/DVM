<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Cms\{Navigation as Store,Conflict,TemplatePolicy};
use PDO;
final class Navigation
{
    use ContentInterfaceText;
    public function __construct(private PDO $db,private Access $access,private string $csrf){}
    /** Comparing/adopting returns an unsaved proposal; only a normal submit writes. */
    public function action(array $post):?array
    {
        $this->csrf($post);
        if(!NavigationFormState::submitted($post))throw new \DomainException('Unbekannte Menüaktion.');
        $proposal=NavigationFormState::restore($post);$current=$this->current($proposal);
        if($proposal['browse']!==''){
            if($proposal['browse']==='resume'){
                if($proposal['seen_version']!==$current['version'])throw new Conflict('Der angezeigte Menüstand wurde erneut geändert. Bitte vergleichen.');
                $proposal['version']=$current['version'];
            }
            return $proposal+['error'=>'','conflict'=>$proposal['version']!==$current['version']];
        }
        $items=[];
        foreach($proposal['items'] as $id=>$row){
            $position=filter_var($row['position'],FILTER_VALIDATE_INT);
            if($position===false)throw new \InvalidArgumentException('Gültige Position angeben.');
            $items[]=['id'=>$id,'label'=>$row['label'],'position'=>$position,'visible'=>$row['visible']];
        }
        try{(new Store($this->db,$this->access))->save($items,$proposal['automatic_program_year'],$proposal['version']);}
        catch(Conflict $error){throw new Conflict('Das Menü wurde inzwischen geändert. Vergleiche den aktuellen Stand mit deinem Vorschlag.',0,$error);}
        return null;
    }
    public function correction(array $post,string $error,bool $conflict):array
    {
        $this->csrf($post);
        if(!NavigationFormState::submitted($post))throw new \DomainException('Unbekannte Menüaktion.');
        $proposal=NavigationFormState::restore($post);$this->current($proposal);
        return $proposal+['error'=>$error,'conflict'=>$conflict];
    }
    private function csrf(array $post):void
    {
        if(!is_string($post['csrf']??null)||!hash_equals($this->csrf,$post['csrf']))throw new \DomainException('Formular abgelaufen. Bitte neu laden.');
    }
    /** Recheck actor, club and page policies before displaying any saved original. */
    private function current(array $proposal):array
    {
        $actor=$this->access->require('website','publish');
        if($proposal['actor']!==$actor['id']||$proposal['club']!==$actor['club_id'])throw new \DomainException('Dieses Menüformular gehört zu einer anderen Anmeldung. Bitte den Menübereich erneut öffnen.');
        $current=(new Store($this->db,$this->access))->state();$known=array_column($current['items'],null,'id');
        foreach($proposal['items'] as $id=>$row){
            if(!isset($known[$id]))throw new \DomainException('Menüeintrag ist nicht mehr verfügbar. Bitte den Menübereich erneut öffnen.');
            TemplatePolicy::assertCapability($this->db,$this->access,$id,'publish');
        }
        if($proposal['automatic_program_year']!==$current['automatic_program_year']&&!(new Store($this->db,$this->access))->canChangeAutomaticYear())throw new \DomainException('Für die automatische Programmbeschriftung fehlt die Berechtigung.');
        return $current;
    }
    public function manager(?array $proposal=null):string
    {
        $current=$proposal===null?(new Store($this->db,$this->access))->state():$this->current($proposal);
        $actor=$this->access->user();$e=Html::e(...);$write=true;
        try{$this->access->require('website','publish');}catch(\DomainException){$write=false;}
        $automaticWritable=$write&&(new Store($this->db,$this->access))->canChangeAutomaticYear();
        $readonly=[];
        if($write)foreach($current['items'] as $item){try{TemplatePolicy::assertCapability($this->db,$this->access,$item['id'],'publish');}catch(\DomainException){$readonly[$item['id']]=$item;}}
        $state=$current;
        if($proposal!==null){
            $known=array_column($current['items'],null,'id');$state['items']=[];
            foreach($proposal['items'] as $id=>$row)$state['items'][]=array_replace($known[$id],$row);
            $state['version']=$proposal['version'];$state['automatic_program_year']=$proposal['automatic_program_year'];
        }
        $h='<section class="panel text-break"><h2>'.Html::e($this->contentText('Hauptmenü')).'</h2>';
        if($proposal!==null){
            if($proposal['error']!=='')$h.='<p role="alert" tabindex="-1" data-navigation-error>'.$e($proposal['error']).'</p>';
            $h.='<p role="status">'.Html::e($this->contentText('Dein Vorschlag ist noch nicht gespeichert.')).'</p>';
            $h.='<details data-navigation-current'.($state['version']!==$current['version']?' open':'').'><summary class="btn btn-outline theme-secondary">'.Html::e($this->contentFormat('Aktuell gespeichertes Menü · Stand {version}',['version'=>$current['version']])).'</summary><p>'.Html::e($this->contentText('Programmjahr automatisch anzeigen')).': '.($current['automatic_program_year']?'Ja':'Nein').'</p><ul>';
            foreach($current['items'] as $item)$h.='<li><strong>'.$e($item['label']).'</strong> <small>/'.$e($item['slug']).'/</small> · '.Html::e($this->contentFormat('Position {position}',['position'=>$item['position']])).' · '.($item['visible']?Html::e($this->contentText('Sichtbar')):Html::e($this->contentText('Ausgeblendet'))).'</li>';
            $h.='</ul></details><h3>'.Html::e($this->contentFormat('Dein Vorschlag · Stand {version}',['version'=>$state['version']])).'</h3>';
        }
        $h.='<form method="post" data-navigation-editor'.($proposal!==null?' data-unsaved-changes':'').'>'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','navigation-save').Html::hidden('version',$state['version']).Html::hidden('navigation_actor',$actor['id']).Html::hidden('navigation_club',$actor['club_id']).Html::hidden('navigation_seen_version',$current['version']);
        if($write&&!$automaticWritable&&$state['automatic_program_year'])$h.=Html::hidden('automatic_program_year','1');
        $h.='<fieldset'.($write?'':' disabled').'><label class="check"><input class="check" type="checkbox" name="automatic_program_year" value="1"'.($state['automatic_program_year']?' checked':'').($automaticWritable?'':' disabled').'> '.Html::e($this->contentText('Programmjahr automatisch anzeigen')).($write&&!$automaticWritable?' · '.Html::e($this->contentText('Nur Ansicht')):'').'</label><div class="native-menu-list">';
        foreach($state['items'] as $item){
            if(isset($readonly[$item['id']]))continue;
            $name='items['.$item['id'].']';$position=(string)$item['position'];$number=filter_var($position,FILTER_VALIDATE_INT)!==false;
            $h.='<div class="native-menu-row" data-menu-row><div>'.Html::input($name.'[label]',$this->contentText('Name im Menü'),$item['label'],'text','required maxlength="100"').'<small>/'.$e($item['slug']).'/</small></div>'.Html::input($name.'[position]','Position',$position,$number?'number':'text',($number?'min="0" max="10000"':'inputmode="numeric" pattern="[0-9]{1,5}"').' required data-menu-position').'<div class="actions"><button type="button" class="btn btn-solid theme-secondary secondary" data-menu-step="-1" aria-label="'.Html::e($this->contentFormat('{name} nach oben',['name'=>$item['label']])).'">↑</button><button type="button" class="btn btn-solid theme-secondary secondary" data-menu-step="1" aria-label="'.Html::e($this->contentFormat('{name} nach unten',['name'=>$item['label']])).'">↓</button></div><label class="check"><input class="check" type="checkbox" name="'.$e($name).'[visible]" value="1"'.($item['visible']?' checked':'').'> '.Html::e($this->contentText('Im Menü anzeigen')).'</label></div>';
        }
        $h.='</div></fieldset>';
        if($write){
            $h.='<div class="actions"><button class="btn btn-solid theme-primary">'.Html::e($this->contentText('Menü speichern')).'</button><button class="btn btn-solid theme-secondary secondary" name="navigation_browse" value="compare" formnovalidate>'.Html::e($this->contentText('Aktuellen Stand vergleichen')).'</button>';
            if($proposal!==null&&$state['version']!==$current['version'])$h.='<button class="btn btn-solid theme-secondary secondary" name="navigation_browse" value="resume" formnovalidate>'.Html::e($this->contentText('Auf aktuellen Stand übernehmen')).'</button>';
            $h.='</div>';
        }else $h.='<p>'.Html::e($this->contentText('Für Menüänderungen ist das Veröffentlichungsrecht erforderlich.')).'</p>';
        $h.='</form>';
        if($readonly!==[]){
            $h.='<details data-navigation-readonly><summary class="btn btn-outline theme-secondary">'.Html::e($this->contentText('Nur Ansicht')).'</summary><ul>';
            foreach($readonly as $item)$h.='<li><strong>'.$e($item['label']).'</strong> <small>/'.$e($item['slug']).'/</small> · '.Html::e($this->contentFormat('Position {position}',['position'=>$item['position']])).' · '.($item['visible']?Html::e($this->contentText('Sichtbar')):Html::e($this->contentText('Ausgeblendet'))).'</li>';
            $h.='</ul></details>';
        }
        return $h.'</section>';
    }
}
