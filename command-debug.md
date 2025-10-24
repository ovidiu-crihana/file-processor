
# Guida all’utilizzo del comando `app:debug-merge`

Il comando `app:debug-merge` permette di **ispezionare**, **validare** e **contare** i gruppi che verrebbero creati durante l’elaborazione reale del CSV, **senza scrivere alcun file TIFF o PDF**.

È pensato per verificare la correttezza della logica di grouping, naming, gestione tavole e numerazione cartelle, prima di eseguire il comando di processo effettivo `app:process-files`.

---

## Opzioni disponibili

| Opzione | Default | Descrizione |
|----------|----------|-------------|
| `--limit=<n>` | `0` | Limita il numero di **gruppi mostrati**. (0 = nessun limite) |
| `--max-rows=<n>` | `0` | Legge solo le prime N righe del CSV (0 = tutte). |
| `--batch=<id>` | _vuoto_ | Filtra i record per uno specifico **batch**. |
| `--tipo=<tipo>` | _vuoto_ | Filtra per un **tipo documento** specifico. |
| `--show-files` | _false_ | Mostra l’elenco completo dei file sorgenti di ciascun gruppo. |
| `--summary-only` | _false_ | Mostra solo il riepilogo finale (nessun dettaglio dei gruppi). |
| `--verbose-scan` | _false_ | Abilita i log diagnostici interni (trigger tavole, prefissi VIN, righe ignorate). |

---

## Modalità d’uso principali

### 1️⃣ Anteprima minima (validazione struttura)
Mostra solo i primi gruppi per confermare la struttura e la nomenclatura.

```bash
php bin/console app:debug-merge --limit=5
````

**Quando usarla**

* Dopo aver impostato `.env` e verificato i percorsi.
* Per controllare nomi file, cartelle numerate e conteggio pagine.

---

### 2️⃣ Anteprima dettagliata con elenco file

Visualizza i primi N gruppi con la lista completa dei file sorgenti.

```bash
php bin/console app:debug-merge --limit=3 --show-files
```

**Quando usarla**

* Per verificare i percorsi effettivi (`Work\AAAA\MM\GG\Batch\File.tif`)
* Per cross-check tra CSV e filesystem.

⚠️ Evitare su CSV grandi: genera output molto esteso.

---

### 3️⃣ Analisi tavole e trigger VIN

Mostra le righe trigger e i prefissi associati (es. VIN00000911).

```bash
php bin/console app:debug-merge --limit=10 --verbose-scan
```

**Quando usarla**

* Per validare la logica di riconoscimento delle “Tavole Importate”.
* Per capire dove vengono impostati e resettati i prefissi VIN.

---

###  4️⃣ Analisi di un batch specifico

Filtra e mostra solo i gruppi appartenenti a un batch.

```bash
php bin/console app:debug-merge --batch=BAT000586089_PERMESSI-A-COSTRUIRE_ANNULLATI_NON-DEFINITI_2011 --limit=10
```

**Quando usarla**

* Per debug mirato di un solo batch.
* Ottima per test cliente o validazione parziale.

---

### 5️⃣ Analisi di un tipo documento

Filtra per un tipo documento specifico, ad esempio per verificare le “Tavole” o le “Planimetrie”.

```bash
php bin/console app:debug-merge --tipo=ELABORATO_GRAFICO --limit=5 --show-files
```

**Quando usarla**

* Per validare il comportamento delle planimetrie e dei path speciali (`Work\Tavole\Importate\*`).

---

### 6️⃣ Riepilogo statistico (modalità silenziosa)

Mostra solo le statistiche finali, senza dettagliare i gruppi.

```bash
php bin/console app:debug-merge --summary-only
```

**Quando usarla**

* Prima del lancio reale, per avere una visione globale del CSV.
* Esegue in pochi secondi anche su file con centinaia di migliaia di righe.

**Esempio output:**

```
Riepilogo CSV
----------------
 • Totale righe lette: 309684
 • Righe STATO=CORRETTO: 276123
 • Righe skippate (non corrette): 33561
 • Righe filtrate da opzioni: 0
 • Trigger tavole rilevati: 47
 • Cartelle numerate totali: 16432
 • Gruppi generati: 32491
```

---

### 7️⃣ Log completo su file

Esegue un’analisi globale silenziosa e scrive il risultato su un file log.

```bash
php bin/console app:debug-merge --summary-only > var/logs/debug_summary.log
```

**Quando usarla**

* In produzione o su CSV molto grandi.
* Permette di avere un report completo consultabile successivamente.

---

## Strategie operative consigliate

| Scopo                                | Comando consigliato                                                           |
| ------------------------------------ | ----------------------------------------------------------------------------- |
| Verifica preliminare (CSV completo)  | `php bin/console app:debug-merge --summary-only`                              |
| Analisi campione (primi 5 gruppi)    | `php bin/console app:debug-merge --limit=5`                                   |
| Validazione percorsi e file sorgenti | `php bin/console app:debug-merge --limit=3 --show-files`                      |
| Debug tavole e trigger VIN           | `php bin/console app:debug-merge --verbose-scan --limit=10`                   |
| Debug mirato su un batch             | `php bin/console app:debug-merge --batch=<id_batch> --limit=5`                |
| Report CSV completo su log file      | `php bin/console app:debug-merge --summary-only > var/logs/debug_summary.log` |

---

## ⚠️ Note operative

* `--limit` ha priorità su `--max-rows` (il loop si interrompe appena raggiunge il numero di gruppi).
* Non utilizzare `--show-files` in produzione: genera output troppo esteso.
* Tutti i contatori del riepilogo sono affidabili e confrontabili con l’elaborazione reale (`app:process-files`).
* I gruppi “⭐️ [TAVOLE]” indicano automaticamente la presenza di prefissi VIN e cartelle speciali in `Work\Tavole\Importate`.

---

## Workflow raccomandato (prima dell’elaborazione reale)

1️⃣ **Verifica globale:**

```bash
php bin/console app:debug-merge --summary-only
```

2️⃣ **Analisi campione di controllo:**

```bash
php bin/console app:debug-merge --limit=5 --show-files
```

3️⃣ **Se tutto è coerente:**

```bash
php bin/console app:process-files --confirm
```

---

> *Documento generato automaticamente — aggiornato alle regole operative del progetto File Processor (2025-10).*
> Per modifiche future, aggiornare il comando `app:debug-merge` e rieseguire l’esportazione della guida.


