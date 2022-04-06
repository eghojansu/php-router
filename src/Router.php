<?php

declare(strict_types=1);

namespace Ekok\Router;

use Ekok\Utils\Arr;
use Ekok\Utils\Val;
use Ekok\Utils\Call;
use Ekok\Utils\File;
use Ekok\Router\Attribute\Route as AttributeRoute;

class Router
{
    const ROUTE_PARAMS = '/(?:\/?@(\w+)(?:(?::([^\/?]+)|(\*)))?(\?)?)/';
    const ROUTE_PATTERN = '/^\s*([\w|]+)(?:\s*@([^\s]+))?(?:\s*(\/[^\s]*))?(?:\s*\[([^\]]+)\])?\s*$/';

    /** @var array */
    private $routes = array();

    /** @var array */
    private $aliases = array();

    public function __construct(array $routes = null)
    {
        $this->routeAll($routes ?? array());
    }

    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function alias(string $alias, array|string $args = null): string
    {
        $path = $this->aliases[$alias] ?? ('/' . ltrim($alias, '/'));

        if ($args && is_string($args)) {
            parse_str($args, $params);
        } else {
            $params = $args ?? array();
        }

        if (false !== strpos($path, '@')) {
            $path = preg_replace_callback(
                self::ROUTE_PARAMS,
                static function ($match) use ($alias, &$params) {
                    $param = $params[$match[1]] ?? null;

                    if (!$param && !$match[4]) {
                        throw new \LogicException(sprintf('Route param required: %s@%s', $match[1], $alias));
                    }

                    if ($param) {
                        unset($params[$match[1]]);
                    }

                    return $param ? '/' . urldecode($param) : null;
                },
                $path,
                flags: PREG_UNMATCHED_AS_NULL,
            );
        }

        if ($params) {
            $path .= '?' . http_build_query($params);
        }

        return $path;
    }

    public function routeAll(array $routes): static
    {
        array_walk($routes, fn ($handler, $route) => $this->route($route, $handler));

        return $this;
    }

    public function route(string $route, callable|string|null $handler = null): static
    {
        $found = preg_match(self::ROUTE_PATTERN, $route, $matches, PREG_UNMATCHED_AS_NULL);

        if (!$found) {
            throw new \LogicException(sprintf('Invalid route: "%s"', $route));
        }

        list(, $verbs, $alias, $usePattern, $attributes) = $matches;

        $pattern = $usePattern ?? $this->aliases[$alias] ?? null;

        if (!$pattern) {
            throw new \LogicException(
                $alias ?
                    sprintf('Route not exists: %s', $alias) :
                    sprintf('No path defined in route: "%s"', $route)
            );
        }

        $set = compact('handler', 'alias') + $this->parseAttributes($attributes);

        if ($alias) {
            $this->aliases[$alias] = $pattern;
        }

        foreach (explode('|', strtoupper($verbs)) as $verb) {
            $this->routes[$pattern][$verb] = $set;
        }

        return $this;
    }

    public function rest(
        string $name,
        string|object $class,
        string $prefix = null,
        string $attrs = null,
    ): static {
        $path = ($prefix ?? '/') . $name;
        $param = '/@' . $name;

        return $this->routeAll(array(
            'GET @' . $name . '.index ' . $path . ' ' . $attrs => Call::standarize($class, 'index'),
            'POST @' . $name . '.index ' . $attrs => Call::standarize($class, 'store'),
            'GET @' . $name . '.item ' . $path . $param . ' ' . $attrs => Call::standarize($class, 'show'),
            'PUT|PATCH @' . $name . '.item ' . $attrs => Call::standarize($class, 'update'),
            'DELETE @' . $name . '.item ' . $attrs => Call::standarize($class, 'destroy'),
        ));
    }

    public function resource(
        string $name,
        string|object $class,
        string $prefix = null,
        string $attrs = null,
    ): static {
        $path = ($prefix ?? '/') . $name;
        $param = '/@' . $name;

        return $this->routeAll(array(
            'GET @' . $name . '.index ' . $path . ' ' . $attrs => Call::standarize($class, 'index'),
            'GET @' . $name . '.create ' . $path . '/create ' . $attrs => Call::standarize($class, 'create'),
            'POST @' . $name . '.store ' . $path . ' ' . $attrs => Call::standarize($class, 'store'),
            'GET @' . $name . '.show ' . $path . $param . ' ' . $attrs => Call::standarize($class, 'show'),
            'GET @' . $name . '.edit ' . $path . $param . '/edit ' . $attrs => Call::standarize($class, 'edit'),
            'PUT|PATCH @' . $name . '.update ' . $path . $param . ' ' . $attrs => Call::standarize($class, 'update'),
            'DELETE @' . $name . '.destroy ' . $path . $param . ' ' . $attrs => Call::standarize($class, 'destroy'),
        ));
    }

    public function loadClass(string|object $class): static
    {
        $ref = new \ReflectionClass($class);

        if (!$ref->isInstantiable()) {
            return $this;
        }

        $group = $this->buildGroup($ref);

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$attrs = $method->getAttributes(AttributeRoute::class)) {
                continue;
            }

            /** @var AttributeRoute */
            $attr = $attrs[0]->newInstance();

            $route = $this->buildAttr($attr, $group);
            $handler = Call::standarize($class, $method->name);

            $this->route($route, $handler);
        }

        return $this;
    }

    public function load(string $directory): static
    {
        $classes = File::getClassByScan($directory . '/*.php');

        array_walk($classes, fn (string $class) => $this->loadClass($class));

        return $this;
    }

    public function match(string $path, string $method = null, array &$matches = null): array|null
    {
        $args = null;
        $matches = $this->routes[$path] ?? $this->find($path, $args);
        $match = $matches[$method ?? 'GET'] ?? $matches[strtoupper($verb ?? 'get')] ?? null;

        if ($match) {
            $match += compact('args');

            if ($args && is_string($match['handler'])) {
                $match['handler'] = str_replace(
                    array_map(static fn ($arg) => "@$arg", array_keys($args)),
                    $args,
                    $match['handler'],
                );
            }
        }

        return $match;
    }

    private function find(string $path, array &$args = null): array|null
    {
        return Arr::first(
            $this->routes,
            function (array $routes, string $pattern) use ($path, &$args) {
                return $this->matchPattern($pattern, $path, $args) ? $routes : null;
            },
        );
    }

    private function matchPattern(string $pattern, string $path, array &$args = null): bool
    {
        $match = !!preg_match($this->regexify($pattern), $path, $matches);
        $args = array_filter(array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));

        return $match;
    }

    private function regexify(string $pattern): string
    {
        return (
            '#^' .
            preg_replace_callback(
                self::ROUTE_PARAMS,
                static fn (array $match) => (
                    '/' .
                    $match[4] .
                    '(?<' .
                        $match[1] .
                        '>' .
                        ($match[3] ? '.*' : ($match[2] ?? '[\w-]+')) .
                    ')'
                ),
                $pattern,
                flags: PREG_UNMATCHED_AS_NULL,
            ) .
            '/?$#'
        );
    }

    private function parseAttributes(string|null $attrs): array
    {
        return Arr::reduce(
            $attrs ? array_filter(explode(',', $attrs), 'trim') : array(),
            static function (array $attrs, string $line) {
                list($tag, $value) = array_map('trim', explode('=', $line . '='));

                if ('' === $value) {
                    $attrs['tags'][] = Val::cast($tag);
                } elseif (false !== strpos($value, ';')) {
                    $attrs[$tag] = array_map(array(Val::class, 'cast'), array_filter(explode(';', $value), 'trim'));
                } elseif (isset($attrs[$tag])) {
                    if (!is_array($attrs[$tag])) {
                        $attrs[$tag] = (array) $attrs[$tag];
                    }

                    $attrs[$tag][] = Val::cast($value);
                } else {
                    $attrs[$tag] = Val::cast($value);
                }

                return $attrs;
            },
            array(),
        );
    }

    private function buildGroup(\ReflectionClass $ref): array
    {
        if ($attrs = $ref->getAttributes(AttributeRoute::class)) {
            /** @var AttributeRoute */
            $attr = $attrs[0]->newInstance();

            return array(
                'path' => rtrim($attr->path ?? '', '/') . '/',
                'name' => $attr->name,
                'verbs' => $attr->verbs ?? 'GET',
                'attrs' => $attr->attrs ?? array(),
            );
        }

        return array(
            'path' => '/',
            'name' => null,
            'verbs' => 'GET',
            'attrs' => array(),
        );
    }

    private function buildAttr(AttributeRoute $attr, array $group): string
    {
        $route = $attr->verbs ?? $group['verbs'];
        $attrs = array_merge($group['attrs'], $attr->attrs ?? array());

        if ($attr->name) {
            $route .= ' @' . $group['name'] . $attr->name;
        }

        if ($attr->path) {
            $route .= ' ' . $group['path'] . ltrim($attr->path, '/');
        }

        if ($attrs) {
            $route .= ' [' . Arr::reduce(
                $attrs,
                static function ($attrs, $value, $tag) {
                    if ($attrs) {
                        $attrs .= ',';
                    }

                    if (is_numeric($tag)) {
                        $attrs .= is_array($value) ? implode(',', $value) : $value;
                    } elseif (is_array($value)) {
                        $attrs .= $tag . '=' . implode(';', $value);
                    } else {
                        $attrs .= $tag . '=' . $value;
                    }

                    return $attrs;
                },
            ) . ']';
        }

        return $route;
    }
}
