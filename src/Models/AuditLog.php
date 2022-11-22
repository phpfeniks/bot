<?php

namespace Feniks\Bot\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{

    const CRITICAL = 1;
    const ERROR = 2;
    const WARNING = 3;
    const NOTICE = 4;
    const INFO = 5;
    const DEBUG = 6;

    use HasFactory;
}