<?php

namespace Tests\Feature;

use App\Livewire\UnresolvedIndex;
use App\Models\DevelopmentReport;
use App\Models\Project;
use App\Models\UnresolvedItem;
use App\Support\UnresolvedPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UnresolvedPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_prompt_names_the_item_and_its_report_and_how_to_close_it(): void
    {
        $project = Project::factory()->create(['name' => 'SGC']);
        $report = DevelopmentReport::factory()->for($project)->create(['summary' => "Built payments.\nMore detail."]);
        $item = UnresolvedItem::factory()->for($project)->create(['text' => 'Known defect: bad id returns 500', 'development_report_id' => $report->id]);

        $prompt = UnresolvedPrompt::forItem($item);

        $this->assertStringStartsWith("Resolve open item #{$item->id} in the Unseen project \"SGC\" (project #{$project->id}):\n\nKnown defect: bad id returns 500", $prompt);
        $this->assertStringContainsString("left open by report #{$report->id}: \"Built payments. More detail.\"", $prompt);
        $this->assertStringContainsString('needs a person', $prompt);
        $this->assertStringContainsString("\"resolves\": [{$item->id}]", $prompt);
        $this->assertStringContainsString('Never mark anything verified', $prompt);
        $this->assertDoesNotMatchRegularExpression('/\d+\|[A-Za-z0-9]{20,}/', $prompt);
    }

    public function test_all_items_prompt_lists_them_and_counts_the_rest(): void
    {
        $project = Project::factory()->create();
        $items = UnresolvedItem::factory()->for($project)->count(3)->create();

        $prompt = UnresolvedPrompt::forAll($project, $items, 3);
        foreach ($items as $item) {
            $this->assertStringContainsString("- #{$item->id}: {$item->text}", $prompt);
        }
        $this->assertStringContainsString('Sort the items: first defects and security or privacy problems', $prompt);
        $this->assertStringNotContainsString('more: run', $prompt);

        $this->assertStringContainsString('…and 70 more: run `unseen status --json`', UnresolvedPrompt::forAll($project, $items, 120));
    }

    public function test_unresolved_page_offers_prompts_for_open_items_only(): void
    {
        $project = Project::factory()->create();
        $open = UnresolvedItem::factory()->for($project)->create(['text' => 'Wire up CI']);
        UnresolvedItem::factory()->for($project)->create(['text' => 'Old thing', 'status' => UnresolvedItem::RESOLVED, 'resolved_at' => now()]);
        $this->actingAs($project->owner);

        $this->get("/projects/{$project->id}/unresolved")->assertOk()
            ->assertSee('Resolve the open item')
            ->assertSee("Resolve open item #{$open->id}", false)
            ->assertSee('Copy prompt');

        Livewire::test(UnresolvedIndex::class, ['project' => $project])
            ->set('status', 'resolved')
            ->assertDontSee('Copy prompt')
            ->assertDontSee('Resolve the open item');
    }
}
