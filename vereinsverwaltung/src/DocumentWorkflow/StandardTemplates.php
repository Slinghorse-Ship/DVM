<?php declare(strict_types=1);
namespace Kulturhus\Verein\DocumentWorkflow;

/** Blank, owned work aids. Installing a template creates no assessment or approval. */
final class StandardTemplates
{
    public static function all():array
    {
        $rows=json_decode((string)file_get_contents(dirname(__DIR__,2).'/resources/document-workflows/standard-risk-assessments.json'),true,64,JSON_THROW_ON_ERROR);
        return array_column($rows,null,'key');
    }
    public static function install(WorkflowStore $store,string $key):string
    {
        if($store->actor()['access']!=='write')throw new WorkflowException('Keine Schreibberechtigung für Formularvorlagen.');
        $definition=self::all()[$key]??null;if(!$definition)throw new WorkflowException('Standardvorlage auswählen.');
        unset($definition['key']);
        foreach($store->templates()as$row)if(str_starts_with((string)($row['provenance']['adaptation']??''),'Standardvorlage '.$key.' (Fassung '))return $row['id'];
        $id=$store->saveTemplate('',0,$definition,substr(hash('sha256','dvm-standard-risk-v2:'.$key),0,32));
        $current=$store->template($id);
        if($current['version']===1&&$current['status']==='draft')$store->publishTemplate($id,1);
        return $id;
    }
}
