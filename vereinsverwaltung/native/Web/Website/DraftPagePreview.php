<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Cms\{Media,Pages};
use Kulturhus\Native\Integration\Routes;
use Kulturhus\Native\Web\{Articles,Events,Html,MediaCredit,PublicLayout};

/** Real saved draft inside the website layout; no public lookup of the edited original and no cache. */
final class DraftPagePreview
{
    public function __construct(private Pages $pages,private Media $media,private array $identity,private string $managerUrl,private ?Articles $articles=null){}
    public function render(array $page,callable $privateMediaUrl):string
    {
        $url=\Closure::fromCallable($privateMediaUrl);$e=Html::e(...);
        $fileSources=array_column($page['custom_file_metadata']??[],'source','id');
        $fileUrl=static fn(string $id):string=>($fileSources[$id]??null)==='asset'?Routes::endpoint('file-preview',['revision'=>$id]):Routes::endpoint('website-document-preview',['document'=>$id]);
        $custom=(new PublishedFields($this->pages,$this->media,$url,$fileUrl))->render($page);
        $body='<div class="prose kh-richtext">'.Html::content($page).'</div>';
        $documents='';foreach($page['document_links']??[] as $link)$documents.='<li><a download href="'.$e($fileUrl($link['id'])).'">'.$e($link['label']).'</a></li>';
        if($documents!=='')$body.='<div class="website-documents"><ul>'.$documents.'</ul></div>';
        $body.=(new ContentSectionsView($this->media))->render($page,true,$url);
        $renderer=$page['template_definition']['renderer']??$page['template'];
        if($renderer==='event'&&$page['slug']!=='veranstaltungen'){
            $main=(new Events($this->pages->events(),$this->media,$this->identity['origin'],$this->identity,null,$url))->detail($page,$custom);
        }elseif($page['slug']==='start'){
            $main=(new HomePage($this->pages,$this->media,$this->articles,$this->identity))->render($page,$body.$custom);
        }else{
            $gallery='';$metadata=array_column($page['gallery_items']??[],null,'id');
            foreach($page['media_ids']??[] as $id){
                $image=$this->media->variant($id,'medium');$caption=$metadata[$id]['caption']??$image['alt'];
                $gallery.='<figure><img loading="lazy" src="'.$e($url($id,'medium')).'" width="'.$e($image['width']).'" height="'.$e($image['height']).'" alt="'.$e($image['alt']).'"><figcaption>'.$e($caption).MediaCredit::render($image).'</figcaption></figure>';
            }
            $variant=$renderer==='news'?'stories':($renderer==='gallery'?'gallery':'basic');
            $eyebrow=$renderer==='news'?(isset($page['news_date'])?'<time datetime="'.$e($page['news_date']).'">'.$e($page['news_date']).'</time>':'Aktuelles'):($renderer==='gallery'?'Verein in Bildern':'Unser Verein');
            $main='<main id="main" class="subpage'.($renderer==='gallery'?' gallery-page':'').'"><header class="page-hero page-hero--'.$variant.'"><p class="eyebrow">'.$eyebrow.'</p><h1>'.$e($page['title']).'</h1><p class="lead">'.$e($page['excerpt']).'</p></header><section class="section site-content">'.$body.$custom.'<div class="gallery">'.$gallery.'</div></section></main>';
        }
        $banner='<aside class="container py-2" role="status"><strong>Private Websitevorschau · Nicht veröffentlicht</strong></aside>';
        return PublicLayout::render($page['seo_title']?:$page['title'],$page['seo_description']?:$page['excerpt'],$banner.$main,$this->pages->navigation(),$page['slug'],$this->identity['origin'],$this->managerUrl,$this->identity,true,$this->pages->footerNavigation());
    }
}
