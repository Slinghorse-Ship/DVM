<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\Web\Editor;
$blocks=[];
for($i=0;$i<100;$i++)$blocks[]=match($i%5){
    0=>['type'=>'text','text'=>'Eigener ursprünglicher Text '.$i.' 🎶'],
    1=>['type'=>'heading','text'=>'Eigene Überschrift '.$i],
    2=>['type'=>'button','text'=>'Eigener Link '.$i,'url'=>'https://example.invalid/event/'.$i],
    3=>['type'=>'event','title'=>'Eigener Termin '.$i,'date'=>'11. Oktober 2026, 19 Uhr','venue'=>'Eigener Ort','text'=>'Eigene Beschreibung '.$i],
    default=>['type'=>'divider'],
};
$story=[];for($i=0;$i<10;$i++)$story[]=['title'=>'Eigene Folie '.$i,'text'=>'Eigener Folientext '.$i,'media_ref'=>''];
echo '<form id="blocks"><input name="title" value="Eigener Entwurf"><input type="hidden" name="csrf" value="own-test-only">'.Editor::blocks('blocks_json',$blocks).'<button type="submit">Entwurf speichern</button></form>';
echo '<form id="story">'.Editor::story('story_json',$story).'<button type="submit">Story speichern</button></form>';
