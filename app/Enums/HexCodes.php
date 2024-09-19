<?php

namespace App\Enums;

enum HexCodes: string
{
    case SOH = "\x01"; // Start of Header
    case STX = "\x02"; // Start of Text
    case ETX = "\x03"; // End of Text
    case ETB = "\x17"; // End of Transmission Block
    case EOT = "\x04"; // End of Transmission
    case ENQ = "\x05"; // Enquiry
    case ACK = "\x06"; // Acknowledgement
    case NAK = "\x15"; // Negative Acknowledgement
    case LF = "\x0A"; // Line Feed
    case CR = "\x0D"; // Carriage Return
    case FD = "\x7C"; // Field Delimiter
    case RD = "\x5C"; // Record Delimiter
    case VT = "\x0B"; // Vertical Tab - start block character
    case FS = "\x1C"; // File Separator - end block character
    case caret = "\x5E";
    case ampersand = "\x26";
}
