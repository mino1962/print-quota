# Importazione ed esportazione CSV

Il file usa il separatore `;` e la codifica UTF-8 (con o senza BOM).

## Colonne

| Colonna | Obbligatoria | Significato |
|---|---|---|
| `cognome` | sì | Cognome |
| `nome` | sì | Nome |
| `email` | no | Email. Vuota in aggiornamento: il valore già presente resta |
| `codice` | no | Codice fotocopie. Vuoto su una riga nuova: viene generato con la lunghezza impostata |
| `gruppo` | no | Gruppo o ufficio di appartenenza |
| `telefono` | no | Telefono |
| `note` | no | Nota libera |
| `esterno_id` | no | Identificativo del gestionale di origine. Se presente, è la chiave di aggiornamento |
| `attivo` | no | `S` oppure `N`. Vuoto in aggiornamento: resta il valore già presente |

## Riconoscimento di una riga già presente

1. Se `esterno_id` è valorizzato, aggiorna la persona con lo stesso id esterno.
2. Altrimenti, se `codice` è valorizzato, aggiorna la persona con lo stesso codice.
3. Altrimenti inserisce una persona nuova.

Una cella vuota non cancella email, gruppo, telefono, note, id esterno o codice già salvati.

## Cosa non fa l'importazione

Non crea, non modifica e non cancella gli account sulla stampante. Dopo l'importazione usare le azioni Crea, Leggi, aumento quota o Elimina.

## File modello

Dalla pagina CSV scaricare «Scarica modello vuoto». La prima riga di dati è un esempio (`Rossi;Mario;…`) e va tolta prima dell'importazione.

L'esportazione usa le stesse colonne e lo stesso separatore.
