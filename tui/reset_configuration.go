package main

import (
	"database/sql"
	"fmt"
	"os"
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

	// Count before deletion (ignore errors - counts will just be 0 if tables don't exist)
	var extCount, trunkCount, phoneCount int
	_ = rc.db.QueryRow("SELECT COUNT(*) FROM extensions").Scan(&extCount)
	_ = rc.db.QueryRow("SELECT COUNT(*) FROM trunks").Scan(&trunkCount)
	_ = rc.db.QueryRow("SELECT COUNT(*) FROM voip_phones").Scan(&phoneCount)

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

	// Delete all VoIP phones - check if table exists first
	var tableExists int
	err := rc.db.QueryRow("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'voip_phones'").Scan(&tableExists)
	if err == nil && tableExists > 0 {
		if _, err := rc.db.Exec("DELETE FROM voip_phones"); err != nil {
			return fmt.Errorf("failed to clear voip_phones table: %v", err)
		}
		result.VoIPPhonesRemoved = phoneCount
	}

	result.DatabaseCleared = true
	return nil
}

// clearPjsipConfig resets pjsip.conf to a clean state with only transport configuration
func (rc *ResetConfiguration) clearPjsipConfig() error {
	pjsipPath := "/etc/asterisk/pjsip.conf"

	// Check if file exists
	if _, err := os.Stat(pjsipPath); os.IsNotExist(err) {
		// Nothing to clear
		return nil
	}

	// Create a clean pjsip.conf with just header and basic transport
	config := &AsteriskConfig{
		HeaderLines: []string{
			"; RayanPBX PJSIP Configuration",
			"; Reset to clean state by RayanPBX Reset Configuration",
			"",
		},
		Sections: CreateTransportSections(),
		FilePath: pjsipPath,
	}

	return config.Save()
}

// clearExtensionsConfig resets extensions.conf to a clean state
func (rc *ResetConfiguration) clearExtensionsConfig() error {
	extPath := "/etc/asterisk/extensions.conf"

	// Check if file exists
	if _, err := os.Stat(extPath); os.IsNotExist(err) {
		// Nothing to clear
		return nil
	}

	// Create a clean extensions.conf with basic structure
	general := NewAsteriskSection("general", "")
	general.SetProperty("static", "yes")
	general.SetProperty("writeprotect", "no")

	globals := NewAsteriskSection("globals", "")

	fromInternal := NewAsteriskSection("from-internal", "")
	fromInternal.Comments = []string{"; Add your extension dialplan rules here"}

	config := &AsteriskConfig{
		HeaderLines: []string{
			"; RayanPBX Dialplan Configuration",
			"; Reset to clean state by RayanPBX Reset Configuration",
			"",
		},
		Sections: []*AsteriskSection{general, globals, fromInternal},
		FilePath: extPath,
	}

	return config.Save()
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
