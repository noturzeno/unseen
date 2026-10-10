<?php

namespace App\Livewire;

use App\Models\DevelopmentReport;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ReportDetail extends Component
{
    public DevelopmentReport $report;

    public function mount(DevelopmentReport $report): void
    {
        Gate::authorize('view', $report->project);
    }

    public function render()
    {
        $this->report->load(['project', 'user', 'features', 'unresolvedItems', 'ticketUpdates.ticket']);

        return view('livewire.report-detail')->title('Report #'.$this->report->id);
    }
}
