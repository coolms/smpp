# Changelog

All notable changes to `coolms/smpp` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/). Before 1.0.0, a minor version may break the API.

## Unreleased

### Added

- PDUs: the header and the bodies of bind (transmitter, receiver, transceiver), submit_sm, deliver_sm, data_sm,
  enquire_link, unbind and generic_nack, with typed command ids, statuses and TLV tags. Unknown command ids, statuses
  and tags are kept as integers, so an SMSC's own codes are read rather than refused.
- Encodings: the GSM 7-bit alphabet with its extension table (unpacked, as SMPP carries it, and packed with fill
  bits), ISO-8859-1 and UCS-2 (UTF-16BE, surrogate pairs counted as two units). `DataCoding::bestFor()` picks GSM
  when it holds the text, else UCS-2, or Latin-1 when allowed.
- Long messages: parts with an 8- or 16-bit UDH reference, or one `message_payload`. A part never ends between a GSM
  escape and its code, or inside a surrogate pair. `Segmenter::parts()` says how many SMS a text is billed as.
- Delivery receipts: the `receipted_message_id` and `message_state` TLVs, else the Appendix B text, read in any case,
  with the common spellings of the date keys, seconds or not, full-word states and UCS-2 text. `MessageId::same()`
  matches ids across case, leading zeros, and hex against decimal.
- `Session`: bind, then `send()` with a window of outstanding requests, `submit()`, `poll()`, `drain()`, `results()`,
  `unbind()` and `close()`. It answers deliver_sm and data_sm (handing receipts over; a handler that throws gets the
  SMSC a temporary error), enquire_link, unbind and unknown commands, and lets alert_notification pass unanswered;
  keeps an idle link alive; times out unanswered requests; and holds sends back after `ESME_RTHROTTLED` or
  `ESME_RMSGQFUL`, doubling the pause when it repeats after one. A failed bind, the end of the stream, a framing error
  or a failed write ends the session: the connection closes and outstanding requests are reported lost.
- Transport: TLS by default, verified against the host's name; plain TCP on request. PDU lengths are checked before a
  body is read. Reads and writes both have deadlines. Socket waits go through `Transport\Waiter`.
- The bind password is `#[\SensitiveParameter]`: it reaches the wire and nowhere else, not a stack trace, a dump or
  the session's log.
