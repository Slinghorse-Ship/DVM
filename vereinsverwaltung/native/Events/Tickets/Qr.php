<?php declare(strict_types=1);
namespace Kulturhus\Native\Events\Tickets;

/** QR contains only event and an opaque pass code. Reading a code never performs check-in. */
final class Qr
{
    public static function payload(string $page,string $code):string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$page)||!preg_match('/^[a-f0-9]{32}$/D',$code))throw new \DomainException('Ticket-QR-Code prüfen.');
        return 'DVM:T:1:'.$page.':'.$code;
    }
    public static function parse(string $value,?string $page=null):array
    {
        if($page!==null&&preg_match('/^[a-f0-9]{32}$/D',$page)&&preg_match('/^[a-fA-F0-9]{32}$/D',trim($value)))return ['page_id'=>$page,'code'=>strtolower(trim($value))];
        if(!preg_match('/^DVM:T:1:([a-f0-9]{32}):([a-f0-9]{32})$/D',trim($value),$match))throw new \DomainException('Ticket-QR-Code ungültig.');return ['page_id'=>$match[1],'code'=>$match[2]];
    }
    public static function valuePayload(string $code):string{return 'DVM:V:1:'.self::valueCode($code);}
    public static function valueCode(string $input):string
    {
        $input=strtoupper(trim($input));if(str_starts_with($input,'DVM:V:1:'))$input=substr($input,8);
        if(!preg_match('/^[A-F0-9]{32}$/D',$input))throw new \DomainException('Wertgutschein-QR-Code prüfen.');return $input;
    }
    public static function png(string $page,string $code):string{return self::encode(self::payload($page,$code));}
    public static function valuePng(string $code):string{return self::encode(self::valuePayload($code));}
    private static function encode(string $payload):string
    {
        require_once dirname(__DIR__,3).'/voucher-runtime/vendor/autoload.php';
        $matrix=\BaconQrCode\Encoder\Encoder::encode($payload,\BaconQrCode\Common\ErrorCorrectionLevel::M())->getMatrix();
        $modules=$matrix->getWidth();$scale=6;$size=($modules+8)*$scale;$raw='';
        for($y=-4;$y<$modules+4;$y++){$line='';for($x=-4;$x<$modules+4;$x++)$line.=str_repeat($x>=0&&$y>=0&&$x<$modules&&$y<$modules&&$matrix->get($x,$y)===1?"\0":"\xff",$scale);$raw.=str_repeat("\0".$line,$scale);}
        $chunk=static fn($type,$data)=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
        return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$size,$size,8,0,0,0,0)).$chunk('IDAT',gzcompress($raw,9)).$chunk('IEND','');
    }
}
