<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\FrontendEditor;
use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{Conflict,FrontendEdits,Media,Pages,WebsiteDesign};
use Kulturhus\Native\Integration\Routes;
use Kulturhus\Native\Shared\{Access,Identity};
use Kulturhus\Native\Web\{Articles,Cache,Html,Site};
use PDO;

final class Controller
{
    public static function serve(PDO $db,Access $access,string $mediaRoot,string $csrf,array $query,array $post,string $method):void
    {
        header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: same-origin');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'none'; form-action 'self'");
        header('Content-Type: text/html; charset=utf-8');
        $endpoint=is_string($query['native_endpoint']??null)?$query['native_endpoint']:'';
        $allowed=$endpoint==='frontend-preview'?['GET','HEAD']:['GET','HEAD','POST'];
        if(!in_array($method,$allowed,true)){http_response_code(405);header('Allow: '.implode(', ',$allowed));return;}
        $service=new FrontendEdits($db,$access);$state=null;$submission=null;$field='title';$error='';$conflict=false;$notice='';
        try{
            $id=self::text($query,'item');$state=$service->state($id);
            if($endpoint==='frontend-preview'){
                $version=self::version(self::text($query,'version'));$identity=Identity::forClub($db,$access->club());$identity['website_design']=WebsiteDesign::forClub($db,$access->club())['template'];
                $media=new Media($db,$access,$mediaRoot);$site=new Site(new Pages($db,$access),$media,new Cache(dirname($mediaRoot).'/public-cache'),$identity['origin'],$access->club(),null,new Articles($db,$access,$mediaRoot),Routes::url('website',$id),$identity);
                $html=$site->preview($id,$version,static fn(string $mediaId,string $variant='medium'):string=>Routes::endpoint('media-preview',['media'=>$mediaId,'variant'=>$variant]));
                if($method!=='HEAD')echo $html;return;
            }
            $field=self::text($query,'field')?:'title';FrontendEdits::field($state,$field);
            if($method==='POST'){
                if(array_diff(array_keys($post),['csrf','operation','item','field','version','signature','value','custom_fields','reviewed','review_confirm']))throw new InvalidArgumentException('Nur das ausgewählte Feld bearbeiten.');
                $token=self::text($post,'csrf');if($token===''||!hash_equals($csrf,$token))throw new DomainException('Formular abgelaufen. Seite neu öffnen und erneut versuchen.');
                if(self::text($post,'item')!==$id||self::text($post,'field')!==$field)throw new DomainException('Das Formular gehört nicht zu dieser Seite oder diesem Feld.');
                $version=self::version(self::text($post,'version'));$signature=self::text($post,'signature');$operation=self::text($post,'operation');
                if($operation==='publish'){
                    if(array_key_exists('value',$post)||array_key_exists('custom_fields',$post))throw new InvalidArgumentException('Nur den gespeicherten Entwurf veröffentlichen.');
                    $service->publish($id,$version,$signature,self::text($post,'reviewed'),($post['review_confirm']??null)==='1');
                    header('Location: '.Routes::endpoint('frontend-edit',['item'=>$id,'field'=>$field,'saved'=>'published']),true,303);return;
                }
                if(!in_array($operation,['save','resume'],true))throw new InvalidArgumentException('Bearbeitungsaktion prüfen.');
                if(str_starts_with($field,'custom.')){
                    $key=substr($field,7);$values=$post['custom_fields']??null;
                    if(array_key_exists('value',$post)||!is_array($values)||array_keys($values)!==[$key])throw new InvalidArgumentException('Nur das ausgewählte Vorlagenfeld übertragen.');
                    $value=$values[$key];
                }else{
                    if(array_key_exists('custom_fields',$post)||!array_key_exists('value',$post))throw new InvalidArgumentException('Das ausgewählte Feld vollständig übertragen.');
                    $value=$post['value'];
                }
                $submission=['value'=>$value,'version'=>$version,'signature'=>$signature];
                if($operation==='resume'){
                    // Deliberate local recovery only: display retained input against current original; no save.
                    $submission['version']=$state['page']['version'];$submission['signature']=$state['signature'];
                    $notice='Eingabe übernommen. Aktuellen gespeicherten Entwurf und Feldvorgaben prüfen, dann bewusst speichern.';
                }else{
                    $value=FormValue::read(FrontendEdits::field($state,$field),$value);
                    $service->save($id,$version,$field,$value,$signature);
                    header('Location: '.Routes::endpoint('frontend-edit',['item'=>$id,'field'=>$field,'saved'=>'draft']),true,303);return;
                }
            }elseif(($query['saved']??null)==='draft')$notice='Entwurf gespeichert.';
            elseif(($query['saved']??null)==='published')$notice='Gespeicherten Entwurf veröffentlicht.';
        }catch(Conflict $exception){$error=$exception->getMessage();$conflict=true;http_response_code(409);}
        catch(InvalidArgumentException $exception){$error=$exception->getMessage();http_response_code(422);}
        catch(DomainException|\RuntimeException $exception){$error='Bearbeitung für diesen Zugang nicht verfügbar. Seite neu öffnen oder Berechtigung prüfen.';http_response_code(403);$state=null;}
        if($method==='HEAD')return;
        if($state===null||$endpoint==='frontend-preview'){
            echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bearbeitung nicht verfügbar</title><main><h1>Bearbeitung nicht verfügbar</h1><p role="alert">'.Html::e($error).'</p><a href="'.Html::e(Routes::url('website')).'">Zur Seitenverwaltung</a></main></html>';return;
        }
        try{echo(new View($db,$access,$csrf))->render($state,$field,$submission,$error,$conflict,$notice);}
        catch(DomainException|InvalidArgumentException){http_response_code(422);echo '<main><h1>Eingabe prüfen</h1><p>Das gewählte Feld kann mit dieser Eingabe nicht dargestellt werden.</p><a href="'.Html::e(Routes::endpoint('frontend-edit',['item'=>$state['page']['id']])).'">Bearbeitung neu öffnen</a></main>';}
    }

    private static function text(array $data,string $key):string
    {if(!is_string($data[$key]??''))throw new InvalidArgumentException('Einzelnen Formularwert angeben.');return $data[$key]??'';}
    private static function version(string $value):int
    {if(!preg_match('/\A[1-9][0-9]{0,9}\z/D',$value)||($n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>4294967294]]))===false)throw new InvalidArgumentException('Aktuellen Versionsstand angeben.');return $n;}
}
