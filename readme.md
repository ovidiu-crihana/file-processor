
# Descrizione progetto

## Scopo

Automatizzare la copia, rinomina, organizzazione e fusione dei file TIFF (e creazione dei PDF) a partire da un file CSV massivo.  
Il sistema è progettato per lavorare su cartelle di rete SMB (es. `\\192.168.1.50\Work`) ed è interamente parametrizzabile via file `.env`.

L’obiettivo è garantire:

-   coerenza tra i nomi dei file e i dati nel CSV,

-   struttura di archiviazione uniforme,

-   processi riavviabili e tracciabili anche in caso di interruzione.


----------

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

----------

### File sorgenti (TIFF)

Percorso base (cartella di rete):

```
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

----------

## LOGICA DI ELABORAZIONE

### Filtraggio iniziale

Vengono considerati solo i record del CSV con:

```
STATO = CORRETTO

```

----------

### Raggruppamento logico

Definito nel file `.env`:

```dotenv
CSV_GROUP_KEYS=BATCH,IDDOCUMENTO

```

Ogni coppia unica di `BATCH|IDDOCUMENTO` genera un gruppo logico.  
I gruppi vengono elaborati in ordine di apparizione nel CSV.

----------


Perfetto — ecco la **sezione riformattata correttamente in Markdown**, con tabella leggibile, spazi coerenti e blocchi di codice puliti.  
Puoi sostituirla direttamente nel documento al posto dell’attuale versione:

----------

### Regola di rinomina dei file

Il nome finale dei file TIFF e PDF è costruito in base al pattern definito nel file `.env`:

```dotenv
OUTPUT_FILENAME_PATTERN={TIPO_PRATICA}_{PRAT_NUM}_{ANNO}_{TIPO_DOCUMENTO}_{IDRECORD}.tif

```

#### Spiegazione dei segnaposto disponibili

| Segnaposto         | Fonte (colonna CSV)                                         | Descrizione                    | Esempio           |
| ------------------ | ----------------------------------------------------------- | ------------------------------ | ----------------- |
| `{TIPO_PRATICA}`   | `TIPO_PRATICA`                                              | Tipo pratica                   | `PE`              |
| `{PRAT_NUM}`       | `PRAT_NUM`                                                  | Numero pratica                 | `11`              |
| `{ANNO}`           | `PROT_PRAT_DATA` (ultime 4 cifre)                           | Anno protocollo                | `22042011 → 2011` |
| `{TIPO_DOCUMENTO}` | `TIPO_DOCUMENTO` (eventualmente modificato dalle eccezioni) | Tipo documento o sigla pratica | `FRONTESPIZIO`    |
| `{IDRECORD}`       | `IDRECORD`                                                  | Numero progressivo             | `3`               |

#### Esempio concreto

**Dati CSV:**

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

Il file PDF generato avrà lo stesso nome, ma con estensione `.pdf`.

----------

### Eccezioni speciali

#### Eccezione 1 — “Tavole” (elaborati numerici)

Se `TIPO_DOCUMENTO` contiene numeri (es. `TAVOLA_1`, `ELABORATO2`, ecc.), il programma cerca i file anche nella sottocartella:

```
\\192.168.1.50\Work\<anno>\<mese>\<giorno>\<batch>\tavole\

```

Tutti i file trovati vengono copiati e rinominati secondo la stessa regola.

#### Eccezione 2 — “Titolo autorizzativo”

Se `TIPO_DOCUMENTO` contiene la stringa “titolo autorizzativo” (case-insensitive),  
viene sostituito con il valore della colonna `SIGLA_PRATICA`.

Esempio:

```
TIPO_DOCUMENTO = Titolo Autorizzativo
SIGLA_PRATICA = PERMESSO DI COSTRUIRE
→ PE_11_2011_PERMESSO-DI-COSTRUIRE_3.tif

```

----------

### Fusione TIFF multipagina e creazione PDF

-   Tutti i file di un gruppo vengono ordinati per `IDRECORD` e fusi in un unico TIFF multipagina.

-   Compressione usata: Group4.

-   Anche se mancano alcuni file, viene creato comunque un TIFF con quelli trovati.

-   Subito dopo viene generato un PDF equivalente con lo stesso nome.


Esempio:

```
\\192.168.1.50\Output\BAT000586089_PERMESSI-A-COSTRUIRE_2011\1\PE_11_2011_FRONTESPIZIO_3.tif
\\192.168.1.50\Output\BAT000586089_PERMESSI-A-COSTRUIRE_2011\1\PE_11_2011_FRONTESPIZIO_3.pdf

```

----------

### Checkpoint e ripristino

Ad ogni gruppo completato, viene aggiornato un file di stato:

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

Se il processo viene interrotto, può essere ripreso con l’opzione `--resume`.

----------

## OUTPUT

Struttura finale:

```
\\192.168.1.50\Output\<BATCH>\<IDDOCUMENTO>\
    PE_11_2011_FRONTESPIZIO_3.tif
    PE_11_2011_FRONTESPIZIO_3.pdf

```

----------

## INSTALLAZIONE E SETUP (Windows)

### 1. PHP

Scaricare PHP 8.3 per Windows:  
[https://www.php.net/downloads.php?usage=web&os=windows&osvariant=windows-downloads&version=8.3](https://www.php.net/downloads.php?usage=web&os=windows&osvariant=windows-downloads&version=8.3)

Aggiungere la cartella di PHP al PATH di sistema.

Per verificare la versione di PHP installata:

```bash
php -v

```

Per controllare se la build è Thread Safe (TS) o Non Thread Safe (NTS):

```bash
php -i | find "Thread"

```

Per verificare l’architettura (x64 o x86):

```bash
php -i | find "Architecture"

```

----------

### 2. Installazione estensione Imagick per PHP

1.  Scaricare l’estensione compatibile con la versione di PHP da:  
    [https://pecl.php.net/package/imagick](https://pecl.php.net/package/imagick)  
    oppure da [https://windows.php.net/downloads/pecl/releases/imagick/](https://windows.php.net/downloads/pecl/releases/imagick/)

2.  Scegliere la versione in base a:

    -   Versione PHP (es. 8.3.x)

    -   Architettura (x64)

    -   Thread Safety (TS/NTS)

    -   Estensione “vc15” o “vs16” in base al compilatore usato (vedi output `php -v`)

3.  Estrarre il file `php_imagick.dll` in:

    ```
    C:\php\ext\
    
    ```

    (o nella cartella `ext` corrispondente alla propria installazione PHP)

4.  Copiare anche i file DLL di supporto (`CORE_RL_*`, `IM_MOD_*`, ecc.) nella cartella principale di PHP:

    ```
    C:\php\
    
    ```

5.  Modificare il file `php.ini`:

    ```
    extension=imagick
    
    ```

6.  Verificare l’installazione:

    ```bash
    php -m | find "imagick"
    
    ```

    oppure

    ```bash
    php -r "print_r(new Imagick());"
    
    ```


----------

### 3. ImageMagick (magick.exe)

Scaricare la versione portable:  
[https://imagemagick.org/archive/binaries/ImageMagick-7.1.2-7-portable-Q16-x64.7z](https://imagemagick.org/archive/binaries/ImageMagick-7.1.2-7-portable-Q16-x64.7z)

Estrarre in:

```
C:\Users\<USER>\tools\ImageMagick

```

Aggiungere la cartella al PATH utente:

```powershell
$dest = "C:\Users\<USER>\tools\ImageMagick"
$currentUserPath = [Environment]::GetEnvironmentVariable("Path","User")
$newUserPath = "$currentUserPath;$dest"
[Environment]::SetEnvironmentVariable("Path",$newUserPath,"User")

```

Verificare:

```bash
magick -version

```

----------

### 4. Composer e Symfony

Installare Composer:  
[https://getcomposer.org/download/](https://getcomposer.org/download/)

Dentro la cartella del progetto:

```bash
composer install
composer dump-env dev

```

Verifica:

```bash
php bin/console

```

----------

### 5. Installazione Git

Scaricare e installare Git per Windows da:  
[https://git-scm.com/download/win](https://git-scm.com/download/win)

Durante l’installazione:

-   Selezionare “Use Git from the Windows Command Prompt”

-   Lasciare le impostazioni predefinite per le altre opzioni.


Verificare l’installazione:

```bash
git --version

```

----------

### 6. Clonazione del progetto

Aprire il terminale o PowerShell e posizionarsi nella cartella di lavoro:

```bash
cd C:\Users\<USER>\PhpstormProjects\

```

Clonare il repository:

```bash
git clone https://github.com/<ORGANIZATION>/<REPOSITORY>.git file-processor

```

Entrare nella cartella del progetto:

```bash
cd file-processor

```

Eseguire:

```bash
composer install
composer dump-env dev

```

----------

### 7. Configurazione `.env`

I percorsi di rete devono essere scritti con doppi backslash (`\\`), come nei percorsi Windows.  
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

Non usare slash singoli (`\`) o quadrupli (`\\\\`): i doppi (`\\`) sono il formato corretto.

----------

Perfetto — ecco la sezione **“COMANDI DISPONIBILI”** riscritta in formato **tabellare Markdown pulito**, coerente con lo stile del resto del documento:

---

## COMANDI DISPONIBILI

| Comando                                 | Descrizione                                                                             |
| --------------------------------------- | --------------------------------------------------------------------------------------- |
| `php bin/console app:check-environment` | Verifica l’ambiente, i percorsi configurati e la presenza di `magick.exe`.              |
| `php bin/console app:debug-merge`       | Mostra i gruppi logici (BATCH + IDDOCUMENTO) e i file previsti, senza modificare nulla. |
| `php bin/console app:process-files`     | Esegue l’elaborazione effettiva dei file (TIFF → PDF) secondo le regole definite.       |


----------

## ESECUZIONE

Verifica ambiente:

```bash
php bin/console app:check-environment

```

Anteprima gruppi:

```bash
php bin/console app:debug-merge --limit=10

```

Simulazione (dry-run):

```bash
php bin/console app:process-files --dry-run

```

Simulazione con limite:

```bash
php bin/console app:process-files --dry-run --limit=1000

```

Ripresa dopo interruzione:

```bash
php bin/console app:process-files --resume

```

Esecuzione reale:

```bash
php bin/console app:process-files

```

Log dettagliato:

```bash
php bin/console app:process-files -vv

```