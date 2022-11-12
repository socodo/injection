<?php

namespace Socodo\Injection;

use Closure;

class Binding
{
    /** @var Closure Factory closure. */
    private readonly Closure $factory;

    /** @var bool Is binding be shared. */
    private readonly bool $shared;

    /**
     * Constructor.
     *
     * @param Closure $factory
     * @param bool $shared
     */
    public function __construct(Closure $factory, bool $shared = false)
    {
        $this->factory = $factory;
        $this->shared = $shared;
    }

    /**
     * Get the factory closure.
     *
     * @return Closure
     */
    public function getFactory(): Closure
    {
        return $this->factory;
    }

    /**
     * Determine if the binding should be shared.
     *
     * @return bool
     */
    public function isShared(): bool
    {
        return $this->shared;
    }
}