<?php declare(strict_types=1);
namespace Kulturhus\Verein;

final class EventVoucherViewState
{
    public static function prepare(EventVouchers $service,array $query):array
    {
        $state=['personal'=>true,'access'=>null,'event'=>null,'events'=>null,'cards'=>null,'history'=>null,
            'print'=>null,'errors'=>[],'error'=>'','http_status'=>0,'query'=>[]];
        foreach(['voucher_event','voucher_event_query','voucher_event_page','voucher_cards_query','voucher_cards_page','voucher_card_state','voucher_history_query','voucher_history_page']as$field){
            if(is_string($query[$field]??null)&&preg_match('//u',$query[$field])===1)$state['query'][$field]=$query[$field];
        }
        try{$state=array_replace($state,$service->personalWorkspace($query));}
        catch(\DomainException $failure){$state['error']=$failure->getMessage();$state['http_status']=in_array($failure->getCode(),[422,503],true)?$failure->getCode():403;}
        catch(\Throwable){$state['error']='Persönliche Gutscheine konnten nicht geladen werden.';$state['http_status']=500;}
        return$state;
    }
}
