<?php

namespace App\Http\Controllers;

use App\Models\ServiceInstruction;
use Illuminate\Http\Request;

/**
 * LEGACY: service instructions now live in gabaymo-ai and are managed through AdminAiController (admin/ai/instructions*) and PartnerAiController. This table is kept for older admin/partner builds; do not add features here.
 */
class AdminInstructionController extends Controller
{
    public function index()
    {
        return response()->json([
            'instructions' => ServiceInstruction::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (ServiceInstruction $instruction) => $this->present($instruction))
                ->all(),
        ]);
    }

    public function store(Request $request)
    {
        $instruction = ServiceInstruction::query()->create($this->validated($request));

        AdminOverviewController::record(
            $request,
            'instruction.create',
            "Added the {$instruction->title} service instruction",
        );

        return response()->json(['instruction' => $this->present($instruction)], 201);
    }

    public function update(Request $request, ServiceInstruction $serviceInstruction)
    {
        $serviceInstruction->update($this->validated($request));

        AdminOverviewController::record(
            $request,
            'instruction.update',
            "Updated the {$serviceInstruction->title} service instruction",
        );

        return response()->json(['instruction' => $this->present($serviceInstruction->fresh())]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ]);
    }

    private function present(ServiceInstruction $instruction): array
    {
        return [
            'id' => $instruction->id,
            'title' => $instruction->title,
            'body' => $instruction->body,
            'active' => $instruction->active,
            'sort_order' => $instruction->sort_order,
        ];
    }
}
