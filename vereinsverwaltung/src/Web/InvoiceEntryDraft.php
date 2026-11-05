<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use Kulturhus\Verein\Inventory;

/** Explicit edits to an unsaved invoice form. Never creates financial or inventory records. */
final class InvoiceEntryDraft
{
    public const VERSION='1';
    public const INPUT_VARS=8192;

    public static function operation(mixed $value):bool
    {
        return InvoiceEntryChoices::operation($value)||(is_string($value)&&(in_array($value,['refresh','supplier','supplier_clear','position_add'],true)
            ||preg_match('/\Aposition_(?:edit|remove|inventory_add|inventory_remove|location):(0|[1-4]?[0-9])\z/D',$value)===1));
    }

    /** Legacy small forms remain readable; versioned forms must carry their final field. */
    public static function assertComplete(array $post):void
    {
        if(!array_key_exists('invoice_form_version',$post)&&!array_key_exists('invoice_form_complete',$post))return;
        if(($post['invoice_form_version']??null)!==self::VERSION||!is_string($post['request_key']??null)
            ||!is_string($post['invoice_form_complete']??null)||!hash_equals($post['request_key'],$post['invoice_form_complete']))
            throw new DomainException('Die Rechnung wurde nicht vollständig übertragen. Die unvollständige Eingabe wird nicht gespeichert. Bitte die Originaleingaben im ursprünglichen Tab behalten und die Formulargröße prüfen lassen.',422);
    }

    public static function hasInventoryDraft(array $post):bool
    {
        foreach($post['invoice_positions']??[]as$row)if(is_array($row)&&array_key_exists('new_item',$row))return true;
        return false;
    }

    public static function move(array $post,string $operation,Inventory $inventory):array
    {
        if(!self::operation($operation))throw new DomainException('Die ursprüngliche Formularaktion vollständig prüfen.',422);
        if(InvoiceEntryChoices::operation($operation))return InvoiceEntryChoices::move($post,$operation);
        if(in_array($operation,['refresh','supplier'],true))return$post;
        if($operation==='supplier_clear'){$post['supplier_contact']='';$post['supplier_version']='0';unset($post['positions_reviewed'],$post['positions_rounding_reviewed']);return$post;}
        $rows=$post['invoice_positions']??[];
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>50)throw new DomainException('Bitte 1 bis 50 Rechnungspositionen prüfen.',422);
        if($operation==='position_add'){
            if(count($rows)>=50)throw new DomainException('Höchstens 50 Positionen. Größere Rechnungen zunächst als geprüften Gesamtbetrag erfassen.',422);
            $rows[]=['description'=>'','amount'=>'','tax_rate'=>$post['classification_vat_rate']??'','quantity'=>'1','unit'=>'','unit_price'=>'','price_basis'=>'gross','calculation_note'=>'','source_hint'=>'','sphere'=>'','account'=>'','booking_key'=>'','input_tax'=>$post['classification_input_tax']??'none'];
            $post['use_invoice_positions']='1';
        }else{
            [$kind,$index]=explode(':',$operation,2);$index=(int)$index;
            if(!isset($rows[$index])||!is_array($rows[$index]))throw new DomainException('Die ursprüngliche Rechnungsposition ist nicht verfügbar.',422);
            if($kind==='position_edit'){
                $rows[$index]=array_replace(['description'=>'','amount'=>'','tax_rate'=>'','quantity'=>'1','unit'=>'','unit_price'=>'','price_basis'=>'gross','calculation_note'=>'','source_hint'=>'','sphere'=>'','account'=>'','booking_key'=>'','input_tax'=>$post['classification_input_tax']??'none'],$rows[$index]);
                if(isset($rows[$index]['new_item']))$rows[$index]['new_item']=array_replace(['name'=>'','home_location'=>'','location_version'=>'0','unit'=>'Stück','category'=>'','content_quantity'=>'1','content_unit'=>'Stück'],$rows[$index]['new_item']);
            }
            elseif($kind==='position_remove')array_splice($rows,$index,1);
            elseif($kind==='position_inventory_remove')unset($rows[$index]['new_item']);
            elseif($kind==='position_inventory_add'){
                if(!$inventory->canWrite())throw new DomainException('Für die Inventarneuanlage fehlen die aktuellen Inventarrechte.',403);
                if(!empty($rows[$index]['items']))throw new DomainException('Vor einer Neuanlage die vorhandene Inventarzuordnung dieser Position ausdrücklich zurücknehmen.',422);
                if(!array_key_exists('new_item',$rows[$index]))$rows[$index]['new_item']=['name'=>$rows[$index]['description']??'','home_location'=>'','location_version'=>'0','unit'=>'Stück','category'=>'','content_quantity'=>'1','content_unit'=>'Stück'];
            }elseif($kind==='position_location'){
                if(!$inventory->canWrite())throw new DomainException('Für die Inventarneuanlage fehlen die aktuellen Inventarrechte.',403);
                $draft=$rows[$index]['new_item']??null;
                if(!is_array($draft)||!is_string($draft['home_location']??null)||$draft['home_location']==='')throw new DomainException('Zuerst den Lagerort ausdrücklich auswählen und lesen.',422);
                $place=$inventory->location($draft['home_location']);
                if($place['id']!==$draft['home_location']||!(bool)$place['active'])throw new DomainException('Der ursprüngliche Lagerort ist nicht mehr verfügbar. Bitte ausdrücklich neu auswählen.',409);
                $rows[$index]['new_item']['location_version']=(string)$place['version'];
            }
        }
        $post['invoice_positions']=array_values($rows);if($rows===[])unset($post['use_invoice_positions']);unset($post['positions_reviewed'],$post['positions_rounding_reviewed']);
        return$post;
    }
}
