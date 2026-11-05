<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

/** Classify HTML read intent without choosing another invoice or creating a draft. */
final class OutgoingInvoiceSource
{
    public static function request(bool $route,array $query,string $method='GET'):array
    {
        $none=['state'=>'none','raw'=>null,'message'=>'','status'=>200];
        if(!$route||!in_array($method,['GET','HEAD'],true))return $none;
        foreach(['outgoinginvoice_original','outgoinginvoice_pdf','outgoingmail_original','outgoingmail_pdf','outgoingcorrection_original','outgoingcorrection_pdf']as$key)if(array_key_exists($key,$query))return $none;
        $hasInvoice=array_key_exists('invoice',$query);$hasNew=array_key_exists('new_invoice',$query);
        if(!$hasInvoice&&!$hasNew)return $none;
        $raw=$hasInvoice&&is_string($query['invoice'])?$query['invoice']:null;
        // Only the existing explicit new-draft route can have an empty invoice identity.
        if($hasNew&&$query['new_invoice']==='1'&&(!$hasInvoice||$raw===''))return array_replace($none,['state'=>'new','raw'=>$raw]);
        $result=array_replace($none,['state'=>'invalid','raw'=>$raw,'status'=>422,'message'=>'Die vollständige Rechnungskennung prüfen. Eine neue Rechnung wird hier nicht automatisch geöffnet.']);
        if($hasNew)return array_replace($result,['message'=>'Genau eine bestehende Rechnung oder ausdrücklich eine neue Rechnung öffnen.']);
        if($raw!==null&&preg_match('/\A[a-f0-9]{32}\z/D',$raw))return array_replace($none,['state'=>'requested','raw'=>$raw]);
        return $result;
    }

    public static function unavailable(array $request,int $status):array
    {
        if($request['state']!=='requested')return $request;
        return array_replace($request,['state'=>'unavailable','status'=>$status,'message'=>$status===503?'Die ursprüngliche Rechnung wird gerade bearbeitet oder konnte nicht geladen werden. Bitte erneut prüfen.':'Die ursprünglich angeforderte Rechnung ist derzeit nicht verfügbar.']);
    }

    /** Invalid typed list controls can only be discarded by an explicitly named recovery action. */
    public static function context(array $query):array
    {
        $fields=[];$invalid=false;
        foreach(['invoices_q','inv_status','inv_kind','invoices_page']as$key)if(array_key_exists($key,$query)){
            if(!is_string($query[$key]))$invalid=true;else $fields[$key]=$query[$key];
        }
        return ['fields'=>$invalid?[]:$fields,'invalid'=>$invalid];
    }
}
