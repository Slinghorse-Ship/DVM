<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use PDO;
use Kulturhus\Verein\{Documents,Inventory,InvoiceSupplierContacts,Pagination,Permissions,Registry};

/** Bounded current choices. Searching never adopts a source or a newer version. */
final class InvoiceEntryChoices
{
    public static function operation(mixed $operation):bool
    {
        return is_string($operation)&&preg_match('/\Achoice_(company|item|event)_(search|previous|next|page)\z/D',$operation)===1;
    }

    public static function cursors(array $post):array
    {
        $input=$post['invoice_choices']??[];
        if(!is_array($input)||array_diff(array_keys($input),['company','item','event'])!==[])throw new DomainException('Rechnungsauswahl und Suchseiten prüfen.',422);
        $out=[];
        foreach(['company','item','event']as$kind){
            $row=$input[$kind]??[];
            if(!is_array($row)||array_diff(array_keys($row),$kind==='event'?['query','page','archive']:['query','page'])!==[])throw new DomainException('Rechnungsauswahl und Suchseiten prüfen.',422);
            $query=$row['query']??'';$page=$row['page']??'1';$archive=$row['archive']??'0';
            if(!is_string($query)||!mb_check_encoding($query,'UTF-8')||mb_strlen($query,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$query)
                ||!is_string($page)||preg_match('/\A[1-9][0-9]{0,6}\z/D',$page)!==1||(int)$page>1000000||!in_array($archive,['0','1'],true))throw new DomainException('Rechnungsauswahl und Suchseiten prüfen.',422);
            $out[$kind]=['query'=>$query,'page'=>$page,'archive'=>$archive];
        }
        return$out;
    }

    public static function move(array $post,string $operation):array
    {
        if(!self::operation($operation))throw new DomainException('Rechnungsauswahl und Suchseiten prüfen.',422);
        [, $kind,$move]=explode('_',$operation);$cursor=self::cursors($post)[$kind];
        $page=match($move){'search'=>1,'previous'=>max(1,(int)$cursor['page']-1),'next'=>min(1000000,(int)$cursor['page']+1),default=>(int)$cursor['page']};
        $post['invoice_choices'][$kind]['page']=(string)$page;
        return$post;
    }

    public static function companies(PDO $db,string $actor,array $post):?array
    {
        $cursor=self::cursors($post)['company'];$selected=$post['supplier_contact']??'';
        if(!is_string($selected))throw new DomainException('Gewählte Firma prüfen.',422);
        $input=['contact_query'=>$cursor['query'],'contact_page'=>$cursor['page'],'contact_id'=>$selected];$service=new InvoiceSupplierContacts($db,$actor);
        try{$page=$service->page($input);}catch(DomainException$error){
            if($selected===''||$error->getMessage()!=='Gewählter Kontakt ist nicht mehr verfügbar.')throw$error;
            $page=$service->page(array_replace($input,['contact_id'=>'']));
        }
        if($page['total']===0&&Permissions::actor($db,$actor)['members_access']==='none')return null;
        return$page;
    }

    public static function companyOptions(?array $page):array
    {
        if($page===null)return[];$rows=$page['rows'];
        if($page['selected']!==null&&!in_array($page['selected']['id'],array_column($rows,'id'),true))array_unshift($rows,$page['selected']);
        return$rows;
    }

    /** Select only six small writable source records; never load stock or event bodies. */
    public static function items(PDO $db,string $actor,array $post):?array
    {
        $cursor=self::cursors($post)['item'];$inventory=new Inventory($db,$actor);$documents=new Documents($db,$actor);
        if(!$inventory->canWrite()||!$documents->canScope('inventory',true))return null;
        $club=Permissions::actor($db,$actor)['club_id'];$where=" FROM inventory_items i JOIN document_sources s ON s.club_id=i.club_id AND s.source_type='item' AND s.source_id=i.id AND s.available=1 WHERE i.club_id=? AND i.active=1 AND i.deleted_at IS NULL";$args=[$club];
        if(trim($cursor['query'])!==''){$where.=" AND instr(lower(concat(i.code,' ',i.name)),lower(?))>0";$args[]=trim($cursor['query']);}
        $query=$db->prepare('SELECT COUNT(*)'.$where);$query->execute($args);$window=Pagination::window((int)$query->fetchColumn(),(int)$cursor['page'],6);
        $query=$db->prepare('SELECT i.id,i.name,i.code,i.version'.$where.' ORDER BY i.name,i.code,i.id LIMIT 6 OFFSET '.$window['offset']);$query->execute($args);$rows=$query->fetchAll(PDO::FETCH_ASSOC);
        return ['rows'=>$rows,'query'=>$cursor['query'],'page'=>$window['page'],'pages'=>$window['pages'],'total'=>$window['total']];
    }

    public static function events(Registry $registry,array $post):array
    {
        $cursor=self::cursors($post)['event'];return$registry->appointmentChoicePage(['query'=>$cursor['query'],'page'=>$cursor['page'],'archive'=>$cursor['archive']==='1'])+['query'=>$cursor['query'],'archive'=>$cursor['archive']];
    }

    /** At most six candidates plus labels for the supported one hundred fifty original links. */
    public static function itemOptions(PDO $db,string $actor,array $post,?array $page):array
    {
        $out=[];foreach($page['rows']??[]as$row){$ref=$row['id'].'|'.$row['version'];$out[$ref]=['id'=>$ref,'label'=>$row['code'].' · '.$row['name'],'candidate'=>true];}
        if($page===null)return$out;
        $club=Permissions::actor($db,$actor)['club_id'];$query=$db->prepare("SELECT i.id,i.name,i.code,i.version FROM inventory_items i JOIN document_sources s ON s.club_id=i.club_id AND s.source_type='item' AND s.source_id=i.id AND s.available=1 WHERE i.club_id=? AND i.id=? AND i.deleted_at IS NULL");$read=[];
        $refLists=[$post['item_refs']??[]];foreach($post['invoice_positions']??[]as$row)$refLists[]=is_array($row)?($row['items']??[]):[];
        foreach($refLists as$refs)foreach($refs as$ref){
            if(!is_string($ref)||isset($out[$ref])||isset($read[$ref])||count($read)>=150||preg_match('/\A([a-f0-9]{32})\|([1-9][0-9]{0,9})\z/D',$ref,$parts)!==1)continue;
            $read[$ref]=true;$query->execute([$club,$parts[1]]);$item=$query->fetch(PDO::FETCH_ASSOC);if($item)$out[$ref]=['id'=>$ref,'label'=>$item['code'].' · '.$item['name'],'candidate'=>false,'source_version'=>$parts[2],'current_version'=>(string)$item['version']];
        }
        return$out;
    }
}
