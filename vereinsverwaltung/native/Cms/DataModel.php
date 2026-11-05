<?php
declare(strict_types=1);

namespace Kulturhus\Native\Cms;

use InvalidArgumentException;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Shared\Db;
use PDO;
use RuntimeException;

final class DataModel
{
    use ClubPublicationLock;
    public function __construct(
        private readonly PDO $db,
        private readonly Access $access,
    ) {}

    public function fields(): array
    {
        $this->access->require("website", "read");
        return array_map(fn(array $field):array=>$this->resolveField($field,[]),$this->definitions("field"));
    }

    public function field(string $key): array
    {
        $this->access->require("website", "read");
        $field=$this->read("field", CustomFields::key($key)) ?? throw new RuntimeException("Felddefinition nicht gefunden.");
        return $this->resolveField($field,[]);
    }

    /** Layout submissions need owned identities, with the same manage-only write contract. */
    public function managedFieldKeys(): array
    {
        $this->access->require("website", "manage");
        return array_column($this->definitions("field"), "key");
    }

    public function templates(): array
    {
        $this->access->require("website", "read");
        $templates = [];
        foreach (Templates::all() as $key => $builtin) {
            $templates[$key] = $this->builtin($key);
        }
        foreach ($this->definitions("template") as $definition) {
            $templates[$definition["key"]] = $definition;
        }
        ksort($templates, SORT_STRING);
        return array_values($templates);
    }

    public function template(string $key): array
    {
        $this->access->require("website", "read");
        CustomFields::key($key);
        return $this->read("template", $key) ??
            (isset(Templates::all()[$key])
                ? $this->builtin($key)
                : throw new RuntimeException(
                    "Template-Definition nicht gefunden.",
                ));
    }

    public function templateFields(string|array $template):array
    {
        $template=is_string($template)?$this->template($template):$template;
        $definitions=[];foreach($template['fields']as$key)$definitions[$key]=$this->field($key);
        return TemplateContexts::resolve($template,$definitions);
    }

    /** Canonical row-level selector/order guard; paths are validated by FieldPolicy. */
    public function fieldGuard(string $path,string $alias='p',bool $public=false,?string $payloadAlias=null):array
    {return FieldPolicy::queryGuard($this->db,$this->access,$path,$alias,$public,$payloadAlias);}
    public function fieldValue(array $definition,mixed $value,bool $forSearch=false):mixed
    {return \Kulturhus\Native\Fieldtypes\Runtime::queryValue($this->db,$this->access,$definition,$value,$forSearch);}

    public function pageGuard(string $alias='p'):array
    {return TemplatePolicy::readFilter($this->db,$this->access,$alias);}

    public function saveField(array $data, int $expectedVersion = 0): array
    {
        return $this->save("field", $data, $expectedVersion);
    }

    public function saveTemplate(array $data, int $expectedVersion = 0): array
    {
        return $this->save("template", $data, $expectedVersion);
    }

    public function fieldRevisions(string $key): array
    {
        return $this->history("field", $key);
    }

    public function templateRevisions(string $key): array
    {
        return $this->history("template", $key);
    }

    /** Captures current declarative definitions with typed values; supplied snapshots are ignored. */
    public function normalizePage(array $input, ?array $previous = null, bool $preserveItemVersions = false,bool $trustedInitial=false,array $providerReviews=[]): array
    {
        if($providerReviews){$this->access->require('website','manage');$this->access->require('website','edit');if($previous===null||$preserveItemVersions||$trustedInitial)throw new InvalidArgumentException('Typübernahme benötigt einen bestehenden privaten Originalentwurf.');}
        if($trustedInitial){$this->access->require('website','manage');$this->access->require('website','edit');if($previous!==null||$preserveItemVersions)throw new InvalidArgumentException('Initialwerte sind nur für neue bestätigte Importseiten zulässig.');}
        $template = $this->template(
            CustomFields::key($input["template"] ?? "standard"),
        );
        if (
            !array_key_exists("nav_visible", $input) &&
            $template["renderer"] === "event"
        ) {
            $input["nav_visible"] = false;
        }
        $payload = Templates::normalize($input, [$template["key"] => true]);
        if (!empty($payload['content_sections']) && $template['renderer'] !== 'standard') {
            throw new InvalidArgumentException('Inhaltsabschnitte zuerst entfernen, bevor eine andere Inhaltsart verwendet wird.');
        }
        if ($template["renderer"] === "news") {
            $payload["news_channels"] = NewsChannels::normalize(
                array_key_exists("news_channels", $input)
                    ? $input["news_channels"]
                    : NewsChannels::ALL,
            );
            // Historical originals and approved jobs without this field keep their exact bytes.
            if (array_key_exists("news_review", $input)) {
                $payload["news_review"] = NewsReview::normalize(
                    $input["news_review"],
                );
            }
        }
        if (!empty($payload['document_links']) && $template['renderer'] !== 'standard') throw new InvalidArgumentException('PDF-Dokumente benötigen eine Inhaltsseitenvorlage.');
        $values = $input["custom_fields"] ?? [];
        if (
            !is_array($values) ||
            (array_is_list($values) && $values !== []) ||
            array_diff(array_keys($values), $template["fields"])
        ) {
            throw new InvalidArgumentException(
                "Zusatzfelder müssen ein Objekt mit den Feldern des Templates sein.",
            );
        }
        $definitions = $this->templateFields($template);
        $values=TemplateContexts::authorize($definitions,$values,$trustedInitial?$values:($previous['custom_fields']??[]));
        $values=FieldValues::keepPasswords($definitions,$values,$previous['custom_fields']??[]);
        $fieldAccess=FieldPolicy::prepare($this->db,$this->access,$definitions,$values,$previous??[],$preserveItemVersions);$definitions=$fieldAccess['definitions'];$values=$fieldAccess['values'];
        $providers=\Kulturhus\Native\Fieldtypes\Runtime::prepare($this->db,$this->access,$definitions,$values,$previous??[],$preserveItemVersions,$trustedInitial,$providerReviews);$definitions=$providers['definitions'];$values=$providers['values'];
        FieldConditions::template($template, $definitions);
        $normalized = FieldConditions::values($definitions, $values);
        $normalized = FieldValues::authorize($definitions,$normalized,$previous['custom_fields']??($preserveItemVersions?$normalized:[]),$this->access,$preserveItemVersions);
        FieldValues::walk($definitions,$normalized,function(array $definition,mixed $value):void{
            foreach(FieldValues::collection($definition)?($value??[]):[$value] as$item)$this->checkReference($definition['type']==='pagetable'?'page-reference':$definition['type'],$item);
        });
        $payload["custom_fields"] = $normalized;
        $payload["field_definitions"] = $definitions;
        foreach($definitions as$definition)if(FieldValues::collection($definition)){$mediaIds=FieldValues::references($payload,'media');if($mediaIds)$payload['custom_media_ids']=$mediaIds;break;}
        $fileIds=FieldValues::references($payload,'file',true);
        if($fileIds)$payload['custom_file_metadata']=\Kulturhus\Native\Files\FileReferences::capture($this->db,$this->access->club(),array_map(static fn($id)=>['id'=>$id,'label'=>''],$fileIds));
        $plainFields = [];
        foreach ($definitions as $key => $definition) {
            if ($definition["type"] === "richtext") {
                $plainFields[$key] =
                    $normalized[$key] === null
                        ? null
                        : RichTextField::plain($normalized[$key]);
            }
        }
        // Other existing templates keep their exact approved snapshot shape.
        if ($plainFields) {
            $payload["custom_field_text"] = $plainFields;
        }
        $payload["template_definition"] = $template;
        if (
            strlen(
                json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES |
                        JSON_THROW_ON_ERROR,
                ),
            ) >
            2 * 1024 * 1024
        ) {
            throw new InvalidArgumentException(
                "Inhalt einschließlich Felddefinitionen darf maximal 2 MiB umfassen.",
            );
        }
        return $payload;
    }

    /** Require a deliberate re-save after schema changes, while old publications stay unchanged. */
    public function publication(array $payload): array
    {
        if(isset($payload['template_definition'])){
            $template=$this->template($payload['template']);$definitions=$this->templateFields($template);
            if($this->definitionTypes($definitions)!==$this->definitionTypes($payload['field_definitions']??[]))throw new Conflict('Das Inhaltsmodell wurde geändert. Den Entwurf prüfen und erneut speichern, bevor er veröffentlicht wird.');
        }
        $current = $this->normalizePage($payload,$payload,true);
        // This map came from a saved revision, not from a form. Keep the approved original.
        if (array_key_exists('media_metadata', $payload)) $current['media_metadata'] = $payload['media_metadata'];
        if (array_key_exists('document_links', $payload)) $current['document_links'] = $payload['document_links'];
        // Already approved historical plans must retain their exact byte fingerprint.
        // An absent field means both channels; the next deliberate edit persists it.
        if (
            NewsChannels::isNews($payload) &&
            !array_key_exists("news_channels", $payload)
        ) {
            unset($current["news_channels"]);
        }
        if (
            isset($payload["template_definition"]) &&
            ($payload["template_definition"] !==
                $current["template_definition"] ||
                FieldPolicy::withoutHistory($payload["field_definitions"] ?? []) !==
                    FieldPolicy::withoutHistory($current["field_definitions"]))
        ) {
            throw new Conflict(
                "Das Inhaltsmodell wurde geändert. Den Entwurf prüfen und erneut speichern, bevor er veröffentlicht wird.",
            );
        }
        \Kulturhus\Native\TemplateRenderers\Factory::assertPublication($this->db,$this->access->club(),$current);
        return $current;
    }

    private function save(
        string $kind,
        array $data,
        int $expectedVersion,
    ): array {
        $this->access->require("website", "manage");
        return Db::atomic($this->db, function () use (
            $kind,
            $data,
            $expectedVersion,
        ): array {
            $actor = $this->access->require("website", "manage");
            $this->lockClub();
            $definition =
                $kind === "field"
                    ? CustomFields::definition($data)
                    : $this->normalizeTemplate($data);
            $key = $definition["key"];
            $old = $this->read($kind, $key, true);
            if($kind==='field'&&!array_key_exists('access',$data)&&array_key_exists('access',$old??[]))$definition['access']=$old['access'];
            if (
                $expectedVersion < 0 ||
                ($old["version"] ?? 0) !== $expectedVersion
            ) {
                throw new Conflict(
                    "Die Definition wurde inzwischen geändert. Aktuellen Stand neu laden.",
                );
            }
            if($kind==='field')$definition=\Kulturhus\Native\Fieldtypes\Runtime::assertDefinition($this->db,$this->access,$definition,$old);
            if($kind==='template'){
                if(!array_key_exists('frontend_renderer',$data)&&isset($old['frontend_renderer']))$definition['frontend_renderer']=$old['frontend_renderer'];
                elseif(array_key_exists('frontend_renderer',$data)){
                    $binding=\Kulturhus\Native\TemplateRenderers\Factory::normalize($this->db,$actor['club_id'],$data['frontend_renderer'],$old['frontend_renderer']??null);
                    if($binding===null)unset($definition['frontend_renderer']);else $definition['frontend_renderer']=$binding;
                }
            }
            $version = $expectedVersion + 1;
            $policyChanged=$kind==='template'&&($old['policy']??[])!==($definition['policy']??[]);
            $urlPolicyChanged=$kind==='template'&&\Kulturhus\Native\Routing\Policy::normalize($old['url_policy']??[])!==\Kulturhus\Native\Routing\Policy::normalize($definition['url_policy']??[]);
            $fieldAccessChanged=$kind==='field'&&($old['access']??[])!==($definition['access']??[]);
            if($policyChanged)TemplatePolicy::assertModelUpdate($this->db,$this->access,$definition);
            if ($kind === "field") {
                $fields = array_column(
                    $this->definitions("field"),
                    null,
                    "key",
                );
                $fields[$key] = $definition;
                foreach($fields as $candidate)$this->resolveField($candidate,[],$fields);
                $definition = FieldConditions::bind($definition, $fields);
                foreach (["show_when", "required_when"] as $ruleKind) {
                    foreach (
                        $definition[$ruleKind]["conditions"] ?? []
                        as $condition
                    ) {
                        if (array_key_exists("value", $condition)) {
                            $this->checkReference(
                                $fields[$condition["field"]]["type"],
                                $condition["value"],
                            );
                        }
                    }
                }
                $fields[$key] = $definition;
                FieldConditions::validate(
                    $fields,
                    $this->definitions("template"),
                );
                foreach($this->definitions('template')as$template)if(!empty($template['field_contexts'])){
                    $contextDefinitions=[];
                    foreach($template['fields']as$fieldKey)$contextDefinitions[$fieldKey]=$this->resolveField($fields[$fieldKey],[],$fields);
                    TemplateContexts::resolve($template,$contextDefinitions);
                }
            }
            $json = json_encode(
                $definition,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            if ($old) {
                Db::run(
                    $this->db,
                    "UPDATE khcms_definitions SET version=?,definition_json=?,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND kind=? AND definition_key=?",
                    [
                        $version,
                        $json,
                        $actor["id"],
                        $this->access->club(),
                        $kind,
                        $key,
                    ],
                );
            } else {
                Db::run(
                    $this->db,
                    "INSERT INTO khcms_definitions (club_id,kind,definition_key,version,definition_json,updated_by,updated_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))",
                    [
                        $this->access->club(),
                        $kind,
                        $key,
                        $version,
                        $json,
                        $actor["id"],
                    ],
                );
            }
            Db::run(
                $this->db,
                "INSERT INTO khcms_definition_revisions (club_id,kind,definition_key,version,definition_json,actor_id,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))",
                [
                    $this->access->club(),
                    $kind,
                    $key,
                    $version,
                    $json,
                    $actor["id"],
                ],
            );
            if($policyChanged)TemplatePolicy::refresh($this->db,$this->access);
            elseif($fieldAccessChanged)FieldPolicy::refresh($this->db,$this->access);
            elseif($urlPolicyChanged)Db::run($this->db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$this->access->club()]);
            return $definition + ["version" => $version];
        });
    }

    private function normalizeTemplate(array $input): array
    {
        if (
            array_diff(array_keys($input), [
                "key",
                "label",
                "renderer",
                "fields",
                "version",
                "groups",
                "policy",
                "field_contexts",
                "url_policy",
                "frontend_renderer",
            ])
        ) {
            throw new InvalidArgumentException(
                "Templates dürfen nur deklarative Einstellungen enthalten.",
            );
        }
        $key = CustomFields::key($input["key"] ?? null);
        $label = CustomFields::text($input["label"] ?? "", 160);
        $renderer = $input["renderer"] ?? "standard";
        $fields = $input["fields"] ?? [];
        if (
            $label === "" ||
            !is_string($renderer) ||
            !isset(Templates::all()[$renderer])
        ) {
            throw new InvalidArgumentException(
                "Template-Bezeichnung und ein eingebauter Renderer sind erforderlich.",
            );
        }
        if (
            !is_array($fields) ||
            !array_is_list($fields) ||
            count($fields) > 100
        ) {
            throw new InvalidArgumentException(
                "Template-Felder müssen eine Liste mit maximal 100 Schlüsseln sein.",
            );
        }
        foreach ($fields as $field) {
            $field = CustomFields::key($field);
            if (!$this->read("field", $field)) {
                throw new InvalidArgumentException(
                    "Ein Template-Feld ist in diesem Verein nicht definiert.",
                );
            }
        }
        if (count(array_unique($fields)) !== count($fields)) {
            throw new InvalidArgumentException(
                "Template-Felder dürfen nicht doppelt zugeordnet werden.",
            );
        }
        $template = [
            "key" => $key,
            "label" => $label,
            "renderer" => $renderer,
            "fields" => $fields,
        ];
        if (array_key_exists("groups", $input)) {
            $groups = FieldGroups::normalize($input["groups"], $fields);
            if ($groups) {
                $template["groups"] = $groups;
            }
        }
        if(array_key_exists('field_contexts',$input)){
            $contexts=TemplateContexts::normalize($input['field_contexts'],$fields);if($contexts)$template['field_contexts']=$contexts;
        }
        if(array_key_exists('policy',$input)){
            $policy=TemplatePolicy::normalize($input['policy']);if($policy)$template['policy']=$policy;
        }
        if(isset($input['frontend_renderer']))$template['frontend_renderer']=\Kulturhus\Native\TemplateRenderers\Binding::normalize($input['frontend_renderer']);
        if(array_key_exists('url_policy',$input))$template['url_policy']=\Kulturhus\Native\Routing\Policy::normalize($input['url_policy']);
        $contextDefinitions=[];foreach($fields as$fieldKey)$contextDefinitions[$fieldKey]=$this->resolveField($this->read('field',$fieldKey),[]);
        TemplateContexts::resolve($template,$contextDefinitions);
        FieldConditions::template(
            $template,
            array_column($this->definitions("field"), null, "key"),
        );
        return $template;
    }

    private function builtin(string $key): array
    {
        return [
            "key" => $key,
            "label" => Templates::all()[$key]["label"],
            "renderer" => $key,
            "fields" => [],
            "version" => 0,
        ];
    }

    private function read(string $kind, string $key, bool $lock = false): ?array
    {
        $row = Db::run(
            $this->db,
            "SELECT definition_json,version FROM khcms_definitions WHERE club_id=? AND kind=? AND definition_key=?" .
                ($lock ? " FOR UPDATE" : ""),
            [$this->access->club(), $kind, $key],
        )->fetch(PDO::FETCH_ASSOC);
        return $row
            ? json_decode(
                    $row["definition_json"],
                    true,
                    64,
                    JSON_THROW_ON_ERROR,
                ) + ["version" => (int) $row["version"]]
            : null;
    }

    private function definitions(string $kind): array
    {
        $rows = Db::run(
            $this->db,
            "SELECT definition_json,version FROM khcms_definitions WHERE club_id=? AND kind=? ORDER BY definition_key",
            [$this->access->club(), $kind],
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(
            static fn(array $row): array => json_decode(
                $row["definition_json"],
                true,
                64,
                JSON_THROW_ON_ERROR,
            ) + ["version" => (int) $row["version"]],
            $rows,
        );
    }

    private function history(string $kind, string $key): array
    {
        $this->access->require("website", "read");
        CustomFields::key($key);
        $rows = Db::run(
            $this->db,
            "SELECT version,definition_json,actor_id,created_at FROM khcms_definition_revisions WHERE club_id=? AND kind=? AND definition_key=? ORDER BY version DESC",
            [$this->access->club(), $kind, $key],
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $row): array {
            $row["version"] = (int) $row["version"];
            $row["payload"] = json_decode(
                $row["definition_json"],
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
            unset($row["definition_json"]);
            return $row;
        }, $rows);
    }

    private function checkReference(string $type, mixed $value): void
    {
        if (
            $value === null ||
            !in_array($type, ["page-reference", "media-reference", "file-reference"], true)
        ) {
            return;
        }
        if($type==='file-reference'){\Kulturhus\Native\Files\FileReferences::capture($this->db,$this->access->club(),[['id'=>$value,'label'=>'']]);return;}
        $table = match($type){'page-reference'=>'khcms_pages','file-reference'=>'khcms_documents',default=>'khcms_media'};
        if (
            !Db::run(
                $this->db,
                "SELECT p.id FROM " . $table . " p WHERE p.id=? AND p.club_id=?" . ($type === "page-reference" ? " AND ".PageTrash::activeSql() : ""),
                [$value, $this->access->club()],
            )->fetchColumn()
        ) {
            throw new InvalidArgumentException(
                "Die Referenz gehört nicht zu einer verfügbaren Seite/einem Bild dieses Vereins.",
            );
        }
    }

    /** Stored snapshots supply historical types; no current field change can reinterpret an old original. */
    public function restorePage(array $original, array $current = []): array
    {
        $this->access->require('website','edit');
        $template=$original['template_definition']??null;
        if(!is_array($template))return $this->normalizePage($original,$current);
        Templates::normalize($original,[$template['key']=>true]);
        $definitions=$original['field_definitions']??[];
        $values=$original['custom_fields']??[];
        $fieldAccess=FieldPolicy::prepare($this->db,$this->access,$definitions,$values,$current);$definitions=$fieldAccess['definitions'];$values=$fieldAccess['values'];$original['field_definitions']=$definitions;
        if(isset($current['template']))TemplateContexts::authorize($this->templateFields($current['template']),$values,$current['custom_fields']??[]);
        // Keep the original item counters only as historical evidence; the new draft uses current counters.
        $values=$this->restoreCounters($definitions,$values,$current['custom_fields']??[]);
        $original['custom_fields']=FieldValues::authorize($definitions,$values,$current['custom_fields']??[],$this->access);
        FieldValues::walk($definitions,$original['custom_fields'],function(array$field,mixed$value):void{
            foreach(FieldValues::collection($field)?($value??[]):[$value]as$id)$this->checkReference($field['type']==='pagetable'?'page-reference':$field['type'],$id);
        });
        return $original;
    }

    private function restoreCounters(array$definitions,array$values,array$current):array
    {
        foreach($definitions as$key=>$field)if($field['type']==='repeater'){
            $before=array_column(is_array($current[$key]??null)?$current[$key]:[],null,'id');
            foreach($values[$key]??[]as$i=>$item){$old=$before[$item['id']]??null;$values[$key][$i]['version']=$old['version']??0;$values[$key][$i]['values']=$this->restoreCounters($field['item_definitions'],$item['values'],$old['values']??[]);}
        }
        return $values;
    }

    private function definitionTypes(array$definitions):array
    {
        $types=[];foreach($definitions as$key=>$field)$types[$key]=[$field['type'],!empty($field['multiple']),$this->definitionTypes($field['item_definitions']??[])];return $types;
    }

    private function resolveField(array$field,array$path,array$catalog=[],int &$budget=0):array
    {
        if(++$budget>100)throw new InvalidArgumentException('Ein Datensatzfeld darf einschließlich Unterfeldern höchstens 100 Feldzuordnungen enthalten.');
        if($field['type']==='comments'&&$path)throw new InvalidArgumentException('Kommentarfelder direkt einer Seite zuordnen.');
        if($field['type']!=='repeater')return $field;
        if(count($path)>=3||in_array($field['key'],$path,true))throw new InvalidArgumentException('Datensatzfelder: keine Kreise und höchstens drei Ebenen verwenden.');
        $path[]=$field['key'];$children=[];
        foreach($field['fields'] as$key){$child=$catalog[$key]??$this->read('field',$key);if(!$child)throw new InvalidArgumentException('Datensatzfeld gehört nicht zu diesem Verein.');$children[$key]=$this->resolveField($child,$path,$catalog,$budget);}
        // Child dependency rules only refer to sibling values in the same item.
        FieldConditions::template(['label'=>$field['label'],'fields'=>array_keys($children)],$children);
        $field['item_definitions']=$children;
        return $field;
    }
}
