<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use PDO;
use Kulturhus\Native\Cms\{Pages,Media};
use Kulturhus\Native\Shared\{Access,Identity};
use Kulturhus\Native\Web\{Html,PublicLayout};

final class ContentSectionsPreview
{
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot,private string $managerUrl='/?view=website',private ?\Closure $mediaUrl=null){}
    public function render(string $id):string
    {
        $this->access->require('website','read');if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new \DomainException('Seite nicht verfügbar.');
        $pages=new Pages($this->db,$this->access);try{$p=$pages->get($id);}catch(\RuntimeException $error){throw new \DomainException('Seite nicht verfügbar.',0,$error);}
        if($p['trashed']||$p['archived']||($p['template_definition']['renderer']??$p['template'])!=='standard')throw new \DomainException('Inhaltsseite nicht in der Bearbeitung verfügbar.');
        $identity=Identity::forClub($this->db,$this->access->club());$identity['website_design']=\Kulturhus\Native\Cms\WebsiteDesign::forClub($this->db,$this->access->club())['template'];
        $identity['website_design']=\Kulturhus\Native\Cms\WebsiteDesign::forClub($this->db,$this->access->club())['template'];$content=(new ContentSectionsView(new Media($this->db,$this->access,$this->mediaRoot)))->render($p,true,$this->mediaUrl);
        $main='<main id="main" class="section"><p role="status">Private Abschnittsvorschau · gespeicherte Version '.Html::e($p['version']).'</p><h1>'.Html::e($p['title']).'</h1><div class="prose kh-richtext">'.Html::content($p).'</div>'.$content.'</main>';
        return PublicLayout::render($p['title'],$p['excerpt'],$main,$pages->navigation(),$p['slug'],$identity['origin'],$this->managerUrl,$identity,true,$pages->footerNavigation());
    }
}
