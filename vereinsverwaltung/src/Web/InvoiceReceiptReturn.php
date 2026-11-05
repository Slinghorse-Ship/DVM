<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use PDO;
use Kulturhus\Verein\Permissions;

/** Navigation only: the original invoice must actually belong to this own receipt. */
final class InvoiceReceiptReturn
{
    private const CONTROLS=['invoices_q'=>'','inv_status'=>'','inv_kind'=>'','invoices_page'=>'1'];
    private const ORIGIN='from_invoice';
    private const INVALID='invoice_return_invalid';

    public static function fields(array $source):array
    {
        $keys=[self::ORIGIN=>'',...self::CONTROLS,self::INVALID=>''];
        if(array_intersect_key($source,$keys)===[])return [];
        $out=[];$invalid=array_key_exists(self::INVALID,$source);
        foreach([self::ORIGIN=>'',...self::CONTROLS]as$key=>$unused){
            if(!is_string($source[$key]??null)||!mb_check_encoding($source[$key],'UTF-8'))$invalid=true;
            else $out[$key]=$source[$key];
        }
        if($invalid)$out[self::INVALID]='1';
        return $out;
    }

    public static function forInvoice(string $id,array $source):array
    {
        return self::fields([self::ORIGIN=>$id]+array_intersect_key($source,self::CONTROLS+[self::INVALID=>''])+self::CONTROLS);
    }

    public static function without(array $source):array
    {
        return array_diff_key($source,[self::ORIGIN=>'',...self::CONTROLS,self::INVALID=>'']);
    }

    public function __construct(private PDO $db,private string $actor){}

    /** A return destination is not evidence that this bank movement paid the invoice. */
    public function resolveBank(string $transaction,array $source):array
    {
        $fields=self::fields($source);$result=['state'=>'none','fields'=>$fields,'back'=>null,'invoice'=>null,'reset'=>null];
        if($fields===[])return $result;
        $own=!$this->db->inTransaction();
        try{
            if($own)$this->db->beginTransaction();$actor=Permissions::finance($this->db,$this->actor);
            $q=$this->db->prepare('SELECT id FROM finance_transactions WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$actor['club_id'],$transaction]);
            if(!$q->fetchColumn())throw new DomainException('Kontoumsatz nicht verfügbar.',404);
            $result['state']='invalid';$id=$fields[self::ORIGIN]??'';
            if(preg_match('/\A[a-f0-9]{32}\z/D',$id)){
                $q=$this->db->prepare('SELECT id,entry_id FROM outgoing_invoices WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$actor['club_id'],$id]);$invoice=$q->fetch(PDO::FETCH_ASSOC);
                if($invoice&&is_string($invoice['entry_id'])&&preg_match('/\A[a-f0-9]{32}\z/D',$invoice['entry_id'])){
                    $q=$this->db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$actor['club_id'],$invoice['entry_id']]);
                    if($q->fetchColumn()){
                        $result['reset']=['view'=>'finance','tab'=>'outgoing','invoice'=>$invoice['id']];
                        if(!isset($fields[self::INVALID])){
                            $result['state']='valid';$result['invoice']=$result['reset']+array_intersect_key($fields,self::CONTROLS);
                            $result['back']=['view'=>'finance','tab'=>'receipts','entry'=>$invoice['entry_id']]+$fields;
                        }
                    }
                }
            }
            if($own)$this->db->commit();
            if(Permissions::finance($this->db,$this->actor)['club_id']!==$actor['club_id'])throw new DomainException('Vereinszugang geändert.',403);
            return $result;
        }catch(\Throwable $failure){
            if($own&&$this->db->inTransaction())$this->db->rollBack();
            if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Rechnungsbezug wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
            throw $failure;
        }
    }

    /** Keeps a borrowed transaction; after our own commit, authority is checked again. */
    public function resolve(string $receipt,array $source):array
    {
        $fields=self::fields($source);$result=['state'=>'none','fields'=>$fields,'back'=>null,'reset'=>null];
        if($fields===[])return $result;
        $own=!$this->db->inTransaction();
        try{
            if($own)$this->db->beginTransaction();$actor=Permissions::finance($this->db,$this->actor);
            $q=$this->db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$actor['club_id'],$receipt]);
            if(!$q->fetchColumn())throw new DomainException('Beleg nicht verfügbar.',404);
            $result['state']='invalid';$id=$fields[self::ORIGIN]??'';
            if(preg_match('/\A[a-f0-9]{32}\z/D',$id)){
                $q=$this->db->prepare('SELECT id,entry_id FROM outgoing_invoices WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$actor['club_id'],$id]);$invoice=$q->fetch(PDO::FETCH_ASSOC);
                if($invoice&&$invoice['entry_id']===$receipt){
                    $result['reset']=['view'=>'finance','tab'=>'outgoing','invoice'=>$id];
                    if(!isset($fields[self::INVALID])){$result['state']='valid';$result['back']=$result['reset']+array_intersect_key($fields,self::CONTROLS);}
                }
            }
            if($own)$this->db->commit();
            if(Permissions::finance($this->db,$this->actor)['club_id']!==$actor['club_id'])throw new DomainException('Vereinszugang geändert.',403);
            return $result;
        }catch(\Throwable $failure){
            if($own&&$this->db->inTransaction())$this->db->rollBack();
            if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Rechnungsbezug wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
            throw $failure;
        }
    }
}
