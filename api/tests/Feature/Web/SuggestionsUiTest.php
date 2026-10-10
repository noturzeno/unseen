<?php

namespace Tests\Feature\Web;

use App\Livewire\SuggestionsReview;
use App\Models\FeatureSuggestion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Tests\TestCase;

class SuggestionsUiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user, 'owner')->create();
        $this->actingAs($this->user);
    }

    public function test_review_page_lists_pending_suggestions_with_evidence(): void
    {
        FeatureSuggestion::factory()->for($this->project)->create(['name' => 'Invoices', 'evidence' => [['type' => 'model', 'path' => 'app/Models/Invoice.php']]]);
        FeatureSuggestion::factory()->for($this->project)->create(['name' => 'Old thing', 'status' => 'dismissed']);

        $this->get("/projects/{$this->project->id}/suggestions")
            ->assertOk()
            ->assertSee('Invoices')
            ->assertSee('app/Models/Invoice.php')
            ->assertDontSee('Old thing');
        $this->get("/projects/{$this->project->id}")->assertSeeHtml('<span class="count hot">1</span>');
    }

    public function test_review_page_is_read_only(): void
    {
        $suggestion = FeatureSuggestion::factory()->for($this->project)->create(['name' => 'Invoices']);

        $this->get("/projects/{$this->project->id}/suggestions")
            ->assertOk()
            ->assertDontSee('Accept…')
            ->assertDontSee('wire:click="dismiss', false);

        // Agents review suggestions through the API; the page has no actions to call.
        $this->expectException(MethodNotFoundException::class);
        Livewire::test(SuggestionsReview::class, ['project' => $this->project])->call('dismiss', $suggestion->id);
    }

    public function test_outsiders_get_403(): void
    {
        $other = Project::factory()->create();

        $this->get("/projects/{$other->id}/suggestions")->assertForbidden();
    }
}
