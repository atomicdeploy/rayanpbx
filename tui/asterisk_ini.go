package main

import (
	"bufio"
	"fmt"
	"os"
	"regexp"
	"strings"
)

// AsteriskSection represents a section in an Asterisk configuration file
// In Asterisk configs, multiple sections can have the same name but different types
// (e.g., [101] for endpoint, auth, and aor)
//
// Sections can be in one of two states:
// - Active (Commented=false): Section is enabled and parsed by Asterisk
// - Commented (Commented=true): Section is disabled (all lines prefixed with ;)
//
// Body comments within a section are preserved in the BodyComments field.
type AsteriskSection struct {
	Name         string            // Section name (e.g., "101", "transport-udp")
	Type         string            // Section type from type= key (e.g., "endpoint", "auth", "aor", "transport")
	Properties   map[string]string // Key-value pairs in order
	Keys         []string          // Keys in order (for preserving insertion order)
	Comments     []string          // Comments associated with this section (preceding lines starting with ;)
	BodyComments []string          // Comments within the section body (lines starting with ; between properties)
	Commented    bool              // Whether this section is commented out (disabled)
}

// AsteriskConfig represents an Asterisk configuration file
type AsteriskConfig struct {
	Sections    []*AsteriskSection // All sections in order
	HeaderLines []string           // Lines before the first section (header comments)
	FilePath    string
}

// NewAsteriskSection creates a new section with the given name and type
func NewAsteriskSection(name, sectionType string) *AsteriskSection {
	return &AsteriskSection{
		Name:         name,
		Type:         sectionType,
		Properties:   make(map[string]string),
		Keys:         []string{},
		Comments:     []string{},
		BodyComments: []string{},
		Commented:    false,
	}
}

// SetProperty sets a property value (maintains order for new keys)
func (s *AsteriskSection) SetProperty(key, value string) {
	if _, exists := s.Properties[key]; !exists {
		s.Keys = append(s.Keys, key)
	}
	s.Properties[key] = value
}

// GetProperty gets a property value
func (s *AsteriskSection) GetProperty(key string) (string, bool) {
	val, ok := s.Properties[key]
	return val, ok
}

// String renders the section as a config string
// If the section is marked as Commented, all lines are prefixed with ';'
func (s *AsteriskSection) String() string {
	var sb strings.Builder
	prefix := ""
	if s.Commented {
		prefix = ";"
	}

	// Write comments (these are always preserved as-is, not double-commented)
	for _, comment := range s.Comments {
		sb.WriteString(comment)
		sb.WriteString("\n")
	}

	// Write section header
	sb.WriteString(fmt.Sprintf("%s[%s]\n", prefix, s.Name))

	// Write properties in order
	for _, key := range s.Keys {
		if val, ok := s.Properties[key]; ok {
			sb.WriteString(fmt.Sprintf("%s%s=%s\n", prefix, key, val))
		}
	}

	// Write body comments (preserved for round-trip)
	for _, comment := range s.BodyComments {
		sb.WriteString(comment)
		sb.WriteString("\n")
	}

	return sb.String()
}

// ParseAsteriskConfig parses an Asterisk configuration file
func ParseAsteriskConfig(filePath string) (*AsteriskConfig, error) {
	content, err := os.ReadFile(filePath)
	if err != nil {
		return nil, err
	}

	return ParseAsteriskConfigContent(string(content), filePath)
}

// ParseAsteriskConfigContent parses Asterisk config from string content
// Supports both active and commented-out sections (prefixed with ;)
func ParseAsteriskConfigContent(content string, filePath string) (*AsteriskConfig, error) {
	config := &AsteriskConfig{
		Sections:    []*AsteriskSection{},
		HeaderLines: []string{},
		FilePath:    filePath,
	}

	scanner := bufio.NewScanner(strings.NewReader(content))
	// Match both regular and commented section headers: [name] or ;[name]
	sectionRegex := regexp.MustCompile(`^\s*;?\s*\[([^\]]+)\]`)
	commentedSectionRegex := regexp.MustCompile(`^\s*;\s*\[([^\]]+)\]`)
	kvRegex := regexp.MustCompile(`^\s*([^=;\s]+)\s*=\s*(.*)$`)
	// Match commented key=value lines: ;key=value
	commentedKvRegex := regexp.MustCompile(`^\s*;\s*([^=;\s]+)\s*=\s*(.*)$`)

	var currentSection *AsteriskSection
	var pendingComments []string
	inHeader := true

	for scanner.Scan() {
		line := scanner.Text()
		trimmedLine := strings.TrimSpace(line)

		// Check for section header (both active and commented)
		if matches := sectionRegex.FindStringSubmatch(line); matches != nil {
			// Save current section if any
			if currentSection != nil {
				config.Sections = append(config.Sections, currentSection)
			}

			// Start new section
			sectionName := matches[1]
			currentSection = NewAsteriskSection(sectionName, "")
			currentSection.Comments = pendingComments
			pendingComments = []string{}
			inHeader = false

			// Check if this section is commented out
			if commentedSectionRegex.MatchString(line) {
				currentSection.Commented = true
			}
			continue
		}

		// Check for key=value (active section)
		if matches := kvRegex.FindStringSubmatch(line); matches != nil && currentSection != nil && !currentSection.Commented {
			key := strings.TrimSpace(matches[1])
			value := strings.TrimSpace(matches[2])

			// If it's a type key, set the section type
			if key == "type" {
				currentSection.Type = value
			}

			currentSection.SetProperty(key, value)
			continue
		}

		// Check for commented key=value (for commented sections)
		if matches := commentedKvRegex.FindStringSubmatch(line); matches != nil && currentSection != nil && currentSection.Commented {
			key := strings.TrimSpace(matches[1])
			value := strings.TrimSpace(matches[2])

			// If it's a type key, set the section type
			if key == "type" {
				currentSection.Type = value
			}

			currentSection.SetProperty(key, value)
			continue
		}

		// Handle comments and blank lines
		if strings.HasPrefix(trimmedLine, ";") || trimmedLine == "" {
			if inHeader {
				config.HeaderLines = append(config.HeaderLines, line)
			} else if currentSection == nil {
				// Comments before any section after header
				pendingComments = append(pendingComments, line)
			} else if currentSection != nil {
				// Body comments within a section (preserve them for all sections)
				// For active sections, these are comments between properties
				// For commented sections, these are non-property comment lines
				currentSection.BodyComments = append(currentSection.BodyComments, line)
			}
			continue
		}
	}

	// Don't forget the last section
	if currentSection != nil {
		config.Sections = append(config.Sections, currentSection)
	}

	return config, nil
}

// FindSectionsByName finds all sections with a given name
func (c *AsteriskConfig) FindSectionsByName(name string) []*AsteriskSection {
	var result []*AsteriskSection
	for _, section := range c.Sections {
		if section.Name == name {
			result = append(result, section)
		}
	}
	return result
}

// FindSectionByNameAndType finds a section with a specific name and type
func (c *AsteriskConfig) FindSectionByNameAndType(name, sectionType string) *AsteriskSection {
	for _, section := range c.Sections {
		if section.Name == name && section.Type == sectionType {
			return section
		}
	}
	return nil
}

// RemoveSectionsByName removes all sections with a given name
func (c *AsteriskConfig) RemoveSectionsByName(name string) int {
	var newSections []*AsteriskSection
	removed := 0
	for _, section := range c.Sections {
		if section.Name == name {
			removed++
		} else {
			newSections = append(newSections, section)
		}
	}
	c.Sections = newSections
	return removed
}

// RemoveSectionByNameAndType removes a specific section by name and type
func (c *AsteriskConfig) RemoveSectionByNameAndType(name, sectionType string) bool {
	var newSections []*AsteriskSection
	removed := false
	for _, section := range c.Sections {
		if section.Name == name && section.Type == sectionType {
			removed = true
		} else {
			newSections = append(newSections, section)
		}
	}
	c.Sections = newSections
	return removed
}

// AddSection adds a section to the configuration
func (c *AsteriskConfig) AddSection(section *AsteriskSection) {
	c.Sections = append(c.Sections, section)
}

// AddOrReplaceSection adds a section or replaces an existing one with the same name and type
func (c *AsteriskConfig) AddOrReplaceSection(section *AsteriskSection) {
	for i, s := range c.Sections {
		if s.Name == section.Name && s.Type == section.Type {
			c.Sections[i] = section
			return
		}
	}
	c.Sections = append(c.Sections, section)
}

// String renders the entire configuration as a string
func (c *AsteriskConfig) String() string {
	var sb strings.Builder

	// Write header lines
	for _, line := range c.HeaderLines {
		sb.WriteString(line)
		sb.WriteString("\n")
	}

	// Add extra newline after header if there are sections
	if len(c.HeaderLines) > 0 && len(c.Sections) > 0 {
		sb.WriteString("\n")
	}

	// Write sections
	for i, section := range c.Sections {
		sb.WriteString(section.String())
		// Add blank line between sections
		if i < len(c.Sections)-1 {
			sb.WriteString("\n")
		}
	}

	return sb.String()
}

// Save writes the configuration to the file
func (c *AsteriskConfig) Save() error {
	return os.WriteFile(c.FilePath, []byte(c.String()), 0644)
}

// SaveTo writes the configuration to a specific file
func (c *AsteriskConfig) SaveTo(filePath string) error {
	return os.WriteFile(filePath, []byte(c.String()), 0644)
}

// HasSection checks if a section with the given name exists
func (c *AsteriskConfig) HasSection(name string) bool {
	for _, section := range c.Sections {
		if section.Name == name {
			return true
		}
	}
	return false
}

// HasSectionWithType checks if a section with the given name and type exists
func (c *AsteriskConfig) HasSectionWithType(name, sectionType string) bool {
	for _, section := range c.Sections {
		if section.Name == name && section.Type == sectionType {
			return true
		}
	}
	return false
}

// FindActiveSectionsByName finds all active (non-commented) sections with a given name
func (c *AsteriskConfig) FindActiveSectionsByName(name string) []*AsteriskSection {
	var result []*AsteriskSection
	for _, section := range c.Sections {
		if section.Name == name && !section.Commented {
			result = append(result, section)
		}
	}
	return result
}

// FindCommentedSectionsByName finds all commented-out sections with a given name
func (c *AsteriskConfig) FindCommentedSectionsByName(name string) []*AsteriskSection {
	var result []*AsteriskSection
	for _, section := range c.Sections {
		if section.Name == name && section.Commented {
			result = append(result, section)
		}
	}
	return result
}

// CommentOutSectionsByName comments out all sections with a given name
// Returns the number of sections that were commented out
func (c *AsteriskConfig) CommentOutSectionsByName(name string) int {
	count := 0
	for _, section := range c.Sections {
		if section.Name == name && !section.Commented {
			section.Commented = true
			count++
		}
	}
	return count
}

// UncommentSectionsByName uncomments all sections with a given name
// Returns the number of sections that were uncommented
func (c *AsteriskConfig) UncommentSectionsByName(name string) int {
	count := 0
	for _, section := range c.Sections {
		if section.Name == name && section.Commented {
			section.Commented = false
			count++
		}
	}
	return count
}

// RemoveCommentedSectionsByName removes only commented-out sections with a given name
// Active (uncommented) sections are preserved
// Returns the number of sections removed
func (c *AsteriskConfig) RemoveCommentedSectionsByName(name string) int {
	var newSections []*AsteriskSection
	removed := 0
	for _, section := range c.Sections {
		if section.Name == name && section.Commented {
			removed++
		} else {
			newSections = append(newSections, section)
		}
	}
	c.Sections = newSections
	return removed
}

// RemoveActiveSectionsByName removes only active (non-commented) sections with a given name
// Commented sections are preserved
// Returns the number of sections removed
func (c *AsteriskConfig) RemoveActiveSectionsByName(name string) int {
	var newSections []*AsteriskSection
	removed := 0
	for _, section := range c.Sections {
		if section.Name == name && !section.Commented {
			removed++
		} else {
			newSections = append(newSections, section)
		}
	}
	c.Sections = newSections
	return removed
}

// HasActiveSection checks if an active (non-commented) section with the given name exists
func (c *AsteriskConfig) HasActiveSection(name string) bool {
	for _, section := range c.Sections {
		if section.Name == name && !section.Commented {
			return true
		}
	}
	return false
}

// HasCommentedSection checks if a commented-out section with the given name exists
func (c *AsteriskConfig) HasCommentedSection(name string) bool {
	for _, section := range c.Sections {
		if section.Name == name && section.Commented {
			return true
		}
	}
	return false
}

// FindSectionsForExtension finds all sections that belong to an extension,
// including those with alternative naming patterns like [101-auth] or [auth101].
// This helps identify sections that should be managed together.
func (c *AsteriskConfig) FindSectionsForExtension(extNumber string) []*AsteriskSection {
	var result []*AsteriskSection
	
	// Get all possible naming patterns for this extension
	patterns := getExtensionSectionPatterns(extNumber)
	
	for _, section := range c.Sections {
		for _, pattern := range patterns {
			if section.Name == pattern {
				result = append(result, section)
				break
			}
		}
	}
	
	return result
}

// RemoveSectionsForExtension removes all sections that belong to an extension,
// including those with alternative naming patterns like [101-auth] or [auth101].
// Returns the number of sections removed.
func (c *AsteriskConfig) RemoveSectionsForExtension(extNumber string) int {
	// Get all possible naming patterns and build a lookup map
	patterns := make(map[string]bool)
	for _, pattern := range getExtensionSectionPatterns(extNumber) {
		patterns[pattern] = true
	}
	
	var newSections []*AsteriskSection
	removed := 0
	
	for _, section := range c.Sections {
		if patterns[section.Name] {
			removed++
		} else {
			newSections = append(newSections, section)
		}
	}
	
	c.Sections = newSections
	return removed
}

// CreatePjsipEndpointSections creates the three sections needed for a PJSIP endpoint
// Returns endpoint, auth, and aor sections
func CreatePjsipEndpointSections(extNumber, secret, context, transport string, codecs []string, directMedia string, callerID string, maxContacts int, qualifyFrequency int, voicemailEnabled bool) []*AsteriskSection {
	sections := make([]*AsteriskSection, 0, 3)

	// Endpoint section
	endpoint := NewAsteriskSection(extNumber, "endpoint")
	endpoint.SetProperty("type", "endpoint")
	endpoint.SetProperty("context", context)
	endpoint.SetProperty("disallow", "all")

	// Add codecs in order
	for _, codec := range codecs {
		codec = strings.TrimSpace(codec)
		if codec != "" {
			endpoint.SetProperty("allow", codec)
		}
	}

	endpoint.SetProperty("transport", transport)
	endpoint.SetProperty("auth", extNumber)
	endpoint.SetProperty("aors", extNumber)
	endpoint.SetProperty("direct_media", directMedia)

	if callerID != "" {
		endpoint.SetProperty("callerid", callerID)
	}

	if voicemailEnabled {
		endpoint.SetProperty("mailboxes", fmt.Sprintf("%s@default", extNumber))
	}

	// SIP Presence and Device State support
	endpoint.SetProperty("subscribe_context", context)
	endpoint.SetProperty("device_state_busy_at", "1")

	sections = append(sections, endpoint)

	// Auth section
	auth := NewAsteriskSection(extNumber, "auth")
	auth.SetProperty("type", "auth")
	auth.SetProperty("auth_type", "userpass")
	auth.SetProperty("username", extNumber)
	auth.SetProperty("password", secret)

	sections = append(sections, auth)

	// AOR section
	aor := NewAsteriskSection(extNumber, "aor")
	aor.SetProperty("type", "aor")
	aor.SetProperty("max_contacts", fmt.Sprintf("%d", maxContacts))
	aor.SetProperty("remove_existing", "yes")
	aor.SetProperty("qualify_frequency", fmt.Sprintf("%d", qualifyFrequency))
	aor.SetProperty("support_outbound", "yes")

	sections = append(sections, aor)

	return sections
}

// CreateTransportSections creates transport sections for UDP and TCP
func CreateTransportSections() []*AsteriskSection {
	sections := make([]*AsteriskSection, 0, 2)

	// UDP Transport
	udp := NewAsteriskSection("transport-udp", "transport")
	udp.Comments = []string{"; RayanPBX SIP Transports Configuration"}
	udp.SetProperty("type", "transport")
	udp.SetProperty("protocol", "udp")
	udp.SetProperty("bind", "0.0.0.0:5060")
	udp.SetProperty("allow_reload", "yes")

	sections = append(sections, udp)

	// TCP Transport
	tcp := NewAsteriskSection("transport-tcp", "transport")
	tcp.SetProperty("type", "transport")
	tcp.SetProperty("protocol", "tcp")
	tcp.SetProperty("bind", "0.0.0.0:5060")
	tcp.SetProperty("allow_reload", "yes")

	sections = append(sections, tcp)

	return sections
}
