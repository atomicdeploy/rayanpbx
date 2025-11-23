<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AsteriskConsoleService;
use Illuminate\Http\Request;

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
