<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** Targeted edits of one current original. All validation and history remain in Pages. */
final class FrontendEdits
{
    use ClubPublicationLock;
    public function __construct(private PDO $db,private Access $access) {}

    /** Internal server-side view model, never a JSON representation of the private original. */
    public function state(string $id):array
    {
        $this->access->require('website','edit');
        $pages=new Pages($this->db,$this->access);$page=$pages->get($id);
        TemplatePolicy::assertCapability($this->db,$this->access,$id,'edit');
        if($page['trashed']||$page['archived'])throw new DomainException('Seite zuerst zurück in die Bearbeitung holen.');
        $model=new DataModel($this->db,$this->access);$template=$model->template($page['template']);$definitions=$pages->editorDefinitions($id,$page['template']);
        $states=FieldConditions::states($definitions,$page['custom_fields']??[]);
        $fields=[
            'title'=>['key'=>'title','label'=>'Titel','type'=>'text','required'=>true,'min'=>null,'max'=>200],
            'excerpt'=>['key'=>'excerpt','label'=>'Kurztext','type'=>'textarea','required'=>false,'min'=>null,'max'=>1200],
            'body_html'=>['key'=>'body_html','label'=>'Inhalt','type'=>'richtext','required'=>false,'min'=>null,'max'=>100000],
        ];
        foreach($definitions as $key=>$field){
            if(($field['display']??'visible')!=='visible'||!($states[$key]['visible']??true)||$field['type']==='password')continue;
            $field['required']=$states[$key]['required'];$fields['custom.'.$key]=$field;
        }
        $canPublish=true;
        try{TemplatePolicy::assertCapability($this->db,$this->access,$id,'publish');}catch(DomainException){$canPublish=false;}
        return ['page'=>$page,'fields'=>$fields,'signature'=>self::digest([$template,$definitions]),'reviewed'=>self::digest($page),'can_publish'=>$canPublish];
    }

    public static function value(array $state,string $field):mixed
    {
        self::field($state,$field);
        if(str_starts_with($field,'custom.'))return $state['page']['custom_fields'][substr($field,7)]??null;
        if($field==='body_html'&&empty($state['page']['body_html']))return '<p>'.str_replace(["\r\n","\r","\n"],'<br>',htmlspecialchars($state['page']['body']??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</p>';
        return $state['page'][$field]??'';
    }

    public static function field(array $state,string $field):array
    {
        if(!isset($state['fields'][$field]))throw new DomainException('Dieses Feld ist in der aktuellen Vorlage nicht zur Bearbeitung verfügbar.');
        return $state['fields'][$field];
    }

    public function save(string $id,int $version,string $field,mixed $value,string $signature):array
    {
        return Db::atomic($this->db,function()use($id,$version,$field,$value,$signature):array{
            $this->access->require('website','edit');$this->lockClub();$state=$this->state($id);$this->expect($state,$version,$signature);self::field($state,$field);
            $payload=$state['page'];
            if(str_starts_with($field,'custom.'))$payload['custom_fields'][substr($field,7)]=$value;
            else{
                if(!is_string($value))throw new InvalidArgumentException('Text für das gewählte Feld angeben.');
                $payload[$field]=$value;
                if($field==='body_html')$payload['body']=''; // Clearing HTML must also clear its legacy plain-text fallback.
            }
            return (new Pages($this->db,$this->access))->save($payload,$version);
        });
    }

    /** Explicitly reviewed complete saved draft; never implicitly publishes a field submission. */
    public function publish(string $id,int $version,string $signature,string $reviewed,bool $confirmed):array
    {
        return Db::atomic($this->db,function()use($id,$version,$signature,$reviewed,$confirmed):array{
            $this->access->require('website','publish');$this->lockClub();$state=$this->state($id);$this->expect($state,$version,$signature);
            if(!$confirmed)throw new InvalidArgumentException('Gesamten gespeicherten Entwurf vor dem Veröffentlichen bestätigen.');
            if(!preg_match('/\A[a-f0-9]{64}\z/D',$reviewed)||!hash_equals($state['reviewed'],$reviewed))throw new Conflict('Die angezeigte Vorschau ist nicht mehr aktuell. Aktuellen Entwurf prüfen.');
            if(!$state['page']['has_unpublished_changes'])throw new Conflict('Diese Fassung ist bereits veröffentlicht.');
            return (new Pages($this->db,$this->access))->publish($id,$version);
        });
    }

    private function expect(array $state,int $version,string $signature):void
    {
        if($version<1||$version!==$state['page']['version'])throw new Conflict('Die Seite wurde inzwischen geändert. Ihre Eingabe ist erhalten. Aktuellen Entwurf prüfen und die Eingabe bewusst übernehmen.');
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$signature)||!hash_equals($state['signature'],$signature))throw new Conflict('Die Vorlage oder ihre Felder wurden geändert. Ihre Eingabe ist erhalten. Aktuelle Feldvorgaben prüfen und bewusst übernehmen.');
    }

    private static function digest(array $data):string
    {return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
}
