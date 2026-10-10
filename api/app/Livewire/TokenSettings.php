<?php

namespace App\Livewire;

use App\Support\TokenPresets;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The signed-in user's API tokens: create from a preset with an expiry, see usage, revoke.
 * A new token's value is shown once and never stored in plain text.
 */
#[Title('API tokens')]
class TokenSettings extends Component
{
    public string $name = '';

    public string $preset = 'agent';

    public string $expiresInDays = '90';

    /** Plain-text value of the token just created; shown once. */
    public ?string $newToken = null;

    public function create(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'preset' => ['required', Rule::in(array_keys(TokenPresets::PRESETS))],
            'expiresInDays' => ['nullable', 'integer', 'min:1', 'max:365'],
        ], [], ['expiresInDays' => 'expiry']);

        $token = auth()->user()->createToken(
            $data['name'],
            TokenPresets::abilities($data['preset']),
            $data['expiresInDays'] ? now()->addDays((int) $data['expiresInDays']) : null,
        );

        $this->newToken = $token->plainTextToken;
        $this->reset('name');
    }

    public function dismissNewToken(): void
    {
        $this->newToken = null;
    }

    public function revoke(int $id): void
    {
        auth()->user()->tokens()->whereKey($id)->delete();
    }

    public function render()
    {
        return view('livewire.token-settings', [
            'tokens' => auth()->user()->tokens()->latest()->get(),
            'presets' => TokenPresets::PRESETS,
        ]);
    }
}
