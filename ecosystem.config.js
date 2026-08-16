module.exports = {
  apps: [
    {
      name: 'lightdeploy',
      script: 'php',
      args: '-S 0.0.0.0:8000 -t public public/router.php',
      cwd: __dirname,
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '150M',
      env: {
        NODE_ENV: 'production',
        PORT: 8000
      },
      error_file: './logs/application/pm2-error.log',
      out_file: './logs/application/pm2-out.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z'
    }
  ]
};
