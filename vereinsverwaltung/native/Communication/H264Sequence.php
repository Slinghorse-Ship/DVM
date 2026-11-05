<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;

/** Bounded bit reader and H.264 sequence dimensions, including pixel cropping. */
final class H264Sequence
{
    private int $position=0;
    public function __construct(private string $data) {}
    public function bits(int $count): int
    {
        if($count<1||$count>32||$this->position+$count>strlen($this->data)*8)throw new DomainException('Videocodec ist unvollständig.');
        $value=0;for($i=0;$i<$count;$i++,$this->position++)$value=($value<<1)|((ord($this->data[intdiv($this->position,8)])>>(7-$this->position%8))&1);return $value;
    }
    private function ue(): int
    { $zeros=0;while($this->bits(1)===0)if(++$zeros>24)throw new DomainException('Videocodec ist unvollständig.');return $zeros ? (1<<$zeros)-1+$this->bits($zeros) : 0; }
    private function se(): int { $n=$this->ue();return $n%2 ? intdiv($n+1,2) : -intdiv($n,2); }
    public static function dimensions(string $nal): array
    {
        $data=preg_replace('/\x00\x00\x03/',"\x00\x00",substr($nal,1));$r=new self($data);$profile=$r->bits(8);$r->bits(8);$r->bits(8);$r->ue();$chroma=1;
        if(in_array($profile,[100,110,122,244,44,83,86,118,128,138,139,134,135],true)){
            $chroma=$r->ue();if($chroma!==1||$r->ue()!==0||$r->ue()!==0)throw new DomainException('Video als H.264 mit 8-Bit-4:2:0 exportieren.');
            $r->bits(1);if($r->bits(1))for($i=0;$i<8;$i++)if($r->bits(1)){$last=$next=8;for($j=0;$j<($i<6?16:64);$j++){if($next)$next=($last+$r->se()+256)%256;$last=$next?:$last;}}
        }
        $r->ue();$order=$r->ue();if($order===0)$r->ue();elseif($order===1){$r->bits(1);$r->se();$r->se();$cycles=$r->ue();if($cycles>255)throw new DomainException('Videocodec prüfen.');for($i=0;$i<$cycles;$i++)$r->se();}elseif($order!==2)throw new DomainException('Videocodec prüfen.');
        $r->ue();$r->bits(1);$width=$r->ue()+1;$height=$r->ue()+1;$frame=$r->bits(1);if(!$frame)throw new DomainException('Video ohne Zeilensprung exportieren.');$r->bits(1);$left=$right=$top=$bottom=0;if($r->bits(1)){ $left=$r->ue();$right=$r->ue();$top=$r->ue();$bottom=$r->ue(); }
        return [$width*16-2*($left+$right),$height*16-2*($top+$bottom)];
    }
}
