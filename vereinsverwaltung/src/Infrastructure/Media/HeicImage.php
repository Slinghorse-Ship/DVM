<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Container admission only. Native decoding is exclusively inside the OCR sandbox. */
final class HeicImage
{
 public static function mime(string $bytes):string
 {
  if(strlen($bytes)<20||strlen($bytes)>10485760||substr($bytes,4,4)!=='ftyp')throw new DomainException('HEIC-Dateikopf ungültig.');
  $size=unpack('N',substr($bytes,0,4))[1];if($size<20||$size>4096||$size>strlen($bytes)||$size%4!==0)throw new DomainException('HEIC-Dateikopf ungültig.');
  $brands=[substr($bytes,8,4)];for($i=16;$i<$size;$i+=4)$brands[]=substr($bytes,$i,4);
  if(array_intersect($brands,['msf1','hevc','hevx','avis'])||!array_intersect($brands,['heic','heix'])||!in_array($brands[0],['heic','heix','mif1','heif'],true))throw new DomainException('Nur einzelne HEIC-Fotos werden unterstützt.');
  return 'image/heic';
 }
 public static function preview(string $bytes,string $mime):string
 {
  if(!in_array($mime,['image/heic','image/heif'],true))throw new DomainException('Keine HEIC-Vorschau.');self::mime($bytes);require_once dirname(__DIR__, 2).'/Finance/Invoices/InvoiceRecognition.php';$result=InvoiceRecognition::extract($bytes,$mime,'preview');
  $jpeg=isset($result['preview_base64'])&&is_string($result['preview_base64'])?base64_decode($result['preview_base64'],true):false;
  if(($result['state']??'')!=='ready'||$jpeg===false||strlen($jpeg)>1500000||!str_starts_with($jpeg,"\xff\xd8\xff"))throw new DomainException('HEIC-Vorschau konnte nicht sicher erstellt werden.');return $jpeg;
 }
}
