<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;

use PDO;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Communication\{PressPhotoAttachments,SubscriberSelection};

/** Only six original filenames; each download is bound to the exact saved press revision. */
final class PressPhotoLinks
{
    public static function render(PDO $db,Access $access,array $item):string
    {
        if(($item['module']??'')!=='press'||!($item['attachment_media_ids']??[]))return'';
        $club=$access->require('press','read')['club_id'];if(($item['club_id']??'')!==$club)throw new \DomainException('Eigene Pressefassung öffnen.');
        $ids=PressPhotoAttachments::ids($item['attachment_media_ids']);$pages=max(1,(int)ceil(count($ids)/6));$page=min($pages,SubscriberSelection::number($_GET['photo_page']??1));$picked=array_slice($ids,($page-1)*6,6);
        $names=[];foreach(Db::run($db,'SELECT id,original_name,mime FROM khcms_media WHERE club_id=? AND id IN ('.implode(',',array_fill(0,count($picked),'?')).')',[$club,...$picked])->fetchAll()as$row)$names[$row['id']]=PressPhotoAttachments::filename(['name'=>$row['original_name'],'mime'=>$row['mime']]);
        $h='<details class="press-photo-originals" id="press-photo-originals"><summary>Fotoanlagen <span class="badge">'.count($ids).'</span></summary><div class="press-photo-original-list">';
        foreach($picked as$id)$h.='<a class="btn btn-outline theme-secondary" href="/edition-image?'.Html::e(http_build_query(['module'=>'press','item'=>$item['id'],'revision'=>$item['version'],'media'=>$id,'attachment'=>'1'])).'">'.Html::e($names[$id]??'Fotoanlage').'</a>';
        $h.='</div>';if($pages>1){$uri=parse_url($_SERVER['REQUEST_URI']??'/?view=press',PHP_URL_PATH);parse_str($_SERVER['QUERY_STRING']??'', $query);$h.='<nav aria-label="Fotoanlagenseiten">';foreach(['Zurück'=>-1,'Weiter'=>1]as$label=>$step)if($page+$step>=1&&$page+$step<=$pages){$query['photo_page']=$page+$step;$h.='<a class="btn btn-outline theme-secondary" href="'.Html::e($uri.'?'.http_build_query($query).'#press-photo-originals').'">'.$label.'</a>';}$h.='<span>Seite '.$page.' von '.$pages.'</span></nav>';}
        return$h.'</details>';
    }
}
