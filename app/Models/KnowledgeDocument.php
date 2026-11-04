<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeDocument extends Model
{
    public const CATEGORIES = [
        'MOOE and Liquidation',
        'Procurement and Bidding',
        'School Forms and Reports',
        'Teacher Workload',
        'Administrative Responsibilities',
        'Personnel Management',
        'Complaints and Grievances',
        'Child Protection',
        'School Governance',
        'Financial Management',
        'Other DepEd Policies',
    ];

    public const KINDS = [
        'deped_order' => 'DepEd Order',
        'memorandum' => 'DepEd Memorandum',
        'republic_act' => 'Republic Act',
        'csc' => 'CSC issuance',
        'court' => 'Court decision',
        'other' => 'Other',
    ];

    protected $fillable = [
        'title',
        'category',
        'kind',
        'reference',
        'summary',
        'content',
        'active',
        'source_status',
        'source_note',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
