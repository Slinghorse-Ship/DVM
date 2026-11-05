<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use PDO;
use Kulturhus\Native\Cms\{Pages,Media,WebsiteDesign,HomeLayout};
use Kulturhus\Native\Shared\{Access,Identity};
use Kulturhus\Native\Web\{Html,PublicLayout,Articles};

/** Read-only, uncached preview; no seeded pages, imports or public configuration writes. */
final class DesignPreview
{
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot,private string $managerUrl='/?view=settings'){}
    public function render(mixed $key):string
    {
        $this->access->require('website','read');$design=WebsiteDesign::template($key);
        $pages=new Pages($this->db,$this->access);$media=new Media($this->db,$this->access,$this->mediaRoot);
        $identity=Identity::forClub($this->db,$this->access->club());$identity['website_design']=$key;
        $page=$pages->publicPage('start');$published=$page!==null;
        if(!$page)$page=['title'=>'Gemeinsam etwas bewegen.','excerpt'=>'Ein Ort für Begegnungen, Ideen und ein aktives Vereinsleben.','body'=>'','home_layout'=>array_replace(HomeLayout::defaults(),['poster_top'=>'Dein Verein','poster_main'=>'Dabei sein','poster_sub'=>'Gemeinsam aktiv','highlights'=>['Begegnung','Engagement','Gemeinschaft']])];
        $body=Html::content($page).(new ContentSectionsView($media))->render($page).(new PublishedFields($pages,$media))->render($page);
        $main='<aside class="design-preview-note" role="status"><strong>Private Designvorschau · '.Html::e($design['name']).'</strong><p>Diese Ansicht ändert deine Website nicht. '.($published?'Sie zeigt die veröffentlichte Startseite.':'Sie zeigt Beispieltexte, solange noch keine Startseite veröffentlicht ist.').'</p><a href="'.Html::e($this->managerUrl).'">Zur Vorlagenauswahl</a></aside>'.(new HomePage($pages,$media,new Articles($this->db,$this->access,$this->mediaRoot),$identity))->render($page,$body);
        return PublicLayout::render('Designvorschau · '.$design['name'],'Private Website-Vorschau',$main,$pages->navigation(),'start',$identity['origin'],$this->managerUrl,$identity,true,$pages->footerNavigation());
    }
}
