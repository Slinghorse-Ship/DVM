<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;
use Kulturhus\Native\TemplateRenderers\{Definition as Renderer,RenderContext};

/** Reviewed, optional website layouts; all content remains in native immutable page revisions. */
final class EditorialLayouts implements Plugin,SourceFiles
{
    public function definition():Definition{return new Definition('editorial-layouts','Website-Darstellungen','1.0.0');}
    public function sourceFiles():array{return [__DIR__.'/editorial-layouts.css'];}
    public function install(Context $context):void{}
    public function upgrade(Context $context,string $previousVersion):void{}
    public function uninstall(Context $context):void{}
    public function initialize(HookScope $hooks,UrlScope $urls,Context $context):void
    {
        $styles=['layout'=>__DIR__.'/editorial-layouts.css'];
        $context->renderers()->register('reading',new Renderer('Leseseite','1.0.0',styles:$styles));
        $context->renderers()->register('cards',new Renderer('Kartenübersicht','1.0.0',layout:'cards',styles:$styles));
        $context->renderers()->register('split',new Renderer('Geteilte Ansicht','1.0.0',layout:'split',styles:$styles));
        $context->renderers()->register('focus',new Renderer('Titel im Fokus','1.0.0',render:static function(RenderContext $view):string{
            return '<article class="template-renderer template-renderer--focus"><header>'.$view->component('title').$view->component('excerpt').'</header><div>'.$view->component('body').$view->component('sections').$view->component('fields').$view->component('documents').$view->component('media').$view->component('listing').'</div></article>';
        },styles:$styles));
    }
}
