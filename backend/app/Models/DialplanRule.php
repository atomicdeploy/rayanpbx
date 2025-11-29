<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DialplanRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'context',
        'pattern',
        'priority',
        'app',
        'app_data',
        'enabled',
        'rule_type',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'priority' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Scope to filter by context
     */
    public function scopeByContext($query, string $context)
    {
        return $query->where('context', $context);
    }

    /**
     * Scope to filter enabled rules
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to filter by rule type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('rule_type', $type);
    }

    /**
     * Get the dialplan line representation for this rule
     */
    public function toDialplanLine(): string
    {
        $appData = $this->app_data ? "({$this->app_data})" : '';
        return "exten => {$this->pattern},{$this->priority},{$this->app}{$appData}";
    }

    /**
     * Get the verbose dialplan representation (with NoOp logging)
     */
    public function toVerboseDialplan(): string
    {
        $lines = [];
        
        // Add description as comment if provided
        if ($this->description) {
            $lines[] = "; {$this->description}";
        }
        
        // Check if this is a pattern rule that should use verbose format
        if ($this->rule_type === 'pattern' || $this->rule_type === 'internal') {
            $lines[] = "exten => {$this->pattern},1,NoOp({$this->name}: \${EXTEN})";
            
            if ($this->app === 'Dial') {
                $lines[] = " same => n,{$this->app}({$this->app_data})";
                $lines[] = " same => n,Hangup()";
            } else {
                $appData = $this->app_data ? "({$this->app_data})" : '';
                $lines[] = " same => n,{$this->app}{$appData}";
            }
        } else {
            // Simple rule format
            $appData = $this->app_data ? "({$this->app_data})" : '';
            $lines[] = "exten => {$this->pattern},{$this->priority},{$this->app}{$appData}";
        }
        
        return implode("\n", $lines);
    }

    /**
     * Create a default internal pattern rule for extension-to-extension calls
     */
    public static function createDefaultInternalPattern(): self
    {
        return static::create([
            'name' => 'Internal Extension Calls',
            'context' => 'from-internal',
            'pattern' => '_1XX',
            'priority' => 1,
            'app' => 'Dial',
            'app_data' => 'PJSIP/${EXTEN},30',
            'enabled' => true,
            'rule_type' => 'pattern',
            'description' => 'Pattern match for extensions 100-199. ${EXTEN} is replaced with the dialed number.',
            'sort_order' => 0,
        ]);
    }

    /**
     * Create an outbound routing rule
     * 
     * @param string $name Name for the rule
     * @param string $prefix Dial prefix (e.g., "9" for 9 + number)
     * @param string $trunkName Name of the SIP trunk (alphanumeric, underscores, hyphens only)
     * @param int $stripDigits Number of digits to strip from dialed number
     * @throws \InvalidArgumentException If trunkName contains invalid characters
     */
    public static function createOutboundRule(string $name, string $prefix, string $trunkName, int $stripDigits = 1): self
    {
        // Validate trunkName to prevent dialplan injection
        // Only allow alphanumeric characters, underscores, and hyphens
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $trunkName)) {
            throw new \InvalidArgumentException('Trunk name may only contain alphanumeric characters, underscores, and hyphens');
        }
        
        // Validate prefix
        if (!preg_match('/^[0-9]+$/', $prefix)) {
            throw new \InvalidArgumentException('Prefix may only contain digits');
        }
        
        $appData = $stripDigits > 0 
            ? 'PJSIP/${EXTEN:' . $stripDigits . '}@' . $trunkName . ',60'
            : 'PJSIP/${EXTEN}@' . $trunkName . ',60';

        return static::create([
            'name' => $name,
            'context' => 'from-internal',
            'pattern' => "_{$prefix}X.",
            'priority' => 1,
            'app' => 'Dial',
            'app_data' => $appData,
            'enabled' => true,
            'rule_type' => 'outbound',
            'description' => "Outbound routing via {$trunkName}. Dial {$prefix} + number.",
            'sort_order' => 10,
        ]);
    }
}
