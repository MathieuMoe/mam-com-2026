# ==========================================================================
# mam-com-2026 - create the Git repository and publish it on GitHub
# Run in PowerShell:  cd C:\Users\Mathieu\_WEB\mam-com.com\www ; .\git-init.ps1
# ==========================================================================
$ErrorActionPreference = "Stop"
Set-Location "C:\Users\Mathieu\_WEB\mam-com.com\www"

# 1) Safety check: the secret files must be ignored
git init -b main
git add .
$leak = git diff --cached --name-only | Select-String -Pattern '^(api|app)/config\.php$'
if ($leak) { Write-Host "STOP: a config.php file would be committed:" $leak -ForegroundColor Red; git reset; exit 1 }

# 2) First commit
git commit -m "mam-com.com 2026: Spectrum redesign (WebGL, 3D CSS, AI assistant) + previous version in /old_v1-02/"

# 3) Create the GitHub repository and push
#    With GitHub CLI (https://cli.github.com, then once: gh auth login):
gh repo create MathieuMoe/mam-com-2026 --private --source . --remote origin --push --description "Portfolio mam-com.com - design x code x AI (FR/EN/ES)"

#    Without GitHub CLI: create an EMPTY repo "mam-com-2026" on https://github.com/new, then run instead:
#    git remote add origin https://github.com/MathieuMoe/mam-com-2026.git
#    git push -u origin main
