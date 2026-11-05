<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/native/bootstrap.php';
echo Kulturhus\Native\Web\ImageEditor::render(
    ['id'=>str_repeat('a',32),'version'=>1,'kind'=>'post','title'=>'Kulturabend','summary'=>'Eigene Bilder','story'=>[]],
    ['name'=>'Synthetischer Verein','logo_id'=>'','version'=>1],[],[],'synthetic-csrf',false
);
