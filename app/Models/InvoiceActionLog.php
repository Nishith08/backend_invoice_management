<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceActionLog extends Model
{
    protected $fillable = [
        'invoice_id',
        'user_id',
        'role',
        'action',
        'comment',
        'query',
        'rejected_to',
    ];
    public function invoice() {
        return $this->belongsTo(Invoice::class);
    }

    public function user() {
        return $this->belongsTo(User::class, 'user_id');
    }
}

