<?php

declare(strict_types=1);

namespace Ekok\Router\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS|\Attribute::TARGET_METHOD)]
class Route
{
    public function __construct(
        public string|null $path = null,
        public string|null $verbs = null,
        public string|null $name = null,
        public array|null $attrs = null,
    ) {}
}
