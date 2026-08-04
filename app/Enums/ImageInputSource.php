<?php

namespace App\Enums;

enum ImageInputSource: string
{
    case Upload = 'upload';
    case UrlImport = 'url_import';
    case Generated = 'generated';
}
