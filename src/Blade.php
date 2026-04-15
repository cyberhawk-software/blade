<?php

namespace Jenssegers\Blade;

use Illuminate\Config\Repository;
use Illuminate\Container\Container as BaseContainer;
use Illuminate\Contracts\Container\Container as ContainerInterface;
use Illuminate\Contracts\View\Factory as FactoryContract;
use Illuminate\Contracts\View\View;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Component;
use Illuminate\View\DynamicComponent;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\FileEngine;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use ReflectionMethod;

class Blade implements FactoryContract
{
    protected ContainerInterface $container;

    private Factory $factory;

    private BladeCompiler $compiler;

    public function __construct($viewPaths, string $cachePath, ?ContainerInterface $container = null)
    {
        $this->container = $container ?: new Container;

        if ($this->container instanceof Container && $this->container->basePath() === '') {
            $this->container->useBasePath(getcwd() ?: '');
        }

        $this->setupContainer((array) $viewPaths, $cachePath);
        $this->compiler = $this->createCompiler();
        $this->factory = $this->createFactory();

        $this->container->instance('blade.compiler', $this->compiler);
        $this->container->instance(BladeCompiler::class, $this->compiler);
        $this->container->instance('view', $this->factory);
        $this->container->instance(Factory::class, $this->factory);
        $this->container->instance(FactoryContract::class, $this->factory);

        BaseContainer::setInstance($this->container);
        Facade::setFacadeApplication($this->container);
        Component::flushCache();
        Component::forgetFactory();
        $this->registerTerminationCallback(static function () {
            Component::flushCache();
            Component::forgetFactory();
        });
    }

    public function render(string $view, array $data = [], array $mergeData = []): string
    {
        return $this->make($view, $data, $mergeData)->render();
    }

    public function make($view, $data = [], $mergeData = []): View
    {
        return $this->factory->make($view, $data, $mergeData);
    }

    public function compiler(): BladeCompiler
    {
        return $this->compiler;
    }

    public function directive(string $name, callable $handler)
    {
        $this->compiler->directive($name, $handler);
    }

    public function if($name, callable $callback)
    {
        $this->compiler->if($name, $callback);
    }

    public function exists($view): bool
    {
        return $this->factory->exists($view);
    }

    public function file($path, $data = [], $mergeData = []): View
    {
        return $this->factory->file($path, $data, $mergeData);
    }

    public function share($key, $value = null)
    {
        return $this->factory->share($key, $value);
    }

    public function composer($views, $callback): array
    {
        return $this->factory->composer($views, $callback);
    }

    public function creator($views, $callback): array
    {
        return $this->factory->creator($views, $callback);
    }

    public function addNamespace($namespace, $hints): self
    {
        $this->factory->addNamespace($namespace, $hints);

        return $this;
    }

    public function replaceNamespace($namespace, $hints): self
    {
        $this->factory->replaceNamespace($namespace, $hints);

        return $this;
    }

    public function __call(string $method, array $params)
    {
        return call_user_func_array([$this->factory, $method], $params);
    }

    protected function setupContainer(array $viewPaths, string $cachePath): void
    {
        $this->container->singletonIf('files', fn () => new Filesystem);
        $this->container->singletonIf('events', fn () => new Dispatcher($this->container));
        $this->container->singletonIf('config', fn () => new Repository);

        /** @var Repository $config */
        $config = $this->container->make('config');
        $config->set('view.paths', $viewPaths);
        $config->set('view.compiled', $cachePath);
        $config->set('view.cache', $config->get('view.cache', true));
        $config->set('view.compiled_extension', $config->get('view.compiled_extension', 'php'));
        $config->set('view.relative_hash', $config->get('view.relative_hash', false));
        $config->set('view.check_cache_timestamps', $config->get('view.check_cache_timestamps', true));
    }

    protected function createFactory(): Factory
    {
        $resolver = new EngineResolver;
        $files = $this->container->make('files');
        $finder = new FileViewFinder($files, $this->container->make('config')->get('view.paths'));

        $resolver->register('file', fn () => new FileEngine($files));
        $resolver->register('php', fn () => new PhpEngine($files));
        $resolver->register('blade', function () use ($files) {
            $engine = new CompilerEngine($this->compiler, $files);

            $this->registerTerminationCallback(static function () use ($engine) {
                $engine->forgetCompiledOrNotExpired();
            });

            return $engine;
        });

        $factory = new Factory($resolver, $finder, $this->container->make('events'));
        $factory->setContainer($this->container);
        $factory->share('app', $this->container);

        $this->container->instance('view.engine.resolver', $resolver);
        $this->container->instance('view.finder', $finder);

        return $factory;
    }

    protected function createCompiler(): BladeCompiler
    {
        $files = $this->container->make('files');
        $config = $this->container->make('config');

        $arguments = [
            $files,
            $config->get('view.compiled'),
            $config->get('view.relative_hash', false) ? $this->basePath() : '',
            $config->get('view.cache', true),
            $config->get('view.compiled_extension', 'php'),
        ];

        if ((new ReflectionMethod(BladeCompiler::class, '__construct'))->getNumberOfParameters() >= 6) {
            $arguments[] = $config->get('view.check_cache_timestamps', true);
        }

        $compiler = new BladeCompiler(...$arguments);
        $compiler->component('dynamic-component', DynamicComponent::class);

        return $compiler;
    }

    protected function registerTerminationCallback(callable $callback): void
    {
        if (method_exists($this->container, 'terminating')) {
            $this->container->terminating($callback);
        }
    }

    protected function basePath(): string
    {
        if (method_exists($this->container, 'basePath')) {
            return $this->container->basePath();
        }

        return '';
    }
}
