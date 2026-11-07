<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Models\KnowledgeDocument;
use App\Services\GabayMoAi\AiContext;
use App\Services\GabayMoAi\GabayMoAiClient;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Copies the legacy text-only knowledge_documents rows into gabaymo-ai as
 * .txt uploads. Imported documents start in Source Review like any upload;
 * nothing is published automatically.
 */
class ImportLegacyKnowledgeCommand extends Command
{
    protected $signature = 'gabaymo:ai-import-legacy-knowledge {--admin= : Email of the staff account to attribute uploads to} {--dry-run}';

    protected $description = 'Send legacy knowledge documents to gabaymo-ai for extraction and review.';

    private const KIND_TO_TYPE = [
        'deped_order' => 'deped_order', 'memorandum' => 'deped_memorandum', 'republic_act' => 'republic_act',
        'csc' => 'csc_issuance', 'court' => 'court_decision', 'other' => 'other',
    ];

    public function handle(GabayMoAiClient $ai): int
    {
        $admin = AdminUser::query()->with('role')->where('email', (string) $this->option('admin'))->first();
        if (! $admin) {
            $this->error('Pass --admin=email of an existing staff account.');

            return self::INVALID;
        }

        $count = 0;
        foreach (KnowledgeDocument::query()->orderBy('id')->cursor() as $doc) {
            $this->line("#{$doc->id} {$doc->title}");
            if ($this->option('dry-run')) {
                continue;
            }
            $path = tempnam(sys_get_temp_dir(), 'legacy-kb-');
            file_put_contents($path, $doc->content);
            $file = new UploadedFile($path, 'legacy-'.$doc->id.'.txt', 'text/plain', null, true);
            $response = $ai->upload('v1/knowledge/documents', AiContext::admin($admin), $file, ['metadata' => json_encode([
                'title' => $doc->title,
                'document_type' => self::KIND_TO_TYPE[$doc->kind] ?? 'other',
                'document_number' => $doc->reference,
                'category' => $doc->category,
                'description' => $doc->summary,
                'issuing_authority' => 'Department of Education',
            ])], 'legacy-kb-'.$doc->id);
            @unlink($path);
            $this->line($response->successful() ? '  -> '.$response->json('data.id') : '  !! HTTP '.$response->status().' '.mb_substr($response->body(), 0, 200));
            $count += $response->successful() ? 1 : 0;
        }
        $this->info("Imported {$count} document(s). They are waiting in Source Review.");

        return self::SUCCESS;
    }
}
