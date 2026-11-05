<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;

/** Bounded ISO-BMFF inspection for the exact locally rendered reel profile. */
final class ReelFile
{
    public const MAX_BYTES = 104857600;
    private int $boxesLeft = 4096;
    private function __construct(private mixed $handle, private int $size) {}

    public static function inspect(string $path): array
    {
        if (is_link($path) || !is_file($path)) throw new DomainException('Eigene MP4-Datei auswählen.');
        $handle = @fopen($path, 'rb');
        if (!$handle) throw new DomainException('MP4-Datei ist nicht lesbar.');
        try {
            $stat = fstat($handle);
            if (!$stat || $stat['size'] < 32 || $stat['size'] > self::MAX_BYTES) throw new DomainException('MP4-Datei muss zwischen 32 Bytes und 100 MiB liegen.');
            return (new self($handle, $stat['size']))->movie();
        } finally { fclose($handle); }
    }

    private function movie(): array
    {
        $root = $this->boxes(0, $this->size);
        $brand = $this->one($root, 'ftyp');
        $body = $this->read($brand['data'], $brand['end'] - $brand['data'], 4096);
        if (strlen($body) < 8 || strlen($body) % 4 !== 0 || !in_array(substr($body, 0, 4), ['isom','iso2','iso5','iso6','mp41','mp42','avc1','M4V '], true)) $this->invalid();
        foreach ($root as $box) if (in_array($box['type'], ['moof','mfra'], true)) $this->invalid();
        $mdat = array_values(array_filter($root, static fn($b) => $b['type'] === 'mdat'));
        if (!$mdat) $this->invalid();
        $movie = $this->one($root, 'moov');
        $children = $this->boxes($movie['data'], $movie['end']);
        $tracks = array_values(array_filter($children, static fn($b) => $b['type'] === 'trak'));
        if (!$tracks || count($tracks) > 2) $this->invalid();
        $video = $audio = null; $spans = [];
        foreach ($tracks as $track) {
            [$kind, $info, $ranges] = $this->track($track, $mdat);
            if ($kind === 'vide') { if ($video !== null) $this->invalid(); $video = $info; }
            elseif ($kind === 'soun') { if ($audio !== null) $this->invalid(); $audio = $info; }
            else $this->invalid();
            array_push($spans, ...$ranges);
        }
        if (!$video || $video['width'] !== 1080 || $video['height'] !== 1920 || abs($video['fps'] - 30) > 0.01 || (int)round($video['seconds']*1000) < 3000 || (int)round($video['seconds']*1000) > 90000) throw new DomainException('Reel als 1080 × 1920, 30 Bilder/s und 3–90 Sekunden exportieren.');
        if ($audio && ($audio['sample_rate'] !== 48000 || $audio['channels'] !== 2 || abs($audio['seconds'] - $video['seconds']) > .15)) throw new DomainException('Originalton als AAC mit 48 kHz und zwei Kanälen exportieren.');
        usort($spans, static fn($a,$b) => $a[0] <=> $b[0]);
        $previous = 0;
        foreach ($spans as [$begin,$end]) { if ($begin < $previous) $this->invalid(); $previous = $end; }
        return ['mime'=>'video/mp4', 'codec'=>'h264', 'width'=>1080, 'height'=>1920,
            'duration_ms'=>(int)round($video['seconds']*1000), 'frames'=>$video['frames'], 'fps'=>30.0,
            'audio'=>$audio ? ['codec'=>'aac','sample_rate'=>48000,'channels'=>2] : null, 'bytes'=>$this->size];
    }

    private function track(array $track, array $mdat): array
    {
        $children = $this->boxes($track['data'], $track['end']);
        $mdia = $this->one($children, 'mdia');
        $media = $this->boxes($mdia['data'], $mdia['end']);
        $handler = $this->one($media, 'hdlr');
        $this->payload($handler,12);
        $kind = substr($this->read($handler['data'], 12), 8, 4);
        if (!in_array($kind, ['vide','soun'], true)) $this->invalid();
        $mdhd = $this->one($media, 'mdhd');
        $this->payload($mdhd,1);
        $version = ord($this->read($mdhd['data'], 1));
        if (!in_array($version,[0,1],true)) $this->invalid();
        $this->payload($mdhd,$version?36:24);
        $scale = $this->u32($mdhd['data'] + ($version ? 20 : 12));
        $duration = $version ? $this->u64($mdhd['data']+24) : $this->u32($mdhd['data']+16);
        if ($scale < 1 || $scale > 1000000 || $duration < 1) $this->invalid();
        $minf = $this->one($media, 'minf');
        $stbl = $this->one($this->boxes($minf['data'],$minf['end']), 'stbl');
        $samples = $this->boxes($stbl['data'], $stbl['end']);
        $stsd = $this->one($samples, 'stsd'); $this->payload($stsd,8);
        if ($this->u32($stsd['data']+4) !== 1) $this->invalid();
        $entries = $this->boxes($stsd['data']+8,$stsd['end']);
        if (count($entries) !== 1) $this->invalid();
        $entry = $entries[0];
        $info = $kind === 'vide' ? $this->video($entry, $this->one($children,'tkhd')) : $this->audio($entry);
        $stts = $this->one($samples, 'stts'); $this->payload($stts,8);
        $count = $this->u32($stts['data']+4);
        if (!$count || $count > 10000 || $stts['end']-$stts['data'] !== 8+$count*8) $this->invalid();
        $ticks = $frames = 0;
        for ($i=0;$i<$count;$i++) {
            $amount=$this->u32($stts['data']+8+$i*8); $delta=$this->u32($stts['data']+12+$i*8);
            if (!$amount || !$delta || $amount > 10000 || $delta > $scale*2) $this->invalid();
            $frames += $amount; $ticks += $amount*$delta;
            if ($frames>10000 || $ticks>$scale*91) $this->invalid();
        }
        if ($ticks !== $duration) $this->invalid();
        $info['seconds']=$ticks/$scale; $info['frames']=$frames; $info['fps']=$frames*$scale/$ticks;
        $sizes=$this->sampleSizes($this->one($samples,'stsz'),$frames);
        $offsets=$this->chunkOffsets($samples);
        $stsc=$this->one($samples,'stsc'); $this->payload($stsc,8); $runs=$this->u32($stsc['data']+4);
        if (!$runs || $runs>count($offsets) || $stsc['end']-$stsc['data']!==8+$runs*12) $this->invalid();
        $mapping=[]; $previous=0;
        for ($i=0;$i<$runs;$i++) {
            $first=$this->u32($stsc['data']+8+$i*12); $amount=$this->u32($stsc['data']+12+$i*12);
            if ($first<1 || $first<=$previous || $first>count($offsets) || !$amount || $amount>$frames || $this->u32($stsc['data']+16+$i*12)!==1) $this->invalid();
            $mapping[]=[$first,$amount]; $previous=$first;
        }
        if ($mapping[0][0]!==1) $this->invalid();
        $index=$run=0; $ranges=[];
        foreach ($offsets as $chunk=>$offset) {
            while (isset($mapping[$run+1]) && $mapping[$run+1][0]<=$chunk+1) $run++;
            $begin=$offset;
            for ($sample=0;$sample<$mapping[$run][1];$sample++) {
                if (!isset($sizes[$index])) $this->invalid();
                $end=$offset+$sizes[$index++]; $within=false;
                foreach ($mdat as $data) if ($offset>=$data['data'] && $end<=$data['end']) $within=true;
                if (!$within) $this->invalid();
                if ($kind==='vide') $this->nalUnits($offset,$end,$info['nal_size']);
                $offset=$end;
            }
            $ranges[]=[$begin,$offset];
        }
        if ($index!==$frames) $this->invalid();
        return [$kind,$info,$ranges];
    }

    private function video(array $entry, array $tkhd): array
    {
        $this->payload($tkhd,1);
        if ($entry['type']!=='avc1' || $entry['end']-$entry['data']<78) $this->invalid();
        $width=$this->u16($entry['data']+24); $height=$this->u16($entry['data']+26);
        if ($width!==1080 || $height!==1920) throw new DomainException('Reel zuerst ins Hochformat 1080 × 1920 exportieren.');
        $version=ord($this->read($tkhd['data'],1)); if (!in_array($version,[0,1],true)) $this->invalid();
        $matrix=$tkhd['data']+($version ? 52 : 40);
        if ($tkhd['end']-$matrix<44) $this->invalid();
        $expected=[65536,0,0,0,65536,0,0,0,1073741824];
        foreach ($expected as $i=>$value) if ($this->u32($matrix+$i*4)!==$value) throw new DomainException('Videodrehung vor dem Speichern in die Pixel übernehmen.');
        if ($this->u32($matrix+36)!==$width*65536 || $this->u32($matrix+40)!==$height*65536) $this->invalid();
        $avc=$this->one($this->boxes($entry['data']+78,$entry['end']),'avcC');
        $data=$this->read($avc['data'],$avc['end']-$avc['data'],65536);
        if (strlen($data)<7 || ord($data[0])!==1 || !in_array(ord($data[1]),[66,77,100],true)) $this->invalid();
        if((ord($data[4])&252)!==252 || (ord($data[5])&224)!==224)$this->invalid();
        $length=(ord($data[4])&3)+1; if ($length===3) $this->invalid();
        $position=6; $sps=ord($data[5])&31; if (!$sps) $this->invalid();
        for ($i=0;$i<$sps;$i++) {
            $nal=$this->parameter($data,$position);
            if ((ord($nal[0])&31)!==7 || substr($nal,1,3)!==substr($data,1,3) || H264Sequence::dimensions($nal)!==[$width,$height]) $this->invalid();
        }
        if (!isset($data[$position])) $this->invalid();
        $pps=ord($data[$position++]); if (!$pps) $this->invalid();
        for ($i=0;$i<$pps;$i++) if ((ord($this->parameter($data,$position)[0])&31)!==8) $this->invalid();
        return ['codec'=>'h264','width'=>$width,'height'=>$height,'nal_size'=>$length];
    }

    private function parameter(string $data,int &$position): string
    {
        if ($position+2>strlen($data)) $this->invalid();
        $length=unpack('n',substr($data,$position,2))[1]; $position+=2;
        if ($length<2 || $length>4096 || $position+$length>strlen($data)) $this->invalid();
        $nal=substr($data,$position,$length); $position+=$length; return $nal;
    }

    private function audio(array $entry): array
    {
        if ($entry['type']!=='mp4a' || $entry['end']-$entry['data']<28 || $this->u16($entry['data']+8)!==0) $this->invalid();
        $channels=$this->u16($entry['data']+16); $rate=$this->u32($entry['data']+24)>>16;
        $esds=$this->one($this->boxes($entry['data']+28,$entry['end']),'esds');
        $this->payload($esds,4);
        $data=$this->read($esds['data']+4,$esds['end']-$esds['data']-4,4096);
        $position=0; [$tag,$start,$end]=$this->descriptor($data,$position,strlen($data));
        if ($tag!==3 || $start+3>$end) $this->invalid();
        $flags=ord($data[$start+2]); $position=$start+3;
        if ($flags&128) $position+=2;
        if ($flags&64) { if ($position>=$end) $this->invalid(); $position+=1+ord($data[$position]); }
        if ($flags&32) $position+=2;
        $asc=null;
        while ($position<$end) {
            [$tag,$start,$stop]=$this->descriptor($data,$position,$end);
            if ($tag===4) {
                if ($stop-$start<13 || ord($data[$start])!==64) $this->invalid();
                $inside=$start+13;
                while ($inside<$stop) {
                    [$child,$begin,$finish]=$this->descriptor($data,$inside,$stop);
                    if ($child===5) { if ($asc!==null) $this->invalid(); $asc=substr($data,$begin,$finish-$begin); }
                }
            }
        }
        if ($asc===null || strlen($asc)<2) $this->invalid();
        $bits=new H264Sequence($asc); $object=$bits->bits(5); $frequency=$bits->bits(4);
        $rates=[96000,88200,64000,48000,44100,32000,24000,22050,16000,12000,11025,8000,7350];
        $actual=$frequency===15 ? $bits->bits(24) : ($rates[$frequency]??0); $actualChannels=$bits->bits(4);
        if ($object!==2 || $actual!==$rate || $actualChannels!==$channels || $rate!==48000 || $channels!==2) $this->invalid();
        return ['codec'=>'aac','sample_rate'=>$rate,'channels'=>$channels];
    }

    private function descriptor(string $data,int &$position,int $limit): array
    {
        if ($position>=$limit) $this->invalid();
        $tag=ord($data[$position++]); $length=0;
        for ($i=0;$i<4;$i++) {
            if ($position>=$limit) $this->invalid();
            $byte=ord($data[$position++]); $length=($length<<7)|($byte&127);
            if (!($byte&128)) break;
            if ($i===3) $this->invalid();
        }
        $start=$position; $end=$start+$length; if ($end>$limit) $this->invalid(); $position=$end;
        return [$tag,$start,$end];
    }

    private function sampleSizes(array $box,int $frames): array
    {
        $this->payload($box,12); $uniform=$this->u32($box['data']+4); $count=$this->u32($box['data']+8);
        if ($count!==$frames || $box['end']-$box['data']!==12+($uniform?0:$count*4)) $this->invalid();
        $sizes=[]; $sum=0;
        for ($i=0;$i<$count;$i++) {
            $size=$uniform?:$this->u32($box['data']+12+$i*4);
            if (!$size || $size>8*1048576 || ($sum+=$size)>self::MAX_BYTES) $this->invalid();
            $sizes[]=$size;
        }
        return $sizes;
    }

    private function chunkOffsets(array $boxes): array
    {
        $found=array_values(array_filter($boxes,static fn($b)=>in_array($b['type'],['stco','co64'],true)));
        if (count($found)!==1) $this->invalid(); $box=$found[0]; $this->payload($box,8); $width=$box['type']==='stco'?4:8;
        $count=$this->u32($box['data']+4);
        if (!$count || $count>10000 || $box['end']-$box['data']!==8+$count*$width) $this->invalid();
        $offsets=[];
        for ($i=0;$i<$count;$i++) $offsets[]=$width===4 ? $this->u32($box['data']+8+$i*4) : $this->u64($box['data']+8+$i*8);
        return $offsets;
    }

    private function nalUnits(int $begin,int $end,int $lengthSize): void
    {
        $vcl=false; $count=0;
        while ($begin<$end) {
            if (++$count>64 || $begin+$lengthSize>=$end) $this->invalid();
            $length=0; foreach (str_split($this->read($begin,$lengthSize)) as $byte) $length=($length<<8)|ord($byte);
            $begin+=$lengthSize; if (!$length || $begin+$length>$end) $this->invalid();
            $header=ord($this->read($begin,1)); $type=$header&31;
            if (($header&128) || !in_array($type,[1,5,6,7,8,9],true)) $this->invalid();
            if (in_array($type,[1,5],true)) $vcl=true;
            if ($type===7 && H264Sequence::dimensions($this->read($begin,$length,4096))!==[1080,1920]) $this->invalid();
            $begin+=$length;
        }
        if (!$vcl) $this->invalid();
    }

    private function boxes(int $begin,int $end): array
    {
        $boxes=[];
        while ($begin<$end) {
            if (--$this->boxesLeft<0 || $end-$begin<8) $this->invalid();
            $length=$this->u32($begin); $type=$this->read($begin+4,4); $header=8;
            if ($length===1) { if ($end-$begin<16) $this->invalid(); $length=$this->u64($begin+8); $header=16; }
            elseif ($length===0) $length=$end-$begin;
            if ($length<$header || $length>$end-$begin) $this->invalid();
            $boxes[]=['type'=>$type,'data'=>$begin+$header,'end'=>$begin+$length]; $begin+=$length;
        }
        return $boxes;
    }
    private function one(array $boxes,string $type): array
    { $found=array_values(array_filter($boxes,static fn($b)=>$b['type']===$type)); if(count($found)!==1)$this->invalid(); return $found[0]; }
    private function payload(array $box,int $minimum): void
    { if($box['end']-$box['data']<$minimum)$this->invalid(); }
    private function read(int $offset,int $length,int $maximum=262144): string
    { if($offset<0||$length<0||$length>$maximum||$offset>$this->size-$length||fseek($this->handle,$offset)!==0)$this->invalid();$data=$length?fread($this->handle,$length):'';if($data===false||strlen($data)!==$length)$this->invalid();return $data; }
    private function u16(int $offset): int { return unpack('n',$this->read($offset,2))[1]; }
    private function u32(int $offset): int { return unpack('N',$this->read($offset,4))[1]; }
    private function u64(int $offset): int
    { $high=$this->u32($offset);$low=$this->u32($offset+4);if($high>intdiv(PHP_INT_MAX,4294967296))$this->invalid();return $high*4294967296+$low; }
    private function invalid(): never { throw new DomainException('MP4-Videoformat und tatsächliche Mediendaten prüfen.'); }
}
