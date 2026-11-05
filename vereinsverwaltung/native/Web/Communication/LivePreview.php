<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\Html;
/** Read-only live surface for the current unsaved composition, separate from an approved publication. */
final class LivePreview
{
 public static function render(string $module,array $item,array $identity,array $media):string
 {
  if(!in_array($module,['social','press'],true))return'';
  $sources=[];foreach($media as$m)$sources[$m['id']]=['url'=>'/media-preview/'.$m['id'].'/medium','alt'=>$m['alt']];
  $seed=['module'=>$module,'kind'=>$item['kind'],'title'=>$item['title'],'summary'=>$item['summary']??'','body'=>$item['body']??'','body_html'=>$item['body_html']??'','blocks'=>$item['blocks']??[],'story'=>$item['story']??[],'carousel'=>$item['carousel']??[],'media'=>$sources];
  return '<section class="panel compose-live" data-compose-live><header><h2>Livevorschau</h2><span data-compose-live-kind></span></header><textarea data-compose-live-seed hidden>'.Html::e(json_encode($seed,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'</textarea><article class="compose-live-card"><header><strong>'.Html::e($identity['name']).'</strong></header><div data-compose-live-content></div></article><p class="hint">Text und gewählte Medien aktualisieren sich während der Bearbeitung. Speichern übernimmt den Entwurf.</p></section>';
 }
}
