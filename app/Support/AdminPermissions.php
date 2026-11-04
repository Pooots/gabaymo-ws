<?php

namespace App\Support;

class AdminPermissions
{
    public const MODULES = [
        ['group' => 'Operations', 'key' => 'overview', 'label' => 'Overview'],
        ['group' => 'Operations', 'key' => 'members', 'label' => 'Members'],
        ['group' => 'Operations', 'key' => 'roles', 'label' => 'Roles & permissions'],
        ['group' => 'Operations', 'key' => 'partners', 'label' => 'Partners'],
        ['group' => 'Operations', 'key' => 'tickets', 'label' => 'Tickets'],
        ['group' => 'Operations', 'key' => 'access-keys', 'label' => 'Access keys'],
        ['group' => 'Operations', 'key' => 'referral-codes', 'label' => 'Referral codes'],
        ['group' => 'Operations', 'key' => 'plans', 'label' => 'Plans & tokens'],
        ['group' => 'Operations', 'key' => 'subscriptions', 'label' => 'Subscriptions'],
        ['group' => 'Operations', 'key' => 'payments', 'label' => 'Payments'],
        ['group' => 'Operations', 'key' => 'audit-log', 'label' => 'Audit log'],
        ['group' => 'Knowledge & AI', 'key' => 'knowledge-library', 'label' => 'Knowledge Library'],
        ['group' => 'Knowledge & AI', 'key' => 'source-review', 'label' => 'Source Review'],
        ['group' => 'Knowledge & AI', 'key' => 'knowledge-search', 'label' => 'Knowledge Search'],
        ['group' => 'Knowledge & AI', 'key' => 'service-instructions', 'label' => 'Service Instructions'],
        ['group' => 'Knowledge & AI', 'key' => 'ai-review', 'label' => 'AI Review Queue'],
    ];

    public static function keys(): array
    {
        return array_column(self::MODULES, 'key');
    }
}
