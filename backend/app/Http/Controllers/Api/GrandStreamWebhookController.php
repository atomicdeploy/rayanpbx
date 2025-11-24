<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * GrandStream Action URL Webhook Controller
 * 
 * Handles webhook callbacks from GrandStream phones (GXP1625/GXP1630).
 * These webhooks are sent when phone events occur.
 * 
 * Note: These endpoints are public (no authentication required) as they
 * are called directly by the phones.
 */
class GrandStreamWebhookController extends Controller
{
    /**
     * GrandStream Action URL event types
     */
    public const EVENT_SETUP_COMPLETED = 'setup_completed';
    public const EVENT_REGISTERED = 'registered';
    public const EVENT_UNREGISTERED = 'unregistered';
    public const EVENT_REGISTER_FAILED = 'register_failed';
    public const EVENT_OFF_HOOK = 'off_hook';
    public const EVENT_ON_HOOK = 'on_hook';
    public const EVENT_INCOMING_CALL = 'incoming_call';
    public const EVENT_OUTGOING_CALL = 'outgoing_call';
    public const EVENT_MISSED_CALL = 'missed_call';
    public const EVENT_ANSWERED_CALL = 'answered_call';
    public const EVENT_REJECTED_CALL = 'rejected_call';
    public const EVENT_FORWARDED_CALL = 'forwarded_call';
    public const EVENT_ESTABLISHED_CALL = 'established_call';
    public const EVENT_TERMINATED_CALL = 'terminated_call';
    public const EVENT_IDLE_TO_BUSY = 'idle_to_busy';
    public const EVENT_BUSY_TO_IDLE = 'busy_to_idle';
    public const EVENT_OPEN_DND = 'open_dnd';
    public const EVENT_CLOSE_DND = 'close_dnd';
    public const EVENT_OPEN_FORWARD = 'open_forward';
    public const EVENT_CLOSE_FORWARD = 'close_forward';
    public const EVENT_OPEN_UNCONDITIONAL_FORWARD = 'open_unconditional_forward';
    public const EVENT_CLOSE_UNCONDITIONAL_FORWARD = 'close_unconditional_forward';
    public const EVENT_OPEN_BUSY_FORWARD = 'open_busy_forward';
    public const EVENT_CLOSE_BUSY_FORWARD = 'close_busy_forward';
    public const EVENT_OPEN_NO_ANSWER_FORWARD = 'open_no_answer_forward';
    public const EVENT_CLOSE_NO_ANSWER_FORWARD = 'close_no_answer_forward';
    public const EVENT_BLIND_TRANSFER = 'blind_transfer';
    public const EVENT_ATTENDED_TRANSFER = 'attended_transfer';
    public const EVENT_TRANSFER_FINISHED = 'transfer_finished';
    public const EVENT_TRANSFER_FAILED = 'transfer_failed';
    public const EVENT_HOLD_CALL = 'hold_call';
    public const EVENT_UNHOLD_CALL = 'unhold_call';
    public const EVENT_MUTE_CALL = 'mute_call';
    public const EVENT_UNMUTE_CALL = 'unmute_call';
    public const EVENT_OPEN_SYSLOG = 'open_syslog';
    public const EVENT_CLOSE_SYSLOG = 'close_syslog';
    public const EVENT_IP_CHANGE = 'ip_change';
    public const EVENT_AUTO_PROVISION_FINISH = 'auto_provision_finish';

    /**
     * Get all supported event types
     */
    public static function getAllEventTypes(): array
    {
        return [
            self::EVENT_SETUP_COMPLETED,
            self::EVENT_REGISTERED,
            self::EVENT_UNREGISTERED,
            self::EVENT_REGISTER_FAILED,
            self::EVENT_OFF_HOOK,
            self::EVENT_ON_HOOK,
            self::EVENT_INCOMING_CALL,
            self::EVENT_OUTGOING_CALL,
            self::EVENT_MISSED_CALL,
            self::EVENT_ANSWERED_CALL,
            self::EVENT_REJECTED_CALL,
            self::EVENT_FORWARDED_CALL,
            self::EVENT_ESTABLISHED_CALL,
            self::EVENT_TERMINATED_CALL,
            self::EVENT_IDLE_TO_BUSY,
            self::EVENT_BUSY_TO_IDLE,
            self::EVENT_OPEN_DND,
            self::EVENT_CLOSE_DND,
            self::EVENT_OPEN_FORWARD,
            self::EVENT_CLOSE_FORWARD,
            self::EVENT_OPEN_UNCONDITIONAL_FORWARD,
            self::EVENT_CLOSE_UNCONDITIONAL_FORWARD,
            self::EVENT_OPEN_BUSY_FORWARD,
            self::EVENT_CLOSE_BUSY_FORWARD,
            self::EVENT_OPEN_NO_ANSWER_FORWARD,
            self::EVENT_CLOSE_NO_ANSWER_FORWARD,
            self::EVENT_BLIND_TRANSFER,
            self::EVENT_ATTENDED_TRANSFER,
            self::EVENT_TRANSFER_FINISHED,
            self::EVENT_TRANSFER_FAILED,
            self::EVENT_HOLD_CALL,
            self::EVENT_UNHOLD_CALL,
            self::EVENT_MUTE_CALL,
            self::EVENT_UNMUTE_CALL,
            self::EVENT_OPEN_SYSLOG,
            self::EVENT_CLOSE_SYSLOG,
            self::EVENT_IP_CHANGE,
            self::EVENT_AUTO_PROVISION_FINISH,
        ];
    }

    /**
     * Handle generic Action URL webhook
     * 
     * This endpoint handles all Action URL events from GrandStream phones.
     * The event type is passed as a URL parameter.
     */
    public function handleEvent(Request $request, string $event)
    {
        $eventTypes = self::getAllEventTypes();
        
        if (!in_array($event, $eventTypes)) {
            Log::warning('GrandStream webhook: Unknown event type', [
                'event' => $event,
                'ip' => $request->ip(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Unknown event type',
            ], 400);
        }

        $data = $this->extractEventData($request);
        
        Log::info('GrandStream webhook event', [
            'event' => $event,
            'mac' => $data['mac'] ?? 'unknown',
            'ip' => $data['ip'] ?? $request->ip(),
            'data' => $data,
        ]);

        // Process the event based on type
        $this->processEvent($event, $data);

        return response()->json([
            'success' => true,
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Handle setup completed event
     */
    public function setupCompleted(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_SETUP_COMPLETED);
    }

    /**
     * Handle registered event
     */
    public function registered(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_REGISTERED);
    }

    /**
     * Handle unregistered event
     */
    public function unregistered(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_UNREGISTERED);
    }

    /**
     * Handle register failed event
     */
    public function registerFailed(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_REGISTER_FAILED);
    }

    /**
     * Handle off hook event
     */
    public function offHook(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_OFF_HOOK);
    }

    /**
     * Handle on hook event
     */
    public function onHook(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_ON_HOOK);
    }

    /**
     * Handle incoming call event
     */
    public function incomingCall(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_INCOMING_CALL);
    }

    /**
     * Handle outgoing call event
     */
    public function outgoingCall(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_OUTGOING_CALL);
    }

    /**
     * Handle missed call event
     */
    public function missedCall(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_MISSED_CALL);
    }

    /**
     * Handle answered call event
     */
    public function answeredCall(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_ANSWERED_CALL);
    }

    /**
     * Handle rejected call event
     */
    public function rejectedCall(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_REJECTED_CALL);
    }

    /**
     * Handle forwarded call event
     */
    public function forwardedCall(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_FORWARDED_CALL);
    }

    /**
     * Handle established call event
     */
    public function establishedCall(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_ESTABLISHED_CALL);
    }

    /**
     * Handle terminated call event
     */
    public function terminatedCall(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_TERMINATED_CALL);
    }

    /**
     * Handle idle to busy event
     */
    public function idleToBusy(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_IDLE_TO_BUSY);
    }

    /**
     * Handle busy to idle event
     */
    public function busyToIdle(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_BUSY_TO_IDLE);
    }

    /**
     * Handle open DND event
     */
    public function openDnd(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_OPEN_DND);
    }

    /**
     * Handle close DND event
     */
    public function closeDnd(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_CLOSE_DND);
    }

    /**
     * Handle open forward event
     */
    public function openForward(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_OPEN_FORWARD);
    }

    /**
     * Handle close forward event
     */
    public function closeForward(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_CLOSE_FORWARD);
    }

    /**
     * Handle open unconditional forward event
     */
    public function openUnconditionalForward(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_OPEN_UNCONDITIONAL_FORWARD);
    }

    /**
     * Handle close unconditional forward event
     */
    public function closeUnconditionalForward(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_CLOSE_UNCONDITIONAL_FORWARD);
    }

    /**
     * Handle open busy forward event
     */
    public function openBusyForward(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_OPEN_BUSY_FORWARD);
    }

    /**
     * Handle close busy forward event
     */
    public function closeBusyForward(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_CLOSE_BUSY_FORWARD);
    }

    /**
     * Handle open no answer forward event
     */
    public function openNoAnswerForward(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_OPEN_NO_ANSWER_FORWARD);
    }

    /**
     * Handle close no answer forward event
     */
    public function closeNoAnswerForward(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_CLOSE_NO_ANSWER_FORWARD);
    }

    /**
     * Handle blind transfer event
     */
    public function blindTransfer(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_BLIND_TRANSFER);
    }

    /**
     * Handle attended transfer event
     */
    public function attendedTransfer(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_ATTENDED_TRANSFER);
    }

    /**
     * Handle transfer finished event
     */
    public function transferFinished(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_TRANSFER_FINISHED);
    }

    /**
     * Handle transfer failed event
     */
    public function transferFailed(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_TRANSFER_FAILED);
    }

    /**
     * Handle hold call event
     */
    public function holdCall(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_HOLD_CALL);
    }

    /**
     * Handle unhold call event
     */
    public function unholdCall(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_UNHOLD_CALL);
    }

    /**
     * Handle mute call event
     */
    public function muteCall(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_MUTE_CALL);
    }

    /**
     * Handle unmute call event
     */
    public function unmuteCall(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_UNMUTE_CALL);
    }

    /**
     * Handle open syslog event
     */
    public function openSyslog(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_OPEN_SYSLOG);
    }

    /**
     * Handle close syslog event
     */
    public function closeSyslog(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_CLOSE_SYSLOG);
    }

    /**
     * Handle IP change event
     */
    public function ipChange(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_IP_CHANGE);
    }

    /**
     * Handle auto-provision finish event
     */
    public function autoProvisionFinish(Request $request)
    {
        return $this->handleEvent($request, self::EVENT_AUTO_PROVISION_FINISH);
    }

    /**
     * Get Action URL configuration for phones
     * Returns the URLs that should be configured on GrandStream phones
     */
    public function getActionUrls(Request $request)
    {
        $baseUrl = config('rayanpbx.webhook_base_url', url('/api/grandstream/webhook'));

        $actionUrls = [
            'setup_completed' => "{$baseUrl}/setup-completed",
            'registered' => "{$baseUrl}/registered",
            'unregistered' => "{$baseUrl}/unregistered",
            'register_failed' => "{$baseUrl}/register-failed",
            'off_hook' => "{$baseUrl}/off-hook",
            'on_hook' => "{$baseUrl}/on-hook",
            'incoming_call' => "{$baseUrl}/incoming-call",
            'outgoing_call' => "{$baseUrl}/outgoing-call",
            'missed_call' => "{$baseUrl}/missed-call",
            'answered_call' => "{$baseUrl}/answered-call",
            'rejected_call' => "{$baseUrl}/rejected-call",
            'forwarded_call' => "{$baseUrl}/forwarded-call",
            'established_call' => "{$baseUrl}/established-call",
            'terminated_call' => "{$baseUrl}/terminated-call",
            'idle_to_busy' => "{$baseUrl}/idle-to-busy",
            'busy_to_idle' => "{$baseUrl}/busy-to-idle",
            'open_dnd' => "{$baseUrl}/open-dnd",
            'close_dnd' => "{$baseUrl}/close-dnd",
            'open_forward' => "{$baseUrl}/open-forward",
            'close_forward' => "{$baseUrl}/close-forward",
            'open_unconditional_forward' => "{$baseUrl}/open-unconditional-forward",
            'close_unconditional_forward' => "{$baseUrl}/close-unconditional-forward",
            'open_busy_forward' => "{$baseUrl}/open-busy-forward",
            'close_busy_forward' => "{$baseUrl}/close-busy-forward",
            'open_no_answer_forward' => "{$baseUrl}/open-no-answer-forward",
            'close_no_answer_forward' => "{$baseUrl}/close-no-answer-forward",
            'blind_transfer' => "{$baseUrl}/blind-transfer",
            'attended_transfer' => "{$baseUrl}/attended-transfer",
            'transfer_finished' => "{$baseUrl}/transfer-finished",
            'transfer_failed' => "{$baseUrl}/transfer-failed",
            'hold_call' => "{$baseUrl}/hold-call",
            'unhold_call' => "{$baseUrl}/unhold-call",
            'mute_call' => "{$baseUrl}/mute-call",
            'unmute_call' => "{$baseUrl}/unmute-call",
            'open_syslog' => "{$baseUrl}/open-syslog",
            'close_syslog' => "{$baseUrl}/close-syslog",
            'ip_change' => "{$baseUrl}/ip-change",
            'auto_provision_finish' => "{$baseUrl}/auto-provision-finish",
        ];

        return response()->json([
            'success' => true,
            'base_url' => $baseUrl,
            'action_urls' => $actionUrls,
            'supported_events' => self::getAllEventTypes(),
        ]);
    }

    /**
     * Extract event data from request
     * GrandStream phones send data via query parameters or POST body
     */
    protected function extractEventData(Request $request): array
    {
        $data = array_merge($request->query(), $request->all());

        // Common GrandStream variables
        return [
            'mac' => $data['mac'] ?? $request->header('X-GS-MAC'),
            'ip' => $data['ip'] ?? $request->ip(),
            'model' => $data['model'] ?? null,
            'firmware' => $data['fw'] ?? $data['firmware'] ?? null,
            'account' => $data['account'] ?? $data['acc'] ?? null,
            'local_number' => $data['local'] ?? $data['local_number'] ?? null,
            'remote_number' => $data['remote'] ?? $data['remote_number'] ?? null,
            'call_id' => $data['call_id'] ?? $data['callid'] ?? null,
            'duration' => $data['duration'] ?? null,
            'timestamp' => $data['timestamp'] ?? now()->toIso8601String(),
            'raw_data' => $data,
        ];
    }

    /**
     * Process event based on type
     */
    protected function processEvent(string $event, array $data): void
    {
        // Log all events for debugging and analytics
        Log::info("GrandStream phone event: {$event}", $data);

        // Specific event handling
        switch ($event) {
            case self::EVENT_REGISTERED:
                $this->handleRegisteredEvent($data);
                break;
            case self::EVENT_UNREGISTERED:
            case self::EVENT_REGISTER_FAILED:
                $this->handleRegistrationIssue($event, $data);
                break;
            case self::EVENT_INCOMING_CALL:
            case self::EVENT_OUTGOING_CALL:
            case self::EVENT_MISSED_CALL:
            case self::EVENT_ANSWERED_CALL:
                $this->handleCallEvent($event, $data);
                break;
            case self::EVENT_IP_CHANGE:
                $this->handleIpChangeEvent($data);
                break;
            case self::EVENT_AUTO_PROVISION_FINISH:
                $this->handleProvisionFinishEvent($data);
                break;
        }
    }

    /**
     * Handle phone registration event
     */
    protected function handleRegisteredEvent(array $data): void
    {
        Log::info('Phone registered', [
            'mac' => $data['mac'],
            'ip' => $data['ip'],
            'account' => $data['account'],
        ]);
    }

    /**
     * Handle registration issues
     */
    protected function handleRegistrationIssue(string $event, array $data): void
    {
        Log::warning('Phone registration issue', [
            'event' => $event,
            'mac' => $data['mac'],
            'ip' => $data['ip'],
            'account' => $data['account'],
        ]);
    }

    /**
     * Handle call events
     */
    protected function handleCallEvent(string $event, array $data): void
    {
        Log::info('Phone call event', [
            'event' => $event,
            'mac' => $data['mac'],
            'local' => $data['local_number'],
            'remote' => $data['remote_number'],
            'call_id' => $data['call_id'],
        ]);
    }

    /**
     * Handle IP change event
     */
    protected function handleIpChangeEvent(array $data): void
    {
        Log::info('Phone IP changed', [
            'mac' => $data['mac'],
            'new_ip' => $data['ip'],
        ]);
    }

    /**
     * Handle auto-provision finish event
     */
    protected function handleProvisionFinishEvent(array $data): void
    {
        Log::info('Phone provisioning completed', [
            'mac' => $data['mac'],
            'ip' => $data['ip'],
        ]);
    }
}
