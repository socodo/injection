<?php /** @noinspection PhpUndefinedClassInspection, PhpUndefinedNamespaceInspection */

namespace Tests\Unit;

use Codeception\Test\Unit;
use Socodo\Injection\Container;
use Tests\Support\UnitTester;

class ContainerTest extends Unit
{
    private Container $container;

    protected function _before()
    {
        $this->container = new Container();
    }

    public function testEntry()
    {
        $entry = $this->container->get(static::class);
        $this->assertNotEquals($this, $entry);
        $this->assertEquals(static::class, get_class($entry));
    }

    public function testSharedEntry()
    {
        $this->container->set(static::class, $this);
        $this->assertEquals($this, $this->container->get(static::class));
    }

    public function testParameter()
    {
        $entry = $this->container->get(Foo::class);
        $this->assertEquals(Bar::class, get_class($entry->bar));

        $bar = new Bar();
        $bar->a = 10;

        $entry = $this->container->get(Foo::class, [ 'bar' => $bar ]);
        $this->assertEquals($bar->a, $entry->bar->a);
    }

    public function testNestedParameter()
    {
        $bar = new Bar();
        $bar->a = 10;

        $entry = $this->container->get(Baz::class, [ 'bar' => $bar ]);
        $this->assertEquals($bar->a, $entry->foo->bar->a);
    }
}


class Foo
{
    public function __construct(public Bar $bar)
    {}
}

class Bar
{
    public int $a = 0;
}

class Baz
{
    public function __construct(public Foo $foo)
    {}
}