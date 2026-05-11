<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseAdditionalbillDetail extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'purchase_additionalbill_id',
        'account_head_id',
        'amount'

    ];

    public function purchaseAdditionalBill()
    {
        return $this->belongsTo(PurchaseAdditionalbill::class, 'purchase_additionalbill_id');
    }

    
}
