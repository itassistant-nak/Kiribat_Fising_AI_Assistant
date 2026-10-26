# Shared Antigravity Agent Coordination Files

Copy these four Markdown files into the root of `~/Projects/kiribati-ai-fisheries`:
`AGENTS.md`, `PROJECT_STATE.md`, `TASKS.md`, `DECISIONS.md`.

Then tell each new model/session:
> Read AGENTS.md, PROJECT_STATE.md, TASKS.md, and DECISIONS.md. Inspect git status and the codebase. Summarize verified progress and the next unclaimed P0 task. Wait for approval before coding.

These files preserve handoffs, not automatic multi-model orchestration. If multiple agents work at once, use separate Git worktrees/branches, explicitly assigned tasks, and coordinated merges. Avoid concurrent edits to these Markdown files.
