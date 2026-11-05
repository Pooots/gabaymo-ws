<?php

namespace App\Models;

use App\Support\AdminPermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_system',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'permissions' => 'array',
        ];
    }

    /** Staff accounts that have this role. */
    public function accounts(): HasMany
    {
        return $this->hasMany(AdminUser::class);
    }

    public function allows(string $key): bool
    {
        if ($this->is_system) {
            return true;
        }

        return in_array($key, $this->permissions ?? [], true);
    }

    public function allowsAny(array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->allows($key)) {
                return true;
            }
        }

        return false;
    }

    public function granted(): array
    {
        return $this->is_system ? AdminPermissions::keys() : array_values($this->permissions ?? []);
    }
}
