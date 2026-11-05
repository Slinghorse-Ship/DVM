<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Counted own invoice summaries; original financial documents remain in OutgoingInvoices. */
final class OutgoingInvoiceCatalogue
{
    public function __construct(private PDO $db,private string $actor){}
    /** Whole route controls are retained for correction; invalid controls never select substitute rows. */
    public static function nativeContext(array $input):array
    {
        $controls=[];foreach(['invoices_q'=>'','inv_status'=>'','inv_kind'=>'','invoices_page'=>'1']as$key=>$default){$value=array_key_exists($key,$input)?$input[$key]:$default;$controls[$key]=is_string($value)||($key==='invoices_page'&&is_int($value))?(string)$value:$default;}return $controls;
    }
    public function nativePage(array $input=[]):array
    {
        $controls=self::nativeContext($input);$error='';
        foreach(['invoices_q','inv_status','inv_kind','invoices_page']as$key)if(array_key_exists($key,$input)&&!is_string($input[$key])&&!($key==='invoices_page'&&is_int($input[$key])))$error='Ganze Rechnungssuche, Stand, Art und Seite prüfen.';
        if(preg_match('//u',$controls['invoices_q'])!==1||mb_strlen($controls['invoices_q'],'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$controls['invoices_q'])||!in_array($controls['inv_status'],['','draft','reviewed','issued','cancelled'],true)||!in_array($controls['inv_kind'],['','invoice','cancellation','credit'],true)||!preg_match('/\A[1-9][0-9]{0,5}\z/D',$controls['invoices_page']))$error='Ganze Rechnungssuche, Stand, Art und Seite prüfen.';
        $own=!$this->db->inTransaction();
        try{
            if($own)$this->db->exec('START TRANSACTION');$actor=Permissions::finance($this->db,$this->actor);$result=['controls'=>$controls,'rows'=>[],'total'=>null,'pages'=>null,'page'=>null,'error'=>$error];
            if($error===''){
                $buyer="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.buyer_name')),'')";$description="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.description')),'')";$email="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.buyer_email')),'')";$where=' WHERE club_id=?';$args=[$actor['club_id']];
                foreach(['inv_status'=>'state','inv_kind'=>'kind']as$key=>$column)if($controls[$key]!==''){$where.=' AND '.$column.'=?';$args[]=$controls[$key];}
                $search=trim($controls['invoices_q']);if($search!==''){$where.=" AND LOCATE(LOWER(?),LOWER(CONCAT(number,CHAR(10 USING utf8mb4),$buyer,CHAR(10 USING utf8mb4),$description,CHAR(10 USING utf8mb4),$email)))>0";$args[]=$search;}
                $q=$this->db->prepare('SELECT COUNT(*) FROM outgoing_invoices'.$where.' FOR SHARE NOWAIT');$q->execute($args);$result['total']=(int)$q->fetchColumn();$result['pages']=max(1,(int)ceil($result['total']/6));$page=(int)$controls['invoices_page'];
                if($page>$result['pages'])$result['error']='Verfügbare Rechnungsseite auswählen. Es wurde keine andere Seite geöffnet.';
                else{
                    $result['page']=$page;$q=$this->db->prepare("SELECT club_id,id,kind,state,version,number,entry_id,issued_at,$buyer AS buyer_name,$description AS description,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.document_date')) AS document_date,CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.total_cents')) AS SIGNED) AS total_cents FROM outgoing_invoices".$where.' ORDER BY created_at DESC,id DESC LIMIT 6 OFFSET '.(($page-1)*6).' FOR SHARE NOWAIT');$q->execute($args);$result['rows']=$q->fetchAll(PDO::FETCH_ASSOC);$payments=new \Kulturhus\Verein\Finance\Invoices\OutgoingInvoicePaymentState($this->db,$this->actor);foreach($result['rows']as&$invoiceRow)$invoiceRow['payment']=$payments->forRow($invoiceRow);unset($invoiceRow);
                }
            }
            if($own)$this->db->commit();if(Permissions::finance($this->db,$this->actor)['club_id']!==$actor['club_id'])throw new DomainException('Vereinszugang geändert.',403);return $result;
        }catch(\Throwable$failure){if($own&&$this->db->inTransaction())$this->db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Rechnungssuche wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw$failure;}
    }
    public static function controls(array $input):array
    {
        $q=$input['invoices_q']??'';$status=$input['inv_status']??'';$kind=$input['inv_kind']??'';$page=$input['invoices_page']??'1';
        if(!is_string($q)||!mb_check_encoding($q,'UTF-8')||mb_strlen($q)>150||preg_match('/[\x00-\x1f\x7f]/',$q)||!is_string($status)||!in_array($status,['','draft','reviewed','issued','cancelled'],true)||!is_string($kind)||!in_array($kind,['','invoice','cancellation','credit'],true)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,5}\z/D',(string)$page))throw new DomainException('Rechnungssuche, Stand, Art und Seite prüfen.');
        return ['invoices_q'=>trim($q),'inv_status'=>$status,'inv_kind'=>$kind,'invoices_page'=>(int)$page];
    }
    public function page(array $input=[]):array
    {
        $a=Permissions::finance($this->db,$this->actor);$c=self::controls($input);
        $buyer="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.buyer_name')),'')";
        $description="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.description')),'')";
        $email="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.buyer_email')),'')";
        $where=' WHERE club_id=?';$args=[$a['club_id']];
        foreach(['inv_status'=>'state','inv_kind'=>'kind']as$key=>$column)if($c[$key]!==''){$where.=' AND '.$column.'=?';$args[]=$c[$key];}
        if($c['invoices_q']!==''){$where.=" AND LOCATE(LOWER(?),LOWER(CONCAT(number,CHAR(10 USING utf8mb4),$buyer,CHAR(10 USING utf8mb4),$description,CHAR(10 USING utf8mb4),$email)))>0";$args[]=$c['invoices_q'];}
        $q=$this->db->prepare('SELECT COUNT(*) FROM outgoing_invoices'.$where);$q->execute($args);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min($c['invoices_page'],$pages);
        $q=$this->db->prepare("SELECT id,kind,state,version,number,issued_at,$buyer AS buyer_name,$description AS description,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.document_date')) AS document_date,CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.total_cents')) AS SIGNED) AS total_cents FROM outgoing_invoices".$where.' ORDER BY created_at DESC,id DESC LIMIT 6 OFFSET '.(($page-1)*6));$q->execute($args);
        return ['rows'=>$q->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'pages'=>$pages,'page'=>$page,'controls'=>array_replace($c,['invoices_page'=>$page])];
    }
}
