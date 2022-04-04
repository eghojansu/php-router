<?php

use Ekok\Router\Router;

class EnvTest extends \Codeception\Test\Unit
{
    /** @var Router */
    private $router;

    public function _before()
    {
        $this->router = new Router();
    }

    public function testRouteSet()
    {
        $this->router->routeAll(array(
            'GET @home /' => 'get home',
            'GET @profile /profile' => 'get profile',
            'POST @profile' => 'post profile',
            'GET @control /control/@method' => 'get control for @method',
            'GET /time' => 'need so many time!',
            'GET /route-set [with,so,many,tags,and=custom-tags,numbers=1,numbers=2,chars=a;b;c;d]' => 'route-set',
        ));

        $this->assertCount(3, $this->router->getAliases());
        $this->assertCount(5, $this->router->getRoutes());
        $this->assertCount(2, $this->router->getRoutes()['/profile']);
        $this->assertSame('/', $this->router->alias('home'));
        $this->assertSame('/profile', $this->router->alias('profile'));
        $this->assertSame('/control/foo', $this->router->alias('control', 'method=foo'));
        $this->assertSame('/control/bar', $this->router->alias('control', array('method' => 'bar')));
        $this->assertSame('/control/baz?and=query', $this->router->alias('control', array('method' => 'baz', 'and' => 'query')));
        $this->assertSame('/time?with=query', $this->router->alias('time', array('with' => 'query')));

        $expected = array(
            'handler' => 'get home',
            'alias' => 'home',
            'args' => null,
        );

        $this->assertSame($expected, $this->router->match('/'));
        $this->assertSame($expected, $this->router->match('/', 'get'));
        $this->assertSame(
            'get profile',
            $this->router->match('/profile')['handler'],
        );
        $this->assertSame(
            'post profile',
            $this->router->match('/profile', 'POST')['handler'],
        );

        $match = $this->router->match('/control/foo');

        $this->assertSame('get control for foo', $match['handler']);
        $this->assertSame(array('method' => 'foo'), $match['args']);

        $match = $this->router->match('/control/bar');

        $this->assertSame('get control for bar', $match['handler']);
        $this->assertSame(array('method' => 'bar'), $match['args']);

        $this->assertSame(
            'need so many time!',
            $this->router->match('/time')['handler'],
        );

        $expected = array(
            'handler' => 'route-set',
            'alias' => null,
            'tags' => array('with', 'so', 'many', 'tags'),
            'and' => 'custom-tags',
            'numbers' => array(1, 2),
            'chars' => array('a', 'b', 'c', 'd'),
            'args' => null,
        );

        $this->assertSame($expected, $this->router->match('/route-set'));
    }

    public function testRouteInsufficientParams()
    {
        $this->expectException('LogicException');
        $this->expectExceptionMessageMatches('/^Route param required: id@view$/');

        $this->router->route('GET @view /@id', 'foo');
        $this->router->alias('view', 'noid=1');
    }

    /** @dataProvider routeExceptionProvider */
    public function testRouteException(string $expected, string $pattern)
    {
        $this->expectException('LogicException');
        $this->expectExceptionMessageMatches($expected);

        $this->router->route($pattern, 'foo');
    }

    public function routeExceptionProvider()
    {
        return array(
            'invalid definitiion' => array(
                '/^Invalid route: "@"$/',
                '@',
            ),
            'no path' => array(
                '/^No path defined in route: "GET"$/',
                'GET',
            ),
            'no route' => array(
                '/^Route not exists: view$/',
                'POST @view',
            ),
        );
    }
}
