# WooCommerce IRIS (Worldline) – Redirection

Ενσωμάτωση WooCommerce με το Hosted Payment Page (HPP) της Worldline/Cardlink (`shophandlermpi`). Το plugin δημιουργεί και αποστέλλει ασφαλές αίτημα πληρωμής, υπολογίζει και επαληθεύει το `digest` (SHA256 + Base64) για την ακεραιότητα των δεδομένων και χειρίζεται τις επιστροφές επιτυχίας/ακύρωσης. Προαιρετικά προεπιλέγει τη μέθοδο πληρωμής IRIS.

Κατασκευάστηκε από τον John Pastras — [webally.gr](https://webally.gr)

---

## Τι κάνει ακριβώς

- **Αποστολή παραγγελίας στο HPP** της Worldline/Cardlink (URL `shophandlermpi`).
- **Υπολογισμός digest** για το request: `base64( sha256( concat(fields_in_order) + secret ) )`.
- **Αυτόματη ανακατεύθυνση (auto-post)** από τη σελίδα πληρωμής του WooCommerce προς το HPP.
- **Επαλήθευση επιστροφής** (confirm/cancel) με εκ νέου υπολογισμό του digest (Table 3 – Response fields order).
- **Ολοκλήρωση πληρωμής** (Authorized/Captured) ή χαρακτηρισμός ως Failed, με αντίστοιχες σημειώσεις στην παραγγελία.
- **Προεπιλογή IRIS** (προαιρετικά) μέσω του πεδίου `payMethod=IRIS`.
- **Logging** (προαιρετικό) στα WooCommerce logs για έλεγχο αιτημάτων/απαντήσεων.

---

## Προαπαιτούμενα

- WordPress με WooCommerce.
- Διαπιστευτήρια Worldline/Cardlink (MID και Shared Secret) για περιβάλλον Test/Live.

---

## Εγκατάσταση

1. Κατεβάστε τον φάκελο του plugin `woocommerce-iris-worldline-redirect`.
2. Συμπιέστε τον σε `.zip` (αν χρειάζεται) ή ανεβάστε τον φάκελο μέσω FTP/SFTP.
3. Μεταφορτώστε στο WordPress: Πρόσθετα → Νέο → Μεταφόρτωση προσθέτου → επιλέξτε το `.zip`.
4. Ενεργοποιήστε το πρόσθετο.

---

## Ρυθμίσεις στο WooCommerce

1. WooCommerce → Ρυθμίσεις → Πληρωμές.
2. Ενεργοποιήστε το "IRIS (Worldline) – Redirection".
3. Πατήστε "Διαχείριση" και ρυθμίστε:
   - **Enable/Disable**: ενεργοποίηση μεθόδου πληρωμής.
   - **Title/Description**: τίτλος/κείμενο στο checkout.
   - **Test Environment**: ενεργοποίηση sandbox (eurocommerce-test).
   - **Preselect IRIS**: στέλνει `payMethod=IRIS`.
   - **Production (Live)**
     - Live Endpoint URL (π.χ. `https://eurocommerce.cardlink.gr/vpos/shophandlermpi`)
     - Live MID
     - Live Shared Secret
   - **Sandbox (Test)**
     - Test Endpoint URL (προεπιλογή: `https://eurocommerce-test.cardlink.gr/vpos/shophandlermpi`)
     - Test MID
     - Test Shared Secret
   - **Debug log**: ενεργοποιεί logging (WooCommerce → Κατάσταση → Καταγραφές).

Στην οθόνη ρυθμίσεων εμφανίζονται επίσης τα **Callback URLs** για ευκολία:

- Confirm URL: `/?wc-api=iris_worldline_confirm`
- Cancel URL: `/?wc-api=iris_worldline_cancel`

Συνήθως δεν απαιτείται επιπλέον ρύθμιση στο WordPress, αλλά βεβαιωθείτε ότι το κατάστημα είναι προσβάσιμο δημόσια στις παραπάνω διευθύνσεις.

---

## Παραμετροποίηση στον πάροχο (Worldline/Cardlink)

Αν χρειάζεται καταχώριση των Callback URLs στο περιβάλλον του παρόχου, χρησιμοποιήστε τις παρακάτω:

- Confirm URL: `https://your-domain.tld/?wc-api=iris_worldline_confirm`
- Cancel URL: `https://your-domain.tld/?wc-api=iris_worldline_cancel`

Αντικαταστήστε το `your-domain.tld` με το πραγματικό domain του καταστήματος.

---

## Πώς λειτουργεί η ροή

1. Ο πελάτης επιλέγει τη μέθοδο πληρωμής στο checkout και προχωρά.
2. Ο πελάτης ανακατευθύνεται (auto-post) στο HPP με τα απαιτούμενα πεδία και το `digest`.
3. Στο HPP ολοκληρώνει ή ακυρώνει την πληρωμή.
4. Ο πάροχος επιστρέφει στο κατάστημα (Confirm/Cancel) στέλνοντας POST.
5. Το plugin υπολογίζει ξανά το `digest` για επαλήθευση και ενημερώνει την παραγγελία:
   - `AUTHORIZED/CAPTURED` → `payment_complete()` και σημείωση.
   - Άλλη κατάσταση → `failed` και ειδοποίηση στο checkout.

---

## Sandbox (Test) λειτουργία

- Ενεργοποιήστε το "Test Environment" στις ρυθμίσεις του plugin.
- Συμπληρώστε Test MID/Secret και χρησιμοποιήστε το προεπιλεγμένο Test Endpoint.
- Επαληθεύστε ότι λαμβάνετε επιστροφές στις διευθύνσεις Confirm/Cancel.

---

## Ασφάλεια και Digest

- Το request digest δημιουργείται από όλα τα πεδία (στην ακριβή σειρά που ορίζει το manual) και το Shared Secret.
- Για την επιστροφή (response), το plugin ακολουθεί την καθορισμένη σειρά πεδίων (Table 3) πριν τον υπολογισμό.
- Η σύγκριση γίνεται με `hash_equals` για αποφυγή timing attacks.

---

## Καταγραφές (Logs)

- Ενεργοποιήστε το "Debug log".
- Δείτε τα logs στο WooCommerce → Κατάσταση → Καταγραφές → `iris_worldline_redirect`.

---

## Συχνά προβλήματα

- "Λείπουν ρυθμίσεις Worldline (endpoint/MID/secret)": Συμπληρώστε σωστά Endpoint, MID, Secret ανά περιβάλλον.
- "INVALID DIGEST": Ελέγξτε ότι το Shared Secret είναι σωστό και ίδιο σε κατάστημα και πάροχο. Βεβαιωθείτε για την ακριβή σειρά πεδίων και ότι δεν τροποποιούνται πριν/μετά.
- Δεν γίνεται επιστροφή στη σελίδα επιβεβαίωσης: Βεβαιωθείτε ότι οι Callback URLs είναι προσβάσιμες δημόσια (χωρίς firewall/VPN) και σωστά δηλωμένες στον πάροχο αν απαιτείται.

---

## Απόδοση

- Δημιουργός: John Pastras — [webally.gr](https://webally.gr)

Εφόσον χρειάζεστε προσαρμογές (π.χ. custom fields, UI στο HPP, επιπλέον έλεγχοι), επικοινωνήστε.
