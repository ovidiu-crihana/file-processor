
# 📘 File Processor – Documentazione tecnica

## Descrizione progetto

### Scopo

Automatizzare la copia, rinomina, organizzazione e fusione dei file TIFF (e creazione dei PDF) a partire da un file CSV massivo.
Il sistema è progettato per lavorare su cartelle di rete SMB (es. `\\192.168.1.50\Work`) ed è interamente parametrizzabile via file `.env`.

L’obiettivo è garantire:

* Coerenza tra i nomi dei file e i dati nel CSV
* Struttura di archiviazione uniforme
* Processi riavviabili e tracciabili anche in caso di interruzione

---

## INPUT

### CSV principale

Percorso:

```
C:\<path>\<locale>\file.csv
```

Formato CSV (separatore `;`):

```
ID;TIPO_PRATICA;TIPO_DOCUMENTO;PRAT_NUM;PROT_PRAT_NUMERO;PROT_PRAT_DATA;SIGLA_PRATICA;BATCH;STATO;IDDOCUMENTO;IDRECORD;IMMAGINE;DATA_ORA_ACQ
```

Esempio:

```
221092;PE;FRONTESPIZIO;11;11884;22042011;PERMESSO DI COSTRUIRE;BAT000586089_PERMESSI-A-COSTRUIRE_2011;CORRETTO;1;3;00000003.tif;20250825152251
```

---

### File sorgenti (TIFF)

Percorso base (cartella di rete):

```dotenv
SOURCE_BASE_PATH=\\192.168.1.50\Work
```

Struttura tipica:

```
\\192.168.1.50\Work\<anno>\<mese>\<giorno>\<batch>\<file>.tif
```

Esempio reale:

```
\\192.168.1.50\Work\2025\08\25\BAT000586089_PERMESSI-A-COSTRUIRE_2011\00000003.tif
```

---

## LOGICA DI ELABORAZIONE

### Filtraggio iniziale

Vengono considerati solo i record del CSV con:

```dotenv
STATO = CORRETTO
```

---

### Raggruppamento logico

Definito nel file `.env`:

```dotenv
CSV_GROUP_KEYS=BATCH,IDDOCUMENTO
```

Ogni coppia unica di `BATCH|IDDOCUMENTO` genera un gruppo logico.
I gruppi vengono elaborati in ordine di apparizione nel CSV.

---

### Regola di rinomina dei file

Il nome finale dei file TIFF e PDF è costruito in base al pattern definito nel file `.env`:

```dotenv
OUTPUT_FILENAME_PATTERN={TIPO_PRATICA}_{PRAT_NUM}_{ANNO}_{TIPO_DOCUMENTO}_{IDRECORD}.tif
```

#### Segnaposto disponibili

| Segnaposto         | Fonte CSV        | Descrizione                      | Esempio           |
| ------------------ | ---------------- | -------------------------------- | ----------------- |
| `{TIPO_PRATICA}`   | `TIPO_PRATICA`   | Tipo pratica                     | `PE`              |
| `{PRAT_NUM}`       | `PRAT_NUM`       | Numero pratica                   | `11`              |
| `{ANNO}`           | `PROT_PRAT_DATA` | Ultime 4 cifre del protocollo    | `22042011 → 2011` |
| `{TIPO_DOCUMENTO}` | `TIPO_DOCUMENTO` | Tipo documento (o SIGLA_PRATICA) | `FRONTESPIZIO`    |
| `{IDRECORD}`       | `IDRECORD`       | Numero progressivo del record    | `3`               |

**Esempio:**

```
PE_11_2011_FRONTESPIZIO_3.tif
```

---

### Eccezioni speciali

#### Eccezione 1 — “Tavole” (elaborati numerici o grafici)

A partire dalla versione 2.0, la gestione delle *tavole* è completamente **parametrizzabile via `.env`**.
Non è più un controllo fisso sui numeri nel `TIPO_DOCUMENTO`, ma una regola dinamica configurabile.

##### Parametri principali

```dotenv
# Colonna CSV da analizzare (di solito TIPO_DOCUMENTO)
TAVOLE_TRIGGER_COLUMN=TIPO_DOCUMENTO

# Valore o pattern che attiva la ricerca (case-insensitive)
#   1️⃣ Parola singola → "TAVOLA"
#   2️⃣ Lista parole → "TAVOLA,PIANTA,CARTA"
#   3️⃣ Regex → "/TAVOLA[_\-\s]?\d{0,2}/i"
TAVOLE_TRIGGER_VALUE=TAVOLA

# Percorso base dove cercare le tavole
TAVOLE_PATH=tavole
```

##### Modalità di ricerca

| Tipo ricerca    | Sintassi esempio           | Descrizione                            | Esempi che attivano                  |
| --------------- | -------------------------- | -------------------------------------- | ------------------------------------ |
| Parola singola  | `TAVOLA`                   | Attiva se la colonna contiene “TAVOLA” | `TAVOLA_1`, `TavolaTecnica`          |
| Lista di parole | `TAVOLA,PIANTA,CARTA`      | Attiva se contiene una qualsiasi       | `PIANTA_GENERALE`, `CARTA_TECNICA`   |
| Pattern regex   | `/TAVOLA[_\-\s]?\d{0,2}/i` | Attiva se rispetta il pattern          | `TAVOLA_1`, `tavola 10`, `TAVOLA-02` |

##### Percorsi di ricerca supportati

| Tipo                    | Esempio `.env`                                                                | Risultato effettivo                               |
| ----------------------- | ----------------------------------------------------------------------------- | ------------------------------------------------- |
| Relativo al batch       | `TAVOLE_PATH=tavole`                                                          | `Work\<YYYY>\<MM>\<DD>\<BATCH>\tavole\<file>.tif` |
| Relativo alla root Work | `TAVOLE_PATH=..\Tavole`                                                       | `Work\Tavole\<BATCH>\<file>.tif`                  |
| Assoluto locale         | `TAVOLE_PATH=C:\Archivio\4Service\Applications\Vinci\Work\Tavole`             | Cerca direttamente lì                             |
| Percorso UNC            | `TAVOLE_PATH=\\10.10.90.241\Archivio\4Service\Applications\Vinci\Work\Tavole` | Usa cartella su server remoto                     |

##### Regola di comportamento

* La ricerca viene attivata **solo** se la condizione su `TAVOLE_TRIGGER_COLUMN` è vera.
* Le tavole trovate vengono **aggiunte**, non sostituite.
* Il pattern di rinomina rimane invariato (`OUTPUT_FILENAME_PATTERN`).
* I log indicano sempre quante tavole sono state trovate o se la cartella è assente.

---

#### Eccezione 2 — “Titolo autorizzativo”

Se `TIPO_DOCUMENTO` contiene la stringa `"titolo autorizzativo"` (case-insensitive),
viene sostituito con il valore della colonna `SIGLA_PRATICA`.

Esempio:

```
TIPO_DOCUMENTO = Titolo Autorizzativo
SIGLA_PRATICA = PERMESSO DI COSTRUIRE
→ PE_11_2011_PERMESSO-DI-COSTRUIRE_3.tif
```

---

### Fusione TIFF multipagina e creazione PDF

* Tutti i file di un gruppo vengono ordinati per `IDRECORD` e fusi in un unico TIFF multipagina.
* Compressione: `Group4` (default).
* Anche se mancano alcuni file, il TIFF viene comunque creato con quelli trovati.
* Subito dopo viene generato un PDF equivalente con lo stesso nome.

**Esempio:**

```
\\192.168.1.50\Output\<BATCH>\<IDDOCUMENTO>\PE_11_2011_FRONTESPIZIO_3.tif
\\192.168.1.50\Output\<BATCH>\<IDDOCUMENTO>\PE_11_2011_FRONTESPIZIO_3.pdf
```

---

### Checkpoint e ripristino

Ad ogni gruppo completato, viene aggiornato il file:

```
var\state\checkpoint.json
```

Esempio:

```json
{
  "processed": 1240,
  "total": 287499,
  "last_group": "BAT000586089_PERMESSI-A-COSTRUIRE_2011|1",
  "updated_at": "2025-10-19T19:17:02+02:00"
}
```

Il processo può essere ripreso con l’opzione `--resume`.

---

## OUTPUT

Struttura finale:

```
\\192.168.1.50\Output\<BATCH>\<IDDOCUMENTO>\
    PE_11_2011_FRONTESPIZIO_3.tif
    PE_11_2011_FRONTESPIZIO_3.pdf
```

---

## INSTALLAZIONE E SETUP (Windows)

### 1. PHP

Scaricare PHP 8.3 per Windows:
👉 [https://www.php.net/downloads.php](https://www.php.net/downloads.php)

Aggiungere la cartella di PHP al PATH e verificare:

```bash
php -v
```

---

### 2. Estensione Imagick per PHP

Scaricare da [https://windows.php.net/downloads/pecl/releases/imagick/](https://windows.php.net/downloads/pecl/releases/imagick/)

Estrarre in `C:\php\ext`, aggiungere in `php.ini`:

```ini
extension=imagick
```

Verifica:

```bash
php -m | find "imagick"
```

---

### 3. ImageMagick (magick.exe)

Scaricare la versione portable:
👉 [https://imagemagick.org/archive/binaries/](https://imagemagick.org/archive/binaries/)

Estrarre in:

```
C:\Users\<USER>\tools\ImageMagick
```

Aggiungere al PATH utente:

```powershell
$dest = "C:\Users\<USER>\tools\ImageMagick"
$currentUserPath = [Environment]::GetEnvironmentVariable("Path","User")
[Environment]::SetEnvironmentVariable("Path","$currentUserPath;$dest","User")
```

Verifica:

```bash
magick -version
```

---

### 4. Composer e Symfony

```bash
composer install
composer dump-env dev
php bin/console
```

---

### 5. Configurazione `.env`

#### Esempio reale:

```dotenv
SOURCE_BASE_PATH=\\192.168.1.50\Work
OUTPUT_BASE_PATH=\\192.168.1.50\Output
CSV_PATH=C:\Users\<USER>\SampleEnv\host\original.csv
CSV_GROUP_KEYS=BATCH,IDDOCUMENTO
OUTPUT_FILENAME_PATTERN={TIPO_PRATICA}_{PRAT_NUM}_{ANNO}_{TIPO_DOCUMENTO}_{GROUP}.tif
```

---

### 6. COMANDI DISPONIBILI

| Comando                                 | Descrizione                                                           |
| --------------------------------------- | --------------------------------------------------------------------- |
| `php bin/console app:check-environment` | Verifica l’ambiente e la presenza di `magick.exe`.                    |
| `php bin/console app:debug-merge`       | Mostra i gruppi logici e i file trovati (incluso conteggio tavole).   |
| `php bin/console app:process-files`     | Esegue l’elaborazione effettiva (copia, merge TIFF, generazione PDF). |

---

## ESECUZIONE

Verifica ambiente:

```bash
php bin/console app:check-environment
```

Anteprima gruppi (10):

```bash
php bin/console app:debug-merge --limit=10
```

Simulazione:

```bash
php bin/console app:process-files --dry-run
```

Ripresa dopo interruzione:

```bash
php bin/console app:process-files --resume
```

Esecuzione reale:

```bash
php bin/console app:process-files
```

---

## 📘 PARAMETRI `.env` PRINCIPALI

| Variabile                 | Descrizione                                   | Esempio                                                         |
| ------------------------- | --------------------------------------------- | --------------------------------------------------------------- |
| `CSV_PATH`                | Percorso completo del file CSV di input       | `C:\Users\<USER>\SampleEnv\host\original.csv`                   |
| `SOURCE_BASE_PATH`        | Cartella Work di origine                      | `\\10.10.90.241\Archivio\4Service\Applications\Vinci\Work`      |
| `OUTPUT_BASE_PATH`        | Cartella Output di destinazione               | `\\10.10.90.241\Archivio\4Service\Applications\Vinci\Output`    |
| `CSV_FILTER_COLUMN`       | Colonna di filtro                             | `STATO`                                                         |
| `CSV_FILTER_VALUE`        | Valore ammesso                                | `CORRETTO`                                                      |
| `CSV_GROUP_KEYS`          | Chiavi di raggruppamento                      | `BATCH,IDDOCUMENTO`                                             |
| `OUTPUT_FILENAME_PATTERN` | Pattern di rinomina                           | `{TIPO_PRATICA}_{PRAT_NUM}_{ANNO}_{TIPO_DOCUMENTO}_{GROUP}.tif` |
| `ENABLE_PDF_CONVERSION`   | Abilita la creazione PDF                      | `true`                                                          |
| `IMAGEMAGICK_PATH`        | Path completo a `magick.exe`                  | `C:\Users\<USER>\tools\ImageMagick\magick.exe`                  |
| `TAVOLE_TRIGGER_COLUMN`   | Colonna CSV che attiva la ricerca tavole      | `TIPO_DOCUMENTO`                                                |
| `TAVOLE_TRIGGER_VALUE`    | Parola, lista o regex per attivare la ricerca | `TAVOLA` / `TAVOLA,PIANTA` / `/TAVOLA[_\-\s]?\d{0,2}/i`         |
| `TAVOLE_PATH`             | Percorso di ricerca tavole                    | `tavole` / `..\Tavole` / `\\server\Work\Tavole`                 |

---

## 🧾 Log e checkpoint

* Log file: `var\logs\file_processor.log`
* Checkpoint: `var\state\checkpoint.json`
* Livello log configurabile via `LOG_LEVEL=info`

---
