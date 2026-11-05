<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Search and page only rows already filtered by the document access checks. */
final class InvoiceIntakeList
{
 public static function page(array $rows,string $query,string $page,int $size=12):array
 {
  $query=mb_substr(trim($query),0,150);$words=preg_split('/\s+/u',mb_strtolower($query),-1,PREG_SPLIT_NO_EMPTY)?:[];
  $rows=array_values(array_filter($rows,static function(array $row)use($words):bool{
   $text=mb_strtolower(implode(' ',[(string)($row['title']??$row['subject']??''),(string)($row['name']??''),(string)($row['sender']??''),(string)($row['document_date']??$row['received_at']??''),
    ...array_map(static fn($origin)=>($origin['sender']??'').' '.($origin['subject']??''),$row['mail_origins']??[]),
    ...array_map(static fn($link)=>(string)($link['label']??''),$row['entry_links']??[])]));
   foreach($words as $word)if(!str_contains($text,$word))return false;return true;
  }));
  $total=count($rows);$size=max(1,min(50,$size));$pages=max(1,(int)ceil($total/$size));$current=ctype_digit($page)&&strlen($page)<=6?max(1,min($pages,(int)$page)):1;
  return ['rows'=>array_slice($rows,($current-1)*$size,$size),'query'=>$query,'total'=>$total,'page'=>$current,'pages'=>$pages,'first'=>$total?($current-1)*$size+1:0,'last'=>min($current*$size,$total)];
 }
}
