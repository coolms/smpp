# coolms/smpp

An SMPP 3.4 client (ESME) for PHP: send SMS through an SMSC, read the delivery receipts that come back, and keep the
session healthy without a background process.

- **Sessions** bound as transmitter, receiver or transceiver. `submit_sm` with a window of requests outstanding;
  `deliver_sm`, `enquire_link` and `unbind` from the SMSC answered as they come; unknown commands answered with
  `generic_nack` instead of ending the session.
- **Throttling handled.** After `ESME_RTHROTTLED` or `ESME_RMSGQFUL`, sends pause, and the pause doubles while the
  SMSC keeps saying so.
- **Keepalive and timeouts** on the monotonic clock: an idle link gets `enquire_link`; a link that does not answer
  it is declared dead; a request with no response is reported timed out instead of blocking the rest.
- **Encodings:** the GSM 7-bit alphabet with its extension table, ISO-8859-1 and UCS-2 (UTF-16, emoji included).
  The best coding for a text is chosen for you.
- **Long messages** by UDH concatenation (8- or 16-bit reference) or one `message_payload`. A part never splits a
  GSM escape sequence or a UTF-16 surrogate pair, and you know the part count before sending.
- **Delivery receipts** read the way SMSCs actually write them: from the TLVs when present, else from the receipt
  text in its many spellings.
- **Safe by default.** TLS verified against the host name unless you ask for plain TCP; PDU lengths checked before
  anything is read into memory; the bind password never appears in a trace, a dump or a log.
- **Zero dependencies.** PHP 8.5 with `ext-mbstring`.

## Install

```bash
composer require coolms/smpp
```

## Use

```php
use CoolMS\Smpp\Message\DeliveryReceipt;
use CoolMS\Smpp\Message\Segmenter;
use CoolMS\Smpp\Pdu\Bind;
use CoolMS\Smpp\Pdu\BindMode;
use CoolMS\Smpp\Pdu\ShortMessage;
use CoolMS\Smpp\Session;
use CoolMS\Smpp\Transport\Endpoint;
use CoolMS\Smpp\Transport\Opener;

$session = new Session(new Opener()->open(new Endpoint('smsc.example.com', 3550)));
$session->bind(BindMode::Transceiver, new Bind('my-system-id', 'password'));

$session->onDeliver(function (ShortMessage $message, ?DeliveryReceipt $receipt): int {
    if (null !== $receipt) {
        // $receipt->messageId, $receipt->state (Delivered, Undeliverable, Expired, ...)
    }

    return 0; // accept it
});

$reference = random_int(0, 255);
foreach (new Segmenter()->split('Your code is 123456', reference: $reference) as $segment) {
    $result = $session->submit(new ShortMessage(
        sourceAddr: 'MyShop',
        destinationAddr: '447700900123',
        shortMessage: $segment->shortMessage,
        dataCoding: $segment->dataCoding->value,
        esmClass: $segment->esmClass,
        registeredDelivery: ShortMessage::RECEIPT_ON_FINAL,
        sourceTon: 5,
        tlvs: $segment->tlvs,
    ));
    // $result->isOk(), $result->messageId, $result->isTransient()
}

$session->poll(5.0); // answer the SMSC, read receipts, keep the link alive
$session->unbind();
```

`send()` does not wait for the response: queue many messages, then `drain()` collects their results. The session does
its work only while you call it, so a long-running worker calls `poll()` in its loop.

## Tests

The unit suite runs against an SMSC simulated inside the test process (`tests/Support/FakeSmsc.php`) on a fake clock,
so every flow -- bind, window, throttling, receipts, keepalive, timeouts -- is deterministic and nothing sleeps.

```bash
composer test
```

## Licence

MIT. See [LICENSE](LICENSE).
