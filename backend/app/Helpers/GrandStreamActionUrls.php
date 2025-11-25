<?php

namespace App\Helpers;

/**
 * GrandStream Action URL Configuration Helper
 * 
 * Centralizes all Action URL event types, P-value parameters, and URL generation
 * for GrandStream phones (GXP1625/GXP1630).
 */
class GrandStreamActionUrls
{
    /**
     * Action URL event types
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
     * Mapping of event types to URL slugs
     */
    protected static array $eventSlugMap = [
        self::EVENT_SETUP_COMPLETED => 'setup-completed',
        self::EVENT_REGISTERED => 'registered',
        self::EVENT_UNREGISTERED => 'unregistered',
        self::EVENT_REGISTER_FAILED => 'register-failed',
        self::EVENT_OFF_HOOK => 'off-hook',
        self::EVENT_ON_HOOK => 'on-hook',
        self::EVENT_INCOMING_CALL => 'incoming-call',
        self::EVENT_OUTGOING_CALL => 'outgoing-call',
        self::EVENT_MISSED_CALL => 'missed-call',
        self::EVENT_ANSWERED_CALL => 'answered-call',
        self::EVENT_REJECTED_CALL => 'rejected-call',
        self::EVENT_FORWARDED_CALL => 'forwarded-call',
        self::EVENT_ESTABLISHED_CALL => 'established-call',
        self::EVENT_TERMINATED_CALL => 'terminated-call',
        self::EVENT_IDLE_TO_BUSY => 'idle-to-busy',
        self::EVENT_BUSY_TO_IDLE => 'busy-to-idle',
        self::EVENT_OPEN_DND => 'open-dnd',
        self::EVENT_CLOSE_DND => 'close-dnd',
        self::EVENT_OPEN_FORWARD => 'open-forward',
        self::EVENT_CLOSE_FORWARD => 'close-forward',
        self::EVENT_OPEN_UNCONDITIONAL_FORWARD => 'open-unconditional-forward',
        self::EVENT_CLOSE_UNCONDITIONAL_FORWARD => 'close-unconditional-forward',
        self::EVENT_OPEN_BUSY_FORWARD => 'open-busy-forward',
        self::EVENT_CLOSE_BUSY_FORWARD => 'close-busy-forward',
        self::EVENT_OPEN_NO_ANSWER_FORWARD => 'open-no-answer-forward',
        self::EVENT_CLOSE_NO_ANSWER_FORWARD => 'close-no-answer-forward',
        self::EVENT_BLIND_TRANSFER => 'blind-transfer',
        self::EVENT_ATTENDED_TRANSFER => 'attended-transfer',
        self::EVENT_TRANSFER_FINISHED => 'transfer-finished',
        self::EVENT_TRANSFER_FAILED => 'transfer-failed',
        self::EVENT_HOLD_CALL => 'hold-call',
        self::EVENT_UNHOLD_CALL => 'unhold-call',
        self::EVENT_MUTE_CALL => 'mute-call',
        self::EVENT_UNMUTE_CALL => 'unmute-call',
        self::EVENT_OPEN_SYSLOG => 'open-syslog',
        self::EVENT_CLOSE_SYSLOG => 'close-syslog',
        self::EVENT_IP_CHANGE => 'ip-change',
        self::EVENT_AUTO_PROVISION_FINISH => 'auto-provision-finish',
    ];

    /**
     * GrandStream P-value parameters for each Action URL
     */
    protected static array $pValueMap = [
        self::EVENT_SETUP_COMPLETED => 'P1500',
        self::EVENT_REGISTERED => 'P1501',
        self::EVENT_UNREGISTERED => 'P1502',
        self::EVENT_REGISTER_FAILED => 'P1503',
        self::EVENT_OFF_HOOK => 'P1504',
        self::EVENT_ON_HOOK => 'P1505',
        self::EVENT_INCOMING_CALL => 'P1506',
        self::EVENT_OUTGOING_CALL => 'P1507',
        self::EVENT_MISSED_CALL => 'P1508',
        self::EVENT_ANSWERED_CALL => 'P1509',
        self::EVENT_REJECTED_CALL => 'P1510',
        self::EVENT_FORWARDED_CALL => 'P1511',
        self::EVENT_ESTABLISHED_CALL => 'P1512',
        self::EVENT_TERMINATED_CALL => 'P1513',
        self::EVENT_IDLE_TO_BUSY => 'P1514',
        self::EVENT_BUSY_TO_IDLE => 'P1515',
        self::EVENT_OPEN_DND => 'P1516',
        self::EVENT_CLOSE_DND => 'P1517',
        self::EVENT_OPEN_FORWARD => 'P1518',
        self::EVENT_CLOSE_FORWARD => 'P1519',
        self::EVENT_OPEN_UNCONDITIONAL_FORWARD => 'P1520',
        self::EVENT_CLOSE_UNCONDITIONAL_FORWARD => 'P1521',
        self::EVENT_OPEN_BUSY_FORWARD => 'P1522',
        self::EVENT_CLOSE_BUSY_FORWARD => 'P1523',
        self::EVENT_OPEN_NO_ANSWER_FORWARD => 'P1524',
        self::EVENT_CLOSE_NO_ANSWER_FORWARD => 'P1525',
        self::EVENT_BLIND_TRANSFER => 'P1526',
        self::EVENT_ATTENDED_TRANSFER => 'P1527',
        self::EVENT_TRANSFER_FINISHED => 'P1528',
        self::EVENT_TRANSFER_FAILED => 'P1529',
        self::EVENT_HOLD_CALL => 'P1530',
        self::EVENT_UNHOLD_CALL => 'P1531',
        self::EVENT_MUTE_CALL => 'P1532',
        self::EVENT_UNMUTE_CALL => 'P1533',
        self::EVENT_OPEN_SYSLOG => 'P1534',
        self::EVENT_CLOSE_SYSLOG => 'P1535',
        self::EVENT_IP_CHANGE => 'P1536',
        self::EVENT_AUTO_PROVISION_FINISH => 'P1537',
    ];

    /**
     * Get all supported event types
     */
    public static function getAllEventTypes(): array
    {
        return array_keys(self::$eventSlugMap);
    }

    /**
     * Get the URL slug for an event type
     */
    public static function getEventSlug(string $event): ?string
    {
        return self::$eventSlugMap[$event] ?? null;
    }

    /**
     * Get the P-value parameter for an event type
     */
    public static function getPValue(string $event): ?string
    {
        return self::$pValueMap[$event] ?? null;
    }

    /**
     * Get all P-value mappings
     */
    public static function getPValueMap(): array
    {
        return self::$pValueMap;
    }

    /**
     * Get the webhook base URL
     */
    public static function getBaseUrl(): string
    {
        return config('rayanpbx.webhook_base_url') ?? url('/api/grandstream/webhook');
    }

    /**
     * Get the webhook URL for a specific event
     */
    public static function getEventUrl(string $event): ?string
    {
        $slug = self::getEventSlug($event);
        if (!$slug) {
            return null;
        }
        
        return self::getBaseUrl() . '/' . $slug;
    }

    /**
     * Get all Action URLs mapped by event type
     */
    public static function getAllActionUrls(): array
    {
        $baseUrl = self::getBaseUrl();
        $urls = [];
        
        foreach (self::$eventSlugMap as $event => $slug) {
            $urls[$event] = "{$baseUrl}/{$slug}";
        }
        
        return $urls;
    }

    /**
     * Get complete Action URL configuration for phone provisioning
     */
    public static function getActionUrlConfig(): array
    {
        return [
            'base_url' => self::getBaseUrl(),
            'action_urls' => self::getAllActionUrls(),
            'p_values' => self::getPValueMap(),
        ];
    }

    /**
     * Check if an event type is valid
     */
    public static function isValidEventType(string $event): bool
    {
        return isset(self::$eventSlugMap[$event]);
    }
}
