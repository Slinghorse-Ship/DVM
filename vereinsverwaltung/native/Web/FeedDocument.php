<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

use LogicException;
use RuntimeException;

/** A complete validated representation on a private temporary stream, never a public cache file. */
final class FeedDocument
{
    private $stream;
    private $hash;
    private int $length=0;
    private int $records=0;
    private ?string $etag=null;

    public function __construct()
    {
        $this->stream=tmpfile();
        if($this->stream===false)throw new RuntimeException('Temporäre Feed-Ausgabe nicht verfügbar.');
        $this->hash=hash_init('sha256');
    }

    public function append(string $bytes):void
    {
        if(!is_resource($this->stream)||$this->etag!==null)throw new LogicException('Feed-Ausgabe bereits abgeschlossen.');
        $length=strlen($bytes);$offset=0;
        while($offset<$length){$written=fwrite($this->stream,substr($bytes,$offset));if($written===false||$written===0)throw new RuntimeException('Feed-Ausgabe konnte nicht gespeichert werden.');$offset+=$written;}
        hash_update($this->hash,$bytes);$this->length+=$length;
    }

    public function seal():void
    {
        if(!is_resource($this->stream)||$this->etag!==null)throw new LogicException('Feed-Ausgabe bereits abgeschlossen.');
        if(!fflush($this->stream)||!rewind($this->stream))throw new RuntimeException('Feed-Ausgabe konnte nicht gelesen werden.');
        $this->etag='"'.hash_final($this->hash).'"';
    }

    public function length():int{$this->ready();return $this->length;}
    public function etag():string{$this->ready();return $this->etag;}
    public function recordCount():int{$this->ready();return $this->records;}
    public function addRecord():void
    {
        if(!is_resource($this->stream)||$this->etag!==null)throw new LogicException('Feed-Ausgabe bereits abgeschlossen.');
        ++$this->records;
    }

    public function contents():string
    {
        $this->ready();if(!rewind($this->stream))throw new RuntimeException('Feed-Ausgabe konnte nicht gelesen werden.');
        $body=stream_get_contents($this->stream);if($body===false||strlen($body)!==$this->length)throw new RuntimeException('Feed-Ausgabe unvollständig.');return $body;
    }

    public function sendBody():void
    {
        $this->ready();if(!rewind($this->stream))throw new RuntimeException('Feed-Ausgabe konnte nicht gelesen werden.');
        $sent=0;while(!feof($this->stream)){$chunk=fread($this->stream,65536);if($chunk===false||($chunk===''&&!feof($this->stream)))throw new RuntimeException('Feed-Ausgabe unvollständig.');$sent+=strlen($chunk);echo $chunk;}
        if($sent!==$this->length)throw new RuntimeException('Feed-Ausgabe unvollständig.');
    }

    private function ready():void
    {
        if(!is_resource($this->stream)||$this->etag===null)throw new LogicException('Feed-Ausgabe noch nicht verfügbar.');
    }
    public function close():void{if(is_resource($this->stream))fclose($this->stream);}
    public function __destruct(){$this->close();}
}
