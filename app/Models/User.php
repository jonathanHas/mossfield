<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /** Write-offs this user recorded (the audit trail). */
    public function stockWriteOffs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockWriteOff::class, 'written_off_by');
    }

    public function hasRole(UserRole|string ...$roles): bool
    {
        $normalized = array_map(
            fn ($r) => $r instanceof UserRole ? $r : UserRole::from($r),
            $roles
        );

        return in_array($this->role, $normalized, true);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isOffice(): bool
    {
        return $this->role === UserRole::Office;
    }

    public function isFactory(): bool
    {
        return $this->role === UserRole::Factory;
    }

    public function isDriver(): bool
    {
        return $this->role === UserRole::Driver;
    }

    /**
     * Returns true if this user is the only remaining active admin.
     * Used to block demote/deactivate/delete actions that would lock
     * the system out of user management.
     */
    public function isLastActiveAdmin(): bool
    {
        if (! $this->isAdmin() || ! $this->is_active) {
            return false;
        }

        return self::query()
            ->where('role', UserRole::Admin->value)
            ->where('is_active', true)
            ->where('id', '!=', $this->id)
            ->doesntExist();
    }

    /**
     * Invalidate every database session for this user AND their "Remember me"
     * cookies. Called after role demotions and deactivations so elevated
     * session tokens can't outlive the change. Relies on SESSION_DRIVER=database
     * (set in .env.example).
     *
     * Cycling remember_token matters as much as deleting the sessions: a
     * recaller cookie re-creates a session from the stored token on the next
     * request, so deleting sessions alone signed the user straight back in.
     * EnsureUserIsActive is the backstop for anything this misses.
     */
    public function logOutEverywhere(): int
    {
        $this->setRememberToken(Str::random(60));
        $this->saveQuietly();

        return DB::table('sessions')->where('user_id', $this->id)->delete();
    }
}
