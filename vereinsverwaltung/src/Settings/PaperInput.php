<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use Kulturhus\Verein\SettingsSchema;

/** Whole requested layout and upload provenance remain separate from normalization. */
final class PaperInput
{
    public static function initial(array $state):array
    {
        $data=array_replace(SettingsSchema::defaults('paper'),$state['data']);$fields=SettingsSchema::fields('paper');
        foreach($data as$key=>&$value)if(is_int($value)&&$fields[$key][1]!=='checkbox')$value=(string)$value;unset($value);
        return self::capture(['version'=>(string)$state['version'],'data'=>$data,'logo'=>null]);
    }
    public static function capture(array $input):array
    {
        $keys=array_keys($input);sort($keys);$fields=SettingsSchema::fields('paper');$expected=array_keys($fields);sort($expected);
        if($keys!==['data','logo','version']||!is_array($input['data']))throw new DomainException('Vollständiges ursprüngliches Briefpapier prüfen.',422);
        $keys=array_keys($input['data']);sort($keys);if($keys!==$expected)throw new DomainException('Vollständiges ursprüngliches Briefpapier prüfen.',422);
        foreach($input['data']as$key=>$value){
            if($fields[$key][1]==='checkbox'&&(is_int($value)||is_bool($value)))continue;
            if(!is_string($value)||strlen($value)>40000||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new DomainException('Ganzes ursprüngliches Briefpapierfeld prüfen.',422);
        }
        if($input['logo']!==null)self::metadata($input['logo']);
        return ['version'=>(string)GeneralInput::version($input['version']),'data'=>$input['data'],'logo'=>$input['logo']];
    }
    /** Conscious current review retains every editable intention and adopts only its managed source. */
    public static function adoptCurrent(array $input,array $state):array
    {
        $input=self::capture($input);$input['version']=(string)GeneralInput::version($state['version']);
        $input['data']['signature']=$state['data']['signature'];return self::capture($input);
    }
    public static function normalize(array $data):array
    {
        $data=self::capture(['version'=>'0','data'=>$data,'logo'=>null])['data'];$out=[];
        foreach(SettingsSchema::fields('paper')as$key=>[$label,$type,$limit]){
            try{
                $v=match($type){'checkbox'=>SettingsSchema::bool($data[$key]),'integer'=>SettingsSchema::integer($data[$key],...$limit),'select'=>SettingsSchema::choice($data[$key],array_map('strval',array_keys($limit))),default=>GeneralValues::text($data[$key],$limit)};
                if($type==='color'&&!preg_match('/\A#[0-9a-fA-F]{6}\z/D',$v))throw new DomainException('Farbe prüfen.');
                if($type==='asset'&&$v!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$v))throw new DomainException('Ungültiges Bild.');
                if(str_starts_with($key,'footer_'))$v=SettingsSchema::formatted($v,40000);
                $out[$key]=$v;
            }catch(DomainException$failure){throw new DomainException($label.': '.$failure->getMessage(),422,$failure);}
        }
        return$out;
    }
    /** Old callers may omit optional fields and retain the existing canonical sender policy. */
    public static function canonical(array $data):array
    {
        $fields=SettingsSchema::fields('paper');if(array_diff(array_keys($data),array_keys($fields)))throw new DomainException('Unerwartetes Einstellungsfeld.',422);
        foreach($data as$key=>&$value)if(is_int($value)&&$fields[$key][1]!=='checkbox')$value=(string)$value;unset($value);
        return self::normalize(array_replace(SettingsSchema::defaults('paper'),$data));
    }
    public static function metadata(mixed $meta):array
    {
        if(!is_array($meta))throw new DomainException('Ursprüngliche Logodatei prüfen.',422);$keys=array_keys($meta);sort($keys);
        if($keys!==['bytes','height','mime','name','sha256','source','width']||!is_string($meta['name'])||$meta['name']===''||strlen($meta['name'])>240||preg_match('//u',$meta['name'])!==1||preg_match('/[\\\\\/:\x00-\x1f\x7f]/',$meta['name'])||$meta['mime']!=='image/png'||!is_int($meta['bytes'])||$meta['bytes']<1||$meta['bytes']>2097152||!is_string($meta['sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$meta['sha256'])||!is_int($meta['width'])||!is_int($meta['height'])||min($meta['width'],$meta['height'])<10||max($meta['width'],$meta['height'])>4000||$meta['width']*$meta['height']>8000000)throw new DomainException('Ursprüngliche Logodatei prüfen.',422);
        if($meta['source']!==null){
            $s=$meta['source'];if(!is_array($s)||array_keys($s)!==['version','kind','name','bytes','sha256','prepared_sha256']||$s['version']!==1||!in_array($s['kind'],['heif','raw'],true)||!is_string($s['name'])||$s['name']===''||strlen($s['name'])>240||preg_match('//u',$s['name'])!==1||preg_match('/[\\\\\/:\x00-\x1f\x7f]/',$s['name'])||!is_int($s['bytes'])||$s['bytes']<8||$s['bytes']>268435456)throw new DomainException('Gesicherte Kameraherkunft bitte erneut prüfen.',422);
            foreach(['sha256','prepared_sha256']as$key)if(!is_string($s[$key])||!preg_match('/\A[a-f0-9]{64}\z/D',$s[$key]))throw new DomainException('Gesicherte Kameraherkunft bitte erneut prüfen.',422);
        }
        return$meta;
    }
    public static function upload(string $name,string $bytes,?array $source=null):array
    {
        if(strlen($bytes)>2097152)throw new DomainException('Logo als PNG oder JPEG bis 2 MB auswählen.',422);$size=@getimagesizefromstring($bytes);
        if(!$size||!in_array($size['mime'],['image/png','image/jpeg'],true)||min($size[0],$size[1])<10||max($size[0],$size[1])>4000||$size[0]*$size[1]>8000000)throw new DomainException('PNG/JPEG mit 10 bis 4.000 Pixeln und höchstens 8 Megapixeln erforderlich.',422);
        $image=@imagecreatefromstring($bytes);if(!$image)throw new DomainException('Das Bild konnte nicht gelesen werden.',422);
        try{
            if($size['mime']==='image/jpeg')$image=\Kulturhus\Native\Cms\ImageOrientation::applyBytes($image,$bytes);
            $width=imagesx($image);$height=imagesy($image);imagesavealpha($image,true);ob_start();imagepng($image,null,6);$clean=(string)ob_get_clean();
        }finally{unset($image);}
        $meta=self::metadata(['name'=>$name,'mime'=>'image/png','bytes'=>strlen($clean),'sha256'=>hash('sha256',$clean),'width'=>$width,'height'=>$height,'source'=>$source]);
        return['meta'=>$meta,'bytes'=>$clean];
    }
    public static function bytes(array $input,?string $bytes):void
    {
        $input=self::capture($input);
        if($input['logo']===null){if($bytes!==null)throw new DomainException('Ursprüngliche Logodatei prüfen.',422);return;}
        $m=$input['logo'];$size=$bytes!==null?@getimagesizefromstring($bytes):false;
        if($bytes===null||strlen($bytes)!==$m['bytes']||!hash_equals($m['sha256'],hash('sha256',$bytes))||!$size||$size['mime']!==$m['mime']||$size[0]!==$m['width']||$size[1]!==$m['height']||$input['data']['logo']!=='')throw new DomainException('Ursprüngliche Logodatei stimmt nicht mit der erhaltenen Prüfung überein.',409);
    }
}
