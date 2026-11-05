<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;use PDO;
require_once dirname(__DIR__, 2).'/Infrastructure/Media/LiveUploads.php';

/** An optional original is stored inside the existing Finance create transaction. */
final class ManualFinanceAttachment
{
 public static function request(array $files,string $existingDocument):?array
 {
  $file=$files['receipt_file']??null;if($file===null||(is_array($file)&&($file['error']??null)===UPLOAD_ERR_NO_FILE))return null;
  if($existingDocument!=='')throw new DomainException('Das Original aus dem Rechnungseingang ist bereits ausgewählt. Bitte keine zweite Datei hinzufügen.');
  if(!is_array($file)||($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!LiveUploads::file($file['tmp_name'])||!is_string($file['name']??null))throw new DomainException('Belegdatei unvollständig. Bitte erneut auswählen.');
  if(filesize($file['tmp_name'])>10485760)throw new DomainException('Belegdatei darf höchstens 10 MB groß sein.');
  $prepared=LiveUploads::prepared($file['tmp_name'],$file['name']);$bytes=$prepared->bytes;
  self::inspect($prepared->name,$bytes);return ['name'=>$prepared->name,'bytes'=>$bytes,'prepared'=>$prepared];
 }
 private static function inspect(string $name,string $bytes):void
 {
  [, $mime]=Documents::inspectFile($name,$bytes);
  if(!in_array($mime,['application/pdf','application/xml','image/jpeg','image/png','image/heic','image/heif'],true))throw new DomainException('Als Beleg PDF, XML, JPG, PNG oder HEIC auswählen.');
 }
 public static function store(PDO $db,string $actor,string $entry,array $file,string $key):string
 {
  if(!$db->inTransaction())throw new DomainException('Beleg und Original müssen gemeinsam gespeichert werden.');
  $finance=new Finance($db,$actor);$docs=new Documents($db,$actor);
  if(!$finance->canWrite()||!$docs->canWrite())throw new DomainException('Finanz- und Dokument-Bearbeitungsrechte erforderlich.');
  $row=$finance->entry($entry);self::inspect($file['name'],$file['bytes']);
  return $docs->uploadWithinTransaction($file['name'],$file['bytes'],['title'=>$row['description'],'kind'=>'receipt','scope'=>'finance','document_date'=>$row['document_date'],'links'=>['entry:'.$entry]],substr(hash('sha256','manual-receipt:'.$key),0,32),($file['prepared']??null) instanceof CameraDocumentUploads?$file['prepared']:null);
 }
}
