# print-quota

Gestione delle quote di stampa per stampanti Kyocera Command Center RX. Una organizzazione, utenti di stampa, quote e accesso degli operatori.

Autore: **Gelsomino Lullo**. Licenza: [MIT](LICENSE).

English guide: [README.md](README.md).

## Cosa fa

- Conserva gli utenti di stampa (cognome, nome, email facoltativa, codice generato, gruppo, telefono, note, id esterno).
- Conserva le stampanti (nome, ubicazione, IP, utente amministratore del Command Center).
- Crea, legge, aumenta ed elimina gli account di Job Accounting attraverso l'interfaccia web della stampante.
- Mette le azioni in coda, così il browser resta utilizzabile. La pagina Coda va lasciata aperta finché le attività terminano.
- Importa ed esporta gli utenti di stampa in CSV. Vedi [docs/csv-utenti.md](docs/csv-utenti.md).
- Due ruoli: **Admin** e **Operatore**. Un operatore non è un utente di stampa finché un Admin non usa **Crea utenza di stampa**.

## Requisiti

- PHP 8.1 o successivo, con `pdo_sqlite`, `dom` e `mbstring`.
- Una stampante che espone il Job Accounting di Command Center RX. Il client parla solo con quell'interfaccia HTML.
- Nessun server database separato. Il file `data/print-quota.sqlite` nasce al primo avvio.

## Installazione

1. Copiare questa cartella sul server web.
2. Puntare il sito (o un virtual host) alla cartella. L'unico ingresso è `index.php`.
3. La cartella `data/` deve essere scrivibile dal server web.
4. Aprire il sito. Il primo amministratore è `admin` / `ChangeMe!`.
5. Al primo accesso viene chiesta una nuova password (almeno 8 caratteri).
6. In Impostazioni indicare il nome dell'organizzazione, la lunghezza del codice e il passo della quota.
7. Inserire le stampanti, poi gli utenti di stampa, poi usare **Crea** su ciascun utente.

Il piè di pagina mostra `vAAMMDD.HHMM · Gelsomino Lullo`. La versione è l'orario dell'ultima modifica dei sorgenti, fuso Europe/Rome.

## Ruoli

| Ruolo | Utenti di stampa e quote | Stampanti e credenziali | Operatori e impostazioni |
|---|---|---|---|
| Admin | sì | sì | sì |
| Operatore | sì | solo elenco e nomi | no |

## Quota

`quota_step` è la quantità del pulsante di aumento (predefinito 1000). `quota_initial` è il tetto usato quando le copie non sono ancora note. `quota_max` è il limite superiore. La lunghezza del codice è `code_length` (da 4 a 10).

## Sicurezza

- Le password delle stampanti stanno nel file SQLite locale. Proteggere `data/` e non pubblicarlo.
- Cambiare la password predefinita dell'amministratore prima che altri possano raggiungere il sito.
- Il software parla con la stampante come amministratore. Usarlo solo su stampanti di cui si ha la gestione.

## Limiti

- Una sola organizzazione.
- Solo Command Center RX. Altri produttori non sono gestiti.
- Un client da riga di comando potrà arrivare in seguito. Non fa parte di questa versione.
