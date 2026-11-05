<?php declare(strict_types=1);
/** Pure HTTP preflight through both real routers, without starting a browser. */
function ownNavigationHttp(array $fixture):void
{
    $checks=0;$committed=0;$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;};
    $request=static function(string $url,array &$cookies,?array $data=null,bool $json=false):array{
        $headers=['Connection: close','Accept: '.($json?'application/json':'text/html')];
        if($cookies!==[])$headers[]='Cookie: '.implode('; ',array_map(static fn($key,$value):string=>$key.'='.$value,array_keys($cookies),array_values($cookies)));
        $options=['method'=>$data===null?'GET':'POST','header'=>$headers,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>30];
        if($data!==null){$options['header'][]='Content-Type: application/x-www-form-urlencoded';$options['content']=http_build_query($data);}
        $body=file_get_contents($url,false,stream_context_create(['http'=>$options]));if($body===false)throw new RuntimeException('Own menu HTTP response unavailable.');$status=0;
        foreach($http_response_header as$line){if(preg_match('/^HTTP\/\S+ ([0-9]{3}) /',$line,$m))$status=(int)$m[1];if(preg_match('/^Set-Cookie: ([^=;]+)=([^;]*)/i',$line,$m))$cookies[$m[1]]=$m[2];}
        return ['status'=>$status,'body'=>$body];
    };
    $form=static function(string $html):array{
        $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xpath=new DOMXPath($dom);$values=[];
        foreach($xpath->query('//form[@data-navigation-editor]//input[@name]')as$field){if($field->hasAttribute('disabled')||($field->getAttribute('type')==='checkbox'&&!$field->hasAttribute('checked')))continue;$values[$field->getAttribute('name')]=$field->getAttribute('value');}
        if(!isset($values['csrf'],$values['navigation_actor'],$values['navigation_club'],$values['version']))throw new RuntimeException('Actual menu form is incomplete.');return $values;
    };
    foreach($fixture['origins']as$app=>$origin){
        $url=$origin.($app==='native'?'/?view=navigation':'/vereinsverwaltung/?view=website&tab=navigation');$cookies=[];$remote=[];
        $check($request($origin.'/own-test-enter',$cookies)['status']===303,'actual own authentication '.$app);$request($origin.'/own-test-enter?role=editor',$remote);
        $opened=$request($url,$cookies);$check($opened['status']===200,'actual menu opens '.$app);$initial=$form($opened['body']);
        if(!isset($initial['automatic_program_year'])){$initial['automatic_program_year']='1';$check($request($url,$cookies,$initial)['status']===303,'authorized owner sets automatic label for readonly-true regression '.$app);$committed++;}
        $initialEditorCookies=[];$request($origin.'/own-test-enter?role=editor',$initialEditorCookies);$initialEditor=$request($url,$initialEditorCookies);$initialEditorForm=$form($initialEditor['body']);
        $check(($initialEditorForm['automatic_program_year']??null)==='1'&&!str_contains($initialEditor['body'],$fixture['programme'])&&!str_contains($initialEditor['body'],'Own inaccessible programme original'),'inaccessible programme disables change while hidden true preserves actual normal POST '.$app);
        $forbidden=$initialEditorForm;unset($forbidden['automatic_program_year']);$forbidden['items['.$fixture['items']['first'].'][label]']='Own forbidden automatic proposal';$refused=$request($url,$initialEditorCookies,$forbidden);$check($refused['status']>=400&&!str_contains($refused['body'],'Own forbidden automatic proposal'),'forged automatic toggle cannot recover private programme or own forbidden proposal '.$app);
        $initialEditorForm['items['.$fixture['items']['first'].'][label]']='Allowed initial publisher '.$app;$check($request($url,$initialEditorCookies,$initialEditorForm)['status']===303,'unchanged disabled true permits allowed row save '.$app);$committed++;
        $post=$form($request($url,$cookies)['body']);$old=(int)$post['version'];$label='Own HTTP proposed label '.$app.' & ä🙂';$key='items['.$fixture['items']['first'].']';$post[$key.'[label]']=$label;$post[$key.'[position]']='19';unset($post[$key.'[visible]'],$post['automatic_program_year']);
        $other=$form($request($url,$remote)['body']);$other[$key.'[label]']='Parallel HTTP original '.$app;
        $check($request($url,$remote,$other)['status']===303,'parallel real menu save '.$app);$committed++;
        $conflict=$request($url,$cookies,$post);$check($conflict['status']===409,'actual stale HTTP409 '.$app);$recovered=$form($conflict['body']);
        $check($recovered[$key.'[label]']===$label&&$recovered[$key.'[position]']==='19'&&(int)$recovered['version']===$old&&!isset($recovered[$key.'[visible]'])&&!isset($recovered['automatic_program_year']),'409 retains exact human values and old CAS '.$app);
        $check(str_contains($conflict['body'],'data-unsaved-changes')&&str_contains($conflict['body'],'Parallel HTTP original '.$app),'409 shows separate current and unsaved proposal '.$app);
        $compared=$request($url,$cookies,$recovered+['navigation_browse'=>'compare']);$check($compared['status']===200&&(int)$form($compared['body'])['version']===$old,'compare keeps original CAS '.$app);
        $adopt=$request($url,$cookies,$form($compared['body'])+['navigation_browse'=>'resume']);$check($adopt['status']===200,'explicit actual no-save adoption '.$app);$adopted=$form($adopt['body']);
        $check((int)$adopted['version']===$old+1&&$adopted[$key.'[label]']===$label&&str_contains($adopt['body'],'data-unsaved-changes'),'adoption advances only proposal CAS '.$app);
        $check(str_contains($request($url,$remote)['body'],'Parallel HTTP original '.$app),'no-save adoption leaves persisted original intact '.$app);
        $bad=$adopted;$bad[$key.'[label]']='<img src=x onerror=alert(1)>';$bad[$key.'[position]']='own invalid position';$invalid=$request($url,$cookies,$bad);$check($invalid['status']===422,'actual invalid HTTP422 '.$app);$own=$form($invalid['body']);
        $check($own[$key.'[label]']===$bad[$key.'[label]']&&$own[$key.'[position]']===$bad[$key.'[position]']&&$own['version']===$adopted['version']&&!str_contains($invalid['body'],'<img src=x'),'422 preserves escaped invalid values and adopted CAS '.$app);
        $json=$request($url,$cookies,$post,true);$jsonBody=json_decode($json['body'],true,32,JSON_THROW_ON_ERROR);$check($json['status']===409&&isset($jsonBody['error'])&&!isset($jsonBody['html']),'JSON409 does not replace browser input '.$app);
        if($app==='integrated'){$json=$request($url,$cookies,$post+['navigation_browse'=>'compare'],true);$dto=json_decode($json['body'],true,32,JSON_THROW_ON_ERROR);$check($json['status']===200&&$dto['unsaved']===true&&str_contains($dto['html'],'data-unsaved-changes'),'integrated JSON compare declares no save '.$app);}
        foreach(['csrf'=>'forged','navigation_actor'=>'another-actor','navigation_club'=>'another-club']as$name=>$value){$bad=array_replace($adopted,[$name=>$value,$key.'[label]'=>'Own forbidden HTTP proposal']);$refused=$request($url,$cookies,$bad);$check($refused['status']>=400&&!str_contains($refused['body'],'Own forbidden HTTP proposal'),'wrong current context cannot recover own input '.$app.' '.$name);}
        $check($request($url,$cookies,$adopted)['status']===303,'explicit subsequent actual save '.$app);$committed++;
        $final=$form($request($url,$cookies)['body']);$check((int)$final['version']===$old+2&&$final[$key.'[label]']===$label,'actual saved proposal committed exactly once '.$app);
        $check($request($url,$cookies,$adopted)['status']===409,'same intent replay cannot create second revision '.$app);
        $editorCookies=[];$request($origin.'/own-test-enter?role=editor',$editorCookies);$read=$request($url,$editorCookies);$editor=$form($read['body']);$lockedKey='items['.$fixture['locked'].']';
        $check(str_contains($read['body'],'data-navigation-readonly')&&str_contains($read['body'],'Locked original &amp; ä🙂')&&!array_key_exists($lockedKey.'[label]',$editor),'mixed ACL exposes read-only original without submitting it '.$app);
        $editor[$key.'[label]']='Allowed HTTP publisher '.$app;$check($request($url,$editorCookies,$editor)['status']===303,'ordinary publisher can save only allowed rows '.$app);$committed++;
        $current=$request($url,$editorCookies);$check(str_contains($current['body'],'Locked original &amp; ä🙂'),'skipped locked override remains exact '.$app);
        $forged=$form($current['body']);$forged[$lockedKey.'[label]']='Own forbidden locked proposal';$forged[$lockedKey.'[position]']='3';$denied=$request($url,$editorCookies,$forged);$check($denied['status']>=400&&!str_contains($denied['body'],'Own forbidden locked proposal'),'forged locked row cannot recover original '.$app);
    }
    $result=['suite'=>'native-navigation-http','passed'=>$checks,'committed_saves'=>$committed,'external_actions'=>0];file_put_contents($fixture['artifacts'].'/result.json',json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL);echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
}
