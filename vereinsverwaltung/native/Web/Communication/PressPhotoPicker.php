<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;

use PDO;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Communication\PressPhotoAttachments;

/** Existing complete photo chooser, with a separate press selection and common upload handling. */
final class PressPhotoPicker
{
    public static function render(PDO $db,Access $access,array $item):string
    {
        $access->require('press','read');$ids=PressPhotoAttachments::ids($item['attachment_media_ids']??[]);$canRead=false;$upload=false;
        try{$access->require('website','read');$canRead=true;try{$access->require('website','edit');$upload=true;}catch(\DomainException){}}catch(\DomainException){}
        $canUpload=false;try{$access->require('press','edit');$canUpload=true;}catch(\DomainException){}
        $upload=$upload&&$canUpload;
        $h='<details class="press-photo-attachments"><summary>Fotoanlagen <span class="badge" data-attachment-count>'.count($ids).' ausgewählt</span></summary>'.Html::hidden('attachment_picker_present','1');
        if($canRead){
            $picker=(new \Kulturhus\Native\Web\Website\PageMediaPicker($db,$access))->render($ids,$upload,[],'press');
            $h.=$picker;
        }else{
            foreach($ids as$id){$name=Db::run($db,'SELECT original_name FROM khcms_media WHERE club_id=? AND id=?',[$access->club(),$id])->fetchColumn();$h.='<label class="check"><input class="check" type="checkbox" name="attachment_media_ids[]" value="'.$id.'" checked> '.Html::e(is_string($name)?$name:'Fotoanlage').'</label>';}
        }
        if($canUpload&&!$upload)$h.='<label for="native-press-module-photos">Neue Fotoanlagen<input id="native-press-module-photos" class="form-control" type="file" name="press_photos[]" accept="'.\Kulturhus\Native\Cms\PhotoInput::accept().'" multiple></label>';
        return $h.'<p>Bis zu zehn Fotoanlagen pro E-Mail.</p></details>';
    }
}
