#!/bin/bash
# ANIS - publish the web files of /home/latefcare/anis to /home/latefcare/public_html.
#
# Run by cPanel Git Version Control (.cpanel.yml) after the pull, or by hand:
#
#   /bin/bash /home/latefcare/anis/deploy/cpanel-deploy.sh             publish
#   /bin/bash /home/latefcare/anis/deploy/cpanel-deploy.sh --dry-run   every check, nothing written
#   /bin/bash /home/latefcare/anis/deploy/cpanel-deploy.sh --rollback  put back the previous build
#                                                                       and the backed-up files
#
# Publishes exactly four things to public_html:
#   index.php    from deploy/public_html/index.php (absolute paths, public_path = public_html)
#   favicon.ico  from public/favicon.ico
#   robots.txt   from public/robots.txt
#   build/       from public/build/ (swapped in whole; the previous one kept as build.prev)
#
# Never writes .htaccess (merged by hand once; only checked here), and never touches
# php.ini, .user.ini, .well-known, cgi-bin or anything else in public_html. Never runs
# Composer, npm, Artisan or a migration. Never reads .env.
#
# Paths are fixed. There is no option or variable that changes them.

set -euo pipefail
umask 022

readonly REPO='/home/latefcare/anis'
readonly WEB='/home/latefcare/public_html'
readonly DEPLOY_USER='latefcare'
readonly BACKUPS='/home/latefcare/deploy-backups'
readonly LOCK='/home/latefcare/.anis-deploy.lock'
readonly HTACCESS_MARKER='# BEGIN ANIS Laravel'
readonly HTACCESS_END='# END ANIS Laravel'

MODE='deploy'
case "${1:-}" in
    '') ;;
    --dry-run) MODE='dry-run' ;;
    --rollback) MODE='rollback' ;;
    *) echo "[deploy] Unknown option. Use --dry-run or --rollback, or no option." >&2; exit 2 ;;
esac
if [ "$#" -gt 1 ]; then
    echo "[deploy] Only one option is accepted." >&2
    exit 2
fi

say() { echo "[deploy] $*"; }
fail() { echo "[deploy] REFUSED: $* Nothing was changed." >&2; exit 1; }
# After the swap has started, something has changed: say so, and point to --rollback.
broke() { echo "[deploy] FAILED after publishing started: $* Check the site; to go back run --rollback." >&2; exit 1; }

# --------------------------------------------------------------- where and who

[ "$(id -un)" = "$DEPLOY_USER" ] || fail "run as $DEPLOY_USER, not $(id -un)."
[ -d "$REPO" ] && [ ! -L "$REPO" ] || fail "$REPO is not a directory."
[ -d "$WEB" ] && [ ! -L "$WEB" ] || fail "$WEB is not a directory."
[ "$(cd "$WEB" && pwd -P)" = "$WEB" ] || fail "$WEB resolves elsewhere."
[ "$(cd "$REPO" && pwd -P)" = "$REPO" ] || fail "$REPO resolves elsewhere."

PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] || fail "php is not on PATH (needed to check the manifest and index.php)."

# One run at a time (mkdir is atomic). Not taken by --dry-run, which writes nothing.
if [ "$MODE" != 'dry-run' ]; then
    mkdir "$LOCK" 2>/dev/null || fail "another deployment holds $LOCK (remove it by hand only if no deployment is running)."
    trap 'rmdir "$LOCK" 2>/dev/null || true' EXIT
fi

# --------------------------------------------------------------- rollback

if [ "$MODE" = 'rollback' ]; then
    [ -d "$WEB/build.prev" ] || fail "there is no $WEB/build.prev to go back to."
    [ -d "$WEB/build" ] || fail "there is no live $WEB/build to swap with."
    LAST="$(find "$BACKUPS" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | sort | tail -n 1 || true)"
    [ -n "$LAST" ] && [ -d "$LAST" ] || fail "there is no backup in $BACKUPS."
    say "Rolling back to build.prev and the files in $LAST"

    rm -rf -- "$WEB/build.rollback"
    mv -- "$WEB/build" "$WEB/build.rollback"
    if ! mv -- "$WEB/build.prev" "$WEB/build"; then
        mv -- "$WEB/build.rollback" "$WEB/build"
        fail "the previous build could not be put back; the current one was restored."
    fi
    mv -- "$WEB/build.rollback" "$WEB/build.prev"

    for f in index.php favicon.ico robots.txt; do
        if [ -f "$LAST/$f" ]; then
            cp -p -- "$LAST/$f" "$WEB/.$f.rollback"
            mv -f -- "$WEB/.$f.rollback" "$WEB/$f"
        fi
    done
    say "Rolled back. The build that was live is now $WEB/build.prev."
    exit 0
fi

# --------------------------------------------------------------- source checks

SRC_BUILD="$REPO/public/build"
SRC_INDEX="$REPO/deploy/public_html/index.php"
SRC_HTACCESS="$REPO/public/.htaccess"
HTACCESS_SUM_FILE="$REPO/deploy/laravel-htaccess.sha256"

# Git is required: the branch and the clean tree are checked on every run.
GIT_BIN="$(command -v git || true)"
if [ -z "$GIT_BIN" ] && [ -x /usr/local/cpanel/3rdparty/bin/git ]; then
    GIT_BIN='/usr/local/cpanel/3rdparty/bin/git'
fi
[ -n "$GIT_BIN" ] || fail "git was not found (PATH or /usr/local/cpanel/3rdparty/bin/git): the branch and the working tree cannot be checked."
BRANCH="$("$GIT_BIN" -C "$REPO" rev-parse --abbrev-ref HEAD 2>/dev/null)" \
    || fail "git could not read the branch of $REPO."
[ "$BRANCH" = 'main' ] || fail "the repository is on '$BRANCH', not main."
TREE="$("$GIT_BIN" -C "$REPO" status --porcelain --untracked-files=no 2>/dev/null)" \
    || fail "git could not read the state of the working tree of $REPO."
[ -z "$TREE" ] || fail "the repository has uncommitted changes to tracked files."
COMMIT="$("$GIT_BIN" -C "$REPO" rev-parse --short HEAD 2>/dev/null)" \
    || fail "git could not read the current commit of $REPO."

[ -f "$REPO/vendor/autoload.php" ] || fail "$REPO/vendor/autoload.php is missing (run composer install by hand first)."
[ -f "$REPO/bootstrap/app.php" ] || fail "$REPO/bootstrap/app.php is missing."
[ ! -e "$REPO/public/hot" ] || fail "$REPO/public/hot exists: it would point every page to a Vite dev server."
[ ! -e "$SRC_BUILD/hot" ] || fail "$SRC_BUILD/hot exists."
[ ! -e "$WEB/hot" ] || fail "$WEB/hot exists: remove it by hand, it points every page to a Vite dev server."
[ -f "$SRC_BUILD/manifest.json" ] || fail "$SRC_BUILD/manifest.json is missing."
[ -d "$SRC_BUILD/assets" ] || fail "$SRC_BUILD/assets is missing."

# Every file the manifest names must be in build/, and at least one entry must exist.
# shellcheck disable=SC2016 # PHP code: the $ signs are PHP's, not the shell's.
"$PHP_BIN" -r '
    $dir = $argv[1];
    $m = json_decode((string) file_get_contents($dir."/manifest.json"), true);
    if (! is_array($m) || $m === []) { fwrite(STDERR, "manifest.json is not a non-empty JSON object\n"); exit(1); }
    $entries = 0;
    foreach ($m as $key => $chunk) {
        foreach (array_merge([$chunk["file"] ?? null], $chunk["css"] ?? [], $chunk["assets"] ?? []) as $f) {
            if ($f === null) { continue; }
            if (str_contains($f, "..") || ! is_file($dir."/".$f)) { fwrite(STDERR, "missing or unsafe file in manifest: $f\n"); exit(1); }
        }
        $entries += ! empty($chunk["isEntry"]) ? 1 : 0;
    }
    if ($entries === 0) { fwrite(STDERR, "manifest.json has no entry\n"); exit(1); }
' "$SRC_BUILD" || fail "the Vite build is incomplete."

[ -f "$SRC_INDEX" ] || fail "$SRC_INDEX is missing."
! grep -q "__DIR__\.'/\.\./" "$SRC_INDEX" || fail "$SRC_INDEX still has relative paths."
# shellcheck disable=SC2016 # literal text searched for in the template
for needle in \
    "'/home/latefcare/anis/storage/framework/maintenance.php'" \
    "'/home/latefcare/anis/vendor/autoload.php'" \
    "'/home/latefcare/anis/bootstrap/app.php'" \
    '$app->usePublicPath(__DIR__);'; do
    grep -qF -- "$needle" "$SRC_INDEX" || fail "$SRC_INDEX does not contain: $needle"
done
"$PHP_BIN" -l "$SRC_INDEX" >/dev/null || fail "$SRC_INDEX has a PHP syntax error."

for f in favicon.ico robots.txt; do
    [ -f "$REPO/public/$f" ] || fail "$REPO/public/$f is missing."
done

# .htaccess is merged by hand once; it is only checked here, never written.
# The ANIS block must appear once, end once, and hold the rules that send every
# request for a file or folder that does not exist to index.php.
[ -f "$WEB/.htaccess" ] || fail "$WEB/.htaccess is missing."
HTACCESS_TEXT="$(tr -d '\r' < "$WEB/.htaccess")"   # a file saved with CRLF still matches
[ "$(printf '%s\n' "$HTACCESS_TEXT" | grep -cxF -- "$HTACCESS_MARKER")" = 1 ] \
    || fail "$WEB/.htaccess must contain the line '$HTACCESS_MARKER' exactly once: merge the Laravel rules by hand first."
[ "$(printf '%s\n' "$HTACCESS_TEXT" | grep -cxF -- "$HTACCESS_END")" = 1 ] \
    || fail "$WEB/.htaccess must contain the line '$HTACCESS_END' exactly once."
BLOCK="$(awk -v b="$HTACCESS_MARKER" -v e="$HTACCESS_END" '$0 == b { on = 1; next } $0 == e { if (on) { done = 1 }; on = 0; next } on { print } END { exit done ? 0 : 1 }' <<< "$HTACCESS_TEXT")" \
    || fail "$WEB/.htaccess: '$HTACCESS_END' must come after '$HTACCESS_MARKER'."
for rule in \
    '^[[:space:]]*RewriteEngine[[:space:]]+On[[:space:]]*$' \
    '^[[:space:]]*RewriteCond[[:space:]]+%\{REQUEST_FILENAME\}[[:space:]]+!-d[[:space:]]*$' \
    '^[[:space:]]*RewriteCond[[:space:]]+%\{REQUEST_FILENAME\}[[:space:]]+!-f[[:space:]]*$' \
    '^[[:space:]]*RewriteRule[[:space:]]+\^[[:space:]]+index\.php[[:space:]]+\[L\][[:space:]]*$'; do
    printf '%s\n' "$BLOCK" | grep -qE -- "$rule" \
        || fail "the ANIS block of $WEB/.htaccess lacks a rule matching: $rule"
done
if [ -f "$HTACCESS_SUM_FILE" ]; then
    EXPECTED="$(cut -d' ' -f1 < "$HTACCESS_SUM_FILE")"
    ACTUAL="$(sha256sum "$SRC_HTACCESS" | cut -d' ' -f1)"
    [ "$EXPECTED" = "$ACTUAL" ] || say "WARNING: public/.htaccess changed since the last manual merge; review the ANIS block in $WEB/.htaccess."
fi

ASSETS="$(find "$SRC_BUILD/assets" -type f | wc -l)"
say "Commit $COMMIT: build with $ASSETS asset file(s), index.php template, favicon.ico, robots.txt."

if [ "$MODE" = 'dry-run' ]; then
    say "--dry-run: all checks passed. Would:"
    say "  back up $WEB/{index.php,favicon.ico,robots.txt} to $BACKUPS/<timestamp>/"
    say "  copy $SRC_BUILD/ to $WEB/build.new/, then swap it in as $WEB/build (previous kept as build.prev)"
    say "  replace $WEB/index.php, favicon.ico and robots.txt"
    say "Nothing was written."
    exit 0
fi

# --------------------------------------------------------------- publish

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$BACKUPS/$STAMP-$COMMIT"
mkdir -p -- "$BACKUP"
for f in index.php favicon.ico robots.txt; do
    if [ -f "$WEB/$f" ]; then
        cp -p -- "$WEB/$f" "$BACKUP/$f"
    fi
done
echo "$COMMIT" > "$BACKUP/commit"
say "Backup: $BACKUP"

# 1. The new build, next to the live one. --delete only ever applies inside build.new.
rm -rf -- "$WEB/build.new"
/bin/rsync -a --delete -- "$SRC_BUILD/" "$WEB/build.new/"
cmp -s -- "$SRC_BUILD/manifest.json" "$WEB/build.new/manifest.json" || fail "the copied manifest differs from the source."

# 2. Swap: the live build becomes build.prev, the new one becomes build.
if [ -d "$WEB/build" ]; then
    rm -rf -- "$WEB/build.prev"
    mv -- "$WEB/build" "$WEB/build.prev"
fi
if ! mv -- "$WEB/build.new" "$WEB/build"; then
    if [ -d "$WEB/build.prev" ]; then
        mv -- "$WEB/build.prev" "$WEB/build"
    fi
    echo "[deploy] FAILED: the new build could not be put in place; the previous build was restored." >&2
    exit 1
fi

# 3. index.php, favicon.ico, robots.txt: written beside, then moved over (atomic per file).
cp -- "$SRC_INDEX" "$WEB/.index.php.new"
mv -f -- "$WEB/.index.php.new" "$WEB/index.php"
for f in favicon.ico robots.txt; do
    cp -- "$REPO/public/$f" "$WEB/.$f.new"
    mv -f -- "$WEB/.$f.new" "$WEB/$f"
done

# 4. Verify what is live.
cmp -s -- "$SRC_INDEX" "$WEB/index.php" || broke "the published index.php differs from the template."
cmp -s -- "$SRC_BUILD/manifest.json" "$WEB/build/manifest.json" || broke "the live manifest differs from the source."
[ ! -e "$WEB/hot" ] || broke "$WEB/hot appeared."

if [ -d "$WEB/build.prev" ]; then
    say "Published commit $COMMIT. Previous build kept in $WEB/build.prev; files backed up in $BACKUP."
else
    say "Published commit $COMMIT (first build: no previous build to keep). Files backed up in $BACKUP."
fi
say "Rollback: /bin/bash $REPO/deploy/cpanel-deploy.sh --rollback"
