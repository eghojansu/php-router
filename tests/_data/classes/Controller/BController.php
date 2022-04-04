<?php

use Ekok\Router\Attribute\Route;

class BController
{
    #[Route(path: '/b', name: 'b')]
    public function home()
    {
        return 'Welcome home';
    }

    #[Route(path: '/b/complex', attrs: array(
        'this', 'is', 'a', 'bunch', 'of', 'tags',
        'named-tag' => 'foo',
        'named-tags' => array('foo', 'bar'),
    ))]
    public function complexAttributes()
    {}
}
