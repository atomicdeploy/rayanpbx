package main

import (
	"database/sql"
	"fmt"
	"os"
	"regexp"
	"strings"
)

// ResetConfiguration handles resetting all configuration to a clean state
type ResetConfiguration struct {
	configManager   *AsteriskConfigManager
	asteriskManager *AsteriskManager
	db              *sql.DB
	verbose         bool
}

// NewResetConfiguration creates a new reset configuration handler
func NewResetConfiguration(db *sql.DB, configManager *AsteriskConfigManager, asteriskManager *AsteriskManager, verbose bool) *ResetConfiguration {
	return &ResetConfiguration{
		db:              db,
		configManager:   configManager,
		asteriskManager: asteriskManager,
		verbose:         verbose,
	}
}

// ResetResult contains the result of a reset operation
type ResetResult struct {
	DatabaseCleared    bool
	PjsipCleared       bool
	ExtensionsCleared  bool
	ManagerCleared     bool
	AsteriskReloaded   bool
	ExtensionsRemoved  int
	TrunksRemoved      int
	VoIPPhonesRemoved  int
	Errors             []string
}

// HasErrors returns true if there were any errors during reset
func (r *ResetResult) HasErrors() bool {
	return len(r.Errors) > 0
}

// ResetAll performs a complete reset of all configuration
// This is a destructive operation that clears the database and Asterisk config files
func (rc *ResetConfiguration) ResetAll() (*ResetResult, error) {
	result := &ResetResult{
		Errors: []string{},
	}

	// Step 1: Clear database tables
	dbErr := rc.clearDatabase(result)
	if dbErr != nil {
		result.Errors = append(result.Errors, fmt.Sprintf("Database: %v", dbErr))
	}

	// Step 2: Clear PJSIP configuration (pjsip.conf)
	pjsipErr := rc.clearPjsipConfig()
	if pjsipErr != nil {
		result.Errors = append(result.Errors, fmt.Sprintf("pjsip.conf: %v", pjsipErr))
	} else {
		result.PjsipCleared = true
	}

	// Step 3: Clear dialplan/extensions configuration (extensions.conf)
	extErr := rc.clearExtensionsConfig()
	if extErr != nil {
		result.Errors = append(result.Errors, fmt.Sprintf("extensions.conf: %v", extErr))
	} else {
		result.ExtensionsCleared = true
	}

	// Step 4: Reload Asterisk configuration
	reloadErr := rc.reloadAsterisk()
	if reloadErr != nil {
		result.Errors = append(result.Errors, fmt.Sprintf("Asterisk reload: %v", reloadErr))
	} else {
		result.AsteriskReloaded = true
	}

	if result.HasErrors() {
		return result, fmt.Errorf("reset completed with errors")
	}

	return result, nil
}

// clearDatabase clears all PBX-related data from the database
func (rc *ResetConfiguration) clearDatabase(result *ResetResult) error {
	if rc.db == nil {
		return fmt.Errorf("database connection is nil")
	}

	// Count before deletion
	var extCount, trunkCount, phoneCount int
	rc.db.QueryRow("SELECT COUNT(*) FROM extensions").Scan(&extCount)
	rc.db.QueryRow("SELECT COUNT(*) FROM trunks").Scan(&trunkCount)
	rc.db.QueryRow("SELECT COUNT(*) FROM voip_phones").Scan(&phoneCount)

	// Delete all extensions
	if _, err := rc.db.Exec("DELETE FROM extensions"); err != nil {
		return fmt.Errorf("failed to clear extensions table: %v", err)
	}
	result.ExtensionsRemoved = extCount

	// Delete all trunks
	if _, err := rc.db.Exec("DELETE FROM trunks"); err != nil {
		return fmt.Errorf("failed to clear trunks table: %v", err)
	}
	result.TrunksRemoved = trunkCount

	// Delete all VoIP phones
	if _, err := rc.db.Exec("DELETE FROM voip_phones"); err != nil {
		// Table might not exist, ignore error
		if !strings.Contains(err.Error(), "doesn't exist") {
			return fmt.Errorf("failed to clear voip_phones table: %v", err)
		}
	} else {
		result.VoIPPhonesRemoved = phoneCount
	}

	result.DatabaseCleared = true
	return nil
}

// clearPjsipConfig removes all managed sections from pjsip.conf and resets to clean state
func (rc *ResetConfiguration) clearPjsipConfig() error {
	pjsipPath := "/etc/asterisk/pjsip.conf"

	// Check if file exists
	if _, err := os.Stat(pjsipPath); os.IsNotExist(err) {
		// Nothing to clear
		return nil
	}

	existingContent, err := os.ReadFile(pjsipPath)
	if err != nil {
		return fmt.Errorf("failed to read pjsip.conf: %v", err)
	}

	content := string(existingContent)

	// Remove all RayanPBX managed sections using regex
	patterns := []string{
		// Extension sections
		`(?s); BEGIN MANAGED - Extension \d+.*?; END MANAGED - Extension \d+\n`,
		// Hello World extension
		`(?s); BEGIN MANAGED - RayanPBX Hello World Extension.*?; END MANAGED - RayanPBX Hello World Extension\n`,
		// Transports
		`(?s); BEGIN MANAGED - RayanPBX Transports.*?; END MANAGED - RayanPBX Transports\n`,
		`(?s); BEGIN MANAGED - RayanPBX Transport.*?; END MANAGED - RayanPBX Transport\n`,
	}

	for _, pattern := range patterns {
		re := regexp.MustCompile(pattern)
		content = re.ReplaceAllString(content, "")
	}

	// Create a clean pjsip.conf with just header and basic transport
	cleanConfig := `; RayanPBX PJSIP Configuration
; Reset to clean state by RayanPBX Reset Configuration

; UDP Transport (default)
[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0:5060
allow_reload=yes

; TCP Transport
[transport-tcp]
type=transport
protocol=tcp
bind=0.0.0.0:5060
allow_reload=yes

`

	err = os.WriteFile(pjsipPath, []byte(cleanConfig), 0644)
	if err != nil {
		return fmt.Errorf("failed to write clean pjsip.conf: %v", err)
	}

	return nil
}

// clearExtensionsConfig removes all managed sections from extensions.conf and resets to clean state
func (rc *ResetConfiguration) clearExtensionsConfig() error {
	extPath := "/etc/asterisk/extensions.conf"

	// Check if file exists
	if _, err := os.Stat(extPath); os.IsNotExist(err) {
		// Nothing to clear
		return nil
	}

	existingContent, err := os.ReadFile(extPath)
	if err != nil {
		return fmt.Errorf("failed to read extensions.conf: %v", err)
	}

	content := string(existingContent)

	// Remove all RayanPBX managed sections using regex
	patterns := []string{
		// Internal extensions
		`(?s); BEGIN MANAGED - RayanPBX Internal Extensions.*?; END MANAGED - RayanPBX Internal Extensions\n`,
		// Hello World dialplan
		`(?s); BEGIN MANAGED - RayanPBX Hello World Dialplan.*?; END MANAGED - RayanPBX Hello World Dialplan\n`,
	}

	for _, pattern := range patterns {
		re := regexp.MustCompile(pattern)
		content = re.ReplaceAllString(content, "")
	}

	// Create a clean extensions.conf with just header
	cleanConfig := `; RayanPBX Dialplan Configuration
; Reset to clean state by RayanPBX Reset Configuration

[general]
static=yes
writeprotect=no

[globals]

[from-internal]
; Add your extension dialplan rules here

`

	err = os.WriteFile(extPath, []byte(cleanConfig), 0644)
	if err != nil {
		return fmt.Errorf("failed to write clean extensions.conf: %v", err)
	}

	return nil
}

// reloadAsterisk reloads Asterisk to apply the clean configuration
func (rc *ResetConfiguration) reloadAsterisk() error {
	if rc.asteriskManager == nil {
		return fmt.Errorf("asterisk manager is nil")
	}

	// Check if Asterisk is running
	status, _ := rc.asteriskManager.GetServiceStatus()
	if status != "running" {
		// Asterisk is not running, nothing to reload
		return nil
	}

	// Reload PJSIP module
	if _, err := rc.asteriskManager.ExecuteCLICommand("module reload res_pjsip.so"); err != nil {
		return fmt.Errorf("failed to reload PJSIP: %v", err)
	}

	// Reload dialplan
	if _, err := rc.asteriskManager.ExecuteCLICommand("dialplan reload"); err != nil {
		return fmt.Errorf("failed to reload dialplan: %v", err)
	}

	return nil
}

// GetSummary returns a human-readable summary of what will be reset
func (rc *ResetConfiguration) GetSummary() (string, error) {
	var summary strings.Builder

	summary.WriteString("⚠️  This will reset ALL configuration:\n\n")

	// Count items in database
	if rc.db != nil {
		var extCount, trunkCount, phoneCount int
		rc.db.QueryRow("SELECT COUNT(*) FROM extensions").Scan(&extCount)
		rc.db.QueryRow("SELECT COUNT(*) FROM trunks").Scan(&trunkCount)
		rc.db.QueryRow("SELECT COUNT(*) FROM voip_phones").Scan(&phoneCount)

		summary.WriteString("📋 Database:\n")
		summary.WriteString(fmt.Sprintf("   • %d extension(s) will be deleted\n", extCount))
		summary.WriteString(fmt.Sprintf("   • %d trunk(s) will be deleted\n", trunkCount))
		if phoneCount > 0 {
			summary.WriteString(fmt.Sprintf("   • %d VoIP phone(s) will be deleted\n", phoneCount))
		}
	}

	summary.WriteString("\n📁 Asterisk Configuration Files:\n")
	
	// Check pjsip.conf
	if _, err := os.Stat("/etc/asterisk/pjsip.conf"); err == nil {
		summary.WriteString("   • /etc/asterisk/pjsip.conf will be reset\n")
	}

	// Check extensions.conf
	if _, err := os.Stat("/etc/asterisk/extensions.conf"); err == nil {
		summary.WriteString("   • /etc/asterisk/extensions.conf will be reset\n")
	}

	summary.WriteString("\n🔄 Asterisk will be reloaded to apply changes\n")
	summary.WriteString("\n⚠️  THIS ACTION CANNOT BE UNDONE!\n")

	return summary.String(), nil
}
