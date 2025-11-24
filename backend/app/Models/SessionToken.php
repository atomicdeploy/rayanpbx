<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\Contracts\HasAbilities;

class SessionToken extends Model implements HasAbilities
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'personal_access_tokens';

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tokenable_type',
        'tokenable_id',
        'name',
        'token',
        'jti',
        'abilities',
        'expires_at',
        'ip_address',
        'user_agent',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * Get the tokenable model that the access token belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function tokenable()
    {
        return $this->morphTo('tokenable');
    }

    /**
     * Find the token instance matching the given JTI.
     */
    public static function findByJti(string $jti): ?static
    {
        return static::where('jti', $jti)->first();
    }

    /**
     * Determine if the token has a given ability.
     *
     * @param  string  $ability
     */
    public function can($ability): bool
    {
        $abilities = $this->abilities ?? [];

        return in_array('*', $abilities) || in_array($ability, $abilities);
    }

    /**
     * Determine if the token is missing a given ability.
     *
     * @param  string  $ability
     */
    public function cant($ability): bool
    {
        return ! $this->can($ability);
    }

    /**
     * Check if the token has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Revoke the token.
     */
    public function revoke(): bool
    {
        return $this->delete();
    }

    /**
     * Update the last used timestamp.
     */
    public function updateLastUsed(): bool
    {
        return $this->forceFill(['last_used_at' => now()])->save();
    }
}
