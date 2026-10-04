<?php

namespace App\Enums;

enum MediaAuditRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
