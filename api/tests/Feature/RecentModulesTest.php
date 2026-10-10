<?php

namespace Tests\Feature;

use App\Enums\ClaimSource;
use App\Livewire\ProjectOverview;
use App\Models\AgentRun;
use App\Models\DevelopmentReport;
use App\Models\Feature;
use App\Models\FeatureCategory;
use App\Models\FeatureEvidence;
use App\Models\Project;
use App\Services\AgentRunService;
use App\Services\ProjectDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class RecentModulesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Feature $checkout;

    private Feature $login;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create();
        $billing = FeatureCategory::factory()->for($this->project)->create(['name' => 'Billing']);
        $auth = FeatureCategory::factory()->for($this->project)->create(['name' => 'Auth']);
        $this->checkout = Feature::factory()->for($this->project)->create(['name' => 'Checkout', 'category_id' => $billing->id]);
        $this->login = Feature::factory()->for($this->project)->create(['name' => 'Login', 'category_id' => $auth->id]);
    }

    private function report(array $features, array $attributes): DevelopmentReport
    {
        $report = DevelopmentReport::factory()->for($this->project)->create($attributes);
        $report->features()->attach(collect($features)->mapWithKeys(fn (Feature $f) => [$f->id => ['kind' => FeatureEvidence::KIND_DEVELOPMENT_REPORT, 'source' => ClaimSource::AgentReported->value]])->all());

        return $report;
    }

    public function test_modules_come_from_reports_and_proceed_prompts_newest_first(): void
    {
        $this->report([$this->checkout], ['prompt' => 'Add Apple Pay to checkout', 'reported_at' => now()->subHours(5)]);
        $this->report([$this->login], ['summary' => 'Fixed the remember-me cookie', 'reported_at' => now()->subHours(2)]);
        $this->report([$this->checkout], ['prompt' => 'Old work', 'reported_at' => now()->subDays(40)]); // too old

        $run = app(AgentRunService::class)->queue($this->project, $this->project->owner, 'feature', $this->checkout->id);
        $run->update(['status' => AgentRun::LAUNCHED]);
        app(AgentRunService::class)->queue($this->project, $this->project->owner, 'feature', $this->login->id); // still queued: not sent

        $modules = app(ProjectDashboard::class)->recentModules($this->project);

        $this->assertSame(['Billing', 'Auth'], array_column($modules, 'name'));
        $this->assertSame(2, $modules[0]['prompts']);
        $this->assertSame(['Checkout'], $modules[0]['features']);
        $this->assertSame(['text' => $run->title, 'asked' => true, 'via' => 'proceed', 'url' => route('features.show', $this->checkout)], $modules[0]['latest']);
        $this->assertSame(1, $modules[1]['prompts']);
        $this->assertSame(['text' => 'Fixed the remember-me cookie', 'asked' => false], array_intersect_key($modules[1]['latest'], ['text' => 0, 'asked' => 0]));
    }

    public function test_reports_accept_a_prompt_and_the_dashboard_shows_it(): void
    {
        Sanctum::actingAs($this->project->owner, ['*']);

        $this->postJson("/api/v1/projects/{$this->project->id}/reports", [
            'summary' => 'Added Apple Pay.', 'prompt' => 'Add Apple Pay to checkout', 'features' => [['id' => $this->checkout->id]],
        ])->assertCreated()->assertJsonPath('data.prompt', 'Add Apple Pay to checkout');

        $this->getJson("/api/v1/projects/{$this->project->id}/dashboard")
            ->assertJsonPath('data.recent_modules.0.name', 'Billing')
            ->assertJsonPath('data.recent_modules.0.latest.text', 'Add Apple Pay to checkout');

        $this->actingAs($this->project->owner);
        Livewire::test(ProjectOverview::class, ['project' => $this->project])
            ->assertSee('Recently targeted modules')
            ->assertSee('Add Apple Pay to checkout')
            ->call('filterFeatures', (string) $this->checkout->category_id)
            ->assertViewHas('features', fn ($f) => $f->pluck('name')->all() === ['Checkout']);
    }
}
