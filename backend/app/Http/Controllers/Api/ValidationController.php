<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ConfigValidatorService;
use App\Services\PjsipService;
use Illuminate\Http\Request;

/**
 * Configuration Validation Controller
 * Validates PJSIP and dialplan configurations
 */
class ValidationController extends Controller
{
    private $validator;
    private $pjsip;
    
    public function __construct(ConfigValidatorService $validator, PjsipService $pjsip)
    {
        $this->validator = $validator;
        $this->pjsip = $pjsip;
    }
    
    /**
     * Validate PJSIP configuration
     * POST /api/validate/pjsip
     */
    public function validatePjsip(Request $request)
    {
        $validated = $request->validate([
            'config' => 'required|string'
        ]);
        
        $result = $this->validator->validatePjsipConfig($validated['config']);
        
        return response()->json($result);
    }
    
    /**
     * Validate dialplan configuration
     * POST /api/validate/dialplan
     */
    public function validateDialplan(Request $request)
    {
        $validated = $request->validate([
            'config' => 'required|string'
        ]);
        
        $result = $this->validator->validateDialplan($validated['config']);
        
        return response()->json($result);
    }
    
    /**
     * Analyze user-provided configuration
     * POST /api/validate/analyze
     */
    public function analyzeConfig(Request $request)
    {
        $validated = $request->validate([
            'pjsip' => 'required|array',
            'dialplan' => 'required|array'
        ]);
        
        $result = $this->validator->analyzeConfiguration(
            $validated['pjsip'],
            $validated['dialplan']
        );
        
        return response()->json($result);
    }
    
    /**
     * Validate trunk connection
     * GET /api/validate/trunk/{name}
     */
    public function validateTrunk($name)
    {
        $result = $this->pjsip->validateTrunkConnection($name);
        
        return response()->json($result);
    }
    
    /**
     * Validate extension registration
     * GET /api/validate/extension/{extension}
     */
    public function validateExtension($extension)
    {
        $result = $this->pjsip->validateExtensionRegistration($extension);
        
        return response()->json($result);
    }
    
    /**
     * Test call routing
     * POST /api/validate/routing
     */
    public function testRouting(Request $request)
    {
        $validated = $request->validate([
            'from' => 'required|string',
            'to' => 'required|string'
        ]);
        
        $result = $this->pjsip->testCallRouting($validated['from'], $validated['to']);
        
        return response()->json($result);
    }
    
    /**
     * Get registration hooks configuration
     * GET /api/validate/hooks/registration
     */
    public function getRegistrationHooks()
    {
        $hooks = $this->pjsip->getRegistrationHooks();
        
        return response()->json($hooks);
    }
    
    /**
     * Get GrandStream provisioning hooks
     * GET /api/validate/hooks/grandstream
     */
    public function getGrandstreamHooks()
    {
        $hooks = $this->pjsip->getGrandstreamHooks();
        
        return response()->json($hooks);
    }
}
