<?php declare(strict_types=1);
namespace Kulturhus\Native\Routing;

use InvalidArgumentException;
use JsonSerializable;

/** Immutable request metadata, kept separate from every saved original and custom field payload. */
final class Context implements JsonSerializable
{
    public function __construct(
        public readonly string $pageId,
        public readonly int $publishedRevision,
        public readonly string $sourceSlug,
        public readonly string $basePath,
        public readonly ?string $locale,
        public readonly ?string $contentLocale,
        public readonly array $parameters,
        public readonly int $page,
        public readonly string $pageParameter,
        public readonly Policy $policy,
        public readonly RequestPath $request,
        public readonly Transport $transport,
        public readonly int $generation,
        public readonly bool $pathPage=false,
    ) {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$pageId)||$publishedRevision<1||$page<1||$page>Policy::MAX_PAGE||!in_array($pageParameter,['page','seite'],true)||($locale!==null&&!preg_match('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,3}\z/D',$locale)))throw new InvalidArgumentException('Veröffentlichten URL-Kontext prüfen.');
        $transport->base($sourceSlug);$transport->base($basePath);
    }
    public function eventQuery(array $query):array
    {
        $query=$this->policy->eventQuery($this->parameters,$query);
        if($this->sourceSlug==='veranstaltungen')$query['seite']=(string)$this->page;
        return$query;
    }
    public function accepts(array $published,Policy $policy):bool
    {
        return($published['id']??null)===$this->pageId&&($published['published_revision']??$published['published_version']??$published['version']??null)===$this->publishedRevision&&($published['slug']??null)===$this->sourceSlug&&($published['language_path']??$published['slug']??null)===$this->basePath&&hash_equals($this->policy->fingerprint(),$policy->fingerprint());
    }
    public function fingerprint():string
    {
        return hash('sha256',json_encode($this->jsonSerialize()+['query'=>$this->request->query,'origin'=>$this->transport->origin,'demo'=>$this->transport->demo,'policy'=>$this->policy->fingerprint()],JSON_THROW_ON_ERROR));
    }
    public function jsonSerialize():array
    {
        return ['page_id'=>$this->pageId,'published_revision'=>$this->publishedRevision,'source_slug'=>$this->sourceSlug,'base_path'=>$this->basePath,'language'=>$this->locale,'content_language'=>$this->contentLocale,'parameters'=>$this->parameters,'page'=>$this->page,'page_parameter'=>$this->pageParameter,'generation'=>$this->generation];
    }
    public function withBase(string $base,string $locale):self
    {
        return new self($this->pageId,$this->publishedRevision,$this->sourceSlug,$base,$locale,$locale,$this->parameters,$this->page,$this->pageParameter,$this->policy,$this->request,$this->transport,$this->generation,$this->pathPage);
    }
    public function withPage(int $page):self
    {
        return new self($this->pageId,$this->publishedRevision,$this->sourceSlug,$this->basePath,$this->locale,$this->contentLocale,$this->parameters,$page,$this->pageParameter,$this->policy,$this->request,$this->transport,$this->generation,$this->pathPage);
    }
}
