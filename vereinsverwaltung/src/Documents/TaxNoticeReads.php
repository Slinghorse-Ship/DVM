<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Current financial original metadata; ordinary document reads keep their contract. */
trait TaxNoticeReads
{
    public function taxNotice(string $id,bool $eligible=true):array
    {
        $read=$this->financialOriginalRead($id,0,'Eigenen zugänglichen Steuerbescheid auswählen.');
        if($eligible&&($read['document']['origin']!=='upload'||$read['file']['mime']!=='application/pdf'||$read['document']['state']!=='active'||$read['trashed']||!in_array('finance',$read['document']['scopes'],true)))throw new DomainException('Bitte einen aktiven, hochgeladenen PDF-Steuerbescheid aus dem Bereich Finanzen & Spenden auswählen.',422);
        return$read;
    }
    /** Current scopes and all ancestor permissions also apply to already issued financial originals. */
    public function currentFinancialOriginal(string $id,int $revision=1):array
    {
        if($revision<1)throw new DomainException('Ursprüngliche Finanzdokumentfassung auswählen.',422);
        return$this->financialOriginalRead($id,$revision,'Eigenes zugängliches Finanzdokument auswählen.');
    }
    /** Current latest document for an explicit financial evidence selection. */
    public function currentFinancialDocument(string $id):array
    {
        return$this->financialOriginalRead($id,0,'Eigenes zugängliches Finanzdokument auswählen.');
    }
    private function financialOriginalRead(string $id,int $revision,string $missing):array
    {
        if(!$this->db->inTransaction())return $this->tx(fn():array=>$this->financialOriginalRead($id,$revision,$missing));
        $a=Permissions::finance($this->db,$this->actorId);
        $doc=$this->q('SELECT * FROM documents WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$id])->fetch();
        if(!$doc)throw new DomainException($missing,422);
        $args=[$a['club_id'],$id];if($revision>0)$args[]=$revision;
        $file=$this->q('SELECT name,mime,sha256,revision,length(bytes) size,created_at AS file_created_at,created_by AS file_created_by FROM document_files WHERE club_id=? AND document_id=?'.($revision>0?' AND revision=?':'').' ORDER BY revision DESC LIMIT 1 FOR SHARE NOWAIT',$args)->fetch();
        if(!$file)throw new DomainException($missing,422);
        $doc['scopes']=json_decode($doc['scopes'],true,64,JSON_THROW_ON_ERROR);
        try{$this->allow($doc['scopes'],false,(bool)$doc['admission_only']);}
        catch(DomainException $failure){throw new DomainException($failure->getMessage(),403,$failure);}
        $parts=[];$folders=[];$seen=[];$folder=$doc['folder_id'];$admission=(bool)$doc['admission_only'];
        while($folder!==''){
            if(isset($seen[$folder])||count($seen)>30)throw new DomainException('Ungültige Ordnerstruktur.',422);
            $seen[$folder]=true;
            $row=$this->q('SELECT * FROM document_folders WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$folder])->fetch();
            if(!$row)throw new DomainException('Ordner nicht verfügbar.',422);
            if(count($seen)===1)$admission=$admission||(bool)$row['admission_only'];
            try{$this->allow([$row['scope']],false,$admission||(bool)$row['admission_only']);}
            catch(DomainException $failure){throw new DomainException($failure->getMessage(),403,$failure);}
            array_unshift($parts,$row['name']);array_unshift($folders,$row);$folder=$row['parent_id']??'';
        }
        $trashed=$this->q("SELECT action FROM document_history WHERE club_id=? AND document_id=? AND action IN ('trashed','trash-restored') ORDER BY sequence DESC LIMIT 1 FOR SHARE NOWAIT",[$a['club_id'],$id])->fetchColumn()==='trashed';
        return ['document'=>$doc,'file'=>$file,'path'=>'/'.implode('/',$parts),'folders'=>$folders,'trashed'=>$trashed];
    }
}
