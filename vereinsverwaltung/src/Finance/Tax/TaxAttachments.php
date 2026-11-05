<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;

/** Exact document/report revisions, never the assembly report or an unapproved draft. */
final class TaxAttachments
{
    public function __construct(private PDO $db,private string $actor){}
    public function choices(int $year):array
    {
        $rows=(new TaxPreparation($this->db,$this->actor))->attachments();
        foreach($rows as &$r)$r['selection']=$r['id'];unset($r);
        $reviews=new ActivityReportReview($this->db,$this->actor);
        foreach($reviews->listing() as $report){
            if((int)$report['to_year']!==$year||$report['state']!=='approved')continue;
            $full=$reviews->get($report['id']);if(!$reviews->approved($full))continue;
            $rows[]=['id'=>$report['id'],'selection'=>'report:'.$report['id'],'title'=>'Tätigkeitsbericht Finanzamt '.$report['from_year'].'–'.$report['to_year']];
        }
        return $rows;
    }
    public function select(array $ids,int $year):array
    {
        if(count($ids)>20||count(array_unique($ids,SORT_REGULAR))!==count($ids))throw new DomainException('Höchstens 20 unterschiedliche PDF-Anlagen auswählen.');
        $result=[];$total=0;
        foreach($ids as $id){
            if(!is_string($id))throw new DomainException('Anlage ungültig.');
            $report=str_starts_with($id,'report:');$sourceId=$report?substr($id,7):$id;
            if($report){
                $reviews=new ActivityReportReview($this->db,$this->actor);$r=$reviews->get($sourceId);
                if((int)$r['to_year']!==$year)throw new DomainException('Berichtsjahr und Erklärung passen nicht zusammen.');
                $file=$reviews->file($sourceId);$revision=(int)$r['version'];$title='Tätigkeitsbericht Finanzamt '.$r['from_year'].'–'.$r['to_year'];
            }else{
                $docs=new Documents($this->db,$this->actor);$r=$docs->document($id);$file=$docs->file($id);
                if($r['scopes']!==['finance']||$file['mime']!=='application/pdf')throw new DomainException('Nur Finanz-PDFs können beigefügt werden.');
                $revision=(int)$file['revision'];$title=$r['title'];
            }
            if(!str_starts_with($file['bytes'],'%PDF-'))throw new DomainException('Anlage ist kein PDF.');
            $total+=strlen($file['bytes']);if($total>2097152)throw new DomainException('Anhänge zusammen höchstens 2 MB.');
            $result[]=['id'=>$sourceId,'kind'=>$report?'report':'document','revision'=>$revision,'sha256'=>hash('sha256',$file['bytes']),'title'=>$title,'name'=>'Anlage-'.(count($result)+1).'.pdf'];
        }
        return $result;
    }
    public function fromSnapshot(array $snapshot,int $year):array
    {
        $sources=$snapshot['sources']??[];$ids=array_column($sources,'document_id');
        $review=$snapshot['activity_review']??[];
        if(($review['approved']??false)===true&&($review['kind']??null)==='tax')$ids[]='report:'.$review['id'];
        $selected=$this->select($ids,$year);
        foreach($selected as $item){
            if($item['kind']==='report'){
                if($item['revision']!==(int)$review['version'])throw new DomainException('Tätigkeitsbericht geändert. Steuerunterlagen erneut prüfen.');
            }else{
                $original=array_values(array_filter($sources,static fn($source)=>$source['document_id']===$item['id']));
                if(count($original)!==1||(int)$original[0]['revision']!==$item['revision']||!hash_equals($original[0]['sha256'],$item['sha256']))throw new DomainException('Ausgewählter Nachweis geändert. Steuerunterlagen erneut prüfen.');
            }
        }
        return $selected;
    }
    public function files(array $selections):array
    {
        $out=[];$total=0;
        if(count($selections)>20)throw new DomainException('Zu viele Anlagen.');
        foreach($selections as $selection){
            if(($selection['kind']??'document')==='report'){
                $reviews=new ActivityReportReview($this->db,$this->actor);$r=$reviews->get($selection['id']);
                if((int)$r['version']!==$selection['revision'])throw new DomainException('Tätigkeitsbericht wurde geändert. Anlage erneut auswählen und prüfen.');
                $file=$reviews->file($selection['id']);
            }else{
                $docs=new Documents($this->db,$this->actor);$doc=$docs->document($selection['id']);
                if($doc['scopes']!==['finance'])throw new DomainException('Anlage ist nicht mehr für Finanzen verfügbar.');
                $file=$docs->file($selection['id'],$selection['revision']);
            }
            $total+=strlen($file['bytes']);
            if($total>2097152||!hash_equals($selection['sha256'],hash('sha256',$file['bytes'])))throw new DomainException('Eine Anlage wurde verändert. Bitte neu auswählen.');
            $out[]=['name'=>$selection['name'],'bytes'=>$file['bytes']];
        }
        return $out;
    }
}
