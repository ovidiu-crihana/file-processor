# Descrittore progetto

## Scopo

Automatizzare la **copia**, **rinomina**, **organizzazione** e **fusione** dei file TIFF (e creazione dei PDF) a partire da un **file CSV massivo**.
Il sistema è progettato per lavorare su **cartelle di rete SMB** (es. `\\192.168.1.50\Work`) ed è interamente **parametrizzabile via file `.env`**.

L’obiettivo è garantire:

* coerenza tra i nomi dei file e i dati nel CSV,
* struttura di archiviazione uniforme,
* processi riavviabili e tracciabili anche in caso di interruzione.

---

## INPUT

### CSV principale

**Percorso:**

```
C:\<path>\<locale>\file.csv
```

**Formato CSV (separatore `;`):**

```
ID;TIPO_PRATICA;TIPO_DOCUMENTO;PRAT_NUM;PROT_PRAT_NUMERO;PROT_PRAT_DATA;SIGLA_PRATICA;BATCH;STATO;IDDOCUMENTO;IDRECORD;IMMAGINE;DATA_ORA_ACQ
```

**Esempio:**

```
221092;PE;FRONTESPIZIO;11;11884;22042011;PERMESSO DI COSTRUIRE;BAT000586089_PERMESSI-A-COSTRUIRE_2011;CORRETTO;1;3;00000003.tif;20250825152251
```

---

### File sorgenti (TIFF)

**Percorso base (cartella di rete):**

```
SOURCE_BASE_PATH=\\192.168.1.50\Work
```

**Struttura tipica:**

```
\\192.168.1.50\Work\<anno>\<mese>\<giorno>\<batch>\<file>.tif
```

**Esempio reale:**

```
\\192.168.1.50\Work\2025\08\25\BAT000586089_PERMESSI-A-COSTRUIRE_2011\00000003.tif
```

---

## LOGICA DI ELABORAZIONE

### Filtraggio iniziale

Vengono considerati **solo i record** del CSV con:

```
STATO = CORRETTO
```

---

### Raggruppamento logico

Definito nel file `.env`:

```dotenv
CSV_GROUP_KEYS=BATCH,IDDOCUMENTO
```

Ogni coppia unica di `BATCH|IDDOCUMENTO` genera un **gruppo logico**.
I gruppi vengono elaborati uno alla volta in ordine di apparizione nel CSV.

---

### Percorso di destinazione

**Percorso base di output (cartella di rete):**

```
OUTPUT_BASE_PATH=\\192.168.1.50\Output
```

**Struttura generata:**

```
\\192.168.1.50\Output\<BATCH>\<IDDOCUMENTO>\
```

**Esempio reale:**

```
\\192.168.1.50\Output\BAT000586089_PERMESSI-A-COSTRUIRE_2011\1\
```

---

### Regola di rinomina dei file

Il nome finale dei file TIFF e PDF è costruito in base al pattern definito in `.env`:

```dotenv
OUTPUT_FILENAME_PATTERN={TIPO_PRATICA}_{PRAT_NUM}_{ANNO}_{TIPO_DOCUMENTO}_{IDRECORD}.tif
```

#### 🔍 Spiegazione dei segnaposto disponibili

| Segnaposto         | Fonte (colonna CSV)                                       | Descrizione                    | Esempio           |
| ------------------ | --------------------------------------------------------- | ------------------------------ | ----------------- |
| `{TIPO_PRATICA}`   | TIPO_PRATICA                                              | Tipo pratica                   | `PE`              |
| `{PRAT_NUM}`       | PRAT_NUM                                                  | Numero pratica                 | `11`              |
| `{ANNO}`           | PROT_PRAT_DATA (ultime 4 cifre)                           | Anno protocollo                | `22042011 → 2011` |
| `{TIPO_DOCUMENTO}` | TIPO_DOCUMENTO (eventualmente modificato dalle eccezioni) | Tipo documento o sigla pratica | `FRONTESPIZIO`    |
| `{IDRECORD}`       | IDRECORD                                                  | Numero progressivo             | `3`               |

#### Esempio concreto

**CSV:**

```
TIPO_PRATICA = PE
PRAT_NUM = 11
PROT_PRAT_DATA = 22042011
TIPO_DOCUMENTO = FRONTESPIZIO
IDRECORD = 3
```

**Risultato finale:**

```
PE_11_2011_FRONTESPIZIO_3.tif
```

Il file PDF generato avrà lo stesso nome ma estensione `.pdf`.

---

### Eccezioni speciali

#### 🔸 Eccezione 1 — “Tavole” (elaborati numerici)

Se `TIPO_DOCUMENTO` contiene numeri (es. `TAVOLA_1`, `ELABORATO2`, ecc.), il programma cerca i file anche nella sottocartella:

```
\\192.168.1.50\Work\<anno>\<mese>\<giorno>\<batch>\tavole\
```

Tutti i file trovati vengono copiati e rinominati secondo la stessa regola.

---

#### 🔸 Eccezione 2 — “Titolo autorizzativo”

Se `TIPO_DOCUMENTO` contiene la stringa “titolo autorizzativo” (case-insensitive):

* viene sostituito con il valore della colonna `SIGLA_PRATICA`.

**Esempio:**

```
TIPO_DOCUMENTO = Titolo Autorizzativo
SIGLA_PRATICA = PERMESSO DI COSTRUIRE
→ PE_11_2011_PERMESSO-DI-COSTRUIRE_3.tif
```

---

### Fusione TIFF multipagina e creazione PDF

* Tutti i file di un gruppo vengono ordinati per `IDRECORD` e fusi in un unico **TIFF multipagina**.
* Compressione usata: **Group4**.
* Anche se mancano alcuni file, viene creato comunque un TIFF con quelli trovati.
* Subito dopo viene generato un **PDF equivalente** con lo stesso nome.

**Esempio:**

```
\\192.168.1.50\Output\BAT000586089_PERMESSI-A-COSTRUIRE_2011\1\PE_11_2011_FRONTESPIZIO_3.tif
\\192.168.1.50\Output\BAT000586089_PERMESSI-A-COSTRUIRE_2011\1\PE_11_2011_FRONTESPIZIO_3.pdf
```

---

### Checkpoint e ripristino

Ad ogni gruppo completato, viene aggiornato un file di stato:

```
var\state\checkpoint.json
```

**Esempio:**

```json
{
  "processed": 1240,
  "total": 287499,
  "last_group": "BAT000586089_PERMESSI-A-COSTRUIRE_2011|1",
  "updated_at": "2025-10-19T19:17:02+02:00"
}
```

Se il processo viene interrotto, può essere ripreso con l’opzione `--resume`.

---

## OUTPUT

**Struttura finale:**

```
\\192.168.1.50\Output\<BATCH>\<IDDOCUMENTO>\
    PE_11_2011_FRONTESPIZIO_3.tif
    PE_11_2011_FRONTESPIZIO_3.pdf
```

---

## ⚙️ INSTALLAZIONE E SETUP (Windows)

### 1️⃣ PHP

Scaricare PHP 8.3 per Windows:
👉 [https://www.php.net/downloads.php](https://www.php.net/downloads.php?usage=web&os=windows&osvariant=windows-downloads&version=8.3)

Aggiungere la cartella di PHP al **PATH di sistema**.

---

### 2️⃣ ImageMagick (magick.exe)

Scaricare la versione portable:
👉 [https://imagemagick.org/archive/binaries/ImageMagick-7.1.2-7-portable-Q16-x64.7z](https://imagemagick.org/archive/binaries/ImageMagick-7.1.2-7-portable-Q16-x64.7z)

Estrarre in:

```
C:\Users\<USER>\tools\ImageMagick
```

Aggiungere la cartella al **PATH utente**:

```powershell
$dest = "C:\Users\<USER>\tools\ImageMagick"
$currentUserPath = [Environment]::GetEnvironmentVariable("Path","User")
$newUserPath = "$currentUserPath;$dest"
[Environment]::SetEnvironmentVariable("Path",$newUserPath,"User")
```

Verificare installazione:

```bash
magick -version
```

---

### Composer e Symfony

Installare Composer:
👉 [https://getcomposer.org/download/](https://getcomposer.org/download/)

Dentro la cartella del progetto:

```bash
composer install
composer dump-env dev
```

Verifica:

```bash
php bin/console
```

---

### Configurazione `.env`

I percorsi di rete vanno **scritti con doppi backslash (`\\`)**, esattamente come nei percorsi di Windows.
Esempio corretto:

```dotenv
SOURCE_BASE_PATH=\\192.168.1.50\Work
OUTPUT_BASE_PATH=\\192.168.1.50\Output
CSV_PATH=C:\Users\<USER>\ProcessFilesProject\host\original.csv
CHECKPOINT_FILE=var\state\checkpoint.json
LOG_FILE=var\log\process.log
CSV_GROUP_KEYS=BATCH,IDDOCUMENTO
EXCEPTION_TAVOLE_PATH=tavole
OUTPUT_FILENAME_PATTERN={TIPO_PRATICA}_{PRAT_NUM}_{ANNO}_{TIPO_DOCUMENTO}_{IDRECORD}.tif
```

> ⚠️ Non usare slash singoli (`\`) o quadrupli (`\\\\`).
> I doppi (`\\`) sono il formato corretto e pienamente compatibile con Windows SMB.

---

## COMANDI DISPONIBILI

| Comando                                 | Descrizione                                                    |
| --------------------------------------- | -------------------------------------------------------------- |
| `php bin/console app:check-environment` | Verifica l’ambiente, i percorsi e la presenza di `magick.exe`  |
| `php bin/console app:debug-merge`       | Mostra i gruppi logici (BATCH + IDDOCUMENTO) e i file previsti |
| `php bin/console app:process-files`     | Esegue l’elaborazione effettiva (TIFF → PDF)                   |

---

## Esempi pratici di esecuzione

### 🔹 Verifica ambiente

```bash
php bin/console app:check-environment
```

### 🔹 Anteprima gruppi (senza scrivere file)

```bash
php bin/console app:debug-merge --limit=10
```

### 🔹 Simulazione completa (dry-run)

```bash
php bin/console app:process-files --dry-run
```

### 🔹 Simulazione con limite

```bash
php bin/console app:process-files --dry-run --limit=1000
```

### 🔹 Ripresa dopo interruzione

```bash
php bin/console app:process-files --resume
```

### 🔹 Esecuzione reale (scrive su disco)

```bash
php bin/console app:process-files
```

### 🔹 Log dettagliato (debug verbose)

```bash
php bin/console app:process-files -vv
```
