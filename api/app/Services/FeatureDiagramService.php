<?php

namespace App\Services;

use App\Enums\ClaimSource;
use App\Enums\DiagramType;
use App\Models\Feature;
use App\Models\FeatureDiagram;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Validates and saves feature diagrams sent by coding agents through the API.
 * The Mermaid definition is stored as text and only rendered in the browser
 * (Mermaid securityLevel "strict"), never interpreted on the server.
 */
class FeatureDiagramService
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'type' => [$required, Rule::enum(DiagramType::class)],
            'title' => [$required, 'string', 'max:255'],
            'definition' => [$required, 'string', 'max:'.FeatureDiagram::MAX_DEFINITION],
        ];
    }

    /**
     * @param  array{type?: string, title?: string, definition?: string}  $data  Already validated.
     */
    public function save(Feature $feature, ?FeatureDiagram $diagram, array $data, ClaimSource $source, ?User $user): FeatureDiagram
    {
        $diagram ??= $feature->diagrams()->make(['created_by' => null]);
        $diagram->fill($data);
        // The last writer owns the claim: an agent rewriting a person's diagram makes it agent-reported.
        $diagram->source = $source;
        $diagram->created_by ??= $user?->id;
        $diagram->save();

        return $diagram;
    }
}
