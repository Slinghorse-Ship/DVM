<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Cms\{Pages,Media,WebsiteDesign};
use Kulturhus\Native\Shared\{Access,Identity};
use Kulturhus\Native\Web\Html;
/** Current draft in the actual website theme, with no save or publication. */
final class LivePagePreview
{
 public static function render(\PDO $db,Access $access,string $mediaRoot,array $page):string
 {
  $identity=Identity::forClub($db,$access->club());$identity['website_design']=WebsiteDesign::forClub($db,$access->club())['template'];
  $pages=new Pages($db,$access);if($page['id']!==''){$presentation=$pages->presentation($page);if($presentation['theme']!==null)$identity['website_design']=$presentation['theme'];}
  $mediaUrl=static fn(string $id,string $size='medium'):string=>'/media-preview/'.$id.'/'.$size;
  $html=(new DraftPagePreview($pages,new Media($db,$access,$mediaRoot),$identity,'/vereinsverwaltung/?view=website'))->render($page,$mediaUrl);
  return '<section class="panel page-live-preview" data-page-live data-page-live-media="/media-preview/00000000000000000000000000000000/medium"><header><h2>Livevorschau</h2><div role="group" aria-label="Vorschaubreite"><button type="button" class="btn btn-solid theme-primary" data-page-live-width="desktop" aria-pressed="true">Computer</button><button type="button" class="btn btn-outline theme-secondary" data-page-live-width="mobile" aria-pressed="false">Handy</button></div></header><p>Text, Bilder und Inhaltsabschnitte erscheinen sofort im Website-Design. „Entwurf speichern“ übernimmt die gesamte Seite.</p><textarea hidden data-page-live-html>'.Html::e($html).'</textarea><div data-page-live-viewport><iframe title="Livevorschau des Website-Beitrags" sandbox="allow-same-origin"></iframe></div></section>';
 }
}
