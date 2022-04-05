<?php

use Ekok\Router\Attribute\Route;

#[Route('/a')]
class AController
{
    #[Route('/home')]
    public function home()
    {
        return 'Welcome home';
    }

    public function thisMethodShouldBeSkipped()
    {}
}
