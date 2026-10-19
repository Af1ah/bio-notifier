# Employee biometrics

Use **Register biometric** to save data and **Push to device** to transfer it. **Fetch biometric templates** remains available for manual refresh and recovery.

## Register biometric

Choose one input:

- **Read from attendance device**: enroll the employee on the source terminal under the employee PIN, then select it here. The application requests its configured fingerprint templates, conventional face templates and AI Face JPEG and saves returned data to the employee. An acknowledged request without returned data is not a completed fetch.
- **Raw fingerprint template**: choose the finger slot and paste the template from a compatible enrollment reader or its software. This stores the template; it does not send it to a terminal. Fingerprint photographs are not templates. Direct browser/USB-reader capture needs the reader manufacturer's SDK and is not implemented by this form.
- **AI Face JPEG**: choose Upload photo or Take photo. Camera access uses the browser permission dialog; mobile browsers without live camera access may offer their native capture picker. The captured image is converted to JPEG, reduced to a maximum 1024-pixel edge and compressed to fit 768 KB. The server verifies the JPEG and size again. Upload accepts `.jpg` and `.jpeg` up to 768 KB. It is stored privately for later transfer to AI Face terminals. A JPEG cannot be sent as a conventional face template.

Conventional face templates are read from terminals and can be sent only to devices configured for Face template. A matching verification format does not guarantee the same biometric algorithm across firmware models; verify actual recognition when piloting a new model.

## Push to device

Select the destination first. The application always sends the employee PIN, name and device privilege, then sends selected stored credentials in queue order.

Only formats enabled in the device's supported verification formats and available gateway operations appear. Card is selected by default when supported; biometric formats start unchecked. A device configured for Card and Fingerprint shows those two options. One configured for Card, Fingerprint and Face template also shows Face template.

Fingerprint sends all stored finger slots. Face template sends stored face slots. AI Face sends the stored JPEG. Missing selected data or unsupported formats stop the push before commands are queued. Unselected credentials are left unchanged; unchecking Card omits the Card field instead of sending a blank card number.

Track individual command results in Device Commands. Queue acceptance does not prove device storage or successful fingerprint recognition. No biometric credential is deleted by these actions. Existing eBio companies retain their enrollment workflow.

## Automatic fetch after a punch

For a known employee with no stored fingerprint or conventional face template, the first fingerprint or face punch queues one background fetch from the punching device, provided that format is enabled in the device settings. Card/password punches do not trigger it. The attendance receipt remains independent of device response timing.

A persistent employee/format marker is saved in the same transaction as a recoverable background effect. It reuses the employee and device already loaded during attendance processing, with no extra lookup or cache request for each punch. Subsequent punches do not create discovery jobs or device queries. Stable command IDs prevent a retried background effect from queuing a second device command. Received templates are reused without polling.

Manual Fetch can refresh templates or recover a failed or empty first fetch. Adding another finger after templates have already been stored requires this manual refresh; a punch does not report which slots changed. Manual Fetch includes an AI Face JPEG query for devices configured for AI Face. Returned JPEGs replace the saved preview; a fingerprint or conventional face template reply never replaces the photo. Automatic discovery remains once-only for missing fingerprint/conventional face templates.

## View the stored photo

Open Employee → View to see the saved AI Face JPEG. The preview is rendered inside the authorized employee profile from private storage; it does not create a public photo URL. It confirms what is saved in the application, not whether a terminal has accepted the photo. Conventional face templates are binary credentials and cannot be rendered as a photograph.

## eBio employee photo

For companies using eBio, **Refresh photo** in the employee actions or profile calls the authenticated `GetEmployeePhoto` SOAP method. eBio returns its decrypted Base64 photo. The application validates the JPEG, stores it privately and displays it in the existing employee profile. An empty, rejected or invalid response leaves the current photo unchanged. Refresh is explicit, not a request made on every profile view or punch.

The local eBio assembly trace shows `BIOPHOTO Content` stored in `EmployeesBio` with BioName `AI` after eBio's own database encryption. `GetEmployeePhoto` decrypts that value before returning it. The Go device wire therefore does not need eBio's legacy database decryption algorithm. Legacy `USERPIC Content` JPEG records are also accepted as employee photos. `BIODATA Tmp` remains a recognition template and cannot be turned into the employee photograph.

For gateway companies, receiving `BIOPHOTO` or `USERPIC` updates the saved JPEG; fetching only a face template does not. This does not add a new firmware-specific photo-export command or prove that MAGNUM can export its current photo. eBio user pushes now take the validated saved JPEG, rather than using a face-template value as EmployeePhoto.
