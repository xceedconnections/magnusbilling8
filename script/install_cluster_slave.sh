#!/bin/bash
# Install this host as a MagnusBilling 8 Asterisk cluster slave (no local portal).
# Signaling customers still use the OpenSIPS proxy; this node processes calls via AGI
# against the central MariaDB on the master portal server.
#
# Usage: ./install_cluster_slave.sh [MASTER_IP]
# Or choose option 2 from install.sh

set -euo pipefail
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:${PATH}}"

if [[ ${EUID} -ne 0 ]]; then
  echo "Run as root."
  exit 1
fi

echo
echo "=================== CLUSTER SLAVE INSTALL ==================="
echo "This machine will run Asterisk + Magnus AGI and bill on the MASTER database."
echo

MASTER_IP="${1:-}"
if [[ -z "${MASTER_IP}" ]]; then
  read -r -p "Main MagnusBilling portal IP (master): " MASTER_IP
fi
if ! [[ "${MASTER_IP}" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Invalid MASTER IP."
  exit 1
fi

read -r -p "MariaDB user on master for this slave [mbillingSlave]: " DB_USER
DB_USER="${DB_USER:-mbillingSlave}"
read -r -s -p "MariaDB password created on master (option 3 / attach cluster): " DB_PASS
echo
if [[ -z "${DB_PASS}" ]]; then
  echo "Database password is required."
  exit 1
fi
read -r -p "MariaDB database name [mbilling]: " DB_NAME
DB_NAME="${DB_NAME:-mbilling}"
read -r -p "Local SIP port [5060]: " SIP_PORT
SIP_PORT="${SIP_PORT:-5060}"

PUBLIC_IP="$(curl -4 -fsS --max-time 5 ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')"
read -r -p "Public IP of THIS slave [${PUBLIC_IP}]: " THIS_PUBLIC
THIS_PUBLIC="${THIS_PUBLIC:-$PUBLIC_IP}"

echo
echo "Master=${MASTER_IP}  SlavePublic=${THIS_PUBLIC}  DB=${DB_USER}@${MASTER_IP}/${DB_NAME}"
read -r -p "Continue? [y/N]: " CONFIRM
[[ "${CONFIRM}" =~ ^[Yy]$ ]] || exit 1

. /etc/os-release
case "${ID:-}" in
  debian|ubuntu) ;;
  *) echo "Only Debian/Ubuntu are supported."; exit 1 ;;
esac

apt-get update --allow-releaseinfo-change
DEBIAN_FRONTEND=noninteractive apt-get install -y \
  curl wget ca-certificates sudo git apache2 php-cli php-mysql php-xml php-mbstring php-curl \
  mariadb-client asterisk 2>/dev/null || true

# Prefer Magnus Asterisk 20 build when packages are incomplete
if ! command -v asterisk >/dev/null 2>&1; then
  echo "Asterisk not found via apt. Run a full master install on a dedicated VM, or install Asterisk 20 first."
  echo "Continuing with AGI + MySQL remote config only if Asterisk is already present..."
fi

MB_GITHUB_ARCHIVE="https://github.com/xceedconnections/magnusbilling8/archive/refs/heads/source.tar.gz"
mkdir -p /var/www/html/mbilling
cd /var/www/html/mbilling
if [[ ! -f resources/asterisk/mbilling.php ]]; then
  if ! wget -O mbilling-source.tar.gz "${MB_GITHUB_ARCHIVE}"; then
    echo "Unable to download MagnusBilling from ${MB_GITHUB_ARCHIVE}"
    exit 1
  fi
  tar xzf mbilling-source.tar.gz --strip-components=1
  rm -f mbilling-source.tar.gz
fi

# Point AGI/runtime at central master DB
install -d -m 0755 /etc/asterisk
printf '[general]\ndbhost = %s\ndbname = %s\ndbuser = %s\ndbpass = %s\ndbport = 3306\n' \
  "${MASTER_IP}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}" > /etc/asterisk/res_config_mysql.conf
chmod 640 /etc/asterisk/res_config_mysql.conf

# Dialplan AGI entry (same as master)
cat > /etc/asterisk/extensions_magnus.conf <<'EOF'
[billing]
exten => _[*0-9].,1,AGI("/var/www/html/mbilling/resources/asterisk/mbilling.php")
 same => n,Hangup()

exten => _+X.,1,Goto(billing,${EXTEN:1},1)

exten => h,1,hangup()

[trunk_answer_handler]
exten => s,1,Set(MASTER_CHANNEL(TRUNKANSWERTIME)=${EPOCH})
 same => n,Return()
EOF

grep -q 'extensions_magnus.conf' /etc/asterisk/extensions.conf 2>/dev/null \
  || echo '#include extensions_magnus.conf' >> /etc/asterisk/extensions.conf

# Fail2ban lite on slave
DEBIAN_FRONTEND=noninteractive apt-get install -y fail2ban firewalld
systemctl enable --now fail2ban firewalld || true
firewall-cmd --permanent --add-port="${SIP_PORT}/udp" || true
firewall-cmd --permanent --add-port=10000-20000/udp || true
firewall-cmd --reload || true

# Test DB reachability
if ! mysql --protocol=tcp -h"${MASTER_IP}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -e "SELECT 1" >/dev/null 2>&1; then
  echo
  echo "ERROR: Cannot connect to master MariaDB ${DB_USER}@${MASTER_IP}/${DB_NAME}"
  echo "On the MASTER run install.sh option 3 (attach cluster server) first to create the DB user and open port 3306."
  exit 1
fi

echo
echo "=================== SLAVE READY ==================="
echo "Asterisk on this host bills against master ${MASTER_IP}."
echo "Public IP of this slave: ${THIS_PUBLIC}"
echo "SIP port: ${SIP_PORT}"
echo
echo "Next on MASTER portal (or install.sh option 3):"
echo "  Routes -> Servers -> New"
echo "  Type=Asterisk  Host=${THIS_PUBLIC}  Public IP=${THIS_PUBLIC}  SIP port=${SIP_PORT}  Weight=2  Status=Active"
echo
echo "Customers must point SIP to the OpenSIPS proxy, not to this slave IP."
echo "==================================================="
