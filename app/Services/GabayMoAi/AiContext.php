<?php

namespace App\Services\GabayMoAi;

use App\Models\AdminUser;
use App\Models\User;

/**
 * Builds the actor context sent to gabaymo-ai from the authenticated
 * session. Scopes come from the staff role's module permissions; partner
 * identity and member region come from the database, never from the request.
 */
class AiContext
{
    /** Admin module => AI scopes it grants. */
    public const MODULE_SCOPES = [
        'knowledge-library' => ['knowledge.read', 'knowledge.write', 'knowledge.download'],
        'source-review' => ['knowledge.read', 'knowledge.review', 'knowledge.download'],
        'knowledge-search' => ['knowledge.read', 'knowledge.search', 'knowledge.answer_test'],
        'service-instructions' => ['instructions.read', 'instructions.write_global', 'instructions.write_partner'],
        'ai-review' => ['answers.review', 'chat.read', 'knowledge.read'],
        'overview' => ['system.health'],
        // Answer feedback and analytics are read through the answers.review scope (contract A).
        'feedback' => ['answers.review'],
        'analytics' => ['answers.review'],
    ];

    public static function admin(AdminUser $admin): array
    {
        $role = $admin->role;
        $scopes = [];
        foreach (self::MODULE_SCOPES as $module => $granted) {
            if ($role && $role->allows($module)) {
                $scopes = [...$scopes, ...$granted];
            }
        }
        // Only the system Administrator role may record a self-approval exception.
        if ($role?->is_system) {
            $scopes[] = 'knowledge.self_approval_exception';
        }

        return [
            'actor_type' => 'admin',
            'user_id' => $admin->id,
            'name' => $admin->name,
            'partner_id' => null,
            'scopes' => array_values(array_unique($scopes)),
            'geo' => [],
        ];
    }

    public static function member(User $user, ?string $reservationId = null): array
    {
        return array_filter([
            'actor_type' => 'member',
            'user_id' => $user->id,
            'name' => $user->name,
            'scopes' => ['chat.ask', 'chat.read'],
            'geo' => array_filter(['region_label' => $user->region]),
            'plan' => $user->subscriptionPlan?->name ?? 'Free Trial',
            // Paid members get answers guided by partners' preferred answers.
            'plan_paid' => (int) ($user->subscriptionPlan?->price ?? 0) > 0,
            'credit_reservation_id' => $reservationId,
        ], fn ($v) => $v !== null);
    }

    /** Scheduled jobs acting for no user (issuance alerts). */
    public static function system(array $scopes): array
    {
        return [
            'actor_type' => 'system',
            'user_id' => null,
            'partner_id' => null,
            'name' => 'gabaymo-ws scheduler',
            'scopes' => array_values($scopes),
            'geo' => [],
        ];
    }

    public static function partner(User $partner): array
    {
        return [
            'actor_type' => 'partner',
            'user_id' => $partner->id,
            // A partner acts only as itself; gabaymo-ai rejects any other partner_id.
            'partner_id' => $partner->id,
            'name' => $partner->name,
            'scopes' => ['instructions.read', 'instructions.write_partner', 'partner.knowledge.read', 'partner.training'],
            'geo' => [],
        ];
    }
}
