<?php

namespace App\Enums;

enum ReportReason: string
{
    case Spam = 'spam';
    case Offensive = 'offensive';
    case Fake = 'fake';
    case Copyright = 'copyright';
    case WrongCategory = 'wrong_category';
    case Other = 'other';
}
