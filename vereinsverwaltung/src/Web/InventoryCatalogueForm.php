<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

/** Read selections are validated before a scalar helper can discard their originals. */
final class InventoryCatalogueForm
{
    public const FIELDS=[
        'where'=>['q'=>150,'member'=>80,'location'=>80,'state'=>40,'where_page'=>0],
        'items'=>['q'=>150,'status'=>40,'category'=>120,'group'=>80,'item_page'=>0],
        'loans'=>['q'=>150,'state'=>40,'borrower'=>80,'loan_page'=>0],
        'assets'=>['category'=>120,'member'=>80,'location'=>80,'asset_page'=>0],
        'material'=>['q'=>150],
    ];
    public static function applies(array $query,string $tab):bool
    {
        if(!isset(self::FIELDS[$tab])||($tab==='material'&&($query['view']??null)!=='material'))return false;
        if($tab==='items'&&(isset($query['new_item'])||(is_string($query['item']??null)&&$query['item']!=='')))return false;
        if($tab==='loans'&&(isset($query['new_loan'])||array_key_exists('loan',$query)))return false;
        return true;
    }
    public static function state(array $query,string $tab):array
    {
        $fields=self::FIELDS[$tab]??throw new \InvalidArgumentException('Known inventory catalogue required.');
        $original=array_intersect_key($query,array_flip(['view','tab','item','loan',...array_keys($fields)]));
        $route=$tab==='material'?['view'=>'material']:['view'=>'inventory','tab'=>$tab];
        $blocked=($query['view']??null)!==$route['view']||($tab!=='material'&&isset($query['tab'])&&$query['tab']!==''&&$query['tab']!==$tab);
        foreach(['item','loan']as $key)if(array_key_exists($key,$query)&&!is_string($query[$key]))$blocked=true;
        foreach($fields as $name=>$limit){
            if(!array_key_exists($name,$query))continue;$value=$query[$name];
            if(!is_string($value)||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1f\x7f]/',$value)){$blocked=true;continue;}
            if($limit===0){if(!preg_match('/\A[1-9][0-9]{0,6}\z/D',$value)||(int)$value>1000000)$blocked=true;}
            elseif(strlen($value)>$limit)$blocked=true;
        }
        $choices=match($tab){
            'where'=>['state'=>['','available','reserved','blocked','issued']],
            'items'=>['status'=>['','active','low','blocked','archived','all']],
            'loans'=>['state'=>['','open','overdue','planned','reserved','issued','returned','cancelled']],
            default=>[],
        };
        foreach($choices as $name=>$allowed)if(isset($query[$name])&&!in_array($query[$name],$allowed,true))$blocked=true;
        return ['blocked'=>$blocked,'original'=>$blocked?$original:null,'tab'=>$tab,'route'=>$route,'error'=>$blocked?'Inventarauswahl vollständig prüfen. Die ursprünglichen Filter- und Seitenangaben bleiben erhalten.':''];
    }
    public static function options(array $options,string $value,string $unavailable):array
    {
        return $value!==''&&!array_key_exists($value,$options)?[$value=>$unavailable]+$options:$options;
    }
}
