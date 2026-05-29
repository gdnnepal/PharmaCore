#!/usr/bin/env bash
# =============================================================================
#  PharmaCore — One-Click Installer
#  Usage:
#    bash <(curl -fsSL https://raw.githubusercontent.com/gdnnepal/PharmaCore/main/install.sh)
#  Or after cloning:
#    bash install.sh
# =============================================================================

set -euo pipefail

# ── Colours ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

info()    { echo -e "${CYAN}[INFO]${RESET}  $*"; }
success() { echo -e "${GREEN}[OK]${RESET}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${RESET}  $*"; }
error()   { echo -e "${RED}[ERROR]${RESET} $*" >&2; }
die()     { error "$*"; exit 1; }

# ── Banner ────────────────────────────────────────────────────────────────────
echo -e "${BOLD}${CYAN}"
echo "  ██████╗ ██╗  ██╗ █████╗ ██████╗ ███╗   ███╗ █████╗  ██████╗ ██████╗ ██████╗ ███████╗"
echo "  ██╔══██╗██║  ██║██╔══██╗██╔══██╗████╗ ████║██╔══██╗██╔════╝██╔═══██╗██╔══██╗██╔════╝"
echo "  ██████╔╝███████║███████║██████╔╝██╔████╔██║███████║██║     ██║   ██║██████╔╝█████╗  "
echo "  ██╔═══╝ ██╔══██║██╔══██║██╔══██╗██║╚██╔╝██║██╔══██║██║     ██║   ██║██╔══██╗██╔══╝  "
echo "  ██║     ██║  ██║██║  ██║██║  ██║██║ ╚═╝ ██║██║  ██║╚██████╗╚██████╔╝██║  ██║███████╗"
echo "  ╚═╝     ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝╚═╝     ╚═╝╚═╝  ╚═╝ ╚═════╝ ╚═════╝ ╚═╝  ╚═╝╚══════╝"
echo -e "${RESET}"
echo -e "${BOLD}  Pharmacy Management System — Installer${RESET}"
echo    "  https://github.com/gdnnepal/PharmaCore"
echo

# ── Dependency checks ─────────────────────────────────────────────────────────
info "Checking dependencies..."

command -v git  >/dev/null 2>&1 || die "git is not installed. Install it and re-run."
command -v php  >/dev/null 2>&1 || die "PHP is not installed. Install PHP 8.0+ and re-run."
command -v mysql>/dev/null 2>&1 || warn "mysql client not found — DB creation will be skipped (create it manually)."

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')

if [[ "$PHP_MAJOR" -lt 8 ]]; then
    die "PHP 8.0+ is required. Found PHP $PHP_VERSION."
fi
success "PHP $PHP_VERSION detected."

# Check required PHP extensions
for ext in pdo pdo_mysql curl json mbstring; do
    php -m | grep -qi "^${ext}$" || die "PHP extension '$ext' is missing. Enable it in php.ini."
done
success "All required PHP extensions present."

# ── Installation directory ────────────────────────────────────────────────────
INSTALL_DIR=""
if [[ -f "$(pwd)/config.php" && -f "$(pwd)/installer.php" ]]; then
    # Already inside the repo
    INSTALL_DIR="$(pwd)"
    info "Running inside existing repo at: $INSTALL_DIR"
else
    echo
    read -rp "$(echo -e "${BOLD}Install directory${RESET} [default: $(pwd)/PharmaCore]: ")" INSTALL_DIR
    INSTALL_DIR="${INSTALL_DIR:-$(pwd)/PharmaCore}"

    if [[ -d "$INSTALL_DIR" && -f "$INSTALL_DIR/config.php" ]]; then
        warn "Directory already exists. Pulling latest changes..."
        git -C "$INSTALL_DIR" pull --ff-only || warn "Git pull failed — continuing with existing files."
    else
        info "Cloning PharmaCore into $INSTALL_DIR ..."
        git clone https://github.com/gdnnepal/PharmaCore.git "$INSTALL_DIR"
        success "Repository cloned."
    fi
fi

cd "$INSTALL_DIR"

# ── Remove stale install.lock so installer can run ───────────────────────────
if [[ -f "install.lock" ]]; then
    warn "install.lock found. Removing to allow re-configuration..."
    rm -f install.lock
fi

# ── Collect database credentials ─────────────────────────────────────────────
echo
echo -e "${BOLD}── Database Configuration ──────────────────────────────────${RESET}"
read -rp "DB Host     [127.0.0.1]: " DB_HOST;  DB_HOST="${DB_HOST:-127.0.0.1}"
read -rp "DB Port     [3306]:      " DB_PORT;  DB_PORT="${DB_PORT:-3306}"
read -rp "DB Name     [pharmacore]:  " DB_NAME;  DB_NAME="${DB_NAME:-pharmacore}"
read -rp "DB User     [root]:      " DB_USER;  DB_USER="${DB_USER:-root}"
read -rsp "DB Password [leave blank if none]: " DB_PASS; echo
DB_PASS="${DB_PASS:-}"

# ── Collect admin credentials ─────────────────────────────────────────────────
echo
echo -e "${BOLD}── Admin Account ────────────────────────────────────────────${RESET}"
read -rp "Admin Username [admin]: " ADMIN_USER; ADMIN_USER="${ADMIN_USER:-admin}"
read -rp "Admin Full Name [Administrator]: " ADMIN_NAME; ADMIN_NAME="${ADMIN_NAME:-Administrator}"
while true; do
    read -rsp "Admin Password (min 8 chars): " ADMIN_PASS; echo
    if [[ ${#ADMIN_PASS} -lt 8 ]]; then
        warn "Password must be at least 8 characters."
    else
        read -rsp "Confirm Password: " ADMIN_PASS2; echo
        if [[ "$ADMIN_PASS" != "$ADMIN_PASS2" ]]; then
            warn "Passwords do not match. Try again."
        else
            break
        fi
    fi
done

# ── Collect pharmacy details ──────────────────────────────────────────────────
echo
echo -e "${BOLD}── Pharmacy Information ─────────────────────────────────────${RESET}"
read -rp "Pharmacy Name:    " PHARMACY_NAME
read -rp "Address:          " PHARMACY_ADDR
read -rp "Phone Number:     " PHARMACY_PHONE
read -rp "Email (optional): " PHARMACY_EMAIL
read -rp "PAN/VAT Number:   " PAN_VAT
read -rp "DDA No:           " DDA_NO

# ── Create database (if mysql client available) ───────────────────────────────
if command -v mysql >/dev/null 2>&1; then
    info "Creating database '$DB_NAME' if it doesn't exist..."
    MYSQL_CMD="mysql -h\"$DB_HOST\" -P\"$DB_PORT\" -u\"$DB_USER\""
    [[ -n "$DB_PASS" ]] && MYSQL_CMD="$MYSQL_CMD -p\"$DB_PASS\""
    eval "$MYSQL_CMD -e \"CREATE DATABASE IF NOT EXISTS \\\`${DB_NAME}\\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\"" \
        && success "Database ready." \
        || warn "Could not create database automatically. Create it manually and continue."
fi

# ── Write .env file ───────────────────────────────────────────────────────────
info "Writing .env file..."
cat > .env <<EOF
# PharmaCore Environment — generated by install.sh on $(date -u +"%Y-%m-%dT%H:%M:%SZ")

# Database
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
EOF
success ".env written."

# ── Run PHP CLI installer ─────────────────────────────────────────────────────
info "Running database setup via PHP CLI..."

php - <<PHPSCRIPT
<?php
declare(strict_types=1);

// Load .env
\$lines = file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
foreach(\$lines as \$line){
    \$line = trim(\$line);
    if(\$line === '' || str_starts_with(\$line, '#') || !str_contains(\$line, '=')) continue;
    [\$k, \$v] = explode('=', \$line, 2);
    putenv(trim(\$k) . '=' . trim(\$v));
}

\$host  = getenv('DB_HOST') ?: '127.0.0.1';
\$port  = (int)(getenv('DB_PORT') ?: 3306);
\$name  = getenv('DB_NAME') ?: 'pharmacore';
\$user  = getenv('DB_USER') ?: 'root';
\$pass  = getenv('DB_PASS') ?: '';

echo "Connecting to MySQL at \$host:\$port ...\n";

try {
    \$pdo = new PDO(
        "mysql:host=\$host;port=\$port;dbname=\$name;charset=utf8mb4",
        \$user, \$pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    echo "Connected.\n";
} catch(PDOException \$e){
    echo "DB connection failed: " . \$e->getMessage() . "\n";
    exit(1);
}

// Import SQL dump
\$sqlFile = __DIR__ . '/pharmacy_npr.sql';
if(is_file(\$sqlFile)){
    echo "Importing SQL schema...\n";
    \$sql = file_get_contents(\$sqlFile);
    \$pdo->exec('DROP TRIGGER IF EXISTS trg_audit_logs_no_delete');
    foreach(array_filter(array_map('trim', explode(';', \$sql))) as \$stmt){
        if(\$stmt === '' || str_starts_with(\$stmt, '--') || str_starts_with(\$stmt, '/*')) continue;
        try { \$pdo->exec(\$stmt); } catch(PDOException \$e){ /* ignore migration warnings */ }
    }
    echo "Schema imported.\n";
}

// Permissions
\$perms = [
    ['dashboard.view','View Dashboard','dashboard'],
    ['sale.create','Create Sale','sales'],
    ['sale.credit','Allow Credit Sale','sales'],
    ['sales_record.view','View Sales Record','sales'],
    ['inventory.view','View Inventory','inventory'],
    ['inventory.manage','Manage Inventory','inventory'],
    ['inventory.transfer','Transfer Inventory Stock','inventory'],
    ['inventory.transfer_record.view','View Stock Transfer Records','inventory'],
    ['inventory.transfer.reverse','Reverse Stock Transfer','inventory'],
    ['customers.manage','Manage Customers','customers'],
    ['customers.view','View Customers','customers'],
    ['customers.create','Add Customers','customers'],
    ['customers.edit','Edit Customers','customers'],
    ['customers.delete','Delete Customers','customers'],
    ['customers.payment','Manage Customer Payments','customers'],
    ['suppliers.manage','Manage Suppliers','suppliers'],
    ['report.view','View Reports','reports'],
    ['settings.manage','Manage Settings','settings'],
    ['branch.manage','Manage Branches','admin'],
    ['user.manage','Manage Users','admin'],
];
\$ins = \$pdo->prepare('INSERT INTO permissions(permission_key,label,category) VALUES(?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),category=VALUES(category)');
foreach(\$perms as \$p) \$ins->execute(\$p);

// Admin user
\$adminUser = '${ADMIN_USER}';
\$adminName = '${ADMIN_NAME}';
\$adminHash = password_hash('${ADMIN_PASS}', PASSWORD_DEFAULT);
\$pdo->prepare('INSERT INTO users(username,password_hash,full_name,branch_id,is_admin,is_active) VALUES(?,?,?,NULL,1,1) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),full_name=VALUES(full_name),is_admin=1,is_active=1')
    ->execute([\$adminUser, \$adminHash, \$adminName]);

\$adminId = (int)\$pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1')->execute([\$adminUser]) ? \$pdo->lastInsertId() : 0;
\$adminIdStmt = \$pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
\$adminIdStmt->execute([\$adminUser]);
\$adminId = (int)\$adminIdStmt->fetchColumn();

if(\$adminId > 0){
    \$allPermIds = \$pdo->query('SELECT id FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
    \$ap = \$pdo->prepare('INSERT IGNORE INTO user_permissions(user_id,permission_id) VALUES(?,?)');
    foreach(\$allPermIds as \$pid) \$ap->execute([\$adminId, (int)\$pid]);
}

// Pharmacy details
\$pdo->prepare('INSERT INTO pharmacy_details(id,pharmacy_name,address,phone_number,email,pan_vat,dda_no) VALUES(1,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE pharmacy_name=VALUES(pharmacy_name),address=VALUES(address),phone_number=VALUES(phone_number),email=VALUES(email),pan_vat=VALUES(pan_vat),dda_no=VALUES(dda_no)')
    ->execute(['${PHARMACY_NAME}','${PHARMACY_ADDR}','${PHARMACY_PHONE}','${PHARMACY_EMAIL}','${PAN_VAT}','${DDA_NO}']);

// App settings
\$pdo->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES('show_pos_menu','1') ON DUPLICATE KEY UPDATE setting_value='1'")->execute();

// Write install.lock
file_put_contents(__DIR__ . '/install.lock', 'Installed via CLI on ' . date('c') . PHP_EOL);

echo "Installation complete.\n";
PHPSCRIPT

if [[ $? -ne 0 ]]; then
    die "PHP setup script failed. Check the error above."
fi

success "Database configured and admin account created."

# ── File permissions ──────────────────────────────────────────────────────────
info "Setting file permissions..."
chmod 640 .env 2>/dev/null || true
chmod 644 install.lock 2>/dev/null || true

# ── Optional: npm build ───────────────────────────────────────────────────────
if command -v npm >/dev/null 2>&1 && [[ -f "package.json" ]]; then
    info "Installing npm dependencies and building CSS..."
    npm install --silent && npm run build --silent \
        && success "CSS built." \
        || warn "npm build failed — using pre-built CSS from repo."
else
    info "npm not found — using pre-built CSS from repo (css/style.css)."
fi

# ── Done ──────────────────────────────────────────────────────────────────────
echo
echo -e "${GREEN}${BOLD}╔══════════════════════════════════════════════════════════╗${RESET}"
echo -e "${GREEN}${BOLD}║         PharmaCore installed successfully!               ║${RESET}"
echo -e "${GREEN}${BOLD}╚══════════════════════════════════════════════════════════╝${RESET}"
echo
echo -e "  ${BOLD}Admin username:${RESET} $ADMIN_USER"
echo -e "  ${BOLD}Install path:${RESET}   $INSTALL_DIR"
echo
echo -e "  ${BOLD}Next steps:${RESET}"
echo    "  1. Point your web server (Apache/Nginx) to: $INSTALL_DIR"
echo    "  2. Open your browser and go to your configured domain/path"
echo    "  3. Log in with the admin credentials you just set"
echo
echo -e "  ${YELLOW}Keep your .env file secure — never commit it to git.${RESET}"
echo
