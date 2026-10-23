# NEW-SRL-Wildix-calcolatore-CDS-bundle

Login come root

Creare cartella per script
```
mkdir /var/_scripts
cd /var/_scripts
```

copiare il file
```
wget -O cdr.php https://raw.githubusercontent.com/canvacanva/NEW-SRL-cdr/main/cdr.php
nano cdr.php
```

Modificare la apikey, user e pass del DB, eventuale valore di bundle attuali
```
$API_KEY = 'wsk-v1-INCOLLA_QUI';
$DB = ['host' => 'localhost', 'name' => 'cdr', 'user' => 'INCOLLA_UTENTE', 'pass' => 'INCOLLA_PASSWORD'];
$CURRENT_BUNDLES = 6;            // bundle attuali (WMS -> Call and chat history -> AI+Data Boost, "bundles")
```
