# ADR-0005: Coding agents reach Rollcall through an ADVISOR module, not `rollcall mcp`

- **Status:** Accepted
- **Date:** 2026-11-03
- **Deciders:** Adam Perkins (Enterprise Applications), project owner. Recorded retroactively from the CHANGELOG
  (backlog ROLLCALL-33 closed) and the module's code.
- **Resolves:** whether Rollcall ships its own MCP server.

## Context

The backlog asked for a `rollcall mcp` command so a coding agent could ask about the fleet. ADVISOR is already the
stack's read-only MCP server, with a module system. A second server would mean a second install, a second config
and a second set of rules about what it may say.

## Decision

Rollcall ships a module, `Waynestate\Rollcall\Advisor\RollcallModule`, with three read-only tools
(`rollcall_fleet_summary`, `rollcall_burn_down`, `rollcall_supported_versions`). It ships no manifest, so it loads
only when someone names it in their own `ADVISOR_MODULES_FILE`. The tools build from the public report, so they
answer counts only (ADR-0002).

## Consequences

- Backlog ROLLCALL-33 is closed as decided.
- `RollcallModuleTest` fails if a fixture app name or path appears in any tool output, and every tool is declared
  `readOnly`.
- A new tool is a module change and a minor release, read against the same contract as the report.
