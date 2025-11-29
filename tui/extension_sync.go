package main

import (
	"database/sql"
	"fmt"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// Pre-compiled regex patterns for extension number extraction
// These are compiled once at package initialization for efficiency
var (
	// Standard pattern: purely numeric (e.g., "101")
	extNumericPattern = regexp.MustCompile(`^\d+$`)
	
	// Suffix patterns: NUMBER-TYPE (e.g., "101-auth", "101-aor", "101-endpoint")
	extSuffixPattern = regexp.MustCompile(`^(\d+)[-_]?(auth|aor|endpoint)$`)
	
	// Prefix patterns: TYPE-NUMBER or TYPENUMBER (e.g., "auth-101", "auth101", "aor101")
	extPrefixPattern = regexp.MustCompile(`^(auth|aor|endpoint)[-_]?(\d+)$`)
)

// extractExtensionNumber extracts the base extension number from various section naming patterns.
// Handles patterns like:
//   - "101" -> "101" (standard)
//   - "101-auth" -> "101" (suffix style)
//   - "101-aor" -> "101" (suffix style)
//   - "auth101" -> "101" (prefix style)
//   - "aor101" -> "101" (prefix style)
//   - "endpoint101" -> "101" (prefix style)
//
// Returns the base extension number and whether this is a valid extension-related section.
// Non-numeric base names (like trunk names) are not matched.
func extractExtensionNumber(sectionName string) (string, bool) {
	// Standard pattern: purely numeric (e.g., "101")
	if extNumericPattern.MatchString(sectionName) {
		return sectionName, true
	}

	// Suffix patterns: NUMBER-TYPE (e.g., "101-auth", "101-aor", "101-endpoint")
	if matches := extSuffixPattern.FindStringSubmatch(sectionName); matches != nil {
		return matches[1], true
	}

	// Prefix patterns: TYPE-NUMBER or TYPENUMBER (e.g., "auth-101", "auth101", "aor101")
	if matches := extPrefixPattern.FindStringSubmatch(sectionName); matches != nil {
		return matches[2], true
	}

	return "", false
}

// getExtensionSectionPatterns returns all possible section name patterns for an extension.
// This is used to find and remove sections with alternative naming patterns.
func getExtensionSectionPatterns(extNumber string) []string {
	return []string{
		extNumber,                    // Standard: [101]
		extNumber + "-auth",          // [101-auth]
		extNumber + "-aor",           // [101-aor]
		extNumber + "-endpoint",      // [101-endpoint]
		extNumber + "_auth",          // [101_auth]
		extNumber + "_aor",           // [101_aor]
		extNumber + "_endpoint",      // [101_endpoint]
		"auth-" + extNumber,          // [auth-101]
		"aor-" + extNumber,           // [aor-101]
		"endpoint-" + extNumber,      // [endpoint-101]
		"auth_" + extNumber,          // [auth_101]
		"aor_" + extNumber,           // [aor_101]
		"endpoint_" + extNumber,      // [endpoint_101]
		"auth" + extNumber,           // [auth101]
		"aor" + extNumber,            // [aor101]
		"endpoint" + extNumber,       // [endpoint101]
	}
}

// isAlternativeNaming returns true if the section name uses non-standard naming
// (anything other than just the extension number)
func isAlternativeNaming(sectionName, extNumber string) bool {
	return sectionName != extNumber
}

// codecsToJSONSync converts a comma-separated codec string to JSON array format
func codecsToJSONSync(codecs string) string {
	if codecs == "" {
		return `["ulaw","alaw","g722"]`
	}
	codecList := strings.Split(codecs, ",")
	var jsonCodecs []string
	for _, codec := range codecList {
		codec = strings.TrimSpace(codec)
		if codec != "" {
			jsonCodecs = append(jsonCodecs, `"`+codec+`"`)
		}
	}
	if len(jsonCodecs) == 0 {
		return `["ulaw","alaw","g722"]`
	}
	return "[" + strings.Join(jsonCodecs, ",") + "]"
}

// ExtensionSource indicates where an extension is defined
type ExtensionSource int

const (
	SourceDatabase ExtensionSource = iota
	SourceAsterisk
	SourceBoth
)

// SyncStatus represents the sync status between DB and Asterisk
type SyncStatus int

const (
	SyncStatusMatch SyncStatus = iota
	SyncStatusDBOnly
	SyncStatusAsteriskOnly
	SyncStatusMismatch
)

// ExtensionSyncInfo contains information about an extension's sync status
type ExtensionSyncInfo struct {
	ExtensionNumber string
	Source          ExtensionSource
	SyncStatus      SyncStatus
	DBExtension     *Extension
	AsteriskConfig  *AsteriskExtension
	Differences     []string
}

// AsteriskExtension represents an extension parsed from Asterisk config
type AsteriskExtension struct {
	ExtensionNumber  string
	Context          string
	Transport        string
	Codecs           []string
	Secret           string
	MaxContacts      int
	QualifyFrequency int
	DirectMedia      string
	CallerID         string
	Registered       bool // Live status from Asterisk
}

// ExtensionSyncManager handles synchronization between DB and Asterisk
type ExtensionSyncManager struct {
	db                  *sql.DB
	pjsipConfigPath     string
	asteriskManager     *AsteriskManager
	asteriskConfigMgr   *AsteriskConfigManager
}

// NewExtensionSyncManager creates a new sync manager
func NewExtensionSyncManager(db *sql.DB, asteriskManager *AsteriskManager, configMgr *AsteriskConfigManager) *ExtensionSyncManager {
	return &ExtensionSyncManager{
		db:                  db,
		pjsipConfigPath:     "/etc/asterisk/pjsip.conf",
		asteriskManager:     asteriskManager,
		asteriskConfigMgr:   configMgr,
	}
}

// ParsePjsipConfig parses the pjsip.conf file and extracts extensions
func (esm *ExtensionSyncManager) ParsePjsipConfig() ([]AsteriskExtension, error) {
	content, err := os.ReadFile(esm.pjsipConfigPath)
	if err != nil {
		if os.IsNotExist(err) {
			return []AsteriskExtension{}, nil
		}
		return nil, fmt.Errorf("failed to read pjsip.conf: %w", err)
	}

	return esm.parsePjsipContent(string(content))
}

// parsePjsipContent parses the content of pjsip.conf and extracts extensions
// This function handles both standard naming (all sections named [101]) and
// alternative naming patterns ([101-auth], [auth101], etc.) by extracting
// the base extension number from any recognized pattern.
func (esm *ExtensionSyncManager) parsePjsipContent(content string) ([]AsteriskExtension, error) {
	extensions := make(map[string]*AsteriskExtension)
	
	lines := strings.Split(content, "\n")
	var currentSection string
	var currentExtNumber string // The extracted base extension number
	var currentType string
	
	for _, line := range lines {
		line = strings.TrimSpace(line)
		
		// Skip comments and empty lines
		if line == "" || strings.HasPrefix(line, ";") {
			continue
		}
		
		// Check for section header [name]
		if strings.HasPrefix(line, "[") && strings.HasSuffix(line, "]") {
			currentSection = strings.TrimPrefix(strings.TrimSuffix(line, "]"), "[")
			currentType = ""
			
			// Try to extract extension number from section name
			// This handles both standard ([101]) and alternative ([101-auth]) naming
			if extNum, ok := extractExtensionNumber(currentSection); ok {
				currentExtNumber = extNum
			} else {
				currentExtNumber = ""
			}
			continue
		}
		
		// Skip non-extension sections (transports, global, etc.)
		if currentSection == "" || 
		   currentSection == "global" || 
		   strings.HasPrefix(currentSection, "transport-") {
			continue
		}
		
		// Parse key=value
		parts := strings.SplitN(line, "=", 2)
		if len(parts) != 2 {
			continue
		}
		
		key := strings.TrimSpace(parts[0])
		value := strings.TrimSpace(parts[1])
		
		// Check the type of this section
		if key == "type" {
			currentType = value
			continue
		}
		
		// Only process endpoint, auth, and aor types for extensions
		// Skip identify sections (used for trunks)
		if currentType == "identify" {
			continue
		}
		
		// Skip if we couldn't extract an extension number
		if currentExtNumber == "" {
			continue
		}
		
		// Get or create extension entry using the extracted extension number
		// This groups sections with alternative naming back to the base extension
		ext, exists := extensions[currentExtNumber]
		if !exists {
			ext = &AsteriskExtension{
				ExtensionNumber: currentExtNumber,
				MaxContacts:     1,
				QualifyFrequency: 60,
				DirectMedia:     "no",
			}
			extensions[currentExtNumber] = ext
		}
		
		// Parse properties based on type
		switch currentType {
		case "endpoint":
			switch key {
			case "context":
				ext.Context = value
			case "transport":
				ext.Transport = value
			case "allow":
				ext.Codecs = append(ext.Codecs, value)
			case "callerid":
				ext.CallerID = value
			case "direct_media":
				ext.DirectMedia = value
			}
		case "auth":
			switch key {
			case "password":
				ext.Secret = value
			}
		case "aor":
			switch key {
			case "max_contacts":
				if val, err := strconv.Atoi(value); err == nil {
					ext.MaxContacts = val
				}
			case "qualify_frequency":
				if val, err := strconv.Atoi(value); err == nil {
					ext.QualifyFrequency = val
				}
			}
		}
	}
	
	// Build result - we already filtered to only numeric extension numbers
	// via extractExtensionNumber, so just collect all entries with a context
	var result []AsteriskExtension
	for _, ext := range extensions {
		if ext.Context != "" {
			result = append(result, *ext)
		}
	}
	
	return result, nil
}

// GetDatabaseExtensions fetches all extensions from the database
func (esm *ExtensionSyncManager) GetDatabaseExtensions() ([]Extension, error) {
	return GetExtensions(esm.db)
}

// GetLiveAsteriskEndpoints gets live endpoint information from Asterisk
func (esm *ExtensionSyncManager) GetLiveAsteriskEndpoints() (map[string]bool, error) {
	output, err := esm.asteriskManager.ExecuteCLICommand("pjsip show endpoints")
	if err != nil {
		return nil, err
	}
	
	registered := make(map[string]bool)
	lines := strings.Split(output, "\n")
	
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		
		// Parse endpoint lines
		fields := strings.Fields(line)
		if len(fields) >= 2 {
			endpoint := fields[0]
			// Skip non-numeric endpoints
			if match, _ := regexp.MatchString(`^\d+$`, endpoint); match {
				// Check if endpoint is available/registered
				registered[endpoint] = strings.Contains(line, "Avail") || 
				                        strings.Contains(line, "Available") ||
				                        strings.Contains(line, "Not in use")
			}
		}
	}
	
	return registered, nil
}

// CompareExtensions compares database and Asterisk extensions
func (esm *ExtensionSyncManager) CompareExtensions() ([]ExtensionSyncInfo, error) {
	// Get database extensions
	dbExtensions, err := esm.GetDatabaseExtensions()
	if err != nil {
		return nil, fmt.Errorf("failed to get database extensions: %w", err)
	}
	
	// Get Asterisk extensions from config
	asteriskExts, err := esm.ParsePjsipConfig()
	if err != nil {
		return nil, fmt.Errorf("failed to parse pjsip config: %w", err)
	}
	
	// Get live registration status
	liveStatus, _ := esm.GetLiveAsteriskEndpoints()
	
	// Build maps for easy lookup
	dbMap := make(map[string]*Extension)
	for i := range dbExtensions {
		dbMap[dbExtensions[i].ExtensionNumber] = &dbExtensions[i]
	}
	
	astMap := make(map[string]*AsteriskExtension)
	for i := range asteriskExts {
		asteriskExts[i].Registered = liveStatus[asteriskExts[i].ExtensionNumber]
		astMap[asteriskExts[i].ExtensionNumber] = &asteriskExts[i]
	}
	
	// Build sync info list
	var syncInfos []ExtensionSyncInfo
	
	// Process all known extensions (from both sources)
	allExtensions := make(map[string]bool)
	for ext := range dbMap {
		allExtensions[ext] = true
	}
	for ext := range astMap {
		allExtensions[ext] = true
	}
	
	for extNum := range allExtensions {
		dbExt := dbMap[extNum]
		astExt := astMap[extNum]
		
		info := ExtensionSyncInfo{
			ExtensionNumber: extNum,
			DBExtension:     dbExt,
			AsteriskConfig:  astExt,
		}
		
		if dbExt != nil && astExt != nil {
			info.Source = SourceBoth
			info.SyncStatus = SyncStatusMatch
			
			// Check for differences
			info.Differences = esm.findDifferences(dbExt, astExt)
			if len(info.Differences) > 0 {
				info.SyncStatus = SyncStatusMismatch
			}
		} else if dbExt != nil {
			info.Source = SourceDatabase
			info.SyncStatus = SyncStatusDBOnly
			info.Differences = []string{"Not in Asterisk config"}
		} else {
			info.Source = SourceAsterisk
			info.SyncStatus = SyncStatusAsteriskOnly
			info.Differences = []string{"Not in database"}
		}
		
		syncInfos = append(syncInfos, info)
	}
	
	// Sort by extension number (numerically if possible, otherwise lexicographically)
	sort.Slice(syncInfos, func(i, j int) bool {
		numI, errI := strconv.Atoi(syncInfos[i].ExtensionNumber)
		numJ, errJ := strconv.Atoi(syncInfos[j].ExtensionNumber)
		
		// If both are numeric, compare numerically
		if errI == nil && errJ == nil {
			return numI < numJ
		}
		// Otherwise, compare lexicographically
		return syncInfos[i].ExtensionNumber < syncInfos[j].ExtensionNumber
	})
	
	return syncInfos, nil
}

// findDifferences compares a DB extension with an Asterisk extension
func (esm *ExtensionSyncManager) findDifferences(dbExt *Extension, astExt *AsteriskExtension) []string {
	var diffs []string
	
	// Compare context
	dbContext := dbExt.Context
	if dbContext == "" {
		dbContext = "from-internal"
	}
	if astExt.Context != dbContext {
		diffs = append(diffs, fmt.Sprintf("Context: DB=%s, Asterisk=%s", dbContext, astExt.Context))
	}
	
	// Compare transport
	dbTransport := dbExt.Transport
	if dbTransport == "" {
		dbTransport = "transport-udp"
	}
	if astExt.Transport != "" && astExt.Transport != dbTransport {
		diffs = append(diffs, fmt.Sprintf("Transport: DB=%s, Asterisk=%s", dbTransport, astExt.Transport))
	}
	
	// Compare max_contacts
	dbMaxContacts := dbExt.MaxContacts
	if dbMaxContacts == 0 {
		dbMaxContacts = 1
	}
	if astExt.MaxContacts != dbMaxContacts {
		diffs = append(diffs, fmt.Sprintf("Max Contacts: DB=%d, Asterisk=%d", dbMaxContacts, astExt.MaxContacts))
	}
	
	// Compare direct_media
	dbDirectMedia := dbExt.DirectMedia
	if dbDirectMedia == "" {
		dbDirectMedia = "no"
	}
	if astExt.DirectMedia != "" && astExt.DirectMedia != dbDirectMedia {
		diffs = append(diffs, fmt.Sprintf("Direct Media: DB=%s, Asterisk=%s", dbDirectMedia, astExt.DirectMedia))
	}
	
	return diffs
}

// SyncDatabaseToAsterisk syncs a single extension from database to Asterisk
func (esm *ExtensionSyncManager) SyncDatabaseToAsterisk(extNumber string) error {
	// Find the extension in database
	dbExts, err := esm.GetDatabaseExtensions()
	if err != nil {
		return err
	}
	
	var ext *Extension
	for i := range dbExts {
		if dbExts[i].ExtensionNumber == extNumber {
			ext = &dbExts[i]
			break
		}
	}
	
	if ext == nil {
		return fmt.Errorf("extension %s not found in database", extNumber)
	}
	
	// Generate and write PJSIP config
	sections := esm.asteriskConfigMgr.GeneratePjsipEndpoint(*ext)
	err = esm.asteriskConfigMgr.WritePjsipConfigSections(sections, fmt.Sprintf("Extension %s", extNumber))
	if err != nil {
		return fmt.Errorf("failed to write PJSIP config: %w", err)
	}
	
	// Reload Asterisk
	return esm.asteriskConfigMgr.ReloadAsterisk()
}

// SyncAsteriskToDatabase syncs a single extension from Asterisk to database
func (esm *ExtensionSyncManager) SyncAsteriskToDatabase(extNumber string) error {
	// Parse Asterisk config
	asteriskExts, err := esm.ParsePjsipConfig()
	if err != nil {
		return err
	}
	
	var astExt *AsteriskExtension
	for i := range asteriskExts {
		if asteriskExts[i].ExtensionNumber == extNumber {
			astExt = &asteriskExts[i]
			break
		}
	}
	
	if astExt == nil {
		return fmt.Errorf("extension %s not found in Asterisk config", extNumber)
	}
	
	// Check if extension exists in database
	var count int
	err = esm.db.QueryRow("SELECT COUNT(*) FROM extensions WHERE extension_number = ?", extNumber).Scan(&count)
	if err != nil {
		return fmt.Errorf("database query error: %w", err)
	}
	
	// Convert codecs to JSON format
	codecsJSON := codecsToJSONSync(strings.Join(astExt.Codecs, ","))
	
	if count > 0 {
		// Update existing extension
		query := `UPDATE extensions SET 
			context = ?, transport = ?, max_contacts = ?, qualify_frequency = ?, 
			direct_media = ?, codecs = ?, updated_at = NOW() 
			WHERE extension_number = ?`
		_, err = esm.db.Exec(query, 
			astExt.Context, 
			astExt.Transport, 
			astExt.MaxContacts, 
			astExt.QualifyFrequency,
			astExt.DirectMedia,
			codecsJSON,
			extNumber)
	} else {
		// Insert new extension
		query := `INSERT INTO extensions 
			(extension_number, name, secret, context, transport, max_contacts, 
			 qualify_frequency, direct_media, codecs, enabled, created_at, updated_at) 
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())`
		_, err = esm.db.Exec(query,
			extNumber,
			fmt.Sprintf("Extension %s", extNumber), // Default name
			astExt.Secret,
			astExt.Context,
			astExt.Transport,
			astExt.MaxContacts,
			astExt.QualifyFrequency,
			astExt.DirectMedia,
			codecsJSON)
	}
	
	if err != nil {
		return fmt.Errorf("database write error: %w", err)
	}
	
	return nil
}

// SyncAllDatabaseToAsterisk syncs all database extensions to Asterisk
func (esm *ExtensionSyncManager) SyncAllDatabaseToAsterisk() (int, []error) {
	dbExts, err := esm.GetDatabaseExtensions()
	if err != nil {
		return 0, []error{err}
	}
	
	var errors []error
	synced := 0
	
	for _, ext := range dbExts {
		if err := esm.SyncDatabaseToAsterisk(ext.ExtensionNumber); err != nil {
			errors = append(errors, fmt.Errorf("ext %s: %w", ext.ExtensionNumber, err))
		} else {
			synced++
		}
	}
	
	return synced, errors
}

// SyncAllAsteriskToDatabase syncs all Asterisk extensions to database
func (esm *ExtensionSyncManager) SyncAllAsteriskToDatabase() (int, []error) {
	asteriskExts, err := esm.ParsePjsipConfig()
	if err != nil {
		return 0, []error{err}
	}
	
	var errors []error
	synced := 0
	
	for _, ext := range asteriskExts {
		if err := esm.SyncAsteriskToDatabase(ext.ExtensionNumber); err != nil {
			errors = append(errors, fmt.Errorf("ext %s: %w", ext.ExtensionNumber, err))
		} else {
			synced++
		}
	}
	
	return synced, errors
}

// RemoveFromAsterisk removes an extension from Asterisk config
func (esm *ExtensionSyncManager) RemoveFromAsterisk(extNumber string) error {
	return esm.asteriskConfigMgr.RemovePjsipConfig(fmt.Sprintf("Extension %s", extNumber))
}

// RemoveFromDatabase removes an extension from the database
func (esm *ExtensionSyncManager) RemoveFromDatabase(extNumber string) error {
	_, err := esm.db.Exec("DELETE FROM extensions WHERE extension_number = ?", extNumber)
	return err
}

// GetSyncSummary returns a summary of sync status
func (esm *ExtensionSyncManager) GetSyncSummary() (total, matched, dbOnly, astOnly, mismatched int, err error) {
	syncInfos, err := esm.CompareExtensions()
	if err != nil {
		return 0, 0, 0, 0, 0, err
	}
	
	total = len(syncInfos)
	for _, info := range syncInfos {
		switch info.SyncStatus {
		case SyncStatusMatch:
			matched++
		case SyncStatusDBOnly:
			dbOnly++
		case SyncStatusAsteriskOnly:
			astOnly++
		case SyncStatusMismatch:
			mismatched++
		}
	}
	
	return
}

// GetAllExtensionsCombined returns all extensions from both sources with sync info
func (esm *ExtensionSyncManager) GetAllExtensionsCombined() ([]ExtensionSyncInfo, error) {
	return esm.CompareExtensions()
}

// SyncConflict represents a sync conflict that requires user intervention
type SyncConflict struct {
	ExtensionNumber string
	Type           string   // "mismatch"
	Differences    []string
	DBExtension    *Extension
	AsteriskConfig *AsteriskExtension
}

// AutoSyncResult contains the results of an automatic sync operation
type AutoSyncResult struct {
	DBToAsteriskSynced    int
	AsteriskToDBSynced    int
	Conflicts             []SyncConflict
	Errors                []error
	TotalProcessed        int
	AlreadyInSync         int
}

// PerformAutoSync performs automatic bidirectional sync
// - Extensions only in DB are synced to Asterisk
// - Extensions only in Asterisk are synced to DB
// - Mismatched extensions are reported as conflicts for user resolution
func (esm *ExtensionSyncManager) PerformAutoSync() (*AutoSyncResult, error) {
	result := &AutoSyncResult{
		Conflicts: []SyncConflict{},
		Errors:    []error{},
	}
	
	// Get current sync status
	syncInfos, err := esm.CompareExtensions()
	if err != nil {
		return nil, fmt.Errorf("failed to compare extensions: %w", err)
	}
	
	result.TotalProcessed = len(syncInfos)
	
	for _, info := range syncInfos {
		switch info.SyncStatus {
		case SyncStatusMatch:
			// Already in sync, nothing to do
			result.AlreadyInSync++
			
		case SyncStatusDBOnly:
			// Extension only in DB - sync to Asterisk
			if err := esm.SyncDatabaseToAsterisk(info.ExtensionNumber); err != nil {
				result.Errors = append(result.Errors, fmt.Errorf("sync DB→Asterisk for %s: %w", info.ExtensionNumber, err))
			} else {
				result.DBToAsteriskSynced++
			}
			
		case SyncStatusAsteriskOnly:
			// Extension only in Asterisk - sync to DB
			if err := esm.SyncAsteriskToDatabase(info.ExtensionNumber); err != nil {
				result.Errors = append(result.Errors, fmt.Errorf("sync Asterisk→DB for %s: %w", info.ExtensionNumber, err))
			} else {
				result.AsteriskToDBSynced++
			}
			
		case SyncStatusMismatch:
			// Conflict - requires user intervention
			conflict := SyncConflict{
				ExtensionNumber: info.ExtensionNumber,
				Type:           "mismatch",
				Differences:    info.Differences,
				DBExtension:    info.DBExtension,
				AsteriskConfig: info.AsteriskConfig,
			}
			result.Conflicts = append(result.Conflicts, conflict)
		}
	}
	
	// Reload Asterisk if any changes were made
	if result.DBToAsteriskSynced > 0 {
		if esm.asteriskConfigMgr != nil {
			esm.asteriskConfigMgr.ReloadAsterisk()
		}
	}
	
	return result, nil
}

// HasConflicts returns true if there are unresolved conflicts
func (result *AutoSyncResult) HasConflicts() bool {
	return len(result.Conflicts) > 0
}

// Summary returns a human-readable summary of the sync result
func (result *AutoSyncResult) Summary() string {
	var sb strings.Builder
	
	sb.WriteString(fmt.Sprintf("Processed: %d extensions\n", result.TotalProcessed))
	sb.WriteString(fmt.Sprintf("Already synced: %d\n", result.AlreadyInSync))
	sb.WriteString(fmt.Sprintf("DB → Asterisk: %d synced\n", result.DBToAsteriskSynced))
	sb.WriteString(fmt.Sprintf("Asterisk → DB: %d synced\n", result.AsteriskToDBSynced))
	
	if len(result.Conflicts) > 0 {
		sb.WriteString(fmt.Sprintf("⚠️  Conflicts requiring attention: %d\n", len(result.Conflicts)))
		for _, c := range result.Conflicts {
			sb.WriteString(fmt.Sprintf("  - Extension %s: %s\n", c.ExtensionNumber, strings.Join(c.Differences, ", ")))
		}
	}
	
	if len(result.Errors) > 0 {
		sb.WriteString(fmt.Sprintf("Errors: %d\n", len(result.Errors)))
		for _, e := range result.Errors {
			sb.WriteString(fmt.Sprintf("  - %v\n", e))
		}
	}
	
	return sb.String()
}
