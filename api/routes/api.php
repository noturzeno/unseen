<?php

use App\Http\Controllers\Api\V1\AgentRunController;
use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\DevelopmentReportController;
use App\Http\Controllers\Api\V1\FeatureCategoryController;
use App\Http\Controllers\Api\V1\FeatureCheckController;
use App\Http\Controllers\Api\V1\FeatureController;
use App\Http\Controllers\Api\V1\FeatureDiagramController;
use App\Http\Controllers\Api\V1\FeatureSuggestionController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectDashboardController;
use App\Http\Controllers\Api\V1\ProjectMemberController;
use App\Http\Controllers\Api\V1\RepositoryScanController;
use App\Http\Controllers\Api\V1\RoadmapController;
use App\Http\Controllers\Api\V1\SpecMismatchController;
use App\Http\Controllers\Api\V1\TestRunController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\UnresolvedItemController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/tokens', [AuthTokenController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('auth.tokens.store');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthTokenController::class, 'me'])->name('me');
        Route::delete('auth/tokens/current', [AuthTokenController::class, 'destroy'])->name('auth.tokens.destroy');

        // Token abilities limit what a token may do; project policies still decide which projects.
        Route::middleware('ability:read')->group(function () {
            Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::get('projects/{project}/dashboard', ProjectDashboardController::class)->name('projects.dashboard');
            Route::get('projects/{project}/members', [ProjectMemberController::class, 'index'])->name('projects.members.index');
            Route::get('projects/{project}/categories', [FeatureCategoryController::class, 'index'])->name('projects.categories.index');
            Route::get('projects/{project}/features', [FeatureController::class, 'index'])->name('projects.features.index');
            Route::get('features/{feature}', [FeatureController::class, 'show'])->name('features.show');
            Route::get('features/{feature}/history', [FeatureController::class, 'history'])->name('features.history');
            Route::get('features/{feature}/evidence', [DevelopmentReportController::class, 'evidence'])->name('features.evidence');
            Route::get('projects/{project}/reports', [DevelopmentReportController::class, 'index'])->name('projects.reports.index');
            Route::get('reports/{report}', [DevelopmentReportController::class, 'show'])->name('reports.show');
            Route::get('projects/{project}/scans', [RepositoryScanController::class, 'index'])->name('projects.scans.index');
            Route::get('projects/{project}/suggestions', [FeatureSuggestionController::class, 'index'])->name('projects.suggestions.index');
            Route::get('suggestions/{suggestion}', [FeatureSuggestionController::class, 'show'])->name('suggestions.show');
            Route::get('features/{feature}/checks', [FeatureCheckController::class, 'index'])->name('features.checks.index');
            Route::get('features/{feature}/diagrams', [FeatureDiagramController::class, 'index'])->name('features.diagrams.index');
            Route::get('projects/{project}/test-runs', [TestRunController::class, 'index'])->name('projects.test-runs.index');
            Route::get('test-runs/{testRun}', [TestRunController::class, 'show'])->name('test-runs.show');
            Route::get('projects/{project}/unresolved', [UnresolvedItemController::class, 'index'])->name('projects.unresolved.index');
            Route::get('projects/{project}/roadmap', [RoadmapController::class, 'show'])->name('projects.roadmap.show');
            Route::get('projects/{project}/tickets', [TicketController::class, 'index'])->name('projects.tickets.index');
            Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
            Route::get('projects/{project}/mismatches', [SpecMismatchController::class, 'index'])->name('projects.mismatches.index');
            Route::get('mismatches/{mismatch}', [SpecMismatchController::class, 'show'])->name('mismatches.show');
        });

        // Only tokens trusted to run tests (CI, the developer) may submit results that verify features.
        Route::middleware('ability:verify')->group(function () {
            Route::post('projects/{project}/test-runs', [TestRunController::class, 'store'])->name('projects.test-runs.store');
        });

        Route::middleware('ability:report')->group(function () {
            Route::post('projects/{project}/reports', [DevelopmentReportController::class, 'store'])->name('projects.reports.store');
            // Scans only create suggestions; turning them into features needs `record`.
            Route::post('projects/{project}/scans', [RepositoryScanController::class, 'store'])->name('projects.scans.store');
        });

        // Tracking data. Coding agents use `record`; `write` (full access) includes it. Never verification.
        Route::middleware('ability:record,write')->group(function () {
            Route::put('projects/{project}/roadmap', [RoadmapController::class, 'sync'])->name('projects.roadmap.sync');
            // unseen runner: pick up "Proceed" requests and report whether Claude Code was opened.
            Route::post('projects/{project}/runs/claim', [AgentRunController::class, 'claim'])->name('projects.runs.claim');
            Route::patch('runs/{run}', [AgentRunController::class, 'update'])->name('runs.update');
            Route::post('projects/{project}/categories', [FeatureCategoryController::class, 'store'])->name('projects.categories.store');
            Route::match(['put', 'patch'], 'categories/{category}', [FeatureCategoryController::class, 'update'])->name('categories.update');
            Route::delete('categories/{category}', [FeatureCategoryController::class, 'destroy'])->name('categories.destroy');
            Route::post('projects/{project}/features', [FeatureController::class, 'store'])->name('projects.features.store');
            Route::match(['put', 'patch'], 'features/{feature}', [FeatureController::class, 'update'])->name('features.update');
            Route::post('suggestions/{suggestion}/accept', [FeatureSuggestionController::class, 'accept'])->name('suggestions.accept');
            Route::post('suggestions/{suggestion}/merge', [FeatureSuggestionController::class, 'merge'])->name('suggestions.merge');
            Route::post('suggestions/{suggestion}/dismiss', [FeatureSuggestionController::class, 'dismiss'])->name('suggestions.dismiss');
            Route::post('suggestions/{suggestion}/restore', [FeatureSuggestionController::class, 'restore'])->name('suggestions.restore');
            Route::post('unresolved/{item}/resolve', [UnresolvedItemController::class, 'resolve'])->name('unresolved.resolve');
            Route::patch('unresolved/{item}', [UnresolvedItemController::class, 'update'])->name('unresolved.update');
            Route::post('unresolved/{item}/reopen', [UnresolvedItemController::class, 'reopen'])->name('unresolved.reopen');
            Route::post('features/{feature}/checks', [FeatureCheckController::class, 'store'])->name('features.checks.store');
            Route::delete('checks/{check}', [FeatureCheckController::class, 'destroy'])->name('checks.destroy');
            Route::delete('diagrams/{diagram}', [FeatureDiagramController::class, 'destroy'])->name('diagrams.destroy');
            // Tickets and the QA / client feedback the agent is given about them.
            Route::post('projects/{project}/tickets', [TicketController::class, 'store'])->name('projects.tickets.store');
            Route::match(['put', 'patch'], 'tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
            Route::post('tickets/{ticket}/comments', [TicketController::class, 'comment'])->name('tickets.comments.store');
            // PRD vs design mismatches: agents record them and mark decisions applied, but never decide.
            Route::post('projects/{project}/mismatches', [SpecMismatchController::class, 'store'])->name('projects.mismatches.store');
            Route::patch('mismatches/{mismatch}', [SpecMismatchController::class, 'update'])->name('mismatches.update');
            Route::post('mismatches/{mismatch}/applied', [SpecMismatchController::class, 'applied'])->name('mismatches.applied');
        });

        // Diagrams describe how a feature works; agents keep them current.
        Route::middleware('ability:report,record,write')->group(function () {
            Route::post('features/{feature}/diagrams', [FeatureDiagramController::class, 'store'])->name('features.diagrams.store');
            Route::match(['put', 'patch'], 'diagrams/{diagram}', [FeatureDiagramController::class, 'update'])->name('diagrams.update');
        });

        // Projects, members and tokens are managed by people: not part of the coding-agent token.
        Route::middleware('ability:write')->group(function () {
            Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::match(['put', 'patch'], 'projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
            Route::post('projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
            Route::delete('projects/{project}/archive', [ProjectController::class, 'unarchive'])->name('projects.unarchive');
            Route::post('projects/{project}/members', [ProjectMemberController::class, 'store'])->name('projects.members.store');
            Route::delete('projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy'])->name('projects.members.destroy');
            Route::get('auth/tokens', [AuthTokenController::class, 'index'])->name('auth.tokens.index');
            // Deciding a PRD vs design mismatch is a person's call (agent tokens have `record`, not `write`).
            Route::post('mismatches/{mismatch}/decide', [SpecMismatchController::class, 'decide'])->name('mismatches.decide');
            Route::delete('auth/tokens/{token}', [AuthTokenController::class, 'revoke'])->whereNumber('token')->name('auth.tokens.revoke');
        });
    });
});
