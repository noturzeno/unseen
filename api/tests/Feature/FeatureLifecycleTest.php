<?php

namespace Tests\Feature;

use App\Enums\ClaimSource;
use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus;
use App\Models\Feature;
use App\Services\FeatureLifecycle;
use App\Services\FeatureStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function states(Feature $feature): array
    {
        $feature->refresh();

        return array_column(FeatureLifecycle::steps($feature, $feature->statusHistory()->get()), 'state', 'key');
    }

    public function test_steps_follow_status_and_verification(): void
    {
        $feature = Feature::factory()->create();
        $statuses = app(FeatureStatusService::class);
        $this->assertSame(['planned' => 'current', 'in_progress' => 'upcoming', 'implemented' => 'upcoming', 'verified' => 'upcoming'], $this->states($feature));

        $statuses->apply($feature->fresh(), FeatureStatus::Blocked, null, ClaimSource::UserEntered);
        $this->assertSame(['planned' => 'done', 'in_progress' => 'blocked', 'implemented' => 'upcoming', 'verified' => 'upcoming'], $this->states($feature));

        $statuses->apply($feature->fresh(), FeatureStatus::Implemented, null, ClaimSource::UserEntered);
        $this->assertSame('waiting', $this->states($feature)['verified']);

        $statuses->apply($feature->fresh(), null, VerificationStatus::Failed, ClaimSource::UserEntered);
        $this->assertSame('failed', $this->states($feature)['verified']);

        $statuses->apply($feature->fresh(), null, VerificationStatus::Verified, ClaimSource::UserEntered);
        $this->assertSame(['planned' => 'done', 'in_progress' => 'done', 'implemented' => 'done', 'verified' => 'done'], $this->states($feature));
    }

    public function test_dates_come_from_history_and_are_cleared_when_moved_back(): void
    {
        $feature = Feature::factory()->create();
        $statuses = app(FeatureStatusService::class);

        $this->travelTo('2026-03-02 10:00');
        $statuses->apply($feature->fresh(), FeatureStatus::Implemented, null, ClaimSource::UserEntered);
        $this->travelTo('2026-03-09 10:00');
        $statuses->apply($feature->fresh(), FeatureStatus::InProgress, null, ClaimSource::UserEntered);

        $feature->refresh();
        $steps = collect(FeatureLifecycle::steps($feature, $feature->statusHistory()->get()))->keyBy('key');
        $this->assertSame('2026-03-09', $steps['in_progress']['at']->toDateString());
        $this->assertNull($steps['implemented']['at']); // no longer implemented
        $this->assertSame('current', $steps['in_progress']['state']);
    }
}
