<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class VoipPhone extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'ip',
        'mac',
        'extension',
        'name',
        'vendor',
        'model',
        'firmware',
        'status',
        'discovery_type',
        'user_agent',
        'cti_enabled',
        'snmp_enabled',
        'snmp_config',
        'credentials',
        'config',
        'last_seen',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'cti_enabled' => 'boolean',
        'snmp_enabled' => 'boolean',
        'snmp_config' => 'array',
        'config' => 'array',
        'last_seen' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'credentials',
    ];

    /**
     * Get the credentials attribute (decrypted).
     */
    protected function credentials(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (empty($value)) {
                    return null;
                }
                try {
                    return json_decode(Crypt::decryptString($value), true);
                } catch (\Exception $e) {
                    return null;
                }
            },
            set: function ($value) {
                if (empty($value)) {
                    return null;
                }

                return Crypt::encryptString(json_encode($value));
            },
        );
    }

    /**
     * Scope for online phones.
     */
    public function scopeOnline($query)
    {
        return $query->where('status', 'online');
    }

    /**
     * Scope for registered phones.
     */
    public function scopeRegistered($query)
    {
        return $query->where('status', 'registered');
    }

    /**
     * Scope for GrandStream phones.
     */
    public function scopeGrandstream($query)
    {
        return $query->where('vendor', 'grandstream');
    }

    /**
     * Scope for phones with CTI enabled.
     */
    public function scopeCtiEnabled($query)
    {
        return $query->where('cti_enabled', true);
    }

    /**
     * Mark phone as online.
     */
    public function markOnline(): self
    {
        $this->update([
            'status' => 'online',
            'last_seen' => now(),
        ]);

        return $this;
    }

    /**
     * Mark phone as offline.
     */
    public function markOffline(): self
    {
        $this->update([
            'status' => 'offline',
        ]);

        return $this;
    }

    /**
     * Update the last_seen timestamp or touch a given attribute.
     *
     * When called without arguments, updates the `last_seen` field to the current time.
     * When an attribute is provided, delegates to the parent's touch method.
     *
     * @param  string|null  $attribute  Optional attribute to touch
     */
    public function touch($attribute = null): bool
    {
        if ($attribute !== null) {
            return parent::touch($attribute);
        }

        $this->last_seen = now();

        return $this->save();
    }

    /**
     * Get decrypted credentials for API calls.
     */
    public function getCredentialsForApi(): array
    {
        $creds = $this->credentials;

        return $creds ?? ['username' => 'admin', 'password' => ''];
    }

    /**
     * Check if phone has valid credentials.
     */
    public function hasCredentials(): bool
    {
        $creds = $this->credentials;

        return ! empty($creds) && ! empty($creds['password'] ?? null);
    }

    /**
     * Get formatted display name.
     */
    public function getDisplayName(): string
    {
        if (! empty($this->name)) {
            return $this->name;
        }
        if (! empty($this->extension)) {
            return "Phone {$this->extension}";
        }
        if (! empty($this->model)) {
            return "{$this->model} ({$this->ip})";
        }

        return $this->ip;
    }
}
