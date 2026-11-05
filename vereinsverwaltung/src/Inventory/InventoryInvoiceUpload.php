<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
require_once dirname(__DIR__).'/Infrastructure/Media/CameraDocumentUploads.php';

final class InventoryInvoiceUpload
{
    public function __construct(private PDO $db,private string $actor){}

    public function authorize(string $item):array
    {
        if(!(new Finance($this->db,$this->actor))->canWrite())throw new DomainException('Finanzschreibrecht erforderlich.');
        (new Documents($this->db,$this->actor))->source('item',$item,true);
        $row=(new Inventory($this->db,$this->actor))->item($item);
        if(!$row['active'])throw new DomainException('Für einen archivierten Gegenstand können keine Rechnungen hinzugefügt werden.');
        return $row;
    }

    public function upload(string $item,array $files,array $data,string $key):array
    {
        $this->authorize($item);
        foreach($files as &$file){
            if(($file['prepared']??null) instanceof CameraDocumentUploads&&($file['prepared']->name!==($file['name']??null)||$file['prepared']->bytes!==($file['bytes']??null)))throw new DomainException('Vorbereitete Datei passt nicht zum Upload.');
            $prepared=($file['prepared']??null) instanceof CameraDocumentUploads?$file['prepared']:CameraDocumentUploads::fromBytes((string)($file['name']??''),(string)($file['bytes']??''));$file['prepared']=$prepared;$file['name']=$prepared->name;$file['bytes']=$prepared->bytes;
            if(!in_array(strtolower(pathinfo($file['name']??'',PATHINFO_EXTENSION)),['pdf','xml','jpg','jpeg','png'],true))throw new DomainException('Rechnungen als PDF, XML, JPG oder PNG auswählen.');
            $file['links']=['item:'.$item];$file['scope']='finance';$file['folder']='';
        }unset($file);
        $data=array_replace($data,['kind'=>'receipt','scope'=>'finance','folder'=>'','links'=>['item:'.$item]]);
        return (new Documents($this->db,$this->actor))->uploadMany($files,$data,$key,fn()=>$this->authorize($item));
    }
}
