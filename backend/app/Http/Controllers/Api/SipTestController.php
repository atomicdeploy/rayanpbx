<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class SipTestController extends Controller
{
    /**
     * Check which SIP testing tools are available
     */
    public function checkTools()
    {
        $tools = [
            'pjsua' => $this->isToolInstalled('pjsua'),
            'sipsak' => $this->isToolInstalled('sipsak'),
            'sipexer' => $this->isToolInstalled('sipexer'),
            'sipp' => $this->isToolInstalled('sipp'),
        ];
        
        $available = array_filter($tools, fn($installed) => $installed);
        
        return response()->json([
            'tools' => $tools,
            'available_count' => count($available),
            'recommended_tool' => $this->getRecommendedTool($available),
        ]);
    }
    
    /**
     * Install a SIP testing tool
     */
    public function installTool(Request $request)
    {
        $validated = $request->validate([
            'tool' => 'required|string|in:pjsua,sipsak,sipp',
        ]);
        
        $tool = $validated['tool'];
        $scriptPath = base_path('../scripts/sip-test-suite.sh');
        
        if (!file_exists($scriptPath)) {
            return response()->json([
                'success' => false,
                'error' => 'SIP test suite script not found',
            ], 500);
        }
        
        try {
            $result = Process::run([
                'sudo',
                'bash',
                $scriptPath,
                'install',
                $tool
            ]);
            
            $success = $result->successful();
            
            return response()->json([
                'success' => $success,
                'tool' => $tool,
                'output' => $result->output(),
                'error' => $success ? null : $result->errorOutput(),
            ]);
        } catch (\Exception $e) {
            Log::error('SIP tool installation failed', [
                'tool' => $tool,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Test SIP registration for an extension
     */
    public function testRegistration(Request $request)
    {
        $validated = $request->validate([
            'extension' => 'required|string',
            'password' => 'required|string',
            'server' => 'nullable|string',
            'port' => 'nullable|integer|min:1|max:65535',
        ]);
        
        $extension = $validated['extension'];
        $password = $validated['password'];
        $server = $validated['server'] ?? '127.0.0.1';
        $port = $validated['port'] ?? 5060;
        
        $scriptPath = base_path('../scripts/sip-test-suite.sh');
        
        if (!file_exists($scriptPath)) {
            return response()->json([
                'success' => false,
                'error' => 'SIP test suite script not found',
            ], 500);
        }
        
        try {
            $result = Process::run([
                'bash',
                $scriptPath,
                '-s', $server,
                '-p', (string)$port,
                'register',
                $extension,
                $password,
            ]);
            
            $output = $result->output();
            $success = $result->successful();
            
            // Parse output for details
            $registered = str_contains($output, '✅ PASS: Registration successful');
            $failed = str_contains($output, '❌ FAIL');
            
            $troubleshooting = [];
            if (str_contains($output, 'Authentication failed')) {
                $troubleshooting[] = 'Check username and password';
            }
            if (str_contains($output, 'Network issue')) {
                $troubleshooting[] = 'Check server connectivity';
            }
            if (str_contains($output, 'Extension not found')) {
                $troubleshooting[] = 'Verify extension exists in Asterisk';
            }
            
            return response()->json([
                'success' => $success,
                'registered' => $registered,
                'extension' => $extension,
                'server' => $server,
                'port' => $port,
                'output' => $output,
                'troubleshooting' => $troubleshooting,
            ]);
        } catch (\Exception $e) {
            Log::error('SIP registration test failed', [
                'extension' => $extension,
                'server' => $server,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Test call between two extensions
     */
    public function testCall(Request $request)
    {
        $validated = $request->validate([
            'from_extension' => 'required|string',
            'from_password' => 'required|string',
            'to_extension' => 'required|string',
            'to_password' => 'required|string',
            'server' => 'nullable|string',
            'port' => 'nullable|integer|min:1|max:65535',
        ]);
        
        $fromExt = $validated['from_extension'];
        $fromPass = $validated['from_password'];
        $toExt = $validated['to_extension'];
        $toPass = $validated['to_password'];
        $server = $validated['server'] ?? '127.0.0.1';
        $port = $validated['port'] ?? 5060;
        
        $scriptPath = base_path('../scripts/sip-test-suite.sh');
        
        if (!file_exists($scriptPath)) {
            return response()->json([
                'success' => false,
                'error' => 'SIP test suite script not found',
            ], 500);
        }
        
        try {
            $result = Process::run([
                'bash',
                $scriptPath,
                '-s', $server,
                '-p', (string)$port,
                'call',
                $fromExt,
                $fromPass,
                $toExt,
                $toPass,
            ]);
            
            $output = $result->output();
            $success = $result->successful();
            
            // Parse output for details
            $callEstablished = str_contains($output, '✅ PASS: Call established');
            $failed = str_contains($output, '❌ FAIL');
            
            $troubleshooting = [];
            if (str_contains($output, 'Call not answered')) {
                $troubleshooting[] = 'Destination extension did not answer';
            }
            if (str_contains($output, 'Destination extension not found')) {
                $troubleshooting[] = 'Check if destination extension is registered';
            }
            if (str_contains($output, 'Service unavailable')) {
                $troubleshooting[] = 'Check Asterisk dialplan configuration';
            }
            
            return response()->json([
                'success' => $success,
                'call_established' => $callEstablished,
                'from_extension' => $fromExt,
                'to_extension' => $toExt,
                'server' => $server,
                'port' => $port,
                'output' => $output,
                'troubleshooting' => $troubleshooting,
            ]);
        } catch (\Exception $e) {
            Log::error('SIP call test failed', [
                'from_extension' => $fromExt,
                'to_extension' => $toExt,
                'server' => $server,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Run full test suite with two extensions
     */
    public function testFull(Request $request)
    {
        $validated = $request->validate([
            'extension1' => 'required|string',
            'password1' => 'required|string',
            'extension2' => 'required|string',
            'password2' => 'required|string',
            'server' => 'nullable|string',
            'port' => 'nullable|integer|min:1|max:65535',
        ]);
        
        $ext1 = $validated['extension1'];
        $pass1 = $validated['password1'];
        $ext2 = $validated['extension2'];
        $pass2 = $validated['password2'];
        $server = $validated['server'] ?? '127.0.0.1';
        $port = $validated['port'] ?? 5060;
        
        $scriptPath = base_path('../scripts/sip-test-suite.sh');
        
        if (!file_exists($scriptPath)) {
            return response()->json([
                'success' => false,
                'error' => 'SIP test suite script not found',
            ], 500);
        }
        
        try {
            $result = Process::run([
                'bash',
                $scriptPath,
                '-s', $server,
                '-p', (string)$port,
                'full',
                $ext1,
                $pass1,
                $ext2,
                $pass2,
            ]);
            
            $output = $result->output();
            $success = $result->successful();
            
            // Parse test results
            preg_match('/Passed:\s*(\d+)/', $output, $passedMatches);
            preg_match('/Failed:\s*(\d+)/', $output, $failedMatches);
            preg_match('/Total:\s*(\d+)/', $output, $totalMatches);
            
            $passed = isset($passedMatches[1]) ? (int)$passedMatches[1] : 0;
            $failed = isset($failedMatches[1]) ? (int)$failedMatches[1] : 0;
            $total = isset($totalMatches[1]) ? (int)$totalMatches[1] : 0;
            
            return response()->json([
                'success' => $success,
                'results' => [
                    'passed' => $passed,
                    'failed' => $failed,
                    'total' => $total,
                ],
                'output' => $output,
            ]);
        } catch (\Exception $e) {
            Log::error('Full SIP test failed', [
                'extension1' => $ext1,
                'extension2' => $ext2,
                'server' => $server,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Test SIP OPTIONS ping
     */
    public function testOptions(Request $request)
    {
        $validated = $request->validate([
            'server' => 'nullable|string',
            'port' => 'nullable|integer|min:1|max:65535',
        ]);
        
        $server = $validated['server'] ?? '127.0.0.1';
        $port = $validated['port'] ?? 5060;
        
        $scriptPath = base_path('../scripts/sip-test-suite.sh');
        
        if (!file_exists($scriptPath)) {
            return response()->json([
                'success' => false,
                'error' => 'SIP test suite script not found',
            ], 500);
        }
        
        try {
            $result = Process::run([
                'bash',
                $scriptPath,
                '-s', $server,
                '-p', (string)$port,
                'options',
            ]);
            
            $output = $result->output();
            $success = $result->successful();
            
            $responsive = str_contains($output, '✅ PASS: SIP server is responsive');
            
            return response()->json([
                'success' => $success,
                'responsive' => $responsive,
                'server' => $server,
                'port' => $port,
                'output' => $output,
            ]);
        } catch (\Exception $e) {
            Log::error('SIP OPTIONS test failed', [
                'server' => $server,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Helper: Check if a tool is installed
     */
    private function isToolInstalled(string $tool): bool
    {
        try {
            $result = Process::run(['which', $tool]);
            return $result->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Helper: Get recommended tool
     */
    private function getRecommendedTool(array $availableTools): ?string
    {
        if (isset($availableTools['pjsua'])) {
            return 'pjsua';
        }
        if (isset($availableTools['sipsak'])) {
            return 'sipsak';
        }
        if (isset($availableTools['sipexer'])) {
            return 'sipexer';
        }
        if (isset($availableTools['sipp'])) {
            return 'sipp';
        }
        return null;
    }
}
