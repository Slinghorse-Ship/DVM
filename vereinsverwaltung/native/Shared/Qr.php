<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;
/** Real QR encoding; no remote QR service. Four-module quiet zone is mandatory. */
final class Qr
{
    public static function png(string $payload,int $scale=5):string
    {
        if($payload===''||strlen($payload)>2000||$scale<1||$scale>10)throw new \DomainException('QR-Inhalt prüfen.');
        require_once dirname(__DIR__,2).'/voucher-runtime/vendor/autoload.php';
        $m=\BaconQrCode\Encoder\Encoder::encode($payload,\BaconQrCode\Common\ErrorCorrectionLevel::M())->getMatrix();$n=$m->getWidth();$size=($n+8)*$scale;$raw='';
        for($y=-4;$y<$n+4;$y++){$line='';for($x=-4;$x<$n+4;$x++)$line.=str_repeat($x>=0&&$x<$n&&$y>=0&&$y<$n&&$m->get($x,$y)===1?"\0":"\xff",$scale);$raw.=str_repeat("\0".$line,$scale);}
        $chunk=static fn($t,$d)=>pack('N',strlen($d)).$t.$d.pack('N',crc32($t.$d));return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$size,$size,8,0,0,0,0)).$chunk('IDAT',gzcompress($raw,9)).$chunk('IEND','');
    }
}
