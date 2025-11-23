//go:build ignore
// +build ignore

package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"sync"
	"time"

	"github.com/common-nighthawk/go-figure"
	"github.com/fatih/color"
	_ "github.com/go-sql-driver/mysql"
	"github.com/golang-jwt/jwt/v5"
	"github.com/gorilla/websocket"
	"github.com/joho/godotenv"
)

var upgrader = websocket.Upgrader{
	CheckOrigin: func(r *http.Request) bool {
		// Allow all origins in development
		// TODO: Restrict in production
		return true
	},
}

type Client struct {
	conn     *websocket.Conn
	send     chan []byte
	userID   string
	username string
}

type Hub struct {
	clients    map[*Client]bool
	broadcast  chan []byte
	register   chan *Client
	unregister chan *Client
	mu         sync.RWMutex
}

type Message struct {
	Type      string                 `json:"type"`
	Payload   map[string]interface{} `json:"payload"`
	Timestamp time.Time              `json:"timestamp"`
}

func newHub() *Hub {
	return &Hub{
		broadcast:  make(chan []byte),
		register:   make(chan *Client),
		unregister: make(chan *Client),
		clients:    make(map[*Client]bool),
	}
}

func (h *Hub) run() {
	green := color.New(color.FgGreen)
	yellow := color.New(color.FgYellow)

	for {
		select {
		case client := <-h.register:
			h.mu.Lock()
			h.clients[client] = true
			h.mu.Unlock()
			green.Printf("✅ Client connected: %s (Total: %d)\n", client.username, len(h.clients))

		case client := <-h.unregister:
			h.mu.Lock()
			if _, ok := h.clients[client]; ok {
				delete(h.clients, client)
				close(client.send)
			}
			h.mu.Unlock()
			yellow.Printf("👋 Client disconnected: %s (Total: %d)\n", client.username, len(h.clients))

		case message := <-h.broadcast:
			h.mu.RLock()
			for client := range h.clients {
				select {
				case client.send <- message:
				default:
					close(client.send)
					delete(h.clients, client)
				}
			}
			h.mu.RUnlock()
		}
	}
}

func (c *Client) readPump(hub *Hub) {
	defer func() {
		hub.unregister <- c
		c.conn.Close()
	}()

	c.conn.SetReadDeadline(time.Now().Add(60 * time.Second))
	c.conn.SetPongHandler(func(string) error {
		c.conn.SetReadDeadline(time.Now().Add(60 * time.Second))
		return nil
	})

	for {
		_, message, err := c.conn.ReadMessage()
		if err != nil {
			if websocket.IsUnexpectedCloseError(err, websocket.CloseGoingAway, websocket.CloseAbnormalClosure) {
				log.Printf("error: %v", err)
			}
			break
		}

		// Echo message back or handle it
		hub.broadcast <- message
	}
}

func (c *Client) writePump() {
	ticker := time.NewTicker(54 * time.Second)
	defer func() {
		ticker.Stop()
		c.conn.Close()
	}()

	for {
		select {
		case message, ok := <-c.send:
			c.conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			if !ok {
				c.conn.WriteMessage(websocket.CloseMessage, []byte{})
				return
			}

			w, err := c.conn.NextWriter(websocket.TextMessage)
			if err != nil {
				return
			}
			w.Write(message)

			// Add queued messages
			n := len(c.send)
			for i := 0; i < n; i++ {
				w.Write([]byte{'\n'})
				w.Write(<-c.send)
			}

			if err := w.Close(); err != nil {
				return
			}

		case <-ticker.C:
			c.conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			if err := c.conn.WriteMessage(websocket.PingMessage, nil); err != nil {
				return
			}
		}
	}
}

func serveWs(hub *Hub, jwtSecret string) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		// Extract JWT token from query params, cookie, or header
		tokenString := r.URL.Query().Get("token")
		if tokenString == "" {
			cookie, err := r.Cookie("rayanpbx_token")
			if err == nil {
				tokenString = cookie.Value
			}
		}
		if tokenString == "" {
			authHeader := r.Header.Get("Authorization")
			if len(authHeader) > 7 && authHeader[:7] == "Bearer " {
				tokenString = authHeader[7:]
			}
		}

		if tokenString == "" {
			http.Error(w, "Unauthorized: No token provided", http.StatusUnauthorized)
			return
		}

		// Verify JWT token
		token, err := jwt.Parse(tokenString, func(token *jwt.Token) (interface{}, error) {
			if _, ok := token.Method.(*jwt.SigningMethodHMAC); !ok {
				return nil, fmt.Errorf("unexpected signing method")
			}
			return []byte(jwtSecret), nil
		})

		if err != nil || !token.Valid {
			http.Error(w, "Unauthorized: Invalid token", http.StatusUnauthorized)
			return
		}

		claims, ok := token.Claims.(jwt.MapClaims)
		if !ok {
			http.Error(w, "Unauthorized: Invalid claims", http.StatusUnauthorized)
			return
		}

		userClaims, ok := claims["user"].(map[string]interface{})
		if !ok {
			http.Error(w, "Unauthorized: Invalid user claims", http.StatusUnauthorized)
			return
		}

		username := userClaims["name"].(string)

		// Upgrade to WebSocket
		conn, err := upgrader.Upgrade(w, r, nil)
		if err != nil {
			log.Println(err)
			return
		}

		client := &Client{
			conn:     conn,
			send:     make(chan []byte, 256),
			username: username,
		}

		hub.register <- client

		// Send welcome message
		welcomeMsg := Message{
			Type: "welcome",
			Payload: map[string]interface{}{
				"message": "Connected to RayanPBX WebSocket",
				"user":    username,
			},
			Timestamp: time.Now(),
		}
		msgJSON, _ := json.Marshal(welcomeMsg)
		client.send <- msgJSON

		go client.writePump()
		go client.readPump(hub)
	}
}

// MonitorDatabase monitors database for changes and broadcasts events
func monitorDatabase(hub *Hub, db *sql.DB) {
	cyan := color.New(color.FgCyan)
	cyan.Println("📊 Starting database monitor...")

	ticker := time.NewTicker(2 * time.Second)
	defer ticker.Stop()

	var lastExtCount, lastTrunkCount int

	for range ticker.C {
		var extCount, trunkCount int
		db.QueryRow("SELECT COUNT(*) FROM extensions WHERE enabled = 1").Scan(&extCount)
		db.QueryRow("SELECT COUNT(*) FROM trunks WHERE enabled = 1").Scan(&trunkCount)

		// Broadcast if changes detected
		if extCount != lastExtCount || trunkCount != lastTrunkCount {
			msg := Message{
				Type: "status_update",
				Payload: map[string]interface{}{
					"extensions": extCount,
					"trunks":     trunkCount,
				},
				Timestamp: time.Now(),
			}
			msgJSON, _ := json.Marshal(msg)
			hub.broadcast <- msgJSON

			lastExtCount = extCount
			lastTrunkCount = trunkCount
		}
	}
}

func loadConfig() (string, string, string, error) {
	// Load .env files from multiple paths in priority order
	// Later paths override earlier ones:
	// 1. /opt/rayanpbx/.env
	// 2. /usr/local/rayanpbx/.env
	// 3. /etc/rayanpbx/.env
	// 4. <root of the project>/.env (found by looking for VERSION file)
	// 5. <current working directory>/.env
	envPaths := []string{
		"/opt/rayanpbx/.env",
		"/usr/local/rayanpbx/.env",
		"/etc/rayanpbx/.env",
	}
	
	// Add project root .env
	currentDir, _ := os.Getwd()
	for i := 0; i < 3; i++ {
		envPath := filepath.Join(currentDir, ".env")
		versionPath := filepath.Join(currentDir, "VERSION")
		
		if _, err := os.Stat(envPath); err == nil {
			envPaths = append(envPaths, envPath)
			break
		}
		if _, err := os.Stat(versionPath); err == nil {
			envPaths = append(envPaths, filepath.Join(currentDir, ".env"))
			break
		}
		
		parentDir := filepath.Dir(currentDir)
		if parentDir == currentDir {
			break
		}
		currentDir = parentDir
	}
	
	// Add current directory .env
	cwd, _ := os.Getwd()
	localEnvPath := filepath.Join(cwd, ".env")
	envPaths = append(envPaths, localEnvPath)
	
	// Track loaded paths to avoid duplicates
	loadedPaths := make(map[string]bool)
	anyLoaded := false
	
	// Load each .env file in order
	for _, envPath := range envPaths {
		if loadedPaths[envPath] {
			continue
		}
		
		if _, err := os.Stat(envPath); err == nil {
			var loadErr error
			if anyLoaded {
				loadErr = godotenv.Overload(envPath)
			} else {
				loadErr = godotenv.Load(envPath)
			}
			
			if loadErr == nil {
				anyLoaded = true
				loadedPaths[envPath] = true
			}
		}
	}

	wsHost := getEnv("WEBSOCKET_HOST", "0.0.0.0")
	wsPort := getEnv("WEBSOCKET_PORT", "9000")
	jwtSecret := getEnv("JWT_SECRET", "your-super-secret-jwt-key-change-this")

	return wsHost, wsPort, jwtSecret, nil
}

func printBanner() {
	myFigure := figure.NewFigure("WebSocket Server", "slant", true)
	cyan := color.New(color.FgCyan, color.Bold)
	magenta := color.New(color.FgMagenta, color.Bold)

	lines := myFigure.Slicify()
	for i, line := range lines {
		if i%2 == 0 {
			cyan.Println(line)
		} else {
			magenta.Println(line)
		}
	}

	yellow := color.New(color.FgYellow)
	yellow.Println("    🚀 RayanPBX Real-time Event System 🚀")
	fmt.Println()
}

func main() {
	printBanner()

	green := color.New(color.FgGreen)
	cyan := color.New(color.FgCyan)
	red := color.New(color.FgRed)

	// Load configuration
	cyan.Println("🔧 Loading configuration...")
	wsHost, wsPort, jwtSecret, err := loadConfig()
	if err != nil {
		red.Printf("❌ Failed to load configuration: %v\n", err)
		os.Exit(1)
	}
	green.Println("✅ Configuration loaded")

	// Connect to database for monitoring
	cyan.Println("🔌 Connecting to database...")
	config, _ := LoadConfig()
	db, err := ConnectDB(config)
	if err != nil {
		red.Printf("❌ Failed to connect to database: %v\n", err)
		os.Exit(1)
	}
	defer db.Close()
	green.Println("✅ Database connected")

	// Create hub
	hub := newHub()
	go hub.run()

	// Start database monitor
	go monitorDatabase(hub, db)

	// Setup HTTP routes
	http.HandleFunc("/ws", serveWs(hub, jwtSecret))
	http.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]interface{}{
			"status":  "healthy",
			"clients": len(hub.clients),
		})
	})

	addr := fmt.Sprintf("%s:%s", wsHost, wsPort)
	green.Printf("🚀 WebSocket server starting on ws://%s/ws\n", addr)
	green.Printf("💚 Health endpoint: http://%s/health\n", addr)
	fmt.Println()
	cyan.Println("📡 Waiting for connections...")
	fmt.Println()

	if err := http.ListenAndServe(addr, nil); err != nil {
		red.Printf("❌ Server error: %v\n", err)
		os.Exit(1)
	}
}
