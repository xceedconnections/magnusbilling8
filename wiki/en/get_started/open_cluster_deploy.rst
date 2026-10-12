Open cluster deploy (no C Application)
======================================

This guide builds a multi-Asterisk MagnusBilling 8 cluster using the **free**
OpenSIPS SIP Proxy already shipped in this repository. You do **not** need the
commercial ``app_mbilling`` C Application.

Architecture
------------

* **Master** — MagnusBilling 8 panel + MariaDB + Asterisk 20 (billing DB of record)
* **SIP Proxy** — OpenSIPS 3.6 (public customer SIP IP; weighted dispatcher)
* **Slave(s)** — Asterisk 20 running Magnus AGI against the **central** MariaDB

Customers always register/send INVITEs to the proxy public IP. Adding or removing
Asterisk slaves does not change the customer SIP address.

Prerequisites
-------------

* Debian 11/12/13 or Ubuntu LTS on each node (not Windows/XAMPP)
* Open ports: ``5060/udp`` (SIP), ``10000-20000/udp`` (RTP when media is not bypassed),
  ``3306/tcp`` between master and proxy (``proxy_magnus`` only), SSH
* At least 2 CPU / 4 GB RAM per Asterisk slave for moderate traffic

1. Install the master
--------------------

On a clean Debian/Ubuntu host as root::

   curl -O https://raw.githubusercontent.com/xceedconnections/magnusbilling8/source/script/install.sh
   bash install.sh

The installer asks for a mode:

1. **Fresh MagnusBilling 8** (portal + Asterisk + MariaDB) — choose this on the main server.
2. **Cluster slave** — choose on a second VM; enter the MAIN portal IP and DB password.
3. **Attach cluster server** — run again on the MAIN portal to register a slave IP,
   open MariaDB ``3306``, and print join credentials.

Open ``http://MASTER_IP``, sign in (default ``root`` / ``magnus``), change the
password, then note the MariaDB password in ``/root/passwordMysql.log``.

2. Install the OpenSIPS proxy
-----------------------------

On a second host::

   curl -O https://raw.githubusercontent.com/xceedconnections/magnusbilling8/source/script/installOpenSips-3.6.sh
   chmod +x installOpenSips-3.6.sh
   ./installOpenSips-3.6.sh PUBLIC_IP MASTER_IP [LOCALNET]

Optional STIR/SHAKEN at install time::

   sudo env \
     STIR_SHAKEN_CERT_PATH=/root/stir/certificate.pem \
     STIR_SHAKEN_KEY_PATH=/root/stir/private-key.pem \
     STIR_SHAKEN_X5U=https://certificates.example.com/stir/certificate.pem \
     STIR_SHAKEN_ATTESTATION=A \
     ./installOpenSips-3.6.sh PUBLIC_IP MASTER_IP

The installer prints ``proxy_magnus`` credentials. Add them in the panel under
**Routes → Servers**:

* Type = ``SipProxy``
* Host = proxy IP
* Username / Password / Port = values from the installer
* Status = Active

3. Add Asterisk slaves
----------------------

Recommended path with ``install.sh``::

   # On MAIN portal
   bash install.sh          # choose option 3 — enter new slave public IP
   # Save the printed DB password from /root/cluster-slave-IP.txt

   # On the NEW slave VM
   bash install.sh          # choose option 2 — enter MAIN IP + DB password

Or manually: install Asterisk + AGI on the slave, point
``/etc/asterisk/res_config_mysql.conf`` at the **master** MariaDB, then in
**Routes → Servers** add:

* Type = ``Asterisk``
* Host / Public IP / SIP port
* Weight > 0 (example: master weight ``1``, each slave weight ``2``)
* Status = Active

Optionally link slaves to a specific SipProxy using the related-server selector
on the SipProxy form.

Weight ``0`` removes a slave from the dispatcher pool without changing the
customer-facing proxy IP.

4. Sync proxy accounts
----------------------

On the master, ensure cron runs::

   php /var/www/html/mbilling/cron.php sipproxyaccounts

The proxy host also runs ``/root/sync_opensips_reload.sh`` every minute to reload
``address`` and ``dispatcher`` when the portal writes changes.

5. Point customers at the proxy
-------------------------------

Set the customer SIP domain / registrar to ``PUBLIC_PROXY_IP:5060``.

Assign optional per-SIP pinning and direct media in **Clients → SIP Users**:

* **Asterisk server** — pin that account to one slave (empty = weight balance)
* **Directmedia = yes** — for public-IP / IP-auth customers, OpenSIPS skips SDP
  rewrite so RTP stays on the customer/carrier media IPs (VOS-like bypass).
  Softphones behind NAT should keep Directmedia = no.

6. MariaDB scale (central billing stays fast)
---------------------------------------------

* Put the data directory on a large disk.
* Apply ``script/mariadb-cluster.cnf.example`` tuning (buffer pool, connections).
* Keep archive cron enabled (``CallArchive``). Optional: ``php cron.php cdrscale``.
* Optional partitioning SQL: ``script/cdr_partition.sql``.

7. STIR/SHAKEN from the panel
-----------------------------

After the proxy is registered, open **Settings → STIR/SHAKEN** to enable signing,
set attestation A/B/C, paste certificate/key, and set the HTTPS ``x5u`` URL.
Settings are written to the OpenSIPS ``stir_shaken_config`` table.

Verification
------------

* ``opensips-cli -x mi ds_list`` — slaves present with expected weights
* Place a call — CDR appears in the **same** portal with ``id_server`` set
* With Directmedia yes and public SDP — ``sngrep``/pcap shows media IPs of the
  endpoints, not the proxy/Asterisk public IP
