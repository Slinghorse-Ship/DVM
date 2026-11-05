<?php declare(strict_types=1);
namespace Kulturhus\Verein\Events\Calendar;
use PDO;
use Kulturhus\Verein\ManagerDocumentWorkflows;
use Kulturhus\Verein\DocumentWorkflow\InspectionStore;

final class InspectionSource
{
    public function __construct(private PDO $db,private string $actor) {}
    public function available(): bool {return InspectionStore::installed($this->db)&&(new ManagerDocumentWorkflows($this->db,$this->actor))->inspections->allows();}
    public function rows(string $from,string $to): array
    {
        if(!$this->available())return [];$rows=[];
        foreach((new ManagerDocumentWorkflows($this->db,$this->actor))->inspections->deadlines($from,$to)as$r)$rows[]=Entry::dated('inspection:'.$r['id'], $r['title'],$r['deadline'],'inspections',['view'=>'documents','tab'=>'inspections','inspection'=>$r['id']],true,InspectionStore::STATUSES[$r['status']]);
        return $rows;
    }
}
