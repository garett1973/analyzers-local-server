<?php

namespace App\Enums;

enum HexCodes: string
{
    case SOH = "\x01";
    case STX = "\x02";
    case ETX = "\x03";
    case ETB = "\x17";
    case EOT = "\x04";
    case ENQ = "\x05";
    case ACK = "\x06";
    case NAK = "\x15";
    case LF = "\x0A";
    case CR = "\x0D";
    case FD = "\x7C";
    case RD = "\x5C";
    case VT = "\x0B";
    case FS = "\x1C";
    case caret = "\x5E";
    case ampersand = "\x26";
}
