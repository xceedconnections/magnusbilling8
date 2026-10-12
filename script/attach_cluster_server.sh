#!/bin/bash
# Run on the MAIN MagnusBilling portal host to allow a new Asterisk slave to join.
# Creates MariaDB user, opens firewalld for MySQL, and inserts pkg_servers row.
#
# Usage: ./attach_cluster_server.sh
# Or choose option 3 from install.sh

set -euo pipefail
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:${PATH}}"

if [[ ${EUID} -ne 0 ]]; then
  echo "Run as root."
  exit 1
fi

if [[ ! -f /var/www/html/mbilling/index.php ]]; then
  echo "MagnusBilling portal not found. Install master first (install.sh option 1)."
  exit 1
fi

echo
echo "=================== ATTACH CLUSTER SERVER ==================="
echo "This registers a new Asterisk slave on the MAIN portal database."
echo

read -r -p "New cluster (slave) server public IP: " SLAVE_IP
if ! [[ "${SLAVE_IP}" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Invalid slave IP."
  exit 1
fi

read -r -p "Friendly name [Asterisk-${SLAVE_IP}]: " SLAVE_NAME
SLAVE_NAME="${SLAVE_NAME:-Asterisk-${SLAVE_IP}}"
read -r -p "SIP port on slave [5060]: " SIP_PORT
SIP_PORT="${SIP_PORT:-5060}"
read -r -p "Dispatcher weight [2]: " WEIGHT
WEIGHT="${WEIGHT:-2}"
read -r -p "MariaDB user for slave [mbillingSlave]: " DB_USER
DB_USER="${DB_USER:-mbillingSlave}"

DB_PASS="$(tr -dc A-Za-z0-9_ </dev/urandom | head -c 16)"
DB_CONFIG="/etc/asterisk/res_config_mysql.conf"
DB_NAME="mbilling"

if [[ -r "${DB_CONFIG}" ]]; then
  # shellcheck disable=SC1090
  eval "$(awk -F' *= *' '/^dbname|^dbuser|^dbpass|^dbhost/ {gsub(/ /,"",$1); print toupper($1)"=\""$2"\""}' "${DB_CONFIG}" 2>/dev/null || true)"
  DB_NAME="${DBNAME:-mbilling}"
fi

echo
echo "Will create MariaDB user ${DB_USER}@${SLAVE_IP} on database ${DB_NAME}"
echo "and insert Servers row Type=Asterisk Host=${SLAVE_IP} Weight=${WEIGHT}"
read -r -p "Continue? [y/N]: " CONFIRM
[[ "${CONFIRM}" =~ ^[Yy]$ ]] || exit 1

# Open MySQL to this slave
if command -v firewall-cmd >/dev/null 2>&1; then
  firewall-cmd --permanent --add-rich-rule="rule family=\"ipv4\" source address=\"${SLAVE_IP}/32\" port protocol=\"tcp\" port=\"3306\" accept" || true
  firewall-cmd --reload || true
fi

# Ensure MariaDB listens for remote slaves
MYSQL_CNF="/etc/mysql/mariadb.conf.d/60-magnusbilling.cnf"
if [[ -f "${MYSQL_CNF}" ]]; then
  if ! grep -q '^bind-address' "${MYSQL_CNF}"; then
    echo 'bind-address = 0.0.0.0' >> "${MYSQL_CNF}"
  else
    sed -i 's/^bind-address.*/bind-address = 0.0.0.0/' "${MYSQL_CNF}"
  fi
  systemctl restart mariadb || systemctl restart mysql || true
fi

mariadb <<SQL
CREATE USER IF NOT EXISTS '${DB_USER}'@'${SLAVE_IP}' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'${SLAVE_IP}' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${SLAVE_IP}';
FLUSH PRIVILEGES;
SQL

# Insert / update pkg_servers
EXISTING="$(mariadb -N -e "SELECT id FROM ${DB_NAME}.pkg_servers WHERE host='${SLAVE_IP}' OR public_ip='${SLAVE_IP}' LIMIT 1" 2>/dev/null || true)"
if [[ -n "${EXISTING}" ]]; then
  mariadb -e "UPDATE ${DB_NAME}.pkg_servers SET name='${SLAVE_NAME}', host='${SLAVE_IP}', public_ip='${SLAVE_IP}', sip_port='${SIP_PORT}', type='asterisk', weight=${WEIGHT}, status=1 WHERE id=${EXISTING}"
  echo "Updated existing Servers id=${EXISTING}"
else
  mariadb -e "INSERT INTO ${DB_NAME}.pkg_servers (name,host,public_ip,username,password,port,sip_port,type,weight,status,description)
    VALUES ('${SLAVE_NAME}','${SLAVE_IP}','${SLAVE_IP}','magnus','magnussolution','5038','${SIP_PORT}','asterisk',${WEIGHT},1,'cluster slave')"
  echo "Inserted new Servers row for ${SLAVE_IP}"
fi

# Save join credentials for the operator
JOIN_FILE="/root/cluster-slave-${SLAVE_IP}.txt"
cat > "${JOIN_FILE}" <<EOF
MagnusBilling cluster join credentials
=====================================
Slave IP:     ${SLAVE_IP}
Master IP:    $(hostname -I | awk '{print $1}')
DB host:      MASTER_PUBLIC_OR_PRIVATE_IP
DB name:      ${DB_NAME}
DB user:      ${DB_USER}
DB password:  ${DB_PASS}
SIP port:     ${SIP_PORT}
Weight:       ${WEIGHT}

On the SLAVE server run:
  bash /path/to/install.sh
  choose option 2
  enter this master IP and the DB password above
EOF
chmod 600 "${JOIN_FILE}"

echo
echo "=================== DONE ==================="
echo "Slave ${SLAVE_IP} is registered in Routes -> Servers."
echo "Credentials saved to ${JOIN_FILE}"
echo
echo "On the NEW server run install.sh option 2 and use:"
echo "  Master IP = (this portal IP)"
echo "  DB user   = ${DB_USER}"
echo "  DB pass   = ${DB_PASS}"
echo "============================================"
