<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Pdu;

/**
 * The optional parameter (TLV) tags SMPP 3.4 defines (section 5.3.2). An SMSC may send others -- vendor tags in
 * 0x1400-0x3FFF -- so a Tlv keeps its tag as an int and this enum names the defined ones.
 */
enum Tag: int
{
    case DestAddrSubunit = 0x0005;
    case DestNetworkType = 0x0006;
    case DestBearerType = 0x0007;
    case DestTelematicsId = 0x0008;
    case SourceAddrSubunit = 0x000D;
    case SourceNetworkType = 0x000E;
    case SourceBearerType = 0x000F;
    case SourceTelematicsId = 0x0010;
    case QosTimeToLive = 0x0017;
    case PayloadType = 0x0019;
    case AdditionalStatusInfoText = 0x001D;
    case ReceiptedMessageId = 0x001E;
    case MsMsgWaitFacilities = 0x0030;
    case PrivacyIndicator = 0x0201;
    case SourceSubaddress = 0x0202;
    case DestSubaddress = 0x0203;
    case UserMessageReference = 0x0204;
    case UserResponseCode = 0x0205;
    case SourcePort = 0x020A;
    case DestinationPort = 0x020B;
    case SarMsgRefNum = 0x020C;
    case LanguageIndicator = 0x020D;
    case SarTotalSegments = 0x020E;
    case SarSegmentSeqnum = 0x020F;
    case ScInterfaceVersion = 0x0210;
    case CallbackNumPresInd = 0x0302;
    case CallbackNumAtag = 0x0303;
    case NumberOfMessages = 0x0304;
    case CallbackNum = 0x0381;
    case DpfResult = 0x0420;
    case SetDpf = 0x0421;
    case MsAvailabilityStatus = 0x0422;
    case NetworkErrorCode = 0x0423;
    case MessagePayload = 0x0424;
    case DeliveryFailureReason = 0x0425;
    case MoreMessagesToSend = 0x0426;
    case MessageState = 0x0427;
    case UssdServiceOp = 0x0501;
    case DisplayTime = 0x1201;
    case SmsSignal = 0x1203;
    case MsValidity = 0x1204;
    case AlertOnMessageDelivery = 0x130C;
    case ItsReplyType = 0x1380;
    case ItsSessionInfo = 0x1383;
}
