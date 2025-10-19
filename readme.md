
## Regole di generazione e rinomina file
### Processo di esportazione da `\\server\Work` a `\\server\Output`

---

## Scopo del documento

Il presente documento descrive in modo chiaro e completo le **regole operative** che verranno applicate per:
- selezionare i file validi contenuti nella cartella `\\server\Work`;
- rinominarli secondo criteri univoci e leggibili;
- organizzarli in una nuova struttura di cartelle in `\\server\Output`.

L’obiettivo è garantire coerenza e tracciabilità dei documenti archiviati, mantenendo il legame con le informazioni del file CSV fornito.

---

## Descrizione generale del processo

Il processo elabora il file CSV ricevuto, legge le informazioni riga per riga e:
1. considera solo i file con **stato “CORRETTO”**;
2. costruisce il percorso di origine del file (`\\server\Work\...`);
3. genera automaticamente le **cartelle di destinazione** in `\\server\Output`;
4. rinomina i file secondo una regola precisa basata su più colonne del CSV;
5. gestisce due eccezioni speciali (“elaborati grafici” e “titoli autorizzativi”).

---

## Regole di dettaglio

### 1. Filtraggio iniziale
- Si processano **solo le righe** del CSV con `STATO = CORRETTO`.
- Tutte le altre righe vengono ignorate.

---

### 2. Percorso file d’origine

Il file sorgente si trova in:
```
\\server\Work\<anno>\<mese>\<giorno>\<batch>\<nome_file>.tif
```

dove:  
| Campo CSV | Significato | Esempio |
|------------|-------------|----------|
| `DATA_ORA_ACQ` | Data e ora in formato `YYYYMMDDHHMMSS` → usata per ricavare `<anno>\<mese>\<giorno>` | `20250825123000` → `2025\08\25` |
| `BATCH` | Nome cartella batch | `BAT000586089_PERMESSI-A-COSTRUIRE-BO` |
| `IMMAGINE` | Nome file TIFF | `00000006.tif` |

---

### 3. Creazione delle cartelle di output

Nel percorso di destinazione `\\server\Output` viene creata una **nuova cartella** ogni volta che:

- cambia il valore nella colonna `BATCH`;
- oppure, all’interno dello stesso batch, cambia il valore nella colonna `IDRECORD`.

**Struttura finale:**
```
\\server\Output\<BATCH>\<IDRECORD>\
```

---

### 4. Regola di rinomina dei file

Ogni file viene rinominato nel seguente formato:

```
TIPO_PRATICA + "_" + PRAT_NUM + "_" + ANNO_PROTOCOLLO + "_" + ELABORATO_GRAFICO + "_" + IDRECORD + ".tif"
```

| Campo CSV | Significato | Esempio |
|------------|-------------|----------|
| `TIPO_PRATICA` | Tipo pratica | `PE` |
| `PRAT_NUM` | Numero pratica | `11` |
| `PROT_PRAT_DATA` | Data protocollo → ultime 4 cifre = anno | `22042011` → `2011` |
| `TIPO_DOCUMENTO` | Tipo documento | `CARTELLA_SEP` |
| `IDRECORD` | Numero progressivo | `1` |

**Esempio nome file:**
```
PE_11_2011_CARTELLA_SEP_001.tif
```

---

### 5. Percorso di destinazione

Esempio di percorso finale in `Output`:

```
\\server\Output\<BATCH>\<IDRECORD>\PE_11_2011_CARTELLA_SEP_001.tif
```

---

## Eccezioni

### 🔸 1. Elaborato grafico (file con numeri nel nome)
Se la colonna `TIPO_DOCUMENTO` contiene **un numero** (es. `TAVOLA_1`, `ELABORATO2`, `TAVOLA_3`):
- viene cercato anche il file corrispondente nella cartella:
  ```
  \\server\Work\<anno>\<mese>\<giorno>\<batch>\tavole\
  ```
- tutti i file trovati vengono copiati e rinominati **con la stessa regola**.

---

### 🔸 2. Titolo autorizzativo
Se `TIPO_DOCUMENTO` contiene la stringa **“titolo autorizzativo”** (senza distinzione maiuscole/minuscole):
- al posto del `TIPO_DOCUMENTO` viene usato il valore della colonna `SIGLA_PRATICA`.

**Esempio:**
```
TIPO_DOCUMENTO = "Titolo Autorizzativo"
SIGLA_PRATICA = "PERMESSO DI COSTRUIRE"

→ Nome finale: PE_11_2011_PERMESSO-DI-COSTRUIRE_001.tif
```

---

## Esempi pratici di risultato

| Dati CSV principali | Percorso di destinazione |
|----------------------|---------------------------|
| `TIPO_PRATICA = PE`<br>`PRAT_NUM = 11`<br>`PROT_PRAT_DATA = 22042011`<br>`TIPO_DOCUMENTO = CARTELLA_SEP`<br>`IDRECORD = 1`<br>`BATCH = BAT000586089_PERMESSI-A-COSTRUIRE-BO` | `\\server\Output\BAT000586089_PERMESSI-A-COSTRUIRE-BO\1\PE_11_2011_CARTELLA_SEP_001.tif` |
| `TIPO_DOCUMENTO = Titolo Autorizzativo`<br>`SIGLA_PRATICA = PERMESSO DI COSTRUIRE` | `\\server\Output\<BATCH>\<IDRECORD>\PE_11_2011_PERMESSO-DI-COSTRUIRE_001.tif` |
| `TIPO_DOCUMENTO = TAVOLA_2` | Copia anche da `\tavole\` → `\\server\Output\<BATCH>\<IDRECORD>\PE_11_2011_TAVOLA_2_002.tif` |

---

## Conclusioni

Il processo di esportazione:
- garantisce che vengano presi **solo i file validi (STATO = CORRETTO)**;
- mantiene una **struttura di cartelle coerente** con le informazioni di origine;
- produce nomi file **uniformi, leggibili e univoci**;
- gestisce correttamente i casi speciali previsti (“tavole” e “titolo autorizzativo”).

## Diagramma logico del processo
![enter image description here](https://i.ibb.co/Tx2qnLX2/tbd-diagramma.png)
