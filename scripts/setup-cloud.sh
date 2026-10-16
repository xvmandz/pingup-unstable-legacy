#!/bin/bash
# Rootless PHP runtime for the Debian 13 cloud workspace. Package signatures and
# hashes are verified by APT; no Composer/Node dependencies are needed.
set -euo pipefail
runtime=/workspace/php-runtime
mkdir -p "$runtime"/apt/lists/partial "$runtime"/apt/cache/archives/partial "$runtime"/bin "$runtime"/sessions
cat > "$runtime/apt/sources.list" <<'EOF'
deb [signed-by=/usr/share/keyrings/debian-archive-keyring.gpg] https://deb.debian.org/debian trixie main
deb [signed-by=/usr/share/keyrings/debian-archive-keyring.gpg] https://security.debian.org/debian-security trixie-security main
EOF
cat > "$runtime/apt.conf" <<'EOF'
Dir::Etc::parts "-";
Dir::Etc::main "-";
Dir::Etc::sourcelist "/workspace/php-runtime/apt/sources.list";
Dir::Etc::sourceparts "-";
Dir::State::lists "/workspace/php-runtime/apt/lists";
Dir::Cache "/workspace/php-runtime/apt/cache";
APT::Sandbox::User "agent";
EOF
export APT_CONFIG="$runtime/apt.conf"
/usr/bin/apt-get update
cd "$runtime/apt/cache/archives"
/usr/bin/apt-get download php8.4-cli php8.4-common php8.4-sqlite3 php8.4-mbstring libonig5 libargon2-1
for package in php8.4-{cli,common,sqlite3,mbstring}_*.deb libonig5_*.deb libargon2-1_*.deb; do
    dpkg-deb -x "$package" "$runtime/root"
done
cat > "$runtime/bin/php" <<'EOF'
#!/bin/sh
export LD_LIBRARY_PATH="/workspace/php-runtime/root/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
exec /workspace/php-runtime/root/usr/bin/php8.4 -n -d extension_dir=/workspace/php-runtime/root/usr/lib/php/20240924 -d extension=pdo -d extension=pdo_sqlite -d extension=mbstring -d session.save_path=/workspace/php-runtime/sessions "$@"
EOF
chmod +x "$runtime/bin/php"
"$runtime/bin/php" -r 'if (!extension_loaded("pdo_sqlite") || !extension_loaded("mbstring")) exit(1); echo "PHP and required extensions ready\n";'
