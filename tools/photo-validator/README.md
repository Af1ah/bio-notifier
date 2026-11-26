# Employee punch photo validator

Build from the application root before enabling employee punch submissions:

```sh
mkdir -p bin
(cd tools/photo-validator && CGO_ENABLED=0 go build -trimpath -o ../../bin/photo-validator .)
```

Include the executable built for the target host in the release before switching to the new application code. The PHP service account needs execute access. The default location is `bin/photo-validator` relative to the application root. Override it with `PUNCH_FACE_VALIDATOR_BINARY` if needed.

The validator reads a bounded JPEG from stdin and returns a JSON verdict. It checks face count, framing, lighting, sharpness, and approximate eye positions. A printed or displayed face may pass. Staff review remains part of the punch workflow.

The embedded face and eye models and sample fixture come from [Pigo v1.4.6](https://github.com/esimov/pigo/tree/v1.4.6). See models/LICENSE.pigo and models/SHA256SUMS for provenance and integrity.
