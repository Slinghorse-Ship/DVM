<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices;

use DomainException;
use Kulturhus\Verein\OutgoingInvoices;
use Kulturhus\Verein\Settings\GeneralInput;

/** Whole native form values remain separate from the canonical invoice normalization. */
final class OutgoingInvoiceInput
{
    public const FIELDS = ['buyer_name','buyer_street','buyer_postcode','buyer_city','buyer_email',
        'buyer_reference','seller_contact','description','note','vat_reason','document_date',
        'delivery_date','due_date','category','contact_id','payment_account','domestic_confirmed','invoice_format'];
    public const POSITION = ['description','quantity','unit','unit_gross','tax_rate','sphere','account'];

    private static function whole(mixed $value): string
    {
        if (!is_string($value) || strlen($value)>40000 || preg_match('//u',$value)!==1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)) {
            throw new DomainException('Ganzes Rechnungsfeld enthält ungültige Daten.',422);
        }
        return $value;
    }

    private static function fields(array $input,array $keys): array
    {
        if (array_diff($keys,array_keys($input)) || array_diff(array_keys($input),$keys)) {
            throw new DomainException('Alle ursprünglichen Rechnungsfelder vollständig übermitteln.',422);
        }
        $result=[];
        foreach ($keys as $key) $result[$key]=self::whole($input[$key]);
        return $result;
    }

    public static function capture(array $input): array
    {
        if (array_keys($input)!==['operation','invoice','version','data']
            || !is_string($input['operation']) || !in_array($input['operation'],['save','review','issue'],true)
            || !is_string($input['invoice']) || ($input['invoice']!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$input['invoice']))
            || ($input['invoice']===''&&$input['operation']!=='save') || !is_array($input['data'])) {
            throw new DomainException('Eigene ganze Rechnungsaktion auswählen.',422);
        }
        $input['version']=(string)GeneralInput::version($input['version']);
        if (in_array($input['operation'],['save','review'],true)) {
            $fields=self::FIELDS;$fields[]='positions';$data=$input['data'];
            if (array_diff($fields,array_keys($data)) || array_diff(array_keys($data),$fields)
                || !is_array($data['positions']) || !array_is_list($data['positions']) || count($data['positions'])>50) {
                throw new DomainException('Alle ursprünglichen Rechnungsfelder und Positionen vollständig übermitteln.',422);
            }
            $positions=$data['positions'];unset($data['positions']);$data=self::fields($data,self::FIELDS);
            foreach ($positions as $index=>$row) {
                if (!is_array($row)) throw new DomainException('Ganze ursprüngliche Rechnungsposition prüfen.',422);
                $positions[$index]=self::fields($row,self::POSITION);
            }
            $data['positions']=$positions;$input['data']=$data;
        } else {
            $input['data']=self::fields($input['data'],$input['operation']==='issue'?['reviewed_hash']:[]);
        }
        return $input;
    }

    public static function initial(?array $invoice=null): array
    {
        $source=$invoice['data']??[];$data=[];
        foreach (self::FIELDS as $key) $data[$key]=(string)($source[$key]??'');
        $data['domestic_confirmed']=!empty($source['domestic_confirmed'])?'1':'0';
        $data['invoice_format']=$invoice===null?'pdf':(string)($source['invoice_format']??'hybrid-xrechnung');
        if ($invoice===null) {
            foreach (['document_date','delivery_date'] as $key) $data[$key]=date('Y-m-d');
            $data['due_date']=date('Y-m-d',strtotime('+14 days'));$data['category']='general';
        }
        $data['positions']=[];
        foreach ($source['positions']??[] as $position) {
            $data['positions'][]=['description'=>$position['description'],
                'quantity'=>OutgoingInvoices::quantityText((int)$position['quantity']),
                'unit'=>$position['unit'],'unit_gross'=>OutgoingInvoices::amount((int)$position['price']),
                'tax_rate'=>(string)$position['rate'],'sphere'=>(string)$position['sphere'],'account'=>(string)$position['account']];
        }
        if ($invoice===null) $data['positions'][]=self::emptyPosition();
        return self::capture(['operation'=>'save','invoice'=>$invoice['id']??'',
            'version'=>(string)($invoice['version']??0),'data'=>$data]);
    }

    public static function emptyPosition(): array
    {
        return ['description'=>'','quantity'=>'1','unit'=>'C62','unit_gross'=>'0,00','tax_rate'=>'0','sphere'=>'','account'=>''];
    }

    public static function row(array $input,string $action): array
    {
        $input=self::capture($input);
        if ($input['operation']!=='save') throw new DomainException('Eigene ungespeicherte Rechnungspositionen öffnen.',422);
        $rows=$input['data']['positions'];
        if ($action==='add') {
            if (count($rows)>=50) throw new DomainException('Höchstens 50 Rechnungspositionen sind möglich.',422);
            $rows[]=self::emptyPosition();
        } else {
            if (!preg_match('/\A(remove|up|down):([0-9]{1,2})\z/D',$action,$parts)
                || !array_key_exists((int)$parts[2],$rows)) throw new DomainException('Gültige ungespeicherte Positionsaktion auswählen.',422);
            $index=(int)$parts[2];
            if ($parts[1]==='remove') {
                if (count($rows)<=1) throw new DomainException('Mindestens eine Rechnungsposition beibehalten.',422);
                array_splice($rows,$index,1);
            } else {
                $other=$index+($parts[1]==='up'?-1:1);
                if (!array_key_exists($other,$rows)) throw new DomainException('Gültige ungespeicherte Positionsaktion auswählen.',422);
                [$rows[$index],$rows[$other]]=[$rows[$other],$rows[$index]];
            }
        }
        $input['data']['positions']=$rows;
        return $input;
    }
}
