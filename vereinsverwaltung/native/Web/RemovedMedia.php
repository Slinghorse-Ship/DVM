<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{MediaRemoval,MediaAvailability,MediaRetention};

/** Explicit native forms and six original-bound cards; usable without JavaScript. */
final class RemovedMedia extends Workspace
{
    public function action(array $post): array
    {
        if(!is_string($post['csrf']??null))throw new InvalidArgumentException('Formular neu laden.');
        $this->validatePost($post);$this->access->require('website','manage');
        $operation=$post['operation']??null;
        if($operation==='media-retention-save'){
            $version=filter_var($post['retention_version']??null,FILTER_VALIDATE_INT);
            if($version===false||$version===null)throw new InvalidArgumentException('Gespeicherte Aufbewahrungsfassung prüfen.');
            (new MediaRetention($this->db,$this->access))->save($post['minimum_days']??null,$version);$_POST=[];
            return ['view'=>'media','section'=>'removed','item'=>'','notice'=>$this->mediaText('Aufbewahrung gespeichert.')];
        }
        $id=$post['id']??null;$version=filter_var($post['removal_version']??null,FILTER_VALIDATE_INT);$original=$post['removal_operation']??null;
        if(!is_string($id)||!is_string($original)||$version===false||$version===null||$version<0||$version>=4294967294||!is_string($operation))throw new InvalidArgumentException('Bild und gespeicherten Zustand prüfen.');
        $service=new MediaRemoval($this->db,$this->access,$this->mediaRoot);
        if($operation==='media-remove'){
            if(($post['confirm_remove']??null)!=='1')throw new DomainException('Entfernen des ausgewählten Bildes bestätigen.');
            $state=$service->remove($id,$version,$original);$notice='Bild entfernt.';
        }elseif($operation==='media-restore'){$state=$service->restore($id,$version,$original);$notice='Bild zurückgeholt.';}
        elseif($operation==='media-removal-recover'){$state=$service->recover($id,$original);$notice='Bildspeicher geprüft.';}
        elseif(in_array($operation,['media-purge','media-purge-resume'],true)){
            if(($post['confirm_purge']??null)!=='1')throw new DomainException('Endgültiges Löschen dieses Bildes bestätigen.');
            $state=$operation==='media-purge'?$service->purge($id,$version,$original):$service->finishPurge($id,$version,$original);$notice='Bild endgültig gelöscht.';
        }
        else throw new DomainException('Unbekannte Bildaktion.');
        $_POST=[];
        return ['view'=>'media','section'=>in_array($state['state'],['removed','purging','purged'],true)?'removed':'library','item'=>$id,'notice'=>$this->mediaText($notice)];
    }

    public function panel(string $id): string
    {
        if(!$this->can('website','manage')||!MediaAvailability::installed($this->db))return '';
        $state=(new MediaRemoval($this->db,$this->access,$this->mediaRoot))->get($id);
        if(!in_array($state['state'],['active','restored'],true))return '';
        $posted=($_POST['operation']??null)==='media-remove'&&($_POST['id']??null)===$id;
        $operation=$posted&&is_string($_POST['removal_operation']??null)&&preg_match('/\A[a-f0-9]{32}\z/D',$_POST['removal_operation'])?$_POST['removal_operation']:bin2hex(random_bytes(16));
        $version=$posted&&is_string($_POST['removal_version']??null)?$_POST['removal_version']:(string)$state['version'];
        $html='<details class="panel media-remove" id="media-remove"'.($posted?' open':'').'><summary>'.Html::e($this->mediaText('Bild entfernen')).'</summary><p>'.Html::e($this->mediaText('Originaldateien bleiben zum Zurückholen gespeichert.')).'</p><form data-media-removal-form method="post" action="'.$this->actionUrl('library',$id).'">'.$this->fields('media-remove',$id,$version,$operation).'<label class="media-remove-confirm"><input class="check" type="checkbox" name="confirm_remove" value="1" required>'.Html::e($this->mediaText('Dieses Bild aus der Mediathek entfernen')).'</label><button class="btn btn-outline theme-danger">'.Html::e($this->mediaText('Bild entfernen')).'</button></form>';
        return $html.'</details>';
    }

    public function manager(string $id='',array $query=[]): string
    {
        $this->access->require('website','manage');$service=new MediaRemoval($this->db,$this->access,$this->mediaRoot);
        $html=MediaLibrary::tabs(false,true,true,false,true,text:$this->mediaText(...)).'<div class="media-library removed-media" id="removed-media">';
        if($id!==''){
            $image=$service->describe($id);
            if($image['state']==='purged')return $html.'<article class="panel media-recovery" data-media-purge-state="purged" data-removed-media="'.Html::e($id).'"><h2>'.Html::e($this->mediaText('Bild endgültig gelöscht')).'</h2><p>'.Html::e($image['original_name']).'</p><a class="back-link" data-nav href="'.$this->url('removed').'">'.Html::e($this->mediaText('← Entfernte Bilder')).'</a></article></div>';
            if($image['state']==='purging')return $html.'<a class="back-link" data-nav href="'.$this->url('removed').'">'.Html::e($this->mediaText('← Entfernte Bilder')).'</a>'.$this->pendingCard($image,true).'</div>';
            if($image['state']!=='removed'){
                $pending=$service->pendingOperation($id);
                if($pending!=='')return $html.'<article class="panel media-recovery"><h2>'.Html::e($this->mediaText('Bildspeicher prüfen')).'</h2><p>'.Html::e($image['original_name']).'</p><p>'.Html::e($this->mediaText('Bildvorgang unterbrochen.')).'</p><form data-media-removal-form method="post" action="'.$this->actionUrl('removed',$id).'">'.$this->fields('media-removal-recover',$id,(string)$image['version'],$pending).'<button class="btn btn-solid theme-primary">'.Html::e($this->mediaText('Speicher prüfen')).'</button></form></article></div>';
                return $html.'<a class="back-link" data-nav href="'.$this->url('library',$id).'">'.Html::e($this->mediaText('Bild in der Mediathek öffnen')).'</a></div>';
            }
            return $html.'<a class="back-link" data-nav href="'.$this->url('removed').'">'.Html::e($this->mediaText('← Entfernte Bilder')).'</a>'.$this->card($image,true).'</div>';
        }
        $list=$service->listing($query['removed_page']??'1');
        $html.='<div class="media-library-heading"><h2>'.Html::e($this->mediaText('Entfernte Bilder')).'</h2><span>'.Html::e($this->mediaFormat('{count} Bilder · Seite {page} von {pages}',['count'=>$list['total'],'page'=>$list['page'],'pages'=>$list['pages']])).'</span></div>'.$this->retention().$this->pager($list).'<div class="removed-media-list">';
        foreach($list['rows']as$image)$html.=$image['state']==='purging'?$this->pendingCard($image,false):$this->card($image,false);
        if(!$list['rows'])$html.='<p>'.Html::e($this->mediaText('Keine entfernten Bilder.')).'</p>';
        return $html.'</div>'.$this->pager($list).'</div>';
    }

    private function card(array $image,bool $detail): string
    {
        $id=$image['id'];$title=$image['alt']!==''?$image['alt']:$image['original_name'];
        $html='<article class="panel removed-media-card" data-removed-media="'.Html::e($id).'"><img src="/media-removed/'.Html::e($id).'/thumb" alt="" loading="lazy" width="'.(int)$image['width'].'" height="'.(int)$image['height'].'"><div class="removed-media-body"><h3>'.($detail?Html::e($title):'<a data-nav href="'.$this->url('removed',$id).'">'.Html::e($title).'</a>').'</h3><p class="removed-media-name">'.Html::e($image['original_name']).'</p><p>'.Html::e($this->mediaFormat('Gespeichert bis mindestens {date}',['date'=>$this->mediaTime($image['retain_until'])])).'</p><form data-media-removal-form method="post" action="'.$this->actionUrl('removed',$id).'">'.$this->fields('media-restore',$id,(string)$image['version'],$image['operation']).'<button class="btn btn-solid theme-primary">'.Html::e($this->mediaText('Bild zurückholen')).'</button></form>';
        if($detail)$html.=$this->purgePanel($image).'<details><summary>'.Html::e($this->mediaText('Bildspeicher prüfen')).'</summary><form data-media-removal-form method="post" action="'.$this->actionUrl('removed',$id).'">'.$this->fields('media-removal-recover',$id,(string)$image['version'],$image['operation']).'<button class="btn btn-outline theme-secondary">'.Html::e($this->mediaText('Speicher prüfen')).'</button></form></details>';
        return $html.'</div></article>';
    }

    private function pendingCard(array $image,bool $detail): string
    {
        $id=$image['id'];$title=$image['alt']!==''?$image['alt']:$image['original_name'];
        $html='<article class="panel removed-media-card" data-media-purge-state="purging" data-removed-media="'.Html::e($id).'"><div class="removed-media-body"><h3>'.($detail?Html::e($title):'<a data-nav href="'.$this->url('removed',$id).'">'.Html::e($title).'</a>').'</h3><p>'.Html::e($this->mediaText('Löschen unterbrochen')).'</p>';
        return $html.($detail?$this->purgeForm($image,true):'<a class="btn btn-outline theme-danger" data-nav href="'.$this->url('removed',$id).'">'.Html::e($this->mediaText('Löschen fortsetzen')).'</a>').'</div></article>';
    }
    private function purgePanel(array $image): string
    {
        $posted=($_POST['operation']??null)==='media-purge'&&($_POST['id']??null)===$image['id'];
        return '<details class="media-purge" id="media-purge"'.($posted?' open':'').'><summary>'.Html::e($this->mediaText('Endgültig löschen')).'</summary>'.((int)$image['purge_eligible']===1?$this->purgeForm($image,false):'<p>'.Html::e($this->mediaFormat('Mindestaufbewahrung bis {date}',['date'=>$this->mediaTime($image['retain_until'])])).'</p>').'</details>';
    }
    private function purgeForm(array $image,bool $resume): string
    {
        $action=$resume?'media-purge-resume':'media-purge';$posted=($_POST['operation']??null)===$action&&($_POST['id']??null)===$image['id'];
        $version=$posted&&is_string($_POST['removal_version']??null)?$_POST['removal_version']:(string)$image['version'];
        $operation=$posted&&is_string($_POST['removal_operation']??null)?$_POST['removal_operation']:$image['operation'];
        $fields=$this->fields($action,$image['id'],$version,$operation);
        if($posted)$fields=str_replace('data-media-removal-check hidden','data-media-removal-check',$fields);
        return '<form class="media-purge" data-media-removal-form method="post" action="'.$this->actionUrl('removed',$image['id']).'">'.$fields.'<p>'.Html::e($this->mediaText('Dateien können danach nicht zurückgeholt werden.')).'</p><label class="media-remove-confirm"><input class="check" type="checkbox" name="confirm_purge" value="1" required><span>'.Html::e($this->mediaText($resume?'Verbleibende Dateien endgültig löschen':'Dieses Bild endgültig löschen')).'</span></label><button class="btn btn-outline theme-danger">'.Html::e($this->mediaText($resume?'Löschen fortsetzen':'Endgültig löschen')).'</button></form>';
    }

    private function fields(string $action,string $id,string $version,string $operation): string
    {
        return Html::hidden('csrf',$this->csrf).Html::hidden('operation',$action).Html::hidden('id',$id).Html::hidden('removal_version',$version).Html::hidden('removal_operation',$operation).'<p role="alert" tabindex="-1" data-media-removal-error hidden></p><a data-nav data-media-removal-check hidden href="'.$this->url('removed',$id).'">'.Html::e($this->mediaText('Bildzustand prüfen')).'</a>';
    }
    private function retention(): string
    {
        if(!MediaRetention::installed($this->db))return '';
        $rule=(new MediaRetention($this->db,$this->access))->get();$posted=($_POST['operation']??null)==='media-retention-save';
        $days=$posted&&is_string($_POST['minimum_days']??null)?$_POST['minimum_days']:(string)$rule['minimum_days'];
        $version=$posted&&is_string($_POST['retention_version']??null)?$_POST['retention_version']:(string)$rule['version'];
        return '<details class="panel media-retention" id="media-retention"'.($posted?' open':'').'><summary>'.Html::e($this->mediaText('Aufbewahrung')).'</summary><p>'.Html::e($this->mediaText('Gilt für künftig entfernte Bilder.')).'</p><form data-media-removal-form method="post" action="'.Html::e('/?view=media&section=removed#media-result').'">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','media-retention-save').Html::hidden('retention_version',$version).Html::input('minimum_days',$this->mediaText('Mindestaufbewahrung in Tagen (0 = keine Mindestfrist)'),$days,'number','required min="0" max="3650" step="1"').'<p role="alert" tabindex="-1" data-media-removal-error hidden></p><a data-nav data-media-removal-check hidden href="'.$this->url('removed').'">'.Html::e($this->mediaText('Aufbewahrung prüfen')).'</a><button class="btn btn-solid theme-primary">'.Html::e($this->mediaText('Aufbewahrung speichern')).'</button></form></details>';
    }
    private function pager(array $list): string
    {
        if($list['pages']<2)return '';$html='<nav class="media-library-pager" aria-label="'.Html::e($this->mediaText('Entfernte Bilderseiten')).'">';
        foreach([-1=>'Vorherige Bilder',1=>'Weitere Bilder']as$step=>$label){$page=$list['page']+$step;if($page>0&&$page<=$list['pages'])$html.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e('/?'.http_build_query(['view'=>'media','section'=>'removed','removed_page'=>(string)$page]).'#removed-media').'">'.Html::e($this->mediaText($label)).'</a>';}
        return $html.'<form method="get" action="/#removed-media">'.Html::hidden('view','media').Html::hidden('section','removed').Html::input('removed_page',$this->mediaText('Seite wählen'),(string)$list['page'],'number','required min="1" max="'.$list['pages'].'" step="1"').'<button class="btn btn-outline theme-secondary">'.Html::e($this->mediaText('Öffnen')).'</button></form></nav>';
    }
    private function url(string $section,string $id=''): string
    {
        return Html::e('/?'.http_build_query(['view'=>'media','section'=>$section,'item'=>$id]).($section==='removed'?'#removed-media':($id!==''?'#media-remove':'')));
    }
    private function actionUrl(string $section,string $id): string
    {
        return Html::e('/?'.http_build_query(['view'=>'media','section'=>$section,'item'=>$id]).'#media-result');
    }
}
