<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\ModuleActivityService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'role',
        'phone',
        'status',
        'approval_status',
        'last_login_at',
        'password',
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
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        // Red dots: a new, newly approved or re-roled user starts with every module marked as seen.
        static::created(fn (User $user) => app(ModuleActivityService::class)->markAllViewed($user));
        static::updated(function (User $user): void {
            if ($user->wasChanged('role') || ($user->wasChanged('approval_status') && $user->approval_status === 'approved')) {
                app(ModuleActivityService::class)->markAllViewed($user);
            }
        });
    }

    public function getRoleLabelAttribute(): string
    {
        return match ($this->role) {
            'admin' => 'Admin',
            'inventory_officer' => 'Inventory Officer',
            'sales_officer' => 'Sales Officer',
            'dispatch_officer' => 'Dispatch Officer',
            'driver' => 'Driver',
            default => ucfirst(str_replace('_', ' ', (string) $this->role)),
        };
    }
}
