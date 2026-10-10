<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClaimSource;
use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\FeatureDiagram;
use App\Services\FeatureDiagramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Mermaid diagrams describing how a feature works, written by coding agents (recorded as
 * agent-reported). Adding and updating needs `report`, `record` or `write`; deleting needs `record` or `write`.
 */
class FeatureDiagramController extends Controller
{
    public function __construct(private readonly FeatureDiagramService $diagrams) {}

    public function index(Feature $feature): JsonResponse
    {
        Gate::authorize('view', $feature->project);

        return response()->json(['data' => $feature->diagrams()->get()->map(fn (FeatureDiagram $d) => self::present($d))]);
    }

    public function store(Request $request, Feature $feature): JsonResponse
    {
        Gate::authorize('update', $feature->project);

        $data = $request->validate(FeatureDiagramService::rules());
        $diagram = $this->diagrams->save($feature, null, $data, ClaimSource::AgentReported, $request->user());

        return response()->json(['data' => self::present($diagram)], 201);
    }

    public function update(Request $request, FeatureDiagram $diagram): JsonResponse
    {
        Gate::authorize('update', $diagram->feature->project);

        $data = $request->validate(FeatureDiagramService::rules(partial: true));
        $diagram = $this->diagrams->save($diagram->feature, $diagram, $data, ClaimSource::AgentReported, $request->user());

        return response()->json(['data' => self::present($diagram)]);
    }

    public function destroy(FeatureDiagram $diagram): Response
    {
        Gate::authorize('update', $diagram->feature->project);

        $diagram->delete();

        return response()->noContent();
    }

    public static function present(FeatureDiagram $diagram): array
    {
        return [
            'id' => $diagram->id,
            'feature_id' => $diagram->feature_id,
            'type' => $diagram->type,
            'title' => $diagram->title,
            'definition' => $diagram->definition,
            'source' => $diagram->source,
            'created_at' => $diagram->created_at,
            'updated_at' => $diagram->updated_at,
        ];
    }
}
