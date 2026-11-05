<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Cms\Media;
use Kulturhus\Native\Web\Html;

final class ContentSectionsView
{
    public function __construct(private Media $media){}
    public function render(array $page,bool $private=false,?callable $mediaUrl=null):string
    {
        $h='';foreach($page['content_sections']??[]as $index=>$row){
            $e=Html::e(...);$titleId='section-'.$row['id'];$image='';
            if($row['media_id']!==null){$m=$private?$this->media->variant($row['media_id'],'medium'):$this->media->publicVariant($row['media_id'],'medium',$page);if($m){$url=$mediaUrl?$mediaUrl($row['media_id']):($private?'/media-preview/':'/media/').$row['media_id'].'/medium';$image='<figure><img loading="lazy" decoding="async" src="'.$e($url).'" width="'.$m['width'].'" height="'.$m['height'].'" alt="'.$e($row['alt']).'"'.ContentSectionCrop::style($row).'>'.($row['caption']!==''||$row['credit']!==''||($m['credit']??'')!==''||($m['source_name']??'')!==''||($m['source_url']??'')!==''||($m['license_name']??'')!==''||($m['license_url']??'')!==''?'<figcaption>'.$e($row['caption']).($row['credit']!==''?' <span class="photo-credit">Foto: '.$e($row['credit']).'</span>':'').\Kulturhus\Native\Web\MediaCredit::render($m).'</figcaption>':'').'</figure>';}}
            $text='<div class="website-section-text">'.($row['heading']!==''?'<h2 id="'.$titleId.'">'.$e($row['heading']).'</h2>':'').'<div class="prose kh-richtext">'.\Kulturhus\Native\Shared\RichText::sanitize($row['body_html']).'</div>'.($row['button_url']!==''?'<p><a class="btn btn-solid theme-primary button" href="'.$e($row['button_url']).'">'.$e($row['button_label']).'</a></p>':'').'</div>';
            $h.='<section class="website-content-section website-content-section--'.$row['layout'].'" '.($row['heading']!==''?'aria-labelledby="'.$titleId.'"':'aria-label="Inhaltsabschnitt '.($index+1).'"').'>'.($row['layout']==='image-before'?$image.$text:$text.$image).'</section>';
        }
        return $h!==''?'<div class="website-content-sections">'.$h.'</div>':'';
    }
}
