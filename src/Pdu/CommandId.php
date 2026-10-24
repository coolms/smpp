<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Pdu;

/**
 * The command_id of every SMPP 3.4 PDU (section 5.1.2.1). A response's id is its request's with bit 31 set;
 * generic_nack is a response with no request of its own.
 */
enum CommandId: int
{
    case GenericNack = 0x80000000;
    case BindReceiver = 0x00000001;
    case BindReceiverResp = 0x80000001;
    case BindTransmitter = 0x00000002;
    case BindTransmitterResp = 0x80000002;
    case QuerySm = 0x00000003;
    case QuerySmResp = 0x80000003;
    case SubmitSm = 0x00000004;
    case SubmitSmResp = 0x80000004;
    case DeliverSm = 0x00000005;
    case DeliverSmResp = 0x80000005;
    case Unbind = 0x00000006;
    case UnbindResp = 0x80000006;
    case ReplaceSm = 0x00000007;
    case ReplaceSmResp = 0x80000007;
    case CancelSm = 0x00000008;
    case CancelSmResp = 0x80000008;
    case BindTransceiver = 0x00000009;
    case BindTransceiverResp = 0x80000009;
    case Outbind = 0x0000000B;
    case EnquireLink = 0x00000015;
    case EnquireLinkResp = 0x80000015;
    case SubmitMulti = 0x00000021;
    case SubmitMultiResp = 0x80000021;
    case AlertNotification = 0x00000102;
    case DataSm = 0x00000103;
    case DataSmResp = 0x80000103;

    private const int RESPONSE_BIT = 0x80000000;

    public function isResponse(): bool
    {
        return 0 !== ($this->value & self::RESPONSE_BIT);
    }

    /** The response to this request; null for a response, outbind and alert_notification, which are not answered. */
    public function response(): ?self
    {
        if ($this->isResponse() || self::Outbind === $this || self::AlertNotification === $this) {
            return null;
        }

        return self::from($this->value | self::RESPONSE_BIT);
    }
}
