<?php
declare(strict_types=1);

namespace Kulturhus\Native\Web;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{CustomFields, DataModel, FieldConditions, FieldPolicy};
use Kulturhus\Native\Shared\Access;
use PDO;

/** Declarative model management and typed controls; no template code is accepted. */
final class ContentModel
{
    use ContentInterfaceText;
    private const TYPE_LABELS = \Kulturhus\Native\Web\Fields\Controls::TYPE_LABELS;
    private const RENDERERS = [
        "standard" => "Inhaltsseite",
        "event" => "Veranstaltung",
        "gallery" => "Galerie",
        "news" => "Beitrag / Aktuelles",
    ];
    private readonly DataModel $model;

    public function __construct(
        private readonly PDO $db,
        private readonly Access $access,
        private readonly string $csrf,
    ) {
        $this->model = new DataModel($db, $access);
    }

    private array $modelState = [];

    public function manager(?array $query = null): string
    {
        $catalogue=new \Kulturhus\Native\Cms\ModelCatalogue($this->db,$this->access);
        $list=$catalogue->listing($query??$_GET);$this->modelState=array_intersect_key($list,array_flip(['model_kind','model_q','model_page','model_key','model_new']));
        $kind=$list['model_kind'];$label=$kind==='field'?$this->contentText('Felder'):$this->contentText('Vorlagen');$html='<section class="model-catalogue" data-model-catalogue><nav class="model-catalogue-tabs" aria-label="'.Html::e($this->contentText('Modelle')).'">';
        foreach(['field'=>$this->contentText('Felder'),'template'=>$this->contentText('Vorlagen')]as$target=>$title)$html.='<a data-nav class="btn btn-outline theme-secondary" href="'.Html::e($this->modelUrl(['model_kind'=>$target,'model_page'=>1,'model_key'=>'','model_new'=>'0'])).'"'.($kind===$target?' aria-current="page"':'').'>'.Html::e($this->contentText($title)).'</a>';
        $html.='</nav><div class="model-catalogue-heading"><h2>'.Html::e($label).' <span class="badge">'.Html::e($list['total']).'</span></h2><a data-nav class="btn btn-solid theme-primary" href="'.Html::e($this->modelUrl(['model_key'=>'','model_new'=>'1'])).'">'.($kind==='field'?Html::e($this->contentText('Neues Feld')):Html::e($this->contentText('Neue Vorlage'))).'</a></div>';
        $html.='<form method="get" action="/" class="model-catalogue-search">'.Html::hidden('view','model').Html::hidden('model_kind',$kind).'<label>'.Html::e($this->contentText('Suche')).'<input class="form-control" type="search" name="model_q" maxlength="100" value="'.Html::e($list['model_q']).'" placeholder="'.Html::e($this->contentText('Name oder Kennung')).'"></label><button class="btn btn-outline theme-secondary">'.Html::e($this->contentText('Suchen')).'</button></form><div class="model-catalogue-items" role="list">';
        foreach($list['items']as$item){$type=$kind==='field'?(self::TYPE_LABELS[$item['type']]??$item['type']):(self::RENDERERS[$item['type']]??$item['type']);$html.='<a data-nav role="listitem" class="model-catalogue-item" href="'.Html::e($this->modelUrl(['model_key'=>$item['key'],'model_new'=>'0'])).'"'.($list['model_key']===$item['key']?' aria-current="true"':'').'><strong>'.Html::e($item['label']).'</strong><span>'.Html::e($item['key']).' · '.Html::e($this->contentText($type)).' · '.($item['version']?'Version '.Html::e($item['version']):'Standard').'</span></a>';}
        if(!$list['items'])$html.=('<p class="empty-state">' . Html::e($this->contentText('Keine')) . ' ').Html::e($label).(' ' . Html::e($this->contentText('gefunden.')) . '</p>');
        $html.='</div>'.str_replace('<a ','<a data-nav ',Pager::render($list['total'],$list['size'],$list['model_page'],fn(int$page):string=>$this->modelUrl(['model_page'=>$page]),$this->contentFormat('{label} durchsuchen',['label'=>$label])));
        if($list['model_key']!==''||$list['model_new']==='1'){
            $selected=$list['model_key']!==''?$catalogue->selected($kind,$list['model_key']):null;
            $fields=array_values(FieldPolicy::modelDefinitions($this->db,$this->access,array_column($this->model->fields(),null,'key')));
            $conditions=array_column(array_map(static fn(array$field):array=>['key'=>$field['key'],'type'=>$field['type'],'options'=>$field['options'],'multiple'=>!empty($field['multiple'])],$fields),null,'key');
            $html.='<section id="model-editor" class="panel model-catalogue-editor" data-condition-catalog="'.Html::e(json_encode($conditions,JSON_THROW_ON_ERROR)).'"><div class="model-catalogue-heading"><h2>'.Html::e($selected['label']??($kind==='field'?$this->contentText('Neues Feld'):$this->contentText('Neue Vorlage'))).'</h2><a data-nav class="btn btn-outline theme-secondary" href="'.Html::e($this->modelUrl(['model_key'=>'','model_new'=>'0'])).('">' . Html::e($this->contentText('Schließen')) . '</a></div>').($kind==='field'?$this->fieldForm($selected,$fields):$this->templateForm($selected,$fields)).(new FieldRuleEditor($this->db,$this->access))->referenceTemplates().'</section>';
        }
        $html.='<p><a data-navigation-native class="btn btn-outline theme-secondary" href="'.Html::e(\Kulturhus\Native\Integration\Routes::endpoint('cms-transfer')).'">'.Html::e($this->contentText('Inhalte übertragen')).'</a></p>';
        return $html.'</section>';
    }

    private function modelUrl(array $changes=[]):string
    {
        $query=\Kulturhus\Native\Cms\ModelCatalogue::state(array_replace($this->modelState,$changes));
        return '/?'.http_build_query(['view'=>'model',...$query]);
    }

    public function action(array $post): void
    {
        $this->access->require("website", "manage");
        $token = $post["csrf"] ?? null;
        if (!is_string($token) || !hash_equals($this->csrf, $token)) {
            throw new DomainException(
                $this->contentText("Formular abgelaufen. Bitte Seite neu laden."),
            );
        }
        $operation = self::string($post["operation"] ?? "");
        $version = $post["version"] ?? 0;
        if (
            (!is_int($version) && !is_string($version)) ||
            !preg_match("/\A(?:0|[1-9][0-9]*)\z/D", (string) $version)
        ) {
            throw new InvalidArgumentException(
                "Gültige Definitionsversion erforderlich.",
            );
        }
        $version = filter_var($version, FILTER_VALIDATE_INT, [
            "options" => ["min_range" => 0],
        ]);
        if ($version === false) {
            throw new InvalidArgumentException(
                "Definitionsversion außerhalb des zulässigen Bereichs.",
            );
        }
        if ($operation === "model-field") {
            $type = self::string($post["type"] ?? "");
            $providers=[];
            if(array_key_exists('field_provider_settings',$post)){
                $previous=$version>0?(new \Kulturhus\Native\Cms\ModelCatalogue($this->db,$this->access))->selected('field',self::string($post['key']??'')):null;
                $providers=\Kulturhus\Native\Fieldtypes\View::submitted($post,\Kulturhus\Native\Fieldtypes\Factory::registry($this->db,$this->access->club()),$previous);$type=$providers['type'];
            }
            $optionsText = CustomFields::text(
                self::string($post["options"] ?? ""),
                50000,
            );
            $options =
                $optionsText === ""
                    ? []
                    : array_values(
                        array_filter(
                            array_map(
                                "trim",
                                preg_split("/\R/u", $optionsText),
                            ),
                            static fn(string $option): bool => $option !== "",
                        ),
                    );
            $required = $post["required"] ?? false;
            if (!in_array($required, [true, false, 1, 0, "1", "0"], true)) {
                throw new InvalidArgumentException(
                    "Pflichtfeld-Einstellung muss Ja oder Nein sein.",
                );
            }
            $minimum = self::string($post["min"] ?? "");
            $maximum = self::string($post["max"] ?? "");
            if ($type === "decimal") {
                $minimum = str_replace(",", ".", $minimum);
                $maximum = str_replace(",", ".", $maximum);
            }
            $collection=!empty($post['multiple'])||in_array($type,['repeater','pagetable'],true);
            $extra=[];
            if(!empty($post['multiple']))$extra['multiple']=true;
            if($collection)$extra['max_items']=self::string($post['max_items']??'50');
            if($type==='repeater')$extra['fields']=$post['item_fields']??[];
            $this->model->saveField(
                [
                    "key" => self::string($post["key"] ?? ""),
                    "label" => self::string($post["label"] ?? ""),
                    "type" => $type,
                    "required" =>
                        $required === true ||
                        $required === 1 ||
                        $required === "1",
                    "min" => trim($minimum),
                    "max" => trim($maximum),
                    "options" => $options,
                    "show_when" => FieldRuleEditor::submitted(
                        $post["show_when"] ?? null,
                    ),
                    "required_when" => FieldRuleEditor::submitted(
                        $post["required_when"] ?? null,
                    ),
                ]+$extra+\Kulturhus\Native\Web\Fields\FieldAccessSettings::submitted($post)+$providers,
                $version,
            );
            return;
        }
        if ($operation === "model-template") {
            $fields = $post["fields"] ?? [];
            if (!is_array($fields) || !array_is_list($fields)) {
                throw new InvalidArgumentException(
                    "Vorlagenfelder müssen als Liste ausgewählt werden.",
                );
            }
            $this->model->saveTemplate(
                [
                    "key" => self::string($post["key"] ?? ""),
                    "label" => self::string($post["label"] ?? ""),
                    "renderer" => self::string($post["renderer"] ?? "standard"),
                    "fields" => $fields,
                    "groups" => \Kulturhus\Native\Web\Fields\GroupForm::submitted(
                        array_key_exists("groups", $post)
                            ? $post["groups"]
                            : [],
                        array_key_exists("field_groups", $post)
                            ? $post["field_groups"]
                            : [],
                        $fields,
                        $this->model->managedFieldKeys(),
                    ),
                ]+\Kulturhus\Native\Web\Fields\TemplateSettings::submitted($post,$fields),
                $version,
            );
            return;
        }
        throw new DomainException("Unbekannte Modellaktion.");
    }

    public function templateChoices(): array
    {
        $choices = [];
        foreach ($this->model->templates() as $template) {
            $choices[$template["key"]] = $template["label"];
        }
        return $choices;
    }

    /** Controls only, intended to be included inside the editor's page-save form. */
    public function fields(string $templateKey, array $values = [],?array $captured=null): string
    {
        $this->access->require("website", "read");
        $template = $this->model->template($templateKey);
        if (!$template["fields"]) {
            return ('<p class="hint">' . Html::e($this->contentText('Diese Vorlage hat keine zusätzlichen Felder.')) . '</p>');
        }
        $definitions = $captured??FieldPolicy::editorDefinitions($this->db,$this->access,$this->model->templateFields($template));
        $definitions=\Kulturhus\Native\Fieldtypes\Runtime::editorDefinitions($this->db,$this->access,$definitions,$captured);
        $values=array_intersect_key($values,$definitions);
        $states = FieldConditions::states($definitions, $values);
        $rules = array_filter(
            $definitions,
            static fn($field) => isset($field["show_when"]) ||
                isset($field["required_when"]),
        );
        $conditionAttribute = $rules
            ? ' data-field-conditions="' .
                Html::e(
                    json_encode(
                        array_map(
                            static fn($field) => array_intersect_key(
                                $field,
                                array_flip([
                                    "type",
                                    "required",
                                    "show_when",
                                    "required_when",
                                    "display",
                                ]),
                            ),
                            $definitions,
                        ),
                        JSON_THROW_ON_ERROR,
                    ),
                ) .
                '"'
            : "";
        $html =
            '<div class="model-fields" data-template="' .
            Html::e($templateKey) .
            '"' .
            $conditionAttribute .
            ('><h3>' . Html::e($this->contentText('Zusätzliche Angaben')) . '</h3>');
        $controls = new \Kulturhus\Native\Web\Fields\Controls(
            $this->db,
            $this->access,
            true,
        );
        $fragments = [];
        foreach ($template["fields"] as $key) {
            if(!isset($definitions[$key])){$fragments[$key]="";continue;}
            $field = $definitions[$key];
            $field["required"] = $states[$key]["required"];
            if(($field['display']??'visible')==='hidden')$fragments[$key]='<div data-model-field="'.Html::e($key).'" hidden></div>';
            else $fragments[$key] = $controls->render($field, $values[$key] ?? null);
        }
        $html .= \Kulturhus\Native\Web\Fields\Layout::render(
            $template,
            $fragments,
        );
        return $html .
            ($rules
                ? '<p class="hint" role="status" aria-live="polite" data-field-condition-status></p>'
                : "") .
            "</div>";
    }

    /** $submitted is the custom_fields sub-array, not the full HTTP body. */
    public function values(string $templateKey, array $submitted,?array $captured=null): array
    {
        $this->access->require("website", "read");
        $template = $this->model->template($templateKey);
        if (array_diff(array_keys($submitted), $template["fields"])) {
            throw new InvalidArgumentException(
                "Ein übermitteltes Feld gehört nicht zu dieser Vorlage.",
            );
        }
        $result = [];
        $definitions = $this->model->templateFields($template);
        foreach ($template["fields"] as $key) {
            $definition = $definitions[$key];
            if(!array_key_exists($key,$submitted))continue;
            if(($definition['display']??'visible')!=='visible'){if(array_key_exists($key,$submitted))$result[$key]=$submitted[$key];continue;}
            $value = $submitted[$key] ?? null;
            if($definition['type']==='password'&&!array_key_exists($key,$submitted))$value='';
            if(\Kulturhus\Native\Cms\FieldValues::collection($definition)&&(!is_array($value)||($value['__complete']??null)!=='1'))throw new InvalidArgumentException('Das Formular wurde nicht vollständig übertragen. Angaben prüfen und erneut speichern.');
            if (
                is_string($value) &&
                in_array(
                    $definition["type"],
                    ["integer", "decimal", "boolean", "date"],
                    true,
                )
            ) {
                $value = trim($value);
            }
            if ($definition["type"] === "decimal" && is_string($value)) {
                $value = str_replace(",", ".", $value);
            }
            if (
                $definition["type"] === "integer" &&
                is_string($value) &&
                preg_match("/\A(-?)([0-9]+)\z/D", $value, $match)
            ) {
                $value = ltrim($match[2], "0") ?: "0";
                if ($match[1] === "-" && $value !== "0") {
                    $value = "-" . $value;
                }
            }
            if(\Kulturhus\Native\Cms\FieldValues::collection($definition))$value=$this->nestedFormValue($definition,$value);
            $result[$key] = $value;
            $definitions[$key] = $definition;
        }
        // Empty password controls preserve saved secrets in the domain layer, which owns the original.
        $result=\Kulturhus\Native\Fieldtypes\Runtime::formValues($this->db,$this->access,$definitions,$result,$captured);
        foreach($definitions as$key=>$definition)$definitions[$key]=$this->formDefinition($definition);
        // Protected item stubs and unchanged nested secrets can only be resolved against the stored page.
        if(array_diff(array_keys($definitions),array_keys($result))||array_filter($definitions,static fn($field)=>\Kulturhus\Native\Cms\FieldValues::collection($field)||$field['type']==='password'||($field['display']??'visible')!=='visible'))return $result;
        return FieldConditions::values($definitions, $result);
    }

    private function fieldForm(
        ?array $field = null,
        array $sources = [],
    ): string {
        $existing = $field !== null;
        $field ??= [
            "key" => "",
            "label" => "",
            "type" => "text",
            "required" => false,
            "min" => null,
            "max" => null,
            "options" => [],
            "version" => 0,
        ];
        $html =
            $this->form("model-field") .
            Html::hidden("version", $field["version"]);
        $html .=
            '<div class="fields">' .
            Html::input(
                "label",
                $this->contentText("Feldbezeichnung"),
                $field["label"],
                "text",
                'required maxlength="160"',
            ) .
            Html::input(
                "key",
                $this->contentText("Feldkennung"),
                $field["key"],
                "text",
                'required maxlength="64" pattern="[a-z][a-z0-9_]{0,63}"' .
                    ($existing ? " readonly" : ""),
            ) .
            "</div>";
        $html .=
            ('<p class="hint">' . Html::e($this->contentText('Die Kennung verbindet Feld und Seiteninhalt. Sie beginnt mit einem Kleinbuchstaben; danach sind auch Zahlen und Unterstriche erlaubt.')) . '</p>');
        $html .=
            \Kulturhus\Native\Fieldtypes\View::render($field, $this->contentOptions(self::TYPE_LABELS),\Kulturhus\Native\Fieldtypes\Factory::registry($this->db,$this->access->club()),interfaceText:$this->contentText(...)) .
            '<label class="check"><input class="check" type="checkbox" name="required" value="1"' .
            ($field["required"] ? " checked" : "") .
            ('> ' . Html::e($this->contentText('Pflichtfeld')) . '</label>');
        $html .=
            '<div class="fields">' .
            Html::input("min", $this->contentText("Untergrenze (optional)"), $field["min"] ?? "") .
            Html::input("max", $this->contentText("Obergrenze (optional)"), $field["max"] ?? "") .
            ('</div><p class="hint">' . Html::e($this->contentText('Text: Zeichenanzahl. Zahlen: Zahlenwert. Datum: JJJJ-MM-TT. Bei Ja/Nein und Verknüpfungen bleiben beide Grenzen leer.')) . '</p>');
        $html .=
            ('<label>' . Html::e($this->contentText('Auswahloptionen')) . '<textarea class="form-control" name="options" rows="4" maxlength="50000">') .
            Html::e(implode("\n", $field["options"])) .
            ('</textarea></label><p class="hint">' . Html::e($this->contentText('Nur für den Feldtyp Auswahl: eine Option pro Zeile.')) . '</p>');
        $html.=('<details><summary>' . Html::e($this->contentText('Mehrfachwerte und Datensätze')) . '</summary><label class="check"><input class="check" type="checkbox" name="multiple" value="1"').(!empty($field['multiple'])?' checked':'').('> ' . Html::e($this->contentText('Mehrere Werte (außer Passwort, Datensatz und Seitentabelle)')) . '</label>').Html::input('max_items',$this->contentText('Höchstens Einträge'),$field['max_items']??50,'number','min="1" max="50"').('<label>' . Html::e($this->contentText('Unterfelder für Datensätze')) . '<select class="form-control" name="item_fields[]" multiple size="5">');
        foreach($sources as$source)if($source['key']!==$field['key'])$html.='<option value="'.Html::e($source['key']).'"'.(in_array($source['key'],$field['fields']??[],true)?' selected':'').'>'.Html::e($source['label']).'</option>';
        $html.='</select></label></details>';
        $html .= (new FieldRuleEditor($this->db, $this->access))->render(
            $field,
            $sources,
        );
        $html.=\Kulturhus\Native\Web\Fields\FieldAccessSettings::render($field,interfaceText:$this->contentText(...));
        return $html .
            '<button class="btn btn-solid theme-primary">' .
            ($existing ? Html::e($this->contentText("Feldänderung speichern")) : Html::e($this->contentText("Feld anlegen"))) .
            "</button></form>";
    }

    private function templateForm(?array $template, array $fields): string
    {
        $existing = $template !== null;
        $template ??= [
            "key" => "",
            "label" => "",
            "renderer" => "standard",
            "fields" => [],
            "version" => 0,
        ];
        $html =
            $this->form("model-template") .
            Html::hidden("version", $template["version"]);
        $html .=
            '<div class="fields">' .
            Html::input(
                "label",
                $this->contentText("Vorlagenname"),
                $template["label"],
                "text",
                'required maxlength="160"',
            ) .
            Html::input(
                "key",
                $this->contentText("Vorlagenkennung"),
                $template["key"],
                "text",
                'required maxlength="64" pattern="[a-z][a-z0-9_]{0,63}"' .
                    ($existing ? " readonly" : ""),
            ) .
            "</div>";
        $html .= Html::select(
            "renderer",
            $this->contentText("Grundlayout"),
            $this->contentOptions(self::RENDERERS),
            $template["renderer"],
        );
        $html .= \Kulturhus\Native\Web\Fields\GroupForm::render(
            $template,
            $fields,
            interfaceText:$this->contentText(...),
        );
        $html.=\Kulturhus\Native\Web\Fields\TemplateSettings::render($template,$fields,$this->model->templates(),\Kulturhus\Native\TemplateRenderers\Factory::registry($this->db,$this->access->club()),interfaceText:$this->contentText(...));
        $html .=
            ('<p class="hint">' . Html::e($this->contentText('Titel, Adresse, Inhalt, SEO und Galerie bleiben Standardangaben jeder Seite. Die ausgewählten Zusatzfelder ergänzen sie.')) . '</p>');
        return $html .
            '<button class="btn btn-solid theme-primary">' .
            ($existing ? Html::e($this->contentText("Vorlagenänderung speichern")) : Html::e($this->contentText("Vorlage anlegen"))) .
            "</button></form>";
    }

    private function form(string $operation): string
    {
        $state=\Kulturhus\Native\Cms\ModelCatalogue::state($this->modelState);
        $hidden='';foreach($state as$key=>$value)$hidden.=Html::hidden($key,$value);
        return '<form method="post" action="'.Html::e($this->modelUrl()).'" data-model-form>' .
            Html::hidden("csrf", $this->csrf) .
            Html::hidden("operation", $operation).$hidden;
    }

    private static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException(
                "Ein Formularwert muss Text sein.",
            );
        }
        return $value;
    }

    private function formDefinition(array$field):array
    {
        if($field['type']==='password')$field['required']=false;
        foreach($field['item_definitions']??[]as$key=>$child)$field['item_definitions'][$key]=$this->formDefinition($child);
        return $field;
    }

    private function nestedFormValue(array$field,mixed$value):mixed
    {
        if(\Kulturhus\Native\Cms\FieldValues::collection($field)&&is_array($value)){
            foreach($value as$i=>$item){
                if($field['type']==='repeater'&&is_array($item)&&is_array($item['values']??null))foreach($field['item_definitions']as$key=>$child)if(array_key_exists($key,$item['values']))$value[$i]['values'][$key]=$this->nestedFormValue($child,$item['values'][$key]);
                elseif($field['type']!=='repeater'){$scalar=$field;unset($scalar['multiple']);if($scalar['type']==='pagetable')$scalar['type']='page-reference';if(is_array($item)&&array_key_exists('value',$item))$value[$i]['value']=$this->nestedFormValue($scalar,$item['value']);else$value[$i]=$this->nestedFormValue($scalar,$item);}
            }
            return $value;
        }
        if(is_string($value)&&in_array($field['type'],['integer','decimal','boolean','date','datetime'],true))$value=trim($value);
        if($field['type']==='decimal'&&is_string($value))$value=str_replace(',','.',$value);
        if($field['type']==='integer'&&is_string($value)&&preg_match('/^(-?)([0-9]+)$/D',$value,$parts)){$value=ltrim($parts[2],'0')?:'0';if($parts[1]==='-'&&$value!=='0')$value='-'.$value;}
        return $value;
    }
}
