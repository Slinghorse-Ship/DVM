<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices;

use DomainException;
use Kulturhus\Verein\Settings\GeneralInput;

final class OutgoingCorrectionInput
{
    public const FIELDS=['kind','amount','reason','date'];
    public static function capture(array $input):array
    {
        if(array_keys($input)!==['invoice','version','data']||!is_string($input['invoice'])||!preg_match('/\A[a-f0-9]{32}\z/D',$input['invoice'])||!is_array($input['data'])||array_diff(self::FIELDS,array_keys($input['data']))||array_diff(array_keys($input['data']),self::FIELDS))throw new DomainException('Ganze eigene Rechnungskorrektur vollständig übermitteln.',422);
        $input['version']=(string)GeneralInput::version($input['version']);$data=[];
        foreach(self::FIELDS as$field){$value=$input['data'][$field];if(!is_string($value)||strlen($value)>40000||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new DomainException('Ganzes Korrekturfeld enthält ungültige Daten.',422);$data[$field]=$value;}
        $input['data']=$data;return $input;
    }
    public static function initial(array $invoice):array
    {
        return self::capture(['invoice'=>$invoice['id'],'version'=>(string)$invoice['version'],'data'=>['kind'=>'cancellation','amount'=>'0','reason'=>'','date'=>date('Y-m-d')]]);
    }
}
