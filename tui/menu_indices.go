package main

// Menu index constants for TUI navigation
// These constants make the switch statements more maintainable by avoiding
// hardcoded numeric values that depend on menu item positions.

// Main menu indices - must match the order in initialModel menuItems slice
const (
	MainMenuQuickSetup = iota
	MainMenuExtensions
	MainMenuTrunks
	MainMenuDialplan
	MainMenuVoIPPhones
	MainMenuConsolePhone
	MainMenuAsterisk
	MainMenuDiagnostics
	MainMenuStatus
	MainMenuLogs
	MainMenuLiveConsole
	MainMenuUsageGuide
	MainMenuConfigManagement
	MainMenuSystemSettings
	MainMenuExit
)

// Diagnostics menu indices - must match the order in initialModel diagnosticsMenu slice
const (
	DiagMenuHealthCheck = iota
	DiagMenuSystemInfo
	DiagMenuCheckSIPPort
	DiagMenuEnableSIPDebug
	DiagMenuDisableSIPDebug
	DiagMenuTestExtension
	DiagMenuTestTrunk
	DiagMenuTestRouting
	DiagMenuTestPort
	DiagMenuSIPTestSuite
	DiagMenuBackToMain
)

// SIP test menu indices - must match the order in initialModel sipTestMenu slice
const (
	SIPTestMenuCheckTools = iota
	SIPTestMenuInstallTool
	SIPTestMenuTestRegister
	SIPTestMenuTestCall
	SIPTestMenuRunFullSuite
	SIPTestMenuBackToDiag
)

// Asterisk menu indices - must match the order in initialModel asteriskMenu slice
const (
	AsteriskMenuStart = iota
	AsteriskMenuStop
	AsteriskMenuRestart
	AsteriskMenuShowStatus
	AsteriskMenuReloadPJSIP
	AsteriskMenuReloadDialplan
	AsteriskMenuReloadAll
	AsteriskMenuConfigTransports
	AsteriskMenuShowEndpoints
	AsteriskMenuShowTransports
	AsteriskMenuShowChannels
	AsteriskMenuShowRegistrations
	AsteriskMenuLiveConsole
	AsteriskMenuBackToMain
)

// System settings menu indices - must match the order in renderSystemSettings
const (
	SysSettingsToggleMode = iota
	SysSettingsToggleDebug
	SysSettingsSetProduction
	SysSettingsSetDevelopment
	SysSettingsRunUpgrade
	SysSettingsResetConfig
	SysSettingsBackToMain
)

// Extension sync menu indices - must match the order in initialModel extensionSyncMenu slice
const (
	ExtSyncMenuSyncSelectedToAsterisk = iota
	ExtSyncMenuSyncSelectedToDB
	ExtSyncMenuSyncAllToAsterisk
	ExtSyncMenuSyncAllToDB
	ExtSyncMenuRefresh
	ExtSyncMenuBackToExtensions
)

// Reset configuration menu indices - must match the order in initialModel resetMenu slice
const (
	ResetMenuResetAll = iota
	ResetMenuShowSummary
	ResetMenuBackToSettings
)

// Dialplan menu indices - must match the order in initialModel dialplanMenu slice
const (
	DialplanMenuViewCurrent = iota
	DialplanMenuGenerateFromExtensions
	DialplanMenuCreateDefaultPattern
	DialplanMenuApplyToAsterisk
	DialplanMenuReloadDialplan
	DialplanMenuPatternHelp
	DialplanMenuBackToMain
)
