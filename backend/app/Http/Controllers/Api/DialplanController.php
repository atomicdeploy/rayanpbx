<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DialplanRule;
use App\Adapters\AsteriskAdapter;
use App\Services\AsteriskConsoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DialplanController extends Controller
{
    private AsteriskAdapter $asterisk;
    private AsteriskConsoleService $console;

    public function __construct(AsteriskAdapter $asterisk, AsteriskConsoleService $console)
    {
        $this->asterisk = $asterisk;
        $this->console = $console;
    }

    /**
     * List all dialplan rules
     */
    public function index(Request $request)
    {
        $query = DialplanRule::query();

        if ($request->has('context')) {
            $query->byContext($request->context);
        }

        if ($request->has('type')) {
            $query->byType($request->type);
        }

        if ($request->has('enabled')) {
            if ($request->enabled === 'true' || $request->enabled === '1') {
                $query->enabled();
            } else {
                $query->where('enabled', false);
            }
        }

        $rules = $query->orderBy('context')
                      ->orderBy('sort_order')
                      ->orderBy('pattern')
                      ->get();

        return response()->json([
            'success' => true,
            'rules' => $rules,
            'total' => $rules->count(),
        ]);
    }

    /**
     * Store a new dialplan rule
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'context' => 'required|string|max:255',
            'pattern' => 'required|string|max:255',
            'priority' => 'integer|min:1|max:999',
            'app' => 'required|string|max:255',
            'app_data' => 'nullable|string|max:1024',
            'enabled' => 'boolean',
            'rule_type' => 'in:internal,outbound,inbound,pattern,custom',
            'description' => 'nullable|string|max:1024',
            'sort_order' => 'integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $rule = DialplanRule::create($request->all());

        // Regenerate and reload dialplan
        $reloadResult = $this->regenerateDialplan();

        return response()->json([
            'success' => true,
            'message' => 'Dialplan rule created successfully',
            'rule' => $rule,
            'reload_success' => $reloadResult['success'],
            'reload_output' => $reloadResult['output'] ?? null,
        ], 201);
    }

    /**
     * Show a specific dialplan rule
     */
    public function show($id)
    {
        $rule = DialplanRule::find($id);

        if (!$rule) {
            return response()->json([
                'success' => false,
                'message' => 'Dialplan rule not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'rule' => $rule,
            'dialplan_line' => $rule->toDialplanLine(),
            'verbose_dialplan' => $rule->toVerboseDialplan(),
        ]);
    }

    /**
     * Update a dialplan rule
     */
    public function update(Request $request, $id)
    {
        $rule = DialplanRule::find($id);

        if (!$rule) {
            return response()->json([
                'success' => false,
                'message' => 'Dialplan rule not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'context' => 'string|max:255',
            'pattern' => 'string|max:255',
            'priority' => 'integer|min:1|max:999',
            'app' => 'string|max:255',
            'app_data' => 'nullable|string|max:1024',
            'enabled' => 'boolean',
            'rule_type' => 'in:internal,outbound,inbound,pattern,custom',
            'description' => 'nullable|string|max:1024',
            'sort_order' => 'integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $rule->update($request->all());

        // Regenerate and reload dialplan
        $reloadResult = $this->regenerateDialplan();

        return response()->json([
            'success' => true,
            'message' => 'Dialplan rule updated successfully',
            'rule' => $rule->fresh(),
            'reload_success' => $reloadResult['success'],
            'reload_output' => $reloadResult['output'] ?? null,
        ]);
    }

    /**
     * Delete a dialplan rule
     */
    public function destroy($id)
    {
        $rule = DialplanRule::find($id);

        if (!$rule) {
            return response()->json([
                'success' => false,
                'message' => 'Dialplan rule not found',
            ], 404);
        }

        $rule->delete();

        // Regenerate and reload dialplan
        $reloadResult = $this->regenerateDialplan();

        return response()->json([
            'success' => true,
            'message' => 'Dialplan rule deleted successfully',
            'reload_success' => $reloadResult['success'],
            'reload_output' => $reloadResult['output'] ?? null,
        ]);
    }

    /**
     * Toggle a dialplan rule enabled/disabled
     */
    public function toggle($id)
    {
        $rule = DialplanRule::find($id);

        if (!$rule) {
            return response()->json([
                'success' => false,
                'message' => 'Dialplan rule not found',
            ], 404);
        }

        $rule->enabled = !$rule->enabled;
        $rule->save();

        // Regenerate and reload dialplan
        $reloadResult = $this->regenerateDialplan();

        return response()->json([
            'success' => true,
            'message' => $rule->enabled ? 'Dialplan rule enabled' : 'Dialplan rule disabled',
            'rule' => $rule,
            'reload_success' => $reloadResult['success'],
            'reload_output' => $reloadResult['output'] ?? null,
        ]);
    }

    /**
     * Get available contexts
     */
    public function contexts()
    {
        $contexts = DialplanRule::select('context')
            ->distinct()
            ->pluck('context')
            ->toArray();

        // Add default contexts if not present
        $defaultContexts = ['from-internal', 'from-trunk', 'outbound-routes'];
        foreach ($defaultContexts as $ctx) {
            if (!in_array($ctx, $contexts)) {
                $contexts[] = $ctx;
            }
        }

        sort($contexts);

        return response()->json([
            'success' => true,
            'contexts' => $contexts,
        ]);
    }

    /**
     * Get available applications
     */
    public function applications()
    {
        return response()->json([
            'success' => true,
            'applications' => [
                ['name' => 'Dial', 'description' => 'Place a call and optionally wait for answer'],
                ['name' => 'NoOp', 'description' => 'No operation (logging only)'],
                ['name' => 'Hangup', 'description' => 'Hang up the call'],
                ['name' => 'Answer', 'description' => 'Answer the call'],
                ['name' => 'VoiceMail', 'description' => 'Send to voicemail'],
                ['name' => 'Playback', 'description' => 'Play a sound file'],
                ['name' => 'Background', 'description' => 'Play a sound file while waiting for input'],
                ['name' => 'Goto', 'description' => 'Jump to another extension/priority'],
                ['name' => 'GotoIf', 'description' => 'Conditional jump'],
                ['name' => 'Queue', 'description' => 'Send to a call queue'],
                ['name' => 'Set', 'description' => 'Set a channel variable'],
                ['name' => 'Wait', 'description' => 'Wait for a number of seconds'],
            ],
        ]);
    }

    /**
     * Get pattern examples and help
     */
    public function patterns()
    {
        return response()->json([
            'success' => true,
            'patterns' => [
                [
                    'pattern' => '100',
                    'description' => 'Matches only extension 100',
                    'example' => 'Dial 100 to reach this extension',
                ],
                [
                    'pattern' => '_1XX',
                    'description' => 'Matches any 3-digit number starting with 1 (100-199)',
                    'example' => 'Pattern for internal extensions 100-199',
                ],
                [
                    'pattern' => '_NXX',
                    'description' => 'N=2-9, X=0-9. Matches 200-999',
                    'example' => 'Matches extensions starting with 2-9',
                ],
                [
                    'pattern' => '_X.',
                    'description' => 'Matches any digits, 1 or more long',
                    'example' => 'Catch-all pattern',
                ],
                [
                    'pattern' => '_9X.',
                    'description' => 'Matches 9 followed by any digits',
                    'example' => 'Dial 9 + external number for outbound calls',
                ],
                [
                    'pattern' => '_0X.',
                    'description' => 'Matches 0 followed by any digits',
                    'example' => 'Alternative outbound prefix',
                ],
                [
                    'pattern' => 's',
                    'description' => 'Start extension for incoming calls',
                    'example' => 'Used for incoming trunk calls without DID',
                ],
            ],
            'placeholders' => [
                ['name' => '${EXTEN}', 'description' => 'The dialed extension number'],
                ['name' => '${EXTEN:1}', 'description' => 'The dialed number with first digit stripped'],
                ['name' => '${CALLERID(num)}', 'description' => 'The caller ID number'],
                ['name' => '${CALLERID(name)}', 'description' => 'The caller ID name'],
            ],
        ]);
    }

    /**
     * Preview generated dialplan
     */
    public function preview(Request $request)
    {
        $context = $request->get('context', 'from-internal');
        
        $rules = DialplanRule::where('context', $context)
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->orderBy('pattern')
            ->get();

        $dialplan = $this->generateDialplanForContext($context, $rules);

        return response()->json([
            'success' => true,
            'context' => $context,
            'rules_count' => $rules->count(),
            'dialplan' => $dialplan,
        ]);
    }

    /**
     * Apply dialplan to Asterisk
     */
    public function apply()
    {
        $result = $this->regenerateDialplan();

        return response()->json([
            'success' => $result['success'],
            'message' => $result['success'] 
                ? 'Dialplan applied successfully' 
                : 'Failed to apply dialplan',
            'output' => $result['output'] ?? null,
            'error' => $result['error'] ?? null,
        ]);
    }

    /**
     * Create default dialplan rules
     */
    public function createDefaults()
    {
        $created = [];

        // Check if default internal pattern exists
        $hasInternalPattern = DialplanRule::where('context', 'from-internal')
            ->where('pattern', '_1XX')
            ->exists();

        if (!$hasInternalPattern) {
            $rule = DialplanRule::createDefaultInternalPattern();
            $created[] = $rule;
        }

        // Regenerate dialplan if we created any rules
        $reloadResult = ['success' => true];
        if (count($created) > 0) {
            $reloadResult = $this->regenerateDialplan();
        }

        return response()->json([
            'success' => true,
            'message' => count($created) > 0 
                ? 'Default dialplan rules created' 
                : 'Default rules already exist',
            'created' => $created,
            'reload_success' => $reloadResult['success'],
        ]);
    }

    /**
     * Get current Asterisk dialplan from live system
     */
    public function showLive()
    {
        try {
            $result = $this->console->showDialplan();
            
            return response()->json([
                'success' => true,
                'dialplan' => $result['output'] ?? $result['dialplan'] ?? '',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get live dialplan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate dialplan for a specific context
     */
    private function generateDialplanForContext(string $context, $rules): string
    {
        $lines = [];
        $lines[] = "[{$context}]";

        foreach ($rules as $rule) {
            if (!$rule->enabled) {
                // Comment out disabled rules
                $ruleLines = explode("\n", $rule->toVerboseDialplan());
                foreach ($ruleLines as $line) {
                    $lines[] = "; " . $line;
                }
            } else {
                $lines[] = $rule->toVerboseDialplan();
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Regenerate and reload dialplan
     */
    private function regenerateDialplan(): array
    {
        try {
            // Get all enabled rules grouped by context
            $contexts = DialplanRule::select('context')
                ->distinct()
                ->pluck('context');

            $fullDialplan = '';

            foreach ($contexts as $context) {
                $rules = DialplanRule::where('context', $context)
                    ->orderBy('sort_order')
                    ->orderBy('pattern')
                    ->get();

                $fullDialplan .= $this->generateDialplanForContext($context, $rules);
                $fullDialplan .= "\n";
            }

            // Write dialplan configuration
            $writeSuccess = $this->asterisk->writeDialplanConfig($fullDialplan, 'RayanPBX Dialplan');

            if (!$writeSuccess) {
                return [
                    'success' => false,
                    'error' => 'Failed to write dialplan configuration',
                ];
            }

            // Reload dialplan in Asterisk
            $reloadResult = $this->asterisk->reloadCLI();

            return [
                'success' => $reloadResult['success'],
                'output' => $reloadResult['dialplan_output'] ?? null,
                'error' => $reloadResult['error'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
