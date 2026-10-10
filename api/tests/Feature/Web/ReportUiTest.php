<?php

namespace Tests\Feature\Web;

use App\Models\DevelopmentReport;
use App\Models\Feature;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportUiTest extends TestCase
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

    public function test_report_pages_render(): void
    {
        $feature = Feature::factory()->for($this->project)->create(['name' => 'Checkout']);
        $report = DevelopmentReport::factory()->for($this->project)->create([
            'summary' => 'Built checkout',
            'unresolved' => ['Handle refunds'],
            'tests' => [['command' => 'php artisan test', 'outcome' => 'failed']],
            'tests_failed_count' => 1,
        ]);
        $report->evidence()->create(['feature_id' => $feature->id, 'kind' => 'development_report', 'source' => 'agent_reported', 'claimed_status' => 'implemented']);
        $report->unresolvedItems()->create(['project_id' => $this->project->id, 'text' => 'Handle refunds']);

        $this->get("/projects/{$this->project->id}/reports")->assertOk()->assertSee('Built checkout')->assertSee('1 failing test');
        $this->get("/reports/{$report->id}")->assertOk()->assertSee('Built checkout')->assertSee('Handle refunds')->assertSee('Checkout');
        $this->get("/projects/{$this->project->id}/reports/create")->assertNotFound(); // reports come from agents only
        $this->get("/projects/{$this->project->id}")->assertOk()->assertSee('Recent agent reports')->assertSee('Handle refunds');
        $this->get("/features/{$feature->id}")->assertOk()->assertSee('Built checkout');
    }

    public function test_outsiders_cannot_see_reports(): void
    {
        $other = Project::factory()->create();
        $report = DevelopmentReport::factory()->for($other)->create();

        $this->get("/projects/{$other->id}/reports")->assertForbidden();
        $this->get("/reports/{$report->id}")->assertForbidden();
    }
}
