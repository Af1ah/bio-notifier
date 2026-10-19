# MAGNUM face retrieval

Verified on 19 October 2026 with serial TDBD262900666, employee PIN 1002.

The terminal supports fingerprints, but none are enrolled for this employee. A read timeout does not establish lack of hardware support and must not remove the configured fingerprint capability.

The accepted attendance-protocol face read is `DATA QUERY BIODATA PIN=1002`. The terminal uploads a tab-separated `BIODATA Pin=1002` record with No, Index, Valid, Duress, Type, MajorVer, MinorVer, Format and Tmp. Type 9 is a visible-light face template. Preserve algorithm version fields instead of replacing them with default versions.

The old parser accepted only FP and FACE records and quarantined this valid response as invalid_records. Unified BIODATA records now use the same durable receipt and tenant ownership flow.

A face template is not a JPEG. Updating it cannot replace or render the independently saved photo. This firmware rejected the Security PUSH table-based biophoto query with -1004 and the tested legacy BIOPHOTO query forms with -1. Keep the previous photo rather than pretending that it was refreshed.

The gateway also accepts documented Security PUSH photo responses on /iclock/querydata with command ID, packet index/count and row-count validation. It acknowledges durable photo packets as biophoto=N. This endpoint does not prove that every terminal implements photo export.

Protocol reference: ZKTeco Security PUSH Communication Protocol (2020), comparison-photo query and querydata response sections.
