#!/bin/sh
# Sets up a RageKit checkout on Linux: the CLI's Composer dependencies, then the pinned toolchain
# (TypePHP + Slint, shared per user; see docs/toolchain.md), then a check of everything. Needs
# PHP 8.4+, Composer, git, tar and a C++ compiler (Debian/Ubuntu: apt install php8.4-cli composer
# git build-essential).
set -e
cd "$(dirname "$0")"
command -v php >/dev/null || { echo "setup: PHP 8.4+ is needed (php not on PATH)"; exit 1; }
command -v composer >/dev/null || { echo "setup: Composer is needed (https://getcomposer.org)"; exit 1; }
composer install --no-interaction
php bin/ragekit setup
php bin/ragekit doctor
