# Shared rules for lottery.myappstore.org.in - included by both nginx server blocks.
# Install: sudo cp deploy/nginx/common.inc /etc/nginx/snippets/lottery.myappstore.org.in.inc

root /var/www/apps/lottery.myappstore.org.in/current;
index index.php;

charset utf-8;
server_tokens off;
client_max_body_size 10m;                      # ticket CSV/Excel uploads (app limit 5 MB)

access_log /var/log/nginx/lottery_access.log;
error_log  /var/log/nginx/lottery_error.log warn;

add_header X-Content-Type-Options nosniff always;
add_header X-Frame-Options SAMEORIGIN always;
add_header Referrer-Policy same-origin always;

# Let's Encrypt HTTP-01 challenge (certbot --webroot -w /var/www/letsencrypt)
location ^~ /.well-known/acme-challenge/ {
    root /var/www/letsencrypt;
    default_type text/plain;
    try_files $uri =404;
}

# ---- Private: never served (nginx ignores .htaccess, so these are REQUIRED) ----
location ~ ^/(config|includes|vendor|uploads|exports|tests|deploy|bin)(/|$) {
    return 404;
}
location ~ /\. {                                  # .env, .htaccess, .user.ini, .git, .github
    return 404;
}
location ~* \.(sql|md|lock|log|ini|sh|ya?ml|inc|bak|dist|example)$ {
    return 404;
}
location = /composer.json { return 404; }
location = /REVISION      { return 404; }

# ---- Application: real files only, no clean-URL rewrites ----
location / {
    try_files $uri $uri/ =404;
}

location ~ \.php$ {
    try_files $uri =404;                          # only existing .php files reach PHP-FPM
    fastcgi_split_path_info ^(.+\.php)(/.*)$;
    include fastcgi_params;
    # realpath_root resolves the `current` symlink, so each release switch is picked up atomically
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    fastcgi_param DOCUMENT_ROOT   $realpath_root;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_read_timeout 120s;                    # PDF generation
}

location ~* \.(css|js|woff2?|ttf|svg|png|jpe?g|gif|ico|webp)$ {
    try_files $uri =404;
    expires 7d;
    access_log off;
}
