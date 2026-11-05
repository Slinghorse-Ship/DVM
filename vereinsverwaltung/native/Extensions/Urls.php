<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;

/** Read-only extension namespace; cannot replace authentication or CMS routes. */
final class Urls
{
    private array $routes = [];
    public function __construct(private readonly Hooks $hooks) {}
    public function register(string $pattern, Contract $contract, \Closure $handler): void
    {
        if ($contract->kind !== 'url' || !preg_match('~^/extensions/[a-z][a-z0-9-]*(?:/(?:[a-z0-9-]+|\{[a-z][a-z0-9_]*\}))*$~D', $pattern)) throw new \DomainException('Erweiterungsadresse benötigt den reservierten Namensraum.');
        $parts = explode('/', $pattern); $names = []; $regex = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{([a-z][a-z0-9_]*)\}$/D', $part, $match)) {
                if (in_array($match[1], $names, true)) throw new \DomainException('Doppelter Adressparameter.');
                $names[] = $match[1]; $regex[] = '([a-zA-Z0-9_-]{1,128})';
            } else $regex[] = preg_quote($part, '~');
        }
        $expression = '~^'.implode('/', $regex).'$~D';
        foreach ($this->routes as [$existingNames,$existingName,$existingHandler,$existingPattern]) {
            $other=explode('/',$existingPattern);
            if(count($other)!==count($parts))continue;
            $overlap=true;
            foreach($parts as $index=>$part)if($part!==$other[$index]&&!str_starts_with($part,'{')&&!str_starts_with($other[$index],'{')){$overlap=false;break;}
            if($overlap)throw new \DomainException('Erweiterungsadresse bereits vergeben.');
        }
        $this->hooks->define($contract);
        $this->routes[$expression] = [$names,$contract->name,$handler,$pattern];
    }
    public function remove(string $owner): void
    {
        foreach($this->routes as $key=>$route)if(str_starts_with($route[1],'extension.'.$owner.'.'))unset($this->routes[$key]);
    }
    public function dispatch(string $method, string $path): ?array
    {
        if (!str_starts_with($path, '/extensions/')) return null;
        if (!in_array($method, ['GET','HEAD'], true)) return ['status'=>405,'headers'=>['Allow'=>'GET, HEAD'],'body'=>''];
        if (strlen($path)>1024 || preg_match('/[%\\\\\x00-\x20?#]/', $path)) return ['status'=>404,'headers'=>[],'body'=>''];
        foreach ($this->routes as $expression=>[$names,$name,$handler]) {
            if (!preg_match($expression,$path,$matches)) continue;
            array_shift($matches);
            $response = $this->hooks->dispatch($name, array_combine($names,$matches), $handler);
            if ($method === 'HEAD') $response['body'] = '';
            return $response;
        }
        return ['status'=>404,'headers'=>[],'body'=>''];
    }
}
