# CSV import and export

Files use the `;` separator and UTF-8 (with or without a BOM).

## Columns

| Column | Required | Meaning |
|---|---|---|
| `cognome` | yes | Surname |
| `nome` | yes | Given name |
| `email` | no | Email. Empty on update: the stored value is kept |
| `codice` | no | Print code. Empty on a new row: generated with the configured length |
| `gruppo` | no | Group or office |
| `telefono` | no | Phone |
| `note` | no | Free note |
| `esterno_id` | no | Id from the source system. When set, it is the update key |
| `attivo` | no | `S` or `N`. Empty on update: the stored value is kept |

## Matching an existing row

1. If `esterno_id` is set, update the person with that external id.
2. Otherwise, if `codice` is set, update the person with that code.
3. Otherwise insert a new person.

An empty cell does not clear an email, group, phone, note, external id, or code already stored.

## What import does not do

It does not create, change, or delete accounts on the printer. After import, use Create, Read, the quota increase, or Delete.

## Template

From the CSV page, download the empty template. The first data row (`Rossi;Mario;…`) is an example and must be removed before import.

Export uses the same columns and the same separator.
