#!/usr/bin/env bash
#
# jq-wrapper.sh - Debugging wrapper for jq that provides helpful error output
#
# Put this at the top of a bash script (or `source` it) to override `jq` with
# a debugging wrapper that, on error, prints:
#  - the exact jq command that was run (with shell-quoted args)
#  - the stdin that was passed to jq (or the contents of any regular file args)
#  - a minimal stacktrace for debugging
#  - instructions for reporting the issue to GitHub
#
# Usage:
#  - Source this file in your script: . /path/to/jq-wrapper.sh
#  - Then use `jq` as usual inside that script.
#

# Max bytes to print when showing inputs
JQ_WRAPPER_MAX_BYTES=${JQ_WRAPPER_MAX_BYTES:-20000}

# GitHub repository for issue reporting
JQ_WRAPPER_GITHUB_REPO=${JQ_WRAPPER_GITHUB_REPO:-"atomicdeploy/rayanpbx"}

# Generate a minimal stacktrace
_jq_wrapper_stacktrace() {
    local frame=0
    local output=""
    
    # Skip the first few frames (this function and jq wrapper)
    while caller $frame > /dev/null 2>&1; do
        local caller_info
        caller_info=$(caller $frame)
        local line func file
        read -r line func file <<< "$caller_info"
        
        # Skip internal wrapper frames
        if [[ "$func" != "jq" && "$func" != "_jq_wrapper_stacktrace" ]]; then
            output+="  at ${func}() in ${file}:${line}"$'\n'
        fi
        ((frame++))
        
        # Limit stacktrace depth
        if [ $frame -gt 10 ]; then
            output+="  ... (truncated)"$'\n'
            break
        fi
    done
    
    echo "$output"
}

# Create a GitHub issue body for error reporting
_jq_wrapper_create_issue_body() {
    local cmd_quoted="$1"
    local input_preview="$2"
    local stacktrace="$3"
    local exit_code="$4"
    
    cat <<EOF
## jq Error Report

### Error Details
- **Exit Code:** ${exit_code}
- **Command:** \`${cmd_quoted}\`

### Input Data
\`\`\`
${input_preview}
\`\`\`

### Stacktrace
\`\`\`
${stacktrace}
\`\`\`

### Environment
- **Script:** $(basename "${BASH_SOURCE[2]:-unknown}")
- **OS:** $(uname -s) $(uname -r)
- **Bash Version:** ${BASH_VERSION}
- **jq Version:** $(command jq --version 2>/dev/null || echo "unknown")
EOF
}

# Offer to submit GitHub issue
_jq_wrapper_offer_issue() {
    local issue_body="$1"
    
    # Check if gh CLI is available
    if command -v gh &> /dev/null; then
        echo "" >&2
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" >&2
        echo "Would you like to report this issue to GitHub?" >&2
        echo "Repository: ${JQ_WRAPPER_GITHUB_REPO}" >&2
        echo "" >&2
        echo "To submit an issue, run:" >&2
        echo "  gh issue create --repo ${JQ_WRAPPER_GITHUB_REPO} --title 'jq parse error in CLI' --body-file /tmp/jq-error-report.md" >&2
        echo "" >&2
        
        # Save issue body to temp file for manual submission
        echo "$issue_body" > /tmp/jq-error-report.md
        echo "(Issue body saved to /tmp/jq-error-report.md)" >&2
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" >&2
    else
        echo "" >&2
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" >&2
        echo "Please report this issue at:" >&2
        echo "  https://github.com/${JQ_WRAPPER_GITHUB_REPO}/issues/new" >&2
        echo "" >&2
        echo "Include the error details shown above in your report." >&2
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" >&2
    fi
}

jq() {
    # Find the external jq executable (ignore this function)
    local jq_bin
    jq_bin="$(type -P jq)" || { command jq "$@"; return $?; }

    # Prepare tmpfile if stdin is non-interactive (piped or heredoc)
    local have_stdin=0 tmp=""
    if ! [ -t 0 ]; then
        have_stdin=1
        tmp="$(mktemp)" || { echo "jq-wrapper: mktemp failed" >&2; "$jq_bin" "$@"; return $?; }
        cat >"$tmp"
    fi

    # Run jq: if we captured stdin, redirect jq's stdin from the tempfile so jq behaves the same
    local rc
    if [ "$have_stdin" -eq 1 ]; then
        "$jq_bin" "$@" <"$tmp"
        rc=$?
    else
        "$jq_bin" "$@"
        rc=$?
    fi

    # On error, print diagnostic: command + input(s)
    if [ $rc -ne 0 ]; then
        # Build a shell-quoted command string for easy copy/paste
        local cmd_quoted
        cmd_quoted="$(printf '%q' "$jq_bin")"
        for a in "$@"; do
            cmd_quoted+=" $(printf '%q' "$a")"
        done

        {
            echo ""
            echo "┌─────────────────────────────────────────────────────────────┐"
            echo "│                    jq Error Details                         │"
            echo "└─────────────────────────────────────────────────────────────┘"
            printf 'jq failed (exit %d): %s\n' "$rc" "$cmd_quoted"
        } >&2

        local input_preview=""
        
        # Show stdin if present
        if [ "$have_stdin" -eq 1 ]; then
            if [ -s "$tmp" ]; then
                local size
                size=$(wc -c <"$tmp" | awk '{print $1}')
                if [ "$size" -le "$JQ_WRAPPER_MAX_BYTES" ]; then
                    printf '---- jq stdin (size: %s bytes) ----\n' "$size" >&2
                    cat "$tmp" >&2
                    printf '\n---- end jq stdin ----\n' >&2
                    input_preview=$(cat "$tmp")
                else
                    printf '---- jq stdin truncated (first %d bytes of %d) ----\n' "$JQ_WRAPPER_MAX_BYTES" "$size" >&2
                    head -c "$JQ_WRAPPER_MAX_BYTES" "$tmp" >&2
                    printf '\n---- end truncated jq stdin ----\n' >&2
                    input_preview=$(head -c "$JQ_WRAPPER_MAX_BYTES" "$tmp")
                    input_preview+="... (truncated)"
                fi
            else
                printf '(stdin was empty)\n' >&2
                input_preview="(empty)"
            fi
        else
            # If no stdin, try to print any regular-file args (jq reads files after the filter)
            local shown=0
            for a in "$@"; do
                if [ -f "$a" ]; then
                    shown=1
                    local fsize
                    fsize=$(wc -c <"$a" | awk '{print $1}')
                    if [ "$fsize" -le "$JQ_WRAPPER_MAX_BYTES" ]; then
                        printf '---- jq input file: %s (size: %s bytes) ----\n' "$a" "$fsize" >&2
                        cat "$a" >&2
                        printf '\n---- end file: %s ----\n' "$a" >&2
                        input_preview=$(cat "$a")
                    else
                        printf '---- jq input file truncated: %s (first %d bytes of %d) ----\n' "$a" "$JQ_WRAPPER_MAX_BYTES" "$fsize" >&2
                        head -c "$JQ_WRAPPER_MAX_BYTES" "$a" >&2
                        printf '\n---- end truncated file: %s ----\n' "$a" >&2
                        input_preview=$(head -c "$JQ_WRAPPER_MAX_BYTES" "$a")
                        input_preview+="... (truncated)"
                    fi
                fi
            done

            if [ "$shown" -eq 0 ]; then
                # Nothing looked like a regular file; print the argument list so user can inspect
                printf 'No stdin and no regular-file args detected. Args passed to jq:\n' >&2
                local a
                for a in "$@"; do printf '  %s\n' "$(printf '%q' "$a")" >&2; done
                input_preview="No input data available"
            fi
        fi
        
        # Print stacktrace
        echo "" >&2
        echo "---- Stacktrace ----" >&2
        local stacktrace
        stacktrace=$(_jq_wrapper_stacktrace)
        echo "$stacktrace" >&2
        echo "---- end Stacktrace ----" >&2
        
        # Create issue body and offer to report
        local issue_body
        issue_body=$(_jq_wrapper_create_issue_body "$cmd_quoted" "$input_preview" "$stacktrace" "$rc")
        _jq_wrapper_offer_issue "$issue_body"
    fi

    # Cleanup
    if [ -n "$tmp" ] && [ -f "$tmp" ]; then rm -f "$tmp"; fi

    return $rc
}

# Export the function so it's available in subshells
export -f jq
export -f _jq_wrapper_stacktrace
export -f _jq_wrapper_create_issue_body
export -f _jq_wrapper_offer_issue
export JQ_WRAPPER_MAX_BYTES
export JQ_WRAPPER_GITHUB_REPO
