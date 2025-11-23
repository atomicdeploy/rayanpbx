#!/bin/bash

# RayanPBX Sound Management
# Manage Asterisk sound files and prompts

set -euo pipefail

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Configuration
SOUNDS_DIR="/var/lib/asterisk/sounds"
CUSTOM_SOUNDS_DIR="$SOUNDS_DIR/custom"

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

# List installed sound packs
sound_list() {
    echo -e "${CYAN}═══════════════════════════════════════${NC}"
    echo -e "${CYAN}  🔊 Installed Sound Packs${NC}"
    echo -e "${CYAN}═══════════════════════════════════════${NC}"
    
    if [ -d "$SOUNDS_DIR" ]; then
        for lang_dir in "$SOUNDS_DIR"/*; do
            if [ -d "$lang_dir" ]; then
                lang=$(basename "$lang_dir")
                file_count=$(find "$lang_dir" -type f -name "*.wav" -o -name "*.gsm" -o -name "*.ulaw" | wc -l)
                echo -e "  ${GREEN}●${NC} $lang - $file_count files"
            fi
        done
    else
        print_error "Sounds directory not found"
        exit 1
    fi
}

# List custom sounds
sound_list_custom() {
    echo -e "${CYAN}═══════════════════════════════════════${NC}"
    echo -e "${CYAN}  🎵 Custom Sound Files${NC}"
    echo -e "${CYAN}═══════════════════════════════════════${NC}"
    
    if [ -d "$CUSTOM_SOUNDS_DIR" ]; then
        find "$CUSTOM_SOUNDS_DIR" -type f \( -name "*.wav" -o -name "*.gsm" -o -name "*.ulaw" \) -exec ls -lh {} \; | awk '{print "  "$9" ("$5")"}'
    else
        print_warn "No custom sounds directory found"
        mkdir -p "$CUSTOM_SOUNDS_DIR"
        print_info "Created custom sounds directory: $CUSTOM_SOUNDS_DIR"
    fi
}

# Upload/install a custom sound file
sound_upload() {
    local source_file=$1
    local dest_name=${2:-}
    
    if [ ! -f "$source_file" ]; then
        print_error "Source file not found: $source_file"
        exit 1
    fi
    
    # Create custom sounds directory if it doesn't exist
    mkdir -p "$CUSTOM_SOUNDS_DIR"
    
    # Determine destination filename
    if [ -z "$dest_name" ]; then
        dest_name=$(basename "$source_file")
    fi
    
    local dest_path="$CUSTOM_SOUNDS_DIR/$dest_name"
    
    print_info "Uploading sound file..."
    
    # Copy file
    cp "$source_file" "$dest_path"
    
    # Convert to appropriate formats if it's a wav file
    if [[ "$source_file" == *.wav ]]; then
        print_info "Converting to Asterisk formats..."
        
        # Convert to gsm
        if command -v sox &> /dev/null; then
            sox "$dest_path" -r 8000 -c 1 "${dest_path%.wav}.gsm"
            print_success "Converted to GSM format"
        fi
        
        # Convert to ulaw
        if command -v sox &> /dev/null; then
            sox "$dest_path" -r 8000 -c 1 -e u-law "${dest_path%.wav}.ulaw"
            print_success "Converted to uLaw format"
        fi
    fi
    
    # Set permissions
    chown asterisk:asterisk "$dest_path"
    chmod 644 "$dest_path"
    
    print_success "Sound file uploaded: $dest_path"
}

# Delete a custom sound file
sound_delete() {
    local filename=$1
    
    if [ -z "$filename" ]; then
        print_error "Please specify a filename"
        exit 1
    fi
    
    local file_path="$CUSTOM_SOUNDS_DIR/$filename"
    
    if [ ! -f "$file_path" ]; then
        print_error "File not found: $file_path"
        exit 1
    fi
    
    print_warn "Deleting: $filename"
    rm -f "$file_path"
    
    # Remove related format files
    rm -f "${file_path%.wav}.gsm" "${file_path%.wav}.ulaw" 2>/dev/null
    
    print_success "Sound file deleted"
}

# Test play a sound file
sound_play() {
    local filename=$1
    
    if [ -z "$filename" ]; then
        print_error "Please specify a filename"
        exit 1
    fi
    
    # Search for file in various locations
    local file_path=""
    
    if [ -f "$CUSTOM_SOUNDS_DIR/$filename" ]; then
        file_path="$CUSTOM_SOUNDS_DIR/$filename"
    elif [ -f "$SOUNDS_DIR/en/$filename" ]; then
        file_path="$SOUNDS_DIR/en/$filename"
    elif [ -f "$filename" ]; then
        file_path="$filename"
    else
        print_error "File not found: $filename"
        exit 1
    fi
    
    print_info "Playing: $file_path"
    
    if command -v play &> /dev/null; then
        play "$file_path"
    elif command -v aplay &> /dev/null; then
        aplay "$file_path"
    else
        print_warn "No audio player found (install sox or alsa-utils)"
    fi
}

# Convert sound file to Asterisk formats
sound_convert() {
    local source_file=$1
    
    if [ ! -f "$source_file" ]; then
        print_error "Source file not found: $source_file"
        exit 1
    fi
    
    if ! command -v sox &> /dev/null; then
        print_error "sox is required for conversion. Install with: apt-get install sox"
        exit 1
    fi
    
    print_info "Converting $source_file to Asterisk formats..."
    
    local base_name="${source_file%.*}"
    
    # Convert to 8kHz mono WAV
    sox "$source_file" -r 8000 -c 1 "${base_name}_8k.wav"
    print_success "Created: ${base_name}_8k.wav"
    
    # Convert to GSM
    sox "$source_file" -r 8000 -c 1 "${base_name}.gsm"
    print_success "Created: ${base_name}.gsm"
    
    # Convert to uLaw
    sox "$source_file" -r 8000 -c 1 -e u-law "${base_name}.ulaw"
    print_success "Created: ${base_name}.ulaw"
    
    print_success "Conversion complete"
}

# Download sound packs
sound_download() {
    local language=${1:-en}
    
    print_info "Downloading sound pack for language: $language"
    print_warn "This feature requires manual implementation"
    print_info "You can download sound packs from:"
    echo "  - https://www.asterisk.org/community/documentation/"
    echo "  - http://downloads.asterisk.org/pub/telephony/sounds/"
}

# Show sound file info
sound_info() {
    local filename=$1
    
    if [ -z "$filename" ]; then
        print_error "Please specify a filename"
        exit 1
    fi
    
    # Search for file
    local file_path=""
    
    if [ -f "$CUSTOM_SOUNDS_DIR/$filename" ]; then
        file_path="$CUSTOM_SOUNDS_DIR/$filename"
    elif [ -f "$SOUNDS_DIR/en/$filename" ]; then
        file_path="$SOUNDS_DIR/en/$filename"
    elif [ -f "$filename" ]; then
        file_path="$filename"
    else
        print_error "File not found: $filename"
        exit 1
    fi
    
    echo -e "${CYAN}═══════════════════════════════════════${NC}"
    echo -e "${CYAN}  🔊 Sound File Info${NC}"
    echo -e "${CYAN}═══════════════════════════════════════${NC}"
    
    echo -e "${CYAN}File:${NC} $file_path"
    echo -e "${CYAN}Size:${NC} $(du -h "$file_path" | cut -f1)"
    
    if command -v file &> /dev/null; then
        echo -e "${CYAN}Type:${NC} $(file -b "$file_path")"
    fi
    
    if command -v soxi &> /dev/null && [[ "$file_path" == *.wav ]]; then
        echo
        soxi "$file_path"
    fi
}

# Main function
main() {
    local command=${1:-}
    
    case "$command" in
        list)
            sound_list
            ;;
        list-custom)
            sound_list_custom
            ;;
        upload)
            sound_upload "$2" "$3"
            ;;
        delete)
            sound_delete "$2"
            ;;
        play)
            sound_play "$2"
            ;;
        convert)
            sound_convert "$2"
            ;;
        download)
            sound_download "$2"
            ;;
        info)
            sound_info "$2"
            ;;
        *)
            echo "RayanPBX Sound Management"
            echo ""
            echo "Usage: $0 <command> [options]"
            echo ""
            echo "Commands:"
            echo "  list                     - List installed sound packs"
            echo "  list-custom              - List custom sound files"
            echo "  upload FILE [NAME]       - Upload a custom sound file"
            echo "  delete NAME              - Delete a custom sound file"
            echo "  play FILE                - Play a sound file"
            echo "  convert FILE             - Convert sound file to Asterisk formats"
            echo "  download [LANG]          - Download sound pack for language"
            echo "  info FILE                - Show sound file information"
            echo ""
            echo "Examples:"
            echo "  $0 list                           # List sound packs"
            echo "  $0 upload /tmp/greeting.wav       # Upload custom sound"
            echo "  $0 play custom/greeting.wav       # Play sound file"
            echo "  $0 convert /tmp/music.mp3         # Convert to Asterisk formats"
            exit 1
            ;;
    esac
}

main "$@"
