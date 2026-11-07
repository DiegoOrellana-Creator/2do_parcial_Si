<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * La raíz del sitio lleva al inicio de sesión.
     */
    public function test_the_root_redirects_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
