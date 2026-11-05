<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

// This helper is loaded only by the owned upload-outcome CLI contract.
$config=getenv('KH_NATIVE_TEST_CONFIG');
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||!is_string($config)||!is_file($config)||(json_decode(file_get_contents($config),true,16,JSON_THROW_ON_ERROR)['owner']??null)!=='isolated-native-ci')throw new \LogicException('Nur eigene isolierte Bildschreibprüfung.');
function imagepng(\GdImage $image,mixed $file=null,int $quality=-1,int $filters=-1):bool
{
    $fault=$GLOBALS['kulturhus_owned_image_write_fault']??null;
    if(is_callable($fault)&&is_string($file)&&$fault($file)===true)return false;
    return \imagepng($image,$file,$quality,$filters);
}
