<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Opaque event/code only. Scanning never grants access or performs a redemption. */
final class EventVoucherQr
{
    public static function payload(string $event,string $code):string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$event)||!preg_match('/^[A-F0-9]{16}$/D',$code))throw new \DomainException('Gutscheincode prüfen.');
        return 'KH:1:'.strtoupper($event).':'.$code;
    }
    public static function searchCode(string $input,string $event):string
    {
        $input=strtoupper(trim($input));
        if(str_starts_with($input,'KH:')){
            if(!preg_match('/^KH:1:([A-F0-9]{32}):([A-F0-9]{16})$/D',$input,$m)||strtolower($m[1])!==$event)throw new \DomainException('Dieser QR-Code gehört nicht zu dieser Veranstaltung.');
            return $m[2];
        }
        return str_replace(['-',' '],'',$input);
    }
    public static function png(string $event,string $code):string
    {
        require_once dirname(__DIR__, 1).'/../voucher-runtime/vendor/autoload.php';
        $matrix=\BaconQrCode\Encoder\Encoder::encode(self::payload($event,$code),\BaconQrCode\Common\ErrorCorrectionLevel::M())->getMatrix();
        // Integer pixels and four modules of quiet zone; grayscale PNG needs only zlib.
        $modules=$matrix->getWidth();$scale=6;$size=($modules+8)*$scale;$raw='';
        for($y=-4;$y<$modules+4;++$y){$line='';for($x=-4;$x<$modules+4;++$x)$line.=str_repeat($x>=0&&$x<$modules&&$y>=0&&$y<$modules&&$matrix->get($x,$y)===1?"\0":"\xff",$scale);$raw.=str_repeat("\0".$line,$scale);}
        $chunk=static fn(string $type,string $data)=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
        return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$size,$size,8,0,0,0,0)).$chunk('IDAT',gzcompress($raw,9)).$chunk('IEND','');
    }
    public static function image(string $event,string $code):string{return 'data:image/png;base64,'.base64_encode(self::png($event,$code));}
}
