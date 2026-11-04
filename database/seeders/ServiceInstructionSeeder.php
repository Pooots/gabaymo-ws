<?php

namespace Database\Seeders;

use App\Models\ServiceInstruction;
use Illuminate\Database\Seeder;

class ServiceInstructionSeeder extends Seeder
{
    public function run(): void
    {
        $instructions = [
            [
                'title' => 'Cite the page',
                'body' => 'Every answer must name the document and the page the sentence comes from. If you cannot point to a page, do not answer.',
                'sort_order' => 1,
            ],
            [
                'title' => 'Say when the source is missing',
                'body' => 'If the knowledge library does not contain the rule, say so. Do not fill the gap with a general explanation. Offer a partner ticket instead.',
                'sort_order' => 2,
            ],
            [
                'title' => 'Keep interpretation separate',
                'body' => 'Quote or paraphrase the issuance first. Any reading of what it means for the school must be labeled as an interpretation.',
                'sort_order' => 3,
            ],
        ];

        foreach ($instructions as $instruction) {
            ServiceInstruction::query()->updateOrCreate(
                ['title' => $instruction['title']],
                [...$instruction, 'active' => true],
            );
        }
    }
}
