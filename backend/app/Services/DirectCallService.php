<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Direct Call Service
 *
 * Provides functionality for making direct SIP calls from the PBX system.
 * Supports:
 * - Playing audio files to called parties
 * - Using host machine's console (microphone/speaker) for live calls
 * - Monitoring call status in real-time
 * - Console as a SIP endpoint for receiving calls
 */
class DirectCallService
{
    protected AsteriskConsoleService $consoleService;
    protected SystemctlService $systemctlService;

    // Call states for monitoring
    public const STATE_IDLE = 'idle';
    public const STATE_DIALING = 'dialing';
    public const STATE_RINGING = 'ringing';
    public const STATE_ANSWERED = 'answered';
    public const STATE_CONNECTED = 'connected';
    public const STATE_HANGUP = 'hangup';
    public const STATE_FAILED = 'failed';
    public const STATE_BUSY = 'busy';
    public const STATE_NO_ANSWER = 'no_answer';

    // Call modes
    public const MODE_AUDIO_FILE = 'audio_file';
    public const MODE_CONSOLE = 'console';

    // Console extension configuration
    public const CONSOLE_EXTENSION = '9999';
    public const CONSOLE_CHANNEL = 'Console/dsp';

    public function __construct(
        AsteriskConsoleService $consoleService,
        SystemctlService $systemctlService
    ) {
        $this->consoleService = $consoleService;
        $this->systemctlService = $systemctlService;
    }

    /**
     * Make a direct SIP call to a phone address
     *
     * @param string $destination SIP URI or extension to call
     * @param string $mode Call mode (audio_file or console)
     * @param string|null $audioFile Path to audio file for playback (when mode is audio_file)
     * @param string $callerId Caller ID to display
     * @param int $timeout Dial timeout in seconds
     * @return array Call result with call ID for tracking
     */
    public function originateCall(
        string $destination,
        string $mode = self::MODE_CONSOLE,
        ?string $audioFile = null,
        string $callerId = 'RayanPBX',
        int $timeout = 30
    ): array {
        // Generate unique call ID
        $callId = 'call_' . uniqid() . '_' . time();

        // Validate mode
        if ($mode === self::MODE_AUDIO_FILE && empty($audioFile)) {
            return [
                'success' => false,
                'error' => 'Audio file path is required for audio_file mode',
                'call_id' => $callId,
            ];
        }

        // Validate audio file exists if specified
        if ($mode === self::MODE_AUDIO_FILE && !file_exists($audioFile)) {
            return [
                'success' => false,
                'error' => 'Audio file not found: ' . $audioFile,
                'call_id' => $callId,
            ];
        }

        // Initialize call status
        $this->setCallStatus($callId, [
            'state' => self::STATE_DIALING,
            'destination' => $destination,
            'mode' => $mode,
            'audio_file' => $audioFile,
            'caller_id' => $callerId,
            'started_at' => now()->toIso8601String(),
            'channel' => null,
        ]);

        try {
            $result = $this->executeOriginate($callId, $destination, $mode, $audioFile, $callerId, $timeout);

            if ($result['success']) {
                $this->updateCallStatus($callId, [
                    'state' => self::STATE_RINGING,
                    'channel' => $result['channel'] ?? null,
                ]);
            } else {
                $this->updateCallStatus($callId, [
                    'state' => self::STATE_FAILED,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }

            return array_merge($result, ['call_id' => $callId]);
        } catch (\Exception $e) {
            Log::error('Failed to originate call', [
                'call_id' => $callId,
                'destination' => $destination,
                'error' => $e->getMessage(),
            ]);

            $this->updateCallStatus($callId, [
                'state' => self::STATE_FAILED,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'call_id' => $callId,
            ];
        }
    }

    /**
     * Execute the Asterisk originate command
     */
    protected function executeOriginate(
        string $callId,
        string $destination,
        string $mode,
        ?string $audioFile,
        string $callerId,
        int $timeout
    ): array {
        // Build the channel based on destination type
        $channel = $this->buildChannelString($destination);

        // Build the application based on mode
        if ($mode === self::MODE_AUDIO_FILE) {
            // Play audio file and hangup
            $audioPath = $this->normalizeAudioPath($audioFile);
            $application = "Playback({$audioPath})";

            // Use channel originate with application
            $command = sprintf(
                'channel originate %s application %s',
                escapeshellarg($channel),
                $application
            );
        } else {
            // Console mode - bridge with console channel
            // First, ensure console channel is properly configured
            $consoleChannel = self::CONSOLE_CHANNEL;

            // Use Dial application to connect the two channels
            $command = sprintf(
                'channel originate %s application Dial(%s,%d)',
                escapeshellarg($channel),
                $consoleChannel,
                $timeout
            );
        }

        // Set caller ID
        if ($callerId) {
            // Add caller ID as variable
            $command .= sprintf(' callerid="%s"', addslashes($callerId));
        }

        Log::info('Executing originate command', [
            'call_id' => $callId,
            'command' => $command,
        ]);

        $result = $this->consoleService->executeCommand($command);

        if ($result['success']) {
            return [
                'success' => true,
                'message' => 'Call initiated successfully',
                'channel' => $channel,
                'output' => $result['output'] ?? '',
            ];
        }

        return [
            'success' => false,
            'error' => $result['error'] ?? 'Failed to originate call',
            'output' => $result['output'] ?? '',
        ];
    }

    /**
     * Build channel string from destination
     */
    protected function buildChannelString(string $destination): string
    {
        // If it's already a full channel string, use it
        if (preg_match('/^(PJSIP|SIP|IAX2|Console)\//i', $destination)) {
            return $destination;
        }

        // If it's a SIP URI (sip:user@host), convert to PJSIP channel
        if (preg_match('/^sip:(.+)@(.+)$/i', $destination, $matches)) {
            return sprintf('PJSIP/%s@%s', $matches[1], $matches[2]);
        }

        // If it looks like an extension number, use PJSIP
        if (preg_match('/^\d+$/', $destination)) {
            return 'PJSIP/' . $destination;
        }

        // If it's an IP address with optional port, treat as direct SIP call
        if (preg_match('/^[\d.]+(?::\d+)?$/', $destination)) {
            return 'PJSIP/' . self::CONSOLE_EXTENSION . '@' . $destination;
        }

        // Default: treat as PJSIP endpoint
        return 'PJSIP/' . $destination;
    }

    /**
     * Normalize audio file path for Asterisk
     *
     * Asterisk expects audio files without extension in most cases
     */
    protected function normalizeAudioPath(string $audioFile): string
    {
        // Remove common audio extensions (Asterisk will auto-detect format)
        $extensions = ['.wav', '.gsm', '.ulaw', '.alaw', '.sln', '.g722', '.siren7', '.siren14'];
        foreach ($extensions as $ext) {
            if (str_ends_with(strtolower($audioFile), $ext)) {
                return substr($audioFile, 0, -strlen($ext));
            }
        }

        return $audioFile;
    }

    /**
     * Dial an extension from the console
     *
     * This allows the host machine to act as a softphone/intercom
     */
    public function dialFromConsole(
        string $extension,
        int $timeout = 30
    ): array {
        $callId = 'console_' . uniqid() . '_' . time();

        $this->setCallStatus($callId, [
            'state' => self::STATE_DIALING,
            'destination' => $extension,
            'mode' => self::MODE_CONSOLE,
            'direction' => 'outbound',
            'started_at' => now()->toIso8601String(),
        ]);

        try {
            // Originate from console to the extension
            $channel = 'PJSIP/' . $extension;
            $command = sprintf(
                'channel originate %s application Dial(%s,%d)',
                self::CONSOLE_CHANNEL,
                $channel,
                $timeout
            );

            $result = $this->consoleService->executeCommand($command);

            if ($result['success']) {
                $this->updateCallStatus($callId, [
                    'state' => self::STATE_RINGING,
                    'channel' => self::CONSOLE_CHANNEL,
                ]);

                return [
                    'success' => true,
                    'call_id' => $callId,
                    'message' => 'Dialing ' . $extension . ' from console...',
                ];
            }

            $this->updateCallStatus($callId, [
                'state' => self::STATE_FAILED,
                'error' => $result['error'] ?? 'Failed to dial',
            ]);

            return [
                'success' => false,
                'call_id' => $callId,
                'error' => $result['error'] ?? 'Failed to originate call from console',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to dial from console', [
                'call_id' => $callId,
                'extension' => $extension,
                'error' => $e->getMessage(),
            ]);

            $this->updateCallStatus($callId, [
                'state' => self::STATE_FAILED,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'call_id' => $callId,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Answer an incoming call on the console
     */
    public function answerOnConsole(): array
    {
        // Check for ringing console channels
        $result = $this->consoleService->executeCommand('core show channels');

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => 'Failed to check channels',
            ];
        }

        // Look for Console channel in ringing state
        if (preg_match('/Console\/dsp.+Ring/', $result['output'])) {
            // Answer the call by executing console answer
            $answerResult = $this->consoleService->executeCommand('console answer');

            return [
                'success' => $answerResult['success'],
                'message' => $answerResult['success'] ? 'Call answered on console' : 'Failed to answer call',
                'error' => $answerResult['error'] ?? null,
            ];
        }

        return [
            'success' => false,
            'error' => 'No incoming call to answer on console',
        ];
    }

    /**
     * Hangup the current console call
     */
    public function hangupConsole(): array
    {
        $result = $this->consoleService->executeCommand('console hangup');

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Console call hung up' : 'Failed to hangup',
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * Get current call status
     */
    public function getCallStatus(string $callId): array
    {
        $status = Cache::get('call_status_' . $callId);

        if (!$status) {
            return [
                'success' => false,
                'error' => 'Call not found',
                'call_id' => $callId,
            ];
        }

        // Update status from Asterisk if call is active
        if (in_array($status['state'], [self::STATE_DIALING, self::STATE_RINGING, self::STATE_CONNECTED])) {
            $status = $this->refreshCallStatus($callId, $status);
        }

        return [
            'success' => true,
            'call_id' => $callId,
            'status' => $status,
        ];
    }

    /**
     * Refresh call status from Asterisk
     */
    protected function refreshCallStatus(string $callId, array $currentStatus): array
    {
        $channel = $currentStatus['channel'] ?? null;

        if (!$channel) {
            return $currentStatus;
        }

        // Check channel status
        $result = $this->consoleService->executeCommand('core show channels');

        if ($result['success']) {
            // Check if channel is still active
            if (strpos($result['output'], $channel) === false) {
                // Channel is gone - call ended
                $currentStatus['state'] = self::STATE_HANGUP;
                $currentStatus['ended_at'] = now()->toIso8601String();
                $this->setCallStatus($callId, $currentStatus);
            } elseif (preg_match("/{$channel}.+Up/", $result['output'])) {
                // Call is connected
                if ($currentStatus['state'] !== self::STATE_CONNECTED) {
                    $currentStatus['state'] = self::STATE_CONNECTED;
                    $currentStatus['answered_at'] = now()->toIso8601String();
                    $this->setCallStatus($callId, $currentStatus);
                }
            }
        }

        return $currentStatus;
    }

    /**
     * Get console channel status
     */
    public function getConsoleStatus(): array
    {
        // Check if console channel is in use
        $result = $this->consoleService->executeCommand('core show channels');

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => 'Failed to get channel status',
            ];
        }

        $status = [
            'success' => true,
            'state' => self::STATE_IDLE,
            'channel' => self::CONSOLE_CHANNEL,
            'call_info' => null,
        ];

        // Parse console channel state
        if (preg_match('/Console\/dsp.+?(\S+)/', $result['output'], $matches)) {
            $channelState = strtolower($matches[1]);

            switch ($channelState) {
                case 'ring':
                    $status['state'] = self::STATE_RINGING;
                    break;
                case 'up':
                    $status['state'] = self::STATE_CONNECTED;
                    break;
                case 'ringing':
                    $status['state'] = self::STATE_DIALING;
                    break;
                default:
                    $status['state'] = $channelState;
            }
        }

        return $status;
    }

    /**
     * Configure console channel for use as SIP endpoint
     *
     * This sets up the Asterisk console to work like a softphone
     */
    public function configureConsoleEndpoint(): array
    {
        $configs = [];
        $errors = [];

        // 1. Enable console channel module
        $result = $this->consoleService->executeCommand('module load chan_console.so');
        if ($result['success']) {
            $configs[] = 'Console channel module loaded';
        } else {
            // Module might already be loaded
            if (strpos($result['output'] ?? '', 'already loaded') !== false) {
                $configs[] = 'Console channel module already loaded';
            } else {
                $errors[] = 'Failed to load console module: ' . ($result['error'] ?? 'Unknown error');
            }
        }

        // 2. Configure console settings via console.conf
        // This would typically be done via config file, but we can verify it's working
        $result = $this->consoleService->executeCommand('console show devices');
        if ($result['success']) {
            $configs[] = 'Console devices available';
        } else {
            $errors[] = 'Console devices not configured';
        }

        // 3. Add console extension to dialplan if needed
        // This is typically done via extensions.conf

        return [
            'success' => empty($errors),
            'configured' => $configs,
            'errors' => $errors,
            'console_extension' => self::CONSOLE_EXTENSION,
            'console_channel' => self::CONSOLE_CHANNEL,
        ];
    }

    /**
     * Get dialplan configuration for console extension
     *
     * Returns the dialplan entries needed to route calls to the console
     */
    public function getConsoleDialplanConfig(): string
    {
        $extension = self::CONSOLE_EXTENSION;

        return <<<DIALPLAN
; Console Extension Configuration
; Add this to your extensions.conf or dialplan

[from-internal]
; Allow dialing the console extension
exten => {$extension},1,NoOp(Calling Console/DSP)
 same => n,Dial(Console/dsp,30,r)
 same => n,VoiceMail({$extension}@default,u)
 same => n,Hangup()

; Intercom mode - auto-answer
exten => *{$extension},1,NoOp(Intercom to Console)
 same => n,Set(PJSIP_HEADER(add,Alert-Info)=<http://localhost>;answer-after=0)
 same => n,Dial(Console/dsp,30,A(beep))
 same => n,Hangup()

DIALPLAN;
    }

    /**
     * Set call status in cache
     */
    protected function setCallStatus(string $callId, array $status): void
    {
        // Store for 1 hour
        Cache::put('call_status_' . $callId, $status, 3600);
    }

    /**
     * Update call status in cache
     */
    protected function updateCallStatus(string $callId, array $updates): void
    {
        $status = Cache::get('call_status_' . $callId, []);
        $status = array_merge($status, $updates);
        $this->setCallStatus($callId, $status);
    }

    /**
     * List all active calls
     */
    public function listActiveCalls(): array
    {
        $result = $this->consoleService->getActiveCalls();

        return [
            'success' => true,
            'calls' => $result,
            'count' => count($result),
        ];
    }

    /**
     * Hangup a specific call by channel
     */
    public function hangupCall(string $channel): array
    {
        $result = $this->consoleService->hangupChannel($channel);

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Call hung up' : 'Failed to hangup call',
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * Send DTMF tones during a call
     */
    public function sendDTMF(string $channel, string $digits): array
    {
        // Validate DTMF digits
        if (!preg_match('/^[0-9*#A-D]+$/', $digits)) {
            return [
                'success' => false,
                'error' => 'Invalid DTMF digits',
            ];
        }

        $command = sprintf('channel originate Local/dtmf@dtmf-context application Wait 0');
        // Actually send DTMF using AMI or CLI
        // For CLI, we'd need to use the channel request command
        $result = $this->consoleService->executeCommand("channel request dtmf {$channel} {$digits}");

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'DTMF sent' : 'Failed to send DTMF',
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * Test call to verify audio is working
     *
     * Calls the specified destination and plays a test audio file
     */
    public function testCall(string $destination): array
    {
        // Use built-in Asterisk sounds for testing
        $testAudio = '/var/lib/asterisk/sounds/en/tt-weasels';

        return $this->originateCall(
            $destination,
            self::MODE_AUDIO_FILE,
            $testAudio,
            'RayanPBX Test',
            20
        );
    }
}
