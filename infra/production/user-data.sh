#!/bin/bash
# First boot of the API host (infra/production/server.tf): packages only. nginx's site, PHP-FPM's
# pool, and the queue worker's service come from the repository with each deploy (deploy/).
set -euxo pipefail
export DEBIAN_FRONTEND=noninteractive
# cloud-init runs this without HOME, and some installers refuse to run without it.
export HOME=/root

apt-get update
apt-get install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y nginx unzip git postgresql-client jq \
  php8.4-fpm php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-zip php8.4-intl php8.4-bcmath php8.4-gd

# No Composer: the deploy installs the PHP dependencies in CI and ships them in the release.
snap install aws-cli --classic

# Each deploy unpacks into releases/<sha> and switches the current link to it; storage/ and .env
# live in shared/ so they survive every deploy.
install -d -o www-data -g www-data /var/www/connected/releases /var/www/connected/shared/storage
