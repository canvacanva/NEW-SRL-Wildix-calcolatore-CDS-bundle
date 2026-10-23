# NEW-SRL-Wildix-calcolatore-CDS-bundle

Login come root

Creare cartella per script
```
mkdir /var/_scripts
cd /var/_scripts
```

copiare il file
```
wget -O cds_bundle.php https://raw.githubusercontent.com/canvacanva/NEW-SRL-Wildix-calcolatore-CDS-bundle/main/cds_bundle.php
nano cds_bundle.php
```

Modificare la apikey, user e pass del DB, eventuale valore di bundle attuali
```
$API_KEY = 'wsk-v1-INCOLLA_QUI';
$DB = ['host' => 'localhost', 'name' => 'cdr', 'user' => 'INCOLLA_UTENTE', 'pass' => 'INCOLLA_PASSWORD'];
$CURRENT_BUNDLES = 6;            // bundle attuali (WMS -> Call and chat history -> AI+Data Boost, "bundles")
```
