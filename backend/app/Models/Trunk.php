<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Trunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'host',
        'port',
        'username',
        'secret',
        'enabled',
        'transport',
        'codecs',
        'context',
        'priority',
        'prefix',
        'strip_digits',
        'max_channels',
        'notes',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'codecs' => 'array',
        'priority' => 'integer',
        'strip_digits' => 'integer',
        'max_channels' => 'integer',
    ];

    protected $hidden = [
        'secret',
    ];

    public function getStatusAttribute()
    {
        // This will be populated by real-time AMI data
        return cache()->get("trunk_status_{$this->name}", 'unknown');
    }
}
