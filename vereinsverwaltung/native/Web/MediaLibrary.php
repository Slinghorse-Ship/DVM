<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{Media, MediaCatalogue, MediaMetadata, MediaQuota, MediaPolicy, MediaArchive};

/** One bounded image library beside albums, usable with native forms and no JavaScript. */
final class MediaLibrary extends Workspace
{
    public static function tabs(bool $library,bool $removed=false,bool $showRemoved=false,bool $storage=false,bool $showStorage=false,?callable $text=null): string
    {
        $text??=static fn(string $source):string=>$source;
        return '<nav class="nav workspace-tabs" aria-label="'.Html::e($text('Galerie und Bilder')).'"><a class="nav-link" data-nav href="/?view=media"'.(!$library&&!$removed&&!$storage?' aria-current="page"':'').'>'.Html::e($text('Alben')).'</a><a class="nav-link" data-nav href="/?view=media&amp;section=library"'.($library?' aria-current="page"':'').'>'.Html::e($text('Alle Bilder')).'</a>'.($showRemoved?'<a class="nav-link" data-nav href="/?view=media&amp;section=removed"'.($removed?' aria-current="page"':'').'>'.Html::e($text('Entfernte Bilder')).'</a>':'').($showStorage?'<a class="nav-link" data-nav href="/?view=media&amp;section=storage"'.($storage?' aria-current="page"':'').'>'.Html::e($text('Speicher prüfen')).'</a>':'').'</nav>';
    }
    public function action(array $post, array $files): array
    {
        $this->validatePost($post);
        $op = $post['operation'] ?? null;
        if($op==='media-archive-save'){
            $id=$post['id']??null;$version=filter_var($post['archive_version']??null,FILTER_VALIDATE_INT);$state=$post['archived']??null;
            if(!is_string($id)||$version===null||$version===false||!is_string($state)||!in_array($state,['0','1'],true))throw new InvalidArgumentException('Bild und gespeicherten Archivstatus prüfen.');
            (new MediaArchive($this->db,$this->access))->save($id,$state==='1',$version);$_POST=[];
            return ['view'=>'media','section'=>'library','item'=>$id,'notice'=>$this->mediaText($state==='1'?'Bild archiviert.':'Bild zurückgeholt.')];
        }
        if($op==='media-policy-save'){
            $version=filter_var($post['policy_version']??null,FILTER_VALIDATE_INT);
            if($version===false||$version===null)throw new InvalidArgumentException('Gespeicherte Prüfversion prüfen.');
            (new MediaPolicy($this->db,$this->access))->save($post['check_rights']??null,$post['require_details']??null,$version);
            $_POST=[];return ['view'=>'media','section'=>'library','item'=>'','notice'=>$this->mediaText('Bildprüfung gespeichert.')];
        }
        if($op==='media-quota-save'){$version=filter_var($post['quota_version']??null,FILTER_VALIDATE_INT);if($version===false||$version===null)throw new InvalidArgumentException('Gespeicherte Speichergrenzenversion prüfen.');(new MediaQuota($this->db,$this->access))->save($post['limit_mib']??null,$version);$_POST=[];return ['view'=>'media','section'=>'library','item'=>'','notice'=>$this->mediaText('Bildspeichergrenze gespeichert.')];}
        $this->access->require('website','edit');
        if ($op === 'media-edit-copy') {
            $source=$post['id']??null;$file=$files['image']??null;
            if(!is_string($source)||!is_array($file)||($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||!is_string($file['name']??null))throw new InvalidArgumentException('Bild erneut bearbeiten.');
            $service=new Media($this->db,$this->access,$this->mediaRoot);$original=$service->variant($source,'original');
            $uploadId=$post['upload_id']??null;if($uploadId!==null&&!is_string($uploadId))throw new InvalidArgumentException('Upload-Kennung prüfen.');
            $copy=$service->upload($file['tmp_name'],$file['name'],$original['alt'],$source,null,$uploadId);$_POST=[];
            return ['view'=>'media','section'=>'library','item'=>$copy['id'],'notice'=>$this->mediaText('Neue Bildfassung gespeichert.'),'image'=>['id'=>$copy['id'],'alt'=>$copy['alt'],'url'=>'/media-preview/'.$copy['id'].'/medium','edit_url'=>$this->url(['item'=>$copy['id']])]];
        } elseif ($op === 'media-upload') {
            $file = $files['image'] ?? null; $alt = $post['alt'] ?? '';
            $uploadId=$post['upload_id']??null;
            if($uploadId!==null&&(!is_string($uploadId)||!preg_match('/\A[a-f0-9]{32}\z/D',$uploadId)))throw new InvalidArgumentException('Upload-Kennung prüfen.');
            if(is_array($file)&&is_array($file['name']??null)){
                if($uploadId!==null)throw new InvalidArgumentException('Eine Upload-Kennung gehört zu genau einem Bild.');
                if(!is_string($alt)||!array_is_list($file['name'])||count($file['name'])<1||count($file['name'])>50)throw new InvalidArgumentException('Bis zu 50 Bilder auswählen.');
                $accepted=[];$failed=[];
                foreach($file['name']as$index=>$name){
                    try{
                        if(!is_string($name)||($file['error'][$index]??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name'][$index]??null)||!is_uploaded_file($file['tmp_name'][$index]))throw new InvalidArgumentException('Datei erneut auswählen.');
                        $accepted[]=(new Media($this->db,$this->access,$this->mediaRoot))->upload($file['tmp_name'][$index],$name,$alt)['id'];
                    }catch(\RuntimeException $error){$failed[]=(is_string($name)?$name.': ':'').$this->mediaText('Bild konnte nicht verarbeitet werden.');}
                    catch(\DomainException|\InvalidArgumentException $error){$failed[]=is_string($name)?$name.': '.$this->mediaText($error->getMessage()):$this->mediaText('Dateiname prüfen.');}
                }
                $message=$this->mediaFormat('{count} Bilder hochgeladen.',['count'=>count($accepted)]).($failed?' '.$this->mediaFormat('{count} fehlgeschlagen: {issues}',['count'=>count($failed),'issues'=>implode(' · ',array_slice($failed,0,5))]):'');
                if(!$accepted)throw new InvalidArgumentException($message);
                $_POST=[];return ['view'=>'media','section'=>'library','item'=>'','notice'=>$message];
            }
            if (!is_string($alt) || !is_array($file) || ($file['error'] ?? null)!==UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name']) || !is_string($file['name'] ?? null)) throw new InvalidArgumentException('Bild und Alternativtext auswählen.');
            $uploaded = (new Media($this->db,$this->access,$this->mediaRoot))->upload($file['tmp_name'],$file['name'],$alt,null,null,$uploadId);$id=$uploaded['id'];
            if($uploadId!==null){$_POST=[];return ['view'=>'media','section'=>'library','item'=>$id,'notice'=>$this->mediaText('Bild hochgeladen.'),'image'=>['id'=>$id,'alt'=>$uploaded['alt'],'name'=>$uploaded['original_name'],'url'=>'/media-preview/'.$id.'/thumb','edit_url'=>$this->url(['item'=>$id])]];}
        } elseif ($op === 'media-metadata-save') {
            $id = $post['id'] ?? null; $version = filter_var($post['version'] ?? null,FILTER_VALIDATE_INT);
            if (!is_string($id) || $version===false || $version===null) throw new InvalidArgumentException('Bild und gespeicherte Version prüfen.');
            $input = $post; $input['channels'] = $post['channels'] ?? [];
            $proof = null; $box = null;
            if (array_key_exists('consent_reference',$post)) { if (!is_string($post['consent_reference'])) throw new InvalidArgumentException('Privaten Nachweis als Text angeben.'); $proof = $post['consent_reference']; $box = $this->box(); }
            (new MediaMetadata($this->db,$this->access,$box))->save($id,$input,$version,$proof);
        } else throw new DomainException('Unbekannte Bildaktion.');
        $_POST=[];
        return ['view'=>'media','section'=>'library','item'=>$id,'notice'=>$this->mediaText($op==='media-upload'?'Bild hochgeladen.':'Bildangaben gespeichert.')];
    }
    public function manager(string $id = '', array $query = []): string
    {
        $this->access->require('website','read');
        if($id!==''&&$this->can('website','manage')&&\Kulturhus\Native\Cms\MediaAvailability::installed($this->db)){
            $removal=new \Kulturhus\Native\Cms\MediaRemoval($this->db,$this->access,$this->mediaRoot);
            if(in_array($removal->get($id)['state'],['removed','purging','purged'],true)||$removal->pendingOperation($id)!=='')return (new RemovedMedia($this->db,$this->access,$this->mediaRoot,$this->csrf))->manager($id,$query);
        }
        $html = self::tabs(true,false,$this->can('website','manage')&&\Kulturhus\Native\Cms\MediaAvailability::installed($this->db),false,$this->can('website','manage'),text:$this->mediaText(...)).'<div class="media-library">';
        if ($id !== '') return $html.$this->editor($id,$query).'</div>';
        $list = (new MediaCatalogue($this->db,$this->access))->listing($query);
        $html .= '<div class="media-library-heading"><h2>'.Html::e($this->mediaText('Alle Bilder')).'</h2><span>'.Html::e($this->mediaFormat('{count} Bilder · Seite {page} von {pages}',['count'=>$list['total'],'page'=>$list['page'],'pages'=>$list['pages']])).'</span></div>';
        $html .= $this->quota().$this->policy().'<form class="media-library-filters" method="get">'.Html::hidden('view','media').Html::hidden('section','library').Html::input('media_q',$this->mediaText('Bilder suchen'),$list['q'],'search','maxlength="120"').'<button class="btn btn-solid theme-primary">'.Html::e($this->mediaText('Suchen')).'</button><details class="media-filter-options"><summary>'.Html::e($this->mediaText('Bildrechte & Archiv')).'</summary>'.Html::select('media_state',$this->mediaText('Bildrechte'),$this->mediaOptions(MediaCatalogue::STATES),$list['state']).Html::select('media_archive',$this->mediaText('Bildauswahl'),$this->mediaOptions(MediaCatalogue::ARCHIVES),$list['archive']).'</details></form>';
        if ($this->can('website','edit')) {
            $posted = ($_POST['operation'] ?? '')==='media-upload';
            $html .= '<details class="panel media-upload"'.($posted?' open':'').'><summary>'.Html::e($this->mediaText('Bilder hochladen')).'</summary><form method="post" enctype="multipart/form-data" data-media-upload-form data-library-photo-status-url="/page-photo-status">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','media-upload').'<label>'.Html::e($this->mediaText('Bilddateien')).'<input class="form-control" type="file" name="image[]" accept="'.\Kulturhus\Native\Cms\PhotoInput::accept().'" multiple required data-media-upload data-dirty-ignore></label><details><summary>'.Html::e($this->mediaText('Bildbeschreibung ergänzen')).'</summary>'.Html::input('alt',$this->mediaText('Alternativtext'),$posted&&is_string($_POST['alt']??null)?$_POST['alt']:'','text','maxlength="500"').'</details><p role="status" aria-live="polite" data-upload-progress></p><div data-library-upload-actions><button class="btn btn-solid theme-primary">'.Html::e($this->mediaText('Hochladen')).'</button><button class="btn btn-outline theme-secondary" type="button" data-library-upload-cancel hidden>'.Html::e($this->mediaText('Upload abbrechen')).'</button><button class="btn btn-outline theme-secondary" type="button" data-library-upload-check hidden>'.Html::e($this->mediaText('Uploads prüfen')).'</button><button class="btn btn-outline theme-secondary" type="button" data-library-upload-forget hidden>'.Html::e($this->mediaText('Prüfung beenden')).'</button></div><div data-library-upload-results hidden></div></form></details>';
        }
        $html .= $this->pager($list).'<div class="media-library-grid">';
        foreach ($list['rows'] as $row) {
            $m = $row['metadata'];
            $html .= '<article class="panel media-library-card"><a data-nav href="'.Html::e($this->url(['item'=>$row['id']])).'"><img loading="lazy" src="/media-preview/'.Html::e($row['id']).'/thumb" alt="" width="'.(int)$row['width'].'" height="'.(int)$row['height'].'"><h3>'.Html::e($m['alt']!==''?$m['alt']:$row['original_name']).'</h3></a><p>'.Html::e($row['original_name']).'</p>'.$this->state($m).($row['archived']?'<span class="badge">'.Html::e($this->mediaText('Archiviert')).'</span>':'').'</article>';
        }
        if (!$list['rows']) $html .= '<p>'.Html::e($this->mediaText('Keine Bilder gefunden.')).'</p>';
        return $html.'</div>'.$this->pager($list).'</div>';
    }
    private function policy():string
    {
        if(!$this->can('website','manage'))return '';
        $p=(new MediaPolicy($this->db,$this->access))->get();$posted=($_POST['operation']??'')==='media-policy-save';
        if($posted)foreach(['version'=>'policy_version','check_rights'=>'check_rights','require_details'=>'require_details']as$key=>$field)if(is_scalar($_POST[$field]??null))$p[$key]=$_POST[$field];
        return '<details class="panel media-policy"'.($posted?' open':'').'><summary>'.Html::e($this->mediaText('Bildprüfung für alle Bereiche')).'</summary><form method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','media-policy-save').Html::hidden('policy_version',$p['version']).Html::select('check_rights',$this->mediaText('Geklärte Rechte und Einwilligung vor Verwendung prüfen'),$this->mediaOptions(['0'=>'Aus','1'=>'Ein']),(string)(int)$p['check_rights']).Html::select('require_details',$this->mediaText('Credit, Quelle und Lizenz vor Verwendung verlangen'),$this->mediaOptions(['0'=>'Aus','1'=>'Ein']),(string)(int)$p['require_details']).'<button class="btn btn-solid theme-primary">'.Html::e($this->mediaText('Bildprüfung speichern')).'</button></form></details>';
    }
    private function quota():string
    {
        $quota=(new MediaQuota($this->db,$this->access))->get();
        $used=$this->mediaNumber($quota['used_bytes']/1048576,2);
        $summary=$this->mediaFormat($quota['limit_bytes']===0?'{count} Bilder · {used} MiB':'{count} Bilder · {used} MiB von {limit} MiB',['count'=>$quota['image_count'],'used'=>$used,'limit'=>$this->mediaNumber($quota['limit_bytes']/1048576)]);
        $html='<div class="media-quota"><p>'.Html::e($summary).'</p>';
        if($this->can('website','manage')){
            $posted=($_POST['operation']??'')==='media-quota-save';
            $value=$posted&&is_string($_POST['limit_mib']??null)?$_POST['limit_mib']:(string)($quota['limit_bytes']/1048576);
            $version=$posted&&is_string($_POST['quota_version']??null)?$_POST['quota_version']:(string)$quota['version'];
            $html.='<details'.($posted?' open':'').'><summary>'.Html::e($this->mediaText('Speichergrenze')).'</summary><form method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','media-quota-save').Html::hidden('quota_version',$version).Html::input('limit_mib',$this->mediaText('Speichergrenze in MiB (0 = ohne Grenze)'),$value,'number','required min="0" max="102400" step="1"').'<button class="btn btn-solid theme-primary">'.Html::e($this->mediaText('Speichergrenze speichern')).'</button></form></details>';
        }
        return $html.'</div>';
    }
    private function editor(string $id, array $query): string
    {
        $service = new MediaMetadata($this->db,$this->access); $current = $service->get($id); $m = $current;
        $image = (new Media($this->db,$this->access,$this->mediaRoot))->variant($id,'medium');
        $posted = ($_POST['operation']??'')==='media-metadata-save' && ($_POST['id']??'')===$id;
        if ($posted) foreach ($m as $key=>$_) if (is_string($_POST[$key]??null) || ($key==='channels' && is_array($_POST[$key]??null) && array_is_list($_POST[$key]))) $m[$key] = $_POST[$key];
        $html = '<a class="back-link" data-nav href="'.$this->url().'">'.Html::e($this->mediaText('← Alle Bilder')).'</a><div class="media-library-heading"><h2>'.Html::e($this->mediaText('Bildangaben')).'</h2>'.$this->state($current).'</div>'.$this->archive($id,$query).(new RemovedMedia($this->db,$this->access,$this->mediaRoot,$this->csrf))->panel($id).'<div class="media-editor"><figure><img src="/media-preview/'.Html::e($id).'/medium" alt="'.Html::e($current['alt']).'" width="'.$image['width'].'" height="'.$image['height'].'"><figcaption>'.Html::e($this->mediaFormat('{width} × {height} Pixel',['width'=>$image['width'],'height'=>$image['height']])).'</figcaption></figure>';
        if($this->can('website','edit')){
            $html=str_replace('<figure><img src=','<figure><img data-original="/media-preview/'.Html::e($id).'/original" src=',$html);
            $html=str_replace('</figcaption></figure>','</figcaption><form method="post" enctype="multipart/form-data" data-photo-copy data-photo-copy-status-url="/page-photo-status" data-photo-copy-name="'.Html::e($image['name']).'">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','media-edit-copy').Html::hidden('id',$id).'<button class="btn btn-outline theme-secondary" type="button" data-photo-copy-edit hidden>'.Html::e($this->mediaText('Foto bearbeiten')).'</button><button class="btn btn-outline theme-secondary" type="button" data-photo-copy-check hidden>'.Html::e($this->mediaText('Speicherung prüfen')).'</button><button class="btn btn-outline theme-secondary" type="button" data-photo-copy-forget hidden>'.Html::e($this->mediaText('Prüfung beenden')).'</button><p>'.Html::e($this->mediaText('Neue private Bearbeitungskopie. Vorhandene Verwendungen bleiben beim Original.')).'</p><p role="status" aria-live="polite" data-photo-copy-status></p></form></figure>',$html);
        }
        if (!$this->can('website','edit')) return $html.$this->values($current).'</div>'.(new MediaUsagePanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($id,$query).$this->history($id,$query,$service);
        $version = $posted && is_scalar($_POST['version']??null) ? $_POST['version'] : $current['version'];
        $html .= '<form class="panel media-metadata-form" method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','media-metadata-save').Html::hidden('id',$id).Html::hidden('version',$version).Html::input('alt',$this->mediaText('Alternativtext'),$m['alt'],'text','required maxlength="500"').Html::input('credit',$this->mediaText('Fotocredit'),$m['credit'],'text','maxlength="500"');
        $html .= '<details'.($posted?' open':'').'><summary>'.Html::e($this->mediaText('Quelle und Lizenz')).'</summary>'.Html::input('source_name',$this->mediaText('Quelle'),$m['source_name'],'text','maxlength="500"').Html::input('source_url',$this->mediaText('Quellenlink'),$m['source_url'],'url','maxlength="2048"').Html::input('license_name',$this->mediaText('Lizenz / Nutzungsvereinbarung'),$m['license_name'],'text','maxlength="200"').Html::input('license_url',$this->mediaText('Lizenzlink'),$m['license_url'],'url','maxlength="2048"').'</details>';
        $html .= '<details'.($posted?' open':'').'><summary>'.Html::e($this->mediaText('Nutzung und Einwilligung')).'</summary>'.Html::select('rights_state',$this->mediaText('Bildrechte'),$this->mediaOptions(MediaMetadata::RIGHTS),$m['rights_state']).Html::select('consent_state',$this->mediaText('Einwilligung'),$this->mediaOptions(MediaMetadata::CONSENT),$m['consent_state']).Html::input('expires_on',$this->mediaText('Nutzung erlaubt bis'),$m['expires_on'],'date').'<fieldset><legend>'.Html::e($this->mediaText('Erlaubte Kanäle')).'</legend>';
        foreach (MediaMetadata::CHANNELS as $channel=>$label) $html .= '<label class="media-channel"><input class="check" type="checkbox" name="channels[]" value="'.$channel.'"'.(in_array($channel,$m['channels'],true)?' checked':'').'>'.Html::e($this->mediaText($label)).'</label>';
        $html .= '</fieldset>';
        if ($this->can('website','manage')) {
            try { $proof = (new MediaMetadata($this->db,$this->access,$this->box()))->privateProof($id); if ($posted && is_string($_POST['consent_reference']??null)) $proof=$_POST['consent_reference']; $html .= '<label>'.Html::e($this->mediaText('Privater Nachweisverweis')).'<textarea class="form-control" name="consent_reference" rows="3" maxlength="4000">'.Html::e($proof).'</textarea></label>'; }
            catch (DomainException) { $html .= '<p role="status">'.Html::e($this->mediaText('Privater Nachweiszugang nicht verfügbar.')).'</p>'; }
        }
        $html .= '</details>';
        if ($posted && (string)$version!==(string)$current['version']) $html .= '<p role="alert">'.Html::e($this->mediaText('Bildangaben geändert.')).' <a data-nav href="'.Html::e($this->url(['item'=>$id])).'">'.Html::e($this->mediaText('Aktuellen Stand öffnen')).'</a></p>';
        return $html.'<button class="btn btn-solid theme-primary">'.Html::e($this->mediaText('Bildangaben speichern')).'</button></form></div>'.(new MediaUsagePanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($id,$query).$this->history($id,$query,$service);
    }
    private function history(string $id, array $query, MediaMetadata $service): string
    {
        $page = $query['media_history_page']??'1';
        if ((!is_string($page)&&!is_int($page)) || !preg_match('/\A[1-9][0-9]{0,6}\z/D',(string)$page)) throw new InvalidArgumentException('Bildverlaufsseite prüfen.');
        $history = $service->history($id,(int)$page);
        if (!$history['total']) return '';
        $html = '<details class="panel media-history"><summary>'.Html::e($this->mediaFormat('Bildverlauf · {count} Versionen',['count'=>$history['total']])).'</summary>';
        foreach ($history['rows'] as $row) $html .= '<details><summary>'.Html::e($this->mediaFormat('Version {version} · {date}',['version'=>$row['version'],'date'=>$row['created_at']])).'</summary>'.$this->values($row['metadata']).'</details>';
        $html .= '<nav class="media-library-pager" aria-label="'.Html::e($this->mediaText('Bildverlauf')).'">';
        foreach (['Zurück'=>$history['page']-1,'Weiter'=>$history['page']+1] as $label=>$target) if ($target>=1 && $target<=$history['pages']) $html .= '<a data-nav href="'.Html::e($this->url(['item'=>$id,'media_history_page'=>$target])).'">'.Html::e($this->mediaText($label)).'</a>';
        return $html.'</nav></details>';
    }
    private function values(array $m): string
    {
        $html = '<dl class="media-values">';
        foreach (['alt'=>'Alternativtext','credit'=>'Fotocredit','source_name'=>'Quelle','source_url'=>'Quellenlink','license_name'=>'Lizenz','license_url'=>'Lizenzlink','expires_on'=>'Nutzung bis'] as $key=>$label) if (($m[$key]??'')!=='') $html .= '<dt>'.Html::e($this->mediaText($label)).'</dt><dd>'.Html::e($m[$key]).'</dd>';
        return $html.'</dl>'.$this->state($m);
    }
    private function archive(string $id,array $query):string
    {
        $service=new MediaArchive($this->db,$this->access);$state=$service->get($id);
        $posted=($_POST['operation']??null)==='media-archive-save'&&($_POST['id']??null)===$id;
        $version=$posted&&is_scalar($_POST['archive_version']??null)?(string)$_POST['archive_version']:(string)$state['version'];
        $target=$posted&&in_array($_POST['archived']??null,['0','1'],true)?$_POST['archived']:($state['archived']?'0':'1');
        $html='<div class="media-archive"><span class="badge">'.Html::e($this->mediaText($state['archived']?'Archiviert':'Aktuell')).'</span>';
        if($this->can('website','edit'))$html.='<form method="post">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation','media-archive-save').Html::hidden('id',$id).Html::hidden('archive_version',$version).Html::hidden('archived',$target).'<button class="btn btn-outline theme-secondary secondary">'.Html::e($this->mediaText($target==='0'?'Bild zurückholen':'Bild archivieren')).'</button></form>';
        if($posted&&$version!==(string)$state['version'])$html.='<p role="alert">'.Html::e($this->mediaText('Der Archivstatus wurde geändert. Aktuellen Stand öffnen.')).' <a data-nav href="'.Html::e($this->url(['item'=>$id])).'">'.Html::e($this->mediaText('Aktuellen Stand öffnen')).'</a></p>';
        $history=$service->history($id,$query['media_archive_page']??1);
        if($history['total']){
            $html.='<details><summary>'.Html::e($this->mediaFormat('Archivverlauf · {count}',['count'=>$history['total']])).'</summary><ol>';
            foreach($history['rows']as$row)$html.='<li>'.Html::e($this->mediaText($row['archived']?'Archiviert':'Zurückgeholt')).' · '.Html::e($row['actor_name']).' · '.Html::e($row['changed_at']).'</li>';
            $html.='</ol><nav aria-label="'.Html::e($this->mediaText('Archivverlaufsseiten')).'"><span>'.Html::e($this->mediaFormat('Seite {page} von {pages}',['page'=>$history['page'],'pages'=>$history['pages']])).'</span>';
            foreach(['Zurück'=>$history['page']-1,'Weiter'=>$history['page']+1]as$label=>$target)if($target>=1&&$target<=$history['pages'])$html.='<a data-nav href="'.Html::e($this->url(['item'=>$id,'media_archive_page'=>$target])).'">'.Html::e($this->mediaText($label)).'</a>';
            $html.='</nav></details>';
        }
        return $html.'</div>';
    }
    private function state(array $m): string
    {
        $html = '<span class="badge">'.Html::e($this->mediaText(MediaMetadata::RIGHTS[$m['rights_state']])).'</span>';
        if ($m['consent_state']==='withdrawn') $html .= '<span class="badge">'.Html::e($this->mediaText('Einwilligung widerrufen')).'</span>';
        if ($m['expires_on']!=='' && !MediaMetadata::usable($m,'website')) $html .= '<span class="badge">'.Html::e($this->mediaFormat('Nutzung prüfen · {date}',['date'=>$m['expires_on']])).'</span>';
        return $html;
    }
    private function pager(array $list): string
    {
        if ($list['pages']===1) return '';
        $html = '<nav class="media-library-pager" aria-label="'.Html::e($this->mediaText('Bildseiten')).'"><span>'.Html::e($this->mediaFormat('Seite {page} von {pages}',['page'=>$list['page'],'pages'=>$list['pages']])).'</span>';
        foreach (['Vorherige Seite'=>$list['page']-1,'Nächste Seite'=>$list['page']+1] as $label=>$target) if ($target>=1 && $target<=$list['pages']) $html .= '<a data-nav href="'.Html::e($this->url(['media_q'=>$list['q'],'media_state'=>$list['state'],'media_archive'=>$list['archive'],'media_page'=>$target])).'">'.Html::e($this->mediaText($label)).'</a>';
        return $html.'</nav>';
    }
    private function url(array $args=[]): string { return '/?'.http_build_query(['view'=>'media','section'=>'library']+$args); }
}
