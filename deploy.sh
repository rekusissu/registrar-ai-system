#!/bin/bash
# ============================================================
# DEPLOYMENT SCRIPT - Pre-Deployment Hardening Automation
# ============================================================
# This script automates security configuration before deployment.
# Run this on the production server after cloning the repository.
#
# Usage: bash deploy.sh
# ============================================================

set -e  # Exit on error

echo "=========================================="
echo "Registrar AI System - Pre-Deployment Setup"
echo "=========================================="
echo ""

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# ── Step 1: Verify Prerequisites ──────────────────────────
echo -e "${YELLOW}Step 1: Verifying prerequisites...${NC}"

if ! command -v php &> /dev/null; then
    echo -e "${RED}ERROR: PHP is not installed${NC}"
    exit 1
fi

if ! command -v mysql &> /dev/null; then
    echo -e "${RED}ERROR: MySQL CLI is not installed${NC}"
    exit 1
fi

if ! command -v openssl &> /dev/null; then
    echo -e "${RED}ERROR: OpenSSL is not installed${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Prerequisites verified${NC}"
echo ""

# ── Step 2: Create .env File ──────────────────────────────
echo -e "${YELLOW}Step 2: Creating .env file...${NC}"

if [ -f .env ]; then
    echo -e "${YELLOW}Warning: .env already exists. Backing up to .env.bak${NC}"
    cp .env .env.bak
fi

# Generate strong secrets
JWT_SECRET=$(openssl rand -hex 32)
KIOSK_TOKEN=$(openssl rand -hex 16)

# Create .env file
cat > .env << EOF
# Application Environment
APP_ENV=production
USE_HTTPS=true

# Database Configuration (UPDATE WITH YOUR VALUES)
DB_HOST=localhost
DB_NAME=registrar_ai
DB_USER=registrar_admin
DB_PASSWORD=CHANGE_THIS_TO_STRONG_PASSWORD
DB_CHARSET=utf8mb4

# Session Configuration
SESSION_IDLE_TIMEOUT=1200

# Security Tokens (Auto-generated - DO NOT share)
JWT_SECRET=$JWT_SECRET
KIOSK_ACCESS_TOKEN=$KIOSK_TOKEN

# OTP Configuration
OTP_SHOW_ONSCREEN=false

# AI Gateway (Optional)
NINEROUTER_URL=http://localhost:20128
AI_API_KEY=

# CORS Configuration (Restrict to your domains in production)
CORS_ALLOWED_ORIGINS=https://registrar.bestlink.edu.ph,https://kiosk.bestlink.edu.ph

# Email Configuration (Optional - if using SMTP)
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM=no-reply@bestlink.edu.ph
EOF

echo -e "${GREEN}✓ .env file created${NC}"
echo -e "${YELLOW}  ACTION REQUIRED: Edit .env and set DB_PASSWORD and other values${NC}"
echo ""

# ── Step 3: Set File Permissions ──────────────────────────
echo -e "${YELLOW}Step 3: Setting file permissions...${NC}"

chmod 600 .env
chmod 600 shared/config.php

mkdir -p logs uploads/student_files
chmod 750 logs
chmod 750 uploads
chmod 750 uploads/student_files

echo -e "${GREEN}✓ File permissions configured${NC}"
echo ""

# ── Step 4: Create Log Rotation Config ────────────────────
echo -e "${YELLOW}Step 4: Setting up log rotation...${NC}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cat > /tmp/registrar-ai-logrotate << EOF
$SCRIPT_DIR/logs/*.log {
    daily
    rotate 30
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
}
EOF

echo -e "${GREEN}✓ Log rotation template created at /tmp/registrar-ai-logrotate${NC}"
echo -e "${YELLOW}  ACTION REQUIRED: sudo mv /tmp/registrar-ai-logrotate /etc/logrotate.d/registrar-ai${NC}"
echo ""

# ── Step 5: Database Setup ────────────────────────────────
echo -e "${YELLOW}Step 5: Database configuration (manual steps required)${NC}"
echo -e "${YELLOW}  1. Connect to MySQL as admin: mysql -u root -p${NC}"
echo -e "${YELLOW}  2. Run the following SQL commands:${NC}"
echo ""

cat << 'EOF'
-- Create application user
CREATE USER 'registrar_admin'@'localhost' IDENTIFIED BY 'YOUR_STRONG_PASSWORD';

-- Grant permissions
REVOKE ALL PRIVILEGES ON *.* FROM 'registrar_admin'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON registrar_ai.* TO 'registrar_admin'@'localhost';
FLUSH PRIVILEGES;

-- Verify permissions
SHOW GRANTS FOR 'registrar_admin'@'localhost';
EOF

echo ""
echo -e "${YELLOW}  3. After creating user, apply migrations:${NC}"
echo -e "${YELLOW}     mysql -u registrar_admin -p registrar_ai < database/login_security.sql${NC}"
echo -e "${YELLOW}     mysql -u registrar_admin -p registrar_ai < database/security_upgrade.sql${NC}"
echo ""

# ── Step 6: Verify Configuration ──────────────────────────
echo -e "${YELLOW}Step 6: Verifying configuration...${NC}"

# Check if .env is readable only by owner
PERMS=$(stat -f %A .env 2>/dev/null || stat -c %a .env 2>/dev/null || echo "unknown")
if [ "$PERMS" != "600" ] && [ "$PERMS" != "unknown" ]; then
    echo -e "${YELLOW}Warning: .env permissions are $PERMS (should be 600)${NC}"
fi

# Check if logs directory exists and is writable
if [ ! -d logs ]; then
    echo -e "${RED}ERROR: logs directory not found${NC}"
    exit 1
fi

if [ ! -w logs ]; then
    echo -e "${RED}ERROR: logs directory is not writable${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Configuration verified${NC}"
echo ""

# ── Step 7: Generate Security Summary ────────────────────
echo -e "${YELLOW}Step 7: Security summary${NC}"
echo ""
echo -e "${GREEN}Generated Secrets (save these securely, do NOT commit to git):${NC}"
echo "  JWT_SECRET: $JWT_SECRET"
echo "  KIOSK_ACCESS_TOKEN: $KIOSK_TOKEN"
echo ""

# ── Final Instructions ────────────────────────────────────
echo "=========================================="
echo -e "${GREEN}Pre-Deployment Setup Complete!${NC}"
echo "=========================================="
echo ""
echo -e "${YELLOW}NEXT STEPS:${NC}"
echo ""
echo "1. Edit .env file with your production values:"
echo "   nano .env"
echo "   - Set DB_PASSWORD to your strong database password"
echo "   - Set DB_HOST if using remote database"
echo "   - Customize CORS_ALLOWED_ORIGINS for your domains"
echo ""
echo "2. Set up database user (manual SQL):"
echo "   mysql -u root -p < database/login_security.sql"
echo ""
echo "3. Configure HTTPS/SSL certificate:"
echo "   - Let's Encrypt: certbot certonly --apache -d registrar.bestlink.edu.ph"
echo "   - Or upload existing certificate"
echo ""
echo "4. Configure web server (Apache/Nginx):"
echo "   - Enable SSL/TLS"
echo "   - Set up HTTPS redirect"
echo "   - Configure security headers"
echo ""
echo "5. Configure firewall:"
echo "   sudo ufw allow 22/tcp"
echo "   sudo ufw allow 80/tcp"
echo "   sudo ufw allow 443/tcp"
echo "   sudo ufw enable"
echo ""
echo "6. Run pre-deployment tests:"
echo "   bash tests/security-tests.sh"
echo ""
echo "7. Test the application:"
echo "   - Navigate to https://registrar.bestlink.edu.ph"
echo "   - Test login flow"
echo "   - Verify OTP email delivery"
echo "   - Check security headers: curl -I https://registrar.bestlink.edu.ph/"
echo ""
echo -e "${YELLOW}For detailed instructions, see: DEPLOYMENT_GUIDE.md${NC}"
echo ""
