<?php

namespace App\Http\Controllers\Api;

use App\Helpers\GrandStreamActionUrls;
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
    // Event type constants delegated to GrandStreamActionUrls helper
    public const EVENT_SETUP_COMPLETED = GrandStreamActionUrls::EVENT_SETUP_COMPLETED;
    public const EVENT_REGISTERED = GrandStreamActionUrls::EVENT_REGISTERED;
    public const EVENT_UNREGISTERED = GrandStreamActionUrls::EVENT_UNREGISTERED;
    public const EVENT_REGISTER_FAILED = GrandStreamActionUrls::EVENT_REGISTER_FAILED;
    public const EVENT_OFF_HOOK = GrandStreamActionUrls::EVENT_OFF_HOOK;
    public const EVENT_ON_HOOK = GrandStreamActionUrls::EVENT_ON_HOOK;
    public const EVENT_INCOMING_CALL = GrandStreamActionUrls::EVENT_INCOMING_CALL;
    public const EVENT_OUTGOING_CALL = GrandStreamActionUrls::EVENT_OUTGOING_CALL;
    public const EVENT_MISSED_CALL = GrandStreamActionUrls::EVENT_MISSED_CALL;
    public const EVENT_ANSWERED_CALL = GrandStreamActionUrls::EVENT_ANSWERED_CALL;
    public const EVENT_REJECTED_CALL = GrandStreamActionUrls::EVENT_REJECTED_CALL;
    public const EVENT_FORWARDED_CALL = GrandStreamActionUrls::EVENT_FORWARDED_CALL;
    public const EVENT_ESTABLISHED_CALL = GrandStreamActionUrls::EVENT_ESTABLISHED_CALL;
    public const EVENT_TERMINATED_CALL = GrandStreamActionUrls::EVENT_TERMINATED_CALL;
    public const EVENT_IDLE_TO_BUSY = GrandStreamActionUrls::EVENT_IDLE_TO_BUSY;
    public const EVENT_BUSY_TO_IDLE = GrandStreamActionUrls::EVENT_BUSY_TO_IDLE;
    public const EVENT_OPEN_DND = GrandStreamActionUrls::EVENT_OPEN_DND;
    public const EVENT_CLOSE_DND = GrandStreamActionUrls::EVENT_CLOSE_DND;
    public const EVENT_OPEN_FORWARD = GrandStreamActionUrls::EVENT_OPEN_FORWARD;
    public const EVENT_CLOSE_FORWARD = GrandStreamActionUrls::EVENT_CLOSE_FORWARD;
    public const EVENT_OPEN_UNCONDITIONAL_FORWARD = GrandStreamActionUrls::EVENT_OPEN_UNCONDITIONAL_FORWARD;
    public const EVENT_CLOSE_UNCONDITIONAL_FORWARD = GrandStreamActionUrls::EVENT_CLOSE_UNCONDITIONAL_FORWARD;
    public const EVENT_OPEN_BUSY_FORWARD = GrandStreamActionUrls::EVENT_OPEN_BUSY_FORWARD;
    public const EVENT_CLOSE_BUSY_FORWARD = GrandStreamActionUrls::EVENT_CLOSE_BUSY_FORWARD;
    public const EVENT_OPEN_NO_ANSWER_FORWARD = GrandStreamActionUrls::EVENT_OPEN_NO_ANSWER_FORWARD;
    public const EVENT_CLOSE_NO_ANSWER_FORWARD = GrandStreamActionUrls::EVENT_CLOSE_NO_ANSWER_FORWARD;
    public const EVENT_BLIND_TRANSFER = GrandStreamActionUrls::EVENT_BLIND_TRANSFER;
    public const EVENT_ATTENDED_TRANSFER = GrandStreamActionUrls::EVENT_ATTENDED_TRANSFER;
    public const EVENT_TRANSFER_FINISHED = GrandStreamActionUrls::EVENT_TRANSFER_FINISHED;
    public const EVENT_TRANSFER_FAILED = GrandStreamActionUrls::EVENT_TRANSFER_FAILED;
    public const EVENT_HOLD_CALL = GrandStreamActionUrls::EVENT_HOLD_CALL;
    public const EVENT_UNHOLD_CALL = GrandStreamActionUrls::EVENT_UNHOLD_CALL;
    public const EVENT_MUTE_CALL = GrandStreamActionUrls::EVENT_MUTE_CALL;
    public const EVENT_UNMUTE_CALL = GrandStreamActionUrls::EVENT_UNMUTE_CALL;
    public const EVENT_OPEN_SYSLOG = GrandStreamActionUrls::EVENT_OPEN_SYSLOG;
    public const EVENT_CLOSE_SYSLOG = GrandStreamActionUrls::EVENT_CLOSE_SYSLOG;
    public const EVENT_IP_CHANGE = GrandStreamActionUrls::EVENT_IP_CHANGE;
    public const EVENT_AUTO_PROVISION_FINISH = GrandStreamActionUrls::EVENT_AUTO_PROVISION_FINISH;

    /**
     * Get all supported event types
     */
    public static function getAllEventTypes(): array
    {
        return GrandStreamActionUrls::getAllEventTypes();
    }

    /**
     * Handle generic Action URL webhook
     * 
     * This endpoint handles all Action URL events from GrandStream phones.
     * The event type is passed as a URL parameter.
     */
    public function handleEvent(Request $request, string $event)
    {
        if (!GrandStreamActionUrls::isValidEventType($event)) {
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
        $config = GrandStreamActionUrls::getActionUrlConfig();

        return response()->json([
            'success' => true,
            'base_url' => $config['base_url'],
            'action_urls' => $config['action_urls'],
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
