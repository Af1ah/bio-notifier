# Employee biometrics

Use **Register biometric** to save data and **Push to device** to transfer it. **Fetch biometric templates** remains available for manual refresh and recovery.

## Register biometric

Choose one input:

- **Read from attendance device**: enroll the employee on the source terminal under the employee PIN, then select it here. The application requests its configured fingerprint and face templates and saves returned data to the employee. An acknowledged request without returned data is not a completed fetch.
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

Manual Fetch can refresh templates or recover a failed or empty first fetch. Adding another finger after templates have already been stored requires this manual refresh; a punch does not report which slots changed. AI Face JPEGs are registered by upload; a face punch is not treated as an available JPEG download.
