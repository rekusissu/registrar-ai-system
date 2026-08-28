# Pre-Deployment Security Hardening Guide

## Overview
This guide walks through hardening the Registrar AI System before production deployment. Follow each section in order.

---

## 1. Environment Configuration

### Step 1.1: Create .env File
```bash
# Copy the example to create your production .env file
cp .env.example .env

# NEVER commit .env to git
# Ensure .env is in .gitignore
echo ".env" >> .gitignore
```

### Step 1.2: Generate Strong Secrets
```bash
# Generate JWT_SECRET (64-byte hex string)
openssl rand -hex 32

# Generate KIOSK_ACCESS_TOKEN (32-byte hex string)
openssl rand -hex 16

# Copy output values into .env
```

### Step 1.3: Edit .env with Production Values
```bash
nano .env
```

**Critical values to set:**

```env
# Application
APP_ENV=production
USE_HTTPS=true

# Database (use strong password)
DB_HOST=your-db-host.com
DB_NAME=registrar_ai
DB_USER=registrar_admin
DB_PASSWORD=SuperStrongPassword123!SecureDBPass456

# Security
JWT_SECRET=<paste-output-from-openssl-rand-hex-32>
KIOSK_ACCESS_TOKEN=<paste-output-from-openssl-rand-hex-16>

# OTP
OTP_SHOW_ONSCREEN=false

# AI Gateway (if using local 9Router)
NINEROUTER_URL=http://localhost:20128
AI_API_KEY=<your-ai-gateway-key-if-required>

# CORS
CORS_ALLOWED_ORIGINS=https://registrar.bestlink.edu.ph,https://kiosk.bestlink.edu.ph
```

---

## 2. Database Hardening

### Step 2.1: Verify Database Credentials
```sql
-- Connect to MySQL with admin account
mysql -u root -p

-- Create application user (if not exists)
CREATE USER 'registrar_admin'@'localhost' IDENTIFIED BY 'SuperStrongPassword123!SecureDBPass456';

-- Grant only necessary permissions
GRANT SELECT, INSERT, UPDATE, DELETE ON registrar_ai.* TO 'registrar_admin'@'localhost';

-- Revoke all grants and re-grant selectively
REVOKE ALL PRIVILEGES ON *.* FROM 'registrar_admin'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON registrar_ai.* TO 'registrar_admin'@'localhost';

FLUSH PRIVILEGES;

-- Verify permissions
SHOW GRANTS FOR 'registrar_admin'@'localhost';
```

### Step 2.2: Run Security Migrations
```bash
# Apply login throttling table (if not already present)
mysql -u registrar_admin -p registrar_ai < database/login_security.sql

# Apply security upgrades
mysql -u registrar_admin -p registrar_ai < database/security_upgrade.sql
```

### Step 2.3: Remove Default Users (if any)
```sql
-- Check for default/test accounts
SELECT id, username, email, role, is_active FROM users;

-- Disable or remove test accounts
DELETE FROM users WHERE username IN ('test', 'admin', 'demo') AND role != 'admin';
-- Or disable inactive accounts:
UPDATE users SET is_active = 0 WHERE username IN ('testuser') AND role != 'admin';
```

---

## 3. Application Configuration

### Step 3.1: Verify File Permissions
```bash
# Ensure uploads directory is writable by web server but not world-readable
mkdir -p uploads/student_files
mkdir -p logs
chmod 750 uploads
chmod 750 uploads/student_files
chmod 750 logs

# Ensure sensitive files are not readable by web server
chmod 600 .env
chmod 600 shared/config.php
```

### Step 3.2: Create Logs Directory
```bash
mkdir -p logs
chmod 750 logs
touch logs/php_errors.log
chmod 640 logs/php_errors.log

# Set log rotation (cron job to compress old logs)
cat > /etc/logrotate.d/registrar-ai << EOF
/path/to/registrar-ai-system/logs/*.log {
    daily
    rotate 30
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
}
EOF
```

### Step 3.3: Disable Debug Mode
Verify in `shared/config.php`:
```php
// Should show (after our update):
define('APP_ENV', getenv('APP_ENV') ?: 'development');

// With APP_ENV=production in .env, errors will NOT display on screen
// They will be logged to logs/php_errors.log instead
```

---

## 4. HTTPS/SSL Configuration

### Step 4.1: Obtain SSL Certificate
**Option A: Let's Encrypt (Recommended)**
```bash
# Install Certbot
sudo apt-get install certbot python3-certbot-apache

# Generate certificate
sudo certbot certonly --apache -d registrar.bestlink.edu.ph

# Certificate files:
# - /etc/letsencrypt/live/registrar.bestlink.edu.ph/fullchain.pem
# - /etc/letsencrypt/live/registrar.bestlink.edu.ph/privkey.pem
```

**Option B: Self-signed (Testing Only)**
```bash
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout /etc/ssl/private/registrar-ai.key \
  -out /etc/ssl/certs/registrar-ai.crt
```

### Step 4.2: Configure Apache/Nginx
**Apache:**
```apache
<VirtualHost *:443>
    ServerName registrar.bestlink.edu.ph
    DocumentRoot /var/www/registrar-ai-system

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/registrar.bestlink.edu.ph/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/registrar.bestlink.edu.ph/privkey.pem

    # Security headers
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
    Header always set X-Frame-Options "DENY"
    Header always set X-Content-Type-Options "nosniff"

    # Disable TLS 1.0 and 1.1
    SSLProtocol -all +TLSv1.2 +TLSv1.3
</VirtualHost>

# Redirect HTTP to HTTPS
<VirtualHost *:80>
    ServerName registrar.bestlink.edu.ph
    Redirect permanent / https://registrar.bestlink.edu.ph/
</VirtualHost>
```

---

## 5. Session & Cookie Security

### Step 5.1: Verify Cookie Settings
The application automatically sets:
- `HttpOnly=1` (prevents JavaScript access)
- `SameSite=Strict` (prevents CSRF)
- `Secure` (HTTPS only in production)

Verify in `shared/security_headers.php` (already configured).

### Step 5.2: Configure Session Timeout
In `.env`:
```env
# 20 minutes (1200 seconds) - adjust as needed
SESSION_IDLE_TIMEOUT=1200
```

---

## 6. Firewall Configuration

### Step 6.1: OS Firewall (iptables/ufw)
```bash
# Allow only HTTP/HTTPS
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp    # SSH (if remote access needed)
sudo ufw allow 80/tcp    # HTTP (redirect to HTTPS)
sudo ufw allow 443/tcp   # HTTPS
sudo ufw allow 3306/tcp  # MySQL (if remote DB)
sudo ufw enable
```

### Step 6.2: WAF (Web Application Firewall)
**Option A: ModSecurity (Apache module)**
```bash
sudo apt-get install libapache2-mod-security2

# Enable OWASP CRS (Core Rule Set)
sudo a2enmod security2
sudo systemctl restart apache2
```

**Option B: Cloudflare/AWS WAF**
- Route traffic through Cloudflare for DDoS protection
- Enable WAF rules in Cloudflare dashboard

### Step 6.3: Rate Limiting
The system includes built-in rate limiting:
- Login throttling: 5 failures / 15 minutes per IP+email
- OTP throttle: 3 attempts / 5 minutes per user

For additional protection, add nginx rate limiting:
```nginx
limit_req_zone $binary_remote_addr zone=api:10m rate=10r/s;

location /api/ {
    limit_req zone=api burst=20 nodelay;
}
```

---

## 7. Monitoring & Logging

### Step 7.1: Enable Application Logging
```bash
# Logs are written to logs/php_errors.log (configured in shared/config.php)
tail -f logs/php_errors.log

# Monitor for errors
grep "ERROR\|CRITICAL" logs/php_errors.log
```

### Step 7.2: Set Up Audit Log Rotation
```bash
# Audit logs in database: audit_logs table
# Query to find suspicious activity:

SELECT user_id, action, ip_address, created_at, old_values 
FROM audit_logs 
WHERE action IN ('login_failed', 'password_reset', 'user_delete')
ORDER BY created_at DESC 
LIMIT 100;
```

### Step 7.3: Optional: Sentry Error Tracking
```env
# In .env
SENTRY_DSN=https://your-sentry-dsn@sentry.io/project-id
```

Then configure in `shared/config.php`:
```php
if (defined('SENTRY_DSN') && SENTRY_DSN !== '') {
    \Sentry\init(['dsn' => SENTRY_DSN]);
}
```

---

## 8. Pre-Deployment Testing

### Step 8.1: Security Headers Test
```bash
# Verify security headers are sent
curl -I https://registrar.bestlink.edu.ph/

# Should include:
# Strict-Transport-Security: max-age=31536000
# X-Frame-Options: DENY
# X-Content-Type-Options: nosniff
# Content-Security-Policy: ...
```

### Step 8.2: HTTPS/SSL Test
```bash
# Test SSL configuration
openssl s_client -connect registrar.bestlink.edu.ph:443

# Online test: https://www.ssllabs.com/ssltest/
```

### Step 8.3: OTP Email Delivery Test
```bash
# Test password reset flow to verify mail() or SMTP works
# Navigate to login → forgot password → enter test email
# Check email for OTP delivery
```

### Step 8.4: Database Connection Test
```bash
# Ensure DB credentials in .env work
mysql -h $DB_HOST -u $DB_USER -p $DB_PASSWORD -e "USE $DB_NAME; SELECT COUNT(*) FROM users;"
```

### Step 8.5: Load Testing
```bash
# Use Apache Bench or wrk to simulate load
ab -n 1000 -c 10 https://registrar.bestlink.edu.ph/dashboard.php

# Monitor for:
# - Response time < 1s
# - Error rate < 0.1%
# - Server resource usage (CPU, memory, disk)
```

---

## 9. Deployment Checklist

- [ ] `.env` file created with strong secrets
- [ ] `APP_ENV=production` in `.env`
- [ ] `OTP_SHOW_ONSCREEN=false` in `.env`
- [ ] Database user created with restricted permissions
- [ ] Security migrations applied (login_security.sql)
- [ ] SSL/TLS certificate installed
- [ ] HTTPS redirect configured
- [ ] Security headers verified
- [ ] Firewall configured (OS level + WAF if applicable)
- [ ] Log rotation configured
- [ ] File permissions set correctly
- [ ] All tests passing (security headers, HTTPS, OTP, DB)
- [ ] Monitoring/alerting configured
- [ ] Backup strategy in place
- [ ] Rollback plan documented
- [ ] Team trained on deployment process

---

## 10. Post-Deployment Verification

### Step 10.1: Verify Production Configuration
```bash
# SSH into production server
ssh user@registrar.bestlink.edu.ph

# Check .env is not world-readable
ls -la .env  # Should show: -rw------- (600)

# Check APP_ENV is production
grep APP_ENV .env

# Verify no errors on screen (errors logged instead)
curl https://registrar.bestlink.edu.ph/api/auth.php?action=session
# Should return JSON, NOT HTML error page
```

### Step 10.2: Test Login Flow
1. Navigate to https://registrar.bestlink.edu.ph/login.php
2. Enter credentials
3. Verify OTP email is received
4. Enter OTP code
5. Verify successful login and redirect to dashboard

### Step 10.3: Monitor for Errors
```bash
# Watch error logs in real-time
tail -f logs/php_errors.log

# Check for unexpected activity
tail -100 logs/php_errors.log | grep -i "error\|warning"
```

### Step 10.4: Test Backup & Recovery
```bash
# Backup database
mysqldump -u registrar_admin -p registrar_ai > backup_$(date +%Y%m%d).sql

# Test restore (to separate DB)
mysql -u registrar_admin -p test_db < backup_$(date +%Y%m%d).sql
```

---

## 11. Ongoing Security Maintenance

### Monthly Tasks
- [ ] Review audit_logs table for suspicious activity
- [ ] Rotate API keys / KIOSK_ACCESS_TOKEN if exposed
- [ ] Check for PHP security updates
- [ ] Review SSL certificate expiration (Let's Encrypt: auto-renew via certbot)

### Quarterly Tasks
- [ ] Penetration testing
- [ ] Security audit of new code
- [ ] Backup restoration test
- [ ] Disaster recovery drill

### Annually
- [ ] Full security assessment
- [ ] Compliance audit (FERPA, local data protection laws)
- [ ] Update security policies

---

## Support & Troubleshooting

### SSL Certificate Expiration
```bash
# Check expiration date
openssl x509 -enddate -noout -in /etc/letsencrypt/live/registrar.bestlink.edu.ph/fullchain.pem

# Auto-renew (cron job, runs daily)
0 0 * * * certbot renew --quiet && systemctl reload apache2
```

### OTP Not Sending
1. Check `logs/php_errors.log` for mail() errors
2. Verify SMTP configuration in `.env` (if configured)
3. Check email address in user profile is valid
4. In development, set `OTP_SHOW_ONSCREEN=true` to see OTP on screen

### Database Connection Issues
```bash
# Test connection from app server
mysql -h $DB_HOST -u registrar_admin -p -e "USE registrar_ai; SELECT 1;"

# Check firewall allows port 3306
telnet $DB_HOST 3306
```

---

**Last Updated:** 2026-08-28
**Version:** 1.0
**Status:** Production Ready (after completing all steps)
