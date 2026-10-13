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

    public function test_self_hosted_runner_guide_is_public_and_linked_from_home(): void
    {
        $this->get('/')->assertSee(route('guides.self-hosted-runner'));

        $this->get('/guides/self-hosted-runner')->assertOk()
            ->assertSee('Run CI on your own Mac')
            ->assertSee('runs-on: [self-hosted, my-mac]')
            // GitHub expression syntax must reach the page literally, not be eaten by Blade.
            ->assertSee('if: ${{ github.event_name', false)
            ->assertSee('npx --yes -p '.url('/downloads/unseen-cli.tgz').' unseen verify', false);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/projects')->assertRedirect('/login');
    }
}
