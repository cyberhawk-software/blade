<?php

namespace Cyberhawk\Blade;

use Closure;
use Illuminate\Container\Container as BaseContainer;

class Container extends BaseContainer
{
    protected array $terminatingCallbacks = [];

    protected string $basePath = '';

    public function useBasePath(string $basePath): static
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);

        return $this;
    }

    public function basePath(string $path = ''): string
    {
        if ($path === '' || $this->basePath === '') {
            return $this->basePath;
        }

        return $this->basePath.DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
    }

    public function terminating(Closure $callback): static
    {
        $this->terminatingCallbacks[] = $callback;

        return $this;
    }

    public function terminate(): void
    {
        foreach ($this->terminatingCallbacks as $terminatingCallback) {
            $terminatingCallback();
        }
    }
}
