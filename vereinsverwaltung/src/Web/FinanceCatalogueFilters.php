<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;

/** Retain complete query values until validation; the route owns contribution/donation scope. */
final class FinanceCatalogueFilters
{
    /** Page size is an interface choice, never part of the financial filter or CSV scope. */
    public static function pageSize(array $query):int
    {
        if(!array_key_exists('per_page',$query))return 6;
        $size=$query['per_page'];
        if(!is_string($size)||!in_array($size,['6','20','40','100'],true))throw new DomainException('Anzahl der Belege pro Seite prüfen.');
        return (int)$size;
    }

    public static function read(array $query,string $view='finance',string $tab='receipts'):array
    {
        $filters=[];foreach(['kind','status','from','to','q','account','direction']as$name)if(array_key_exists($name,$query))$filters[$name]=$query[$name];
        foreach($filters as$value)if(!is_string($value))throw new DomainException('Belegfilter prüfen.');
        if($view==='donations')$filters['kind']='donation';
        elseif($view==='finance'&&$tab==='contributions')$filters['kind']='contribution';
        return $filters;
    }
}
