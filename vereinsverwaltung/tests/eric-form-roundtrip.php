<?php declare(strict_types=1);
// Opt-in, local XML serialization test. Never transmits, and contains no SDK data.
if(getenv('VEREIN_ERIC_TEST')!=='synthetic-only')throw new RuntimeException('Synthetic ERiC test mode required.');
require __DIR__.'/../src/Finance/Tax/TaxEricAdapter.php';require __DIR__.'/../src/Finance/Tax/TaxFormSchema.php';
$in=json_decode((string)stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
$c=\Kulturhus\Verein\TaxEricAdapter::configuration();$s=\Kulturhus\Verein\TaxFormSchema::load($in['data_version']);
foreach(array_diff_key($in['fields'],$s->fields($in['counts'])) as $field=>$unused)throw new RuntimeException('Unknown synthetic field: '.\Kulturhus\Verein\TaxFormSchema::path($field));
$attachments=[];foreach($in['attachments']??[] as $file)$attachments[]=['name'=>$file['name'],'bytes'=>base64_decode($file['base64'],true)];
echo $s->xml($in['fields'],$in['counts'],$in['header'],'test',$c['manufacturer_id'],str_repeat('a',32),$attachments);
