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
}

// LoadConfig loads configuration from root .env file
func LoadConfig() (*Config, error) {
	// Find root .env file
	rootPath := findRootPath()
	envPath := filepath.Join(rootPath, ".env")

	// Load .env file
	if err := godotenv.Load(envPath); err != nil {
		// Try local .env if root not found
		godotenv.Load()
	}

	config := &Config{
		DBHost:     getEnv("DB_HOST", "127.0.0.1"),
		DBPort:     getEnv("DB_PORT", "3306"),
		DBDatabase: getEnv("DB_DATABASE", "rayanpbx"),
		DBUsername: getEnv("DB_USERNAME", "rayanpbx"),
		DBPassword: getEnv("DB_PASSWORD", ""),
		APIBaseURL: getEnv("API_BASE_URL", "http://localhost:8000"),
		JWTSecret:  getEnv("JWT_SECRET", ""),
	}

	return config, nil
}

// findRootPath finds the root directory of the project
func findRootPath() string {
	currentDir, _ := os.Getwd()
	
	// Look for .env file up to 3 levels up
	for i := 0; i < 3; i++ {
		envPath := filepath.Join(currentDir, ".env")
		if _, err := os.Stat(envPath); err == nil {
			return currentDir
		}
		currentDir = filepath.Dir(currentDir)
	}
	
	return "."
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
	ID              int
	ExtensionNumber string
	Name            string
	Enabled         bool
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

// GetExtensions fetches extensions from database
func GetExtensions(db *sql.DB) ([]Extension, error) {
	query := "SELECT id, extension_number, name, enabled FROM extensions ORDER BY extension_number"
	rows, err := db.Query(query)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var extensions []Extension
	for rows.Next() {
		var ext Extension
		if err := rows.Scan(&ext.ID, &ext.ExtensionNumber, &ext.Name, &ext.Enabled); err != nil {
			continue
		}
		extensions = append(extensions, ext)
	}

	return extensions, nil
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
	
	fmt.Println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n")
}
