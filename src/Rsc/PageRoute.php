<?php

namespace LaraBun\Rsc;

use Closure;

class PageRoute
{
    /** @var string|list<string> */
    protected string|array $middlewareValue = [];

    protected ?string $ability = null;

    protected ?string $abilityModel = null;

    /** @var Closure|list<string|array<string, string>>|null */
    protected Closure|array|null $staticPathsValue = null;

    protected ?Closure $viewDataCallback = null;

    /** @var Closure|array<string, mixed>|null */
    protected Closure|array|null $propsValue = null;

    protected ?string $nameValue = null;


    protected ?string $domainValue = null;

    /** @var array<string, string> */
    protected array $whereConstraints = [];

    public static function make(): static
    {
        return new static;
    }

    /**
     * @param  string|list<string>  $middleware
     */
    public function middleware(string|array $middleware): static
    {
        $this->middlewareValue = $middleware;

        return $this;
    }

    public function can(string $ability, ?string $model = null): static
    {
        $this->ability = $ability;
        $this->abilityModel = $model;

        return $this;
    }

    /**
     * @param  Closure|list<string|array<string, string>>  $paths
     */
    public function staticPaths(Closure|array $paths): static
    {
        $this->staticPathsValue = $paths;

        return $this;
    }

    public function viewData(Closure $callback): static
    {
        $this->viewDataCallback = $callback;

        return $this;
    }

    /**
     * Pass props to the React page component.
     * Accepts a static array or a closure for dynamic data.
     *
     * @param  Closure|array<string, mixed>  $props
     */
    public function props(Closure|array $props): static
    {
        $this->propsValue = $props;

        return $this;
    }

    /**
     * @return Closure|array<string, mixed>|null
     */
    public function getProps(): Closure|array|null
    {
        return $this->propsValue;
    }

    public function name(string $name): static
    {
        $this->nameValue = $name;

        return $this;
    }

    public function domain(string $domain): static
    {
        $this->domainValue = $domain;

        return $this;
    }

    public function where(string $param, string $pattern): static
    {
        $this->whereConstraints[$param] = $pattern;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getMiddleware(): array
    {
        return (array) $this->middlewareValue;
    }

    public function getAbility(): ?string
    {
        return $this->ability;
    }

    public function getAbilityModel(): ?string
    {
        return $this->abilityModel;
    }

    /**
     * @return Closure|list<string|array<string, string>>|null
     */
    public function getStaticPaths(): Closure|array|null
    {
        return $this->staticPathsValue;
    }

    public function getViewData(): ?Closure
    {
        return $this->viewDataCallback;
    }

    public function getName(): ?string
    {
        return $this->nameValue;
    }

    public function getDomain(): ?string
    {
        return $this->domainValue;
    }

    /**
     * @return array<string, string>
     */
    public function getWhereConstraints(): array
    {
        return $this->whereConstraints;
    }
}
