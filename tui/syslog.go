package main

import (
	"fmt"
	"log/syslog"
	"os"
	"strings"
	"sync"
)

// SystemLogger provides system-level logging (syslog/dmesg) for RayanPBX TUI
type SystemLogger struct {
	enabled      bool
	syslogWriter *syslog.Writer
	ident        string
	mu           sync.Mutex
}

// LogLevel represents the severity of log messages
type LogLevel int

const (
	LogLevelDebug LogLevel = iota
	LogLevelInfo
	LogLevelWarning
	LogLevelError
	LogLevelCritical
)

// NewSystemLogger creates a new system logger instance
func NewSystemLogger() *SystemLogger {
	enabled := os.Getenv("RAYANPBX_SYSLOG_ENABLED") != "false"
	ident := os.Getenv("RAYANPBX_SYSLOG_IDENT")
	if ident == "" {
		ident = "rayanpbx-tui"
	}

	logger := &SystemLogger{
		enabled: enabled,
		ident:   ident,
	}

	if enabled {
		// Try to connect to syslog
		writer, err := syslog.New(syslog.LOG_AUTH|syslog.LOG_INFO, ident)
		if err == nil {
			logger.syslogWriter = writer
		}
	}

	return logger
}

// Close closes the syslog connection
func (l *SystemLogger) Close() {
	l.mu.Lock()
	defer l.mu.Unlock()

	if l.syslogWriter != nil {
		l.syslogWriter.Close()
		l.syslogWriter = nil
	}
}

// IsEnabled returns whether logging is enabled
func (l *SystemLogger) IsEnabled() bool {
	return l.enabled
}

// AuthInfo logs authentication info message
func (l *SystemLogger) AuthInfo(format string, args ...interface{}) {
	l.log(LogLevelInfo, "AUTH", format, args...)
}

// AuthWarning logs authentication warning message
func (l *SystemLogger) AuthWarning(format string, args ...interface{}) {
	l.log(LogLevelWarning, "AUTH", format, args...)
}

// AuthError logs authentication error message
func (l *SystemLogger) AuthError(format string, args ...interface{}) {
	l.log(LogLevelError, "AUTH", format, args...)
}

// AsteriskInfo logs Asterisk info message
func (l *SystemLogger) AsteriskInfo(format string, args ...interface{}) {
	l.log(LogLevelInfo, "ASTERISK", format, args...)
}

// AsteriskWarning logs Asterisk warning message
func (l *SystemLogger) AsteriskWarning(format string, args ...interface{}) {
	l.log(LogLevelWarning, "ASTERISK", format, args...)
}

// AsteriskError logs Asterisk error message
func (l *SystemLogger) AsteriskError(format string, args ...interface{}) {
	l.log(LogLevelError, "ASTERISK", format, args...)
}

// SIPInfo logs SIP/PBX info message
func (l *SystemLogger) SIPInfo(format string, args ...interface{}) {
	l.log(LogLevelInfo, "SIP", format, args...)
}

// SIPWarning logs SIP/PBX warning message
func (l *SystemLogger) SIPWarning(format string, args ...interface{}) {
	l.log(LogLevelWarning, "SIP", format, args...)
}

// SIPError logs SIP/PBX error message
func (l *SystemLogger) SIPError(format string, args ...interface{}) {
	l.log(LogLevelError, "SIP", format, args...)
}

// Critical logs a critical message
func (l *SystemLogger) Critical(format string, args ...interface{}) {
	l.log(LogLevelCritical, "CRITICAL", format, args...)
}

// log is the core logging function
func (l *SystemLogger) log(level LogLevel, category string, format string, args ...interface{}) {
	if !l.enabled {
		return
	}

	l.mu.Lock()
	defer l.mu.Unlock()

	message := fmt.Sprintf("[%s] %s", category, fmt.Sprintf(format, args...))

	// Write to syslog if available
	if l.syslogWriter != nil {
		switch level {
		case LogLevelDebug:
			l.syslogWriter.Debug(message)
		case LogLevelInfo:
			l.syslogWriter.Info(message)
		case LogLevelWarning:
			l.syslogWriter.Warning(message)
		case LogLevelError:
			l.syslogWriter.Err(message)
		case LogLevelCritical:
			l.syslogWriter.Crit(message)
		}
	}

	// For critical messages, also try to write to /dev/kmsg
	if level == LogLevelCritical {
		l.logToKernel(message, 2) // priority 2 = critical
	}
}

// logToKernel writes a message to the kernel ring buffer (/dev/kmsg)
// This makes the message visible in dmesg output
func (l *SystemLogger) logToKernel(message string, priority int) {
	// Try to write to /dev/kmsg
	f, err := os.OpenFile("/dev/kmsg", os.O_WRONLY, 0)
	if err != nil {
		return
	}
	defer f.Close()

	// Format: <priority>ident: message
	formattedMessage := fmt.Sprintf("<%d>%s: %s\n", priority, l.ident, message)
	f.WriteString(formattedMessage)
}

// LogToKernel writes a message directly to the kernel log (dmesg)
// Use this for important messages that must be visible in dmesg
func (l *SystemLogger) LogToKernel(level LogLevel, format string, args ...interface{}) {
	if !l.enabled {
		return
	}

	l.mu.Lock()
	defer l.mu.Unlock()

	message := fmt.Sprintf(format, args...)
	priority := l.levelToKernelPriority(level)
	l.logToKernel(message, priority)
}

// levelToKernelPriority converts LogLevel to kernel priority
func (l *SystemLogger) levelToKernelPriority(level LogLevel) int {
	switch level {
	case LogLevelDebug:
		return 7 // debug
	case LogLevelInfo:
		return 6 // info
	case LogLevelWarning:
		return 4 // warning
	case LogLevelError:
		return 3 // error
	case LogLevelCritical:
		return 2 // critical
	default:
		return 6 // info
	}
}

// GetStatus returns the status of the logging system
func (l *SystemLogger) GetStatus() map[string]interface{} {
	l.mu.Lock()
	defer l.mu.Unlock()

	status := map[string]interface{}{
		"enabled":           l.enabled,
		"ident":             l.ident,
		"syslog_connected":  l.syslogWriter != nil,
		"kmsg_writable":     l.checkKmsgWritable(),
	}

	return status
}

// checkKmsgWritable checks if /dev/kmsg is writable
func (l *SystemLogger) checkKmsgWritable() bool {
	f, err := os.OpenFile("/dev/kmsg", os.O_WRONLY, 0)
	if err != nil {
		return false
	}
	f.Close()
	return true
}

// LevelFromString converts a string to LogLevel
func LevelFromString(s string) LogLevel {
	switch strings.ToLower(s) {
	case "debug":
		return LogLevelDebug
	case "info":
		return LogLevelInfo
	case "warning", "warn":
		return LogLevelWarning
	case "error", "err":
		return LogLevelError
	case "critical", "crit":
		return LogLevelCritical
	default:
		return LogLevelInfo
	}
}

// Global logger instance
var globalLogger *SystemLogger
var loggerOnce sync.Once

// GetSystemLogger returns the global system logger instance
func GetSystemLogger() *SystemLogger {
	loggerOnce.Do(func() {
		globalLogger = NewSystemLogger()
	})
	return globalLogger
}
