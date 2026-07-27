# Team Workflow — BCP Registrar System

## Golden Rules

1. **Never commit directly to `main`**
2. **Always create a feature branch** for your work
3. **Always pull before you start working** each day
4. **Never force-push to `main`** (unless the team agrees it's safe)
5. **Keep secrets out of git** — use `.env` (which is gitignored)

## Daily Workflow

### 1. Start your day — sync with the team

```bash
git checkout main
git pull --rebase origin main
```

### 2. Create a branch for your task

```bash
git checkout -b feature/your-task-name
# Examples:
#   feature/students-search
#   fix/login-button-alignment
#   refactor/api-students
```

### 3. Work, commit, push

```bash
# Make your changes...
git add .
git commit -m "Describe what you did"
git push -u origin feature/your-task-name
```

### 4. Open a Pull Request on GitHub

Go to https://github.com/rekusissu/Registrar-AI-powered-system/pulls
→ New Pull Request → choose your branch → describe changes → Create

### 5. Once merged, clean up

```bash
git checkout main
git pull --rebase origin main
git branch -d feature/your-task-name
```

## Recovering from "Unrelated Histories"

If you ever see this error again:

```bash
# ❌ DON'T do this:
git pull          # will fail

# ✅ DO this:
git fetch origin
git reset --hard origin/main
```

⚠️ **This will erase any uncommitted local work.** If you have changes you want to keep:

```bash
git stash                 # save changes
git fetch origin
git reset --hard origin/main
git stash pop             # restore changes
```

## Protected Files

The following are **gitignored** and never go to the repo:

- `config/.env` — your local credentials (each dev has their own)
- `node_modules/` — regenerate with `npm install`
- `vendor/` — regenerate with `composer install`
- `storage/documents/*` — uploaded files (your local XAMPP has them)
- `*.log` — runtime logs

## First-Time Setup on a New Machine

```bash
git clone https://github.com/rekusissu/Registrar-AI-powered-system.git
cd Registrar-AI-powered-system
cp config/.env.example config/.env    # then edit with your local values
composer install
npm install
```

## Why This Matters

- **No more "unrelated histories"** — everyone is adding commits on top of the same shared baseline
- **Pull Requests** let teammates review code before it goes to `main`
- **Feature branches** mean if your work breaks something, only your branch is affected, not the whole team
- **Gitignored secrets** means your database password and API keys stay private
