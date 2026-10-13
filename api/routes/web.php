<?php

use App\Livewire\AcceptInvitation;
use App\Livewire\FeatureDetail;
use App\Livewire\Login;
use App\Livewire\MismatchesIndex;
use App\Livewire\ProjectForm;
use App\Livewire\ProjectOverview;
use App\Livewire\ProjectsIndex;
use App\Livewire\ReportDetail;
use App\Livewire\ReportsIndex;
use App\Livewire\RoadmapIndex;
use App\Livewire\SuggestionsReview;
use App\Livewire\TestRunDetail;
use App\Livewire\TicketDetail;
use App\Livewire\TicketsIndex;
use App\Livewire\TokenSettings;
use App\Livewire\UnresolvedIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Public landing page: what the CLI package is and how to connect a repository.
Route::get('/', function () {
    $package = public_path('downloads/unseen-cli.tgz');
    $version = rescue(fn () => json_decode(file_get_contents('phar://'.$package.'/package/package.json'))->version ?? null, null, false);

    return view('home', ['version' => $version, 'size' => is_file($package) ? filesize($package) : null]);
})->name('home');

// Public guide: running CI (and unseen verify) on a self-hosted Mac when GitHub Actions minutes run out.
Route::view('/guides/self-hosted-runner', 'guides.self-hosted-runner')->name('guides.self-hosted-runner');

Route::middleware('guest')->group(function () {
    Route::livewire('/login', Login::class)->name('login');
});

// Invitation links work signed in or out: new contributors create their account here.
Route::livewire('/invitations/{token}', AcceptInvitation::class)->middleware('throttle:30,1')->name('invitations.accept');

// Project and feature data comes from coding agents through the API; the web UI shows it.
// People manage only projects, members and API tokens here, and decide PRD vs design mismatches.
Route::middleware('auth')->group(function () {
    Route::post('/logout', function (Request $request) {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    Route::livewire('/projects', ProjectsIndex::class)->name('projects.index');
    Route::livewire('/projects/create', ProjectForm::class)->name('projects.create');
    Route::livewire('/projects/{project}', ProjectOverview::class)->name('projects.show');
    Route::livewire('/projects/{project}/edit', ProjectForm::class)->name('projects.edit');
    Route::livewire('/features/{feature}', FeatureDetail::class)->name('features.show');
    Route::livewire('/projects/{project}/reports', ReportsIndex::class)->name('reports.index');
    Route::livewire('/reports/{report}', ReportDetail::class)->name('reports.show');
    Route::livewire('/projects/{project}/suggestions', SuggestionsReview::class)->name('suggestions.index');
    Route::livewire('/test-runs/{testRun}', TestRunDetail::class)->name('test-runs.show');
    Route::livewire('/projects/{project}/unresolved', UnresolvedIndex::class)->name('unresolved.index');
    Route::livewire('/projects/{project}/roadmap', RoadmapIndex::class)->name('roadmap.index');
    Route::livewire('/projects/{project}/tickets', TicketsIndex::class)->name('tickets.index');
    Route::livewire('/tickets/{ticket}', TicketDetail::class)->name('tickets.show');
    Route::livewire('/projects/{project}/mismatches', MismatchesIndex::class)->name('mismatches.index');
    Route::livewire('/settings/tokens', TokenSettings::class)->name('settings.tokens');
});
