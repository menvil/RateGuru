<?php

namespace App\Enums;

enum ReportReason: string
{
    case Spam = 'spam';
    case Offensive = 'offensive';
    case Fake = 'fake';
    case Copyright = 'copyright';
    case NotFood = 'not_food';
    case Other = 'other';
}
