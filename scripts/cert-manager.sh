#!/bin/bash

# RayanPBX Certificate Management
# Manage SSL/TLS certificates for secure communications

set -euo pipefail

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Configuration
CERT_DIR="/etc/asterisk/keys"
NGINX_CERT_DIR="/etc/nginx/ssl"
LE_DIR="/etc/letsencrypt"

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

print_info() {
    echo -e "${CYAN}ℹ️  $1${NC}"
}

print_warn() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

# Check if running as root
check_root() {
    if [ "$EUID" -ne 0 ]; then
        print_error "This script must be run as root"
        exit 1
    fi
}

# List all certificates
cert_list() {
    echo -e "${CYAN}═══════════════════════════════════════${NC}"
    echo -e "${CYAN}  🔐 Installed Certificates${NC}"
    echo -e "${CYAN}═══════════════════════════════════════${NC}"
    
    echo -e "\n${YELLOW}Asterisk Certificates:${NC}"
    if [ -d "$CERT_DIR" ]; then
        find "$CERT_DIR" -type f \( -name "*.pem" -o -name "*.crt" -o -name "*.key" \) -exec ls -lh {} \; | awk '{print "  "$9" ("$5")"}'
    else
        print_warn "Asterisk certificates directory not found"
    fi
    
    echo -e "\n${YELLOW}Nginx/Web Certificates:${NC}"
    if [ -d "$NGINX_CERT_DIR" ]; then
        find "$NGINX_CERT_DIR" -type f \( -name "*.pem" -o -name "*.crt" -o -name "*.key" \) -exec ls -lh {} \; | awk '{print "  "$9" ("$5")"}'
    else
        print_warn "Nginx certificates directory not found"
    fi
    
    echo -e "\n${YELLOW}Let's Encrypt Certificates:${NC}"
    if [ -d "$LE_DIR/live" ]; then
        for domain_dir in "$LE_DIR/live"/*; do
            if [ -d "$domain_dir" ]; then
                domain=$(basename "$domain_dir")
                echo -e "  ${GREEN}●${NC} $domain"
                if [ -f "$domain_dir/fullchain.pem" ]; then
                    expiry=$(openssl x509 -enddate -noout -in "$domain_dir/fullchain.pem" | cut -d= -f2)
                    echo -e "    Expires: $expiry"
                fi
            fi
        done
    else
        print_warn "No Let's Encrypt certificates found"
    fi
}

# Generate self-signed certificate
cert_generate_self() {
    local domain=${1:-localhost}
    local days=${2:-365}
    
    print_info "Generating self-signed certificate for: $domain"
    
    # Create directories if they don't exist
    mkdir -p "$CERT_DIR"
    mkdir -p "$NGINX_CERT_DIR"
    
    local cert_file="$CERT_DIR/${domain}.crt"
    local key_file="$CERT_DIR/${domain}.key"
    local pem_file="$CERT_DIR/${domain}.pem"
    
    # Generate certificate
    openssl req -x509 -nodes -days "$days" -newkey rsa:4096 \
        -keyout "$key_file" \
        -out "$cert_file" \
        -subj "/C=US/ST=State/L=City/O=Organization/CN=$domain"
    
    # Combine cert and key into PEM file
    cat "$cert_file" "$key_file" > "$pem_file"
    
    # Set permissions
    chmod 600 "$key_file" "$pem_file"
    chmod 644 "$cert_file"
    
    # Copy to nginx directory
    cp "$cert_file" "$NGINX_CERT_DIR/"
    cp "$key_file" "$NGINX_CERT_DIR/"
    
    print_success "Certificate generated successfully"
    echo -e "  Certificate: $cert_file"
    echo -e "  Private Key: $key_file"
    echo -e "  PEM File: $pem_file"
}

# Install Let's Encrypt certificate using certbot
cert_letsencrypt() {
    local domain=$1
    local email=${2:-}
    
    if [ -z "$domain" ]; then
        print_error "Please specify a domain name"
        exit 1
    fi
    
    # Install certbot if not present
    if ! command -v certbot &> /dev/null; then
        print_info "Installing certbot..."
        apt-get update -qq
        apt-get install -y certbot python3-certbot-nginx
        print_success "Certbot installed"
    fi
    
    print_info "Requesting Let's Encrypt certificate for: $domain"
    
    if [ -n "$email" ]; then
        certbot certonly --nginx -d "$domain" --email "$email" --agree-tos --non-interactive
    else
        certbot certonly --nginx -d "$domain" --register-unsafely-without-email --agree-tos --non-interactive
    fi
    
    if [ $? -eq 0 ]; then
        print_success "Let's Encrypt certificate installed"
        
        # Link certificates for Asterisk
        mkdir -p "$CERT_DIR"
        ln -sf "$LE_DIR/live/$domain/fullchain.pem" "$CERT_DIR/${domain}.crt"
        ln -sf "$LE_DIR/live/$domain/privkey.pem" "$CERT_DIR/${domain}.key"
        ln -sf "$LE_DIR/live/$domain/fullchain.pem" "$CERT_DIR/${domain}.pem"
        
        print_success "Certificates linked for Asterisk"
    else
        print_error "Failed to obtain Let's Encrypt certificate"
        exit 1
    fi
}

# Renew Let's Encrypt certificates
cert_renew() {
    if ! command -v certbot &> /dev/null; then
        print_error "Certbot not installed"
        exit 1
    fi
    
    print_info "Renewing Let's Encrypt certificates..."
    certbot renew
    
    if [ $? -eq 0 ]; then
        print_success "Certificates renewed successfully"
        
        # Reload services
        print_info "Reloading services..."
        systemctl reload nginx 2>/dev/null || true
        asterisk -rx "core reload" 2>/dev/null || true
    else
        print_warn "Some certificates may not have been renewed"
    fi
}

# Show certificate info
cert_info() {
    local cert_file=$1
    
    if [ -z "$cert_file" ]; then
        print_error "Please specify a certificate file"
        exit 1
    fi
    
    if [ ! -f "$cert_file" ]; then
        print_error "Certificate file not found: $cert_file"
        exit 1
    fi
    
    echo -e "${CYAN}═══════════════════════════════════════${NC}"
    echo -e "${CYAN}  🔐 Certificate Information${NC}"
    echo -e "${CYAN}═══════════════════════════════════════${NC}"
    
    openssl x509 -in "$cert_file" -text -noout
}

# Verify certificate
cert_verify() {
    local cert_file=$1
    local key_file=${2:-}
    
    if [ -z "$cert_file" ]; then
        print_error "Please specify a certificate file"
        exit 1
    fi
    
    if [ ! -f "$cert_file" ]; then
        print_error "Certificate file not found: $cert_file"
        exit 1
    fi
    
    print_info "Verifying certificate: $cert_file"
    
    # Check certificate validity
    if openssl x509 -checkend 86400 -noout -in "$cert_file"; then
        print_success "Certificate is valid"
    else
        print_warn "Certificate will expire within 24 hours or is already expired"
    fi
    
    # Show expiry date
    expiry=$(openssl x509 -enddate -noout -in "$cert_file" | cut -d= -f2)
    echo -e "${CYAN}Expires:${NC} $expiry"
    
    # If key file is provided, verify they match
    if [ -n "$key_file" ] && [ -f "$key_file" ]; then
        cert_modulus=$(openssl x509 -noout -modulus -in "$cert_file" | md5sum)
        key_modulus=$(openssl rsa -noout -modulus -in "$key_file" 2>/dev/null | md5sum)
        
        if [ "$cert_modulus" == "$key_modulus" ]; then
            print_success "Certificate and key match"
        else
            print_error "Certificate and key do not match!"
        fi
    fi
}

# Setup automatic renewal (cron job)
cert_setup_renewal() {
    print_info "Setting up automatic certificate renewal..."
    
    # Add cron job for certificate renewal
    cron_cmd="0 3 * * * certbot renew --quiet && systemctl reload nginx"
    
    if ! crontab -l 2>/dev/null | grep -q "certbot renew"; then
        (crontab -l 2>/dev/null; echo "$cron_cmd") | crontab -
        print_success "Automatic renewal configured (daily at 3 AM)"
    else
        print_info "Automatic renewal already configured"
    fi
}

# Import certificate
cert_import() {
    local cert_file=$1
    local key_file=$2
    local name=${3:-imported}
    
    if [ ! -f "$cert_file" ]; then
        print_error "Certificate file not found: $cert_file"
        exit 1
    fi
    
    if [ ! -f "$key_file" ]; then
        print_error "Key file not found: $key_file"
        exit 1
    fi
    
    print_info "Importing certificate..."
    
    mkdir -p "$CERT_DIR"
    mkdir -p "$NGINX_CERT_DIR"
    
    # Copy files
    cp "$cert_file" "$CERT_DIR/${name}.crt"
    cp "$key_file" "$CERT_DIR/${name}.key"
    cat "$cert_file" "$key_file" > "$CERT_DIR/${name}.pem"
    
    # Copy to nginx
    cp "$cert_file" "$NGINX_CERT_DIR/${name}.crt"
    cp "$key_file" "$NGINX_CERT_DIR/${name}.key"
    
    # Set permissions
    chmod 600 "$CERT_DIR/${name}.key" "$CERT_DIR/${name}.pem"
    chmod 644 "$CERT_DIR/${name}.crt"
    chmod 600 "$NGINX_CERT_DIR/${name}.key"
    chmod 644 "$NGINX_CERT_DIR/${name}.crt"
    
    print_success "Certificate imported successfully"
}

# Main function
main() {
    check_root
    
    local command=${1:-}
    
    case "$command" in
        list)
            cert_list
            ;;
        generate)
            cert_generate_self "$2" "$3"
            ;;
        letsencrypt|le)
            cert_letsencrypt "$2" "$3"
            ;;
        renew)
            cert_renew
            ;;
        info)
            cert_info "$2"
            ;;
        verify)
            cert_verify "$2" "$3"
            ;;
        setup-renewal)
            cert_setup_renewal
            ;;
        import)
            cert_import "$2" "$3" "$4"
            ;;
        *)
            echo "RayanPBX Certificate Management"
            echo ""
            echo "Usage: $0 <command> [options]"
            echo ""
            echo "Commands:"
            echo "  list                              - List all certificates"
            echo "  generate DOMAIN [DAYS]            - Generate self-signed certificate"
            echo "  letsencrypt DOMAIN [EMAIL]        - Get Let's Encrypt certificate"
            echo "  renew                             - Renew Let's Encrypt certificates"
            echo "  info CERT_FILE                    - Show certificate information"
            echo "  verify CERT_FILE [KEY_FILE]       - Verify certificate validity"
            echo "  setup-renewal                     - Setup automatic renewal"
            echo "  import CERT_FILE KEY_FILE [NAME]  - Import certificate and key"
            echo ""
            echo "Examples:"
            echo "  $0 list                                      # List all certificates"
            echo "  $0 generate pbx.example.com 365              # Generate self-signed cert"
            echo "  $0 letsencrypt pbx.example.com admin@ex.com  # Get Let's Encrypt cert"
            echo "  $0 verify /etc/asterisk/keys/pbx.crt         # Verify certificate"
            exit 1
            ;;
    esac
}

main "$@"
