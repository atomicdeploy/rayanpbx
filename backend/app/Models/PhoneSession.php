<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * PhoneSession Model
 *
 * Stores session/token information for authenticated VoIP phone connections.
 * This allows RayanPBX to maintain persistent sessions with GrandStream phones
 * instead of sending credentials with every request.
 */
class PhoneSession extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'voip_phone_id',
        'session_id',
        'challenge',
        'cookies',
        'token',
        'is_active',
        'authenticated_at',
        'expires_at',
        'last_used_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'authenticated_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'session_id',
        'challenge',
        'token',
        'cookies',
    ];

    /**
     * Get the VoIP phone that owns this session.
     */
    public function voipPhone(): BelongsTo
    {
        return $this->belongsTo(VoipPhone::class);
    }

    /**
     * Scope for active sessions.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for non-expired sessions.
     */
    public function scopeValid($query)
    {
        return $query->active()
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Check if the session is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Check if the session is valid and usable.
     */
    public function isValid(): bool
    {
        return $this->is_active && ! $this->isExpired();
    }

    /**
     * Mark the session as used and update last_used_at.
     */
    public function markUsed(): bool
    {
        return $this->update([
            'last_used_at' => now(),
        ]);
    }

    /**
     * Revoke/invalidate the session.
     */
    public function revoke(): bool
    {
        return $this->update([
            'is_active' => false,
        ]);
    }

    /**
     * Get decrypted cookies as array.
     */
    public function getCookiesArray(): array
    {
        if (empty($this->cookies)) {
            return [];
        }

        try {
            $decrypted = Crypt::decryptString($this->cookies);

            return json_decode($decrypted, true) ?? [];
        } catch (\Exception $e) {
            // If decryption fails, try parsing as plain JSON (legacy data)
            $decoded = json_decode($this->cookies, true);

            return is_array($decoded) ? $decoded : [];
        }
    }

    /**
     * Set cookies from array (encrypts before storing).
     */
    public function setCookiesFromArray(array $cookies): void
    {
        $this->cookies = Crypt::encryptString(json_encode($cookies));
    }

    /**
     * Get the session cookie string for HTTP requests.
     */
    public function getCookieString(): string
    {
        $cookies = $this->getCookiesArray();
        $parts = [];

        foreach ($cookies as $name => $value) {
            $parts[] = "{$name}={$value}";
        }

        return implode('; ', $parts);
    }

    /**
     * Get a valid session for a phone, or null if none exists.
     */
    public static function getValidSession(int $phoneId): ?self
    {
        return static::where('voip_phone_id', $phoneId)
            ->valid()
            ->orderBy('authenticated_at', 'desc')
            ->first();
    }

    /**
     * Revoke all sessions for a phone.
     */
    public static function revokeAllForPhone(int $phoneId): int
    {
        return static::where('voip_phone_id', $phoneId)
            ->active()
            ->update(['is_active' => false]);
    }

    /**
     * Clean up expired sessions.
     */
    public static function cleanupExpired(): int
    {
        return static::where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['is_active' => false]);
    }
}
