<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Deployment Script Generator
 * POST /api/generate_script
 *
 * Generates a production-grade deployment script with embedded configuration.
 * Supports two actions:
 *   - "generate" → returns the generated script content as JSON
 *   - "save"     → writes the script to a specified output_path on the server
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\SecurityLogger;
use LightDeploy\Auth\AuthService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('METHOD_NOT_ALLOWED', 'Only POST requests are permitted.', 405);
}

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);

// Require script_gen permission
$user = $authService->requirePermission('script_gen');
session_write_close();

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;

// ── Extract and sanitize inputs ──────────────────────────────────────────────

$scriptType = trim((string)($input['script_type'] ?? 'bash'));

// ── Extract and sanitize inputs ──────────────────────────────────────────────

$action = trim((string)($input['action'] ?? 'generate'));
$appDir = trim((string)($input['app_dir'] ?? ''));
$repoUrl = trim((string)($input['repo_url'] ?? ''));
$branch = trim((string)($input['branch'] ?? 'main'));
$envSource = trim((string)($input['env_source'] ?? ''));
$hasNpm = !empty($input['has_npm']);
$hasBuild = !empty($input['has_build']);
$hasPm2 = !empty($input['has_pm2']);
$appName = trim((string)($input['app_name'] ?? ''));
$siteUser = trim((string)($input['site_user'] ?? 'www'));
$siteGroup = trim((string)($input['site_group'] ?? 'www'));
// ── Action Read (Load existing script from disk) ─────────────────────────────
if ($action === 'read') {
    $filePath = trim((string)($input['file_path'] ?? $input['output_path'] ?? ''));
    if (empty($filePath)) {
        jsonError('INVALID_INPUT', 'File path is required for read action.', 400);
    }
    if (strpos($filePath, '..') !== false) {
        jsonError('INVALID_INPUT', 'Path traversal characters are not permitted.', 400);
    }
    if (!file_exists($filePath)) {
        jsonError('NOT_FOUND', "File does not exist on server: {$filePath}", 404);
    }
    if (!is_readable($filePath)) {
        jsonError('READ_FAILED', "File is not readable: {$filePath}", 403);
    }
    $content = file_get_contents($filePath);
    jsonResponse([
        'success' => true,
        'file_path' => $filePath,
        'content' => $content,
        'message' => 'Script loaded successfully from server.'
    ]);
    exit;
}

// ── Validation ───────────────────────────────────────────────────────────────

if ($scriptType === 'pm2_ecosystem') {
    if (empty($appName)) {
        jsonError('INVALID_INPUT', 'PM2 Application Name is required.', 400);
    }
} else {
    if (empty($appDir)) {
        jsonError('INVALID_INPUT', 'Application directory is required.', 400);
    }

    if (empty($repoUrl)) {
        jsonError('INVALID_INPUT', 'GitHub repository URL is required.', 400);
    }

    if (!preg_match('#^https://github\.com/.+\.git$#', $repoUrl)) {
        jsonError('INVALID_INPUT', 'Repository URL must be a valid GitHub HTTPS URL ending with .git', 400);
    }

    if (empty($branch) || !preg_match('/^[a-zA-Z0-9._\/-]+$/', $branch)) {
        jsonError('INVALID_INPUT', 'Branch name contains invalid characters.', 400);
    }

    if ($hasPm2 && empty($appName)) {
        jsonError('INVALID_INPUT', 'PM2 application name is required when PM2 is enabled.', 400);
    }

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $siteUser) || !preg_match('/^[a-zA-Z0-9_-]+$/', $siteGroup)) {
        jsonError('INVALID_INPUT', 'Site user/group must contain only alphanumeric characters, hyphens, or underscores.', 400);
    }
}

if ($action === 'save' && empty($outputPath)) {
    jsonError('INVALID_INPUT', 'Output file path is required for save action.', 400);
}

// ── Generate script ─────────────────────────────────────────────────────────

if ($scriptType === 'pm2_ecosystem') {
    $scriptContent = generatePm2EcosystemScript($input);
    $defaultFilename = "ecosystem.{$appName}.config.js";
} else {
    $hasNpmStr = $hasNpm ? 'true' : 'false';
    $hasBuildStr = $hasBuild ? 'true' : 'false';
    $hasPm2Str = $hasPm2 ? 'true' : 'false';

    $scriptContent = generateDeploymentScript(
        $appDir, $repoUrl, $branch, $envSource,
        $hasNpmStr, $hasBuildStr, $hasPm2Str,
        $appName, $siteUser, $siteGroup
    );
    $defaultFilename = 'deploy-' . (basename($appDir) ?: 'app') . '.sh';
}

// ── Handle action ────────────────────────────────────────────────────────────

if ($action === 'save') {
    $ext = pathinfo($outputPath, PATHINFO_EXTENSION);
    if ($scriptType === 'pm2_ecosystem') {
        if (!in_array($ext, ['js', 'json', 'cjs'], true)) {
            jsonError('INVALID_INPUT', 'PM2 ecosystem output path must end with .js, .config.js, or .cjs extension.', 400);
        }
    } else {
        if ($ext !== 'sh') {
            jsonError('INVALID_INPUT', 'Deployment script output path must end with .sh extension.', 400);
        }
    }

    // Ensure parent directory exists
    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir)) {
        if (!@mkdir($outputDir, 0755, true)) {
            jsonError('WRITE_FAILED', "Cannot create output directory: {$outputDir}", 500);
        }
    }

    if (!is_writable($outputDir)) {
        jsonError('WRITE_FAILED', "Output directory is not writable: {$outputDir}", 500);
    }

    $written = @file_put_contents($outputPath, $scriptContent, LOCK_EX);
    if ($written === false) {
        jsonError('WRITE_FAILED', "Failed to write script to: {$outputPath}", 500);
    }

    @chmod($outputPath, 0755);

    $securityLogger->log('SCRIPT_GENERATED_SAVED', [
        'script_type' => $scriptType,
        'output_path' => $outputPath,
        'app_name' => $appName,
    ], $user['username']);

    jsonSuccess([
        'message' => "Script saved successfully to: {$outputPath}",
        'output_path' => $outputPath,
        'size_bytes' => $written,
    ]);
}

// Default: generate and return content
$securityLogger->log('SCRIPT_GENERATED', [
    'script_type' => $scriptType,
    'app_name' => $appName,
], $user['username']);

jsonSuccess([
    'message' => 'Script generated successfully.',
    'script_content' => $scriptContent,
    'filename' => $defaultFilename,
]);


// ══════════════════════════════════════════════════════════════════════════════
// SCRIPT TEMPLATE GENERATOR
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Generate PM2 Ecosystem JS configuration script based on user specification
 */
function generatePm2EcosystemScript(array $input): string {
    $appName = trim((string)($input['app_name'] ?? 'solar-backend')) ?: 'solar-backend';
    $script = trim((string)($input['pm2_script'] ?? 'src/index.ts')) ?: 'src/index.ts';
    $interpreter = trim((string)($input['pm2_interpreter'] ?? 'node')) ?: 'node';
    $interpreterArgs = trim((string)($input['pm2_interpreter_args'] ?? '--require esbuild-register'));
    $cwd = trim((string)($input['app_dir'] ?? ($input['cwd'] ?? '/www/wwwroot/apisolar.blueoctopus.site'))) ?: '/www/wwwroot/apisolar.blueoctopus.site';
    $instances = isset($input['pm2_instances']) && is_numeric($input['pm2_instances']) ? (int)$input['pm2_instances'] : 1;
    $execMode = trim((string)($input['pm2_exec_mode'] ?? 'fork')) ?: 'fork';
    $watch = !empty($input['pm2_watch']);
    $maxMemoryRestart = trim((string)($input['pm2_max_memory_restart'] ?? '1G')) ?: '1G';
    
    $nodeEnv = trim((string)($input['pm2_node_env'] ?? 'production')) ?: 'production';
    $port = isset($input['pm2_port']) && is_numeric($input['pm2_port']) ? (int)$input['pm2_port'] : 3000;
    
    $errorFile = trim((string)($input['pm2_error_file'] ?? "/var/log/{$appName}-error.log")) ?: "/var/log/{$appName}-error.log";
    $outFile = trim((string)($input['pm2_out_file'] ?? "/var/log/{$appName}-out.log")) ?: "/var/log/{$appName}-out.log";
    $logFile = trim((string)($input['pm2_log_file'] ?? "/var/log/{$appName}-combined.log")) ?: "/var/log/{$appName}-combined.log";
    
    $time = isset($input['pm2_time']) ? !empty($input['pm2_time']) : true;
    $autorestart = isset($input['pm2_autorestart']) ? !empty($input['pm2_autorestart']) : true;
    $maxRestarts = isset($input['pm2_max_restarts']) && is_numeric($input['pm2_max_restarts']) ? (int)$input['pm2_max_restarts'] : 10;
    $minUptime = trim((string)($input['pm2_min_uptime'] ?? '10s')) ?: '10s';
    
    $killTimeout = isset($input['pm2_kill_timeout']) && is_numeric($input['pm2_kill_timeout']) ? (int)$input['pm2_kill_timeout'] : 5000;
    $listenTimeout = isset($input['pm2_listen_timeout']) && is_numeric($input['pm2_listen_timeout']) ? (int)$input['pm2_listen_timeout'] : 3000;
    $mergeLogs = isset($input['pm2_merge_logs']) ? !empty($input['pm2_merge_logs']) : true;

    $watchStr = $watch ? 'true' : 'false';
    $timeStr = $time ? 'true' : 'false';
    $autorestartStr = $autorestart ? 'true' : 'false';
    $mergeLogsStr = $mergeLogs ? 'true' : 'false';

    $code = "module.exports = {\n";
    $code .= "  apps: [{\n";
    $code .= "    name: " . json_encode($appName) . ",\n";
    $code .= "    script: " . json_encode($script) . ",\n";
    $code .= "    interpreter: " . json_encode($interpreter) . ",\n";
    if (!empty($interpreterArgs)) {
        $code .= "    interpreter_args: " . json_encode($interpreterArgs) . ",\n";
    }
    $code .= "    cwd: " . json_encode($cwd) . ",\n";
    $code .= "    \n";
    $code .= "    instances: {$instances},\n";
    $code .= "    exec_mode: " . json_encode($execMode) . ",\n";
    $code .= "    watch: {$watchStr},\n";
    $code .= "    max_memory_restart: " . json_encode($maxMemoryRestart) . ",\n";
    $code .= "    \n";
    $code .= "    env: {\n";
    $code .= "      NODE_ENV: " . json_encode($nodeEnv) . ",\n";
    $code .= "      PORT: {$port},\n";

    if (!empty($input['pm2_custom_env']) && is_array($input['pm2_custom_env'])) {
        foreach ($input['pm2_custom_env'] as $k => $v) {
            $kClean = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$k);
            if (!empty($kClean) && !in_array($kClean, ['NODE_ENV', 'PORT'], true)) {
                $code .= "      {$kClean}: " . json_encode((string)$v) . ",\n";
            }
        }
    }

    $code .= "    },\n";
    $code .= "    \n";
    $code .= "    error_file: " . json_encode($errorFile) . ",\n";
    $code .= "    out_file: " . json_encode($outFile) . ",\n";
    $code .= "    log_file: " . json_encode($logFile) . ",\n";
    $code .= "    time: {$timeStr},\n";
    $code .= "    \n";
    $code .= "    autorestart: {$autorestartStr},\n";
    $code .= "    max_restarts: {$maxRestarts},\n";
    $code .= "    min_uptime: " . json_encode($minUptime) . ",\n";
    $code .= "    \n";
    $code .= "    kill_timeout: {$killTimeout},\n";
    $code .= "    listen_timeout: {$listenTimeout},\n";
    $code .= "    \n";
    $code .= "    merge_logs: {$mergeLogsStr},\n";
    $code .= "  }]\n";
    $code .= "};\n";

    return $code;
}

function generateDeploymentScript(
    string $appDir, string $repoUrl, string $branch, string $envSource,
    string $hasNpm, string $hasBuild, string $hasPm2,
    string $appName, string $siteUser, string $siteGroup
): string {

    $script = <<<'BASH'
#!/usr/bin/env bash

set -Eeuo pipefail

# ============================================================
# DEPLOYMENT SCRIPT
# ============================================================
# This script was auto-generated by LightDeploy Script Generator
# ============================================================

# ============================================================
# CONFIGURATION
# ============================================================

BASH;

    $script .= 'APP_DIR="' . $appDir . '"' . "\n";
    $script .= 'REPO_URL="' . $repoUrl . '"' . "\n";
    $script .= 'BRANCH="' . $branch . '"' . "\n";
    $script .= 'ENV_SOURCE="' . $envSource . '"' . "\n";
    $script .= 'HAS_NPM="' . $hasNpm . '"' . "\n";
    $script .= 'HAS_BUILD="' . $hasBuild . '"' . "\n";
    $script .= 'HAS_PM2="' . $hasPm2 . '"' . "\n";
    $script .= 'APP_NAME="' . $appName . '"' . "\n";
    $script .= 'SITE_USER="' . $siteUser . '"' . "\n";
    $script .= 'SITE_GROUP="' . $siteGroup . '"' . "\n";

    $script .= <<<'BASH'

GITHUB_TOKEN_FILE="/root/.github_token"
LOG_FILE="/var/log/deploy-$(basename "$APP_DIR").log"
TEMP_DIR=""

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# ============================================================
# LOGGING FUNCTIONS
# ============================================================

log() {
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"
}

success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1" | tee -a "$LOG_FILE"
}

warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1" | tee -a "$LOG_FILE"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1" | tee -a "$LOG_FILE" >&2
}

# ============================================================
# ERROR HANDLER
# ============================================================

error_handler() {
    local exit_code=$?
    local line_number="$1"

    error "Deployment failed at line ${line_number}."
    error "Exit code: ${exit_code}"

    if [[ -n "${TEMP_DIR:-}" && -d "${TEMP_DIR:-}" ]]; then
        warning "Removing temporary deployment directory..."
        rm -rf -- "$TEMP_DIR" 2>/dev/null || true
    fi

    error "Deployment aborted."

    exit "$exit_code"
}

trap 'error_handler $LINENO' ERR

# ============================================================
# CLEANUP
# ============================================================

cleanup() {
    if [[ -n "${TEMP_DIR:-}" && -d "${TEMP_DIR:-}" ]]; then
        rm -rf -- "$TEMP_DIR" 2>/dev/null || true
    fi
}

trap cleanup EXIT

# ============================================================
# START DEPLOYMENT
# ============================================================

log "============================================================"
log "Starting deployment"
log "============================================================"
log "Application directory: $APP_DIR"
log "Repository: $REPO_URL"
log "Branch: $BRANCH"
log "Has npm install: $HAS_NPM"
log "Has build: $HAS_BUILD"
log "Has PM2: $HAS_PM2"
if [[ -n "$ENV_SOURCE" ]]; then
    log "Environment source: $ENV_SOURCE"
fi
log "============================================================"

# ============================================================
# ROOT CHECK
# ============================================================

if [[ "$EUID" -ne 0 ]]; then
    error "This script must be run as root."
    exit 1
fi

# ============================================================
# DIRECTORY SAFETY CHECK
# ============================================================

if [[ ! -d "$APP_DIR" ]]; then
    error "Application directory does not exist:"
    error "$APP_DIR"
    exit 1
fi

# ============================================================
# REQUIRED COMMANDS
# ============================================================

REQUIRED_CMDS=("git" "find" "rm" "cp" "mktemp" "chown" "stat")

if [[ "$HAS_NPM" == "true" ]] || [[ "$HAS_BUILD" == "true" ]]; then
    REQUIRED_CMDS+=("npm")
fi

for cmd in "${REQUIRED_CMDS[@]}"; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        error "Required command not found: $cmd"
        exit 1
    fi
done

# ============================================================
# CHECK WWW USER/GROUP
# ============================================================

if ! id "$SITE_USER" >/dev/null 2>&1; then
    error "User does not exist: $SITE_USER"
    exit 1
fi

if ! getent group "$SITE_GROUP" >/dev/null 2>&1; then
    error "Group does not exist: $SITE_GROUP"
    exit 1
fi

# ============================================================
# CHECK ENV FILE (if provided)
# ============================================================

if [[ -n "$ENV_SOURCE" ]]; then
    if [[ ! -f "$ENV_SOURCE" ]]; then
        error "Environment file does not exist:"
        error "$ENV_SOURCE"
        exit 1
    fi
else
    log "No environment file provided, skipping .env installation"
fi

# ============================================================
# CHECK GITHUB TOKEN
# ============================================================

if [[ ! -f "$GITHUB_TOKEN_FILE" ]]; then
    error "GitHub token file does not exist:"
    error "$GITHUB_TOKEN_FILE"
    error "Please create this file with your GitHub Personal Access Token"
    exit 1
fi

GITHUB_TOKEN=$(cat "$GITHUB_TOKEN_FILE" | tr -d '\n\r')

if [[ -z "$GITHUB_TOKEN" ]]; then
    error "GitHub token file is empty:"
    error "$GITHUB_TOKEN_FILE"
    exit 1
fi

success "Environment checks passed."

# ============================================================
# CLONE TO TEMPORARY DIRECTORY
# ============================================================

log "Creating temporary deployment directory..."

TEMP_DIR="$(mktemp -d /tmp/deploy-XXXXXXXX)"

log "Temporary directory: $TEMP_DIR"

log "Cloning repository..."

# Clone using HTTPS with token
git clone \
    --depth 1 \
    --single-branch \
    --branch "$BRANCH" \
    "https://${GITHUB_TOKEN}@${REPO_URL#https://}" \
    "$TEMP_DIR"

success "Git clone completed."

# ============================================================
# VALIDATE REPOSITORY
# ============================================================

if [[ ! -d "$TEMP_DIR/.git" ]]; then
    error "Git repository validation failed."
    exit 1
fi

success "Repository validated."

# ============================================================
# REMOVE IMMUTABLE FILES
# ============================================================

log "Checking for immutable files..."

while IFS= read -r -d '' file; do
    log "Removing immutable flag: $file"
    chattr -i "$file" 2>/dev/null || true
done < <(
    find "$APP_DIR" \
        -type f \
        \( -name ".user.ini" -o -name ".htaccess" \) \
        -print0 \
        2>/dev/null || true
)

# ============================================================
# CLEAN OLD DEPLOYMENT
# ============================================================

log "Cleaning old files..."

find "$APP_DIR" \
    -mindepth 1 \
    -maxdepth 1 \
    -exec rm -rf -- {} +

success "Old files removed."

# ============================================================
# COPY APPLICATION FILES
# ============================================================

log "Copying application files..."

cp -R "$TEMP_DIR"/. "$APP_DIR"/

success "Application files copied."

# ============================================================
# COPY ENVIRONMENT FILE (if provided)
# ============================================================

if [[ -n "$ENV_SOURCE" ]]; then
    log "Installing environment configuration from: $ENV_SOURCE"
    cp "$ENV_SOURCE" "$APP_DIR/.env"
    success ".env installed to $APP_DIR/.env"
else
    log "No environment file provided, skipping .env installation"
fi

# ============================================================
# NPM INSTALL (if enabled)
# ============================================================

if [[ "$HAS_NPM" == "true" ]]; then
    log "Installing npm dependencies..."

    cd "$APP_DIR"

    # Check if package.json exists
    if [[ ! -f "$APP_DIR/package.json" ]]; then
        error "package.json not found in application directory"
        exit 1
    fi

    npm install
    success "NPM dependencies installed."
else
    log "npm install skipped"
fi

# ============================================================
# NPM BUILD (if enabled)
# ============================================================

if [[ "$HAS_BUILD" == "true" ]]; then
    log "Building application..."

    cd "$APP_DIR"

    # Check if build script exists in package.json
    if grep -q '"build"' "$APP_DIR/package.json"; then
        npm run build
        success "Application build completed."

        # Check for build output directory
        if [[ -d "$APP_DIR/dist" ]]; then
            log "Build output found in: dist/"
        elif [[ -d "$APP_DIR/build" ]]; then
            log "Build output found in: build/"
        elif [[ -d "$APP_DIR/.next" ]]; then
            log "Build output found in: .next/"
        elif [[ -d "$APP_DIR/out" ]]; then
            log "Build output found in: out/"
        else
            warning "Could not detect build output directory."
            warning "Common locations: dist/, build/, .next/, out/"
        fi
    else
        warning "No build script found in package.json"
        warning "Skipping build step."
    fi
else
    log "Build skipped"
fi

# ============================================================
# SET OWNERSHIP
# ============================================================

log "Setting ownership to ${SITE_USER}:${SITE_GROUP}..."

chown -R "${SITE_USER}:${SITE_GROUP}" "$APP_DIR"

success "Ownership set to ${SITE_USER}:${SITE_GROUP}."

# ============================================================
# VERIFY DEPLOYMENT
# ============================================================

if [[ -z "$(find "$APP_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
    error "Application directory is empty."
    exit 1
fi

success "Deployment verified."

# ============================================================
# CHECK FOR INDEX FILE (frontend detection)
# ============================================================

if [[ "$HAS_PM2" != "true" ]]; then
    log "Checking for index file..."

    INDEX_FOUND=false

    if [[ -f "$APP_DIR/index.html" ]]; then
        INDEX_FOUND=true
        log "Found: index.html"
    elif [[ -f "$APP_DIR/index.php" ]]; then
        INDEX_FOUND=true
        log "Found: index.php"
    else
        # Check in build directories
        for dir in dist build out public; do
            if [[ -d "$APP_DIR/$dir" && -f "$APP_DIR/$dir/index.html" ]]; then
                INDEX_FOUND=true
                log "Found: $dir/index.html"
                break
            fi
        done
    fi

    if [[ "$INDEX_FOUND" == "false" ]]; then
        warning "No index file found (index.html, index.php, or build output)"
        warning "Make sure your application is properly built"
    fi
fi

# ============================================================
# CHECK FOR MAIN ENTRY POINT (backend detection)
# ============================================================

if [[ "$HAS_NPM" == "true" ]]; then
    log "Checking for main entry point..."

    ENTRY_FOUND=false

    if [[ -f "$APP_DIR/index.js" ]]; then
        ENTRY_FOUND=true
        log "Found: index.js"
    elif [[ -f "$APP_DIR/app.js" ]]; then
        ENTRY_FOUND=true
        log "Found: app.js"
    elif [[ -f "$APP_DIR/server.js" ]]; then
        ENTRY_FOUND=true
        log "Found: server.js"
    elif [[ -f "$APP_DIR/package.json" ]]; then
        # Try to get main entry from package.json
        MAIN_ENTRY=$(grep -o '"main": *"[^"]*"' "$APP_DIR/package.json" | cut -d'"' -f4 || echo "")
        if [[ -n "$MAIN_ENTRY" && -f "$APP_DIR/$MAIN_ENTRY" ]]; then
            ENTRY_FOUND=true
            log "Found main entry from package.json: $MAIN_ENTRY"
        fi
    fi

    if [[ "$ENTRY_FOUND" == "false" ]]; then
        warning "No main entry point found (index.js, app.js, server.js, or package.json main)"
        warning "Make sure your application has a proper entry point"
    fi
fi

# ============================================================
# GIT COMMIT
# ============================================================

if [[ -d "$APP_DIR/.git" ]]; then
    COMMIT="$(
        git -C "$APP_DIR" rev-parse --short HEAD 2>/dev/null \
        || echo "unknown"
    )"
    log "Deployed commit: $COMMIT"
fi

# ============================================================
# PM2 RESTART (if enabled)
# ============================================================

if [[ "$HAS_PM2" == "true" ]]; then
    log "Checking process manager..."

    if command -v pm2 >/dev/null 2>&1; then
        # Check if app is running with PM2
        if pm2 list | grep -q "$APP_NAME"; then
            log "Restarting application with PM2..."
            pm2 restart "$APP_NAME"
            success "PM2 restart completed."
        else
            # Try to find the main entry file
            if [[ -f "$APP_DIR/index.js" ]]; then
                ENTRY_FILE="index.js"
            elif [[ -f "$APP_DIR/app.js" ]]; then
                ENTRY_FILE="app.js"
            elif [[ -f "$APP_DIR/server.js" ]]; then
                ENTRY_FILE="server.js"
            else
                ENTRY_FILE=""
            fi

            if [[ -n "$ENTRY_FILE" ]]; then
                log "Starting application with PM2..."
                pm2 start "$APP_DIR/$ENTRY_FILE" --name "$APP_NAME"
                success "PM2 started application."
            else
                warning "Could not find entry file for PM2. Please start manually."
            fi
        fi

        # Save PM2 configuration
        pm2 save

    else
        warning "PM2 not found. Please restart application manually."

        # Try systemd if available
        if systemctl list-unit-files 2>/dev/null | grep -q "$APP_NAME"; then
            log "Restarting systemd service..."
            systemctl restart "$APP_NAME" || warning "Systemd restart failed"
            success "Systemd service restarted."
        fi
    fi
else
    log "PM2 skipped"

    # Restart web server for frontend
    log "Checking web server status..."

    if command -v nginx >/dev/null 2>&1; then
        if systemctl is-active --quiet nginx 2>/dev/null; then
            log "Reloading Nginx configuration..."
            systemctl reload nginx || warning "Nginx reload failed"
            success "Nginx reloaded."
        fi
    elif command -v apache2 >/dev/null 2>&1; then
        if systemctl is-active --quiet apache2 2>/dev/null; then
            log "Reloading Apache configuration..."
            systemctl reload apache2 || warning "Apache reload failed"
            success "Apache reloaded."
        fi
    fi
fi

# ============================================================
# SUCCESS
# ============================================================

echo
echo "============================================================"
echo -e "${GREEN}      DEPLOYMENT SUCCESSFUL${NC}"
echo "============================================================"
echo "Application : $APP_DIR"
echo "Repository  : $REPO_URL"
echo "Branch      : $BRANCH"
echo "Owner       : ${SITE_USER}:${SITE_GROUP}"
if [[ -f "$APP_DIR/.env" ]]; then
    echo "Environment : $APP_DIR/.env (copied from $ENV_SOURCE)"
else
    echo "Environment : Not installed"
fi
echo "NPM install : $HAS_NPM"
echo "Build       : $HAS_BUILD"
echo "PM2         : $HAS_PM2"
[[ "$HAS_PM2" == "true" ]] && echo "PM2 App     : $APP_NAME"
echo "Time        : $(date '+%Y-%m-%d %H:%M:%S')"
echo "============================================================"

exit 0
BASH;

    return $script;
}
