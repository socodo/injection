<?php

namespace Socodo\Injection;

use Closure;
use Exception;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Socodo\Injection\Exceptions\ClassNotFoundException;
use Socodo\Injection\Exceptions\EntryNotFoundException;
use Socodo\Injection\Exceptions\InjectionException;
use Throwable;

class Container implements ContainerInterface
{
    /** @var static Currently available container instance. */
    protected static Container $instance;

    /** @var array<string, Binding> Bindings of the container. */
    protected array $bindings = [];

    /** @var array<string, mixed> Shared instances of the container. */
    protected array $shared = [];

    /** @var array<string> Build stack. */
    protected array $buildStack = [];

    /** @var array<array> Parameter stack. */
    protected array $buildParameters = [];

    /**
     * Get a binding entry.
     *
     * @param string $id
     * @param array $parameters
     * @return mixed
     * @throws EntryNotFoundException
     */
    public final function get (string $id, array $parameters = []): mixed
    {
        if (!$this->has($id))
        {
            try
            {
                $this->bind($id);
            }
            catch (ClassNotFoundException)
            {
                throw new EntryNotFoundException();
            }
        }

        if (isset($this->shared[$id]))
        {
            return $this->shared[$id];
        }

        $binding = $this->bindings[$id];

        $this->buildParameters[] = $parameters;
        $entry = $binding->getFactory()($this);

        if ($binding->isShared())
        {
            $this->shared[$id] = $entry;
        }

        array_pop($this->buildParameters);
        return $entry;
    }

    /**
     * Determine if the given id has been bound.
     *
     * @param string $id
     * @return bool
     */
    public final function has (string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->shared[$id]);
    }

    /**
     * Register a shared instance.
     *
     * @param string $id
     * @param mixed $instance
     * @return void
     */
    public final function set (string $id, mixed $instance): void
    {
        $this->dropStaleBindings($id);
        $this->shared[$id] = $instance;
    }

    /**
     * Call a method.
     *
     * @param string $id
     * @param string $methodName
     * @param array $parameters
     * @return mixed|void
     * @throws EntryNotFoundException
     * @throws InjectionException
     */
    public final function call (string $id, string $methodName, array $parameters = [])
    {
        $entry = $this->get($id);

        try
        {
            $reflector = new ReflectionMethod($entry, $methodName);
            $reflectionProperties = $reflector->getParameters();

            $this->buildParameters[] = $parameters;
            $parameters = $this->resolveDependencies($reflectionProperties);

            return $reflector->invokeArgs($entry, $parameters);
        }
        catch (Throwable)
        {
            throw new InjectionException('Target method "' . $id . '::' . $methodName . '" cannot be injected with proper dependencies.');
        }
    }

    /**
     * Register a binding with the container.
     *
     * @param string $id
     * @param Closure|string|null $factory
     * @param bool $shared
     * @throws ClassNotFoundException
     */
    public final function bind (string $id, Closure|string $factory = null, bool $shared = false)
    {
        $this->dropStaleBindings($id);

        if ($factory === null)
        {
            $factory = $id;
        }

        if (is_string($factory))
        {
            if (!class_exists($factory))
            {
                throw new ClassNotFoundException('Target class "' . $factory . '" does not exists.');
            }

            $factory = $this->buildFactoryClosure($factory);
        }

        $this->bindings[$id] = new Binding($factory, $shared);
    }

    /**
     * Drop all the stale bindings, instances and aliases.
     *
     * @param string $id
     * @return void
     */
    protected function dropStaleBindings (string $id): void
    {
        unset($this->bindings[$id]);
        unset($this->shared[$id]);
    }

    /**
     * Build a factory closure with a given class name.
     *
     * @param string $factoryClassName
     * @return Closure
     */
    protected function buildFactoryClosure (string $factoryClassName): Closure
    {
        return function (Container $container) use ($factoryClassName) {
            return $container->build($factoryClassName);
        };
    }

    /**
     * Build an instance.
     *
     * @param Closure|string $factory
     * @return void
     * @throws ClassNotFoundException
     * @throws InjectionException
     */
    public final function build(Closure|string $factory)
    {
        if($factory instanceof Closure)
        {
            return $factory($this, $this->getLastBuildParameter());
        }

        try
        {
            $reflector = new ReflectionClass($factory);
        }
        catch (ReflectionException)
        {
            throw new ClassNotFoundException('Target class "' . $factory . '" does not exists.');
        }

        $this->buildStack[] = $factory;

        $constructor = $reflector->getConstructor();
        if ($constructor === null)
        {
            array_pop($this->buildStack);
            return new $factory;
        }

        $dependencies = $constructor->getParameters();
        try
        {
            $instances = $this->resolveDependencies($dependencies);
            return $reflector->newInstanceArgs($instances);
        }
        catch (Exception $e)
        {
            throw new InjectionException($e->getMessage(), $e->getCode(), $e->getPrevious());
        }
    }

    /**
     * Get a last build parameter item.
     *
     * @return array
     */
    protected function getLastBuildParameter(): array
    {
        return empty($this->buildParameters) ? [] : end($this->buildParameters);
    }

    /**
     * Resolve dependencies from given reflectors.
     *
     * @param array<ReflectionParameter> $dependencies
     * @return array
     * @throws EntryNotFoundException
     * @throws InjectionException
     */
    protected function resolveDependencies (array $dependencies): array
    {
        $results = [];
        foreach ($dependencies as $dependency)
        {
            $params = $this->getLastBuildParameter();
            if (isset($params[$dependency->getName()]))
            {
                $results[] = $params[$dependency->getName()];
                continue;
            }

            $result = $this->getParameterClassName($dependency) === null ? $this->resolvePrimitive($dependency) : $this->resolveClass($dependency);
            if ($dependency->isVariadic())
            {
                $results = array_merge($results, $result);
            }
            else
            {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Get parameter's class name from ReflectionParameter.
     *
     * @param ReflectionParameter $parameter
     * @return string|null
     */
    protected function getParameterClassName (ReflectionParameter $parameter): string|null
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin())
        {
            return null;
        }

        $name = $type->getName();
        if (($class = $parameter->getDeclaringClass()) !== null)
        {
            if ($name === 'self')
            {
                return $class->getName();
            }

            if ($name === 'parent' && $parent = $class->getParentClass())
            {
                return $parent->getName();
            }
        }

        return $name;
    }

    /**
     * Resolve a primitive dependency from the container.
     *
     * @throws InjectionException
     */
    protected function resolvePrimitive (ReflectionParameter $parameter)
    {
        if ($parameter->isDefaultValueAvailable())
        {
            return $parameter->getDefaultValue();
        }

        if ($parameter->isVariadic())
        {
            return [];
        }

        throw new InjectionException('Unresolvable dependency resolving "' . $parameter->getName() . '" in class "' . $parameter->getDeclaringClass()->getName() . '"');
    }

    /**
     * Resolve a class based dependency from the container.
     *
     * @throws InjectionException
     */
    protected function resolveClass (ReflectionParameter $parameter)
    {
        try
        {
            $entry = $this->get($this->getParameterClassName($parameter), $this->getLastBuildParameter());
            return $parameter->isVariadic() ? [ $entry ] : $entry;
        }
        catch (InjectionException $e)
        {
            if ($parameter->isDefaultValueAvailable())
            {
                array_pop($this->buildParameters);
                return $parameter->getDefaultValue();
            }

            if ($parameter->isVariadic())
            {
                array_pop($this->buildParameters);
                return [];
            }

            throw $e;
        }
    }
}