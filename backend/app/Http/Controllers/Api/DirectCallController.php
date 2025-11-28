<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DirectCallService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Direct Call Controller
 *
 * Provides API endpoints for making direct SIP calls and managing
 * the Asterisk console as a softphone/intercom.
 */
class DirectCallController extends Controller
{
    private DirectCallService $callService;

    public function __construct(DirectCallService $callService)
    {
        $this->callService = $callService;
    }

    /**
     * Originate a direct SIP call
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function originate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'destination' => 'required|string|max:255',
            'mode' => 'nullable|string|in:audio_file,console',
            'audio_file' => 'nullable|string|max:500',
            'caller_id' => 'nullable|string|max:100',
            'timeout' => 'nullable|integer|min:5|max:120',
        ]);

        $result = $this->callService->originateCall(
            $validated['destination'],
            $validated['mode'] ?? DirectCallService::MODE_CONSOLE,
            $validated['audio_file'] ?? null,
            $validated['caller_id'] ?? 'RayanPBX',
            $validated['timeout'] ?? 30
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Dial an extension from the console (use host as softphone)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function dialFromConsole(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'extension' => 'required|string|max:50',
            'timeout' => 'nullable|integer|min:5|max:120',
        ]);

        $result = $this->callService->dialFromConsole(
            $validated['extension'],
            $validated['timeout'] ?? 30
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Answer an incoming call on the console
     *
     * @return JsonResponse
     */
    public function answerConsole(): JsonResponse
    {
        $result = $this->callService->answerOnConsole();

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Hangup the current console call
     *
     * @return JsonResponse
     */
    public function hangupConsole(): JsonResponse
    {
        $result = $this->callService->hangupConsole();

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Get the status of a specific call
     *
     * @param string $callId
     * @return JsonResponse
     */
    public function getCallStatus(string $callId): JsonResponse
    {
        $result = $this->callService->getCallStatus($callId);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * Get the current console channel status
     *
     * @return JsonResponse
     */
    public function getConsoleStatus(): JsonResponse
    {
        $result = $this->callService->getConsoleStatus();

        return response()->json($result);
    }

    /**
     * Configure the console as a SIP endpoint
     *
     * @return JsonResponse
     */
    public function configureConsole(): JsonResponse
    {
        $result = $this->callService->configureConsoleEndpoint();

        return response()->json($result, $result['success'] ? 200 : 500);
    }

    /**
     * Get the dialplan configuration for console extension
     *
     * @return JsonResponse
     */
    public function getConsoleDialplan(): JsonResponse
    {
        $config = $this->callService->getConsoleDialplanConfig();

        return response()->json([
            'success' => true,
            'dialplan' => $config,
            'extension' => DirectCallService::CONSOLE_EXTENSION,
            'channel' => DirectCallService::CONSOLE_CHANNEL,
        ]);
    }

    /**
     * List all active calls
     *
     * @return JsonResponse
     */
    public function listCalls(): JsonResponse
    {
        $result = $this->callService->listActiveCalls();

        return response()->json($result);
    }

    /**
     * Hangup a specific call by channel
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function hangup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => 'required|string|max:255',
        ]);

        $result = $this->callService->hangupCall($validated['channel']);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Send DTMF tones during a call
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendDTMF(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => 'required|string|max:255',
            'digits' => 'required|string|max:50|regex:/^[0-9*#A-D]+$/',
        ]);

        $result = $this->callService->sendDTMF(
            $validated['channel'],
            $validated['digits']
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Make a test call to verify audio is working
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function testCall(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'destination' => 'required|string|max:255',
        ]);

        $result = $this->callService->testCall($validated['destination']);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Call a phone directly by SIP address (for phones page context menu)
     *
     * This is a convenience endpoint that wraps originate with sensible defaults
     * for calling VoIP phones directly.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function callPhone(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ip' => 'required|ip',
            'extension' => 'nullable|string|max:50',
            'mode' => 'nullable|string|in:audio_file,console',
            'audio_file' => 'nullable|string|max:500',
        ]);

        // Build destination - either use extension@ip or direct IP
        $destination = $validated['ip'];
        if (!empty($validated['extension'])) {
            $destination = $validated['extension'] . '@' . $validated['ip'];
        }

        $result = $this->callService->originateCall(
            $destination,
            $validated['mode'] ?? DirectCallService::MODE_CONSOLE,
            $validated['audio_file'] ?? null,
            'RayanPBX',
            30
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
