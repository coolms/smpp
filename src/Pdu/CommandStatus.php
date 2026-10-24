<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Pdu;

use function in_array;
use function sprintf;

/**
 * The command_status values SMPP 3.4 defines (section 5.1.3). SMSCs also send their own codes in the reserved and
 * vendor ranges (0x0400-0x04FF and others), so a status travels as an int; this enum names the defined ones.
 */
enum CommandStatus: int
{
    case Ok = 0x00000000;
    case InvalidMessageLength = 0x00000001;
    case InvalidCommandLength = 0x00000002;
    case InvalidCommandId = 0x00000003;
    case IncorrectBindStatus = 0x00000004;
    case AlreadyBound = 0x00000005;
    case InvalidPriorityFlag = 0x00000006;
    case InvalidRegisteredDeliveryFlag = 0x00000007;
    case SystemError = 0x00000008;
    case InvalidSourceAddress = 0x0000000A;
    case InvalidDestinationAddress = 0x0000000B;
    case InvalidMessageId = 0x0000000C;
    case BindFailed = 0x0000000D;
    case InvalidPassword = 0x0000000E;
    case InvalidSystemId = 0x0000000F;
    case CancelFailed = 0x00000011;
    case ReplaceFailed = 0x00000013;
    case MessageQueueFull = 0x00000014;
    case InvalidServiceType = 0x00000015;
    case InvalidNumberOfDestinations = 0x00000033;
    case InvalidDistributionListName = 0x00000034;
    case InvalidDestinationFlag = 0x00000040;
    case InvalidSubmitWithReplace = 0x00000042;
    case InvalidEsmClass = 0x00000043;
    case CannotSubmitToDistributionList = 0x00000044;
    case SubmitFailed = 0x00000045;
    case InvalidSourceTon = 0x00000048;
    case InvalidSourceNpi = 0x00000049;
    case InvalidDestinationTon = 0x00000050;
    case InvalidDestinationNpi = 0x00000051;
    case InvalidSystemType = 0x00000053;
    case InvalidReplaceIfPresentFlag = 0x00000054;
    case InvalidNumberOfMessages = 0x00000055;
    case Throttled = 0x00000058;
    case InvalidScheduledDeliveryTime = 0x00000061;
    case InvalidValidityPeriod = 0x00000062;
    case InvalidPredefinedMessage = 0x00000063;
    case TemporaryAppError = 0x00000064;
    case PermanentAppError = 0x00000065;
    case RejectedAppError = 0x00000066;
    case QueryFailed = 0x00000067;
    case InvalidOptionalPart = 0x000000C0;
    case OptionalParameterNotAllowed = 0x000000C1;
    case InvalidParameterLength = 0x000000C2;
    case MissingOptionalParameter = 0x000000C3;
    case InvalidOptionalParameterValue = 0x000000C4;
    case DeliveryFailure = 0x000000FE;
    case UnknownError = 0x000000FF;

    /**
     * Whether sending the same request again later may succeed: the SMSC is busy (ESME_RTHROTTLED, ESME_RMSGQFUL) or
     * an application along the way failed for now (ESME_RX_T_APPN). Every other error, defined or not, is final for
     * this request.
     */
    public static function isTransient(int $status): bool
    {
        return in_array(self::tryFrom($status), [self::Throttled, self::MessageQueueFull, self::TemporaryAppError], true);
    }

    /** "ESME_RTHROTTLED (0x00000058)", or "0x00000401" for a code SMPP 3.4 does not define. */
    public static function describe(int $status): string
    {
        $known = self::tryFrom($status);

        return null === $known ? sprintf('0x%08X', $status) : sprintf('%s (0x%08X)', $known->specName(), $status);
    }

    /** The name the specification uses: ESME_ROK, ESME_RINVPASWD, ESME_RTHROTTLED. */
    public function specName(): string
    {
        return match ($this) {
            self::Ok => 'ESME_ROK',
            self::InvalidMessageLength => 'ESME_RINVMSGLEN',
            self::InvalidCommandLength => 'ESME_RINVCMDLEN',
            self::InvalidCommandId => 'ESME_RINVCMDID',
            self::IncorrectBindStatus => 'ESME_RINVBNDSTS',
            self::AlreadyBound => 'ESME_RALYBND',
            self::InvalidPriorityFlag => 'ESME_RINVPRTFLG',
            self::InvalidRegisteredDeliveryFlag => 'ESME_RINVREGDLVFLG',
            self::SystemError => 'ESME_RSYSERR',
            self::InvalidSourceAddress => 'ESME_RINVSRCADR',
            self::InvalidDestinationAddress => 'ESME_RINVDSTADR',
            self::InvalidMessageId => 'ESME_RINVMSGID',
            self::BindFailed => 'ESME_RBINDFAIL',
            self::InvalidPassword => 'ESME_RINVPASWD',
            self::InvalidSystemId => 'ESME_RINVSYSID',
            self::CancelFailed => 'ESME_RCANCELFAIL',
            self::ReplaceFailed => 'ESME_RREPLACEFAIL',
            self::MessageQueueFull => 'ESME_RMSGQFUL',
            self::InvalidServiceType => 'ESME_RINVSERTYP',
            self::InvalidNumberOfDestinations => 'ESME_RINVNUMDESTS',
            self::InvalidDistributionListName => 'ESME_RINVDLNAME',
            self::InvalidDestinationFlag => 'ESME_RINVDESTFLAG',
            self::InvalidSubmitWithReplace => 'ESME_RINVSUBREP',
            self::InvalidEsmClass => 'ESME_RINVESMCLASS',
            self::CannotSubmitToDistributionList => 'ESME_RCNTSUBDL',
            self::SubmitFailed => 'ESME_RSUBMITFAIL',
            self::InvalidSourceTon => 'ESME_RINVSRCTON',
            self::InvalidSourceNpi => 'ESME_RINVSRCNPI',
            self::InvalidDestinationTon => 'ESME_RINVDSTTON',
            self::InvalidDestinationNpi => 'ESME_RINVDSTNPI',
            self::InvalidSystemType => 'ESME_RINVSYSTYP',
            self::InvalidReplaceIfPresentFlag => 'ESME_RINVREPFLAG',
            self::InvalidNumberOfMessages => 'ESME_RINVNUMMSGS',
            self::Throttled => 'ESME_RTHROTTLED',
            self::InvalidScheduledDeliveryTime => 'ESME_RINVSCHED',
            self::InvalidValidityPeriod => 'ESME_RINVEXPIRY',
            self::InvalidPredefinedMessage => 'ESME_RINVDFTMSGID',
            self::TemporaryAppError => 'ESME_RX_T_APPN',
            self::PermanentAppError => 'ESME_RX_P_APPN',
            self::RejectedAppError => 'ESME_RX_R_APPN',
            self::QueryFailed => 'ESME_RQUERYFAIL',
            self::InvalidOptionalPart => 'ESME_RINVOPTPARSTREAM',
            self::OptionalParameterNotAllowed => 'ESME_ROPTPARNOTALLWD',
            self::InvalidParameterLength => 'ESME_RINVPARLEN',
            self::MissingOptionalParameter => 'ESME_RMISSINGOPTPARAM',
            self::InvalidOptionalParameterValue => 'ESME_RINVOPTPARAMVAL',
            self::DeliveryFailure => 'ESME_RDELIVERYFAILURE',
            self::UnknownError => 'ESME_RUNKNOWNERR',
        };
    }
}
