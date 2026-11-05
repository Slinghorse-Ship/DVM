<?php declare(strict_types=1);
namespace Kulturhus\Native\Files\Web;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Files\{AssetVersions,Catalog,Formats,ImageGroups,ImagePicker,Inventory,Quota,Removal,Retention,Uploads};
use Kulturhus\Native\Shared\MediaDescription;
use Kulturhus\Native\Web\{Html,Workspace};

/** Compact ordinary HTML forms; application routers own entrypoints, authentication and redirects. */
final class Library extends Workspace
{
    public const OPERATIONS=['file-upload','file-image-link','file-recover','file-replace','file-metadata','file-restore','file-remove','file-removal-restore','file-purge','file-quota-save','file-retention-save','file-images-detach','file-images-restore'];
    private array $incomingFiles=[];
    public function action(array $post,array $files=[]):array
    {
        $this->incomingFiles=$files;
        $this->validatePost($post);$operation=$this->scalar($post,'operation');$service=$this->versions();$notice='Datei gespeichert.';$id='';
        if($operation==='file-upload'){$created=$this->uploads()->uploadMany($this->uploaded('asset_files'));$id=count($created)===1?$created[0]['id']:'';$notice=count($created)===1?'Datei hochgeladen.':count($created).' Dateien hochgeladen.';}
        elseif($operation==='file-image-link'){$created=$service->linkImage($this->scalar($post,'source_id'));$id=$created['id'];$notice='Bildfolge angelegt.';}
        elseif($operation==='file-recover'){$recovered=(new Inventory($this->db,$this->access,$this->root()))->recover($this->scalar($post,'source_id'),$this->scalar($post,'fingerprint'),$this->scalar($post,'recovery_name'));$id=$recovered['asset']['id'];$notice=$recovered['created']?'Datei wiederhergestellt.':'Datei bereits wiederhergestellt.';}
        elseif($operation==='file-quota-save'){(new Quota($this->db,$this->access))->save($post['limit_mib']??null,$this->counter($post,'version',true));$notice='Dateispeichergrenze gespeichert.';}
        elseif($operation==='file-retention-save'){(new Retention($this->db,$this->access))->save($post['days']??null,$this->counter($post,'version',true));$notice='Dateiaufbewahrung gespeichert.';}
        else{
            $id=$this->scalar($post,'id');AssetVersions::identifier($id);$version=$this->counter($post,'version');
            if($operation==='file-replace'){$files=$this->uploaded('replacement');if(count($files)!==1)throw new InvalidArgumentException('Genau eine neue Dateifassung auswählen.');$this->uploads()->replace($id,$version,$files[0]['path'],$files[0]['name']);$notice='Neue Dateifassung gespeichert.';}
            elseif($operation==='file-metadata'){
                $asset=$service->get($id);$current=array_replace($asset['metadata'],$asset['source_metadata']??[]);$metadata=array_replace($current,['title'=>$this->scalar($post,'title'),'description'=>$this->scalar($post,'description'),'tags'=>$this->tags($this->scalar($post,'tags'))]);
                foreach(['alt','credit','source_name','source_url','license_name','license_url']as$key)$metadata[$key]=$this->scalar($post,$key);
                if(($post['rights_present']??null)==='1'){$this->access->require('website','manage');foreach(['rights_state','consent_state','expires_on']as$key)$metadata[$key]=$this->scalar($post,$key);$channels=$post['channels']??[];if(!is_array($channels)||!array_is_list($channels))throw new InvalidArgumentException('Dateikanäle prüfen.');$metadata['channels']=$channels;}
                $service->metadata($id,$version,$metadata,$asset['kind']==='image'?$this->counter($post,'media_version',true):null);$notice='Dateiangaben gespeichert.';
            }elseif($operation==='file-restore'){$service->restore($id,$this->scalar($post,'revision'),$version);$notice='Dateiversion als neue Fassung wiederhergestellt.';}
            elseif(in_array($operation,['file-images-detach','file-images-restore'],true)){
                $groups=new ImageGroups($this->db,$this->access,$this->root(),$this->mediaRoot);
                if($operation==='file-images-detach'){if(($post['confirm_detach']??null)!=='1')throw new InvalidArgumentException('Auflösen der Bildfolge bestätigen.');$groups->detach($id,$version);$notice='Bildfolge aufgelöst.';}
                else{$groups->restore($id,$version);$notice='Bildfolge wieder aktiviert.';}
            }
            elseif(in_array($operation,['file-remove','file-removal-restore','file-purge'],true)){
                $removal=new Removal($this->db,$this->access,$this->root());$token=$this->scalar($post,'operation_id');
                if($operation==='file-remove'){$removal->remove($id,$version,$token);$notice='Datei entfernt.';}
                elseif($operation==='file-removal-restore'){$removal->restore($id,$version,$token);$notice='Datei wiederhergestellt.';}
                else{if(($post['confirm_purge']??null)!=='1')throw new InvalidArgumentException('Endgültiges Löschen bestätigen.');$removal->purge($id,$version,$token);$notice='Dateifassungen endgültig gelöscht.';}
            }else throw new InvalidArgumentException('Dateiaktion prüfen.');
        }
        $_POST=[];return ['view'=>'media','section'=>'files','item'=>$id,'notice'=>$notice];
    }
    public function manager(array $query=[]):string
    {
        $this->access->require('website','read');$id=$query['item']??'';if(!is_string($id))throw new InvalidArgumentException('Datei erneut öffnen.');
        $recover=$query['recover_file']??'';if(!is_string($recover))throw new InvalidArgumentException('Dateigruppe erneut öffnen.');if($recover!=='')return '<div class="file-library">'.$this->recovery($recover).'</div>';
        if($id!=='')return '<div class="file-library">'.$this->detail($id,$query).'</div>';
        $q=$query['q']??'';$tag=$query['tag']??'';$kind=$query['kind']??'all';$page=$query['files_page']??'1';$mine=$query['mine']??'0';$removed=$query['removed']??'0';
        foreach([$q,$tag,$kind,$page,$mine,$removed]as$value)if(!is_string($value))throw new InvalidArgumentException('Dateisuche prüfen.');
        if(!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page)||!in_array($mine,['0','1'],true)||!in_array($removed,['0','1'],true))throw new InvalidArgumentException('Dateiseite prüfen.');
        $list=(new Catalog($this->db,$this->access))->listing($q,(int)$page,$kind,$mine==='1',$tag,$removed==='1');
        $h='<div class="file-library"><div class="file-library-heading"><h2 tabindex="-1">Dateien</h2><nav aria-label="Dateiablage"><a data-nav href="'.Html::e($this->url()).'"'.($removed==='0'?' aria-current="page"':'').'>Dateien</a>';
        if($this->can('website','manage'))$h.='<a data-nav href="'.Html::e($this->url(['removed'=>'1'])).'"'.($removed==='1'?' aria-current="page"':'').'>Entfernt</a>';
        $h.='</nav></div>';
        if($removed==='0'&&$this->can('website','edit'))$h.=$this->upload().$this->existingImage($query);
        $h.='<form method="get" class="file-library-search">'.Html::hidden('view','media').Html::hidden('section','files').Html::hidden('removed',$removed).'<label>Suche<input type="search" name="q" value="'.Html::e($q).'" maxlength="200"></label><label>Dateityp<select name="kind">';
        foreach(['all'=>'Alle','file'=>'Dokumente und Medien','image'=>'Bilder']as$key=>$label)$h.='<option value="'.$key.'"'.($kind===$key?' selected':'').'>'.$label.'</option>';
        $h.='</select></label><label>Schlagwort<input name="tag" maxlength="60" value="'.Html::e($tag).'"></label><label class="file-check"><input type="checkbox" name="mine" value="1"'.($mine==='1'?' checked':'').'>Meine Uploads</label><button class="btn btn-outline theme-secondary">Suchen</button></form>';
        $h.='<p class="file-library-count" role="status">'.$list['total'].' '.($list['total']===1?'Datei':'Dateien').' · Seite '.$list['page'].' von '.$list['pages'].'</p>'.$this->pager($list).'<div class="file-library-list">';
        foreach($list['rows']as$row){$h.='<article class="panel file-library-card" data-file-asset="'.Html::e($row['id']).'">';if($row['kind']==='image'&&$row['state']==='active')$h.='<img src="/media-preview/'.Html::e($row['source_id']).'/thumb" alt="" width="120" height="120" loading="lazy">';$h.='<div><h3><a data-nav href="'.Html::e($this->url(['item'=>$row['id']])).'">'.Html::e($row['metadata']['title']).'</a></h3><p>'.Html::e($row['name']).' · '.Html::e($this->bytes($row['bytes'])).' · Fassung '.$row['revision'].'</p>'.$this->tagList($row['metadata']['tags']).'</div></article>';}
        if($list['rows']===[])$h.='<p>Keine Dateien gefunden.</p>';
        $h.='</div>'.$this->pager($list);if($this->can('website','manage'))$h.=$this->settings().$this->storage($query);return $h.'</div>';
    }
    private function detail(string $id,array $query):string
    {
        $asset=$this->versions()->get($id,true);$page=$query['file_history_page']??'1';if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page))throw new InvalidArgumentException('Dateiverlauf prüfen.');
        $history=$this->versions()->history($id,(int)$page);$active=$asset['state']==='active';
        $h='<a data-nav class="back-link" href="'.Html::e($this->url($active?[]:['removed'=>'1'])).'">← Dateien</a><div class="file-library-heading"><h2 tabindex="-1">'.Html::e($asset['metadata']['title']).'</h2><span>Fassung '.$asset['revision'].'</span></div><p>'.Html::e($asset['name']).' · '.Html::e($this->bytes($asset['bytes'])).'</p>';
        $stale=($_POST['id']??null)===$id&&((is_string($_POST['version']??null)&&$_POST['version']!==(string)$asset['version'])||($asset['kind']==='image'&&($_POST['operation']??null)==='file-metadata'&&is_string($_POST['media_version']??null)&&$_POST['media_version']!==(string)($asset['source_metadata_version']??'')));if($stale)$h.='<a data-nav class="btn btn-outline theme-secondary" href="'.Html::e($this->url(['item'=>$id])).'">Aktuellen Stand öffnen</a>';
        if($active){if($asset['kind']==='image')$h.='<img class="file-library-preview" src="/media-preview/'.Html::e($asset['source_id']).'/medium" alt="'.Html::e($asset['metadata']['alt']).'">';$h.='<a data-native-document class="btn btn-outline theme-secondary" href="/file-preview/'.Html::e($asset['revision_id']).'" download>Herunterladen</a>';}
        $h.=$this->tagList($asset['metadata']['tags']);if($active&&$this->can('website','edit'))$h.=$this->metadataForm($asset).$this->replacement($asset);
        if($asset['kind']==='image'&&$active)$h.='<a data-nav class="btn btn-outline theme-secondary" href="'.Html::e('/?'.http_build_query(['view'=>'media','section'=>'library','item'=>$asset['source_id']])).'">Bildverwaltung</a>';
        $open=isset($query['file_history_page'])||($_POST['operation']??null)==='file-restore';$h.='<details class="panel file-history"'.($open?' open':'').'><summary>Verlauf ('.$history['total'].')</summary><ol class="file-history-list">';
        foreach($history['rows']as$row){$h.='<li><div><strong>Fassung '.$row['revision'].'</strong> · '.Html::e($this->time($row['created_at'])).'<p>'.Html::e($row['name']).' · '.Html::e($this->bytes($row['bytes'])).'</p>'.$this->tagList($row['metadata']['tags']).'</div>';
            if($active){$h.='<a data-native-document class="btn btn-outline theme-secondary" href="/file-preview/'.Html::e($row['revision_id']).'" download>Download</a>';if($this->can('website','edit')&&$row['revision_id']!==$asset['revision_id'])$h.=$this->form('file-restore',$asset).Html::hidden('revision',$row['revision_id']).'<button class="btn btn-outline theme-secondary" aria-label="Fassung '.$row['revision'].' wiederherstellen">Wiederherstellen</button></form>';}$h.='</li>';}
        $h.='</ol><nav class="file-library-pager" aria-label="Dateiverlauf">';if($history['page']>1)$h.='<a data-nav href="'.Html::e($this->url(['item'=>$id,'file_history_page'=>(string)($history['page']-1)])).'">← Neuer</a>';if($history['page']<$history['pages'])$h.='<a data-nav href="'.Html::e($this->url(['item'=>$id,'file_history_page'=>(string)($history['page']+1)])).'">Älter →</a>';$h.='</nav></details>';
        if($this->can('website','manage'))$h.=$asset['kind']==='file'?$this->removal($asset):$this->imageGroup($asset,$query);return $h;
    }
    private function existingImage(array $query):string
    {
        $q=$query['media_q']??'';$page=$query['media_page']??'1';if(!is_string($q)||!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page))throw new InvalidArgumentException('Bildsuche prüfen.');$list=(new ImagePicker($this->db,$this->access))->listing($q,(int)$page);
        $open=($query['image_anchor']??'')==='file-existing-image'||($_POST['operation']??null)==='file-image-link';$h='<details id="file-existing-image" class="panel file-existing-image"'.($open?' open':'').'><summary>Vorhandenes Bild als Bildfolge speichern</summary><form method="get" action="#file-existing-image">'.Html::hidden('view','media').Html::hidden('section','files').Html::hidden('image_anchor','file-existing-image').'<label>Bildbeschreibung oder Dateiname<input type="search" name="media_q" maxlength="120" value="'.Html::e($q).'"></label><button class="btn btn-outline theme-secondary">Bild suchen</button></form>';
        if($list['rows']!==[]){$h.=$this->form('file-image-link').'<label>Bild<select name="source_id" required><option value="">Bitte auswählen</option>';foreach($list['rows']as$row)$h.='<option value="'.Html::e($row['id']).'">'.Html::e($row['name'].($row['alt']!==''?' · '.$row['alt']:'')).'</option>';$h.='</select></label><button class="btn btn-solid theme-primary">Bildfolge anlegen</button></form>';}else$h.='<p>Keine freien Bilder gefunden.</p>';
        $h.='<p role="status">'.$list['total'].' Bilder · Seite '.$list['page'].' von '.$list['pages'].'</p><nav class="file-library-pager" aria-label="Vorhandene Bilder">';$args=['media_q'=>$q,'image_anchor'=>'file-existing-image'];if($list['page']>1)$h.='<a data-nav href="'.Html::e($this->url($args+['media_page'=>(string)($list['page']-1)])).'#file-existing-image">← Zurück</a>';if($list['page']<$list['pages'])$h.='<a data-nav href="'.Html::e($this->url($args+['media_page'=>(string)($list['page']+1)])).'#file-existing-image">Weitere Bilder →</a>';return $h.'</nav></details>';
    }
    private function imageGroup(array $asset,array $query):string
    {
        $h='<details class="panel file-image-group"><summary>'.($asset['state']==='active'?'Bildfolge auflösen':'Aufgelöste Bildfolge').'</summary>';
        if($asset['state']==='active')$h.=$this->form('file-images-detach',$asset).'<label class="file-check"><input type="checkbox" name="confirm_detach" value="1" required>Bildfolge auflösen; einzelne Bilder bleiben in der Bildverwaltung erhalten</label><button class="btn btn-outline theme-secondary">Bildfolge auflösen</button></form>';
        else$h.='<p>Einzelne Bilder und ihre Aufbewahrung verwaltest du in der Bildverwaltung.</p>'.$this->form('file-images-restore',$asset).'<button class="btn btn-outline theme-secondary">Bildfolge wieder aktivieren</button></form>';
        $page=$query['file_group_page']??'1';if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,6}\z/D',$page))throw new InvalidArgumentException('Bildfolgenverlauf prüfen.');
        $history=(new ImageGroups($this->db,$this->access,$this->root(),$this->mediaRoot))->history($asset['id'],(int)$page);
        if($history['total']>0){$h.='<ol class="file-history-list">';foreach($history['rows']as$row)$h.='<li>'.Html::e($this->time($row['changed_at'])).' · '.($row['state']==='active'?'Wieder aktiviert':'Aufgelöst').'</li>';$h.='</ol><nav class="file-library-pager" aria-label="Bildfolgenverlauf">';if($history['page']>1)$h.='<a data-nav href="'.Html::e($this->url(['item'=>$asset['id'],'file_group_page'=>(string)($history['page']-1)])).'">← Neuer</a>';if($history['page']<$history['pages'])$h.='<a data-nav href="'.Html::e($this->url(['item'=>$asset['id'],'file_group_page'=>(string)($history['page']+1)])).'">Älter →</a>';$h.='</nav>';}
        return $h.'</details>';
    }
    private function upload():string
    {return '<details class="panel file-upload"'.(($_POST['operation']??null)==='file-upload'?' open':'').'><summary>Hochladen</summary>'.$this->form('file-upload',null,true).'<label>Dateien<input type="file" name="asset_files[]" multiple required accept="'.$this->accept().'"></label><button class="btn btn-solid theme-primary">Hochladen</button></form></details>';}
    private function replacement(array $asset):string
    {return '<details class="panel file-replacement"'.(($_POST['operation']??null)==='file-replace'?' open':'').'><summary>Datei ersetzen</summary>'.$this->form('file-replace',$asset,true).'<label>Neue Fassung<input type="file" name="replacement" required accept="'.($asset['kind']==='image'?$this->photoAccept():$this->documentAccept()).'"></label><button class="btn btn-solid theme-primary">Neue Fassung speichern</button></form></details>';}
    private function metadataForm(array $asset):string
    {
        $posted=($_POST['operation']??null)==='file-metadata'&&($_POST['id']??null)===$asset['id'];$values=array_replace($asset['metadata'],$asset['source_metadata']??[],$asset['usage']??[]);foreach(['title','description','tags',...MediaDescription::PUBLIC_FIELDS,'rights_state','consent_state','expires_on']as$key)if($posted&&is_string($_POST[$key]??null))$values[$key]=$_POST[$key];
        $tags=is_array($values['tags'])?implode(', ',$values['tags']):$values['tags'];$h='<details class="panel file-metadata"'.($posted?' open':'').'><summary>Angaben bearbeiten</summary>'.$this->form('file-metadata',$asset).Html::input('title','Titel',$values['title'],'text','required maxlength="240"').'<label>Beschreibung<textarea name="description" rows="3" maxlength="2500">'.Html::e($values['description']).'</textarea></label>'.Html::input('tags','Schlagwörter (durch Komma getrennt)',$tags,'text','maxlength="1300"').Html::input('alt','Alternativtext',$values['alt'],'text','maxlength="500"');
        if($asset['kind']==='image'){$mediaVersion=$posted&&is_string($_POST['media_version']??null)&&preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$_POST['media_version'])?$_POST['media_version']:(string)$asset['source_metadata_version'];$h.=Html::hidden('media_version',$mediaVersion);}
        $h.='<details><summary>Quelle und Lizenz</summary>';foreach(['credit'=>'Credit','source_name'=>'Quelle','source_url'=>'Quellenlink','license_name'=>'Lizenz','license_url'=>'Lizenzlink']as$key=>$label)$h.=Html::input($key,$label,$values[$key],str_ends_with($key,'_url')?'url':'text','maxlength="'.(str_ends_with($key,'_url')?'2048':($key==='license_name'?'200':'500')).'"');$h.='</details>';
        if($this->can('website','manage')){$h.='<details><summary>Nutzung</summary>'.Html::hidden('rights_present','1');foreach(['rights_state'=>['Rechte',MediaDescription::RIGHTS],'consent_state'=>['Einwilligung',MediaDescription::CONSENT]]as$key=>[$label,$options]){$h.='<label>'.$label.'<select name="'.$key.'">';foreach($options as$value=>$text)$h.='<option value="'.$value.'"'.($values[$key]===$value?' selected':'').'>'.$text.'</option>';$h.='</select></label>';}$h.=Html::input('expires_on','Verwendbar bis',$values['expires_on'],'date');$channels=$posted&&($_POST['rights_present']??null)==='1'?($_POST['channels']??[]):$values['channels'];foreach(MediaDescription::CHANNELS as$key=>$label)$h.='<label class="file-check"><input type="checkbox" name="channels[]" value="'.$key.'"'.(is_array($channels)&&in_array($key,$channels,true)?' checked':'').'>'.$label.'</label>';$h.='</details>';}
        return $h.'<button class="btn btn-solid theme-primary">Angaben speichern</button></form></details>';
    }
    private function removal(array $asset):string
    {
        $state=(new Removal($this->db,$this->access,$this->root()))->describe($asset['id']);
        if($state['state']==='purged')return '<section class="panel"><h3>Datei gelöscht</h3><p>Die gespeicherten Dateifassungen wurden endgültig gelöscht.</p></section>';
        $h='<details class="panel file-removal"><summary>'.($state['state']==='active'?'Datei entfernen':'Entfernte Datei').'</summary>';
        if($state['state']==='active')return $h.$this->form('file-remove',$asset).Html::hidden('operation_id',bin2hex(random_bytes(16))).'<button class="btn btn-outline theme-secondary">Entfernen</button></form></details>';
        $h.='<p>Aufbewahrt bis '.Html::e($this->time($state['retain_until'])).' · '.Html::e($this->bytes($state['retained_bytes'])).'</p>';
        if($state['state']==='removed')$h.=$this->form('file-removal-restore',$asset).Html::hidden('operation_id',$state['operation']).'<button class="btn btn-solid theme-primary">Wiederherstellen</button></form>';
        if($state['purge_eligible'])$h.=$this->form('file-purge',$asset).Html::hidden('operation_id',$state['operation']).'<label class="file-check"><input type="checkbox" name="confirm_purge" value="1" required>Alle Dateifassungen endgültig löschen</label><button class="btn btn-outline theme-secondary">'.($state['state']==='purging'?'Löschen fortsetzen':'Endgültig löschen').'</button></form>';
        return $h.'</details>';
    }
    private function settings():string
    {
        $quota=(new Quota($this->db,$this->access))->get();$retention=(new Retention($this->db,$this->access))->get();
        return '<details class="panel file-settings"><summary>Speicher und Aufbewahrung</summary><p>'.Html::e($this->bytes($quota['used_bytes'])).($quota['limit_bytes']===0?'':' von '.Html::e($this->bytes($quota['limit_bytes']))).'</p>'.$this->form('file-quota-save').Html::hidden('version',(string)$quota['version']).Html::input('limit_mib','Speichergrenze in MiB (0 = ohne Grenze)',(string)($quota['limit_bytes']/1048576),'number','required min="0" max="102400" step="1"').'<button class="btn btn-outline theme-secondary">Speichergrenze speichern</button></form>'.$this->form('file-retention-save').Html::hidden('version',(string)$retention['version']).Html::input('days','Mindestaufbewahrung in Tagen',(string)$retention['minimum_days'],'number','required min="0" max="3650" step="1"').'<button class="btn btn-outline theme-secondary">Aufbewahrung speichern</button></form></details>';
    }
    private function storage(array $query):string
    {
        $check=$query['file_storage']??'';$after=$query['file_storage_after']??'';if(!is_string($check)||!in_array($check,['','1'],true)||!is_string($after))throw new InvalidArgumentException('Dateispeicher erneut prüfen.');
        $h='<details class="panel file-storage" id="file-storage"'.($check==='1'?' open':'').'><summary>Nicht zugeordnete Uploads</summary><a data-nav class="btn btn-outline theme-secondary" href="'.Html::e($this->url(['file_storage'=>'1'])).'#file-storage">Speicher prüfen</a>';
        if($check!=='1')return $h.'</details>';$list=(new Inventory($this->db,$this->access,$this->root()))->listing($after);if(!$list['complete'])$h.='<p role="status">Einige Dateigruppen benötigen Prüfung.</p>';
        $h.='<ul class="file-history-list">';foreach($list['rows']as$row)$h.='<li><div>'.Html::e($this->bytes($row['bytes'])).' · '.Html::e($this->time($row['changed_at'])).'</div><a data-nav class="btn btn-outline theme-secondary" href="'.Html::e($this->url(['recover_file'=>$row['id']])).'">Wiederherstellen</a></li>';
        if($list['rows']===[])$h.='<li>Keine älteren nicht zugeordneten Uploads.</li>';$h.='</ul><nav class="file-library-pager" aria-label="Dateigruppen">';if($after!=='')$h.='<a data-nav href="'.Html::e($this->url(['file_storage'=>'1'])).'#file-storage">Zum Anfang</a>';if($list['has_more'])$h.='<a data-nav href="'.Html::e($this->url(['file_storage'=>'1','file_storage_after'=>$list['next_after']])).'#file-storage">Weitere Dateigruppen →</a>';return $h.'</nav></details>';
    }
    private function recovery(string $id):string
    {
        $original=(new Inventory($this->db,$this->access,$this->root()))->prepare($id);
        if($original['accepted']??false)return '<section class="panel"><h2>Datei bereits wiederhergestellt</h2><a data-nav class="btn btn-solid theme-primary" href="'.Html::e($this->url(['item'=>$original['asset_id']])).'">'.Html::e($original['title']).'</a></section>';
        $posted=($_POST['operation']??null)==='file-recover'&&($_POST['source_id']??null)===$id;$name=$posted&&is_string($_POST['recovery_name']??null)?$_POST['recovery_name']:'';
        return '<a data-nav class="back-link" href="'.Html::e($this->url(['file_storage'=>'1'])).'#file-storage">← Dateigruppen</a><section class="panel"><h2>Datei wiederherstellen</h2><p>'.Html::e($this->bytes($original['bytes'])).' · '.Html::e($this->time($original['changed_at'])).'</p>'.$this->form('file-recover').Html::hidden('source_id',$id).Html::hidden('fingerprint',$original['fingerprint']).Html::input('recovery_name','Dateiname mit Dateiendung',$name,'text','required maxlength="240"').'<button class="btn btn-solid theme-primary">Wiederherstellen</button></form></section>';
    }
    private function form(string $operation,?array $asset=null,bool $upload=false):string
    {$version=$asset?(string)$asset['version']:'';if($asset&&($_POST['operation']??null)===$operation&&($_POST['id']??null)===$asset['id']&&is_string($_POST['version']??null)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$_POST['version']))$version=$_POST['version'];return '<form method="post"'.($upload?' enctype="multipart/form-data"':'').'>'.Html::hidden('csrf',$this->csrf).Html::hidden('operation',$operation).($asset?Html::hidden('id',$asset['id']).Html::hidden('version',$version):'');}
    private function pager(array $list):string
    {$h='<nav class="file-library-pager" aria-label="Dateiseiten">';$query=['q'=>$list['query'],'kind'=>$list['kind'],'mine'=>$list['mine']?'1':'0','tag'=>$list['tag'],'removed'=>$list['removed']?'1':'0'];if($list['page']>1)$h.='<a data-nav href="'.Html::e($this->url($query+['files_page'=>(string)($list['page']-1)])).'">← Zurück</a>';if($list['page']<$list['pages'])$h.='<a data-nav href="'.Html::e($this->url($query+['files_page'=>(string)($list['page']+1)])).'">Weiter →</a>';return $h.'</nav>';}
    private function tagList(array $tags):string
    {if($tags===[])return '';$h='<ul class="file-tags" aria-label="Schlagwörter">';foreach($tags as$tag)$h.='<li>'.Html::e($tag).'</li>';return $h.'</ul>';}
    private function url(array $query=[]):string{return '/?'.http_build_query(['view'=>'media','section'=>'files']+$query);}
    private function root():string{return dirname($this->mediaRoot).'/files';}
    private function versions():AssetVersions{return new AssetVersions($this->db,$this->access,$this->root(),$this->mediaRoot);}
    private function uploads():Uploads{return new Uploads($this->db,$this->access,$this->root(),$this->mediaRoot);}
    private function scalar(array $input,string $key):string{if(!is_string($input[$key]??null))throw new InvalidArgumentException('Dateiangaben prüfen.');return $input[$key];}
    private function counter(array $input,string $key,bool $zero=false):int{$value=$this->scalar($input,$key);if(!preg_match($zero?'/\A(?:0|[1-9][0-9]{0,9})\z/D':'/\A[1-9][0-9]{0,9}\z/D',$value)||(int)$value>=4294967293)throw new InvalidArgumentException('Gespeicherte Dateiversion prüfen.');return(int)$value;}
    private function tags(string $value):array{if(mb_strlen($value)>1300)throw new InvalidArgumentException('Höchstens 20 kurze Schlagwörter angeben.');return array_values(array_filter(array_map('trim',explode(',',$value)),static fn($tag)=>$tag!==''));}
    private function photoAccept():string{return \Kulturhus\Native\Cms\PhotoInput::accept();}
    private function documentAccept():string{return '.'.implode(',.',Formats::EXTENSIONS);}
    private function accept():string{return $this->documentAccept().','.$this->photoAccept();}
    private function bytes(int $bytes):string{return $bytes>=1048576?number_format($bytes/1048576,1,',','.').' MiB':($bytes>=1024?number_format($bytes/1024,1,',','.').' KiB':$bytes.' B');}
    private function time(string $utc):string{return(new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y, H:i');}
    private function uploaded(string $key):array
    {
        $input=$this->incomingFiles[$key]??null;if(!is_array($input))throw new InvalidArgumentException('Dateien auswählen.');$names=$input['name']??null;$out=[];
        $names=is_array($names)?$names:[$names];foreach($names as$i=>$name){$error=is_array($input['error']??null)?($input['error'][$i]??null):($input['error']??null);$path=is_array($input['tmp_name']??null)?($input['tmp_name'][$i]??null):($input['tmp_name']??null);if($error!==UPLOAD_ERR_OK||!is_string($name)||!is_string($path)||!is_uploaded_file($path))throw new InvalidArgumentException('Vollständige Uploaddateien auswählen.');$out[]=['path'=>$path,'name'=>$name];}return $out;
    }
}
