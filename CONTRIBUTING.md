# Contributing

print-quota is maintained by Gelsomino Lullo.

- Open an issue before a large change.
- Keep the application generic: organizations, offices, companies, public bodies, associations. Do not add wording tied to one sector.
- PHP changes must pass `php -l` on every edited file.
- The version shown in the footer is the newest file time of the sources (`AAMMDD.HHMM`, Europe/Rome). Do not hard-code it.
- Do not commit `data/*.sqlite`, logs, or printer passwords.
- Printer credentials stay on the Admin role. An Operator manages print users and quotas.
