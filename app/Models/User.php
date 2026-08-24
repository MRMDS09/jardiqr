<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_PLATFORM_ADMIN = 'platform_admin';

    public const ROLE_ORG_ADMIN = 'org_admin';

    public const ROLE_INVENTORY_AGENT = 'inventory_agent';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'name',
        'email',
        'phone',
        'password',
        'role',
        'is_active',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function canAccessApplication(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->role === self::ROLE_PLATFORM_ADMIN) {
            return true;
        }

        if (! in_array($this->role, [self::ROLE_ORG_ADMIN, self::ROLE_INVENTORY_AGENT], true)) {
            return false;
        }

        if ($this->organization_id === null) {
            return false;
        }

        return in_array($this->organization?->status, [
            Organization::STATUS_TRIAL,
            Organization::STATUS_ACTIVE,
        ], true);
    }
}
