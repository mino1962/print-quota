# print-quota

Job accounting for Kyocera Command Center RX printers. One organization, print users, quotas, and a local operator login.

Author: **Gelsomino Lullo**. License: [MIT](LICENSE).

Italian guide: [README.it.md](README.it.md).

## What it does

- Stores print users (surname, given name, optional email, generated code, group, phone, notes, external id).
- Stores printers (name, location, IP, Command Center administrator login).
- Creates, reads, raises, and deletes Job Accounting accounts through the printer web interface.
- Runs printer actions in a queue so the browser can keep working. Leave the Queue page open while jobs run.
- Imports and exports print users as CSV. See [docs/csv-users.md](docs/csv-users.md).
- Two roles: **Admin** and **Operator**. Operators are not print users unless an Admin uses **Create print user**.

## Requirements

- PHP 8.1 or newer, with `pdo_sqlite`, `dom`, and `mbstring`.
- A printer that exposes Command Center RX Job Accounting. The client speaks that HTML interface only.
- No separate database server. The file `data/print-quota.sqlite` is created on first start.

## Install

1. Copy this folder to the web server.
2. Point the site (or a virtual host) at the folder. `index.php` is the only entry point.
3. Make `data/` writable by the web server.
4. Open the site. The first administrator is `admin` / `ChangeMe!`.
5. The first login asks for a new password (at least 8 characters).
6. Set the organization name, code length, and quota step under Settings.
7. Add printers, then print users, then use **Create** on each user.

The footer shows `vAAMMDD.HHMM · Gelsomino Lullo`. The version is the newest change time of the sources, in Europe/Rome.

## Roles

| Role | Print users and quotas | Printers and credentials | Operators and settings |
|---|---|---|---|
| Admin | yes | yes | yes |
| Operator | yes | list and names only | no |

## Quota

`quota_step` is the amount added by the increase button (default 1000). `quota_initial` is the ceiling used when copies are still unknown. `quota_max` is the upper bound. Code length is `code_length` (4–10).

## Security

- Printer passwords are stored in the local SQLite file. Protect `data/` and do not publish it.
- Change the default administrator password before anyone else can reach the site.
- This software talks to the printer as its administrator. Use it only on printers you administer.

## Limits

- Single organization.
- Command Center RX only. Other vendors are not supported.
- A later command-line client may be added. It is not part of this version.
