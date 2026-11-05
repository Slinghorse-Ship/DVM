<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use DomainException;
use Kulturhus\Native\Shared\Access;

/** Internal reader for one server-loaded edition's exact image references. */
final class EditionImageAccess extends Access
{
    public function __construct(PDO $db,private Access $source,private array $original,private string $media)
    {
        parent::__construct($db,$source->user()['id']);Content::id($media);
        $refs=array_unique([...ImageUsage::refs($original),...array_keys($original['_media_usage']['media']??[])]);
        foreach($original['story']??[]as$slide)if(($slide['media_ref']??'')!=='')$refs[]=$slide['media_ref'];
        if(!in_array($media,$refs,true))throw new DomainException('Bild gehört nicht zu dieser Originalfassung.');
        $this->user();
    }
    public function user(): array
    {
        $user=$this->source->require($this->original['module'],'read');if($user['club_id']!==$this->original['club_id'])throw new DomainException('Verein der Originalfassung nicht verfügbar.');return$user;
    }
    public function require(string $module,string $capability): array
    {
        if($module!=='website'||$capability!=='read')throw new DomainException('Nur das ausgewählte Originalbild lesen.');return$this->user();
    }
}
