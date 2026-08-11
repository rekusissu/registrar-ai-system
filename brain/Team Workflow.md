---
tags: [reference]
---

# 🤝 Team Workflow

Rules from `TEAM-WORKFLOW.md` + `README.md`. Read before contributing.

## Golden rules

1. **Never commit directly to `main`** — always a feature branch
2. Always pull before starting work each day
3. Never force-push to `main`
4. Keep secrets out of git (see [[Gitignore Notes]])
5. Open a Pull Request for review

## Daily flow

```bash
git checkout main
git pull --rebase origin main
git checkout -b feature/your-task-name
# work, then:
git add . && git commit -m "Describe change"
git push -u origin feature/your-task-name
```

Open PR at https://github.com/rekusissu/Registrar-AI-powered-system/pulls

## Recovering from "unrelated histories"

```bash
git fetch origin
git reset --hard origin/main        # ⚠️ erases uncommitted work
```

Keep local changes: `git stash` → reset → `git stash pop`.

## Related

- [[Setup & Verification]] · [[Gitignore Notes]] · [[Source Map]]
