#!/usr/bin/env bash
set -euo pipefail

# Minimal bootstrap script for Ubuntu 22.04 droplet
if [ "$EUID" -ne 0 ]; then
  echo "Run as root or with sudo"
  exit 1
fi

apt update && apt upgrade -y

# create deploy user
useradd -m -s /bin/bash deploy || true
usermod -aG sudo deploy

# install basic packages
apt install -y ca-certificates curl gnupg lsb-release unzip git

# install Docker
mkdir -p /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" \
  | tee /etc/apt/sources.list.d/docker.list > /dev/null
apt update
apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

# enable docker socket for deploy user
usermod -aG docker deploy

# setup firewall
apt install -y ufw
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

echo "Bootstrap complete. Add your SSH public key to /home/deploy/.ssh/authorized_keys and log in as 'deploy' to continue." 
