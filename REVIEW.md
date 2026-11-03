# Verifica iniziale — 3 novembre 2026

## Controlli eseguiti

- Integrità ZIP e lettura di tutti i 16 file: OK.
- Tutte le 11 immagini PNG decodificabili: OK.
- Versione header e Stable tag: 0.4.4.
- Quattro temi presenti e cartella stabile `ws40-weather-signature`.
- Le azioni amministrative esaminate verificano `manage_options` e nonce.
- Le richieste JSON e sfondi remoti usano `wp_safe_remote_get`, timeout e limiti di risposta.
- Le immagini remote sono limitate per tipo, dimensioni e peso.
- I sorgenti distribuiti e lo ZIP in `dist` sono identici all'allegato.

Revisione statica mirata: non equivale a un audit completo di sicurezza o a un collaudo WordPress. PHP/WordPress non sono disponibili nell'ambiente locale: sintassi e comportamento in esecuzione restano da verificare. Il workflow aggiunto eseguirà il lint su GitHub dopo il caricamento.

## Dettagli da correggere in una versione successiva

1. **Destinazione dei link.** `shortcode()`, `live_shortcode()` e i codici BBCode/HTML generati in `settings_page()` puntano a `home_url('/')`, anche quando il dominio visualizzato è differente. Il campo «Displayed domain name» non governa questi collegamenti.
2. **Ultimi dati validi dopo cambio sorgente.** `sanitize()` cancella CACHE e SCHEMA_CACHE, ma conserva LAST_GOOD. Se si cambia endpoint e quello nuovo non restituisce letture, il fallback può mostrare temporaneamente i dati della sorgente precedente, marcati come obsoleti.
3. **Attribuzione.** L'header riporta `John Martin`. Nessuna modifica automatica all'autore.

Questa importazione conserva la 0.4.4 originale come base di confronto.
