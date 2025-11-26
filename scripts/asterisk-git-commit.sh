#!/bin/bash

# RayanPBX Asterisk Configuration Git Commit Helper
# Commits changes to /etc/asterisk Git repository with meaningful messages
#
# Usage: asterisk-git-commit.sh <action> <description>
#   action: Type of change (e.g., "extension-create", "trunk-update", "config-edit")
#   description: Brief description of what was changed
#
# Examples:
#   asterisk-git-commit.sh "extension-create" "Created extension 1001 (John Doe)"
#   asterisk-git-commit.sh "trunk-update" "Updated trunk settings for provider X"
#   asterisk-git-commit.sh "config-edit" "Modified pjsip.conf transport settings"

set -euo pipefail

# Configuration
ASTERISK_CONFIG_DIR="${ASTERISK_CONFIG_DIR:-/etc/asterisk}"
RAYANPBX_VERSION="${RAYANPBX_VERSION:-unknown}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
DIM='\033[2m'
NC='\033[0m'

# Print functions
print_success() { echo -e "${GREEN}✅ $1${NC}"; }
print_error() { echo -e "${RED}❌ $1${NC}"; }
print_info() { echo -e "${CYAN}ℹ️  $1${NC}"; }
print_warn() { echo -e "${YELLOW}⚠️  $1${NC}"; }
print_verbose() {
    if [ "${VERBOSE:-false}" = "true" ]; then
        echo -e "${DIM}[VERBOSE] $1${NC}"
    fi
}

# Check if /etc/asterisk is a Git repository
is_git_repo() {
    [ -d "$ASTERISK_CONFIG_DIR/.git" ]
}

# Initialize Git repository if not exists
init_git_repo() {
    if ! is_git_repo; then
        print_warn "/etc/asterisk is not a Git repository. Skipping commit."
        return 1
    fi
    return 0
}

# Get source information (CLI, TUI, Web API)
get_source() {
    local source="${SOURCE:-}"
    
    if [ -z "$source" ]; then
        # Try to detect source from environment
        if [ -n "${RAYANPBX_TUI:-}" ]; then
            source="TUI"
        elif [ -n "${RAYANPBX_CLI:-}" ]; then
            source="CLI"
        elif [ -n "${RAYANPBX_API:-}" ]; then
            source="Web API"
        else
            source="Unknown"
        fi
    fi
    
    echo "$source"
}

# Get user information
get_user() {
    local user="${USER:-}"
    
    # If running via sudo, get the original user
    if [ -n "${SUDO_USER:-}" ]; then
        user="$SUDO_USER"
    fi
    
    # Fallback to whoami
    if [ -z "$user" ]; then
        user=$(whoami 2>/dev/null || echo "system")
    fi
    
    echo "$user"
}

# Commit changes to Git
commit_changes() {
    local action="${1:-config-change}"
    local description="${2:-Configuration updated}"
    local timestamp
    local source
    local user
    local commit_message
    
    timestamp=$(date '+%Y-%m-%d %H:%M:%S %Z')
    source=$(get_source)
    user=$(get_user)
    
    # Check if this is a Git repository
    if ! init_git_repo; then
        return 0  # Not an error - just skip silently
    fi
    
    # Change to the config directory
    cd "$ASTERISK_CONFIG_DIR" || {
        print_error "Cannot change to $ASTERISK_CONFIG_DIR"
        return 1
    }
    
    # Configure Git user if not already configured (for commits)
    if ! git config user.email > /dev/null 2>&1; then
        git config user.email "rayanpbx@localhost"
        git config user.name "RayanPBX"
    fi
    
    # Check if there are any changes to commit
    if git diff --quiet && git diff --cached --quiet; then
        print_verbose "No changes to commit in $ASTERISK_CONFIG_DIR"
        return 0
    fi
    
    # Stage all changes
    git add -A
    
    # Build commit message
    # Format: [action] description
    # 
    # Timestamp: YYYY-MM-DD HH:MM:SS TZ
    # Source: CLI/TUI/Web API
    # User: username
    # Why: <description>
    commit_message="[${action}] ${description}

Timestamp: ${timestamp}
Source: ${source}
User: ${user}
Why: ${description}

---
Committed by RayanPBX v${RAYANPBX_VERSION}"
    
    # Commit the changes
    if git commit -m "$commit_message" > /dev/null 2>&1; then
        print_success "Configuration snapshot saved: ${action}"
        print_verbose "Commit message: ${commit_message}"
        return 0
    else
        print_warn "Failed to commit changes (may be no changes)"
        return 0
    fi
}

# List recent commits (for viewing history)
list_history() {
    local count="${1:-10}"
    
    if ! is_git_repo; then
        print_error "/etc/asterisk is not a Git repository"
        return 1
    fi
    
    cd "$ASTERISK_CONFIG_DIR" || return 1
    
    echo -e "${CYAN}═══════════════════════════════════════════════════════════${NC}"
    echo -e "${CYAN}  Configuration History (last $count changes)${NC}"
    echo -e "${CYAN}═══════════════════════════════════════════════════════════${NC}"
    echo ""
    
    git log --oneline -n "$count" --format="%C(yellow)%h%C(reset) %C(cyan)%ad%C(reset) %s" --date=short
    
    echo ""
    echo -e "${DIM}Use 'asterisk-git-commit.sh show <commit_hash>' to view details${NC}"
    echo -e "${DIM}Use 'asterisk-git-commit.sh diff <commit_hash>' to see changes${NC}"
}

# Show details of a specific commit
show_commit() {
    local commit_hash="${1:-HEAD}"
    
    if ! is_git_repo; then
        print_error "/etc/asterisk is not a Git repository"
        return 1
    fi
    
    cd "$ASTERISK_CONFIG_DIR" || return 1
    
    git show --stat "$commit_hash"
}

# Show diff of a specific commit
show_diff() {
    local commit_hash="${1:-HEAD}"
    
    if ! is_git_repo; then
        print_error "/etc/asterisk is not a Git repository"
        return 1
    fi
    
    cd "$ASTERISK_CONFIG_DIR" || return 1
    
    git show "$commit_hash"
}

# Revert to a previous commit
revert_to() {
    local commit_hash="${1:-}"
    
    if [ -z "$commit_hash" ]; then
        print_error "Commit hash required"
        echo "Usage: asterisk-git-commit.sh revert <commit_hash>"
        return 1
    fi
    
    if ! is_git_repo; then
        print_error "/etc/asterisk is not a Git repository"
        return 1
    fi
    
    cd "$ASTERISK_CONFIG_DIR" || return 1
    
    print_warn "This will revert changes from commit: $commit_hash"
    
    # Revert a single commit (safer than reset)
    # --no-commit stages the revert without committing, so we can add our own message
    if git revert --no-commit "$commit_hash" 2>/dev/null; then
        commit_changes "config-revert" "Reverted commit $commit_hash"
        print_success "Configuration reverted successfully"
        print_info "Reload Asterisk to apply changes: asterisk -rx 'core reload'"
    else
        git revert --abort 2>/dev/null || true
        print_error "Failed to revert. Manual intervention may be required."
        print_info "The commit may have conflicts or the repository may be in an inconsistent state."
        return 1
    fi
}

# Show status of the repository
show_status() {
    if ! is_git_repo; then
        print_error "/etc/asterisk is not a Git repository"
        print_info "Run the installer to initialize it: sudo ./install.sh"
        return 1
    fi
    
    cd "$ASTERISK_CONFIG_DIR" || return 1
    
    echo -e "${CYAN}═══════════════════════════════════════════════════════════${NC}"
    echo -e "${CYAN}  Asterisk Configuration Repository Status${NC}"
    echo -e "${CYAN}═══════════════════════════════════════════════════════════${NC}"
    echo ""
    
    # Show current status
    local commit_count
    commit_count=$(git rev-list --count HEAD 2>/dev/null || echo "0")
    
    echo -e "  ${GREEN}✓${NC} Repository initialized"
    echo -e "  📊 Total snapshots: ${commit_count}"
    echo ""
    
    # Show pending changes
    if ! git diff --quiet || ! git diff --cached --quiet; then
        echo -e "  ${YELLOW}⚠️  Uncommitted changes:${NC}"
        git status --short
        echo ""
    else
        echo -e "  ${GREEN}✓${NC} No pending changes"
        echo ""
    fi
    
    # Show last commit
    echo -e "  ${CYAN}Last snapshot:${NC}"
    git log -1 --format="    %C(yellow)%h%C(reset) %s (%ar)" 2>/dev/null || echo "    (no commits yet)"
    echo ""
}

# Main command dispatcher
main() {
    local command="${1:-commit}"
    shift || true
    
    case "$command" in
        commit)
            commit_changes "$@"
            ;;
        history|log)
            list_history "$@"
            ;;
        show)
            show_commit "$@"
            ;;
        diff)
            show_diff "$@"
            ;;
        revert)
            revert_to "$@"
            ;;
        status)
            show_status
            ;;
        help|--help|-h)
            echo "RayanPBX Asterisk Configuration Git Commit Helper"
            echo ""
            echo "Usage: asterisk-git-commit.sh <command> [arguments]"
            echo ""
            echo "Commands:"
            echo "  commit <action> <description>  Commit current changes"
            echo "  history [count]                Show recent commits (default: 10)"
            echo "  show <commit_hash>             Show details of a commit"
            echo "  diff <commit_hash>             Show diff of a commit"
            echo "  revert <commit_hash>           Revert to a previous state"
            echo "  status                         Show repository status"
            echo "  help                           Show this help"
            echo ""
            echo "Environment Variables:"
            echo "  SOURCE         Set the source (CLI, TUI, Web API)"
            echo "  VERBOSE        Enable verbose output (true/false)"
            echo ""
            echo "Examples:"
            echo "  asterisk-git-commit.sh commit extension-create 'Added extension 1001'"
            echo "  asterisk-git-commit.sh history 20"
            echo "  asterisk-git-commit.sh revert abc123"
            ;;
        *)
            print_error "Unknown command: $command"
            echo "Run 'asterisk-git-commit.sh help' for usage"
            exit 1
            ;;
    esac
}

main "$@"
