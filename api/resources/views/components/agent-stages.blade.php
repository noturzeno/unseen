<div class="stage-choice" role="group" aria-label="Where is this project?">
    <button type="button" class="stage" wire:click="generate('new')" wire:loading.attr="disabled">
        <strong>Just started</strong>
        <span>Little or no code yet. The agent records the planned features, then tracks them as it builds.</span>
        <em>Get setup prompt →</em>
    </button>
    <button type="button" class="stage" wire:click="generate('existing')" wire:loading.attr="disabled">
        <strong>Already developed</strong>
        <span>Existing codebase. The agent scans it and records every feature, its status, tests and diagrams.</span>
        <em>Get setup prompt →</em>
    </button>
</div>
