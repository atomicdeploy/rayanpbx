<?php

namespace App\Services;

/**
 * System Log Service
 *
 * Provides logging to system log (dmesg/syslog) for important events
 * related to authentication, Asterisk, and SIP/PBX operations.
 */
class SystemLogService
{
    private const FACILITY_AUTH = LOG_AUTH;        // Security/authorization messages
    private const FACILITY_LOCAL0 = LOG_LOCAL0;    // Local use 0 (for Asterisk)
    private const FACILITY_LOCAL1 = LOG_LOCAL1;    // Local use 1 (for SIP/PBX)

    private bool $enabled;
    private string $ident;

    public function __construct()
    {
        $this->enabled = (bool) env('RAYANPBX_SYSLOG_ENABLED', true);
        $this->ident = env('RAYANPBX_SYSLOG_IDENT', 'rayanpbx');
    }

    /**
     * Log an authentication-related message
     */
    public function authInfo(string $message): void
    {
        $this->log(self::FACILITY_AUTH, LOG_INFO, "[AUTH] {$message}");
    }

    /**
     * Log an authentication warning
     */
    public function authWarning(string $message): void
    {
        $this->log(self::FACILITY_AUTH, LOG_WARNING, "[AUTH] {$message}");
    }

    /**
     * Log an authentication error
     */
    public function authError(string $message): void
    {
        $this->log(self::FACILITY_AUTH, LOG_ERR, "[AUTH] {$message}");
    }

    /**
     * Log authentication debug message (only in debug mode)
     */
    public function authDebug(string $message): void
    {
        if (env('APP_DEBUG', false)) {
            $this->log(self::FACILITY_AUTH, LOG_DEBUG, "[AUTH] {$message}");
        }
    }

    /**
     * Log an Asterisk-related info message
     */
    public function asteriskInfo(string $message): void
    {
        $this->log(self::FACILITY_LOCAL0, LOG_INFO, "[ASTERISK] {$message}");
    }

    /**
     * Log an Asterisk warning
     */
    public function asteriskWarning(string $message): void
    {
        $this->log(self::FACILITY_LOCAL0, LOG_WARNING, "[ASTERISK] {$message}");
    }

    /**
     * Log an Asterisk error
     */
    public function asteriskError(string $message): void
    {
        $this->log(self::FACILITY_LOCAL0, LOG_ERR, "[ASTERISK] {$message}");
    }

    /**
     * Log a SIP/PBX-related info message
     */
    public function sipInfo(string $message): void
    {
        $this->log(self::FACILITY_LOCAL1, LOG_INFO, "[SIP] {$message}");
    }

    /**
     * Log a SIP/PBX warning
     */
    public function sipWarning(string $message): void
    {
        $this->log(self::FACILITY_LOCAL1, LOG_WARNING, "[SIP] {$message}");
    }

    /**
     * Log a SIP/PBX error
     */
    public function sipError(string $message): void
    {
        $this->log(self::FACILITY_LOCAL1, LOG_ERR, "[SIP] {$message}");
    }

    /**
     * Log a critical error that should definitely be visible
     */
    public function critical(string $message): void
    {
        $this->log(self::FACILITY_AUTH, LOG_CRIT, "[CRITICAL] {$message}");
    }

    /**
     * Log to kernel ring buffer (dmesg) via /dev/kmsg
     * This is for critical messages that must be visible in dmesg
     */
    public function logToKernel(string $message, int $priority = 4): void
    {
        if (!$this->enabled) {
            return;
        }

        // Priority levels: 0=emerg, 1=alert, 2=crit, 3=err, 4=warn, 5=notice, 6=info, 7=debug
        $formattedMessage = "<{$priority}>{$this->ident}: {$message}";

        // Try to write to /dev/kmsg (requires root or CAP_SYSLOG capability)
        $kmsg = @fopen('/dev/kmsg', 'w');
        if ($kmsg !== false) {
            @fwrite($kmsg, $formattedMessage);
            @fclose($kmsg);
        }

        // Also log to syslog as fallback
        $this->log(self::FACILITY_AUTH, $this->kernelPriorityToSyslog($priority), $message);
    }

    /**
     * Convert kernel priority to syslog priority
     */
    private function kernelPriorityToSyslog(int $kernelPriority): int
    {
        $mapping = [
            0 => LOG_EMERG,
            1 => LOG_ALERT,
            2 => LOG_CRIT,
            3 => LOG_ERR,
            4 => LOG_WARNING,
            5 => LOG_NOTICE,
            6 => LOG_INFO,
            7 => LOG_DEBUG,
        ];

        return $mapping[$kernelPriority] ?? LOG_INFO;
    }

    /**
     * Core logging function
     */
    private function log(int $facility, int $priority, string $message): void
    {
        if (!$this->enabled) {
            return;
        }

        // Open syslog with the appropriate facility
        openlog($this->ident, LOG_PID | LOG_NDELAY, $facility);
        syslog($priority, $message);
        closelog();

        // Also log to Laravel's log if in debug mode
        if (env('APP_DEBUG', false)) {
            $levelName = $this->priorityToLevelName($priority);
            \Illuminate\Support\Facades\Log::log($levelName, "[SYSLOG] {$message}");
        }
    }

    /**
     * Convert syslog priority to Laravel log level name
     */
    private function priorityToLevelName(int $priority): string
    {
        $mapping = [
            LOG_EMERG => 'emergency',
            LOG_ALERT => 'alert',
            LOG_CRIT => 'critical',
            LOG_ERR => 'error',
            LOG_WARNING => 'warning',
            LOG_NOTICE => 'notice',
            LOG_INFO => 'info',
            LOG_DEBUG => 'debug',
        ];

        return $mapping[$priority] ?? 'info';
    }

    /**
     * Check if syslog is available and working
     */
    public function isAvailable(): bool
    {
        // Try to open syslog
        if (!@openlog($this->ident . '_test', LOG_NDELAY, LOG_USER)) {
            return false;
        }
        closelog();
        return true;
    }

    /**
     * Get status of the logging service
     */
    public function getStatus(): array
    {
        return [
            'enabled' => $this->enabled,
            'ident' => $this->ident,
            'syslog_available' => $this->isAvailable(),
            'kmsg_writable' => $this->checkKmsgWritable(),
        ];
    }

    /**
     * Check if /dev/kmsg is writable by actually attempting to open it
     */
    private function checkKmsgWritable(): bool
    {
        $kmsg = @fopen('/dev/kmsg', 'w');
        if ($kmsg !== false) {
            @fclose($kmsg);
            return true;
        }
        return false;
    }
}
