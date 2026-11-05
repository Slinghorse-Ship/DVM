<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use PDO;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access,Db};

final class PageSections
{
    use ClubPublicationLock;
    public function __construct(private PDO $db,private Access $access){}
    public function save(string $id,int $version,array $sections):array
    {
        $this->access->require('website','edit');
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id)||$version<1||$version>4294967294)throw new InvalidArgumentException('Seitenkennung und gespeicherte Version prüfen.');
        $sections=ContentSections::normalize($sections);
        return Db::atomic($this->db,function()use($id,$version,$sections):array{
            $this->access->require('website','edit');$this->lockClub();$pages=new Pages($this->db,$this->access);$page=$pages->get($id);
            if($page['version']!==$version)throw new Conflict('Seite inzwischen geändert. Aktuellen Stand vor der Abschnittsbearbeitung neu laden.');
            if($page['archived']||$page['trashed'])throw new Conflict('Seite zuerst privat zurück in die Bearbeitung holen.');
            if(($page['template_definition']['renderer']??$page['template'])!=='standard')throw new InvalidArgumentException('Inhaltsabschnitte sind für Inhaltsseiten vorgesehen.');
            $payload=(new DataModel($this->db,$this->access))->publication($page);
            if(($payload['content_sections']??[])===$sections)return $page;
            $payload['content_sections']=$sections;$payload['id']=$id;
            return $pages->save($payload,$version);
        });
    }
}
