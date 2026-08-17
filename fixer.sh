cat > ~/fix_lightdeploy.sh << 'EOF'
#!/bin/bash

echo "========================================="
echo "  FIXING LIGHTDEPLOY PATH ISSUES"
echo "========================================="

# 1. Create symlinks
echo "Creating symlinks for Node.js and PM2..."
ln -sf /www/server/nodejs/v24.16.0/bin/node /usr/local/bin/node
ln -sf /www/server/nodejs/v24.16.0/bin/npm /usr/local/bin/npm
ln -sf /www/server/nodejs/v24.16.0/bin/pm2 /usr/local/bin/pm2
ln -sf /www/server/nodejs/v24.16.0/bin/npx /usr/local/bin/npx
ln -sf /www/server/nodejs/v24.16.0/bin/node /usr/bin/node
ln -sf /www/server/nodejs/v24.16.0/bin/npm /usr/bin/npm
ln -sf /www/server/nodejs/v24.16.0/bin/pm2 /usr/bin/pm2
ln -sf /www/server/nodejs/v24.16.0/bin/npx /usr/bin/npx

# 2. Add PATH to /etc/environment
echo "Updating /etc/environment..."
sed -i '/^PATH=/d' /etc/environment
echo 'PATH="/www/server/nodejs/v24.16.0/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/usr/games:/usr/local/games"' >> /etc/environment

# 3. Create path fix file for LIGHTDEPLOY
echo "Creating PATH fix for LIGHTDEPLOY..."
mkdir -p ~/scriptENG/LIGHTDEPLOY/config
cat > ~/scriptENG/LIGHTDEPLOY/config/path_fix.php << 'PHPEOF'
<?php
/**
 * PATH Fix for LIGHTDEPLOY
 * Ensures Node.js, npm, and PM2 are accessible
 */
// Set PATH for all executions
putenv('PATH=/www/server/nodejs/v24.16.0/bin:/usr/local/bin:/usr/bin:/bin');
putenv('HOME=/root');

// Also set environment variables
$_ENV['PATH'] = '/www/server/nodejs/v24.16.0/bin:/usr/local/bin:/usr/bin:/bin';
$_SERVER['PATH'] = '/www/server/nodejs/v24.16.0/bin:/usr/local/bin:/usr/bin:/bin';

// Ensure symlinks exist if not already
$symlinks = [
    '/usr/local/bin/node' => '/www/server/nodejs/v24.16.0/bin/node',
    '/usr/local/bin/npm' => '/www/server/nodejs/v24.16.0/bin/npm',
    '/usr/local/bin/pm2' => '/www/server/nodejs/v24.16.0/bin/pm2',
    '/usr/local/bin/npx' => '/www/server/nodejs/v24.16.0/bin/npx',
];

foreach ($symlinks as $link => $target) {
    if (!file_exists($link)) {
        @symlink($target, $link);
    }
}

// Log that PATH fix was applied
error_log("PATH fix applied: " . getenv('PATH'));
PHPEOF

# 4. Add path fix to bootstrap.php
echo "Adding PATH fix to bootstrap.php..."
if [ -f ~/scriptENG/LIGHTDEPLOY/app/bootstrap.php ]; then
    # Check if already included
    if ! grep -q "path_fix.php" ~/scriptENG/LIGHTDEPLOY/app/bootstrap.php; then
        echo "require_once __DIR__ . '/../config/path_fix.php';" >> ~/scriptENG/LIGHTDEPLOY/app/bootstrap.php
        echo "✓ Added to bootstrap.php"
    else
        echo "✓ Already in bootstrap.php"
    fi
else
    echo "⚠ bootstrap.php not found, creating..."
    echo '<?php' > ~/scriptENG/LIGHTDEPLOY/app/bootstrap.php
    echo "require_once __DIR__ . '/../config/path_fix.php';" >> ~/scriptENG/LIGHTDEPLOY/app/bootstrap.php
fi

# 5. Fix the DeploymentRunner.php directly
echo "Fixing DeploymentRunner.php..."
if [ -f ~/scriptENG/LIGHTDEPLOY/app/Deployment/DeploymentRunner.php ]; then
    # Backup original
    cp ~/scriptENG/LIGHTDEPLOY/app/Deployment/DeploymentRunner.php ~/scriptENG/LIGHTDEPLOY/app/Deployment/DeploymentRunner.php.bak
    
    # Add path fix at the start of the file
    sed -i '2i\    // Fix PATH for all executions\n    putenv("PATH=/www/server/nodejs/v24.16.0/bin:/usr/local/bin:/usr/bin:/bin");\n    putenv("HOME=/root");' ~/scriptENG/LIGHTDEPLOY/app/Deployment/DeploymentRunner.php
    
    echo "✓ Fixed DeploymentRunner.php"
else
    echo "⚠ DeploymentRunner.php not found"
fi

# 6. Fix the DeploymentService.php
echo "Fixing DeploymentService.php..."
if [ -f ~/scriptENG/LIGHTDEPLOY/app/Deployment/DeploymentService.php ]; then
    # Backup original
    cp ~/scriptENG/LIGHTDEPLOY/app/Deployment/DeploymentService.php ~/scriptENG/LIGHTDEPLOY/app/Deployment/DeploymentService.php.bak
    
    # Add path fix at the start of the file
    sed -i '2i\    // Fix PATH for all executions\n    putenv("PATH=/www/server/nodejs/v24.16.0/bin:/usr/local/bin:/usr/bin:/bin");\n    putenv("HOME=/root");' ~/scriptENG/LIGHTDEPLOY/app/Deployment/DeploymentService.php
    
    echo "✓ Fixed DeploymentService.php"
else
    echo "⚠ DeploymentService.php not found"
fi

# 7. Create a global fix for all API endpoints
echo "Creating API endpoint fix..."
mkdir -p ~/scriptENG/LIGHTDEPLOY/app/Api
cat > ~/scriptENG/LIGHTDEPLOY/app/Api/path_fix.inc.php << 'PHPEOF'
<?php
// This file ensures PATH is set for all API endpoints
putenv('PATH=/www/server/nodejs/v24.16.0/bin:/usr/local/bin:/usr/bin:/bin');
putenv('HOME=/root');
?>
PHPEOF

# 8. Add to all API endpoints
echo "Adding PATH fix to all API endpoints..."
for file in ~/scriptENG/LIGHTDEPLOY/app/Api/*.php; do
    if [ -f "$file" ] && ! grep -q "path_fix.inc" "$file"; then
        sed -i '2i\require_once __DIR__ . "/path_fix.inc.php";' "$file"
        echo "✓ Fixed: $(basename $file)"
    fi
done

# 9. Add to system environment
echo "Adding to /etc/profile..."
if ! grep -q "/www/server/nodejs/v24.16.0/bin" /etc/profile; then
    echo 'export PATH=/www/server/nodejs/v24.16.0/bin:$PATH' >> /etc/profile
fi

# 10. Add for the web user
echo "Setting up web user environment..."
mkdir -p /var/www
echo 'export PATH=/www/server/nodejs/v24.16.0/bin:$PATH' >> /var/www/.bashrc 2>/dev/null || true
echo 'export PATH=/www/server/nodejs/v24.16.0/bin:$PATH' >> /var/www/.profile 2>/dev/null || true

# 11. Create a wrapper script for PM2
echo "Creating PM2 wrapper script..."
cat > /usr/local/bin/pm2-wrapper << 'WRAPPER'
#!/bin/bash
export PATH=/www/server/nodejs/v24.16.0/bin:/usr/local/bin:/usr/bin:/bin
export HOME=/root
/www/server/nodejs/v24.16.0/bin/pm2 "$@"
WRAPPER
chmod +x /usr/local/bin/pm2-wrapper

# 12. Test everything
echo ""
echo "========================================="
echo "  TESTING THE FIX"
echo "========================================="

echo "Testing node:"
node --version 2>/dev/null || echo "⚠ node not found"

echo "Testing npm:"
npm --version 2>/dev/null || echo "⚠ npm not found"

echo "Testing pm2:"
pm2 --version 2>/dev/null || echo "⚠ pm2 not found"

echo "Testing pm2 list:"
pm2 list 2>&1 | head -5

echo "Testing as www user:"
sudo -u www bash -c "PATH=/www/server/nodejs/v24.16.0/bin:/usr/local/bin:/usr/bin:/bin pm2 list 2>&1" | head -3

echo ""
echo "========================================="
echo "  FIX COMPLETE!"
echo "========================================="
echo ""
echo "Next steps:"
echo "1. Restart LIGHTDEPLOY: pm2 restart lightdeploy"
echo "2. Check logs: pm2 logs lightdeploy"
echo "3. Test your deployment"
echo ""
echo "Backup files created:"
echo "  - ~/scriptENG/LIGHTDEPLOY/app/Deployment/DeploymentRunner.php.bak"
echo "  - ~/scriptENG/LIGHTDEPLOY/app/Deployment/DeploymentService.php.bak"
EOF

chmod +x ~/fix_lightdeploy.sh
bash ~/fix_lightdeploy.sh