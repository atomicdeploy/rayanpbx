package main

import (
	"testing"
)

func TestExtractExtensionNumber(t *testing.T) {
	tests := []struct {
		name        string
		sectionName string
		wantExt     string
		wantOk      bool
	}{
		// Standard naming
		{"standard numeric", "101", "101", true},
		{"standard three digit", "200", "200", true},
		{"standard four digit", "1001", "1001", true},

		// Suffix patterns (NUMBER-TYPE)
		{"suffix with dash auth", "101-auth", "101", true},
		{"suffix with dash aor", "101-aor", "101", true},
		{"suffix with dash endpoint", "101-endpoint", "101", true},
		{"suffix with underscore auth", "101_auth", "101", true},
		{"suffix no separator auth", "101auth", "101", true},
		{"suffix no separator aor", "101aor", "101", true},

		// Prefix patterns (TYPE-NUMBER or TYPENUMBER)
		{"prefix with dash auth", "auth-101", "101", true},
		{"prefix with dash aor", "aor-101", "101", true},
		{"prefix with underscore auth", "auth_101", "101", true},
		{"prefix no separator auth", "auth101", "101", true},
		{"prefix no separator aor", "aor101", "101", true},
		{"prefix no separator endpoint", "endpoint101", "101", true},

		// Invalid/non-matching patterns
		{"transport section", "transport-udp", "", false},
		{"trunk name", "my-trunk", "", false},
		{"global section", "global", "", false},
		{"random text", "foobar", "", false},
		{"trunk with number", "trunk123", "", false}, // trunk is not a valid prefix
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			gotExt, gotOk := extractExtensionNumber(tt.sectionName)
			if gotExt != tt.wantExt || gotOk != tt.wantOk {
				t.Errorf("extractExtensionNumber(%q) = (%q, %v), want (%q, %v)",
					tt.sectionName, gotExt, gotOk, tt.wantExt, tt.wantOk)
			}
		})
	}
}

func TestIsAlternativeNaming(t *testing.T) {
	tests := []struct {
		sectionName string
		extNumber   string
		want        bool
	}{
		{"101", "101", false},
		{"101-auth", "101", true},
		{"auth101", "101", true},
		{"200", "200", false},
	}

	for _, tt := range tests {
		t.Run(tt.sectionName, func(t *testing.T) {
			got := isAlternativeNaming(tt.sectionName, tt.extNumber)
			if got != tt.want {
				t.Errorf("isAlternativeNaming(%q, %q) = %v, want %v",
					tt.sectionName, tt.extNumber, got, tt.want)
			}
		})
	}
}

func TestParsePjsipContentWithAlternativeNaming(t *testing.T) {
	// Config with alternative naming patterns
	content := `; PJSIP Configuration with alternative naming

[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0:5060

[101]
type=endpoint
context=from-internal
transport=transport-udp
auth=101-auth
aors=101

[101-auth]
type=auth
auth_type=userpass
username=101
password=secret101

[101]
type=aor
max_contacts=1
qualify_frequency=60

[102]
type=endpoint
context=from-internal
auth=auth102
aors=102

[auth102]
type=auth
auth_type=userpass
username=102
password=secret102

[102]
type=aor
max_contacts=2
`

	esm := &ExtensionSyncManager{}
	extensions, err := esm.parsePjsipContent(content)
	if err != nil {
		t.Fatalf("Failed to parse content: %v", err)
	}

	// Should find 2 extensions: 101 and 102
	if len(extensions) != 2 {
		t.Fatalf("Expected 2 extensions, got %d", len(extensions))
	}

	// Check extension 101
	var ext101, ext102 *AsteriskExtension
	for i := range extensions {
		if extensions[i].ExtensionNumber == "101" {
			ext101 = &extensions[i]
		}
		if extensions[i].ExtensionNumber == "102" {
			ext102 = &extensions[i]
		}
	}

	if ext101 == nil {
		t.Error("Expected to find extension 101")
	} else {
		if ext101.Context != "from-internal" {
			t.Errorf("Expected ext101.Context = 'from-internal', got %q", ext101.Context)
		}
		if ext101.Secret != "secret101" {
			t.Errorf("Expected ext101.Secret = 'secret101', got %q", ext101.Secret)
		}
		if ext101.MaxContacts != 1 {
			t.Errorf("Expected ext101.MaxContacts = 1, got %d", ext101.MaxContacts)
		}
	}

	if ext102 == nil {
		t.Error("Expected to find extension 102")
	} else {
		if ext102.Secret != "secret102" {
			t.Errorf("Expected ext102.Secret = 'secret102', got %q", ext102.Secret)
		}
		if ext102.MaxContacts != 2 {
			t.Errorf("Expected ext102.MaxContacts = 2, got %d", ext102.MaxContacts)
		}
	}
}
