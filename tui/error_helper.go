package main

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"strings"
	"time"
)

// ErrorDetails contains detailed error information
type ErrorDetails struct {
	ExitCode    int
	ErrorType   string
	Message     string
	FullOutput  string
	Suggestion  string
	LogFile     string // Path to the log file containing full output
}

// ParseCommandError extracts detailed error information from command execution
func ParseCommandError(err error, output []byte) ErrorDetails {
	details := ErrorDetails{
		FullOutput: string(output),
		Message:    err.Error(),
	}
	
	// Extract exit code from error
	if exitErr, ok := err.(*exec.ExitError); ok {
		details.ExitCode = exitErr.ExitCode()
	}
	
	// Determine error type and provide suggestions based on exit code
	switch details.ExitCode {
	case 127:
		details.ErrorType = "Command Not Found"
		details.Suggestion = "The required command or tool is not installed. Check if the tool is available in PATH."
	case 126:
		details.ErrorType = "Permission Denied"
		details.Suggestion = "The command exists but cannot be executed. Check file permissions."
	case 1:
		details.ErrorType = "General Error"
		details.Suggestion = "The command returned an error. Check the output for details."
	case 2:
		details.ErrorType = "Misuse of Shell"
		details.Suggestion = "The command syntax is incorrect. Check command parameters."
	default:
		details.ErrorType = "Unknown Error"
		details.Suggestion = "Check the full output for details."
	}
	
	// Add context from output if available
	outputStr := strings.TrimSpace(string(output))
	if outputStr != "" {
		details.Message = fmt.Sprintf("%s\n\nOutput:\n%s", err.Error(), outputStr)
	}
	
	return details
}

// truncateByLines truncates output to a maximum number of lines
// Returns the truncated output and whether truncation occurred
func truncateByLines(output string, maxLines int) (string, bool) {
	lines := strings.Split(output, "\n")
	if len(lines) <= maxLines {
		return output, false
	}
	return strings.Join(lines[:maxLines], "\n"), true
}

// saveOutputToTempFile saves the full output to a temporary file
// Returns the file path or empty string on error
func saveOutputToTempFile(output string) string {
	// Create temp file with secure permissions (0600)
	tmpFile, err := os.CreateTemp("", "rayanpbx-error-*.log")
	if err != nil {
		return ""
	}
	defer tmpFile.Close()
	
	// Set restrictive permissions
	os.Chmod(tmpFile.Name(), 0600)
	
	// Write the full output
	if _, err := tmpFile.WriteString(output); err != nil {
		os.Remove(tmpFile.Name())
		return ""
	}
	
	return tmpFile.Name()
}

// FormatVerboseError creates a user-friendly error message with suggestions
func FormatVerboseError(details ErrorDetails) string {
	var result strings.Builder
	
	result.WriteString(fmt.Sprintf("❌ Error: %s (Exit Code: %d)\n\n", details.ErrorType, details.ExitCode))
	
	if details.FullOutput != "" {
		// Use line-based truncation (max 30 lines to avoid cutting mid-line)
		const maxLines = 30
		output, wasTruncated := truncateByLines(details.FullOutput, maxLines)
		
		result.WriteString("📋 Command Output:\n")
		result.WriteString("─────────────────────────────────────\n")
		result.WriteString(output)
		
		if wasTruncated {
			// Save full output to temp file
			logFile := saveOutputToTempFile(details.FullOutput)
			if logFile != "" {
				details.LogFile = logFile
				result.WriteString("\n...\n")
				result.WriteString(fmt.Sprintf("(output truncated)\n"))
				result.WriteString(fmt.Sprintf("To view full output, use:\nless %s\n", logFile))
			} else {
				result.WriteString("\n...\n(output truncated)\n")
			}
		}
		result.WriteString("─────────────────────────────────────\n\n")
	}
	
	result.WriteString("💡 Suggestion: ")
	result.WriteString(details.Suggestion)
	
	return result.String()
}

// GetAISuggestion queries pollinations.ai for troubleshooting suggestions
// This is optional and should be called only when user requests it
func GetAISuggestion(errorMessage string) (string, error) {
	// Build the prompt
	prompt := fmt.Sprintf("I'm running a SIP/VoIP PBX system and got this error: %s\n\nProvide a brief, actionable troubleshooting suggestion (max 3 steps).", errorMessage)
	
	// URL encode the prompt
	encodedPrompt := url.QueryEscape(prompt)
	
	// Make request to pollinations.ai text API
	apiURL := fmt.Sprintf("https://text.pollinations.ai/%s?model=openai", encodedPrompt)
	
	// Create HTTP client with timeout
	client := &http.Client{
		Timeout: 30 * time.Second,
	}
	
	resp, err := client.Get(apiURL)
	if err != nil {
		return "", fmt.Errorf("failed to query AI: %v", err)
	}
	defer resp.Body.Close()
	
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("AI API returned status %d", resp.StatusCode)
	}
	
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", fmt.Errorf("failed to read response: %v", err)
	}
	
	suggestion := strings.TrimSpace(string(body))
	if suggestion == "" {
		return "", fmt.Errorf("empty response from AI")
	}
	
	return suggestion, nil
}

// AIMessage represents a message in the AI conversation
type AIMessage struct {
	Role    string `json:"role"`
	Content string `json:"content"`
}

// AIRequest represents a request to the pollinations.ai API
type AIRequest struct {
	Messages []AIMessage `json:"messages"`
	Model    string      `json:"model"`
}

// GetAISuggestionAdvanced uses POST method for more detailed suggestions
func GetAISuggestionAdvanced(errorMessage, context string) (string, error) {
	// Build the request
	request := AIRequest{
		Messages: []AIMessage{
			{
				Role:    "system",
				Content: "You are a SIP/VoIP PBX troubleshooting assistant. Provide brief, actionable solutions in 3 steps or less. Be concise.",
			},
			{
				Role:    "user",
				Content: fmt.Sprintf("Error: %s\n\nContext: %s\n\nHow do I fix this?", errorMessage, context),
			},
		},
		Model: "openai",
	}
	
	jsonData, err := json.Marshal(request)
	if err != nil {
		return "", fmt.Errorf("failed to create request: %v", err)
	}
	
	// Create HTTP client with timeout
	client := &http.Client{
		Timeout: 30 * time.Second,
	}
	
	resp, err := client.Post("https://text.pollinations.ai/", "application/json", strings.NewReader(string(jsonData)))
	if err != nil {
		return "", fmt.Errorf("failed to query AI: %v", err)
	}
	defer resp.Body.Close()
	
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("AI API returned status %d", resp.StatusCode)
	}
	
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", fmt.Errorf("failed to read response: %v", err)
	}
	
	suggestion := strings.TrimSpace(string(body))
	if suggestion == "" {
		return "", fmt.Errorf("empty response from AI")
	}
	
	return suggestion, nil
}

// CommonErrorSuggestions provides quick offline suggestions for common errors
func CommonErrorSuggestions(errorType string, exitCode int) string {
	suggestions := map[string]string{
		"sip-test":     "Ensure sipsak or pjsua is installed: apt install sipsak",
		"asterisk":     "Check Asterisk service: systemctl status asterisk",
		"network":      "Check network connectivity: ping your_server",
		"permission":   "Run with sudo or check file permissions",
		"command":      "Verify the command is in PATH or use full path",
		"registration": "Check credentials and SIP server address",
	}
	
	// Check for specific error patterns
	switch exitCode {
	case 127:
		return "Install missing tool:\n• For sipsak: apt install sipsak\n• For pjsua: apt install pjsip-apps\n• For nmap: apt install nmap"
	case 126:
		return "Fix permissions:\n• chmod +x /path/to/script\n• Or run with sudo"
	}
	
	if suggestion, ok := suggestions[errorType]; ok {
		return suggestion
	}
	
	return "Check the error output above for details"
}
