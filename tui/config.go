package main

import (
	"database/sql"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"github.com/common-nighthawk/go-figure"
	"github.com/fatih/color"
	_ "github.com/go-sql-driver/mysql"
	"github.com/joho/godotenv"
)

// Version is the application version - read from VERSION file
var Version = "2.0.0"

func init() {
	// Try to load version from VERSION file
	versionFile := filepath.Join(findRootPath(), "VERSION")
	if data, err := os.ReadFile(versionFile); err == nil {
		Version = strings.TrimSpace(string(data))
	}
}

// Config holds the application configuration
type Config struct {
	DBHost     string
	DBPort     string
	DBDatabase string
	DBUsername string
	DBPassword string
	APIBaseURL string
	JWTSecret  string
	AppEnv     string
	AppDebug   bool
}

// LoadConfig loads configuration from multiple .env file paths in priority order.
// Later paths override earlier ones:
// 1. /opt/rayanpbx/.env
// 2. /usr/local/rayanpbx/.env
// 3. /etc/rayanpbx/.env
// 4. <root of the project>/.env (found by looking for VERSION file)
// 5. <current working directory>/.env
func LoadConfig() (*Config, error) {
	// Define the paths to check in order (earlier paths loaded first, later override)
	envPaths := []string{
		"/opt/rayanpbx/.env",
		"/usr/local/rayanpbx/.env",
		"/etc/rayanpbx/.env",
	}
	
	// Add project root .env
	rootPath := findRootPath()
	rootEnvPath := filepath.Join(rootPath, ".env")
	envPaths = append(envPaths, rootEnvPath)
	
	// Add current directory .env
	currentDir, _ := os.Getwd()
	localEnvPath := filepath.Join(currentDir, ".env")
	envPaths = append(envPaths, localEnvPath)
	
	// Track which paths we've already loaded to avoid duplicates
	loadedPaths := make(map[string]bool)
	anyLoaded := false
	
	// Load each .env file in order
	for _, envPath := range envPaths {
		// Skip if we've already loaded this path
		if loadedPaths[envPath] {
			continue
		}
		
		// Check if file exists
		if _, err := os.Stat(envPath); err == nil {
			// Use Overload for all but the first file (Overload overwrites existing values)
			var loadErr error
			if anyLoaded {
				loadErr = godotenv.Overload(envPath)
			} else {
				loadErr = godotenv.Load(envPath)
			}
			
			if loadErr == nil {
				anyLoaded = true
				loadedPaths[envPath] = true
			} else {
				fmt.Fprintf(os.Stderr, "Warning: Failed to load .env file %s: %v\n", envPath, loadErr)
			}
		}
	}

	config := &Config{
		DBHost:     getEnv("DB_HOST", "127.0.0.1"),
		DBPort:     getEnv("DB_PORT", "3306"),
		DBDatabase: getEnv("DB_DATABASE", "rayanpbx"),
		DBUsername: getEnv("DB_USERNAME", "rayanpbx"),
		DBPassword: getEnv("DB_PASSWORD", ""),
		APIBaseURL: getEnv("API_BASE_URL", "http://localhost:8000"),
		JWTSecret:  getEnv("JWT_SECRET", ""),
		AppEnv:     getEnv("APP_ENV", "production"),
		AppDebug:   getEnv("APP_DEBUG", "false") == "true",
	}

	return config, nil
}

// findRootPath finds the root directory of the project by looking for .env file
func findRootPath() string {
	currentDir, _ := os.Getwd()

	// Look for .env file up to 3 levels up
	for i := 0; i < 3; i++ {
		envPath := filepath.Join(currentDir, ".env")
		versionPath := filepath.Join(currentDir, "VERSION")
		
		// Check if this looks like project root (has .env or VERSION file)
		if _, err := os.Stat(envPath); err == nil {
			return currentDir
		}
		if _, err := os.Stat(versionPath); err == nil {
			return currentDir
		}
		
		// Go up one level
		parentDir := filepath.Dir(currentDir)
		if parentDir == currentDir {
			// Reached filesystem root
			break
		}
		currentDir = parentDir
	}

	// Return current directory if root not found
	cwd, _ := os.Getwd()
	return cwd
}

// getEnv gets environment variable with default value
func getEnv(key, defaultValue string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return defaultValue
}

// ConnectDB connects to MySQL database
func ConnectDB(config *Config) (*sql.DB, error) {
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=true",
		config.DBUsername,
		config.DBPassword,
		config.DBHost,
		config.DBPort,
		config.DBDatabase,
	)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		return nil, err
	}

	if err := db.Ping(); err != nil {
		return nil, err
	}

	return db, nil
}

// PrintBanner prints a beautiful banner
func PrintBanner() {
	// Create figlet text
	myFigure := figure.NewFigure("RayanPBX", "slant", true)

	// Print with gradient colors
	cyan := color.New(color.FgCyan, color.Bold)
	magenta := color.New(color.FgMagenta, color.Bold)

	lines := strings.Split(myFigure.String(), "\n")
	for i, line := range lines {
		if i%2 == 0 {
			cyan.Println(line)
		} else {
			magenta.Println(line)
		}
	}

	// Subtitle with version
	yellow := color.New(color.FgYellow)
	green := color.New(color.FgGreen)
	yellow.Print("    🚀 Modern SIP Server Management Toolkit 🚀")
	green.Printf(" v%s\n", Version)
	fmt.Println()
}

// Extension represents a SIP extension
type Extension struct {
	ID               int
	ExtensionNumber  string
	Name             string
	Secret           string
	Email            string
	Enabled          bool
	Context          string
	Transport        string
	CallerID         string
	MaxContacts      int
	VoicemailEnabled bool
	Codecs           string // Comma-separated list of codecs (e.g., "ulaw,alaw,g722")
	DirectMedia      string // "yes" or "no"
	QualifyFrequency int    // Seconds between qualify checks
	CreatedAt        string
	UpdatedAt        string
}

// Trunk represents a SIP trunk
type Trunk struct {
	ID       int
	Name     string
	Host     string
	Port     int
	Enabled  bool
	Priority int
}

// GetExtensions fetches extensions from database including advanced PJSIP options
// Note: codecs are stored as JSON in the database but handled as comma-separated string in TUI
func GetExtensions(db *sql.DB) ([]Extension, error) {
	query := `SELECT id, extension_number, name, COALESCE(secret, ''), COALESCE(email, ''), 
	          enabled, COALESCE(context, 'from-internal'), COALESCE(transport, 'transport-udp'), 
	          COALESCE(caller_id, ''), COALESCE(max_contacts, 1), COALESCE(voicemail_enabled, 0),
	          COALESCE(codecs, '["ulaw","alaw","g722"]'), COALESCE(direct_media, 'no'), COALESCE(qualify_frequency, 60)
	          FROM extensions ORDER BY extension_number`
	rows, err := db.Query(query)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var extensions []Extension
	for rows.Next() {
		var ext Extension
		var codecsJSON string
		if err := rows.Scan(&ext.ID, &ext.ExtensionNumber, &ext.Name, &ext.Secret, &ext.Email,
			&ext.Enabled, &ext.Context, &ext.Transport, &ext.CallerID, &ext.MaxContacts, &ext.VoicemailEnabled,
			&codecsJSON, &ext.DirectMedia, &ext.QualifyFrequency); err != nil {
			continue
		}
		// Convert JSON array to comma-separated string for TUI display
		ext.Codecs = parseCodecsJSON(codecsJSON)
		extensions = append(extensions, ext)
	}

	return extensions, nil
}

// parseCodecsJSON converts a JSON array of codecs to a comma-separated string
func parseCodecsJSON(codecsJSON string) string {
	// If it's already a comma-separated string (old format), return as-is
	if !strings.HasPrefix(codecsJSON, "[") {
		return codecsJSON
	}
	
	// Parse JSON array
	codecsJSON = strings.TrimPrefix(codecsJSON, "[")
	codecsJSON = strings.TrimSuffix(codecsJSON, "]")
	codecsJSON = strings.ReplaceAll(codecsJSON, "\"", "")
	codecsJSON = strings.ReplaceAll(codecsJSON, " ", "")
	return codecsJSON
}

// GetTrunks fetches trunks from database
func GetTrunks(db *sql.DB) ([]Trunk, error) {
	query := "SELECT id, name, host, port, enabled, priority FROM trunks ORDER BY priority"
	rows, err := db.Query(query)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var trunks []Trunk
	for rows.Next() {
		var trunk Trunk
		if err := rows.Scan(&trunk.ID, &trunk.Name, &trunk.Host, &trunk.Port, &trunk.Enabled, &trunk.Priority); err != nil {
			continue
		}
		trunks = append(trunks, trunk)
	}

	return trunks, nil
}

// PrintExtensions displays extensions in a beautiful table
func PrintExtensions(extensions []Extension) {
	if len(extensions) == 0 {
		yellow := color.New(color.FgYellow)
		yellow.Println("📭 No extensions configured")
		return
	}

	cyan := color.New(color.FgCyan, color.Bold)
	green := color.New(color.FgGreen)
	red := color.New(color.FgRed)

	cyan.Println("\n📱 Extensions:")
	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
	fmt.Printf("%-15s %-25s %-10s\n", "Number", "Name", "Status")
	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")

	for _, ext := range extensions {
		status := "🔴 Disabled"
		if ext.Enabled {
			status = "🟢 Enabled"
		}

		fmt.Printf("%-15s ", ext.ExtensionNumber)
		fmt.Printf("%-25s ", ext.Name)

		if ext.Enabled {
			green.Printf("%-10s\n", status)
		} else {
			red.Printf("%-10s\n", status)
		}
	}

	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
	fmt.Printf("Total: %d extensions\n\n", len(extensions))
}

// PrintTrunks displays trunks in a beautiful table
func PrintTrunks(trunks []Trunk) {
	if len(trunks) == 0 {
		yellow := color.New(color.FgYellow)
		yellow.Println("📭 No trunks configured")
		return
	}

	cyan := color.New(color.FgCyan, color.Bold)
	green := color.New(color.FgGreen)
	red := color.New(color.FgRed)

	cyan.Println("\n🔗 Trunks:")
	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
	fmt.Printf("%-15s %-30s %-10s %-10s\n", "Name", "Host", "Priority", "Status")
	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")

	for _, trunk := range trunks {
		status := "🔴 Disabled"
		if trunk.Enabled {
			status = "🟢 Enabled"
		}

		hostPort := fmt.Sprintf("%s:%d", trunk.Host, trunk.Port)

		fmt.Printf("%-15s ", trunk.Name)
		fmt.Printf("%-30s ", hostPort)
		fmt.Printf("%-10d ", trunk.Priority)

		if trunk.Enabled {
			green.Printf("%-10s\n", status)
		} else {
			red.Printf("%-10s\n", status)
		}
	}

	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
	fmt.Printf("Total: %d trunks\n\n", len(trunks))
}

// PrintSystemStatus displays system status
func PrintSystemStatus(db *sql.DB) {
	cyan := color.New(color.FgCyan, color.Bold)
	green := color.New(color.FgGreen)

	cyan.Println("\n📊 System Status:")
	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")

	// Database status
	if err := db.Ping(); err == nil {
		green.Println("✅ Database: Connected")
	} else {
		red := color.New(color.FgRed)
		red.Println("❌ Database: Disconnected")
	}

	// Get counts
	var extCount, trunkCount int
	db.QueryRow("SELECT COUNT(*) FROM extensions WHERE enabled = 1").Scan(&extCount)
	db.QueryRow("SELECT COUNT(*) FROM trunks WHERE enabled = 1").Scan(&trunkCount)

	green.Printf("📱 Active Extensions: %d\n", extCount)
	green.Printf("🔗 Active Trunks: %d\n", trunkCount)

	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
	fmt.Println()
}
