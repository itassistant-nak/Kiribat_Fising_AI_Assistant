# Kiribati AI Fisheries Assistant — Agent Instructions

## Mandatory startup (every model, every session)
1. Read `AGENTS.md`, `PROJECT_STATE.md`, `TASKS.md`, and `DECISIONS.md` in full.
2. Inspect `git status`, recent commits, relevant source files, and available tests. Do not assume status files are accurate without checking code.
3. Identify the highest-priority unblocked task that you are authorized to work on. Claim it in `TASKS.md` with agent/session name and timestamp before editing.
4. State the task, files likely to change, acceptance criteria, and risks. Obtain approval before destructive changes, external deployments, spending, or secret-related actions.

## Working rules
- Keep changes small and scoped. Do not overwrite another agent's work.
- Do not work on a task claimed by another active agent. If a claim is stale, confirm with the user before taking it over.
- For concurrent work, use separate branches/worktrees and coordinate merges; shared Markdown files are not a transactional lock.
- Preserve existing functionality. Run relevant tests and report actual results; never claim unrun tests passed.
- Never put credentials, tokens, private GPS tracks, or real fisher data into the repository or status files.
- Treat poisoning advisories as evidence-based, human-approved; AI cannot determine fish toxicity from an image.
- Keep offline-first capture and reliable sync central to the MVP.

## Mandatory handoff (before stopping)
1. Update task status and acceptance criteria in `TASKS.md`.
2. Update `PROJECT_STATE.md` with verified working functionality, blockers, test commands/results, and exact next steps.
3. Record lasting architecture choices in `DECISIONS.md`.
4. Report changed files and commit hash (if committed). Never say completed unless verified.
5. If interrupted, mark task `IN_PROGRESS` with explicit resume instructions.

## Priority order
P0: Laravel API, Flutter offline catch + photos + GPS, synchronization, Meta vision integration, human confirmation, admin review.
P1: Zone mapping and species-specific advisories, offline warning, species catalog cache, trip route tracking.
P2: On-device classification, advanced assistant, expanded analytics and maps.

## New session kickoff prompt
Read AGENTS.md, PROJECT_STATE.md, TASKS.md, and DECISIONS.md. Inspect git status and relevant code. Summarize current state and blockers, select the highest-priority unclaimed task, and propose your next change. Do not code until I approve.
