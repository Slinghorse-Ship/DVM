<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;

/** Optional, locally shipped example with useful public behavior and no external calls. */
final class WebsiteNotice implements Plugin
{
    public function definition(): Definition
    {
        return new Definition('website-notice','Website-Hinweis','1.0.0',[],[
            'text'=>['type'=>'text','label'=>'Hinweis','max'=>500,'default'=>''],
            'path'=>['type'=>'text','label'=>'Lokale Zieladresse (optional)','max'=>500,'default'=>'','format'=>'local-path'],
            'title_suffix'=>['type'=>'text','label'=>'Ergänzung im Seitentitel (optional)','max'=>80,'default'=>''],
        ]);
    }
    private static function validate(Context $context): void
    {
        if(!Values::localPath($context->configuration['path']))throw new \DomainException('Der Hinweis benötigt eine lokale Zieladresse.');
    }
    public function install(Context $context): void { self::validate($context);$context->put('installed',true); }
    public function upgrade(Context $context,string $previousVersion): void { self::validate($context); }
    public function uninstall(Context $context): void { $context->delete('installed'); }
    public function initialize(HookScope $hooks,UrlScope $urls,Context $context): void
    {
        self::validate($context);$config=$context->configuration;
        $hooks->on('site.title','after',static function(Event $event)use($config):void{
            if($config['title_suffix']!=='')$event->setResult(mb_substr($event->result().' · '.$config['title_suffix'],0,500));
        });
        $hooks->on('site.response','after',static function(Event $event)use($config):void{
            if($config['text']==='')return;$response=$event->result();
            if(($response['status']??200)!==200||!str_contains(strtolower($response['headers']['Content-Type']??'text/html; charset=utf-8'),'text/html')||!preg_match('~</body\s*>~i',$response['body']))return;
            $text=htmlspecialchars($config['text'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            if($config['path']!=='')$text='<a class="btn btn-outline theme-primary py-3 text-wrap" href="'.htmlspecialchars($config['path'],ENT_QUOTES,'UTF-8').'">'.$text.'</a>';
            $notice='<aside class="container py-3 border-top" aria-label="Hinweis">'.$text.'</aside>';
            $response['body']=preg_replace_callback('~</body\s*>~i',static fn(array $match):string=>$notice.'</body>',$response['body'],1);$event->setResult($response);
        });
        $urls->register('',static fn(array $arguments):array=>['status'=>200,'headers'=>['Content-Type'=>'application/json; charset=utf-8','Cache-Control'=>'no-store'],'body'=>json_encode(['text'=>$config['text'],'path'=>$config['path']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    }
}
