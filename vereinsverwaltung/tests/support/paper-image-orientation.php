<?php declare(strict_types=1);
/** Two independently visible camera layouts; no file, database or browser decoder. */
$piImage=imagecreatetruecolor(60,24);imagefilledrectangle($piImage,0,0,29,23,imagecolorallocate($piImage,235,15,20));imagefilledrectangle($piImage,30,0,59,23,imagecolorallocate($piImage,15,25,235));ob_start();imagejpeg($piImage,null,98);$piRaw=(string)ob_get_clean();unset($piImage);
foreach([6,2]as$piOrientation){
 $piPayload="Exif\0\0II".pack('vVv',42,8,1).pack('vvVv',0x112,3,1,$piOrientation)."\0\0".pack('N',0);$piJpeg=substr($piRaw,0,2)."\xff\xe1".pack('n',strlen($piPayload)+2).$piPayload.substr($piRaw,2);
 $piOutput=\Kulturhus\Verein\Settings\PaperInput::upload('Own oriented camera logo.jpg',$piJpeg);$piPng=imagecreatefromstring($piOutput['bytes']);$piA=imagecolorsforindex($piPng,imagecolorat($piPng,(int)(imagesx($piPng)*.25),(int)(imagesy($piPng)*.25)));$piB=imagecolorsforindex($piPng,imagecolorat($piPng,(int)(imagesx($piPng)*.75),(int)(imagesy($piPng)*.75)));
 $piCorrect=$piOrientation===6?($piOutput['meta']['width']===24&&$piOutput['meta']['height']===60&&$piA['red']>180&&$piB['blue']>180):($piOutput['meta']['width']===60&&$piOutput['meta']['height']===24&&$piA['blue']>180&&$piB['red']>180);
 $check($piCorrect&&$piOutput['meta']['sha256']===hash('sha256',$piOutput['bytes']),'actual whole camera JPEG logo retains its visible rotated or mirrored pixels and normalized geometry without a temporary file '.$piOrientation);unset($piPng);
}
