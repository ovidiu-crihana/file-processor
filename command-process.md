# Guida comando `app:process-files`

Il comando `app:process-files` esegue l’elaborazione effettiva dei gruppi di immagini TIFF
in base ai dati del CSV sorgente, generando doppia uscita in **Output/TIFF** e **Output/PDF**.  
Gestisce checkpoint, resume, log dettagliati e filtri mirati per batch e tipo documento.

---

## Sintassi base

```bash
php bin/console app:process-files [OPZIONI]
````

---

## Opzioni disponibili

| Opzione                 | Descrizione                                                                         |
| ----------------------- | ----------------------------------------------------------------------------------- |
| `--dry-run`             | Simula l’esecuzione senza generare file reali (utile per test o debug).             |
| `--resume`              | Riprende dal **checkpoint CSV** precedente, saltando solo i gruppi con `STATUS=OK`. |
| `--limit=N`             | Elabora solo i primi N gruppi (per test o batch parziale).                          |
| `--batch=BATCH_ID`      | Filtra per un singolo `BATCH` (es. `BAT000586089_PERMESSI-A-COSTRUIRE...`).         |
| `--tipo=TIPO_DOCUMENTO` | Filtra ulteriormente per un singolo `TIPO_DOCUMENTO`.                               |
| `--yes` oppure `-y`     | Salta la conferma interattiva iniziale.                                             |

---

## Funzionalità principali

### Checkpoint

* Ogni gruppo elaborato viene registrato in `CHECKPOINT_FILE` (di default `var/logs/checkpoint.csv`).
* Struttura:

  ```
  BATCH,IDDOCUMENTO,TIPO_DOCUMENTO,FOLDER_NUM,STATUS,UPDATED_AT
  ```
* Gli stati possibili:

    * `OK` → gruppo completato correttamente
    * `OK_PARTIAL` → gruppo elaborato parzialmente (file mancanti)
    * `ERROR` → errore durante la fusione o file non validi

---

### Resume

Permette di riprendere un’elaborazione interrotta.

```bash
php bin/console app:process-files --resume -y
```

* Rilegge il checkpoint esistente.
* Salta **solo** i gruppi in stato `OK`.
* Rielabora invece `OK_PARTIAL` e `ERROR`.
* Puoi configurare gli stati da saltare via `.env`:

```dotenv
RESUME_SKIP_STATUSES=OK,OK_PARTIAL
```

> 🔍 In console viene mostrato chiaramente:
>
> * quanti gruppi saranno saltati
> * da quale gruppo riprende
> * per ogni gruppo, lo stato precedente trovato nel checkpoint

---

### Log in tempo reale

Ogni gruppo mostra un blocco leggibile tipo:

```
📁 [#002] PE_11889_2011_ELABORATO_GRAFICO
   ├─ Batch: BAT000586089_PERMESSI-A-COSTRUIRE_ANNULLATI_NON-DEFINITI_2011
   ├─ Tipo documento: ELABORATO_GRAFICO
   ├─ ID Documento: 2
   ├─ Eccezioni: Tavole=SI | Suffisso=NO
   ├─ File previsti: 37
   ├─ Stato: ▶️ Avvio elaborazione...
   └─ Risultato: ⚠️ PARZIALE (6/37 file, 31 mancanti, durata 8.12 s)
```

In fondo viene riportata la **memoria di picco** e il **tempo totale di esecuzione**.

---

### Esempi d’uso

#### 1️⃣ Eseguire test locale (simulazione)

```bash
php bin/console app:process-files --dry-run --limit=20 -y
```

#### 2️⃣ Riprendere un’elaborazione interrotta

```bash
php bin/console app:process-files --resume -y
```

#### 3️⃣ Processare un singolo batch

```bash
php bin/console app:process-files --batch=BAT000586089_PERMESSI-A-COSTRUIRE_ANNULLATI_NON-DEFINITI_2011 -y
```

#### 4️⃣ Processare solo un tipo documento specifico

```bash
php bin/console app:process-files --batch=BAT000586089_PERMESSI-A-COSTRUIRE_ANNULLATI_NON-DEFINITI_2011 --tipo=ISTANZA -y
```

---

## Output finale

Alla fine del processo verranno mostrati:

```
[OK] Completato. Memoria di picco: 9.86 MB
[OK] Completato in 34.17 sec.
```

Il checkpoint aggiornato sarà consultabile in:

```
var/logs/checkpoint.csv
```

---

## Suggerimenti operativi

| Caso                                   | Cosa fare                                                 |
| -------------------------------------- | --------------------------------------------------------- |
| Primo test su PC locale                | Usa `--dry-run` e `--limit=10`                            |
| Esecuzione completa in produzione      | Usa `--yes` per evitare conferme                          |
| Processo interrotto                    | Riprendi con `--resume`                                   |
| Necessario rielaborare gruppi parziali | Cancella o modifica la riga corrispondente nel checkpoint |
| Controllare avanzamento batch          | Monitora l’output in console o il file `checkpoint.csv`   |

---

> 💡 Consiglio: puoi combinare `--resume` e `--limit` per riprendere progressivamente batch molto grandi senza rifare tutto da capo.

