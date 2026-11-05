<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use InvalidArgumentException;
use Kulturhus\Native\Shared\MediaDescription;

/** Bounded plain metadata; operational restrictions remain current even after historical restore. */
final class Metadata
{
    public const RIGHTS=['rights_state','consent_state','expires_on','channels'];
    public static function normalize(array $input,string $name):array
    {
        $allowed=['title','description','tags',...MediaDescription::PUBLIC_FIELDS,...self::RIGHTS];
        if(array_diff(array_keys($input),$allowed))throw new InvalidArgumentException('Dateiangaben prüfen.');
        $title=MediaDescription::text($input['title']??$name,240,true,false,'Datei');
        $description=MediaDescription::text($input['description']??'',2500,false,true,'Datei');
        $tags=$input['tags']??[];
        if(!is_array($tags)||!array_is_list($tags)||count($tags)>20)throw new InvalidArgumentException('Höchstens 20 Schlagwörter je Datei angeben.');
        $out=[];$seen=[];
        foreach($tags as$tag){$tag=MediaDescription::text($tag,60,true,false,'Datei');$fold=mb_strtolower($tag,'UTF-8');if(isset($seen[$fold]))continue;$seen[$fold]=true;$out[]=$tag;}
        $alt=$input['alt']??$title;if(is_string($alt)&&trim($alt)==='')$alt=$title;
        return ['title'=>$title,'description'=>$description,'tags'=>$out]+MediaDescription::normalize(['alt'=>$alt]+$input,'Datei');
    }
    public static function public(array $metadata):array
    {return array_intersect_key($metadata,array_flip(['title','description','tags',...MediaDescription::PUBLIC_FIELDS]));}
    public static function sameRights(array $left,array $right):bool
    {foreach(self::RIGHTS as$key)if(($left[$key]??null)!==($right[$key]??null))return false;return true;}
    public static function restore(array $historical,array $current):array
    {foreach(self::RIGHTS as$key)$historical[$key]=$current[$key];return $historical;}
    /** Neither choosing an earlier image nor replacing pixels silently relaxes present restrictions. */
    public static function restrict(array $head,array $target):array
    {
        $result=[];
        foreach(['rights_state','consent_state']as$key){$left=$head[$key];$right=$target[$key];$result[$key]=in_array('withdrawn',[$left,$right],true)?'withdrawn':(in_array('unknown',[$left,$right],true)?'unknown':$left);}
        $result['channels']=array_values(array_intersect($head['channels'],$target['channels']));
        $dates=array_values(array_filter([$head['expires_on'],$target['expires_on']],static fn($date)=>$date!==''));sort($dates,SORT_STRING);$result['expires_on']=$dates[0]??'';return $result;
    }
}
