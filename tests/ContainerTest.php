<?php /** @noinspection PhpUndefinedClassInspection, PhpUndefinedNamespaceInspection */

namespace Tests\Unit;

use Codeception\Test\Unit;
use PHPUnit\Util\Test;
use Socodo\Injection\Container;
use Socodo\Injection\Exceptions\ClassNotFoundException;
use Socodo\Injection\Exceptions\EntryNotFoundException;
use Socodo\Injection\Exceptions\InjectionException;
use Tests\Support\UnitTester;

class ContainerTest extends Unit
{
    private Container $container;

    protected function _before()
    {
        $this->container = new Container();
    }

    /**
     * Container::get()
     *
     * @return void
     * @throws EntryNotFoundException
     */
    public function testGet (): void
    {
        try
        {
            /**
             * Must throw EntryNotFoundException.
             */
            $this->container->get('UndefinedClass');
        }
        catch (\Throwable $e)
        {
            $this->assertInstanceOf(EntryNotFoundException::class, $e);
        }

        /**
         * Must be an instance of ContainerTest
         */
        $get = $this->container->get(ContainerTest::class);
        $this->assertInstanceOf(ContainerTest::class, $get);

        /**
         * Must be equal with 'Hello, World!'
         */
        $this->container->set('HelloWorldTest', 'Hello, World!');
        $get = $this->container->get('HelloWorldTest');
        $this->assertEquals('Hello, World!', $get);
    }

    /**
     * Container::has()
     *
     * @return void
     * @throws EntryNotFoundException
     */
    public function testHas (): void
    {
        /**
         * Must be false.
         */
        $has = $this->container->has(ContainerTest::class);
        $this->assertFalse($has);

        /**
         * Must be true.
         */
        $this->container->get(ContainerTest::class);
        $has = $this->container->has(ContainerTest::class);
        $this->assertTrue($has);

        /**
         * Must be true.
         */
        $this->container->set('HelloWorldTest', 'Hello, World!');
        $has = $this->container->has('HelloWorldTest');
        $this->assertTrue($has);
    }

    /**
     * Container::set()
     *
     * @return void
     * @throws EntryNotFoundException
     */
    public function testSet (): void
    {
        $this->container->set('StringTest', 'String');
        $this->assertEquals('String', $this->container->get('StringTest'));

        $this->container->set('ArrayTest', [ 'key' => 'value' ]);
        $this->assertEquals([ 'key' => 'value'], $this->container->get('ArrayTest'));

        $this->container->set('NumericTest', 314);
        $this->assertEquals(314, $this->container->get('NumericTest'));

        $this->container->set('BooleanTest', false);
        $this->assertEquals(false, $this->container->get('BooleanTest'));

        $this->container->set('ClosureTest', function () { return 'ClosureTest'; });
        $this->assertInstanceOf(\Closure::class, $this->container->get('ClosureTest'));
        $this->assertEquals('ClosureTest', $this->container->get('ClosureTest')());

        $object = (object) [ 'key' => 'value' ];
        $this->container->set('ObjectTest', $object);
        $this->assertSame($object, $this->container->get('ObjectTest'));
    }

    /**
     * Container::call()
     *
     * @return void
     * @throws InjectionException
     */
    public function testCall (): void
    {
        $call = $this->container->call(Test_A::class, 'a');
        $this->assertInstanceOf(Test_A::class, $call);

        $call = $this->container->call(Test_A::class, 'b');
        $this->assertInstanceOf(Test_B::class, $call);

        $b = new Test_B();
        $call = $this->container->call(Test_A::class, 'b');
        $this->assertNotSame($b, $call);

        $this->container->set(Test_B::class, $b);
        $call = $this->container->call(Test_A::class, 'b');
        $this->assertSame($b, $call);
    }

    /**
     * Container::bind()
     *
     * @return void
     * @throws EntryNotFoundException
     * @throws ClassNotFoundException
     */
    public function testBind (): void
    {
        $this->container->bind(Test_A::class);
        $this->assertInstanceOf(Test_A::class, $this->container->get(Test_A::class));

        $this->container->bind('ATest', Test_A::class);
        $this->assertInstanceOf(Test_A::class, $this->container->get('ATest'));

        $getA = $this->container->get('ATest');
        $getB = $this->container->get('ATest');
        $this->assertNotSame($getA, $getB);

        $this->container->bind(Test_B::class, Test_B::class, true);
        $getA = $this->container->get(Test_B::class);
        $getB = $this->container->get(Test_B::class);
        $this->assertSame($getA, $getB);

        $this->container->bind('FactoryTest', function () { return new Test_A(); });
        $this->assertInstanceOf(Test_A::class, $this->container->get('FactoryTest'));

        $this->container->bind(Test_C::class);
        $getC = $this->container->get(Test_C::class);
        $this->assertInstanceOf(Test_C::class, $getC);
        $this->assertInstanceOf(Test_A::class, $getC->a);
        $this->assertNotSame($this->container->get(Test_A::class), $getC->a);
        $this->assertSame($getB, $getC->b);
    }
}

/**
 * Test purpose classes.
 */
class Test_A {
    public function a (Test_A $a): Test_A { return $a; }
    public function b (Test_B $b): Test_B { return $b; }
}
class Test_B {}
class Test_C {
    public function __construct (public Test_A $a, public Test_B $b) {}
}