# Revisione legale — ipotesi da validare

Destinatario: il legale che valida il plugin «ErreD EU Order Withdrawal for WooCommerce» (funzione di
recesso online, art. 54-bis Codice del Consumo). Versione del plugin: **0.9.0**.

Il plugin non decide se un recesso è valido: offre il canale online e calcola dei termini che il
venditore usa per decidere. Alcune scelte di progettazione, però, presuppongono un'interpretazione
della norma. Il codice le segna con `TODO(legal)`; questo documento le elenca tutte, una per voce,
con una domanda chiusa. Per ciascuna basta rispondere **confermo** oppure indicare la regola corretta.

Le voci 1 e 2 sono nuove nella 0.9.0; le altre esistevano già e non erano mai state validate.

---

## 1. Da quando decorre il periodo, prodotto per prodotto

**Dove:** `src/Integration/WindowBasisResolver.php`, campo «Il periodo di recesso decorre da» su
prodotti e categorie.

**Cosa fa oggi.** Ogni prodotto è classificato dal venditore in una di due categorie:

| Scelta | Decorrenza applicata | Pensata per |
|---|---|---|
| Consegna — beni | data in cui l'ordine è segnato «Completato» | beni materiali |
| Acquisto — servizi e contenuti digitali | data di creazione dell'ordine | servizi; contenuti digitali non su supporto materiale |

Un ordine misto (es. una maglietta e un'iscrizione a una scuola calcio) ha un termine per ciascun
articolo. Il prodotto senza scelta eredita dalla categoria e poi dall'impostazione del negozio. Se due
categorie dello stesso prodotto sono in conflitto vince «consegna», perché non scade mai prima
dell'acquisto.

**Ipotesi.** Che l'art. 52, c. 2, Cod. Cons. si riduca, per ciò che un negozio online registra, a
questi due eventi:
- più beni consegnati separatamente → dalla consegna dell'**ultimo**: approssimato con il
  completamento dell'ordine, che il venditore segna quando ha spedito tutto;
- consegna periodica di beni (es. box in abbonamento) → dalla consegna del **primo**: approssimato
  con il completamento del primo ordine;
- contratto misto beni + servizi in **un unico prodotto** → regola dei beni (per la direttiva è un
  contratto di vendita): il venditore deve classificarlo come «Consegna».

**Domande.**
1. Le tre approssimazioni qui sopra sono accettabili?
2. Un'iscrizione stagionale a un'attività sportiva è un servizio con decorrenza dalla conclusione,
   oppure rientra nell'esclusione dell'art. 59, lett. n) (servizi per il tempo libero con data o
   periodo di esecuzione specifici)? Il plugin lascia la scelta al venditore in entrambi i casi.

**Se la risposta è diversa:** cambia la documentazione per il venditore; il motore ha già un termine
per riga e può accogliere una terza regola.

---

## 2. Le etichette «in tempo / in ritardo» nel pannello del venditore

**Dove:** `src/Rest/AdminWithdrawalsController.php`, dettaglio della richiesta.

**Cosa fa oggi.** Per ogni articolo ritirato confronta il momento della conferma (art. 54-bis: è il
momento della comunicazione) con il periodo dell'articolo e mostra una di queste etichette:

- «Inviata entro il periodo»;
- «Inviata dopo il periodo, entro i tuoi giorni aggiuntivi» (giorni di tolleranza scelti dal venditore);
- «Inviata dopo la fine del periodo»;
- «Inviata prima dell'inizio del periodo (prima della consegna)» — presentata come **non** tardiva;
- «Periodo non disponibile».

Sotto c'è la nota: «Calcolato dalle date dell'ordine e dalle impostazioni dei prodotti così come
sono ora… È un aiuto per la tua decisione, non fa parte della ricevuta».

**Ipotesi.**
- Il consumatore può esercitare il recesso anche prima della consegna dei beni, quindi «prima
  dell'inizio» non va presentato come irregolare.
- «Dopo la fine del periodo» non significa «da respingere»: se mancava l'informativa sul recesso il
  periodo si estende fino a dodici mesi (art. 53). Il plugin non conosce questo fatto e non lo
  calcola; l'etichetta resta un'indicazione.

**Domande.**
1. È corretto presentare il recesso prima della consegna come tempestivo?
2. La formulazione delle etichette e della nota è adeguata, o serve un richiamo esplicito all'art. 53
   accanto a «Inviata dopo la fine del periodo»?

---

## 3. Il consenso all'esecuzione anticipata dei servizi reso obbligatorio

**Dove:** `src/Frontend/CheckoutConsents.php`, `src/Support/Settings.php` (impostazione «Rendi
obbligatorio il consenso all'avvio del servizio», **disattivata** di default).

**Cosa fa oggi.** Al checkout può comparire la casella con cui il consumatore chiede che il servizio
inizi durante il periodo di recesso (art. 14, par. 4, lett. a), direttiva 2011/83/UE), così che in
caso di recesso il venditore possa trattenere un importo proporzionale. Di default è facoltativa. Il
venditore può renderla obbligatoria: è pensato per chi offre solo servizi che iniziano comunque entro
il periodo (una lezione dal vivo, una prenotazione per il giorno dopo).

**Ipotesi.** Che sia lecito subordinare l'ordine a quella richiesta quando il servizio, per sua
natura, non può essere eseguito dopo il periodo di recesso.

**Domanda.** È lecito? In caso contrario l'opzione va rimossa o limitata, e il plugin smette di
offrirla.

---

## 4. Mappatura delle eccezioni dell'art. 59 sulle classificazioni di prodotto

**Dove:** `src/Admin/WithdrawalStatusFields.php`, `src/Support/Settings.php`.

**Cosa fa oggi.** Ogni prodotto o categoria riceve uno «Stato di recesso»:

| Classificazione | Effetto |
|---|---|
| Standard | recesso applicabile |
| Contenuto digitale (art. 16(m) dir.) | escluso; consenso **obbligatorio** al checkout |
| Servizio iniziato prima della scadenza (art. 14(4)(a) dir.) | recesso applicabile; consenso facoltativo per il pagamento proporzionale |
| Alloggio, trasporto, noleggio auto, ristorazione o tempo libero con data (art. 16(l) dir.) | escluso, nessun consenso |
| Altra eccezione dell'art. 16 (deperibili, su misura, sigillati per igiene, supporti sigillati…) | escluso, nessun consenso |

**Domande.**
1. La corrispondenza tra classificazioni ed effetti è corretta rispetto all'art. 59 Cod. Cons.?
2. Per i contenuti digitali è sufficiente un'unica casella che riunisce richiesta di esecuzione
   immediata e presa d'atto della perdita del diritto? Testo predefinito: «Chiedo l'accesso immediato
   al contenuto digitale e prendo atto che perdo quindi il diritto di recesso». Il venditore può
   modificarlo.
3. Per gli esclusi «sigillati» il plugin esclude l'intero prodotto, senza sapere se il sigillo è stato
   rotto. È accettabile che la verifica resti al venditore, sulla singola richiesta?

---

## 5. Contenuto della ricevuta su supporto durevole

**Dove:** `templates/emails/withdrawal-acknowledgement.php` (email) e il PDF allegato.

**Cosa fa oggi.** Email più PDF con: contenuto della dichiarazione, articoli ritirati, data e ora
della trasmissione, riferimento all'ordine, parti, codice di verifica (SHA-256). La frase sui termini
cambia secondo gli articoli ritirati (novità 0.9.0):

- solo beni: «…il periodo di 14 giorni (per i beni, calcolati a partire dalla consegna; per i
  contenuti digitali, dall'inizio del download)…»;
- solo servizi o contenuti digitali: «…il periodo di 14 giorni, calcolati a partire dalla conclusione
  del contratto…»;
- misto: «…(per i beni, calcolati a partire dalla consegna; per i servizi e i contenuti digitali,
  dalla conclusione del contratto)…».

Segue: «Verifichiamo questi aspetti prima di confermare, quindi l'invio di una richiesta non ne
garantisce di per sé l'accettazione».

**Domande.**
1. Il contenuto soddisfa l'art. 54-bis (contenuto della richiesta, data e ora della trasmissione)?
2. La frase «per i contenuti digitali, dall'inizio del download», nella versione per soli beni, è
   corretta, o va allineata alla «conclusione del contratto» usata nelle altre due?
3. La frase finale sull'accettazione non rischia di suggerire che il recesso, se tempestivo, sia
   soggetto ad approvazione del venditore?

---

## 6. Modalità «rigida»

**Dove:** impostazione «Applicazione del termine» (default: **Indicativa**).

**Cosa fa oggi.** In modalità indicativa la funzione di recesso resta sempre disponibile e il termine
è solo mostrato. In modalità rigida gli articoli fuori termine non sono selezionabili e, se nessun
articolo è nel termine, la funzione non viene offerta.

**Ipotesi.** Che sia lecito nascondere la funzione oltre il termine, nonostante l'art. 54-bis la
voglia «disponibile per l'intero periodo»: oltre il periodo il diritto non esiste. Il rischio è la
data approssimata: per i beni la consegna è stimata dal completamento dell'ordine, e per l'art. 53
il periodo reale può essere più lungo.

**Domanda.** La modalità rigida va sconsigliata, limitata o tolta? Oggi è facoltativa e la schermata
delle impostazioni la sconsiglia.

---

## 7. Diciture fissate dalla legge

«recedere dal contratto qui» (pulsante) e «conferma recesso» (secondo passo) sono usate testualmente
nella traduzione italiana. Le altre lingue sono traduzioni libere della stessa funzione.

**Domanda.** Confermi che per i consumatori italiani conta la dicitura italiana, e che in un'altra
lingua del sito basta una formula «equivalente e inequivocabile»?

---

### Come restituire le risposte

Va bene una risposta per numero, anche brevissima («1.1 confermo; 1.2 rientra nella lett. n…»). Ogni
risposta diversa da «confermo» diventa una voce della ROADMAP e, dove serve, una correzione nella
release successiva. Le voci confermate tolgono il relativo `TODO(legal)` dal codice.
