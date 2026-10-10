<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_home_page_shows_the_cli_package_to_guests(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('unseen-cli')
            ->assertSee(url('/downloads/unseen-cli.tgz'))
            ->assertSee('unseen init');
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/projects')->assertRedirect('/login');
    }
}
