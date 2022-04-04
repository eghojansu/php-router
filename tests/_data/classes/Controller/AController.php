<?php

use Ekok\Router\Attribute\Route;

#[Route(path: '/a')]
class AController
{
    #[Route(path: '/home')]
    public function home()
    {
        return 'Welcome home';
    }

    public function thisMethodShouldBeSkipped()
    {}
}
