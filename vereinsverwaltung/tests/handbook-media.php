<?php declare(strict_types=1);
require __DIR__.'/../src/Infrastructure/Media/HandbookMedia.php';
use Kulturhus\Verein\HandbookMedia;
$cases=[''=>null,'bytes=0-99'=>[0,99],'bytes=500-'=>[500,999],'bytes=-200'=>[800,999],'bytes=900-2000'=>[900,999],'bytes=-2000'=>[0,999]];
foreach($cases as $input=>$expected)if(HandbookMedia::byteRange($input,1000)!==$expected)throw new RuntimeException('Incorrect range '.$input);
foreach(['bytes=1000-','bytes=10-9','bytes=-0','bytes=-','bytes=0-1,5-6','unknown','bytes=9999999999999999999999-'] as $input){
 try{HandbookMedia::byteRange($input,1000);throw new RuntimeException('Accepted invalid range '.$input);}catch(InvalidArgumentException){}
}
echo "PASS 13 media range checks\n";
