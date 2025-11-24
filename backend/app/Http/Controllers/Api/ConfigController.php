<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ConfigController extends Controller
{
    private $envPath;
    private $sensitiveKeys = [
        'password', 'secret', 'key', 'token', 'api_key', 
        'private_key', 'jwt_secret', 'db_password', 'ami_secret'
    ];

    public function __construct()
    {
        // Get the project root .env path (one level up from backend)
        $this->envPath = base_path('../.env');
        
        // Fallback to backend .env if root doesn't exist
        if (!File::exists($this->envPath)) {
            $this->envPath = base_path('.env');
        }
    }

    /**
     * List all environment variables
     */
    public function index()
    {
        try {
            if (!File::exists($this->envPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Environment file not found'
                ], 404);
            }

            $content = File::get($this->envPath);
            $lines = explode("\n", $content);
            $config = [];
            $comments = [];
            $lastComment = '';

            foreach ($lines as $line) {
                $line = trim($line);
                
                // Skip empty lines
                if (empty($line)) {
                    $lastComment = '';
                    continue;
                }
                
                // Collect comments
                if (str_starts_with($line, '#')) {
                    $lastComment = ltrim($line, '# ');
                    continue;
                }
                
                // Parse key=value
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    
                    // Remove quotes from value
                    $value = trim($value, '"\'');
                    
                    // Mask sensitive values
                    $isSensitive = $this->isSensitive($key);
                    
                    $config[] = [
                        'key' => $key,
                        'value' => $isSensitive ? '********' : $value,
                        'sensitive' => $isSensitive,
                        'description' => $lastComment,
                    ];
                    
                    $lastComment = '';
                }
            }

            return response()->json([
                'success' => true,
                'data' => $config,
                'count' => count($config)
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to read config: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to read configuration: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific environment variable
     */
    public function show($key)
    {
        try {
            $value = $this->getEnvValue($key);
            
            if ($value === null) {
                return response()->json([
                    'success' => false,
                    'message' => "Key '$key' not found"
                ], 404);
            }

            $isSensitive = $this->isSensitive($key);

            return response()->json([
                'success' => true,
                'data' => [
                    'key' => $key,
                    'value' => $isSensitive ? '********' : $value,
                    'sensitive' => $isSensitive,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to get config '$key': " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve configuration: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new environment variable
     */
    public function store(Request $request)
    {
        $request->validate([
            'key' => 'required|string|regex:/^[A-Z_][A-Z0-9_]*$/|max:255',
            'value' => 'required|string',
        ]);

        try {
            $key = $request->input('key');
            $value = $request->input('value');

            // Check if key already exists
            if ($this->getEnvValue($key) !== null) {
                return response()->json([
                    'success' => false,
                    'message' => "Key '$key' already exists. Use update to modify it."
                ], 409);
            }

            // Backup the file
            $this->backupEnvFile();

            // Add the new key
            File::append($this->envPath, "\n{$key}={$value}");

            Log::info("Config key added: $key");

            return response()->json([
                'success' => true,
                'message' => 'Configuration added successfully',
                'data' => [
                    'key' => $key,
                    'value' => $this->isSensitive($key) ? '********' : $value,
                ]
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to add config: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to add configuration: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an existing environment variable
     */
    public function update(Request $request, $key)
    {
        $request->validate([
            'value' => 'required|string',
        ]);

        try {
            $value = $request->input('value');

            // Check if key exists
            if ($this->getEnvValue($key) === null) {
                return response()->json([
                    'success' => false,
                    'message' => "Key '$key' not found. Use store to create it."
                ], 404);
            }

            // Backup the file
            $this->backupEnvFile();

            // Update the key
            $this->setEnvValue($key, $value);

            Log::info("Config key updated: $key");

            return response()->json([
                'success' => true,
                'message' => 'Configuration updated successfully',
                'data' => [
                    'key' => $key,
                    'value' => $this->isSensitive($key) ? '********' : $value,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to update config '$key': " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update configuration: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an environment variable
     */
    public function destroy($key)
    {
        try {
            // Check if key exists
            if ($this->getEnvValue($key) === null) {
                return response()->json([
                    'success' => false,
                    'message' => "Key '$key' not found"
                ], 404);
            }

            // Backup the file
            $this->backupEnvFile();

            // Remove the key
            $content = File::get($this->envPath);
            $lines = explode("\n", $content);
            $newLines = [];

            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                // Skip the line with this key
                if (!str_starts_with($trimmedLine, $key . '=')) {
                    $newLines[] = $line;
                }
            }

            File::put($this->envPath, implode("\n", $newLines));

            Log::info("Config key removed: $key");

            return response()->json([
                'success' => true,
                'message' => 'Configuration removed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to remove config '$key': " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove configuration: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reload services after configuration changes
     */
    public function reload(Request $request)
    {
        $request->validate([
            'service' => 'nullable|string|in:asterisk,laravel,backend,api,all',
        ]);

        $service = $request->input('service', 'all');
        $results = [];

        try {
            switch ($service) {
                case 'asterisk':
                    $results['asterisk'] = $this->reloadAsterisk();
                    break;
                
                case 'laravel':
                case 'backend':
                case 'api':
                    $results['laravel'] = $this->reloadLaravel();
                    break;
                
                case 'all':
                default:
                    $results['asterisk'] = $this->reloadAsterisk();
                    $results['laravel'] = $this->reloadLaravel();
                    break;
            }

            return response()->json([
                'success' => true,
                'message' => 'Service reload completed',
                'results' => $results
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to reload services: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to reload services: ' . $e->getMessage(),
                'results' => $results
            ], 500);
        }
    }

    /**
     * Helper: Check if a key is sensitive
     */
    private function isSensitive($key)
    {
        $keyLower = strtolower($key);
        foreach ($this->sensitiveKeys as $pattern) {
            if (str_contains($keyLower, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Helper: Get environment variable value from .env file
     */
    private function getEnvValue($key)
    {
        if (!File::exists($this->envPath)) {
            return null;
        }

        $content = File::get($this->envPath);
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, $key . '=')) {
                $value = substr($line, strlen($key) + 1);
                return trim($value, '"\'');
            }
        }

        return null;
    }

    /**
     * Helper: Set environment variable value in .env file
     */
    private function setEnvValue($key, $value)
    {
        $content = File::get($this->envPath);
        $lines = explode("\n", $content);
        $newLines = [];
        $updated = false;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if (str_starts_with($trimmedLine, $key . '=')) {
                $newLines[] = "{$key}={$value}";
                $updated = true;
            } else {
                $newLines[] = $line;
            }
        }

        if (!$updated) {
            $newLines[] = "{$key}={$value}";
        }

        File::put($this->envPath, implode("\n", $newLines));
    }

    /**
     * Helper: Backup .env file
     */
    private function backupEnvFile()
    {
        $backupPath = $this->envPath . '.backup.' . date('YmdHis');
        File::copy($this->envPath, $backupPath);
        Log::info("Config backup created: $backupPath");
    }

    /**
     * Helper: Reload Asterisk
     */
    private function reloadAsterisk()
    {
        try {
            // Check if Asterisk is available
            $output = [];
            $returnCode = 0;
            exec('which asterisk', $output, $returnCode);
            
            if ($returnCode !== 0) {
                return [
                    'success' => false,
                    'message' => 'Asterisk not found'
                ];
            }

            // Reload Asterisk
            exec('asterisk -rx "core reload" 2>&1', $output, $returnCode);
            
            return [
                'success' => $returnCode === 0,
                'message' => $returnCode === 0 ? 'Asterisk reloaded' : 'Failed to reload Asterisk',
                'output' => implode("\n", $output)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error reloading Asterisk: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Helper: Reload Laravel configuration
     */
    private function reloadLaravel()
    {
        try {
            // Clear config cache
            Artisan::call('config:clear');
            
            // Clear application cache
            Artisan::call('cache:clear');

            return [
                'success' => true,
                'message' => 'Laravel configuration and cache cleared'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error reloading Laravel: ' . $e->getMessage()
            ];
        }
    }
}
