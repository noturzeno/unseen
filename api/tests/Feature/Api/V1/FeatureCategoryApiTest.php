<?php

namespace Tests\Feature\Api\V1;

use App\Models\Feature;
use App\Models\FeatureCategory;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeatureCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    private function ownProject(): Project
    {
        return Project::factory()->for(Sanctum::actingAs(User::factory()->create(), ['*']), 'owner')->create();
    }

    public function test_owner_can_create_list_update_and_delete_categories(): void
    {
        $project = $this->ownProject();

        $id = $this->postJson("/api/v1/projects/{$project->id}/categories", ['name' => 'Auth', 'position' => 2])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Auth')
            ->json('data.id');

        $this->getJson("/api/v1/projects/{$project->id}/categories")
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.features_count', 0);

        $this->patchJson("/api/v1/categories/{$id}", ['name' => 'Authentication'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Authentication');

        $this->deleteJson("/api/v1/categories/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('feature_categories', ['id' => $id]);
    }

    public function test_category_names_are_unique_per_project(): void
    {
        $project = $this->ownProject();
        FeatureCategory::factory()->for($project)->create(['name' => 'Billing']);
        FeatureCategory::factory()->create(['name' => 'Other']); // different project

        $this->postJson("/api/v1/projects/{$project->id}/categories", ['name' => 'Billing'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        $this->postJson("/api/v1/projects/{$project->id}/categories", ['name' => 'Other'])->assertCreated();
    }

    public function test_deleting_a_category_keeps_its_features(): void
    {
        $project = $this->ownProject();
        $category = FeatureCategory::factory()->for($project)->create();
        $feature = Feature::factory()->for($project)->create(['category_id' => $category->id]);

        $this->deleteJson("/api/v1/categories/{$category->id}")->assertNoContent();

        $this->assertNull($feature->fresh()->category_id);
    }

    public function test_outsiders_cannot_touch_categories(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $category = FeatureCategory::factory()->create();

        $this->getJson("/api/v1/projects/{$category->project_id}/categories")->assertForbidden();
        $this->postJson("/api/v1/projects/{$category->project_id}/categories", ['name' => 'X'])->assertForbidden();
        $this->patchJson("/api/v1/categories/{$category->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/categories/{$category->id}")->assertForbidden();
    }
}
