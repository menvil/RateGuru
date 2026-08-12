<?php

namespace App\Enums;

enum MediaAuditIssueSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
