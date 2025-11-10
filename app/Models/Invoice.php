<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'title',
        'inv_no',
        'inv_amt',
        'inv_type',
        'comment',
        'document',
        'final_document',
        'status',
        'current_role',
        'department',
    ];
}
