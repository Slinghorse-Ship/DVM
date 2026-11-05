<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use PDO;use DomainException;use Throwable;
use Kulturhus\Verein\{Documents,Finance,InvoiceSupplierContacts};
/** Read a pending invoice again; only the existing native create command can save it. */
final class InvoiceDraftRead
{
    public static function requested(array $post):bool{return array_key_exists('invoice_read',$post);}
    public static function failedSaveRequested(array $query,array $post,string $method,string $error,mixed $csrf):bool
    {
        return $method==='POST'&&$error!==''&&($query['view']??null)==='finance'&&($query['tab']??null)==='inbox'
            &&($post['action']??null)==='create-entry'&&($post['inbox_guided']??null)==='1'
            &&is_string($csrf)&&$csrf!==''&&is_string($post['csrf']??null)&&hash_equals($csrf,$post['csrf']);
    }
    public static function previousOutcome(PDO $db,string $actor,array $query,array $post):?array
    {
        $key=$post['request_key']??null;
        if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!(new Finance($db,$actor))->canRead())return null;
        if(ManualFinanceForm::existing($db,$actor,$key)===null)return null;
        return self::inspect($db,$actor,$query,$post);
    }
    public static function inspect(PDO $db,string $actor,array $query,array $post):array{return self::prepare($db,$actor,$query,$post,false);}
    public static function handle(PDO $db,string $actor,array $query,array $post):array{return self::prepare($db,$actor,$query,$post,true);}
    private static function tree(mixed $value,int $depth=0):bool
    {
        if(is_string($value))return mb_check_encoding($value,'UTF-8')&&!str_contains($value,"\0");
        if(!is_array($value)||$depth>8||count($value)>100)return false;
        foreach($value as$key=>$child)if(!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',(string)$key)||!self::tree($child,$depth+1))return false;
        return true;
    }
    /** Do not turn unrepresentable original bytes into a new HTML confirmation. */
    public static function canRepeat(array $post):bool{return self::tree($post);}
    /** Preserve the original PHP form structure, including repeated scalar list fields. */
    public static function controls(array $post,array $exclude=[]):array
    {
        $out=[];$walk=static function(string$name,mixed$value)use(&$walk,&$out):void{
            if(is_string($value)){$out[]=[$name,$value];return;}
            if(!is_array($value))return;
            $list=array_is_list($value)&&array_reduce($value,static fn($ok,$item)=>$ok&&is_string($item),true);
            foreach($value as$key=>$child)$walk($name.($list?'[]':'['.$key.']'),$child);
        };
        foreach($post as$key=>$value)if(!in_array($key,['csrf','invoice_read',...$exclude],true))$walk((string)$key,$value);
        return$out;
    }
    private static function prepare(PDO $db,string $actor,array $query,array $post,bool $move):array
    {
        $raw=$post;unset($raw['csrf']);$out=['state'=>'invalid','status'=>422,'message'=>'Den ursprünglichen Rechnungsentwurf vollständig prüfen.','raw'=>$raw,'post'=>$post,'document'=>null,'source'=>null,'company'=>null,'companies'=>null,'company_page'=>null,'first'=>null,'can_company_review'=>false,'original'=>['view'=>'finance','tab'=>'inbox']+array_intersect_key($query,array_flip(['inbox_doc','status','inbox_q','inbox_year','inbox_page']))];$own=!$db->inTransaction();
        try{
            if(($post['action']??null)!=='create-entry'||($post['inbox_guided']??null)!=='1')return$out;
            if($move&&!InvoiceEntryDraft::operation($post['invoice_read']??null))return$out;
            try{InvoiceEntryDraft::assertComplete($post);}catch(DomainException$failure){$out['state']='incomplete';$out['status']=422;$out['message']=$failure->getMessage();return$out;}
            if($own){$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();}
            $source=InvoiceInboxSource::prepare($db,$actor,$query,$post,'POST');$out['source']=$source;
            if($source['state']!=='selected'){$out['status']=$source['status'];$out['message']=$source['message'];return$out;}
            $doc=$source['selected'];$out['document']=$doc;
            $key=$post['request_key']??null;if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key))return$out;
            $first=ManualFinanceForm::existing($db,$actor,$key);
            if($first!==null){
                if($first['kind']!=='expense'||!in_array($first['id'],array_column($doc['entry_links'],'source_id'),true)){$out['state']='conflict';$out['status']=409;$out['message']='Diese ursprüngliche Vorgangskennung gehört nicht zu einem Beleg dieser Originalrechnung.';return$out;}
                $out['state']='saved';$out['status']=200;$out['first']=$first;$out['message']='Unter dieser Vorgangskennung wurde bereits ein Beleg gespeichert. Der frühere Ausgang bestätigt keine neuen oder geänderten Entwurfsangaben.';return$out;
            }
            $finance=new Finance($db,$actor);$documents=new Documents($db,$actor);
            if(!$finance->canWrite()||!$documents->canEdit($doc['id'])){$out['state']='blocked';$out['status']=403;$out['message']='Für diesen Rechnungsentwurf fehlen die aktuellen Bearbeitungsrechte.';return$out;}
            if($doc['state']!=='active'||$doc['entry_links']!==[]||(string)$doc['version']!==($post['inbox_version']??null)){$out['state']='stale';$out['status']=409;$out['message']='Die ursprüngliche Rechnung wurde inzwischen geändert, archiviert oder bereits zugeordnet. Der Entwurf und seine alte Dateiversion bleiben erhalten.';return$out;}
            $known=['action','inbox_guided','inbox_doc','inbox_version','request_key','csrf','invoice_read','supplier_contact','supplier_version','supplier_reference','description','counterparty','amount','document_date','due_date','category','notes','purpose','payment_source','reimbursement_contact','invoice_recipient','classification_area','classification_event_kind','classification_asset_kind','classification_special','classification_input_tax','classification_vat_rate','invoice_positions','item_refs','item_positions','use_invoice_positions','positions_reviewed','positions_rounding_reviewed','positions_tax_total','invoice_form_version','invoice_form_complete','confirm_other_invoice','invoice_choices'];
            if(array_diff(array_keys($post),$known)!==[])return$out;
            if(array_key_exists('invoice_positions',$post)&&!is_array($post['invoice_positions']))return$out;
            foreach($post['invoice_positions']??[]as$row){
                if(!is_array($row)||array_is_list($row)||array_diff(array_keys($row),['description','amount','tax_rate','quantity','unit','unit_price','price_basis','calculation_note','source_hint','sphere','account','booking_key','input_tax','items','new_item'])!==[])return$out;
                foreach($row as$field=>$value){
                    if($field==='items'){if(!is_array($value)||!array_is_list($value)||count(array_filter($value,'is_string'))!==count($value))return$out;}
                    elseif($field==='new_item'){if(!is_array($value)||array_diff(array_keys($value),['name','home_location','location_version','unit','category','content_quantity','content_unit'])!==[]||count(array_filter($value,'is_string'))!==count($value))return$out;}
                    elseif(!is_string($value))return$out;
                    if($field==='tax_rate'&&is_string($value)&&!in_array($value,['','0','7','19'],true))return$out;
                    if($field==='price_basis'&&is_string($value)&&!in_array($value,['','gross','net'],true))return$out;
                    if($field==='input_tax'&&is_string($value)&&!in_array($value,['','unknown','none','deductible'],true))return$out;
                }
            }
            foreach($post as$field=>$value){
                if(in_array($field,['invoice_positions','item_refs','item_positions','invoice_choices'],true)){
                    if(!is_array($value)||!self::tree($value))return$out;
                    if($field==='invoice_positions'&&(!array_is_list($value)||count($value)>50))return$out;
                }elseif(!is_string($value)||!self::tree($value))return$out;
            }
            InvoiceEntryChoices::cursors($post);
            if(isset($post['item_refs'])&&(!array_is_list($post['item_refs'])||count($post['item_refs'])>50||count(array_filter($post['item_refs'],'is_string'))!==count($post['item_refs'])))return$out;
            // Keep values that a date/select/text control cannot represent in the original recovery card.
            foreach(['document_date','due_date']as$field)if(isset($post[$field])&&$post[$field]!==''){
                if(!preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D',$post[$field],$parts)||!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]))return$out;
            }
            $choices=['use_invoice_positions'=>['','0','1'],'positions_reviewed'=>['','0','1'],'positions_rounding_reviewed'=>['','0','1'],'category'=>array_keys(Finance::CATEGORIES),'classification_area'=>['','membership','event','club','asset','rent','sales','uncertain'],'classification_event_kind'=>['','ideell','purpose','business'],'classification_asset_kind'=>['','consumable','equipment'],'classification_special'=>['none','mixed','foreign','credit','longterm','unclear'],'classification_input_tax'=>['','unknown','none','deductible'],'classification_vat_rate'=>['','0','7','19'],'payment_source'=>['none','club','private_advance'],'invoice_recipient'=>['club','member','other','unknown']];
            foreach($choices as$field=>$values)if(isset($post[$field])&&!in_array($post[$field],$values,true))return$out;
            foreach(['supplier_reference','counterparty']as$field)if(isset($post[$field])&&preg_match('/[\r\n]/',$post[$field]))return$out;
            if(($post['payment_source']??'')==='private_advance'&&($post['invoice_recipient']??'club')!=='club'&&($post['classification_input_tax']??'')==='deductible')return$out;
            if($move){$out['post']=InvoiceEntryDraft::move($post,$post['invoice_read'],new \Kulturhus\Verein\Inventory($db,$actor));$post=$out['post'];$out['raw']=$post;unset($out['raw']['csrf']);}
            if(InvoiceEntryDraft::hasInventoryDraft($post)&&!(new \Kulturhus\Verein\Inventory($db,$actor))->canWrite()){$out['state']='blocked';$out['status']=403;$out['message']='Der ursprüngliche Inventarentwurf bleibt erhalten. Zum Bearbeiten fehlen die aktuellen Inventarrechte. Die Neuanlage kann ausdrücklich zurückgenommen werden.';return$out;}
            $selected=$post['supplier_contact']??'';$version=$post['supplier_version']??'0';
            if(!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$version)||(int)$version>2147483647)return$out;
            try{$out['company_page']=InvoiceEntryChoices::companies($db,$actor,$post);$out['companies']=$out['company_page']===null?null:InvoiceEntryChoices::companyOptions($out['company_page']);}
            catch(DomainException){
                if($selected!==''||($move&&$post['invoice_read']==='supplier')){$out['state']='blocked';$out['status']=403;$out['message']='Die ursprüngliche Firmenauswahl kann mit den aktuellen Kontaktrechten nicht geprüft werden.';return$out;}
            }
            if($out['companies']!==null)$out['can_company_review']=true;
            foreach($out['companies']??[]as$company)if($company['id']===$selected){$out['company']=$company;break;}
            if($selected!==''&&$out['company']===null){$out['state']='supplier';$out['status']=409;$out['message']='Die ursprünglich gewählte Firma ist nicht verfügbar. Keine andere Firma wurde eingesetzt. Eine neue Auswahl ausdrücklich prüfen.';return$out;}
            if($move&&$post['invoice_read']==='supplier'){
                if($out['company']===null){$out['message']='Zuerst eine Firma ausdrücklich auswählen und lesen.';return$out;}
                $out['post']['supplier_version']=(string)$out['company']['version'];$out['post']['counterparty']=$out['company']['name'];
            }elseif($selected!==''&&(string)$out['company']['version']!==$version){$out['state']='supplier';$out['status']=409;$out['message']='Die ursprüngliche Firmenversion ist noch nicht geprüft oder inzwischen geändert. Die gewählte Firma gesondert im aktuellen Stand lesen.';return$out;}
            if($finance->inputTaxEligibility()==='no'){
                if(isset($post['classification_input_tax'])&&$post['classification_input_tax']!=='none'){$out['state']='stale';$out['status']=409;$out['message']='Die aktuelle Vorsteuerregel passt nicht mehr zu diesem ursprünglichen Entwurf. Die Angaben bleiben erhalten; die steuerliche Einordnung neu prüfen.';return$out;}
                foreach($post['invoice_positions']??[]as$row)if(is_array($row)&&isset($row['input_tax'])&&$row['input_tax']!=='none'){$out['state']='stale';$out['status']=409;$out['message']='Die aktuelle Vorsteuerregel passt nicht mehr zu diesem ursprünglichen Entwurf. Die Angaben bleiben erhalten; die steuerliche Einordnung neu prüfen.';return$out;}
            }
            $out['state']='ready';$out['status']=200;$out['message']='Rechnungsentwurf und Firmenliste angezeigt. Es wurde keine Ausgabe und keine Zahlung gespeichert.';return$out;
        }catch(DomainException$failure){$out['status']=in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():409;$out['message']=$failure->getMessage();return$out;}
        catch(Throwable){$out['status']=503;$out['message']='Der ursprüngliche Rechnungsentwurf konnte gerade nicht gelesen werden. Die Angaben bleiben erhalten.';return$out;}
        finally{if($own&&$db->inTransaction())$db->rollBack();}
    }
}
