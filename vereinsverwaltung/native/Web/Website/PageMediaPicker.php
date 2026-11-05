<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;

use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Web\Html;
use PDO;

/** Complete native multiple select; only labels, never image files or variant JSON. */
final class PageMediaPicker
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    public function __construct(private PDO $db, private Access $access) {}

    /** Keep an owned, valid unsaved selection on a failed HTML save; never write it. */
    public function rejectedSelection(array $page,array $post,string $csrf): array
    {
        return $this->rejected($page,$post,$csrf,'page-save','media_ids');
    }

    public function rejectedAlbumSelection(array $album,array $post,string $csrf): array
    {
        $empty=array_replace($album,['media_ids'=>[]]);
        return array_values(array_diff($this->rejected($empty,$post,$csrf,'album-attach','images')['media_ids'],$album['media_ids']));
    }

    private function rejected(array $page,array $post,string $csrf,string $operation,string $field): array
    {
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST' || http_response_code()<400 || ($post['operation']??null)!==$operation || !is_string($post['csrf']??null) || !hash_equals($csrf,$post['csrf']) || ($post['id']??'')!==$page['id']) return $page;
        $ids=$post[$field]??[];
        if(!is_array($ids)||count($ids)>100)return $page;
        foreach($ids as$id)if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))return $page;
        $ids=array_values(array_unique($ids));
        return Db::atomic($this->db,function()use($page,$ids):array {
            $club=$this->access->require('website','edit')['club_id'];
            if($ids&&(int)Db::run($this->db,'SELECT COUNT(*) FROM khcms_media WHERE club_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')',[$club,...$ids])->fetchColumn()!==count($ids))return $page;
            $page['media_ids']=$ids;return $page;
        });
    }

    public function render(array $selected,bool $uploads=true,array $excluded=[],string $purpose='page'): string
    {
        if(!in_array($purpose,['page','press'],true))throw new \InvalidArgumentException('Bildauswahlbereich prüfen.');
        if(count($excluded)>100)throw new \InvalidArgumentException('Maximal 100 vorhandene Bilder.');
        foreach($excluded as$id)if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new \InvalidArgumentException('Vorhandene Bildauswahl prüfen.');
        $this->access->require('website','read');
        $options=Db::atomic($this->db,function():array {
            $club=$this->access->require('website','read')['club_id'];
            $rows=Db::run($this->db,'SELECT m.id,m.alt,m.original_name FROM khcms_media m WHERE m.club_id=? AND '.\Kulturhus\Native\Cms\MediaAvailability::activeSql($this->db).' ORDER BY LOWER(m.alt) COLLATE utf8mb4_bin,LOWER(m.original_name) COLLATE utf8mb4_bin,m.id',[$club]);
            $labels=[];
            while($row=$rows->fetch(PDO::FETCH_ASSOC))$labels[$row['id']]=$row['alt'].($row['original_name']!==''?' · '.$row['original_name']:'');
            return $labels;
        });
        if(count($selected)>100)throw new \InvalidArgumentException('Maximal 100 Bilder auswählen.');
        foreach($selected as$id)if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new \InvalidArgumentException('Bildauswahl prüfen.');
        $selected=array_values(array_unique($selected));
        $inputId=$purpose==='press'?'native-press-photo-selection':($uploads?'native-page-media':'native-album-media');
        $field=$purpose==='press'?'attachment_media_ids[]':($uploads?'media_ids[]':'images[]');
        $uploadId=$purpose==='press'?'native-press-photo-upload':'native-page-photos';
        $uploadField=$purpose==='press'?'press_photos[]':'page_photos[]';
        $selectionLabel=$purpose==='press'?'Fotoanlagen auswählen':'Bilder auswählen';
        $operation=$purpose==='press'?'data-page-photo-operation="communication-photo-upload" ':'';
        $html='<div class="page-media-picker" data-page-media-picker '.$operation.'data-page-photo-status="/page-photo-status" data-page-media-preview="/media-preview/00000000000000000000000000000000/thumb"><label for="'.$inputId.'">'.Html::e($this->contentText($selectionLabel)).'</label><select class="form-control" id="'.$inputId.'" name="'.$field.'" multiple size="6" data-page-media-select>';
        // Selected options lead in their original order: an ordinary save cannot reorder images.
        foreach($selected as$id)$html.='<option value="'.Html::e($id).'" selected'.(!isset($options[$id])?' data-unavailable':'').'>'.Html::e($options[$id]??$this->contentText('Bild nicht mehr verfügbar')).'</option>';
        foreach($options as$id=>$label)if(!in_array($id,$selected,true)&&!in_array($id,$excluded,true))$html.='<option value="'.Html::e($id).'">'.Html::e($label).'</option>';
        $html.='</select>';
        if($uploads)$html.='<div class="page-media-upload"><label for="'.$uploadId.('">'.Html::e($this->contentText('Fotos hinzufügen')).'</label><input id="').$uploadId.'" class="form-control" type="file" name="'.$uploadField.'" accept="'.\Kulturhus\Native\Cms\PhotoInput::accept().('" multiple data-page-media-upload><button type="button" class="btn btn-outline theme-secondary" data-page-media-upload-cancel hidden>'.Html::e($this->contentText('Abbrechen')).'</button><button type="button" class="btn btn-outline theme-secondary" data-page-photo-check hidden>'.Html::e($this->contentText('Uploads prüfen')).'</button><button type="button" class="btn btn-outline theme-secondary" data-page-photo-forget hidden>'.Html::e($this->contentText('Prüfung beenden')).'</button></div>');
        return $html.'<div data-page-media-controls hidden></div><p class="page-media-status" role="status" aria-live="polite" data-page-media-status>'.Html::e($this->contentFormat('{count} Bilder ausgewählt',['count'=>count($selected)])).'</p></div>';
    }
}
