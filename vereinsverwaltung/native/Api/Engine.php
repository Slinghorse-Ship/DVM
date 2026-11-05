<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Cms\{DataModel,FieldPolicy,Pages,PageTrash,Media,MediaAvailability,MediaArchive,MediaMetadata,Templates,WebsiteDocuments,UploadTransaction};

/** Explicit v1 resources over the original domain services, never arbitrary SQL/class calls. */
final class Engine
{
    private const PAGE_FIELDS=['title','slug','template','body','body_html','excerpt','seo_title','seo_description','noindex','nav_visible','nav_label','nav_order','parent_id','media_ids','gallery_items','event_start','event_end','event_timezone','event_location','event_address','event_status','event_ticket_url','event_reference_url','event_price','news_date','news_channels','news_review','content_sections','document_links','home_layout','custom_fields','page_policy'];
    private const FIELD_FIELDS=['key','label','type','required','min','max','options','fields','show_when','required_when','multiple','max_items','access','fieldtype','inputfield'];
    private const TEMPLATE_FIELDS=['key','label','renderer','fields','groups','policy','field_contexts','url_policy','frontend_renderer'];
    private const ROLE_FIELDS=['name','description','members_access','finance_access','settings_access','inventory_access','loans_access','documents_access','press_access','minutes_access','website_custom','website_levels'];
    public function __construct(private readonly \PDO $db,private readonly Context $context,private readonly string $mediaRoot,private readonly string $documentRoot){}
    private function ok(mixed $data,array $meta=[],int $status=200):array{return ['status'=>$status,'data'=>$data,'meta'=>$meta];}
    private function pages():Pages{return new Pages($this->db,$this->context->access);}
    private function model():DataModel{return new DataModel($this->db,$this->context->access);}
    private function pageDto(array $page):array{return FieldPolicy::privatePayload($this->db,$this->context->access,$page);}
    private function ownPage(string $id):array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!Db::run($this->db,'SELECT 1 FROM khcms_pages WHERE id=? AND club_id=?',[$id,$this->context->access->club()])->fetchColumn())throw Problem::missing();return $this->pages()->get($id);
    }
    private function ownFile(string $id,string $kind='image'):void
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw Problem::missing();$club=$this->context->access->club();
        $sql=$kind==='document'?'SELECT 1 FROM khcms_documents WHERE id=? AND club_id=?':'SELECT 1 FROM khcms_media m WHERE m.id=? AND m.club_id=? AND '.MediaAvailability::activeSql($this->db);
        if(!Db::run($this->db,$sql,[$id,$club])->fetchColumn())throw Problem::missing();
    }
    private function roles():\Kulturhus\Verein\Settings{return new \Kulturhus\Verein\Settings($this->db,$this->context->actor());}
    private function roleDto(array $row):array
    {
        $row['website_custom']=$row['website_access']!==null;$row['website_levels']=[];
        if($row['website_custom'])$row['website_levels']=json_decode($row['website_access'],true,16,JSON_THROW_ON_ERROR);
        else foreach(array_keys(\Kulturhus\Verein\EditorialPolicy::AREAS) as $area)$row['website_levels'][$area]=\Kulturhus\Verein\EditorialPolicy::level($row,$area);
        unset($row['club_id'],$row['website_access']);$row['version']=(int)$row['version'];$row['settings_access']=(bool)$row['settings_access'];return $row;
    }
    private function role(string $id):array{try{return $this->roleDto((new \Kulturhus\Verein\RoleCatalogue($this->db,$this->context->actor()))->role($id));}catch(\DomainException){throw Problem::missing();}}
    private function account(string $id):array
    {
        $this->context->access->require('accounts','manage');$row=Db::run($this->db,'SELECT a.id,a.actor_id,a.email,a.login_name,a.active,a.verified,a.suspended,a.version,(a.totp_secret IS NOT NULL) AS totp_enabled,a.email_factor,u.name FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id WHERE a.id=? AND u.club_id=?',[$id,$this->context->access->club()])->fetch();
        if(!$row)throw Problem::missing();foreach(['active','verified','suspended','totp_enabled','email_factor'] as $key)$row[$key]=(bool)$row[$key];$row['version']=(int)$row['version'];return $row;
    }
    private function definition(string $kind,string $key):array
    {
        if(!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$key))throw Problem::missing();
        try{return $kind==='fields'?$this->model()->field($key):$this->model()->template($key);}catch(\PDOException $error){throw $error;}catch(\RuntimeException){throw Problem::missing();}
    }
    /** Metadata follows current and captured rules, including condition literals and children. */
    private function definitionDto(string $kind,array $definition,bool $mutation=false):array
    {
        if($kind==='templates')return FieldPolicy::modelTemplate($this->db,$this->context->access,$definition);
        $definitions=array_column($this->model()->fields(),null,'key');$definitions[$definition['key']]=$definition;
        $readable=FieldPolicy::modelDefinitions($this->db,$this->context->access,$definitions);
        if(isset($readable[$definition['key']]))return $readable[$definition['key']];
        if($mutation)return ['key'=>$definition['key'],'version'=>(int)$definition['version'],'restricted'=>true];
        throw Problem::missing();
    }
    public function dispatch(string $method,string $path,array $query=[],array $body=[],string $idempotency=''):array|Download
    {
        if(strlen($path)>512||preg_match('/[%\\\\\x00-\x20?#]/',$path)||!str_starts_with($path,'/api/v1/'))throw Problem::missing();
        if(!in_array($method,['GET','HEAD','POST','PUT','DELETE'],true))throw new Problem('method_not_allowed',405,'Diese HTTP-Methode gehört nicht zum API-Vertrag.');
        $readMethod=$method==='GET'||$method==='HEAD';$segments=explode('/',trim(substr($path,8),'/'));$resource=$segments[0]??'';$id=$segments[1]??null;$action=$segments[2]??null;
        if($resource==='files'&&in_array($id,['assets','revisions'],true))return (new FilesController($this->db,$this->context,$this->mediaRoot))->dispatch($method,$path,$query,$body,$idempotency);
        if($resource==='pages'&&$action==='translations'&&isset($segments[3])&&count($segments)<=5)return $this->translation($method,$path,$id,$segments[3],$segments[4]??null,$query,$body,$idempotency);
        if(count($segments)>3||!in_array($resource,['pages','fields','templates','users','roles','permissions','files'],true))throw Problem::missing();
        if($readMethod){if($body)throw Problem::invalid();return Db::atomic($this->db,function()use($resource,$id,$action,$query):array|Download{$result=$this->read($resource,$id,$action,$query);$this->context->active();return $result;});}
        if($resource==='pages'&&$id==='query'&&$action===null&&$method==='POST'){
            $this->context->require('pages.read');Input::keys($body,['selectors','expression','order','page','limit']);Input::keys($query,[]);$page=Input::page(array_intersect_key($body,array_flip(['page','limit'])));
            if(array_key_exists('expression',$body)&&array_key_exists('selectors',$body))throw Problem::invalid();
            $selectors=array_key_exists('expression',$body)?\Kulturhus\Native\Cms\SelectorGrammar::parse(Input::text($body,'expression',16384,false)):($body['selectors']??[]);$order=$body['order']??[];if(!is_array($selectors)||!is_array($order))throw Problem::invalid();
            return Db::atomic($this->db,function()use($selectors,$page,$order):array{$rows=$this->pages()->searchBounded($selectors,$page['limit'],$page['offset'],$order,8*1024*1024);$total=$this->pages()->count($selectors);$this->context->require('pages.read');return $this->ok(array_map($this->pageDto(...),$rows),Input::pagination($total,$page));});
        }
        Input::keys($query,[]);$scope=match($resource){'pages'=>$method==='DELETE'||in_array($action,['publish','unpublish','trash'],true)?'pages.publish':'pages.write','fields','templates'=>'schema.write','users'=>'users.write','roles'=>'roles.write','files'=>'files.write',default=>throw new Problem('method_not_allowed',405,'Für diese Ressource gibt es keinen Schreibvertrag.')};
        $this->context->require($scope);
        if($resource==='users'&&$action==='role')$this->context->require('roles.write');
        $beforeGuard=null;if($resource==='roles'||($resource==='users'&&$action==='role')){$club=$this->context->club();$beforeGuard=fn()=>\Kulturhus\Verein\RoleAssignments::lockClub($this->db,$club);}
        $result=(new Operations($this->db,$this->context,Factory::operationKey()))->run($idempotency,$method,$path,$body,function()use($resource,$id,$action,$method,$body,$scope):array{
            $this->context->require($scope);return $this->write($resource,$id,$action,$method,$body);
        },$beforeGuard);
        // A retry retains its committed result but must obey today's read/ACL
        // authority, including a role or template restriction changed since then.
        if($resource==='pages'){$this->ownPage($result['data']['id']);$result['data']=$this->pageDto($result['data']);}
        elseif($resource==='fields'||$resource==='templates')$result['data']=$this->definitionDto($resource,$result['data'],true);
        return $result;
    }
    private function read(string $resource,?string $id,?string $action,array $query):array|Download
    {
        $scope=match($resource){'pages'=>'pages.read','fields','templates'=>'schema.read','users'=>'users.read','roles'=>'roles.read','permissions'=>'permissions.read','files'=>'files.read'};$this->context->require($scope);
        if($resource==='permissions'){
            if($id!==null||$action!==null||$query)throw Problem::invalid();return $this->ok(['scopes'=>$this->context->allowedScopes(),'authentication'=>$this->context->mode]);
        }
        if($resource==='pages'){
            if($id!==null){$page=$this->ownPage($id);if($action==='versions'){
                $pagination=Input::page($query);$rows=Db::run($this->db,'SELECT revision AS version,action,created_at,payload_json FROM khcms_revisions WHERE page_id=? AND club_id=? ORDER BY revision DESC LIMIT '.$pagination['limit'].' OFFSET '.$pagination['offset'],[$id,$this->context->access->club()])->fetchAll();
                $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM khcms_revisions WHERE page_id=? AND club_id=?',[$id,$this->context->access->club()])->fetchColumn();foreach($rows as &$row){$row['version']=(int)$row['version'];$row['payload']=FieldPolicy::privatePayload($this->db,$this->context->access,json_decode($row['payload_json'],true,128,JSON_THROW_ON_ERROR));unset($row['payload_json']);}unset($row);return $this->ok($rows,Input::pagination($total,$pagination));
            }if($action!==null||$query)throw Problem::invalid();return $this->ok($this->pageDto($page));}
            $pagination=Input::page($query);$rows=$this->pages()->searchBounded([],$pagination['limit'],$pagination['offset'],[],8*1024*1024);return $this->ok(array_map($this->pageDto(...),$rows),Input::pagination($this->pages()->count(),$pagination));
        }
        if($resource==='fields'||$resource==='templates'){
            if($action!==null)throw Problem::missing();if($id!==null){Input::keys($query,[]);return $this->ok($this->definitionDto($resource,$this->definition($resource,$id)));}
            $pagination=Input::page($query);$kind=$resource==='fields'?'field':'template';
            if($kind==='template'){
                $builtins=array_keys(Templates::all());$sql='SELECT definition_key FROM khcms_definitions WHERE club_id=? AND kind=?';$parameters=[$this->context->access->club(),'template'];foreach($builtins as $key){$sql.=' UNION SELECT ?';$parameters[]=$key;}
                $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM ('.$sql.') AS owned_templates',$parameters)->fetchColumn();$keys=Db::run($this->db,$sql.' ORDER BY definition_key LIMIT '.$pagination['limit'].' OFFSET '.$pagination['offset'],$parameters)->fetchAll(\PDO::FETCH_COLUMN);return $this->ok(array_map(fn(string $key):array=>$this->definitionDto('templates',$this->model()->template($key)),$keys),Input::pagination($total,$pagination));
            }
            [$filter,$parameters]=FieldPolicy::definitionFilter($this->db,$this->context->access,'d');$count=(int)Db::run($this->db,'SELECT COUNT(*) FROM khcms_definitions d WHERE '.$filter,$parameters)->fetchColumn();$keys=Db::run($this->db,'SELECT d.definition_key FROM khcms_definitions d WHERE '.$filter.' ORDER BY d.definition_key LIMIT '.$pagination['limit'].' OFFSET '.$pagination['offset'],$parameters)->fetchAll(\PDO::FETCH_COLUMN);$readable=FieldPolicy::modelDefinitions($this->db,$this->context->access,array_column($this->model()->fields(),null,'key'));return $this->ok(array_map(static fn(string $key):array=>$readable[$key]??throw Problem::missing(),$keys),Input::pagination($count,$pagination));
        }
        if($resource==='users'){
            if($action!==null)throw Problem::missing();if($id!==null){Input::keys($query,[]);return $this->ok($this->account($id));}
            $pagination=Input::page($query);$club=$this->context->access->club();$count=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id WHERE u.club_id=?',[$club])->fetchColumn();
            $rows=Db::run($this->db,'SELECT a.id,a.actor_id,a.email,a.login_name,a.active,a.verified,a.suspended,a.version,(a.totp_secret IS NOT NULL) AS totp_enabled,a.email_factor,u.name FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id WHERE u.club_id=? ORDER BY u.name,a.id LIMIT '.$pagination['limit'].' OFFSET '.$pagination['offset'],[$club])->fetchAll();foreach($rows as &$row){foreach(['active','verified','suspended','totp_enabled','email_factor'] as $key)$row[$key]=(bool)$row[$key];$row['version']=(int)$row['version'];}unset($row);return $this->ok($rows,Input::pagination($count,$pagination));
        }
        if($resource==='roles'){
            if($action!==null)throw Problem::missing();if($id!==null){Input::keys($query,[]);return $this->ok($this->role($id));}$pagination=Input::page($query);$club=$this->context->club();$total=(int)Db::run($this->db,'SELECT COUNT(*) FROM settings_roles WHERE club_id=?',[$club])->fetchColumn();$rows=Db::run($this->db,'SELECT * FROM settings_roles WHERE club_id=? ORDER BY name,id LIMIT '.$pagination['limit'].' OFFSET '.$pagination['offset'],[$club])->fetchAll();return $this->ok(array_map($this->roleDto(...),$rows),Input::pagination($total,$pagination));
        }
        if($resource==='files')return $this->readFiles($id,$action,$query);
        throw Problem::missing();
    }
    private function write(string $resource,?string $id,?string $action,string $method,array $body):array
    {
        if($resource==='pages'){
            if($id===null&&$method==='POST'){
                Input::keys($body,['version','data']);if(Input::version($body)!==0)throw Problem::conflict();$data=Input::object($body,'data');Input::keys($data,self::PAGE_FIELDS);return $this->ok($this->pageDto($this->pages()->save($data,0)),[],201);
            }
            if($id===null)throw Problem::missing();$old=$this->ownPage($id);$version=Input::version($body);if($old['version']!==$version)throw Problem::conflict();
            if($method==='PUT'&&$action===null){Input::keys($body,['version','data']);$data=Input::object($body,'data');Input::keys($data,self::PAGE_FIELDS);$input=array_replace(array_intersect_key($old,array_flip(self::PAGE_FIELDS)),$data);$input['id']=$id;return $this->ok($this->pageDto($this->pages()->save($input,$version)));}
            Input::keys($body,['version']);
            if($method==='DELETE'&&$action===null)return $this->ok($this->pageDto((new PageTrash($this->db,$this->context->access))->move($id,$version)));
            if($method!=='POST')throw new Problem('method_not_allowed',405,'Diese Seitenaktion benötigt POST.');
            $row=match($action){'publish'=>$this->pages()->publish($id,$version),'unpublish'=>$this->pages()->unpublish($id,$version),'trash'=>(new PageTrash($this->db,$this->context->access))->move($id,$version),'restore'=>(new PageTrash($this->db,$this->context->access))->restore($id,$version),'archive'=>$this->pages()->archive($id,$version),'unarchive'=>$this->pages()->unarchive($id,$version),default=>throw Problem::missing()};return $this->ok($this->pageDto($row));
        }
        if($resource==='fields'||$resource==='templates'){
            if($action!==null||($id===null&&$method!=='POST')||($id!==null&&$method!=='PUT'))throw new Problem('method_not_allowed',405,'Felder und Vorlagen mit POST anlegen oder mit PUT bearbeiten.');
            Input::keys($body,['version','data']);$version=Input::version($body);$data=Input::object($body,'data');$allowed=$resource==='fields'?self::FIELD_FIELDS:self::TEMPLATE_FIELDS;Input::keys($data,$allowed);
            if($id!==null){$old=$this->definition($resource,$id);if((int)$old['version']!==$version)throw Problem::conflict();$data=array_replace(array_intersect_key($old,array_flip($allowed)),$data);if(isset($data['key'])&&$data['key']!==$id)throw Problem::invalid();$data['key']=$id;}
            elseif($version!==0)throw Problem::conflict();
            $saved=$resource==='fields'?$this->model()->saveField($data,$version):$this->model()->saveTemplate($data,$version);
            return $this->ok($this->definition($resource,$saved['key']),[],$id===null?201:200);
        }
        if($resource==='users'){
            if($id===null)throw Problem::missing();
            if($action==='role'&&$method==='PUT'){
                $this->context->require('roles.write');Input::keys($body,['version','role_id']);$role=Input::text($body,'role_id',32,false);$target=(new \Kulturhus\Verein\RoleCatalogue($this->db,$this->context->actor()))->accountForNativeAccount($id);if((int)$target['version']!==Input::version($body))throw Problem::conflict();$this->roles()->assignRole($target['id'],$role,Input::version($body),$role==='');return $this->ok(['id'=>$id,'role_id'=>$role?:null,'version'=>Input::version($body)+1]);
            }
            if($action!==null||$method!=='PUT')throw new Problem('method_not_allowed',405,'Kontenstatus mit PUT ändern.');Input::keys($body,['version','active']);if(!is_bool($body['active']??null))throw Problem::invalid();$old=$this->account($id);if($old['version']!==Input::version($body))throw Problem::conflict();return $this->ok(\Kulturhus\Native\Auth\Runtime::service($this->db)->setActive($this->context->access,$id,$body['active'],Input::version($body)));
        }
        if($resource==='roles'){
            if($action!==null||($id===null&&$method!=='POST')||($id!==null&&$method!=='PUT'))throw new Problem('method_not_allowed',405,'Rollen mit POST anlegen oder mit PUT bearbeiten.');Input::keys($body,['version','data']);$version=Input::version($body);$data=Input::object($body,'data');Input::keys($data,self::ROLE_FIELDS);
            if($id!==null){$current=Db::run($this->db,'SELECT version FROM settings_roles WHERE club_id=? AND id=? FOR UPDATE',[$this->context->club(),$id])->fetchColumn();if($current===false)throw Problem::missing();if((int)$current!==$version)throw Problem::conflict();}
            if(isset($data['name'])&&is_string($data['name'])&&Db::run($this->db,'SELECT 1 FROM settings_roles WHERE club_id=? AND BINARY name=BINARY ? AND id<>? FOR SHARE',[$this->context->club(),trim($data['name']),$id??''])->fetchColumn())throw new Problem('role_name_conflict',409,'Dieser Rollenname ist bereits vergeben.');
            foreach(['settings_access','website_custom'] as $flag)if(isset($data[$flag])&&!is_bool($data[$flag]))throw Problem::invalid();
            $explicitLevels=array_key_exists('website_levels',$data);
            if($id!==null){$old=$this->role($id);if($old['version']!==$version)throw Problem::conflict();$data=array_replace(array_intersect_key($old,array_flip(self::ROLE_FIELDS)),$data);}elseif($version!==0)throw Problem::conflict();
            if(!$explicitLevels&&!($data['website_custom']??false))unset($data['website_levels']);
            $saved=$this->roles()->saveRole($id??'',$version,$data);return $this->ok(['id'=>$saved,'version'=>$version+1],[],$id===null?201:200);
        }
        if($resource==='files'&&$id!==null&&$action==='archive'&&$method==='PUT'){
            $this->ownFile($id);Input::keys($body,['version','archived']);if(!is_bool($body['archived']??null))throw Problem::invalid();return $this->ok((new MediaArchive($this->db,$this->context->access))->save($id,$body['archived'],Input::version($body)));
        }
        if($resource==='files'&&$id!==null&&$action==='metadata'&&$method==='PUT'){
            $this->ownFile($id);Input::keys($body,['version','data']);$data=Input::object($body,'data');$allowed=['alt','credit','source_name','source_url','license_name','license_url','rights_state','consent_state','expires_on','channels'];Input::keys($data,$allowed);$service=new MediaMetadata($this->db,$this->context->access);$old=$service->get($id);if($old['version']!==Input::version($body))throw Problem::conflict();return $this->ok($service->save($id,array_replace(array_intersect_key($old,array_flip($allowed)),$data),Input::version($body)));
        }
        throw new Problem('method_not_allowed',405,'Für diese Anfrage gibt es keinen Schreibvertrag.');
    }
    private function readFiles(?string $id,?string $action,array $query):array|Download
    {
        $kind=$query['kind']??'image';if(!is_string($kind)||!in_array($kind,['image','document'],true))throw Problem::invalid();unset($query['kind']);$club=$this->context->access->club();
        if($id!==null){
            $this->ownFile($id,$kind);
            if($kind==='document'){$file=(new WebsiteDocuments($this->db,$this->context->access,$this->documentRoot))->file($id);$record=['id'=>$id,'kind'=>'document','name'=>$file['name'],'bytes'=>$file['bytes'],'sha256'=>$file['sha256'],'mime'=>$file['mime']];}
            else{$file=(new Media($this->db,$this->context->access,$this->mediaRoot))->variant($id,'original');$record=array_intersect_key($file,array_flip(['name','bytes','sha256','mime','width','height','alt']))+['id'=>$id,'kind'=>'image'];$record['metadata']=(new MediaMetadata($this->db,$this->context->access))->get($id);$record['archive']=(new MediaArchive($this->db,$this->context->access))->get($id);}
            Input::keys($query,[]);if($action==='content')return new Download($file,$this->context);if($action!==null)throw Problem::missing();return $this->ok($record);
        }
        if($action!==null)throw Problem::missing();$page=Input::page($query);
        if($kind==='document'){$where=' FROM khcms_documents WHERE club_id=?';$rows=Db::run($this->db,'SELECT id,original_name AS name,bytes,sha256,created_at'.$where.' ORDER BY created_at DESC,id DESC LIMIT '.$page['limit'].' OFFSET '.$page['offset'],[$club])->fetchAll();}
        else{$where=' FROM khcms_media m WHERE m.club_id=? AND '.MediaAvailability::activeSql($this->db);$rows=Db::run($this->db,'SELECT m.id,m.original_name AS name,m.mime,m.width,m.height,m.created_at'.$where.' ORDER BY m.created_at DESC,m.id DESC LIMIT '.$page['limit'].' OFFSET '.$page['offset'],[$club])->fetchAll();}
        $count=(int)Db::run($this->db,'SELECT COUNT(*)'.$where,[$club])->fetchColumn();foreach($rows as &$row){$row['kind']=$kind;foreach(['bytes','width','height'] as $key)if(isset($row[$key]))$row[$key]=(int)$row[$key];}unset($row);return $this->ok($rows,Input::pagination($count,$page));
    }
    private function translation(string $method,string $path,?string $id,string $locale,?string $action,array $query,array $body,string $key):array
    {
        if(!$id||$query)throw Problem::invalid();\Kulturhus\Native\Language\Settings::code($locale);$scope=in_array($method,['GET','HEAD'],true)?'pages.read':(in_array($action,['publish','unpublish'],true)?'pages.publish':'pages.write');$this->context->require($scope);$this->ownPage($id);$service=new \Kulturhus\Native\Language\Translations($this->db,$this->context->access);
        (new \Kulturhus\Native\Language\Settings($this->db,$this->context->access))->require($locale,$scope==='pages.publish'?'publish':'edit');
        if(in_array($method,['GET','HEAD'],true)){if($body||$action!==null)throw Problem::invalid();return $this->ok($service->get($id,$locale));}
        $result=(new Operations($this->db,$this->context,Factory::operationKey()))->run($key,$method,$path,$body,function()use($service,$id,$locale,$action,$method,$body,$scope):array{
            $this->context->require($scope);$version=Input::version($body);
            if($action===null&&$method==='PUT'){Input::keys($body,['version','source_revision','data']);if(!is_int($body['source_revision']??null)||$body['source_revision']<1)throw Problem::invalid();return $this->ok($service->save($id,$locale,Input::object($body,'data'),$body['source_revision'],$version));}
            if($method!=='POST')throw new Problem('method_not_allowed',405,'Übersetzung mit PUT speichern oder eine POST-Aktion verwenden.');
            if($action==='restore'){Input::keys($body,['version','revision']);if(!is_int($body['revision']??null)||$body['revision']<1)throw Problem::invalid();return $this->ok($service->restore($id,$locale,$body['revision'],$version));}
            Input::keys($body,['version']);return $this->ok(match($action){'publish'=>$service->publish($id,$locale,$version),'unpublish'=>$service->unpublish($id,$locale,$version),default=>throw Problem::missing()});
        });
        (new \Kulturhus\Native\Language\Settings($this->db,$this->context->access))->require($locale,$scope==='pages.publish'?'publish':'edit');
        $result['data']['payload']=$service->privatePayload($id,(int)$result['data']['source_revision'],$result['data']['payload']);return $result;
    }
    /** Paths are server-verified upload temporary files, never JSON input. */
    public function upload(array $files,string $kind,string $alt,string $key):array
    {
        $this->context->require('files.write');if(!in_array($kind,['image','document'],true)||!array_is_list($files)||!$files||count($files)>10||mb_strlen($alt)>500)throw Problem::invalid();$fingerprints=[];
        foreach($files as $file){if(!is_string($file['path']??null)||!is_string($file['name']??null)||is_link($file['path'])||!is_file($file['path']))throw Problem::invalid();$fingerprints[]=['name'=>$file['name'],'bytes'=>filesize($file['path']),'sha256'=>hash_file('sha256',$file['path'])];}
        return UploadTransaction::run($this->db,function(UploadTransaction $transaction)use($files,$kind,$alt,$key,$fingerprints):array{
            return (new Operations($this->db,$this->context,Factory::operationKey()))->run($key,'POST','/api/v1/files',['kind'=>$kind,'alt'=>$alt,'files'=>$fingerprints],function()use($files,$kind,$alt,$transaction):array{
                $this->context->require('files.write');$result=[];
                if($kind==='document'){$rows=(new WebsiteDocuments($this->db,$this->context->access,$this->documentRoot))->uploadMany($files,null,$transaction);foreach($rows as $row){unset($row['path']);$result[]=$row+['kind'=>'document'];}}
                else{$media=new Media($this->db,$this->context->access,$this->mediaRoot);foreach($files as $file){$row=$media->upload($file['path'],$file['name'],$alt,null,null,null,$transaction);$result[]=array_intersect_key($row,array_flip(['id','original_name','mime','width','height','created_at']))+['kind'=>'image'];}}
                $this->context->require('files.write');return $this->ok($result,[],201);
            });
        });
    }
}
