<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'title',
        'comment',
        'document',
        'final_document',
        'status',
        'current_role',
    ];
}
