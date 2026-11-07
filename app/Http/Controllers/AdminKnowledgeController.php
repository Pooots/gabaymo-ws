<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeDocument;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * LEGACY: knowledge documents now live in gabaymo-ai and are managed through AdminAiController (admin/ai/knowledge/*). This text-only table is kept for older admin builds and for gabaymo:ai-import-legacy-knowledge; do not add features here.
 */
class AdminKnowledgeController extends Controller
{
    public function index()
    {
        return response()->json([
            'documents' => KnowledgeDocument::query()
                ->latest('updated_at')
                ->get()
                ->map(fn (KnowledgeDocument $document) => $this->present($document))
                ->all(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['source_status'] = 'pending';
        $document = KnowledgeDocument::query()->create($data);

        AdminOverviewController::record(
            $request,
            'knowledge.create',
            "Added {$document->title} to the knowledge base",
        );

        return response()->json(['document' => $this->present($document)], 201);
    }

    public function update(Request $request, KnowledgeDocument $knowledgeDocument)
    {
        $data = $this->validated($request);
        $data['source_status'] = 'pending';
        $knowledgeDocument->update($data);

        AdminOverviewController::record(
            $request,
            'knowledge.update',
            "Updated {$knowledgeDocument->title} in the knowledge base",
        );

        return response()->json(['document' => $this->present($knowledgeDocument->fresh())]);
    }

    public function review(Request $request, KnowledgeDocument $knowledgeDocument)
    {
        $data = $request->validate([
            'source_status' => ['required', Rule::in(['approved', 'returned'])],
            'source_note' => ['nullable', 'string', 'max:500'],
        ]);
        $data['source_note'] = trim((string) ($data['source_note'] ?? '')) ?: null;
        $knowledgeDocument->update($data);

        $verb = $data['source_status'] === 'approved' ? 'Approved' : 'Returned';
        AdminOverviewController::record(
            $request,
            'knowledge.review',
            "{$verb} {$knowledgeDocument->title}",
        );

        return response()->json(['document' => $this->present($knowledgeDocument->fresh())]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(KnowledgeDocument::CATEGORIES)],
            'kind' => ['required', Rule::in(array_keys(KnowledgeDocument::KINDS))],
            'reference' => ['nullable', 'string', 'max:120'],
            'summary' => ['required', 'string', 'max:400'],
            'content' => ['required', 'string', 'max:20000'],
            'active' => ['required', 'boolean'],
        ]);

        $data['reference'] = trim((string) ($data['reference'] ?? '')) ?: null;

        return $data;
    }

    private function present(KnowledgeDocument $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'category' => $document->category,
            'kind' => $document->kind,
            'kind_label' => KnowledgeDocument::KINDS[$document->kind] ?? $document->kind,
            'reference' => $document->reference,
            'summary' => $document->summary,
            'content' => $document->content,
            'active' => $document->active,
            'source_status' => $document->source_status,
            'source_note' => $document->source_note,
            'updated_at' => $document->updated_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
