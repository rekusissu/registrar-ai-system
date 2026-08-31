#!/usr/bin/env bash
# =============================================================================
#  Registrar AI System — one-time VPS bootstrap
#  Installs Docker Engine + Compose v2 plugin + git on Ubuntu/Debian.
#
#  Run as your normal (sudo-capable) user:
#    bash server-setup.sh
#  Then log out and back in (to pick up the docker group), or use `sudo docker`.
# =============================================================================
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive

echo "[setup] Updating apt…"
sudo apt-get update
sudo apt-get install -y ca-certificates curl git

# Docker's official apt repo (works for ubuntu / debian).
echo "[setup] Adding Docker apt repository…"
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc 2>/dev/null \
  || sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

. /etc/os-release   # provides $ID and $VERSION_CODENAME
ARCH="$(dpkg --print-architecture)"
echo "deb [arch=${ARCH} signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${ID} ${VERSION_CODENAME} stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

echo "[setup] Installing Docker Engine + Compose…"
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Let your user talk to Docker without sudo.
echo "[setup] Adding current user to the docker group…"
sudo usermod -aG docker "${USER}"

echo
echo "✔ Docker installed:"
docker --version
docker compose version
echo
echo "RELOG, then run:  bash deploy/deploy.sh"