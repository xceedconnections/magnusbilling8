
.. _firewall-ip:

IP
--

| IP Address.




.. _firewall-action:

Action
------

| ``Temp ban`` — temporary Fail2ban ban (SSH, login, DDoS, SQL probe, Asterisk, etc.).
| ``Permanent ban`` — Fail2ban ``ip-blacklist`` jail (stays blocked until you Unban).
| ``IgnoreIP`` — never ban this IP (trusted).

| The Firewall list is refreshed from live Fail2ban jails every ~2 minutes
| (``cron.php failtwobanip``), including ``sshd``, ``asterisk-iptables``,
| ``mbilling_login``, ``mbilling_ddos``, ``mbilling_sql``, and blacklist.


.. _firewall-unban:

Unban
-----

| Select one or more rows and click **Unban**.
| The IP is removed from Fail2ban **immediately** on this server and disappears
| from the Firewall menu.
| It stays free until the **next** real attack matches a jail again
| (it is not re-banned automatically without a new attempt).


.. _firewall-description:

Description
-----------

| Jail / type comes from Fail2ban (for example ``sshd``, ``mbilling_sql``).
| Follow live bans with::
|
|     tail -f /var/log/fail2ban.log
|     fail2ban-client status



