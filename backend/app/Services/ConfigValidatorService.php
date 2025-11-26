<?php

namespace App\Services;

use Exception;

/**
 * Configuration Validator Service
 * Validates Asterisk PJSIP and Dialplan configurations
 * Analyzes configs for correctness and provides feedback
 */
class ConfigValidatorService
{
    /**
     * Validate PJSIP configuration
     * 
     * @param string $config The configuration content
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public function validatePjsipConfig($config)
    {
        $errors = [];
        $warnings = [];
        
        // Check for duplicate sections
        preg_match_all('/^\[([^\]]+)\]/m', $config, $matches);
        $sections = $matches[1];
        $duplicates = array_diff_assoc($sections, array_unique($sections));
        
        if (!empty($duplicates)) {
            $errors[] = "Duplicate sections found: " . implode(', ', array_unique($duplicates));
        }
        
        // Validate endpoint sections
        if (preg_match_all('/^\[([^\]]+)\].*?^type=endpoint/ms', $config, $endpointMatches)) {
            foreach ($endpointMatches[1] as $endpoint) {
                // Check if endpoint has auth
                if (!preg_match("/^\[$endpoint\].*?^type=auth/ms", $config)) {
                    $warnings[] = "Endpoint '$endpoint' may need authentication section";
                }
                
                // Check if endpoint has AOR
                if (!preg_match("/^\[$endpoint\].*?^type=aor/ms", $config)) {
                    $warnings[] = "Endpoint '$endpoint' may need AOR section";
                }
                
                // Check for codec configuration
                if (!preg_match("/^\[$endpoint\].*?^allow=/ms", $config)) {
                    $warnings[] = "Endpoint '$endpoint' has no codecs configured";
                }
                
                // Check for presence/BLF support configuration
                if (!preg_match("/^\[$endpoint\].*?^subscribe_context=/ms", $config)) {
                    $warnings[] = "Endpoint '$endpoint' has no subscribe_context - presence/BLF subscriptions may not work";
                }
            }
        }
        
        // Check transport references
        if (preg_match_all('/^transport=([^\s]+)/m', $config, $transportRefs)) {
            foreach ($transportRefs[1] as $transport) {
                if (!preg_match("/^\[$transport\].*?^type=transport/ms", $config)) {
                    $errors[] = "Transport '$transport' referenced but not defined";
                }
            }
        }
        
        // Validate AOR contacts for trunks
        if (preg_match_all('/^contact=sip:([^\s:]+)/m', $config, $contacts)) {
            foreach ($contacts[1] as $contact) {
                // Basic domain validation
                if (!filter_var($contact, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    if (!filter_var($contact, FILTER_VALIDATE_IP)) {
                        $warnings[] = "Contact '$contact' may not be a valid domain or IP";
                    }
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    /**
     * Validate dialplan configuration
     * 
     * @param string $config The dialplan content
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public function validateDialplan($config)
    {
        $errors = [];
        $warnings = [];
        
        // Check for valid context definitions
        preg_match_all('/^\[([^\]]+)\]/m', $config, $contexts);
        
        foreach ($contexts[1] as $context) {
            // Check if context has any extensions
            $contextBlock = $this->extractContext($config, $context);
            
            if (!preg_match('/^exten\s*=>/m', $contextBlock)) {
                $warnings[] = "Context '$context' has no extensions defined";
            }
        }
        
        // Validate extension patterns
        if (preg_match_all('/^exten\s*=>\s*([^,]+),/m', $config, $extenMatches)) {
            foreach ($extenMatches[1] as $pattern) {
                $pattern = trim($pattern);
                
                // Check for valid pattern syntax
                if (strpos($pattern, '_') === 0) {
                    // Pattern matching
                    if (!preg_match('/^_[0-9NXZ\[\]\.\!]+$/', $pattern)) {
                        $warnings[] = "Pattern '$pattern' may have invalid syntax";
                    }
                }
            }
        }
        
        // Check for PJSIP endpoint references
        if (preg_match_all('/Dial\(PJSIP\/([^@\)]+)/m', $config, $dialMatches)) {
            $endpoints = array_unique($dialMatches[1]);
            $warnings[] = "Dialplan references " . count($endpoints) . " PJSIP endpoints - ensure they are configured";
        }
        
        // Check for device state hints (presence/BLF support)
        if (!preg_match('/^exten\s*=>\s*\d+,hint,PJSIP/m', $config)) {
            $warnings[] = "No hints found - BLF (Busy Lamp Field) may not work. Consider adding hints like: exten => 100,hint,PJSIP/100";
        }
        
        // Check for proper priority sequencing
        $lines = explode("\n", $config);
        $currentExten = null;
        $currentPriority = 0;
        
        foreach ($lines as $line) {
            if (preg_match('/^exten\s*=>\s*([^,]+),(\d+|n),/', $line, $match)) {
                $exten = trim($match[1]);
                $priority = trim($match[2]);
                
                if ($priority !== 'n' && $priority !== '1') {
                    if ($exten === $currentExten && $priority <= $currentPriority) {
                        $warnings[] = "Extension '$exten' may have incorrect priority sequence";
                    }
                }
                
                $currentExten = $exten;
                $currentPriority = ($priority === 'n') ? $currentPriority + 1 : intval($priority);
            } elseif (preg_match('/^\s+same\s*=>\s*n,/', $line)) {
                $currentPriority++;
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    /**
     * Analyze user-provided configuration
     * 
     * @param array $pjsipConfig Array with endpoint, aor, identify sections
     * @param array $dialplanConfig Array with context and extensions
     * @return array Analysis results with recommendations
     */
    public function analyzeConfiguration($pjsipConfig, $dialplanConfig)
    {
        $analysis = [
            'pjsip' => [
                'correct' => [],
                'incorrect' => [],
                'recommendations' => []
            ],
            'dialplan' => [
                'correct' => [],
                'incorrect' => [],
                'recommendations' => []
            ]
        ];
        
        // Analyze PJSIP transport
        if (isset($pjsipConfig['transport']) && $pjsipConfig['transport']['type'] === 'transport') {
            $analysis['pjsip']['correct'][] = "Transport section properly defined";
            
            if (isset($pjsipConfig['transport']['bind'])) {
                $bind = $pjsipConfig['transport']['bind'];
                if (strpos($bind, '0.0.0.0') !== false || strpos($bind, '172.24.23.74') !== false) {
                    $analysis['pjsip']['correct'][] = "Transport bound to specific interface: $bind";
                }
            }
            
            if (!isset($pjsipConfig['transport']['allow_reload'])) {
                $analysis['pjsip']['recommendations'][] = "Consider adding 'allow_reload=yes' to transport for dynamic updates";
            }
        }
        
        // Analyze trunk endpoint
        if (isset($pjsipConfig['trunk-endpoint'])) {
            $trunk = $pjsipConfig['trunk-endpoint'];
            
            if ($trunk['type'] === 'endpoint') {
                $analysis['pjsip']['correct'][] = "Trunk endpoint properly configured";
            }
            
            if (isset($trunk['context'])) {
                $analysis['pjsip']['correct'][] = "Trunk has dedicated context: {$trunk['context']}";
            }
            
            if (isset($trunk['direct_media']) && $trunk['direct_media'] === 'no') {
                $analysis['pjsip']['correct'][] = "Direct media disabled - good for NAT scenarios";
            } else {
                $analysis['pjsip']['recommendations'][] = "Consider setting 'direct_media=no' if behind NAT";
            }
            
            // Check from_domain
            if (isset($trunk['from_domain'])) {
                $analysis['pjsip']['correct'][] = "From domain set for trunk: {$trunk['from_domain']}";
            }
        }
        
        // Analyze trunk AOR
        if (isset($pjsipConfig['trunk-aor'])) {
            $aor = $pjsipConfig['trunk-aor'];
            
            if ($aor['type'] === 'aor' && isset($aor['contact'])) {
                $analysis['pjsip']['correct'][] = "Trunk AOR with static contact configured";
                
                // Validate contact format
                if (preg_match('/^sip:([^:]+)(:\d+)?$/', $aor['contact'], $match)) {
                    $analysis['pjsip']['correct'][] = "Contact URI format is valid";
                } else {
                    $analysis['pjsip']['incorrect'][] = "Contact URI may have invalid format: {$aor['contact']}";
                }
            }
        }
        
        // Analyze identify section
        if (isset($pjsipConfig['trunk-identify'])) {
            $identify = $pjsipConfig['trunk-identify'];
            
            if ($identify['type'] === 'identify' && isset($identify['endpoint']) && isset($identify['match'])) {
                $analysis['pjsip']['correct'][] = "Identify section properly links endpoint to incoming IP/domain";
            }
        }
        
        // Analyze extension endpoint
        if (isset($pjsipConfig['extension'])) {
            $ext = $pjsipConfig['extension'];
            
            if ($ext['type'] === 'endpoint') {
                $analysis['pjsip']['correct'][] = "Extension endpoint properly configured";
            }
            
            // Check auth reference
            if (isset($ext['auth'])) {
                if (isset($pjsipConfig['extension-auth'])) {
                    $analysis['pjsip']['correct'][] = "Extension has authentication configured";
                } else {
                    $analysis['pjsip']['incorrect'][] = "Extension references auth section that doesn't exist";
                }
            }
            
            // Check max_contacts in AOR
            if (isset($pjsipConfig['extension-aor']) && $pjsipConfig['extension-aor']['type'] === 'aor') {
                if (!isset($pjsipConfig['extension-aor']['max_contacts'])) {
                    $analysis['pjsip']['recommendations'][] = "Consider adding 'max_contacts=1' to extension AOR";
                } else {
                    $analysis['pjsip']['correct'][] = "Extension AOR has max_contacts configured";
                }
            }
        }
        
        // Analyze dialplan - incoming context
        if (isset($dialplanConfig['from-trunk'])) {
            $incoming = $dialplanConfig['from-trunk'];
            
            if (preg_match('/Dial\(PJSIP\/(\d+)/', $incoming, $match)) {
                $analysis['dialplan']['correct'][] = "Incoming calls routed to extension {$match[1]}";
            }
            
            if (strpos($incoming, 'Voicemail') !== false) {
                $analysis['dialplan']['correct'][] = "Voicemail configured as fallback";
            }
        }
        
        // Analyze dialplan - outgoing context
        if (isset($dialplanConfig['outgoing'])) {
            $outgoing = $dialplanConfig['outgoing'];
            
            if (preg_match('/exten\s*=>\s*_([0-9X\.]+),/', $outgoing, $match)) {
                $pattern = $match[1];
                $analysis['dialplan']['correct'][] = "Outbound pattern defined: $pattern";
                
                // Check if it's using PJSIP correctly
                if (preg_match('/Dial\(PJSIP\/\$\{EXTEN\}@([^)]+)\)/', $outgoing, $trunkMatch)) {
                    $analysis['dialplan']['correct'][] = "Outbound calls route to trunk: {$trunkMatch[1]}";
                }
            }
            
            // Check for digit manipulation
            if (strpos($outgoing, 'EXTEN:') === false) {
                $analysis['dialplan']['recommendations'][] = "Consider using \${EXTEN:1} to strip leading digit if needed";
            }
        }
        
        return $analysis;
    }
    
    /**
     * Extract context block from dialplan
     */
    private function extractContext($config, $contextName)
    {
        $lines = explode("\n", $config);
        $inContext = false;
        $contextBlock = '';
        
        foreach ($lines as $line) {
            if (preg_match('/^\[' . preg_quote($contextName, '/') . '\]/', $line)) {
                $inContext = true;
                $contextBlock .= $line . "\n";
                continue;
            }
            
            if ($inContext) {
                if (preg_match('/^\[([^\]]+)\]/', $line)) {
                    break; // New context started
                }
                $contextBlock .= $line . "\n";
            }
        }
        
        return $contextBlock;
    }
}
