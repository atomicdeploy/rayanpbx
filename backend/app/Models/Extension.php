<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Extension extends Model
{
    use HasFactory;

    protected $fillable = [
        'extension_number',
        'name',
        'email',
        'secret',
        'enabled',
        'context',
        'transport',
        'codecs',
        'max_contacts',
        'direct_media',
        'qualify_frequency',
        'caller_id',
        'voicemail_enabled',
        'notes',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'voicemail_enabled' => 'boolean',
        'codecs' => 'array',
        'qualify_frequency' => 'integer',
    ];

    protected $hidden = [
        'secret',
    ];

    public function getStatusAttribute()
    {
        // This will be populated by real-time AMI data
        return cache()->get("extension_status_{$this->extension_number}", 'offline');
    }
}
