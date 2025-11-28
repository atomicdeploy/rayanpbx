<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AsteriskConsoleService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConsoleController extends Controller
{
    private AsteriskConsoleService $console;

    public function __construct(AsteriskConsoleService $console)
    {
        $this->console = $console;
    }

    /**
     * Execute Asterisk CLI command
     */
    public function execute(Request $request)
    {
        $validated = $request->validate([
            'command' => 'required|string|max:500',
        ]);

        $result = $this->console->executeSafeCommand($validated['command']);

        return response()->json($result);
    }

    /**
     * Get console output (logs)
     */
    public function output(Request $request)
    {
        $lines = $request->input('lines', 50);
        $lines = min($lines, 500); // Max 500 lines

        $result = $this->console->getConsoleOutput($lines);

        return response()->json($result);
    }

    /**
     * Stream live Asterisk console output (Server-Sent Events)
     * Similar to running `asterisk -rvvvvvvvvv`
     */
    public function live(Request $request): StreamedResponse
    {
        $verbosity = (int) $request->input('verbosity', 5);
        $verbosity = max(1, min(10, $verbosity)); // Clamp between 1-10

        return new StreamedResponse(function () use ($verbosity) {
            // Disable output buffering
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            
            // Set time limit to 0 (no limit) for streaming
            set_time_limit(0);
            
            $this->console->streamLiveOutput(function ($data) {
                echo "data: " . json_encode($data) . "\n\n";
                flush();
                
                // Check if connection is still alive
                if (connection_aborted()) {
                    return;
                }
            }, $verbosity);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Get recent Asterisk errors
     * Returns registration failures, authentication errors, etc.
     */
    public function errors(Request $request)
    {
        $lines = (int) $request->input('lines', 500);
        $lines = max(100, min(2000, $lines));

        $errors = $this->console->getRecentErrors($lines);

        return response()->json([
            'success' => true,
            'errors' => $errors,
            'count' => count($errors),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get available commands
     */
    public function commands()
    {
        $commands = $this->console->getAvailableCommands();

        return response()->json([
            'commands' => $commands,
        ]);
    }

    /**
     * Get Asterisk version
     */
    public function version()
    {
        $version = $this->console->getVersion();

        return response()->json([
            'version' => $version,
        ]);
    }

    /**
     * Get active calls
     */
    public function calls()
    {
        $calls = $this->console->getActiveCalls();

        return response()->json([
            'calls' => $calls,
            'count' => count($calls),
        ]);
    }

    /**
     * Get channels
     */
    public function channels()
    {
        $channels = $this->console->getChannels();

        return response()->json([
            'channels' => $channels,
            'count' => count($channels),
        ]);
    }

    /**
     * Get PJSIP endpoints
     */
    public function endpoints()
    {
        $endpoints = $this->console->getPjsipEndpoints();

        return response()->json([
            'endpoints' => $endpoints,
            'count' => count($endpoints),
        ]);
    }

    /**
     * Get PJSIP registrations
     */
    public function registrations()
    {
        $registrations = $this->console->getPjsipRegistrations();

        return response()->json([
            'registrations' => $registrations,
            'count' => count($registrations),
        ]);
    }

    /**
     * Reload Asterisk
     */
    public function reload(Request $request)
    {
        $module = $request->input('module');
        
        $result = $this->console->reload($module);

        return response()->json($result);
    }

    /**
     * Hangup channel
     */
    public function hangup(Request $request)
    {
        $validated = $request->validate([
            'channel' => 'required|string',
        ]);

        $result = $this->console->hangupChannel($validated['channel']);

        return response()->json($result);
    }

    /**
     * Originate call
     */
    public function originate(Request $request)
    {
        $validated = $request->validate([
            'channel' => 'required|string',
            'extension' => 'required|string',
            'context' => 'string',
        ]);

        $result = $this->console->originateCall(
            $validated['channel'],
            $validated['extension'],
            $validated['context'] ?? 'from-internal'
        );

        return response()->json($result);
    }

    /**
     * Show dialplan
     */
    public function dialplan(Request $request)
    {
        $context = $request->input('context');
        
        $result = $this->console->showDialplan($context);

        return response()->json($result);
    }

    /**
     * Get SIP peers
     */
    public function peers()
    {
        $peers = $this->console->getSipPeers();

        return response()->json([
            'peers' => $peers,
            'count' => count($peers),
        ]);
    }

    /**
     * Start console session
     */
    public function session()
    {
        $result = $this->console->startConsoleSession();

        return response()->json($result);
    }
}
