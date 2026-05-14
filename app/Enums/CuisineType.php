<?php

namespace App\Enums;

enum CuisineType: string
{
    case Italian = 'italian';
    case Asian = 'asian';
    case American = 'american';
    case Mexican = 'mexican';
    case Other = 'other';
    case Unknown = 'unknown';
}
